<?php namespace Vmod\Erp\Services;

use DB;
use Brick\Math\BigDecimal;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\SalesOrder;
use Vmod\Erp\Models\SalesOrderItem;
use Vmod\Erp\Models\DeliveryOrder;
use Vmod\Erp\Models\DeliveryOrderItem;
use Vmod\Erp\Models\StockReservation;

class DispatchService
{
    public function saveDraft(array $input): DeliveryOrder
    {
        $input = $this->allowed($input);
        return $this->mutate('deliveryorder.saved', 'deliveryorder.write', $input, function ($actor, $company, $delivery, $operationId, $so) use ($input) {
            $this->openOrder($so);
            abort_unless(!$delivery || $delivery->state === 'draft', 409, 'Only draft deliveries can be edited.');
            $data = $this->header($input);
            $lines = $this->lines($input, $so, $actor);
            if (!$delivery) {
                $delivery = DeliveryOrder::create($data + ['company_id' => $so->company_id, 'branch_id' => $so->branch_id,
                    'sales_order_id' => $so->id, 'revision_id' => $so->revision_id, 'number' => Op::number($so->company_id, 'DO')]);
                $delivery->root_id = $delivery->id;
            } else {
                abort_unless((int) $delivery->revision_id === (int) $so->revision_id, 409);
                $delivery->fill($data);
                $delivery->items()->delete(); // Only unposted draft rows are replaceable; dispatched rows are never edited/deleted.
                $delivery->version++;
            }
            $delivery->save();
            $this->writeLines($delivery, $lines);
            return $delivery->fresh(['items.sales_item', 'items.location']);
        });
    }

