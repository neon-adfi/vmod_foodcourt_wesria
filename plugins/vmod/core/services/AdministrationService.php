<?php namespace Vmod\Core\Services;

use DB;
use Validator;
use Illuminate\Validation\Rule;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Role;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\Invitation;
use Vmod\Core\Models\Audit;

class AdministrationService
{
    public function mutate(string $permission, callable $callback)
    {
        Workspace::assertContext();
        $companyId = Workspace::authorize($permission)->company_id;
        return DB::transaction(function () use ($companyId, $permission, $callback) {
            // Administration is deliberately serialized per company; split locks only if measured contention warrants it.
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            Workspace::assertContext();
            $actor = Workspace::authorize($permission);
            $result = $callback($actor);
            $fullAdmin = Membership::with(['role', 'user'])->where('company_id', $companyId)->where('is_active', true)
                ->get()->contains(fn ($member) => $member->isFullAdministrator());
            abort_unless($fullAdmin, 409, 'Keep at least one active administrator with every permission and branch.');
            return $result;
        });
    }

    public function assertGrantScope(Membership $actor, Role $role, array $branchIds): void
    {
        abort_unless((int) $role->company_id === (int) $actor->company_id, 404);
        $companyBranches = Branch::where('company_id', $actor->company_id)->pluck('id')->map('intval')->all();
        abort_if(array_diff($branchIds, $companyBranches), 404);
        abort_if(array_diff($role->permissions ?? [], $actor->role->permissions ?? [])
            || array_diff($branchIds, $actor->branchIds()), 403, 'You can only manage access within your own grants.');
    }

    public function canManage(Membership $actor, Role $role, array $branchIds): bool
    {
        try {
            $this->assertGrantScope($actor, $role, $branchIds);
            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return false;
        }
    }

    public function saveCompany(array $input): Company
    {
        return $this->mutate('workspace.manage', function ($actor) use ($input) {
            abort_unless($actor->isFullAdministrator(), 403);
            $data = Validator::make($input, [
                'name' => 'required|string|max:255',
                'currency' => 'sometimes|required|string|regex:/^[A-Z]{3}$/D',
                'expected_currency' => 'required_with:currency|string|size:3',
            ])->validate();
            $company = Company::findOrFail($actor->company_id);
            if (isset($data['currency'])) {
                abort_unless($data['expected_currency'] === $company->currency, 409, 'Currency changed. Reload before saving.');
                abort_if($company->currency_locked_at && $data['currency'] !== $company->currency, 409, 'Currency is locked because a catalogue price has been saved.');
            }
            unset($data['expected_currency']);
            $before = $company->only(['name', 'currency']);
            $company->update($data);
            Audit::record($company->id, 'company.updated', ['before' => $before, 'after' => $company->only(['name', 'currency'])], $actor->user_id);
            return $company;
        });
    }

    public function saveBranch(array $input): Branch
    {
        $data = Validator::make($input, ['id' => 'nullable|integer|min:1', 'name' => 'required|string|max:255'])->validate();
        return $this->mutate('workspace.manage', function ($actor) use ($data) {
            $id = $data['id'] ?? null;
            $fullAdmins = [];
            if ($id) {
                $branch = Branch::where('company_id', $actor->company_id)->findOrFail($id);
                abort_unless(in_array((int) $branch->id, $actor->branchIds(), true), 404);
            } else {
                abort_unless($actor->isFullAdministrator(), 403);
                $fullAdmins = Membership::where('company_id', $actor->company_id)->where('is_active', true)->get()
                    ->filter(fn ($member) => $member->isFullAdministrator());
                $branch = new Branch(['company_id' => $actor->company_id]);
            }
            Validator::make($data, [
                'name' => ['required', Rule::unique('vmod_core_branches')->where('company_id', $actor->company_id)->ignore($id)],
            ])->validate();
            $before = $branch->name;
            $branch->name = $data['name'];
            $branch->save();
            foreach ($fullAdmins as $member) {
                $member->branches()->syncWithoutDetaching([$branch->id]);
            }
            Audit::record($actor->company_id, $id ? 'branch.updated' : 'branch.created', ['branch_id' => $branch->id, 'before' => $before, 'name' => $branch->name], $actor->user_id);
            return $branch;
        });
    }

    public function saveRole(array $input): Role
    {
        $data = Validator::make($input, [
            'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:255',
            'permissions' => 'present|array', 'permissions.*' => ['string', 'distinct', Rule::in(Permissions::all())],
        ])->validate();
        return $this->mutate('roles.manage', function ($actor) use ($data) {
            $id = $data['id'] ?? null;
            $role = $id ? Role::where('company_id', $actor->company_id)->findOrFail($id) : new Role(['company_id' => $actor->company_id]);
            $before = $role->permissions ?? [];
            if ($id) {
                $this->assertGrantScope($actor, $role, []);
                foreach (Membership::where('company_id', $actor->company_id)->where('role_id', $id)->get() as $member) {
                    $this->assertGrantScope($actor, $role, $member->branchIds());
                }
                foreach (Invitation::pending()->where('company_id', $actor->company_id)->where('role_id', $id)->get() as $invitation) {
                    $this->assertGrantScope($actor, $role, $invitation->branch_ids);
                }
            }
            abort_if(array_diff($data['permissions'], $actor->role->permissions ?? []), 403, 'You can only grant permissions you hold. Reload and choose from the available permissions.');
            Validator::make($data, [
                'name' => ['required', Rule::unique('vmod_core_roles')->where('company_id', $actor->company_id)->ignore($id)],
            ])->validate();
            $role->name = $data['name'];
            $role->permissions = array_values($data['permissions']);
            $role->save();
            Audit::record($actor->company_id, $id ? 'role.updated' : 'role.created', ['role_id' => $role->id, 'before' => $before, 'permissions' => $role->permissions], $actor->user_id);
            return $role;
        });
    }

    public function saveMember(array $input): Membership
    {
        $data = Validator::make($input, [
            'id' => 'required|integer|min:1', 'role_id' => 'required|integer|min:1',
            'branch_ids' => 'present|array', 'branch_ids.*' => 'integer|min:1|distinct',
            'is_active' => 'required|boolean',
        ])->validate();
        return $this->mutate('users.manage', function ($actor) use ($data) {
            $member = Membership::where('company_id', $actor->company_id)->findOrFail($data['id']);
            $this->assertGrantScope($actor, $member->role, $member->branchIds());
            $role = Role::where('company_id', $actor->company_id)->findOrFail($data['role_id']);
            $branchIds = array_map('intval', $data['branch_ids']);
            $this->assertGrantScope($actor, $role, $branchIds);
            $before = ['role_id' => $member->role_id, 'branch_ids' => $member->branchIds(), 'is_active' => $member->is_active];
            $member->update(['role_id' => $role->id, 'is_active' => $data['is_active']]);
            $member->branches()->sync($branchIds);
            Audit::record($actor->company_id, 'membership.updated', [
                'membership_id' => $member->id, 'before' => $before,
                'role_id' => $role->id, 'branch_ids' => $branchIds, 'is_active' => (bool) $data['is_active'],
            ], $actor->user_id);
            return $member;
        });
    }
}
