<?php namespace Vmod\Erp\Components;

use Flash;
use Redirect;
use Illuminate\Support\Str;
use Brick\Math\BigDecimal;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\SalesOrder;
use Vmod\Erp\Models\DeliveryOrder;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Models\ProductPackage;
use Vmod\Erp\Models\StockReservation;
use Vmod\Erp\Models\TaxRate;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Services\SalesService;
use Vmod\Erp\Services\DispatchService;

class Sales extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Sales', 'description' => 'Sales orders and delivery'];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $kind = $this->property('kind', 'orders');
        $permission = $kind === 'deliveries' ? 'deliveryorder.read' : 'salesorder.read';
        $actor = Workspace::authorize($permission);
        $queryRules = ['q' => 'nullable|string|max:100', 'state' => 'nullable|in:draft,approved,partially_delivered,completed,dispatched,voided,closed_short',
            'id' => 'nullable|integer|min:1', 'new' => 'nullable|in:1', 'so' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1'];
        $validator = \Validator::make(get(), $queryRules);
        if ($validator->fails()) {
            $this->controller->setStatusCode(400);
            $this->page['queryErrors'] = $validator->errors()->toArray();
            $this->page['queryValues'] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_intersect_key(get(), $queryRules));
            $this->page['queryPath'] = \Request::url();
            return;
        }
        $filters = $validator->validated();
        $class = $kind === 'deliveries' ? DeliveryOrder::class : SalesOrder::class;
        $query = Op::scoped($class, $actor);
        if (!empty($filters['q'])) {
            $query->where('number', 'like', '%' . $filters['q'] . '%');
        }
        if (!empty($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        $record = empty($filters['id']) ? null : ($kind === 'deliveries'
            ? (new DispatchService)->find((int) $filters['id']) : (new SalesService)->find((int) $filters['id']));
        $so = $kind === 'orders' ? $record : ($record ? $record->sales_order : null);
        if ($kind === 'deliveries' && !$record && !empty($filters['so'])) {
            $so = Op::scoped(SalesOrder::class, $actor)->with('revision.items')->findOrFail($filters['so']);
        }
        $this->page['kind'] = $kind;
        $this->page['record'] = $record;
        $this->page['so'] = $so;
        $this->page['filters'] = $filters;
        $this->page['records'] = $query->orderByDesc('id')->paginate(15)->appends(array_intersect_key($filters, array_flip(['q', 'state'])));
        $this->page['workspaceMember'] = $actor;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($actor->role->permissions ?? [], true);
        $this->page['branches'] = $actor->branches()->when(Workspace::branchId(), fn ($q) => $q->where('vmod_core_branches.id', Workspace::branchId()))->get();
        $this->page['operationKey'] = (string) Str::uuid();
        $this->page['today'] = date('Y-m-d');
        if ($kind === 'orders' && Workspace::hasAccess('salesorder.write')) {
            // Workflow selectors use sales permission; they expose no inventory totals.
            $this->page['packages'] = ProductPackage::where('company_id', $actor->company_id)->where('is_active', true)->whereNotNull('sales_price')
                ->whereHas('product', fn ($q) => $q->where('is_active', true))->with('product')->orderBy('id')->get();
            $this->page['customers'] = Party::where('company_id', $actor->company_id)->where('is_active', true)->where('is_customer', true)->orderBy('name')->get();
            $this->page['rates'] = TaxRate::where('company_id', $actor->company_id)->where('is_active', true)->orderBy('name')->get();
            $this->page['locations'] = Op::scoped(StockLocation::class, $actor)->when($so, fn ($q) => $q->where('branch_id', $so->branch_id))
                ->where('is_active', true)->orderBy('name')->get();
        }
        if ($kind === 'deliveries' && Workspace::hasAccess('deliveryorder.write')) {
            $this->page['availableOrders'] = Op::scoped(SalesOrder::class, $actor)->whereIn('state', ['approved', 'partially_delivered'])->with('revision')->orderByDesc('id')->get();
        }
        if ($so) {
            $delivered = (new DispatchService)->delivered($so);
            $reservations = StockReservation::where('company_id', $actor->company_id)->where('sales_order_id', $so->id)->get()->keyBy('sales_order_item_id');
            $this->page['progressLines'] = $so->revision->items->map(function ($item) use ($delivered, $reservations) {
                $done = (string) BigDecimal::of((string) ($delivered[$item->id] ?? '0'))->toScale(4);
                $reservation = $reservations->get($item->id);
                return ['item' => $item, 'delivered' => $done, 'remaining' => (string) BigDecimal::of($item->quantity)->minus($done)->toScale(4),
                    'reserved' => $reservation ? (string) BigDecimal::of($reservation->approved)->minus($reservation->dispatched)->minus($reservation->released)->toScale(4) : '0.0000',
                    'released' => $reservation ? $reservation->released : '0.0000'];
            });
            if ($kind === 'deliveries' || Workspace::hasAccess('deliveryorder.read')) {
                $this->page['deliveries'] = Op::scoped(DeliveryOrder::class, $actor)->where('sales_order_id', $so->id)->orderByDesc('id')->get();
            }
        }
    }

    public function defineProperties()
    {
        return ['kind' => ['title' => 'Document kind', 'default' => 'orders', 'type' => 'string']];
    }

    public function onPreview()
    {
        $this->page['preview'] = (new SalesService)->previewDraft(post());
        return ['#sales-preview' => $this->renderPartial('purchase-preview')];
    }

    public function onSaveOrder()
    {
        return $this->saved('sales', (new SalesService)->saveDraft(post()));
    }

    public function onReprice()
    {
        return $this->saved('sales', (new SalesService)->repriceDraft(post()));
    }

    public function onApprove()
    {
        return $this->saved('sales', (new SalesService)->approve(post()));
    }

    public function onReopen()
    {
        return $this->saved('sales', (new SalesService)->reopen(post()));
    }

    public function onVoid()
    {
        return $this->saved('sales', (new SalesService)->void(post()));
    }

    public function onCloseShort()
    {
        return $this->saved('sales', (new SalesService)->closeShort(post()));
    }

    public function onSaveDelivery()
    {
        return $this->saved('delivery', (new DispatchService)->saveDraft(post()));
    }

    public function onDispatch()
    {
        return $this->saved('delivery', (new DispatchService)->dispatch(post()));
    }

    public function onCorrect()
    {
        $input = post();
        if (($input['reverse_only'] ?? '') === '1') {
            $input['lines'] = [];
        }
        return $this->saved('delivery', (new DispatchService)->correct($input));
    }

    private function saved(string $path, $record)
    {
        Flash::success('Changes saved.');
        return (string) get('id') === (string) $record->id ? ajax()->reload() : Redirect::to('/' . $path . '?id=' . $record->id);
    }
}
