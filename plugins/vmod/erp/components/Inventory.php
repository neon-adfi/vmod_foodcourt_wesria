<?php namespace Vmod\Erp\Components;

use Flash;
use Redirect;
use Illuminate\Support\Str;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\StockLocation;
use Vmod\Erp\Models\StockBalance;
use Vmod\Erp\Models\StockMovement;
use Vmod\Erp\Services\StockService;

class Inventory extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Inventory', 'description' => 'Stock balances and movements'];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $actor = Workspace::authorize('inventory.read');
        $filters = Op::validate(get(), ['q' => 'nullable|string|max:100', 'location_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'movement_page' => 'nullable|integer|min:1']);
        $query = Op::scoped(StockBalance::class, $actor)->with(['product', 'location']);
        $movements = Op::scoped(StockMovement::class, $actor)->with(['product', 'location']);
        foreach ([$query, $movements] as $q) {
            if (!empty($filters['q'])) {
                $q->whereHas('product', fn ($p) => $p->where(fn ($s) => $s->where('name', 'like', '%' . $filters['q'] . '%')->orWhere('sku', 'like', '%' . $filters['q'] . '%')));
            }
            if (!empty($filters['location_id'])) {
                Op::scoped(StockLocation::class, $actor)->findOrFail($filters['location_id']);
                $q->where('location_id', $filters['location_id']);
            }
        }
        $this->page['workspaceMember'] = $actor;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($actor->role->permissions ?? [], true);
        $this->page['filters'] = $filters;
        $this->page['balances'] = $query->orderBy('id')->paginate(15)->appends($filters);
        $this->page['movements'] = $movements->orderByDesc('id')->paginate(15, ['*'], 'movement_page')->appends($filters);
        $this->page['locations'] = Op::scoped(StockLocation::class, $actor)->orderBy('name')->get();
        if (Workspace::hasAccess('inventory.adjust')) {
            $this->page['products'] = Product::where('company_id', $actor->company_id)->where('is_active', true)->orderBy('name')->get();
            $this->page['operationKey'] = (string) Str::uuid();
        }
    }

    public function onOpen()
    {
        (new StockService)->open(post());
        Flash::success('Opening stock posted.');
        return ajax()->reload();
    }

    public function onAdjust()
    {
        (new StockService)->adjust(post());
        Flash::success('Stock adjustment posted.');
        return ajax()->reload();
    }
}
