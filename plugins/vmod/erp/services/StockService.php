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
            // Phase 5 will subtract outstanding reservations here; Phase 4 has no reservation rows.
            abort_if($next->isNegative(), 409, 'Insufficient stock for this adjustment or correction.');
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
