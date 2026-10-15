<?php namespace Vmod\Erp\Services;

use DB;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Company;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\PurchaseOrder;
use Vmod\Erp\Models\PurchaseOrderRevision;
use Vmod\Erp\Models\PurchaseOrderItem;
use Vmod\Erp\Models\GoodsReceipt;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\ProductPackage;
use Vmod\Erp\Models\SupplierOffer;
use Vmod\Erp\Models\TaxRate;

class PurchasingService
{
    public function saveDraft(array $input): PurchaseOrder
    {
        $input = $this->allowed($input);
        return $this->mutate('purchaseorder.saved', 'purchaseorder.write', $input, function ($actor, $company, $po) use ($input) {
            abort_unless(!$po || $po->state === 'draft', 409, 'Only a draft PO can be edited.');
            $data = $this->build($input, $actor, $company, $po);
            if (!$po) {
                $po = PurchaseOrder::create(['company_id' => $company->id, 'branch_id' => $data['branch_id'],
                    'supplier_id' => $data['supplier_id'], 'number' => Op::number($company->id, 'PO')]);
            }
            $this->revision($po, $data, $actor->user_id);
            return $po->fresh(['revision.items']);
        });
    }

    public function previewDraft(array $input): array
    {
        Workspace::assertContext();
        $companyId = Workspace::authorize('purchaseorder.write')->company_id;
        $input = $this->allowed($input);
        return DB::transaction(function () use ($companyId, $input) {
            $company = Company::whereKey($companyId)->sharedLock()->firstOrFail();
            $actor = Workspace::authorize('purchaseorder.write');
            $po = empty($input['id']) ? null : Op::scoped(PurchaseOrder::class, $actor)->whereKey($input['id'])->sharedLock()->firstOrFail();
            abort_unless(!$po || $po->state === 'draft', 409);
            if ($po) {
                Op::validate($input, ['version' => 'required|integer|min:1']);
                abort_unless((int) $po->version === (int) $input['version'], 409, 'This document changed. Reload before previewing.');
            }
            return $this->build($input, $actor, $company, $po);
        });
    }

