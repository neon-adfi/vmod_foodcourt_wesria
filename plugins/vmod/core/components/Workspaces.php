<?php namespace Vmod\Core\Components;

use Flash;
use Redirect;
use Validator;
use Cms\Classes\ComponentBase;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Facades\Auth;
use Vmod\Core\Models\Role;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\Invitation;
use Vmod\Core\Services\AdministrationService;
use Vmod\Core\Services\InvitationService;

class Workspaces extends ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Workspaces', 'description' => 'Company-scoped workspace administration'];
    }

    public function defineProperties()
    {
        return ['view' => ['default' => 'select', 'type' => 'string']];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $this->page['currentUser'] = Auth::getUser();
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['memberships'] = Workspace::memberships();
        if ($this->property('view') === 'select') {
            return;
        }
        try {
            $member = Workspace::membership();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return Redirect::to('/workspaces');
        }
        $view = $this->property('view');
        $permission = ['company' => 'workspace.manage', 'members' => 'users.manage', 'roles' => 'roles.manage'][$view] ?? null;
        if ($permission) {
            Workspace::authorize($permission);
        }
        $this->page['workspaceMember'] = $member;
        $this->page['workspaceBranch'] = Workspace::branchId() ? Branch::find(Workspace::branchId()) : null;
        $this->page['fullAdministrator'] = $member->isFullAdministrator();
        $this->page['access'] = array_fill_keys($member->role->permissions ?? [], true);
        $this->page['branches'] = $member->branches()->orderBy('name')->get();
        $admin = new AdministrationService;
        $roles = Role::where('company_id', $member->company_id)->orderBy('name')->get()
            ->filter(fn ($role) => $admin->canManage($member, $role, []));
        $this->page['roles'] = $roles;
        $this->page['permissionKeys'] = array_values(array_intersect(Permissions::all(), $member->role->permissions ?? []));
        if ($view === 'members') {
            $this->page['companyMembers'] = Membership::with(['user', 'role', 'branches'])->where('company_id', $member->company_id)->get()
                ->filter(fn ($target) => $target->user && $admin->canManage($member, $target->role, $target->branchIds()));
            $this->page['invitations'] = Invitation::pending()->with('role')->where('company_id', $member->company_id)
                ->orderByDesc('id')->get()->filter(fn ($invite) => $admin->canManage($member, $invite->role, $invite->branch_ids));
        }
    }

    public function onSwitch()
    {
        Workspace::assertContext();
        $data = Validator::make(post(), ['company_id' => 'required|integer|min:1', 'branch_id' => 'nullable|integer|min:1'])->validate();
        Workspace::switchTo((int) $data['company_id'], empty($data['branch_id']) ? null : (int) $data['branch_id']);
        return Redirect::to('/');
    }

    public function onCompany()
    {
        (new AdministrationService)->saveCompany(post());
        return $this->saved('/settings/company');
    }

    public function onBranch()
    {
        (new AdministrationService)->saveBranch(post());
        return $this->saved('/settings/company');
    }

    public function onRole()
    {
        $input = post();
        $input['permissions'] = $input['permissions'] ?? [];
        (new AdministrationService)->saveRole($input);
        return $this->saved('/settings/roles');
    }

    public function onMember()
    {
        $input = post();
        $input['branch_ids'] = $input['branch_ids'] ?? [];
        (new AdministrationService)->saveMember($input);
        return $this->saved('/settings/members');
    }

    public function onInvite()
    {
        $input = post();
        $input['branch_ids'] = $input['branch_ids'] ?? [];
        (new InvitationService)->issue($input);
        return $this->saved('/settings/members', 'Invitation sent.');
    }

    public function onRevoke()
    {
        $data = Validator::make(post(), ['id' => 'required|integer|min:1'])->validate();
        (new InvitationService)->revoke((int) $data['id']);
        return $this->saved('/settings/members', 'Invitation revoked.');
    }

    private function saved(string $url, string $message = 'Changes saved.')
    {
        Flash::success($message);
        return Redirect::to($url);
    }
}
