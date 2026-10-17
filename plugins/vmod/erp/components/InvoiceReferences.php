<?php namespace Vmod\Erp\Components;

use Flash;
use Redirect;
use Illuminate\Support\Str;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\Audit;
use Vmod\Erp\Classes\DocumentOperation as Op;
use Vmod\Erp\Models\InvoiceReference;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Services\InvoiceReferenceService;

class InvoiceReferences extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Invoice references', 'description' => 'External invoice metadata and document links'];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $actor = Workspace::authorize('invoice_reference.read');
        $queryRules = ['id' => 'nullable|integer|min:1', 'new' => 'nullable|in:1', 'q' => 'nullable|string|max:100',
            'state' => 'nullable|in:active,voided,all', 'direction' => 'nullable|in:purchase,sales', 'page' => 'nullable|integer|min:1'];
        $validator = \Validator::make(get(), $queryRules);
        if ($validator->fails()) {
            $this->controller->setStatusCode(400);
            $this->page['queryErrors'] = $validator->errors()->toArray();
            $this->page['queryValues'] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_intersect_key(get(), $queryRules));
            $this->page['queryPath'] = \Request::url();
            return;
        }
        $filters = $validator->validated();
        $service = new InvoiceReferenceService;
        $record = empty($filters['id']) ? null : $service->find((int) $filters['id']);
        $query = Op::scoped(InvoiceReference::class, $actor)->with('party');
        if (($filters['state'] ?? 'active') !== 'all') {
            $query->where('state', $filters['state'] ?? 'active');
        }
        if (!empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (!empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('number', 'like', '%' . $filters['q'] . '%')
                ->orWhereHas('party', fn ($p) => $p->where('name', 'like', '%' . $filters['q'] . '%')->orWhere('code', 'like', '%' . $filters['q'] . '%')));
        }
        $this->page['workspaceMember'] = $actor;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($actor->role->permissions ?? [], true);
        $this->page['record'] = $record;
        $this->page['filters'] = $filters;
        $this->page['records'] = $query->orderByDesc('id')->paginate(15)->appends(array_intersect_key($filters, array_flip(['q', 'state', 'direction'])));
        $this->page['links'] = $record ? $service->linkViews($record) : [];
        $this->page['history'] = $record ? Audit::where('company_id', $actor->company_id)->where('action', 'invoice_reference.change')
            ->where('details->id', $record->id)->where('details->branch_id', $record->branch_id)->orderByDesc('id')->get() : [];
        $this->page['operationKey'] = (string) Str::uuid();
        $this->page['today'] = date('Y-m-d');
        if (Workspace::hasAccess('invoice_reference.write')) {
            $this->page['branches'] = $actor->branches()->when(Workspace::branchId(), fn ($q) => $q->where('vmod_core_branches.id', Workspace::branchId()))->get();
            // Retired counterparties may still have eligible historical orders; expose only selector identity.
            $this->page['parties'] = Party::where('company_id', $actor->company_id)->orderBy('name')->get(['id', 'code', 'name']);
        }
    }

    public function onChoices()
    {
        $this->page['choices'] = (new InvoiceReferenceService)->choices(post());
        $this->page['choiceScope'] = array_intersect_key(post(), array_flip(['branch_id', 'party_id', 'direction']));
        return ['#invoice-choices' => $this->renderPartial('invoice-choices')];
    }

    public function onSave()
    {
        return $this->saved((new InvoiceReferenceService)->save(post()));
    }

    public function onVoid()
    {
        return $this->saved((new InvoiceReferenceService)->void(post()));
    }

    public function onRestore()
    {
        return $this->saved((new InvoiceReferenceService)->restore(post()));
    }

    private function saved($record)
    {
        Flash::success('Invoice reference saved.');
        return (string) get('id') === (string) $record->id ? ajax()->reload() : Redirect::to('/invoice-references?id=' . $record->id);
    }
}
