<?php namespace Vmod\Erp\Services;

use DB;
use Brick\Math\BigDecimal;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\PurchaseOrder;
use Vmod\Erp\Models\PurchaseOrderItem;
use Vmod\Erp\Models\GoodsReceipt;
use Vmod\Erp\Models\GoodsReceiptItem;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Models\Product;

class ReceivingService
{
    public function saveDraft(array $input): GoodsReceipt
    {
        $input = $this->allowed($input);
        return $this->mutate('goodsreceipt.saved', 'goodsreceipt.write', $input, function ($actor, $company, $grn, $operationId, $po) use ($input) {
            $this->openOrder($po);
            abort_unless(!$grn || $grn->state === 'draft', 409, 'Only draft receipts can be edited.');
            $data = $this->header($input);
            $lines = $this->lines($input, $po, $actor);
            if (!$grn) {
                $grn = GoodsReceipt::create($data + ['company_id' => $po->company_id, 'branch_id' => $po->branch_id,
                    'purchase_order_id' => $po->id, 'revision_id' => $po->revision_id, 'number' => Op::number($po->company_id, 'GRN')]);
                $grn->root_id = $grn->id;
            } else {
                abort_unless((int) $grn->revision_id === (int) $po->revision_id, 409);
                $grn->fill($data);
                $grn->items()->delete(); // Only unposted draft rows are replaceable; confirmed rows are never edited/deleted.
                $grn->version++;
            }
            $grn->save();
            $this->writeLines($grn, $lines);
            return $grn->fresh(['items.purchase_item', 'items.location']);
        });
    }