    public function repriceDraft(array $input): PurchaseOrder
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'item_ids']));
        return $this->mutate('purchaseorder.repriced', 'purchaseorder.write', $input, function ($actor, $company, $po) use ($input) {
            abort_unless($po && $po->state === 'draft' && !$po->first_received_at, 409);
            Op::validate($input, ['item_ids' => 'required|array|min:1', 'item_ids.*' => 'required|integer|min:1|distinct']);
            $revision = $po->revision;
            $items = $revision->items;
            $ids = array_map('intval', $input['item_ids']);
            abort_if(array_diff($ids, $items->pluck('id')->all()), 404);
            $data = $this->build(['branch_id' => $po->branch_id, 'supplier_id' => $po->supplier_id,
                'document_date' => $revision->document_date, 'notes' => $revision->notes, 'expected_currency' => $revision->currency,
                'price_mode' => $revision->price_mode, 'lines' => $items->map(fn ($item) => ['item_id' => $item->id,
                    'quantity' => $item->quantity, 'price_action' => in_array($item->id, $ids, true) ? 'offer' : 'keep'])->all()], $actor, $company, $po);
            $this->revision($po, $data, $actor->user_id);
            return $po->fresh(['revision.items']);
        });
    }

    public function approve(array $input): PurchaseOrder
    {
        return $this->transition('approved', 'purchaseorder.approve', $input, function ($po) {
            abort_unless($po->state === 'draft' && $po->revision_id, 409, 'Only a saved draft can be approved.');
            $po->revision->update(['approved_at' => now()]);
        });
    }

    public function reopen(array $input): PurchaseOrder
    {
        return $this->transition('draft', 'purchaseorder.write', $input, function ($po, $actor) use ($input) {
            abort_unless($po->state === 'approved' && !$po->first_received_at, 409, 'Receiving has begun or this order cannot be reopened.');
            Op::reason($input);
            $this->voidDraftReceipts($po);
            $old = $po->revision;
            $this->revision($po, ['supplier_snapshot' => $old->supplier_snapshot, 'document_date' => $old->document_date,
                'notes' => $old->notes, 'currency' => $old->currency, 'price_mode' => $old->price_mode,
                'lines' => $old->items->map(fn ($i) => $i->snapshot)->all(),
                'amounts' => ['totals' => $old->only(['net', 'tax', 'gross']), 'lines' => $old->items->map(fn ($i) => $i->only(['net', 'tax', 'gross']))->all()]], $actor->user_id);
        }, ['purchaseorder.approve']);
    }

    public function void(array $input): PurchaseOrder
    {
        return $this->transition('voided', 'purchaseorder.close', $input, function ($po) use ($input) {
            abort_unless(in_array($po->state, ['draft', 'approved'], true) && !$po->first_received_at, 409, 'Use close-short after receiving has begun.');
            $po->close_reason = Op::reason($input);
            $this->voidDraftReceipts($po);
        });
    }

    public function closeShort(array $input): PurchaseOrder
    {
        return $this->transition('closed_short', 'purchaseorder.close', $input, function ($po) use ($input) {
            abort_unless(in_array($po->state, ['approved', 'partially_received'], true) && $po->first_received_at, 409, 'Close-short requires prior receiving and an outstanding remainder.');
            $po->close_reason = Op::reason($input);
            $this->voidDraftReceipts($po);
        });
    }

    public function find(int $id): PurchaseOrder
    {
        return Op::scoped(PurchaseOrder::class, Workspace::authorize('purchaseorder.read'))->with(['revision.items', 'revisions.items'])->findOrFail($id);
    }

    private function transition(string $state, string $permission, array $input, callable $check, array $extra = []): PurchaseOrder
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'reason']));
        return $this->mutate('purchaseorder.' . $state, $permission, $input, function ($actor, $company, $po) use ($state, $input, $check) {
            abort_unless($po, 404);
            $check($po, $actor);
            $po->state = $state;
            $po->version++;
            $po->save();
            return $po->fresh(['revision.items']);
        }, $extra);
    }

    private function mutate(string $action, string $permission, array $input, callable $write, array $extra = [])
    {
        return Op::run($action, $permission, $input, function ($actor, $resultId) use ($input) {
            $id = $resultId ?: ($input['id'] ?? null);
            if ($id) {
                return Op::scoped(PurchaseOrder::class, $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            }
            Op::branch($actor, $input['branch_id'] ?? null);
            return null;
        }, $write, $extra);
    }

    private function allowed(array $input): array
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'branch_id', 'supplier_id', 'expected_currency', 'document_date', 'notes', 'price_mode', 'lines']));
        if (isset($input['lines']) && is_array($input['lines'])) {
            foreach ($input['lines'] as &$line) {
                if (is_array($line)) {
                    $line = array_intersect_key($line, array_flip(['item_id', 'offer_id', 'quantity', 'tax_rate_id', 'price_action', 'unit_price', 'price_reason']));
                }
            }
        }
        return $input;
    }

    private function build(array $input, $actor, $company, ?PurchaseOrder $po): array
    {
        Op::validate($input, ['branch_id' => 'required|integer|min:1', 'supplier_id' => 'required|integer|min:1',
            'expected_currency' => 'required|string|size:3', 'document_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000',
            'price_mode' => 'required|in:exclusive,inclusive', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            'lines.*.item_id' => 'nullable|integer|min:1|distinct', 'lines.*.offer_id' => 'nullable|integer|min:1',
            'lines.*.tax_rate_id' => 'nullable|integer|min:1', 'lines.*.price_action' => 'nullable|in:keep,offer,override',
            'lines.*.price_reason' => 'nullable|string|max:2000']);
        if (!array_is_list($input['lines'])) {
            throw new ValidationException(['lines' => 'Supply an ordered list of lines.']);
        }
        $branchId = Op::branch($actor, $input['branch_id']);
        abort_unless($input['expected_currency'] === $company->currency, 409, 'Currency changed. Reload.');
        abort_if($po && ((int) $po->supplier_id !== (int) $input['supplier_id'] || (int) $po->branch_id !== $branchId), 409, 'Supplier and branch are fixed after the first save.');
        $oldItems = $po ? PurchaseOrderItem::where('revision_id', $po->revision_id)->orderBy('id')->sharedLock()->get()->keyBy('id') : collect();
        $supplier = Party::where('company_id', $actor->company_id)->whereKey($input['supplier_id'])->sharedLock()->firstOrFail();
        $offerIds = [];
        foreach ($input['lines'] as $line) {
            $old = empty($line['item_id']) ? null : $oldItems->get((int) $line['item_id']);
            abort_if(!empty($line['item_id']) && !$old, 404);
            if (!$old || ($line['price_action'] ?? 'keep') === 'offer') {
                $offerIds[] = $old ? $old->snapshot['offer_id'] : ($line['offer_id'] ?? 0);
            }
        }
        // Offer/package ownership is immutable; advisory IDs select parents, then current locks establish eligibility.
        $offers = SupplierOffer::where('company_id', $actor->company_id)->whereIn('id', $offerIds)->get();
        $packages = ProductPackage::where('company_id', $actor->company_id)->whereIn('id', $offers->pluck('package_id'))->get();
        $products = Product::where('company_id', $actor->company_id)->whereIn('id', $packages->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $packages = ProductPackage::where('company_id', $actor->company_id)->whereIn('id', $packages->pluck('id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $offers = SupplierOffer::where('company_id', $actor->company_id)->whereIn('id', $offerIds)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $taxIds = array_filter(array_column($input['lines'], 'tax_rate_id'));
        foreach ($oldItems as $item) {
            $taxIds[] = $item->snapshot['tax_rate_id'];
        }
        $rates = TaxRate::where('company_id', $actor->company_id)->whereIn('id', array_filter($taxIds))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];
        foreach ($input['lines'] as $index => $line) {
            $old = empty($line['item_id']) ? null : $oldItems->get((int) $line['item_id']);
            $action = $line['price_action'] ?? ($old ? 'keep' : 'offer');
            $snapshot = $old ? $old->snapshot : [];
            if ($old && !empty($line['offer_id'])) {
                abort_unless((int) $line['offer_id'] === (int) $snapshot['offer_id'], 409, 'Replace the line to choose a different package.');
            }
            if (!$old || $action === 'offer') {
                $offer = $offers->get($old ? $snapshot['offer_id'] : (int) ($line['offer_id'] ?? 0));
                abort_unless($offer && (int) $offer->supplier_id === (int) $supplier->id, 404);
                $package = $packages->get($offer->package_id);
                $product = $package ? $products->get($package->product_id) : null;
                foreach ([$supplier, $offer, $package, $product] as $master) {
                    if (!$master || !$master->is_active || !$supplier->is_supplier) {
                        throw new ValidationException(["lines.$index.offer_id" => 'Choose an active supplier offer and package.']);
                    }
                }
                if (!$old) {
                    $snapshot = ['product_id' => $product->id, 'sku' => $product->sku, 'description' => $product->description ?: $product->name,
                        'package_id' => $package->id, 'package_name' => $package->name, 'package_code' => $package->code,
                        'conversion' => $package->conversion, 'base_uom' => $product->base_uom, 'offer_id' => $offer->id,
                        'tax_rate_id' => null, 'tax_label' => null, 'tax_rate' => '0.0000'];
                }
                $snapshot['offered_price'] = $offer->unit_price;
                $snapshot['source_price'] = $offer->unit_price;
                $snapshot['source_mode'] = 'exclusive';
                $snapshot['source_tax_rate'] = '0.0000';
                $snapshot['price_reason'] = null;
            }
            $taxId = array_key_exists('tax_rate_id', $line) ? ($line['tax_rate_id'] ?: null) : $snapshot['tax_rate_id'];
            if ((int) $taxId !== (int) $snapshot['tax_rate_id'] || !$old || $action === 'offer') {
                $rate = $taxId ? $rates->get((int) $taxId) : null;
                abort_if($taxId && !$rate, 404);
                if ($rate && !$rate->is_active) {
                    throw new ValidationException(["lines.$index.tax_rate_id" => 'Choose an active tax rate.']);
                }
                // Explicit repricing refreshes offer price only; an unchanged tax selection retains its historical rate.
                if (!$old || (int) $taxId !== (int) $snapshot['tax_rate_id']) {
                    $snapshot['tax_rate_id'] = $rate ? $rate->id : null;
                    $snapshot['tax_label'] = $rate ? $rate->name : null;
                    $snapshot['tax_rate'] = $rate ? $rate->rate : '0.0000';
                }
            }
            if ($action === 'override') {
                $price = DocumentTotals::decimal($line['unit_price'] ?? null, "lines.$index.unit_price");
                $offered = $this->converted($snapshot['offered_price'], 'exclusive', '0', $input['price_mode'], $snapshot['tax_rate']);
                if ($price !== $offered && trim($line['price_reason'] ?? '') === '') {
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
            $snapshot['unit_price'] = $this->converted($snapshot['source_price'], $snapshot['source_mode'], $snapshot['source_tax_rate'], $input['price_mode'], $snapshot['tax_rate']);
            $lines[] = $snapshot;
        }
        return ['branch_id' => $branchId, 'supplier_id' => $supplier->id, 'supplier_snapshot' => $po ? $po->revision->supplier_snapshot : $supplier->only(['id', 'code', 'name', 'email', 'phone', 'address']),
            'document_date' => $input['document_date'], 'notes' => $input['notes'] ?? null, 'currency' => $company->currency,
            'price_mode' => $input['price_mode'], 'lines' => $lines, 'amounts' => DocumentTotals::calculate($lines, $input['price_mode'])];
    }

    private function converted(string $price, string $sourceMode, string $sourceTax, string $mode, string $tax): string
    {
        $net = BigDecimal::of($price)->toBigRational();
        if ($sourceMode === 'inclusive') {
            $net = $net->dividedBy(BigDecimal::of($sourceTax)->dividedBy('100', 6)->plus('1'));
        }
        if ($mode === 'inclusive') {
            $net = $net->multipliedBy(BigDecimal::of($tax)->dividedBy('100', 6)->plus('1'));
        }
        return DocumentTotals::decimal((string) $net->toScale(4, RoundingMode::HalfUp), 'unit_price');
    }

    private function revision(PurchaseOrder $po, array $data, int $actorId): void
    {
        $revision = PurchaseOrderRevision::create(array_intersect_key($data, array_flip(['document_date', 'notes', 'currency', 'price_mode', 'supplier_snapshot']))
            + $data['amounts']['totals'] + ['purchase_order_id' => $po->id, 'number' => (int) PurchaseOrderRevision::where('purchase_order_id', $po->id)->max('number') + 1,
                'actor_user_id' => $actorId, 'created_at' => now()]);
        foreach ($data['lines'] as $index => $snapshot) {
            PurchaseOrderItem::create(['revision_id' => $revision->id, 'position' => $index + 1, 'product_id' => $snapshot['product_id'],
                'quantity' => $snapshot['quantity'], 'base_quantity' => $snapshot['base_quantity'], 'snapshot' => $snapshot] + $data['amounts']['lines'][$index]);
        }
        $po->revision_id = $revision->id;
        $po->version++;
        $po->save();
        $po->unsetRelation('revision');
    }

    private function voidDraftReceipts(PurchaseOrder $po): void
    {
        GoodsReceipt::where('company_id', $po->company_id)->where('purchase_order_id', $po->id)->where('state', 'draft')->update(['state' => 'voided', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
}
