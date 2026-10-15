<?php namespace Vmod\Erp\Services;

use DB;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Company;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\SalesOrder;
use Vmod\Erp\Models\SalesOrderRevision;
use Vmod\Erp\Models\SalesOrderItem;
use Vmod\Erp\Models\DeliveryOrder;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\ProductPackage;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Models\TaxRate;

class SalesService
{
    public function saveDraft(array $input): SalesOrder
    {
        $input = $this->allowed($input);
        return $this->mutate('salesorder.saved', 'salesorder.write', $input, function ($actor, $company, $so) use ($input) {
            abort_unless(!$so || $so->state === 'draft', 409, 'Only a draft SO can be edited.');
            $data = $this->build($input, $actor, $company, $so);
            if (!$so) {
                $so = SalesOrder::create(['company_id' => $company->id, 'branch_id' => $data['branch_id'],
                    'customer_id' => $data['customer_id'], 'number' => Op::number($company->id, 'SO')]);
            }
            $this->revision($so, $data, $actor->user_id);
            return $so->fresh(['revision.items']);
        });
    }

    public function previewDraft(array $input): array
    {
        Workspace::assertContext();
        $companyId = Workspace::authorize('salesorder.write')->company_id;
        $input = $this->allowed($input);
        return DB::transaction(function () use ($companyId, $input) {
            $company = Company::whereKey($companyId)->sharedLock()->firstOrFail();
            $actor = Workspace::authorize('salesorder.write');
            $so = empty($input['id']) ? null : Op::scoped(SalesOrder::class, $actor)->whereKey($input['id'])->sharedLock()->firstOrFail();
            abort_unless(!$so || $so->state === 'draft', 409);
            if ($so) {
                Op::validate($input, ['version' => 'required|integer|min:1']);
                abort_unless((int) $so->version === (int) $input['version'], 409, 'This document changed. Reload before previewing.');
            }
            return $this->build($input, $actor, $company, $so);
        });
    }

