<?php namespace Vmod\Erp\Components;

use DB;
use Flash;
use Redirect;
use Illuminate\Support\Str;
use Brick\Math\BigDecimal;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\PurchaseOrder;
use Vmod\Erp\Models\GoodsReceipt;
use Vmod\Erp\Models\SupplierOffer;
use Vmod\Erp\Models\TaxRate;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Services\PurchasingService;
use Vmod\Erp\Services\ReceivingService;

class Purchasing extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Purchasing', 'description' => 'Purchase orders and receiving'];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $kind = $this->property('kind', 'orders');
        $permission = $kind === 'receipts' ? 'goodsreceipt.read' : 'purchaseorder.read';
        $actor = Workspace::authorize($permission);
        $filters = Op::validate(get(), ['q' => 'nullable|string|max:100', 'state' => 'nullable|in:draft,approved,partially_received,completed,confirmed,voided,closed_short',
            'id' => 'nullable|integer|min:1', 'new' => 'nullable|in:1', 'po' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $class = $kind === 'receipts' ? GoodsReceipt::class : PurchaseOrder::class;
        $query = Op::scoped($class, $actor);
        if (!empty($filters['q'])) {
            $query->where(function ($q) use ($filters, $kind) {
                $q->where('number', 'like', '%' . $filters['q'] . '%');
                if ($kind === 'receipts') {
                    $q->orWhere('supplier_reference', 'like', '%' . $filters['q'] . '%');
                }
            });
        }
        if (!empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        $record = empty($filters['id']) ? null : ($kind === 'receipts'
            ? (new ReceivingService)->find((int) $filters['id']) : (new PurchasingService)->find((int) $filters['id']));
        $po = $kind === 'orders' ? $record : ($record ? $record->purchase_order : null);
        if ($kind === 'receipts' && !$record && !empty($filters['po'])) {
            $po = Op::scoped(PurchaseOrder::class, $actor)->with('revision.items')->findOrFail($filters['po']);
        }
        $this->page['kind'] = $kind;
        $this->page['record'] = $record;
        $this->page['po'] = $po;
        $this->page['filters'] = $filters;
        $this->page['records'] = $query->orderByDesc('id')->paginate(15)->appends(array_intersect_key($filters, array_flip(['q', 'state'])));
        $this->page['workspaceMember'] = $actor;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($actor->role->permissions ?? [], true);
        $this->page['branches'] = $actor->branches()->when(Workspace::branchId(), fn ($q) => $q->where('vmod_core_branches.id', Workspace::branchId()))->get();
        $this->page['operationKey'] = (string) Str::uuid();
        $this->page['today'] = date('Y-m-d');
        if ($kind === 'orders' && Workspace::hasAccess('purchaseorder.write')) {
            // Narrow selectors belong to the purchasing action, not the catalogue management permission.
            $this->page['offers'] = SupplierOffer::where('company_id', $actor->company_id)->where('is_active', true)
                ->whereHas('supplier', fn ($q) => $q->where('is_active', true)->where('is_supplier', true))
                ->whereHas('package', fn ($q) => $q->where('is_active', true)->whereHas('product', fn ($p) => $p->where('is_active', true)))
                ->with(['supplier', 'package.product'])->orderBy('id')->get();
            $this->page['suppliers'] = $this->page['offers']->map(fn ($o) => $o->supplier)->unique('id');
            $this->page['rates'] = TaxRate::where('company_id', $actor->company_id)->where('is_active', true)->orderBy('name')->get();
        }
        if ($kind === 'receipts' && Workspace::hasAccess('goodsreceipt.write')) {
            $this->page['availableOrders'] = Op::scoped(PurchaseOrder::class, $actor)->whereIn('state', ['approved', 'partially_received'])->with('revision')->orderByDesc('id')->get();
        }
        if ($po) {
            $received = (new ReceivingService)->received($po);
            $this->page['progressLines'] = $po->revision->items->map(function ($item) use ($received) {
                $done = (string) BigDecimal::of((string) ($received[$item->id] ?? '0'))->toScale(4);
                return ['item' => $item, 'received' => $done, 'remaining' => (string) BigDecimal::of($item->quantity)->minus($done)->toScale(4)];
            });
            $this->page['receipts'] = Op::scoped(GoodsReceipt::class, $actor)->where('purchase_order_id', $po->id)->orderByDesc('id')->get();
            $this->page['locations'] = Op::scoped(StockLocation::class, $actor)->where('branch_id', $po->branch_id)->where('is_active', true)->orderBy('name')->get();
        }
    }

    public function defineProperties()
    {
        return ['kind' => ['title' => 'Document kind', 'default' => 'orders', 'type' => 'string']];
    }

    public function onPreview()
    {
        $this->page['preview'] = (new PurchasingService)->previewDraft(post());
        return ['#purchase-preview' => $this->renderPartial('purchase-preview')];
    }

    public function onSaveOrder()
    {
        return $this->saved('purchasing', (new PurchasingService)->saveDraft(post()));
    }

    public function onReprice()
    {
        return $this->saved('purchasing', (new PurchasingService)->repriceDraft(post()));
    }

    public function onApprove()
    {
        return $this->saved('purchasing', (new PurchasingService)->approve(post()));
    }

    public function onReopen()
    {
        return $this->saved('purchasing', (new PurchasingService)->reopen(post()));
    }

    public function onVoid()
    {
        return $this->saved('purchasing', (new PurchasingService)->void(post()));
    }

    public function onCloseShort()
    {
        return $this->saved('purchasing', (new PurchasingService)->closeShort(post()));
    }

    public function onSaveReceipt()
    {
        return $this->saved('receiving', (new ReceivingService)->saveDraft(post()));
    }

    public function onConfirm()
    {
        return $this->saved('receiving', (new ReceivingService)->confirm(post()));
    }

    public function onCorrect()
    {
        $input = post();
        if (($input['reverse_only'] ?? '') === '1') {
            $input['lines'] = [];
        }
        return $this->saved('receiving', (new ReceivingService)->correct($input));
    }

    private function saved(string $path, $record)
    {
        Flash::success('Changes saved.');
        return (string) get('id') === (string) $record->id ? ajax()->reload() : Redirect::to('/' . $path . '?id=' . $record->id);
    }
}
