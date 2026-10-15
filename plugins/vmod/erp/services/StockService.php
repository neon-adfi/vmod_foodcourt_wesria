<?php namespace Vmod\Erp\Services;

use DB;
use Brick\Math\BigDecimal;
use Vmod\Core\Classes\Workspace;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Models\StockBalance;
use Vmod\Erp\Models\StockMovement;
use Vmod\Erp\Models\GoodsReceipt;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Models\ProductPackage;
use Vmod\Erp\Models\SalesOrder;
use Vmod\Erp\Models\SalesOrderItem;
use Vmod\Erp\Models\DeliveryOrder;
use Vmod\Erp\Models\StockReservation;

class StockService
{
    public function open(array $input): StockMovement
    {
        return $this->manual($input, true);
    }

    public function adjust(array $input): StockMovement
    {
        return $this->manual($input, false);
    }

    private function manual(array $input, bool $opening): StockMovement
    {
        $input = array_intersect_key($input, array_flip(['operation_key', 'branch_id', 'location_id', 'product_id', 'expected_base_uom', 'quantity', 'direction', 'reason', 'reference']));
        return Op::run($opening ? 'inventory.opened' : 'inventory.adjusted', 'inventory.adjust', $input, function ($actor, $resultId) use ($input) {
            if ($resultId) {
                return Op::scoped(StockMovement::class, $actor)->whereKey($resultId)->firstOrFail();
            }
            Op::branch($actor, $input['branch_id'] ?? null);
            return null;
        }, function ($actor, $company, $unused, $operationId) use ($input, $opening) {
            Op::validate($input, ['product_id' => 'required|integer|min:1', 'location_id' => 'required|integer|min:1',
                'expected_base_uom' => 'required|string|max:30', 'reference' => 'nullable|string|max:255',
                'direction' => $opening ? 'nullable|in:increase' : 'required|in:increase,decrease']);
            $reason = Op::reason($input);
            $quantity = DocumentTotals::decimal($input['quantity'] ?? null, 'quantity', true);
            $product = Product::where('company_id', $actor->company_id)->whereKey($input['product_id'])->sharedLock()->firstOrFail();
            abort_unless($product->base_uom === $input['expected_base_uom'], 409, 'Product base unit changed. Reload before posting stock.');
            $location = Op::scoped(StockLocation::class, $actor)->where('branch_id', $input['branch_id'])->whereKey($input['location_id'])->sharedLock()->firstOrFail();
            abort_unless($location->is_active && $product->is_active, 409, 'Choose an active product and location.');
            if (($input['direction'] ?? 'increase') === 'decrease') {
                $quantity = (string) BigDecimal::of($quantity)->negated();
            }
            $entry = ['company_id' => $actor->company_id, 'branch_id' => $location->branch_id, 'location_id' => $location->id,
                'product_id' => $product->id, 'quantity' => $quantity, 'base_uom' => $product->base_uom,
                'action' => $opening ? 'opening' : 'adjustment', 'receipt_item_id' => null, 'reason' => $reason, 'reference' => $input['reference'] ?? null];
            $balances = $this->balances([$entry]);
            if ($opening) {
                // Balance lock serializes opening against first receipt/adjustment, even while the ledger is empty.
                abort_if(StockMovement::where($this->key($entry))->sharedLock()->first(), 409, 'Opening stock is allowed only before the first movement.');
            }
            return $this->post([$entry], $balances, $operationId, $actor->user_id)[0];
        });
    }

