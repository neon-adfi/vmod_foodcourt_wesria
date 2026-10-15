<?php namespace Vmod\Erp\Components;

use Redirect;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Branch;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\PurchaseOrderRevision;
use Vmod\Erp\Models\SalesOrderRevision;
use Vmod\Erp\Services\PurchasingService;
use Vmod\Erp\Services\SalesService;
use Vmod\Erp\Services\ReceivingService;
use Vmod\Erp\Services\DispatchService;

class DocumentPrint extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Document print', 'description' => 'Protected operational document history'];
    }

    public function onRun()
    {
        $this->controller->setResponseHeader('Cache-Control', 'private, no-store, max-age=0');
        $this->controller->setResponseHeader('X-Robots-Tag', 'noindex, nofollow');
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $input = Op::validate(
            ['kind' => $this->param('kind'), 'id' => $this->param('id'), 'revision' => get('revision')],
            ['kind' => 'required|in:po,so,grn,do', 'id' => 'required|integer|min:1', 'revision' => 'nullable|integer|min:1']
        );
        $this->page['document'] = $this->document($input['kind'], (int) $input['id'], empty($input['revision']) ? null : (int) $input['revision']);
    }

    public function document(string $kind, int $id, ?int $revisionId = null): array
    {
        abort_unless(in_array($kind, ['po', 'so', 'grn', 'do'], true), 404);
        $commercial = in_array($kind, ['po', 'so'], true);
        abort_if(!$commercial && $revisionId !== null, 404);
        $record = match ($kind) {
            'po' => (new PurchasingService)->find($id), 'so' => (new SalesService)->find($id),
            'grn' => (new ReceivingService)->find($id), 'do' => (new DispatchService)->find($id),
        };
        $purchase = in_array($kind, ['po', 'grn'], true);
        $parent = $commercial ? $record : ($purchase ? $record->purchase_order : $record->sales_order);
        $revisionClass = $purchase ? PurchaseOrderRevision::class : SalesOrderRevision::class;
        $revision = $revisionClass::where($purchase ? 'purchase_order_id' : 'sales_order_id', $parent->id)
            ->with('items')->findOrFail($commercial ? ($revisionId ?: $record->revision_id) : $record->revision_id);
        $data = ['kind' => $kind, 'id' => $record->id, 'commercial' => $commercial,
            'title' => ['po' => 'Purchase order', 'so' => 'Sales order', 'grn' => 'Goods receipt', 'do' => 'Delivery order'][$kind],
            'number' => $record->number, 'state' => $record->state, 'historical' => $commercial && (int) $record->revision_id !== (int) $revision->id,
            'approved' => (bool) $revision->approved_at, 'revision_number' => $revision->number,
            'parent_number' => $commercial ? null : $parent->number, 'issuer_missing' => !$revision->issuer_snapshot,
            'issuer' => $revision->issuer_snapshot ?: ['company' => Company::findOrFail($record->company_id)->name,
                'branch' => Branch::where('company_id', $record->company_id)->findOrFail($record->branch_id)->name],
            'party' => $purchase ? $revision->supplier_snapshot : $revision->customer_snapshot,
            'document_date' => $commercial ? $revision->document_date : $record->document_date,
            'notes' => $commercial ? $revision->notes : ($kind === 'do' ? $record->notes : null),
            'reason' => $commercial ? $record->close_reason : $record->reason, 'supplier_reference' => $kind === 'grn' ? $record->supplier_reference : null,
            'back_path' => ['po' => 'purchasing', 'so' => 'sales', 'grn' => 'receiving', 'do' => 'delivery'][$kind], 'lines' => []];
        foreach ($commercial ? $revision->items : $record->items as $item) {
            $source = $commercial ? $item : ($purchase ? $item->purchase_item : $item->sales_item);
            $snapshot = $source->snapshot;
            $line = ['description' => $snapshot['description'], 'package' => $snapshot['package_name'], 'quantity' => $item->quantity,
                'base_quantity' => $item->base_quantity, 'base_uom' => $snapshot['base_uom']];
            if ($commercial) {
                $line += ['unit_price' => $snapshot['unit_price'], 'tax_rate' => $snapshot['tax_rate'], 'tax_label' => $snapshot['tax_label'] ?? '',
                    'net' => $item->net, 'tax' => $item->tax, 'gross' => $item->gross];
            } else {
                $name = $purchase ? $item->location_name : ($snapshot['location_name'] ?? null);
                $line += ['location' => $name ?: $item->location->name, 'location_missing' => !$name];
            }
            $data['lines'][] = $line;
        }
        if ($commercial) {
            $data += ['totals' => $revision->only(['net', 'tax', 'gross']), 'currency' => $revision->currency, 'price_mode' => $revision->price_mode];
        } else {
            $actor = Workspace::membership();
            $query = Op::scoped(get_class($record), $actor);
            $previous = $record->replaces_id ? (clone $query)->findOrFail($record->replaces_id) : null;
            $next = (clone $query)->where('replaces_id', $record->id)->first();
            $data += ['previous' => $previous ? $previous->number : null, 'replacement' => $next ? $next->number : null];
        }
        return $data;
    }
}
