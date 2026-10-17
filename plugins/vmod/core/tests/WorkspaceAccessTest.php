<?php namespace Vmod\Core\Tests;

use Vmod\Tests\ApplicationTestCase;
use Vmod\Core\Models\User;
use Vmod\Core\Services\BootstrapService;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Services\AdministrationService;
use Vmod\Core\Services\InvitationService;
use Vmod\Core\Models\Role;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\Invitation;
use Vmod\Core\Models\Audit;

class WorkspaceAccessTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        $this->app['db']->beginTransaction();
    }

    public function tearDown(): void
    {
        if ($this->app['auth']->user()) {
            $this->app['auth']->logout();
        }
        while ($this->app['db']->transactionLevel()) {
            $this->app['db']->rollBack();
        }
        parent::tearDown();
    }

    private function company(): array
    {
        $suffix = bin2hex(random_bytes(6));
        return (new BootstrapService)->create([
            'company_code' => 'test-' . $suffix, 'company_name' => 'Company ' . $suffix,
            'branch_name' => 'Main', 'first_name' => 'Owner',
            'email' => $suffix . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
        ]);
    }

    private function acting(array $company, ?User $user = null): void
    {
        if ($this->app['auth']->user()) {
            $this->app['auth']->logout();
        }
        $this->app['auth']->login(($user ?: $company['owner'])->fresh());
        Workspace::clear();
        Workspace::switchTo($company['company']->id, $company['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
    }

    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Request should have been rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(404, $status);
        }
    }

    public function testScopedAdministrationContextAndLastAdministrator(): void
    {
        $this->assertTrue(class_exists(AdministrationService::class), 'Core administration service must exist.');
        $one = $this->company();
        $two = $this->company();
        $this->acting($one);
        $service = new AdministrationService;
        $role = $service->saveRole(['name' => 'Reader', 'permissions' => ['catalogue.read']]);
        $this->assertSame($one['company']->id, $role->company_id);
        $this->denied(fn () => $service->saveRole(['id' => $two['membership']->role_id, 'name' => 'Foreign', 'permissions' => []]), 404);
        $this->denied(fn () => $service->saveMember([
            'id' => $one['membership']->id, 'role_id' => $role->id,
            'branch_ids' => [$one['branch']->id], 'is_active' => true,
        ]), 409);
        $this->assertTrue($one['membership']->fresh()->isFullAdministrator());
        \Request::instance()->headers->remove('X-Workspace-Context');
        $this->denied(fn () => $service->saveRole(['name' => 'Missing token', 'permissions' => []]), 409);
        $this->assertFalse(Role::where('name', 'Missing token')->exists());
        $old = Workspace::token();
        Workspace::switchTo($one['company']->id);
        \Request::instance()->headers->set('X-Workspace-Context', $old);
        $this->denied(fn () => $service->saveCompany(['name' => 'Stale']), 409);
        $this->assertNotSame('Stale', $one['company']->fresh()->name);
    }

    public function testBranchesAndSharedRolesCannotEscalateDelegatedAuthority(): void
    {
        $this->assertTrue(class_exists(AdministrationService::class), 'Core administration service must exist.');
        $one = $this->company();
        $other = $this->company();
        $this->acting($one);
        $service = new AdministrationService;
        $branch = $service->saveBranch(['name' => 'Second']);
        $this->assertContains($branch->id, $one['membership']->branchIds());
        $role = $service->saveRole(['name' => 'Manager', 'permissions' => ['users.manage', 'roles.manage', 'workspace.manage', 'catalogue.read']]);
        $member = Membership::create(['company_id' => $one['company']->id, 'user_id' => $other['owner']->id, 'role_id' => $role->id]);
        $member->branches()->sync([$one['branch']->id]);
        $this->acting($one, $other['owner']);
        $this->denied(fn () => $service->saveRole(['name' => 'Escalated', 'permissions' => ['inventory.adjust']]), 403);
        $this->denied(fn () => $service->saveBranch(['name' => 'Unauthorized branch']), 403);
        $this->denied(fn () => $service->saveMember([
            'id' => $member->id, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id, $branch->id], 'is_active' => true,
        ]), 403);
        $this->acting($one);
        $third = $this->company();
        $wide = Membership::create(['company_id' => $one['company']->id, 'user_id' => $third['owner']->id, 'role_id' => $role->id]);
        $wide->branches()->sync([$branch->id]);
        $this->acting($one, $other['owner']);
        $this->denied(fn () => $service->saveRole(['id' => $role->id, 'name' => 'Manager', 'permissions' => ['catalogue.read']]), 403);
        $this->acting($one);
        $service->saveMember(['id' => $member->id, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id], 'is_active' => false]);
        $this->assertFalse($member->fresh()->is_active);
        $service->saveMember(['id' => $member->id, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id], 'is_active' => true]);
        $this->assertTrue($member->fresh()->is_active);
        $this->assertTrue($other['membership']->fresh()->is_active);
    }

    public function testInvitationAcceptIsAtomicAndSurvivesIssuerRevocation(): void
    {
        $this->assertTrue(class_exists(InvitationService::class), 'Core invitation service must exist.');
        $one = $this->company();
        $sender = $this->company();
        $this->acting($one);
        $admin = new AdministrationService;
        $role = $admin->saveRole(['name' => 'Inviter', 'permissions' => ['users.manage', 'catalogue.read']]);
        $member = Membership::create(['company_id' => $one['company']->id, 'user_id' => $sender['owner']->id, 'role_id' => $role->id]);
        $member->branches()->sync([$one['branch']->id]);
        $this->acting($one, $sender['owner']);
        $email = bin2hex(random_bytes(8)) . '@example.invalid';
        $invitations = new InvitationService;
        $issued = $invitations->issue(['email' => $email, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id]]);
        $this->assertFalse(User::where('email', $email)->exists());
        $this->assertNotSame($issued['token'], $issued['invitation']->token_hash);
        $this->acting($one);
        $admin->saveMember(['id' => $member->id, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id], 'is_active' => false]);
        $this->app['auth']->logout();
        $new = $invitations->accept($issued['token'], [
            'first_name' => 'New member', 'password' => 'Accepted42!password',
            'password_confirmation' => 'Accepted42!password', 'email' => 'ignored@example.invalid',
        ]);
        $this->assertSame($email, $new->email);
        $this->assertTrue($new->hasVerifiedEmail());
        $this->assertTrue(\Hash::check('Accepted42!password', $new->password));
        $this->assertSame(1, Membership::where('company_id', $one['company']->id)->where('user_id', $new->id)->count());
        $this->denied(fn () => $invitations->accept($issued['token'], []), 409);
        $this->assertSame(1, User::where('email', $email)->count());
    }

    public function testExistingAccountAcceptanceCannotChangePasswordOrSkipConsent(): void
    {
        $this->assertTrue(class_exists(InvitationService::class), 'Core invitation service must exist.');
        $one = $this->company();
        $two = $this->company();
        $this->acting($one);
        $role = (new AdministrationService)->saveRole(['name' => 'Reader', 'permissions' => ['catalogue.read']]);
        $service = new InvitationService;
        $issued = $service->issue(['email' => $two['owner']->email, 'role_id' => $role->id, 'branch_ids' => [$one['branch']->id]]);
        $this->denied(fn () => $service->accept($issued['token'], []), 403);
        $this->app['auth']->logout();
        $this->denied(fn () => $service->accept($issued['token'], []), 403);
        $this->app['auth']->login($two['owner']);
        $hash = $two['owner']->password;
        $accepted = $service->accept($issued['token'], ['password' => 'DoNotChange42!']);
        $this->assertSame($hash, $accepted->fresh()->password);
        $this->assertSame(2, Membership::where('user_id', $accepted->id)->count());
    }

    public function testRecoveryUsesNativeBrokerAndRevokesExistingSessions(): void
    {
        $this->assertTrue(method_exists(\Vmod\Core\Services\IdentityService::class, 'recover'), 'Core recovery must exist.');
        $one = $this->company();
        $this->acting($one);
        $user = $one['owner']->fresh();
        $persist = $user->persist_code;
        $otherSession = new \Illuminate\Session\Store('other-device', new \Illuminate\Session\ArraySessionHandler(120));
        $otherGuard = new \RainLab\User\Classes\SessionGuard('web', $this->app['auth']->createUserProvider('users'), $otherSession);
        $otherGuard->loginQuietly($user);
        $this->assertInstanceOf(User::class, $otherGuard->user());
        $service = new \Vmod\Core\Services\IdentityService;
        $service->recover(['email' => $user->email]);
        $service->recover(['email' => 'unknown-' . bin2hex(random_bytes(5)) . '@example.invalid']);
        $token = $this->app['auth.password']->broker('users')->createToken($user);
        $service->reset([
            'token' => $token, 'email' => $user->email,
            'password' => 'Changed42!password', 'password_confirmation' => 'Changed42!password',
        ]);
        $this->assertNotSame($persist, $user->fresh()->persist_code);
        $this->assertTrue(\Hash::check('Changed42!password', $user->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->assertNull($this->app['auth']->user());
        $otherGuard->forgetUser();
        $this->assertNull($otherGuard->user(), 'An independent native session must also be revoked.');
        try {
            $service->reset(['token' => $token, 'email' => $user->email, 'password' => 'ChangedAgain42!', 'password_confirmation' => 'ChangedAgain42!']);
            $this->fail('A consumed recovery token must fail.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    public function testBootstrapDuplicateAndUnsafeTargetPreserveData(): void
    {
        $one = $this->company();
        $count = \Vmod\Core\Models\Company::count();
        $input = [
            'company_code' => 'duplicate-' . bin2hex(random_bytes(5)), 'company_name' => 'Duplicate',
            'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $one['owner']->email,
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
        ];
        try {
            (new BootstrapService)->create($input);
            $this->fail('Existing global account cannot be reassigned.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertSame($count, \Vmod\Core\Models\Company::count());
        }
        $input['email'] = bin2hex(random_bytes(8)) . '@example.invalid';
        $input['password'] = $input['password_confirmation'] = 'x';
        try {
            (new BootstrapService)->create($input);
            $this->fail('Native password rejection must roll back the company, branch and role.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertSame($count, \Vmod\Core\Models\Company::count());
            $this->assertFalse(User::where('email', $input['email'])->exists());
        }
        $this->assertSame(1, $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('vmod:bootstrap', ['--no-interaction' => true]));
        config(['database.connections.mysql.host' => 'mysql-dev']);
        try {
            (new BootstrapService)->assertOwnedEnvironment();
            $this->fail('Mixed database identities must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('owned', $exception->getMessage());
        } finally {
            config(['database.connections.mysql.host' => 'mysql-test']);
        }
    }

    public function testCoreBootstrapHydratesNativeUserAndExplicitWorkspace(): void
    {
        $this->assertTrue(class_exists(BootstrapService::class), 'Phase 2 bootstrap service must exist.');
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        $this->app['db']->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(6));
            $created = (new BootstrapService)->create([
                'company_code' => 'test-' . $suffix, 'company_name' => 'Synthetic company',
                'branch_name' => 'Main', 'first_name' => 'Owner',
                'email' => $suffix . '@example.invalid',
                'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
            ]);
            $this->assertInstanceOf(User::class, $created['owner']);
            $this->assertSame(User::class, config('auth.providers.users.model'));
            $this->app['auth']->login($created['owner']);
            Workspace::switchTo($created['company']->id, $created['branch']->id);
            $this->assertTrue(Workspace::hasAccess('users.manage'));
            $this->assertFalse(Workspace::hasAccess('unknown.permission'));
            $this->assertEqualsCanonicalizing(Permissions::all(), $created['membership']->role->permissions);
            $this->assertNotEmpty(Workspace::token());
        } finally {
            $this->app['auth']->logout();
            $this->app['db']->rollBack();
        }
    }

    public function testInvitationExpiryResendRevocationAndRollback(): void
    {
        $one = $this->company();
        $this->acting($one);
        $service = new InvitationService;
        $email = bin2hex(random_bytes(8)) . '@example.invalid';
        $input = ['email' => $email, 'role_id' => $one['membership']->role_id, 'branch_ids' => [$one['branch']->id]];
        $first = $service->issue($input);
        $second = $service->issue($input);
        $this->denied(fn () => $service->inspect($first['token']), 409);
        $this->assertSame(1, Invitation::pending()->where('email', $email)->count());
        $service->revoke($second['invitation']->id);
        $this->denied(fn () => $service->inspect($second['token']), 409);
        $third = $service->issue($input);
        $third['invitation']->update(['expires_at' => now()->subSecond()]);
        $this->denied(fn () => $service->accept($third['token'], []), 409);
        $fourth = $service->issue($input);
        $page = new \ArrayObject(['controller' => null], \ArrayObject::ARRAY_AS_PROPS);
        (new \Vmod\Core\Components\Workspaces($page, ['view' => 'members']))->onRun();
        $this->assertSame([$fourth['invitation']->id], $page['invitations']->pluck('id')->all());
        $this->app['auth']->logout();
        try {
            $service->accept($fourth['token'], ['first_name' => 'Weak', 'password' => 'x', 'password_confirmation' => 'x']);
            $this->fail('Native password policy must reject weak passwords.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertFalse(User::where('email', $email)->exists());
            $this->assertNull($fourth['invitation']->fresh()->accepted_at);
        }
    }

    public function testEmailRegisteredAfterInvitationRequiresLoginWithoutCredentialOverwrite(): void
    {
        $one = $this->company();
        $this->acting($one);
        $service = new InvitationService;
        $email = bin2hex(random_bytes(8)) . '@example.invalid';
        $issued = $service->issue(['email' => $email, 'role_id' => $one['membership']->role_id, 'branch_ids' => []]);
        $user = new User;
        $user->first_name = 'Registered later';
        $user->email = $email;
        $user->password = $user->password_confirmation = 'Existing42!password';
        $user->activated_at = now();
        $user->save();
        $this->app['auth']->logout();
        $this->denied(fn () => $service->accept($issued['token'], ['password' => 'Overwritten42!']), 403);
        $this->app['auth']->login($user);
        $accepted = $service->accept($issued['token'], ['password' => 'Overwritten42!']);
        $this->assertTrue(\Hash::check('Existing42!password', $accepted->fresh()->password));
        $this->assertSame(1, User::where('email', $email)->count());
    }

    public function testMailFailureLeavesRecoverablePendingInvitation(): void
    {
        $one = $this->company();
        $this->acting($one);
        $email = bin2hex(random_bytes(8)) . '@example.invalid';
        $mail = \Mail::getFacadeRoot();
        \Mail::swap(new class {
            public function send(...$arguments)
            {
                throw new \RuntimeException('Synthetic transport failure');
            }
        });
        try {
            (new InvitationService)->issue(['email' => $email, 'role_id' => $one['membership']->role_id, 'branch_ids' => []]);
            $this->fail('Transport failure must be reported.');
        } catch (\October\Rain\Exception\ApplicationException $exception) {
            $this->assertStringContainsString('Invitation saved, but email could not be sent', $exception->getMessage());
            $this->assertSame(1, Invitation::pending()->where('email', $email)->count());
        } finally {
            \Mail::swap($mail);
        }
    }

    public function testPendingInvitationsProtectSharedRoleAndLastAdminRoleCannotBeWeakened(): void
    {
        $one = $this->company();
        $other = $this->company();
        $this->acting($one);
        $admin = new AdministrationService;
        $branch = $admin->saveBranch(['name' => 'Outside delegate']);
        $role = $admin->saveRole(['name' => 'Delegate', 'permissions' => ['users.manage', 'roles.manage']]);
        $reader = $admin->saveRole(['name' => 'Reader', 'permissions' => []]);
        $member = Membership::create(['company_id' => $one['company']->id, 'user_id' => $other['owner']->id, 'role_id' => $role->id]);
        $member->branches()->attach($one['branch']->id);
        (new InvitationService)->issue(['email' => bin2hex(random_bytes(8)) . '@example.invalid', 'role_id' => $reader->id, 'branch_ids' => [$branch->id]]);
        $this->acting($one, $other['owner']);
        $this->denied(fn () => $admin->saveRole(['id' => $reader->id, 'name' => 'Changed indirectly', 'permissions' => ['users.manage']]), 403);
        $this->acting($one);
        $this->denied(fn () => $admin->saveRole(['id' => $one['membership']->role_id, 'name' => 'Administrator', 'permissions' => ['users.manage']]), 409);
        $this->assertTrue($one['membership']->fresh()->isFullAdministrator());
        $this->acting($one, $other['owner']);
        $member->update(['is_active' => false]);
        $this->assertFalse(Workspace::hasAccess('users.manage'));
        $this->denied(fn () => $admin->saveRole(['name' => 'Revoked', 'permissions' => []]), 403);
    }

    public function testFrontendActorStaysNativeWithBackendSessionAndSpoofedPayload(): void
    {
        $one = $this->company();
        $this->acting($one);
        $backend = new \Backend\Models\User;
        $backend->login = 'phase2-' . bin2hex(random_bytes(6));
        $backend->email = $backend->login . '@example.invalid';
        $backend->password = $backend->password_confirmation = 'Synthetic42!password';
        $backend->save();
        \BackendAuth::login($backend);
        try {
            $this->app['auth']->forgetGuards();
            $this->assertInstanceOf(User::class, \Vmod\Core\Facades\Auth::getUser());
            (new AdministrationService)->saveBranch(['name' => 'Native actor', 'actor_user_id' => 999999, 'backend_user_id' => $backend->id]);
            $audit = Audit::where('company_id', $one['company']->id)->where('action', 'branch.created')->latest('id')->firstOrFail();
            $this->assertSame($one['owner']->id, $audit->actor_user_id);
            $this->assertSame('frontend', $audit->source);
            $this->assertArrayNotHasKey('backend_user_id', $audit->details);
        } finally {
            \BackendAuth::logout();
        }
    }

    public function testNativeRecoveryExpiryAndPublicRegistrationStayClosed(): void
    {
        $one = $this->company();
        $token = $this->app['auth.password']->broker('users')->createToken($one['owner']);
        \DB::table('user_password_resets')->where('email', $one['owner']->email)->update(['created_at' => now()->subMinutes(61)]);
        try {
            (new \Vmod\Core\Services\IdentityService)->reset([
                'token' => $token, 'email' => $one['owner']->email,
                'password' => 'Expired42!password', 'password_confirmation' => 'Expired42!password',
            ]);
            $this->fail('Expired native recovery tokens must fail.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertTrue(\Hash::check('Synthetic42!password', $one['owner']->fresh()->password));
        }
        \RainLab\User\Models\Setting::set('allow_registration', true);
        \RainLab\User\Models\Setting::clearInternalCache();
        $this->assertFalse((bool) \RainLab\User\Models\Setting::get('allow_registration'));
    }

    public function testRecoveryTransportFailureDoesNotRevealRegisteredEmails(): void
    {
        $one = $this->company();
        $mail = \Mail::getFacadeRoot();
        $failedTransport = new class {
            public $attempts = 0;
            public function send(...$arguments)
            {
                $this->attempts++;
                throw new \Symfony\Component\Mailer\Exception\TransportException('Synthetic transport outage');
            }
        };
        \Mail::swap($failedTransport);
        try {
            $service = new \Vmod\Core\Services\IdentityService;
            $this->assertNull($service->recover(['email' => $one['owner']->email]));
            $this->assertNull($service->recover(['email' => 'unknown-' . bin2hex(random_bytes(8)) . '@example.invalid']));
            $this->assertSame(1, $failedTransport->attempts);
        } finally {
            \Mail::swap($mail);
        }
    }

    public function testSignedOutContextDiffersFromStaleWorkspaceAndDeniedPermission(): void
    {
        $company = $this->company();
        $this->acting($company);
        $this->app['auth']->logout();
        $this->denied(fn () => Workspace::assertContext(), 401);
        $this->denied(fn () => Workspace::membership(), 401);
        Workspace::clear();
        $this->denied(fn () => Workspace::assertContext(), 401);
        $this->acting($company);
        \Request::instance()->headers->set('X-Workspace-Context', 'stale');
        $this->denied(fn () => Workspace::assertContext(), 409);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $this->denied(fn () => Workspace::authorize('unknown.permission'), 403);
    }
}