    public function confirm(array $input): GoodsReceipt
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key']));
        return $this->mutate('goodsreceipt.confirmed', 'goodsreceipt.confirm', $input, function ($actor, $company, $grn, $operationId, $po) {
            $this->openOrder($po);
            abort_unless($grn && $grn->state === 'draft' && (int) $grn->revision_id === (int) $po->revision_id, 409, 'Only a current saved draft can be confirmed.');
            $lines = $this->lines(['lines' => $grn->items->map(fn ($i) => $i->only(['purchase_order_item_id', 'location_id', 'quantity']))->all()], $po, $actor);
            $this->withinOrder($po, $lines);
            $this->claimReference($po, $grn);
            (new StockService)->postReceipt($grn, $actor, $operationId);
            $grn->state = 'confirmed';
            $grn->confirmed_at = now();
            $grn->version++;
            $grn->save();
            $po->first_received_at = $po->first_received_at ?: now();
            $this->progress($po);
            return $grn->fresh(['items.purchase_item', 'items.location']);
        });
    }

    public function correct(array $input): GoodsReceipt
    {
        $input = $this->allowed($input);
        return $this->mutate('goodsreceipt.corrected', 'goodsreceipt.correct', $input, function ($actor, $company, $grn, $operationId, $po) use ($input) {
            $this->openOrder($po);
            abort_unless($grn && $grn->state === 'confirmed', 409, 'Only the current confirmed receipt can be corrected.');
            $reason = Op::reason($input);
            Op::validate($input, ['lines' => 'present|array|max:100']);
            $replacement = $grn;
            if ($input['lines']) {
                $data = $this->header($input);
                $lines = $this->lines($input, $po, $actor);
                $this->withinOrder($po, $lines, $grn->id);
                $replacement = GoodsReceipt::create($data + ['company_id' => $po->company_id, 'branch_id' => $po->branch_id,
                    'purchase_order_id' => $po->id, 'revision_id' => $po->revision_id, 'number' => Op::number($po->company_id, 'GRN'),
                    'root_id' => $grn->root_id, 'replaces_id' => $grn->id, 'reason' => $reason]);
                $this->writeLines($replacement, $lines);
                $this->claimReference($po, $replacement);
            }
            // Reason belongs to the correction action; original item snapshots and quantities stay immutable.
            $replacement->reason = $reason;
            (new StockService)->postReceipt($replacement, $actor, $operationId, $grn);
            $grn->state = 'voided';
            $grn->reason = $reason;
            $grn->version++;
            $grn->save();
            if ($replacement->id !== $grn->id) {
                $replacement->state = 'confirmed';
                $replacement->confirmed_at = now();
                $replacement->save();
            } else {
                $replacement = $grn;
            }
            $this->progress($po);
            return $replacement->fresh(['items.purchase_item', 'items.location']);
        });
    }

    public function find(int $id): GoodsReceipt
    {
        return Op::scoped(GoodsReceipt::class, Workspace::authorize('goodsreceipt.read'))->with(['items.purchase_item', 'items.location', 'purchase_order.revision.items'])->findOrFail($id);
    }

    public function received(PurchaseOrder $po): array
    {
        return DB::table('vmod_erp_goods_receipt_items as i')->join('vmod_erp_goods_receipts as g', 'g.id', '=', 'i.goods_receipt_id')
            ->where('g.purchase_order_id', $po->id)->where('g.state', 'confirmed')->groupBy('i.purchase_order_item_id')
            ->selectRaw('i.purchase_order_item_id, SUM(i.quantity) AS quantity')->pluck('quantity', 'purchase_order_item_id')->all();
    }

    private function mutate(string $action, string $permission, array $input, callable $write)
    {
        $po = null;
        return Op::run($action, $permission, $input, function ($actor, $resultId) use ($input, &$po) {
            $id = $resultId ?: ($input['id'] ?? null);
            $advisory = $id ? Op::scoped(GoodsReceipt::class, $actor)->findOrFail($id) : null;
            $poId = $advisory ? $advisory->purchase_order_id : ($input['purchase_order_id'] ?? null);
            Op::validate(['purchase_order_id' => $poId], ['purchase_order_id' => 'required|integer|min:1']);
            $po = Op::scoped(PurchaseOrder::class, $actor)->whereKey($poId)->lockForUpdate()->firstOrFail();
            abort_if(isset($input['purchase_order_id']) && (int) $input['purchase_order_id'] !== (int) $po->id, 409, 'Receipt parent is fixed.');
            return $id ? Op::scoped(GoodsReceipt::class, $actor)->whereKey($id)->lockForUpdate()->firstOrFail() : null;
        }, function ($actor, $company, $grn, $operationId) use ($write, &$po) {
            return $write($actor, $company, $grn, $operationId, $po);
        });
    }

    private function allowed(array $input): array
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'purchase_order_id', 'supplier_reference', 'document_date', 'reason', 'lines']));
        if (isset($input['lines']) && is_array($input['lines'])) {
            foreach ($input['lines'] as &$line) {
                if (is_array($line)) {
                    $line = array_intersect_key($line, array_flip(['purchase_order_item_id', 'location_id', 'quantity']));
                }
            }
        }
        return $input;
    }

    private function header(array $input): array
    {
        $data = Op::validate($input, ['supplier_reference' => 'required|string|max:160', 'document_date' => 'required|date_format:Y-m-d']);
        $data['supplier_reference'] = trim($data['supplier_reference']);
        if ($data['supplier_reference'] === '') {
            throw new ValidationException(['supplier_reference' => 'Enter the supplier delivery reference.']);
        }
        return $data;
    }

    private function openOrder(PurchaseOrder $po): void
    {
        abort_unless(in_array($po->state, ['approved', 'partially_received', 'completed'], true), 409, 'This PO is not open for receiving or correction.');
    }

    private function lines(array $input, PurchaseOrder $po, $actor): array
    {
        Op::validate($input, ['lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            'lines.*.purchase_order_item_id' => 'required|integer|min:1', 'lines.*.location_id' => 'required|integer|min:1']);
        if (!array_is_list($input['lines'])) {
            throw new ValidationException(['lines' => 'Supply an ordered list of receipt lines.']);
        }
        $items = PurchaseOrderItem::where('revision_id', $po->revision_id)->orderBy('id')->sharedLock()->get()->keyBy('id');
        Product::where('company_id', $po->company_id)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->sharedLock()->get();
        $locations = Op::scoped(StockLocation::class, $actor)->where('branch_id', $po->branch_id)
            ->whereIn('id', array_column($input['lines'], 'location_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];
        foreach ($input['lines'] as $index => $line) {
            $item = $items->get((int) $line['purchase_order_item_id']);
            $location = $locations->get((int) $line['location_id']);
            abort_unless($item && $location, 404);
            if (!$location->is_active) {
                throw new ValidationException(["lines.$index.location_id" => 'Choose an active receiving location.']);
            }
            $quantity = DocumentTotals::decimal($line['quantity'] ?? null, "lines.$index.quantity", true);
            try {
                $base = DocumentTotals::baseQuantity($quantity, $item->snapshot['conversion']);
            } catch (ValidationException $e) {
                throw new ValidationException(["lines.$index.quantity" => 'Base quantity must be exact to four decimals and within range.']);
            }
            $lines[] = ['purchase_order_item_id' => $item->id, 'location_id' => $location->id, 'location_name' => $location->name, 'quantity' => $quantity, 'base_quantity' => $base];
        }
        return $lines;
    }

    private function withinOrder(PurchaseOrder $po, array $lines, ?int $excluding = null): void
    {
        // Current locking reads are required after waiting for another confirmation under MySQL REPEATABLE READ.
        $receipts = GoodsReceipt::where('company_id', $po->company_id)->where('purchase_order_id', $po->id)->where('state', 'confirmed')
            ->when($excluding, fn ($q) => $q->where('id', '<>', $excluding))->orderBy('id')->lockForUpdate()->get();
        $effective = GoodsReceiptItem::whereIn('goods_receipt_id', $receipts->pluck('id'))->orderBy('id')->sharedLock()->get();
        $quantities = [];
        foreach (array_merge($effective->toArray(), $lines) as $line) {
            $id = $line['purchase_order_item_id'];
            $quantities[$id] = ($quantities[$id] ?? BigDecimal::of('0'))->plus($line['quantity']);
        }
        foreach (PurchaseOrderItem::where('revision_id', $po->revision_id)->sharedLock()->get() as $item) {
            abort_if(($quantities[$item->id] ?? BigDecimal::of('0'))->isGreaterThan($item->quantity), 409, 'Receipt exceeds the approved remaining quantity.');
        }
    }

    private function claimReference(PurchaseOrder $po, GoodsReceipt $grn): void
    {
        $key = ['company_id' => $po->company_id, 'supplier_id' => $po->supplier_id,
            'normalized_reference' => mb_strtoupper(trim($grn->supplier_reference), 'UTF-8')];
        DB::table('vmod_erp_delivery_references')->insertOrIgnore($key + ['root_id' => $grn->root_id]);
        $claim = DB::table('vmod_erp_delivery_references')->where($key)->lockForUpdate()->first();
        abort_unless((int) $claim->root_id === (int) $grn->root_id, 409, 'This supplier delivery reference already belongs to another receipt.');
    }

    private function writeLines(GoodsReceipt $grn, array $lines): void
    {
        foreach ($lines as $line) {
            GoodsReceiptItem::create($line + ['goods_receipt_id' => $grn->id]);
        }
    }

    private function progress(PurchaseOrder $po): void
    {
        $receipts = GoodsReceipt::where('company_id', $po->company_id)->where('purchase_order_id', $po->id)->where('state', 'confirmed')->orderBy('id')->lockForUpdate()->get();
        $received = GoodsReceiptItem::whereIn('goods_receipt_id', $receipts->pluck('id'))->orderBy('id')->sharedLock()->get()->groupBy('purchase_order_item_id');
        $complete = true;
        foreach (PurchaseOrderItem::where('revision_id', $po->revision_id)->sharedLock()->get() as $item) {
            $sum = BigDecimal::of('0');
            foreach ($received->get($item->id, collect()) as $line) {
                $sum = $sum->plus($line->quantity);
            }
            $complete = $complete && $sum->isEqualTo($item->quantity);
        }
        $po->state = $complete ? 'completed' : ($received->isEmpty() ? 'approved' : 'partially_received');
        $po->version++;
        $po->save();
    }
}
