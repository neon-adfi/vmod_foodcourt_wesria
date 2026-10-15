<?php namespace Vmod\Erp\Services;

use DB;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Audit;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\InvoiceReference;
use Vmod\Erp\Models\InvoiceReferenceLink;
use Vmod\Erp\Models\PurchaseOrder;
use Vmod\Erp\Models\SalesOrder;
use Vmod\Erp\Models\GoodsReceipt;
use Vmod\Erp\Models\DeliveryOrder;

class InvoiceReferenceService
{
    private const TYPES = [
        'po' => [PurchaseOrder::class, 'purchase_order_id', 'purchase', 'supplier_id'],
        'grn' => [GoodsReceipt::class, 'goods_receipt_id', 'purchase', 'supplier_id'],
        'so' => [SalesOrder::class, 'sales_order_id', 'sales', 'customer_id'],
        'do' => [DeliveryOrder::class, 'delivery_order_id', 'sales', 'customer_id'],
    ];

    public function save(array $input): InvoiceReference
    {
        $input = $this->input($input, true);
        return $this->mutate('saved', $input, function ($actor, $record) use ($input) {
            abort_unless(!$record || $record->state === 'active', 409, 'Restore this reference before editing it.');
            $branch = Op::branch($actor, $input['branch_id']);
            if ($record) {
                abort_unless((int) $record->branch_id === $branch && (int) $record->party_id === (int) $input['party_id']
                    && $record->direction === $input['direction'], 409, 'Branch, direction and counterparty are fixed. Void and recreate the reference.');
                Op::reason($input);
            }
            $existing = $record ? $record->links->keyBy(fn ($link) => $this->key($link->identity())) : collect();
            $additions = array_values(array_filter($input['links'], fn ($link) => !$existing->has($this->key($link))));
            $resolved = $this->resolveLinks($actor, $additions, $input);
            $currency = $record ? $record->currency : null;
            foreach ($resolved as $link) {
                abort_if($currency && $currency !== $link['currency'], 409, 'Linked documents must use one currency.');
                $currency = $link['currency'];
            }
            $data = array_intersect_key($input, array_flip(['number', 'document_date', 'amount']));
            $data['active_number'] = mb_strtoupper($input['number'], 'UTF-8');
            if (!$record) {
                $record = InvoiceReference::create($data + ['company_id' => $actor->company_id, 'branch_id' => $branch,
                    'direction' => $input['direction'], 'party_id' => $input['party_id'], 'currency' => $currency]);
            } else {
                $record->fill($data);
                $record->version++;
                $record->save();
            }
            $keys = array_map(fn ($link) => $this->key($link), $input['links']);
            foreach ($existing as $key => $link) {
                if (!in_array($key, $keys, true)) {
                    $link->delete(); // Reference links are editable metadata; the detailed audit retains removed identities.
                }
            }
            foreach ($resolved as $link) {
                unset($link['currency']);
                InvoiceReferenceLink::create($link + ['invoice_reference_id' => $record->id]);
            }
            return $record;
        });
    }

    public function void(array $input): InvoiceReference
    {
        return $this->state($input, 'voided');
    }

    public function restore(array $input): InvoiceReference
    {
        return $this->state($input, 'active');
    }

    public function find(int $id): InvoiceReference
    {
        return Op::scoped(InvoiceReference::class, Workspace::authorize('invoice_reference.read'))->with(['party', 'links'])->findOrFail($id);
    }

    public function linkViews(InvoiceReference $record): array
    {
        $actor = Workspace::authorize('invoice_reference.read');
        Op::scoped(InvoiceReference::class, $actor)->findOrFail($record->id);
        return $record->links->map(function ($link) use ($actor) {
            $identity = $link->identity();
            $type = self::TYPES[$identity['kind']];
            $current = Op::scoped($type[0], $actor)->findOrFail($identity['id']);
            $permission = ['po' => 'purchaseorder', 'so' => 'salesorder', 'grn' => 'goodsreceipt', 'do' => 'deliveryorder'][$identity['kind']];
            return $identity + $link->only(['number', 'document_date', 'linked_state'])
                + ['state' => $current->state, 'can_read' => Workspace::hasAccess($permission . '.read')];
        })->all();
    }