    public function repriceDraft(array $input): SalesOrder
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'item_ids']));
        return $this->mutate('salesorder.repriced', 'salesorder.write', $input, function ($actor, $company, $so) use ($input) {
            abort_unless($so && $so->state === 'draft' && !$so->first_dispatch_at, 409);
            Op::validate($input, ['item_ids' => 'required|array|min:1', 'item_ids.*' => 'required|integer|min:1|distinct']);
            $revision = $so->revision;
            $items = $revision->items;
            $ids = array_map('intval', $input['item_ids']);
            abort_if(array_diff($ids, $items->pluck('id')->all()), 404);
            $data = $this->build(['branch_id' => $so->branch_id, 'customer_id' => $so->customer_id,
                'document_date' => $revision->document_date, 'notes' => $revision->notes, 'expected_currency' => $revision->currency,
                'price_mode' => $revision->price_mode, 'lines' => $items->map(fn ($item) => ['item_id' => $item->id,
                    'quantity' => $item->quantity, 'location_id' => $item->location_id, 'price_action' => in_array($item->id, $ids, true) ? 'catalogue' : 'keep'])->all()], $actor, $company, $so);
            $this->revision($so, $data, $actor->user_id);
            return $so->fresh(['revision.items']);
        });
    }

    public function approve(array $input): SalesOrder
    {
        return $this->transition('approved', 'salesorder.approve', $input, function ($so) {
            abort_unless($so->state === 'draft' && $so->revision_id, 409, 'Only a saved draft can be approved.');
            (new StockService)->reserve($so);
            $so->revision->update(['approved_at' => now()]);
        });
    }

    public function reopen(array $input): SalesOrder
    {
        return $this->transition('draft', 'salesorder.write', $input, function ($so, $actor) use ($input) {
            abort_unless($so->state === 'approved' && !$so->first_dispatch_at, 409, 'Dispatch has begun or this order cannot be reopened.');
            Op::reason($input);
            (new StockService)->release($so, true);
            $this->voidDraftDeliveries($so);
            $old = $so->revision;
            $this->revision($so, ['customer_snapshot' => $old->customer_snapshot, 'document_date' => $old->document_date,
                'notes' => $old->notes, 'currency' => $old->currency, 'price_mode' => $old->price_mode,
                'lines' => $old->items->map(fn ($i) => $i->snapshot)->all(),
                'amounts' => ['totals' => $old->only(['net', 'tax', 'gross']), 'lines' => $old->items->map(fn ($i) => $i->only(['net', 'tax', 'gross']))->all()]], $actor->user_id);
        }, ['salesorder.approve']);
    }

    public function void(array $input): SalesOrder
    {
        return $this->transition('voided', 'salesorder.close', $input, function ($so) use ($input) {
            abort_unless(in_array($so->state, ['draft', 'approved'], true) && !$so->first_dispatch_at, 409, 'Use close-short after dispatch has begun.');
            $so->close_reason = Op::reason($input);
            (new StockService)->release($so);
            $this->voidDraftDeliveries($so);
        });
    }

    public function closeShort(array $input): SalesOrder
    {
        return $this->transition('closed_short', 'salesorder.close', $input, function ($so) use ($input) {
            abort_unless(in_array($so->state, ['approved', 'partially_delivered'], true) && $so->first_dispatch_at, 409, 'Close-short requires prior dispatch and an outstanding remainder.');
            $so->close_reason = Op::reason($input);
            (new StockService)->release($so);
            $this->voidDraftDeliveries($so);
        });
    }

    public function find(int $id): SalesOrder
    {
        return Op::scoped(SalesOrder::class, Workspace::authorize('salesorder.read'))->with(['revision.items', 'revisions.items'])->findOrFail($id);
    }

    private function transition(string $state, string $permission, array $input, callable $check, array $extra = []): SalesOrder
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'reason']));
        return $this->mutate('salesorder.' . $state, $permission, $input, function ($actor, $company, $so) use ($state, $input, $check) {
            abort_unless($so, 404);
            $check($so, $actor);
            $so->state = $state;
            $so->version++;
            $so->save();
            return $so->fresh(['revision.items']);
        }, $extra);
    }

    private function mutate(string $action, string $permission, array $input, callable $write, array $extra = [])
    {
        return Op::run($action, $permission, $input, function ($actor, $resultId) use ($input) {
            $id = $resultId ?: ($input['id'] ?? null);
            if ($id) {
                return Op::scoped(SalesOrder::class, $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            }
            Op::branch($actor, $input['branch_id'] ?? null);
            return null;
        }, $write, $extra);
    }

    private function allowed(array $input): array
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'branch_id', 'customer_id', 'expected_currency', 'document_date', 'notes', 'price_mode', 'lines']));
        if (isset($input['lines']) && is_array($input['lines'])) {
            foreach ($input['lines'] as &$line) {
                if (is_array($line)) {
                    $line = array_intersect_key($line, array_flip(['item_id', 'package_id', 'location_id', 'quantity', 'tax_rate_id', 'price_action', 'unit_price', 'price_reason']));
                }
            }
        }
        return $input;
    }

    private function build(array $input, $actor, $company, ?SalesOrder $so): array
    {
        Op::validate($input, ['branch_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1',
            'expected_currency' => 'required|string|size:3', 'document_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000',
            'price_mode' => 'required|in:exclusive,inclusive', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            'lines.*.item_id' => 'nullable|integer|min:1|distinct', 'lines.*.package_id' => 'nullable|integer|min:1',
            'lines.*.location_id' => 'required|integer|min:1', 'lines.*.tax_rate_id' => 'nullable|integer|min:1',
            'lines.*.price_action' => 'nullable|in:keep,catalogue,override', 'lines.*.price_reason' => 'nullable|string|max:2000']);
        if (!array_is_list($input['lines'])) {
            throw new ValidationException(['lines' => 'Supply an ordered list of lines.']);
        }
        $branchId = Op::branch($actor, $input['branch_id']);
        abort_unless($input['expected_currency'] === $company->currency, 409, 'Currency changed. Reload.');
        abort_if($so && ((int) $so->customer_id !== (int) $input['customer_id'] || (int) $so->branch_id !== $branchId), 409, 'Customer and branch are fixed after the first save.');
        $oldItems = $so ? SalesOrderItem::where('revision_id', $so->revision_id)->orderBy('id')->sharedLock()->get()->keyBy('id') : collect();
        $customer = Party::where('company_id', $actor->company_id)->whereKey($input['customer_id'])->sharedLock()->firstOrFail();
        $packageIds = [];
        foreach ($input['lines'] as $line) {
            $old = empty($line['item_id']) ? null : $oldItems->get((int) $line['item_id']);
            abort_if(!empty($line['item_id']) && !$old, 404);
            if (!$old || ($line['price_action'] ?? 'keep') === 'catalogue') {
                $packageIds[] = $old ? $old->snapshot['package_id'] : ($line['package_id'] ?? 0);
            }
        }
        // Package ownership is immutable; advisory IDs select parents, then current locks establish eligibility.
        $packages = ProductPackage::where('company_id', $actor->company_id)->whereIn('id', $packageIds)->get();
        $products = Product::where('company_id', $actor->company_id)->whereIn('id', $packages->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $packages = ProductPackage::where('company_id', $actor->company_id)->whereIn('id', $packageIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $rates = TaxRate::where('company_id', $actor->company_id)->whereIn('id', array_filter(array_merge(
            array_column($input['lines'], 'tax_rate_id'),
            $oldItems->map(fn ($i) => $i->snapshot['tax_rate_id'])->all()
        )))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $locations = Op::scoped(StockLocation::class, $actor)->where('branch_id', $branchId)
            ->whereIn('id', array_column($input['lines'], 'location_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];
        foreach ($input['lines'] as $index => $line) {
            $old = empty($line['item_id']) ? null : $oldItems->get((int) $line['item_id']);
            $action = $line['price_action'] ?? ($old ? 'keep' : 'catalogue');
            $snapshot = $old ? $old->snapshot : [];
            if ($old && !empty($line['package_id'])) {
                abort_unless((int) $line['package_id'] === (int) $snapshot['package_id'], 409, 'Replace the line to choose a different package.');
            }
            if (!$old || $action === 'catalogue') {
                $package = $packages->get($old ? $snapshot['package_id'] : (int) ($line['package_id'] ?? 0));
                abort_unless($package, 404);
                $product = $products->get($package->product_id);
                if (!$customer->is_active || !$customer->is_customer || !$package->is_active || !$product || !$product->is_active || $package->sales_price === null) {
                    throw new ValidationException(["lines.$index.package_id" => 'Choose an active customer and package with a catalogue sales price.']);
                }
                if (!$old) {
                    $snapshot = ['product_id' => $product->id, 'sku' => $product->sku, 'description' => $product->description ?: $product->name,
                        'package_id' => $package->id, 'package_name' => $package->name, 'package_code' => $package->code,
                        'conversion' => $package->conversion, 'base_uom' => $product->base_uom,
                        'tax_rate_id' => null, 'tax_label' => null, 'tax_rate' => '0.0000'];
                }
                $snapshot['list_price'] = $package->sales_price;
                $snapshot['source_price'] = $package->sales_price;
                $snapshot['source_mode'] = 'exclusive';
                $snapshot['source_tax_rate'] = '0.0000';
                $snapshot['price_reason'] = null;
            }
            $location = $locations->get((int) $line['location_id']);
            abort_unless($location, 404);
            if (!$location->is_active) {
                throw new ValidationException(["lines.$index.location_id" => 'Choose an active dispatch location.']);
            }
            if (!$old || (int) $old->location_id !== (int) $location->id) {
                $snapshot['location_id'] = $location->id;
                $snapshot['location_code'] = $location->code;
                $snapshot['location_name'] = $location->name;
            }
            $taxId = array_key_exists('tax_rate_id', $line) ? ($line['tax_rate_id'] ?: null) : $snapshot['tax_rate_id'];
            if ((int) $taxId !== (int) $snapshot['tax_rate_id'] || !$old || $action === 'catalogue') {
                $rate = $taxId ? $rates->get((int) $taxId) : null;
                abort_if($taxId && !$rate, 404);
                if ($rate && !$rate->is_active) {
                    throw new ValidationException(["lines.$index.tax_rate_id" => 'Choose an active tax rate.']);
                }
                if (!$old || (int) $taxId !== (int) $snapshot['tax_rate_id']) {
                    $snapshot['tax_rate_id'] = $rate ? $rate->id : null;
                    $snapshot['tax_label'] = $rate ? $rate->name : null;
                    $snapshot['tax_rate'] = $rate ? $rate->rate : '0.0000';
                }
            }
            if ($action === 'override') {
                $price = DocumentTotals::decimal($line['unit_price'] ?? null, "lines.$index.unit_price");
                $listed = DocumentTotals::convertUnitPrice($snapshot['list_price'], 'exclusive', '0', $input['price_mode'], $snapshot['tax_rate']);
                if ($price !== $listed && trim($line['price_reason'] ?? '') === '') {
                    throw new ValidationException(["lines.$index.price_reason" => 'Explain the price override.']);
                }
                $snapshot['source_price'] = $price;
                $snapshot['source_mode'] = $input['price_mode'];
                $snapshot['source_tax_rate'] = $snapshot['tax_rate'];
                $snapshot['price_reason'] = trim($line['price_reason'] ?? '');
            }
            $snapshot['quantity'] = DocumentTotals::decimal($line['quantity'] ?? null, "lines.$index.quantity", true);
            try {
                $snapshot['base_quantity'] = DocumentTotals::baseQuantity($snapshot['quantity'], $snapshot['conversion']);
            } catch (ValidationException $e) {
                throw new ValidationException(["lines.$index.quantity" => 'Base quantity must be exact to four decimals and within range.']);
            }
            $snapshot['unit_price'] = DocumentTotals::convertUnitPrice($snapshot['source_price'], $snapshot['source_mode'], $snapshot['source_tax_rate'], $input['price_mode'], $snapshot['tax_rate']);
            $lines[] = $snapshot;
        }
        return ['branch_id' => $branchId, 'customer_id' => $customer->id, 'customer_snapshot' => $so ? $so->revision->customer_snapshot : $customer->only(['id', 'code', 'name', 'email', 'phone', 'address']),
            'document_date' => $input['document_date'], 'notes' => $input['notes'] ?? null, 'currency' => $company->currency,
            'price_mode' => $input['price_mode'], 'lines' => $lines, 'amounts' => DocumentTotals::calculate($lines, $input['price_mode'])];
    }

    private function revision(SalesOrder $so, array $data, int $actorId): void
    {
        $issuer = $so->revision_id ? $so->revision->issuer_snapshot : null;
        $issuer = $issuer ?: ['company' => Company::findOrFail($so->company_id)->name,
            'branch' => \Vmod\Core\Models\Branch::where('company_id', $so->company_id)->findOrFail($so->branch_id)->name];
        $revision = SalesOrderRevision::create(array_intersect_key($data, array_flip(['document_date', 'notes', 'currency', 'price_mode', 'customer_snapshot']))
            + $data['amounts']['totals'] + ['issuer_snapshot' => $issuer, 'sales_order_id' => $so->id, 'number' => (int) SalesOrderRevision::where('sales_order_id', $so->id)->max('number') + 1,
                'actor_user_id' => $actorId, 'created_at' => now()]);
        foreach ($data['lines'] as $index => $snapshot) {
            SalesOrderItem::create(['revision_id' => $revision->id, 'position' => $index + 1, 'product_id' => $snapshot['product_id'],
                'location_id' => $snapshot['location_id'], 'quantity' => $snapshot['quantity'], 'base_quantity' => $snapshot['base_quantity'], 'snapshot' => $snapshot] + $data['amounts']['lines'][$index]);
        }
        $so->revision_id = $revision->id;
        $so->version++;
        $so->save();
        $so->unsetRelation('revision');
    }

    private function voidDraftDeliveries(SalesOrder $so): void
    {
        DeliveryOrder::where('company_id', $so->company_id)->where('sales_order_id', $so->id)->where('state', 'draft')->update(['state' => 'voided', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
}