    /** Internal receipt posting: caller owns the company/PO/GRN transaction; accepts domain records, never browser deltas. */
    public function postReceipt(GoodsReceipt $receipt, $actor, int $operationId, ?GoodsReceipt $reversed = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        Workspace::authorize($reversed ? 'goodsreceipt.correct' : 'goodsreceipt.confirm');
        $entries = [];
        foreach ([[$reversed, 'reversal'], [$receipt, 'receipt']] as [$document, $action]) {
            if (!$document || ($action === 'receipt' && $receipt->id === ($reversed->id ?? null))) {
                continue;
            }
            abort_unless((int) $document->company_id === (int) $actor->company_id, 404);
            Op::branch($actor, $document->branch_id);
            foreach ($document->items()->with('purchase_item')->orderBy('id')->get() as $item) {
                $snapshot = $item->purchase_item->snapshot;
                $entries[] = ['company_id' => $document->company_id, 'branch_id' => $document->branch_id, 'location_id' => $item->location_id,
                    'product_id' => $snapshot['product_id'], 'quantity' => $action === 'reversal' ? (string) BigDecimal::of($item->base_quantity)->negated() : $item->base_quantity,
                    'base_uom' => $snapshot['base_uom'], 'action' => $action, 'receipt_item_id' => $item->id,
                    'reason' => $receipt->reason, 'reference' => $document->number];
            }
        }
        Product::where('company_id', $actor->company_id)->whereIn('id', array_column($entries, 'product_id'))->orderBy('id')->sharedLock()->get();
        $this->post($entries, $this->balances($entries), $operationId, $actor->user_id);
    }