    public function choices(array $input): array
    {
        $actor = Workspace::authorize('invoice_reference.write');
        $filters = Op::validate($input, ['branch_id' => 'required|integer|min:1', 'party_id' => 'required|integer|min:1',
            'direction' => 'required|in:purchase,sales', 'kind' => 'required|in:po,so,grn,do', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $branch = Op::branch($actor, $filters['branch_id']);
        $type = self::TYPES[$filters['kind']];
        abort_unless($type[2] === $filters['direction'], 404);
        $query = Op::scoped($type[0], $actor)->where('branch_id', $branch)->whereIn('state', $this->states($filters['kind']));
        $order = in_array($filters['kind'], ['po', 'so'], true);
        if ($order) {
            $query->where($type[3], $filters['party_id'])->with('revision');
        } else {
            $parent = $filters['kind'] === 'grn' ? 'purchase_order' : 'sales_order';
            $query->whereHas($parent, fn ($q) => $q->where($type[3], $filters['party_id']));
        }
        if (!empty($filters['q'])) {
            $query->where('number', 'like', '%' . $filters['q'] . '%');
        }
        $page = $query->orderByDesc('id')->paginate(15, ['*'], 'page', $filters['page'] ?? 1);
        return ['documents' => $page->getCollection()->map(fn ($doc) => [
            'kind' => $filters['kind'], 'id' => $doc->id, 'revision_id' => $doc->revision_id, 'number' => $doc->number,
            'document_date' => $order ? $doc->revision->document_date : $doc->document_date, 'state' => $doc->state,
        ])->all(), 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
    }

    private function state(array $input, string $state): InvoiceReference
    {
        $input = $this->input($input, false);
        return $this->mutate($state === 'active' ? 'restored' : 'voided', $input, function ($actor, $record) use ($state, $input) {
            abort_unless($record, 404);
            abort_if($record->state === $state, 409, 'This reference has already changed state.');
            Op::reason($input);
            $record->state = $state;
            $record->active_number = $state === 'active' ? mb_strtoupper($record->number, 'UTF-8') : null;
            $record->version++;
            $record->save();
            return $record;
        });
    }

    private function mutate(string $action, array $input, callable $write): InvoiceReference
    {
        try {
            return Op::run('invoice_reference.' . $action, 'invoice_reference.write', $input, function ($actor, $resultId) use ($input) {
                $id = $resultId ?: ($input['id'] ?? null);
                if (!$id) {
                    return null;
                }
                $record = Op::scoped(InvoiceReference::class, $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
                $record->setRelation('links', $record->links()->orderBy('id')->lockForUpdate()->get());
                return $record;
            }, function ($actor, $company, $record, $operationId) use ($input, $write, $action) {
                $before = $record ? $this->auditData($record) : null;
                $record = $write($actor, $record)->fresh(['links']);
                $after = $this->auditData($record);
                $oldKeys = array_column($before['links'] ?? [], 'key');
                $newKeys = array_column($after['links'], 'key');
                Audit::record($company->id, 'invoice_reference.change', ['id' => $record->id, 'branch_id' => $record->branch_id,
                    'operation_id' => $operationId, 'action' => $action, 'reason' => $input['reason'] ?? null, 'before' => $before, 'after' => $after,
                    'added' => array_values(array_diff($newKeys, $oldKeys)), 'removed' => array_values(array_diff($oldKeys, $newKeys))], $actor->user_id);
                return $record;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062 && str_contains($exception->getMessage(), 'erp_invoice_active_number')) {
                abort(409, 'An active invoice reference already uses this number for this counterparty and direction.');
            }
            throw $exception;
        }
    }

    private function input(array $input, bool $saving): array
    {
        Workspace::assertContext();
        Workspace::authorize('invoice_reference.write');
        $fields = ['id', 'version', 'operation_key', 'reason'];
        if ($saving) {
            $fields = array_merge($fields, ['branch_id', 'direction', 'party_id', 'number', 'document_date', 'amount', 'links']);
        }
        $input = array_intersect_key($input, array_flip($fields));
        if (!$saving) {
            return $input;
        }
        Op::validate($input, ['branch_id' => 'required|integer|min:1', 'direction' => 'required|in:purchase,sales', 'party_id' => 'required|integer|min:1',
            'number' => 'required|string|max:160', 'document_date' => 'required|date_format:Y-m-d', 'links' => 'required|array|min:1|max:100',
            'links.*' => 'required|array', 'links.*.kind' => 'required|in:po,so,grn,do', 'links.*.id' => 'required|integer|min:1', 'links.*.revision_id' => 'required|integer|min:1']);
        $input['number'] = trim($input['number']);
        if ($input['number'] === '' || mb_strlen(mb_strtoupper($input['number'], 'UTF-8')) > 160) {
            throw new ValidationException(['number' => 'Enter a number of at most 160 characters after case normalization.']);
        }
        if (!is_string($input['amount'] ?? null) || !preg_match('/^\d{1,17}(?:\.\d{1,2})?$/D', trim($input['amount']))) {
            throw new ValidationException(['amount' => 'Enter a nonnegative gross amount with at most two decimal places.']);
        }
        $input['amount'] = (string) BigDecimal::of(trim($input['amount']))->toScale(2);
        if (!array_is_list($input['links'])) {
            throw new ValidationException(['links' => 'Supply a list of document links.']);
        }
        $seen = [];
        foreach ($input['links'] as &$link) {
            $link = ['kind' => $link['kind'], 'id' => (int) $link['id'], 'revision_id' => (int) $link['revision_id']];
            $key = $link['kind'] . ':' . $link['id'];
            if (isset($seen[$key])) {
                throw new ValidationException(['links' => 'Link each document only once.']);
            }
            $seen[$key] = true;
        }
        unset($link);
        usort($input['links'], fn ($a, $b) => [$a['kind'], $a['id']] <=> [$b['kind'], $b['id']]);
        return $input;
    }

    private function resolveLinks($actor, array $links, array $input): array
    {
        $parents = [];
        foreach ($links as $link) {
            $type = self::TYPES[$link['kind']];
            abort_unless($type[2] === $input['direction'], 404);
            $doc = Op::scoped($type[0], $actor)->findOrFail($link['id']);
            $parentKind = $type[2] === 'purchase' ? 'po' : 'so';
            $parentId = $link['kind'] === 'grn' ? $doc->purchase_order_id : ($link['kind'] === 'do' ? $doc->sales_order_id : $doc->id);
            $parents[$parentKind . ':' . $parentId] = [$parentKind, (int) $parentId];
        }
        // Match fulfilment writers: all sorted order parents precede any receipt/delivery child locks.
        uasort($parents, fn ($a, $b) => $a <=> $b);
        $locked = [];
        foreach ($parents as $key => [$kind, $id]) {
            $locked[$key] = Op::scoped(self::TYPES[$kind][0], $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
        }
        $result = [];
        foreach ($links as $link) {
            $type = self::TYPES[$link['kind']];
            $doc = Op::scoped($type[0], $actor)->whereKey($link['id'])->lockForUpdate()->firstOrFail();
            $parentKind = $type[2] === 'purchase' ? 'po' : 'so';
            $parentId = $link['kind'] === 'grn' ? $doc->purchase_order_id : ($link['kind'] === 'do' ? $doc->sales_order_id : $doc->id);
            $parent = $locked[$parentKind . ':' . $parentId];
            abort_unless((int) $doc->branch_id === (int) $input['branch_id'] && (int) $parent->{$type[3]} === (int) $input['party_id'], 404);
            abort_unless(in_array($doc->state, $this->states($link['kind']), true)
                && (int) $doc->revision_id === $link['revision_id'], 409, 'This document or revision is no longer eligible. Reload the choices.');
            $revisionClass = $type[2] === 'purchase' ? \Vmod\Erp\Models\PurchaseOrderRevision::class : \Vmod\Erp\Models\SalesOrderRevision::class;
            $revision = $revisionClass::where($parentKind === 'po' ? 'purchase_order_id' : 'sales_order_id', $parentId)->whereKey($doc->revision_id)->firstOrFail();
            $result[] = [$type[1] => $doc->id, ($type[2] === 'purchase' ? 'purchase_revision_id' : 'sales_revision_id') => $revision->id,
                'number' => $doc->number, 'document_date' => in_array($link['kind'], ['po', 'so'], true) ? $revision->document_date : $doc->document_date,
                'linked_state' => $doc->state, 'currency' => $revision->currency];
        }
        return $result;
    }

    private function states(string $kind): array
    {
        return match ($kind) {
            'po' => ['approved', 'partially_received', 'completed'], 'so' => ['approved', 'partially_delivered', 'completed'],
            'grn' => ['confirmed'], 'do' => ['dispatched'],
        };
    }

    private function key(array $link): string
    {
        return $link['kind'] . ':' . $link['id'] . ':' . $link['revision_id'];
    }

    private function auditData(InvoiceReference $record): array
    {
        return $record->only(['branch_id', 'direction', 'party_id', 'number', 'document_date', 'amount', 'currency', 'state', 'version'])
            + ['links' => $record->links->map(fn ($link) => ['key' => $this->key($link->identity())]
                + $link->only(['number', 'document_date', 'linked_state']))->all()];
    }
}
