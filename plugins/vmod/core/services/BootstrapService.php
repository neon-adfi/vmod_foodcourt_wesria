<?php namespace Vmod\Core\Services;

use DB;
use Validator;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Role;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\User;
use Vmod\Core\Models\Audit;

class BootstrapService
{
    public function assertOwnedEnvironment(): void
    {
        $config = config('database.connections.' . config('database.default'));
        $testing = app()->environment('testing')
            && getenv('VMOD_TEST_DATABASE_OWNER') === 'vmod_wesria_phase1'
            && ($config['host'] ?? '') === 'mysql-test'
            && ($config['database'] ?? '') === 'vmod_wesria_test'
            && ($config['username'] ?? '') === 'vmod_wesria_test';
        $local = app()->environment('local')
            && ($config['host'] ?? '') === 'mysql-dev'
            && ($config['database'] ?? '') === 'vmod_wesria_dev'
            && ($config['username'] ?? '') === 'vmod_wesria_dev';
        if (!app()->runningInConsole() || (!$local && !$testing)
            || config('database.default') !== 'mysql' || !empty($config['url']) || !empty($config['unix_socket'])
            || DB::connection()->getDatabaseName() !== $config['database']) {
            throw new \RuntimeException('Bootstrap requires the owned local or disposable test database and CLI.');
        }
    }

    public function create(array $input): array
    {
        $this->assertOwnedEnvironment();
        $data = Validator::make($input, [
            'company_code' => 'required|string|max:80|regex:/^[a-z0-9][a-z0-9-]*$/|unique:vmod_core_companies,code',
            'company_name' => 'required|string|max:255', 'branch_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255', 'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255', 'password' => 'required|string|confirmed',
        ])->validate();
        $data['email'] = strtolower(trim($data['email']));
        return IdentityService::withAccountCreationLock(function () use ($data, $input) {
            return DB::transaction(function () use ($data, $input) {
                if (User::withTrashed()->where('email', $data['email'])->exists()) {
                    throw new ValidationException(['email' => 'This email already exists. Use an invitation.']);
                }
                $company = Company::create(['code' => $data['company_code'], 'name' => $data['company_name']]);
                $branch = Branch::create(['company_id' => $company->id, 'name' => $data['branch_name']]);
                $role = Role::create(['company_id' => $company->id, 'name' => 'Administrator', 'permissions' => Permissions::all()]);
                $owner = new User;
                $owner->first_name = $data['first_name'];
                $owner->last_name = $data['last_name'] ?? null;
                $owner->email = $data['email'];
                $owner->password = $data['password'];
                $owner->password_confirmation = $input['password_confirmation'];
                $owner->activated_at = now();
                $owner->save();
                $membership = Membership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role_id' => $role->id]);
                $membership->branches()->sync([$branch->id]);
                Audit::record($company->id, 'company.bootstrap', ['owner_user_id' => $owner->id, 'branch_id' => $branch->id], null, 'cli');
                return compact('company', 'branch', 'owner', 'membership');
            });
        });
    }
}