    /** Internal SO approval: caller owns the company/operation transaction; no physical movement is posted. */
    public function reserve(SalesOrder $order): void
    {
        [$order, $actor] = $this->salesOrder($order, 'salesorder.approve');
        abort_unless($order->state === 'draft' && $order->revision_id && !$order->first_dispatch_at, 409);
        $items = SalesOrderItem::where('revision_id', $order->revision_id)->orderBy('id')->sharedLock()->get();
        abort_if($items->isEmpty(), 409);
        abort_if(StockReservation::where('company_id', $order->company_id)->whereIn('sales_order_item_id', $items->pluck('id'))->lockForUpdate()->first(), 409);
        $customer = Party::where('company_id', $order->company_id)->whereKey($order->customer_id)->sharedLock()->firstOrFail();
        $products = Product::where('company_id', $order->company_id)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $packages = ProductPackage::where('company_id', $order->company_id)->whereIn('id', $items->map(fn ($i) => $i->snapshot['package_id']))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $locations = Op::scoped(StockLocation::class, $actor)->where('branch_id', $order->branch_id)->whereIn('id', $items->pluck('location_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        abort_unless($customer->is_active && $customer->is_customer, 409, 'The customer must be active before approval.');
        $entries = [];
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            $package = $packages->get($item->snapshot['package_id']);
            $location = $locations->get($item->location_id);
            abort_unless(
                $product && $product->is_active && $package && $package->is_active && $package->sales_price !== null
                && (int) $package->product_id === (int) $product->id && $location && $location->is_active,
                409,
                'Approval requires active products, priced packages and dispatch locations.'
            );
            $entries[] = ['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'product_id' => $item->product_id,
                'location_id' => $item->location_id, 'quantity' => $item->base_quantity, 'sales_order_item_id' => $item->id];
        }
        $balances = $this->balances($entries);
        foreach ($entries as $entry) {
            $balance = $balances[$this->token($entry)];
            $next = BigDecimal::of($balance->reserved)->plus($entry['quantity']);
            abort_if($next->isGreaterThan($balance->on_hand), 409, 'Insufficient available stock to approve the complete order.');
            $balance->reserved = DocumentTotals::decimal((string) $next, 'quantity');
            $balance->save();
            StockReservation::create($this->key($entry) + ['sales_order_id' => $order->id, 'sales_order_item_id' => $entry['sales_order_item_id'],
                'approved' => $entry['quantity'], 'dispatched' => '0.0000', 'released' => '0.0000']);
        }
    }

    /** Release only this SO's outstanding commitments; historical reservation rows remain. */
    public function release(SalesOrder $order, bool $reopening = false): void
    {
        [$order, $actor] = $this->salesOrder($order, $reopening ? 'salesorder.write' : 'salesorder.close');
        if ($reopening) {
            Workspace::authorize('salesorder.approve');
            abort_unless($order->state === 'approved' && !$order->first_dispatch_at, 409);
        } else {
            abort_unless(in_array($order->state, ['draft', 'approved', 'partially_delivered'], true), 409);
        }
        $reservations = StockReservation::where('company_id', $order->company_id)->where('sales_order_id', $order->id)
            ->orderBy('sales_order_item_id')->lockForUpdate()->get();
        Product::where('company_id', $order->company_id)->whereIn('id', $reservations->pluck('product_id'))->orderBy('id')->sharedLock()->get();
        $balances = $this->balances($reservations->map(fn ($r) => $r->attributesToArray())->all());
        foreach ($reservations as $reservation) {
            $outstanding = BigDecimal::of($reservation->approved)->minus($reservation->dispatched)->minus($reservation->released);
            abort_if($outstanding->isNegative(), 409, 'Reservation quantities are inconsistent.');
            $balance = $balances[$this->token($reservation->attributesToArray())];
            $next = BigDecimal::of($balance->reserved)->minus($outstanding);
            abort_if($next->isNegative(), 409, 'Reserved stock is inconsistent.');
            $balance->reserved = (string) $next->toScale(4);
            $balance->save();
            $reservation->released = (string) BigDecimal::of($reservation->released)->plus($outstanding)->toScale(4);
            $reservation->save();
        }
    }

    /** Consume/restore only server-loaded exact SO-line reservations; caller also commits DO state and audit. */
    public function postDispatch(DeliveryOrder $delivery, int $operationId, ?DeliveryOrder $reversed = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        Workspace::assertContext();
        $actor = Workspace::authorize($reversed ? 'deliveryorder.correct' : 'deliveryorder.dispatch');
        $advisory = Op::scoped(DeliveryOrder::class, $actor)->findOrFail($delivery->id);
        $order = Op::scoped(SalesOrder::class, $actor)->whereKey($advisory->sales_order_id)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($order->state, ['approved', 'partially_delivered', 'completed'], true), 409);
        $documents = Op::scoped(DeliveryOrder::class, $actor)->where('sales_order_id', $order->id)
            ->whereIn('id', array_filter([$delivery->id, $reversed ? $reversed->id : null]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_if($reversed && !$documents->has($reversed->id), 404);
        $delivery = $documents->get($delivery->id);
        $reversed = $reversed ? $documents->get($reversed->id) : null;
        abort_unless($delivery && (int) $delivery->revision_id === (int) $order->revision_id, 404);
        abort_unless($reversed ? $reversed->state === 'dispatched' : $delivery->state === 'draft', 409);
        abort_unless(!$reversed || ((int) $reversed->revision_id === (int) $order->revision_id
            && ($delivery->id === $reversed->id || ($delivery->state === 'draft' && (int) $delivery->replaces_id === (int) $reversed->id))), 409);
        $items = SalesOrderItem::where('revision_id', $order->revision_id)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $reservations = StockReservation::where('company_id', $order->company_id)->where('sales_order_id', $order->id)
            ->whereIn('sales_order_item_id', $items->keys())->orderBy('sales_order_item_id')->lockForUpdate()->get()->keyBy('sales_order_item_id');
        Product::where('company_id', $order->company_id)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->sharedLock()->get();
        $entries = [];
        $consumed = [];
        foreach ([[$reversed, 'dispatch_reversal'], [$delivery, 'dispatch']] as [$document, $action]) {
            if (!$document || ($action === 'dispatch' && $delivery->id === ($reversed->id ?? null))) {
                continue;
            }
            foreach ($document->items()->orderBy('id')->get() as $item) {
                $source = $items->get($item->sales_order_item_id);
                $reservation = $reservations->get($item->sales_order_item_id);
                abort_unless($source && $reservation && (int) $reservation->location_id === (int) $source->location_id
                    && (int) $item->location_id === (int) $source->location_id && (int) $reservation->product_id === (int) $source->product_id, 404);
                $quantity = DocumentTotals::baseQuantity($item->quantity, $source->snapshot['conversion']);
                abort_unless($quantity === $item->base_quantity, 409);
                $delta = BigDecimal::of($quantity)->multipliedBy($action === 'dispatch' ? '1' : '-1');
                $consumed[$source->id] = ($consumed[$source->id] ?? BigDecimal::of('0'))->plus($delta);
                $entries[] = ['company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'location_id' => $source->location_id,
                    'product_id' => $source->product_id, 'quantity' => (string) $delta->negated()->toScale(4), 'base_uom' => $source->snapshot['base_uom'],
                    'action' => $action, 'receipt_item_id' => null, 'delivery_item_id' => $item->id, 'reason' => $delivery->reason,
                    'reference' => $document->number];
            }
        }
        $balances = $this->balances($entries);
        $reservedChanges = [];
        foreach ($consumed as $itemId => $delta) {
            $reservation = $reservations->get($itemId);
            $next = BigDecimal::of($reservation->dispatched)->plus($delta);
            abort_if($next->isNegative() || $next->plus($reservation->released)->isGreaterThan($reservation->approved), 409, 'Delivery exceeds this order line’s outstanding reservation.');
            $reservation->dispatched = (string) $next->toScale(4);
            $reservation->save();
            $token = $this->token($reservation->attributesToArray());
            $reservedChanges[$token] = ($reservedChanges[$token] ?? BigDecimal::of('0'))->minus($delta);
        }
        foreach ($reservedChanges as $token => $delta) {
            $balance = $balances[$token];
            $nextReserved = BigDecimal::of($balance->reserved)->plus($delta);
            abort_if($nextReserved->isNegative(), 409, 'Reserved stock is inconsistent.');
            // Keep this in memory until post() saves on-hand and reserved together after checking the final net change.
            $balance->reserved = DocumentTotals::decimal((string) $nextReserved->toScale(4), 'quantity');
        }
        $this->post($entries, $balances, $operationId, $actor->user_id);
    }

    private function salesOrder(SalesOrder $order, string $permission): array
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        Workspace::assertContext();
        $actor = Workspace::authorize($permission);
        return [Op::scoped(SalesOrder::class, $actor)->whereKey($order->id)->lockForUpdate()->firstOrFail(), $actor];
    }

    private function key(array $entry): array
    {
        return array_intersect_key($entry, array_flip(['company_id', 'branch_id', 'location_id', 'product_id']));
    }

    private function token(array $entry): string
    {
        return implode(':', [$entry['company_id'], $entry['branch_id'], $entry['location_id'], $entry['product_id']]);
    }

    private function balances(array $entries): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            $keys[$this->token($entry)] = $this->key($entry);
        }
        ksort($keys, SORT_NATURAL);
        $balances = [];
        foreach ($keys as $token => $key) {
            DB::table('vmod_erp_stock_balances')->insertOrIgnore($key + ['on_hand' => '0.0000']);
            $balances[$token] = StockBalance::where($key)->lockForUpdate()->firstOrFail();
        }
        return $balances;
    }

    private function post(array $entries, array $balances, int $operationId, int $actorId): array
    {
        $totals = [];
        foreach ($entries as $entry) {
            $token = $this->token($entry);
            $totals[$token] = ($totals[$token] ?? BigDecimal::of('0'))->plus($entry['quantity']);
        }
        foreach ($totals as $token => $delta) {
            $next = BigDecimal::of($balances[$token]->on_hand)->plus($delta);
            abort_if($next->isLessThan($balances[$token]->reserved), 409, 'Insufficient available stock for this adjustment or correction.');
            $balances[$token]->on_hand = DocumentTotals::decimal((string) $next->toScale(4), 'quantity');
            $balances[$token]->save();
        }
        $movements = [];
        foreach ($entries as $entry) {
            $movements[] = StockMovement::create($entry + ['operation_id' => $operationId, 'actor_user_id' => $actorId, 'created_at' => now()]);
        }
        return $movements;
    }
}