    public function dispatch(array $input): DeliveryOrder
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key']));
        return $this->mutate('deliveryorder.dispatched', 'deliveryorder.dispatch', $input, function ($actor, $company, $delivery, $operationId, $so) {
            $this->openOrder($so);
            abort_unless($delivery && $delivery->state === 'draft' && (int) $delivery->revision_id === (int) $so->revision_id, 409, 'Only a current saved draft can be dispatched.');
            $this->lines(['lines' => $delivery->items->map(fn ($i) => $i->only(['sales_order_item_id', 'quantity']))->all()], $so, $actor);
            (new StockService)->postDispatch($delivery, $operationId);
            $delivery->state = 'dispatched';
            $delivery->dispatched_at = now();
            $delivery->version++;
            $delivery->save();
            $so->first_dispatch_at = $so->first_dispatch_at ?: now();
            $this->progress($so);
            return $delivery->fresh(['items.sales_item', 'items.location']);
        });
    }

    public function correct(array $input): DeliveryOrder
    {
        $input = $this->allowed($input);
        return $this->mutate('deliveryorder.corrected', 'deliveryorder.correct', $input, function ($actor, $company, $delivery, $operationId, $so) use ($input) {
            $this->openOrder($so);
            abort_unless($delivery && $delivery->state === 'dispatched', 409, 'Only the current dispatched delivery can be corrected.');
            $reason = Op::reason($input);
            Op::validate($input, ['lines' => 'present|array|max:100']);
            $replacement = $delivery;
            if ($input['lines']) {
                $data = $this->header($input);
                $lines = $this->lines($input, $so, $actor);
                $replacement = DeliveryOrder::create($data + ['company_id' => $so->company_id, 'branch_id' => $so->branch_id,
                    'sales_order_id' => $so->id, 'revision_id' => $so->revision_id, 'number' => Op::number($so->company_id, 'DO'),
                    'root_id' => $delivery->root_id, 'replaces_id' => $delivery->id, 'reason' => $reason]);
                $this->writeLines($replacement, $lines);
            }
            // Reason belongs to the correction action; original item snapshots and quantities stay immutable.
            $replacement->reason = $reason;
            $replacement->save();
            (new StockService)->postDispatch($replacement, $operationId, $delivery);
            $delivery->state = 'voided';
            $delivery->reason = $reason;
            $delivery->version++;
            $delivery->save();
            if ($replacement->id !== $delivery->id) {
                $replacement->state = 'dispatched';
                $replacement->dispatched_at = now();
                $replacement->save();
            } else {
                $replacement = $delivery;
            }
            $this->progress($so);
            return $replacement->fresh(['items.sales_item', 'items.location']);
        });
    }

    public function find(int $id): DeliveryOrder
    {
        return Op::scoped(DeliveryOrder::class, Workspace::authorize('deliveryorder.read'))->with(['items.sales_item', 'items.location', 'sales_order.revision.items'])->findOrFail($id);
    }

    public function delivered(SalesOrder $so): array
    {
        return DB::table('vmod_erp_delivery_order_items as i')->join('vmod_erp_delivery_orders as g', 'g.id', '=', 'i.delivery_order_id')
            ->where('g.sales_order_id', $so->id)->where('g.state', 'dispatched')->groupBy('i.sales_order_item_id')
            ->selectRaw('i.sales_order_item_id, SUM(i.quantity) AS quantity')->pluck('quantity', 'sales_order_item_id')->all();
    }

    private function mutate(string $action, string $permission, array $input, callable $write)
    {
        $so = null;
        return Op::run($action, $permission, $input, function ($actor, $resultId) use ($input, &$so) {
            $id = $resultId ?: ($input['id'] ?? null);
            $advisory = $id ? Op::scoped(DeliveryOrder::class, $actor)->findOrFail($id) : null;
            $soId = $advisory ? $advisory->sales_order_id : ($input['sales_order_id'] ?? null);
            Op::validate(['sales_order_id' => $soId], ['sales_order_id' => 'required|integer|min:1']);
            $so = Op::scoped(SalesOrder::class, $actor)->whereKey($soId)->lockForUpdate()->firstOrFail();
            abort_if(isset($input['sales_order_id']) && (int) $input['sales_order_id'] !== (int) $so->id, 409, 'Delivery parent is fixed.');
            return $id ? Op::scoped(DeliveryOrder::class, $actor)->whereKey($id)->lockForUpdate()->firstOrFail() : null;
        }, function ($actor, $company, $delivery, $operationId) use ($write, &$so) {
            return $write($actor, $company, $delivery, $operationId, $so);
        });
    }

    private function allowed(array $input): array
    {
        $input = array_intersect_key($input, array_flip(['id', 'version', 'operation_key', 'sales_order_id', 'notes', 'document_date', 'reason', 'lines']));
        if (isset($input['lines']) && is_array($input['lines'])) {
            foreach ($input['lines'] as &$line) {
                if (is_array($line)) {
                    $line = array_intersect_key($line, array_flip(['sales_order_item_id', 'quantity']));
                }
            }
        }
        return $input;
    }

    private function header(array $input): array
    {
        return Op::validate($input, ['document_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000']);
    }

    private function openOrder(SalesOrder $so): void
    {
        abort_unless(in_array($so->state, ['approved', 'partially_delivered', 'completed'], true), 409, 'This SO is not open for dispatch or correction.');
    }

    private function lines(array $input, SalesOrder $so, $actor): array
    {
        Op::validate($input, ['lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            'lines.*.sales_order_item_id' => 'required|integer|min:1']);
        if (!array_is_list($input['lines'])) {
            throw new ValidationException(['lines' => 'Supply an ordered list of delivery lines.']);
        }
        $items = SalesOrderItem::where('revision_id', $so->revision_id)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];
        foreach ($input['lines'] as $index => $line) {
            $item = $items->get((int) $line['sales_order_item_id']);
            abort_unless($item, 404);
            $quantity = DocumentTotals::decimal($line['quantity'] ?? null, "lines.$index.quantity", true);
            try {
                $base = DocumentTotals::baseQuantity($quantity, $item->snapshot['conversion']);
            } catch (ValidationException $e) {
                throw new ValidationException(["lines.$index.quantity" => 'Base quantity must be exact to four decimals and within range.']);
            }
            $lines[] = ['sales_order_item_id' => $item->id, 'location_id' => $item->location_id, 'quantity' => $quantity, 'base_quantity' => $base];
        }
        return $lines;
    }

    private function writeLines(DeliveryOrder $delivery, array $lines): void
    {
        foreach ($lines as $line) {
            DeliveryOrderItem::create($line + ['delivery_order_id' => $delivery->id]);
        }
    }

    private function progress(SalesOrder $so): void
    {
        $reservations = StockReservation::where('company_id', $so->company_id)->where('sales_order_id', $so->id)
            ->whereIn('sales_order_item_id', SalesOrderItem::where('revision_id', $so->revision_id)->select('id'))
            ->orderBy('sales_order_item_id')->lockForUpdate()->get();
        $any = false;
        $complete = $reservations->isNotEmpty();
        foreach ($reservations as $reservation) {
            $any = $any || BigDecimal::of($reservation->dispatched)->isPositive();
            $complete = $complete && BigDecimal::of($reservation->dispatched)->isEqualTo($reservation->approved);
        }
        $so->state = $complete ? 'completed' : ($any ? 'partially_delivered' : 'approved');
        $so->version++;
        $so->save();
    }
}
