<?php namespace Vmod\Erp\Components;

use Flash;
use Redirect;
use Validator;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Erp\Services\CatalogueService;
use Vmod\Erp\Models\ProductPackage;

class Catalogue extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Catalogue', 'description' => 'Scoped ERP master data'];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        try {
            $member = Workspace::membership();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return Redirect::to('/workspaces');
        }
        Workspace::authorize('catalogue.read');
        $kinds = ['parties' => 'Customers & suppliers', 'products' => 'Products', 'packages' => 'Packages', 'offers' => 'Supplier offers', 'tax_rates' => 'Tax rates', 'locations' => 'Stock locations'];
        $kind = $this->param('kind') ?: 'products';
        abort_unless(isset($kinds[$kind]), 404);
        $validator = Validator::make(get(), ['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive,all', 'edit' => 'nullable|integer|min:1', 'new' => 'nullable|in:1', 'page' => 'nullable|integer|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $filters = $validator->validated();
        $service = new CatalogueService;
        $this->page['currentUser'] = Auth::getUser();
        $this->page['workspaceMember'] = $member;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($member->role->permissions ?? [], true);
        $this->page['canWriteCatalogue'] = Workspace::hasAccess('catalogue.write');
        $this->page['kinds'] = $kinds;
        $this->page['kind'] = $kind;
        $this->page['catalogueTitle'] = $kinds[$kind];
        $this->page['filters'] = $filters;
        $this->page['records'] = $service->listing($kind, $filters['q'] ?? '', $filters['status'] ?? 'active')->appends(array_intersect_key($filters, array_flip(['q', 'status'])));
        $this->page['record'] = empty($filters['edit']) ? null : $service->find($kind, (int) $filters['edit']);
        $this->page['showEditor'] = Workspace::hasAccess('catalogue.write') && (!empty($filters['edit']) || !empty($filters['new']));
        $this->page['saveHandler'] = ['parties' => 'onSaveParty', 'products' => 'onSaveProduct', 'packages' => 'onSavePackage', 'offers' => 'onSaveOffer', 'tax_rates' => 'onSaveTaxRate', 'locations' => 'onSaveLocation'][$kind];
        if ($this->page['showEditor']) {
            $this->page['baseUomLocked'] = $kind === 'products' && $this->page['record'] && (ProductPackage::where('product_id', $this->page['record']->id)->exists() || \Vmod\Erp\Models\StockMovement::where('product_id', $this->page['record']->id)->exists());
            $this->page['products'] = $kind === 'packages' ? $service->choices('products') : [];
            $this->page['packages'] = $kind === 'offers' ? $service->choices('packages') : [];
            $this->page['suppliers'] = $kind === 'offers' ? $service->choices('parties')->where('is_supplier', true) : [];
            $this->page['branches'] = $member->branches()->when(Workspace::branchId(), fn ($query) => $query->where('vmod_core_branches.id', Workspace::branchId()))->get();
        }
    }

    public function onSaveParty()
    {
        return $this->saved('parties', (new CatalogueService)->saveParty(post()));
    }

    public function onSaveProduct()
    {
        return $this->saved('products', (new CatalogueService)->saveProduct(post()));
    }

    public function onSavePackage()
    {
        return $this->saved('packages', (new CatalogueService)->savePackage(post()));
    }

    public function onSaveOffer()
    {
        return $this->saved('offers', (new CatalogueService)->saveOffer(post()));
    }

    public function onSaveTaxRate()
    {
        return $this->saved('tax_rates', (new CatalogueService)->saveTaxRate(post()));
    }

    public function onSaveLocation()
    {
        return $this->saved('locations', (new CatalogueService)->saveLocation(post()));
    }

    public function onSetActive()
    {
        $kind = post('kind');
        abort_unless(is_string($kind), 404);
        return $this->saved($kind, (new CatalogueService)->setActive($kind, post()));
    }

    private function saved(string $kind, $record)
    {
        Flash::success('Changes saved.');
        if ((string) get('edit') === (string) $record->id) {
            return ajax()->reload();
        }
        return Redirect::to('/catalogue/' . $kind . '?edit=' . $record->id . '&status=all#catalogue-editor');
    }
}
