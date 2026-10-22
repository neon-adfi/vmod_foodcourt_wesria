<?php namespace Vmod\CircularityUi\Tests;

use DB;
use Event;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Audit;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Role;
use Vmod\Core\Services\BootstrapService;
use Vmod\CircularityUi\Classes\CircularityClient;
use Vmod\CircularityUi\Updates\GrantCircularityPermissions;
use Vmod\Tests\ApplicationTestCase;

class BridgeTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        DB::beginTransaction();
        config(['vmod.circularityui::api.url' => 'http://nginx-tech/api/circularity/v1',
            'vmod.circularityui::api.allow_internal_http' => true,
            'vmod.circularityui::api.token' => 'private-test-token']);
        Http::swap(new Factory);
    }

    public function tearDown(): void
    {
        $this->app['auth']->logout();
        while (DB::transactionLevel()) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function company(): array
    {
        $suffix = bin2hex(random_bytes(6));
        return (new BootstrapService)->create([
            'company_code' => 'circ-' . $suffix, 'company_name' => 'Circularity test',
            'branch_name' => 'Court A', 'first_name' => 'Owner', 'email' => $suffix . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
        ]);
    }

    private function acting(array $company, bool $selectedBranch = true): void
    {
        $this->app['auth']->login($company['owner']);
        Workspace::clear();
        Workspace::switchTo($company['company']->id, $selectedBranch ? $company['branch']->id : null);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
    }

    private function denied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Operation must be denied.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            $this->assertStringNotContainsString('private-test-token', $exception->getMessage());
        }
    }

    public function testRegistryIsAppendOnlyAndRejectsMalformedExtensions(): void
    {
        $this->assertContains('circularity.read', Permissions::all());
        $this->assertContains('circularity.write', Permissions::all());
        $base = Permissions::all();
        Event::listen('vmod.core.extendPermissions', fn () => ['catalogue.read', 'example.read']);
        $this->assertSame(array_merge($base, ['example.read']), Permissions::all());
        Event::listen('vmod.core.extendPermissions', fn () => [false]);
        $this->expectException(\UnexpectedValueException::class);
        Permissions::all();
    }

    public function testMigrationPreservesAdministratorsAndDelegationAndIsRepeatSafe(): void
    {
        $this->assertTrue(class_exists(CircularityClient::class), 'Circularity bridge must exist.');
        $company = $this->company();
        $role = $company['membership']->role;
        $old = array_values(array_diff(Permissions::all(), ['circularity.read', 'circularity.write']));
        $role->permissions = array_merge($old, ['historic.extra']);
        $role->save();
        $partial = Role::create(['company_id' => $role->company_id, 'name' => 'Delegate', 'permissions' => array_slice($old, 1)]);
        $branches = $company['membership']->branchIds();
        require_once dirname(__DIR__) . '/updates/grant_circularity_permissions.php';
        (new GrantCircularityPermissions)->up();
        $this->assertTrue($company['membership']->fresh()->isFullAdministrator());
        $this->assertContains('historic.extra', $role->fresh()->permissions);
        $this->assertSame(array_slice($old, 1), $partial->fresh()->permissions);
        $this->assertSame($branches, $company['membership']->fresh()->branchIds());
        $query = Audit::where('company_id', $role->company_id)->where('action', 'circularity.permissions_added');
        $this->assertSame(1, $query->count());
        $this->assertNull($query->first()->actor_user_id);
        $this->assertSame('migration', $query->first()->source);
        (new GrantCircularityPermissions)->up();
        $this->assertSame(1, $query->count());
        (new GrantCircularityPermissions)->down();
        $this->assertContains('circularity.read', $role->fresh()->permissions);
    }

    public function testAuditFailureRollsBackPermissionChanges(): void
    {
        $this->assertTrue(class_exists(CircularityClient::class), 'Circularity bridge must exist.');
        $company = $this->company();
        $role = $company['membership']->role;
        $old = array_values(array_diff(Permissions::all(), ['circularity.read', 'circularity.write']));
        $role->permissions = $old;
        $role->save();
        DB::connection()->beforeExecuting(function ($query) {
            if (str_starts_with($query, 'insert into `vmod_core_audits`')) {
                throw new \RuntimeException('Test audit failure');
            }
        });
        require_once dirname(__DIR__) . '/updates/grant_circularity_permissions.php';
        try {
            (new GrantCircularityPermissions)->up();
            $this->fail('Audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test audit failure', $exception->getMessage());
        }
        $this->assertSame($old, $role->fresh()->permissions);
    }

    public function testClientUsesFreshFrontendScopeAndNeverForwardsBrowserAuthority(): void
    {
        $this->assertTrue(class_exists(CircularityClient::class), 'Circularity bridge must exist.');
        $company = $this->company();
        $second = Branch::create(['company_id' => $company['company']->id, 'name' => 'Court B']);
        $company['membership']->branches()->syncWithoutDetaching([$second->id]);
        $this->acting($company, false);
        \Request::instance()->headers->set('X-Circularity-Context', '{"tenant_ref":"forged"}');
        \Request::instance()->headers->set('Cookie', 'browser-secret=never-forward');
        Http::fake(['*' => Http::response(['ok' => true])]);
        $client = new CircularityClient;
        $this->assertSame(['ok' => true], $client->request('GET', '/probe'));
        Http::assertSent(function ($request) use ($company, $second) {
            $context = json_decode($request->header('X-Circularity-Context')[0], true);
            return $context['tenant_ref'] === (string) $company['company']->id
                && $context['actor_ref'] === (string) $company['owner']->id
                && $context['branch_refs'] === [(string) $company['branch']->id, (string) $second->id]
                && !$request->hasHeader('Cookie')
                && $request->header('Authorization') === ['Bearer private-test-token'];
        });
        $this->denied(fn () => $client->request('POST', '/probe'), 422);
        $this->denied(fn () => $client->request('POST', '/probe', [], 'foreign-court'), 404);
        $this->assertSame(['ok' => true], $client->request('POST', '/probe', [], (string) $second->id));
        Http::assertSentCount(2);
        $role = $company['membership']->role;
        $role->permissions = ['catalogue.read'];
        $role->save();
        $this->denied(fn () => $client->request('GET', '/probe'), 403);
        Http::assertSentCount(2);
    }

    public function testSelectedBranchAndStaleWorkspaceAreEnforced(): void
    {
        $this->assertTrue(class_exists(CircularityClient::class), 'Circularity bridge must exist.');
        $company = $this->company();
        $second = Branch::create(['company_id' => $company['company']->id, 'name' => 'Court B']);
        $company['membership']->branches()->syncWithoutDetaching([$second->id]);
        $this->acting($company);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $client = new CircularityClient;
        $client->request('GET', '/probe');
        Http::assertSent(fn ($request) => json_decode($request->header('X-Circularity-Context')[0], true)['branch_refs']
            === [(string) $company['branch']->id]);
        $this->denied(fn () => $client->request('PATCH', '/probe', [], (string) $second->id), 404);
        Workspace::switchTo($company['company']->id, $second->id);
        $this->denied(fn () => $client->request('POST', '/probe'), 409);
        Http::assertSentCount(1);
    }

    public function testHealthClientRejectsErrorsRedirectsAndInvalidResponseShapes(): void
    {
        $this->assertTrue(class_exists(CircularityClient::class), 'Circularity bridge must exist.');
        foreach ([[503, '<html>maintenance</html>'], [302, ''], [401, ['message' => 'secret diagnostic']],
            [200, ['status' => 'ok']], [200, 'not json']] as [$status, $body]) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($body, $status)]);
            $this->denied(fn () => (new CircularityClient)->health(), 503);
            Http::assertSentCount(1);
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['service' => 'circularity', 'api_version' => 'v1', 'status' => 'ok'])]);
        $this->assertSame('ok', (new CircularityClient)->health()['status']);
        config(['vmod.circularityui::api.allow_internal_http' => false]);
        $this->denied(fn () => (new CircularityClient)->health(), 503);
        Http::assertSentCount(1);
    }

    public function testConfiguredUrlsCannotEmbedCredentialsOrUseOtherPlainHttpHosts(): void
    {
        Http::fake(['*' => Http::response(['service' => 'circularity', 'api_version' => 'v1', 'status' => 'ok'])]);
        foreach (['https://embedded-user@example.invalid/api', 'http://other-host/api',
            'https://example.invalid/api?token=secret', 'https://example.invalid/api#fragment'] as $url) {
            config(['vmod.circularityui::api.url' => $url]);
            $this->denied(fn () => (new CircularityClient)->health(), 503);
        }
        Http::assertNothingSent();
    }

    public function testReadOnlyAndEmptyBranchGrantsNeverDispatchWrites(): void
    {
        $company = $this->company();
        $role = $company['membership']->role;
        $role->permissions = ['circularity.read'];
        $role->save();
        $this->acting($company, false);
        Http::fake();
        $client = new CircularityClient;
        $this->denied(fn () => $client->request('POST', '/probe', [], (string) $company['branch']->id), 403);
        $company['membership']->branches()->detach();
        $this->denied(fn () => $client->request('GET', '/probe'), 403);
        Http::assertNothingSent();
    }

    public function testProviderFieldErrorsRemainEditableWithoutLeakingOtherBodies(): void
    {
        $company = $this->company();
        $this->acting($company);
        Http::fake(['*' => Http::response(['errors' => ['quantity' => ['Enter a nonnegative quantity.']]], 422)]);
        try {
            (new CircularityClient)->request('POST', '/probe');
            $this->fail('Provider validation must reach native form errors.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertSame(['quantity' => ['Enter a nonnegative quantity.']], $exception->errors());
        }
        Http::assertSentCount(1);
    }

    public function testCaptureResponsesAreValidatedAndConflictsExposeOnlySafeMetadata(): void
    {
        $company = $this->company();
        $this->acting($company);
        foreach (['/areas', '/entries', '/options', '/entries/7a63453a-7953-42b3-a352-8042d61e09b4'] as $path) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['ok' => true])]);
            $this->denied(fn () => (new CircularityClient)->request('GET', $path), 503);
        }
        $uuid = '7a63453a-7953-42b3-a352-8042d61e09b4';
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['entry' => ['uuid' => $uuid, 'status' => 'removed', 'version' => 2]])]);
        $this->assertSame(2, (new CircularityClient)->request('GET', '/entries/' . $uuid)['entry']['version']);
        foreach ([
            ['code' => 'daily_total_exists', 'uuid' => $uuid, 'want' => ['code' => 'daily_total_exists', 'uuid' => $uuid]],
            ['code' => 'malicious', 'uuid' => $uuid, 'want' => ['code' => 'conflict']],
            ['code' => 'entry_changed', 'uuid' => 'https://bad.invalid', 'want' => ['code' => 'entry_changed']],
        ] as $case) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['code' => $case['code'], 'uuid' => $case['uuid'],
                'url' => 'https://bad.invalid', 'message' => 'private diagnostic'], 409)]);
            try {
                (new CircularityClient)->request('POST', '/entries');
                $this->fail('Conflict must preserve safe recovery metadata.');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
                $response = $exception->getResponse();
                $this->assertSame(409, $response->getStatusCode());
                $data = json_decode($response->getContent(), true);
                $this->assertStringNotContainsString('private diagnostic', $response->getContent());
                $this->assertStringNotContainsString('https://', $response->getContent());
                unset($data['message']);
                $this->assertSame($case['want'], $data);
            }
        }
    }

    public function testCaptureComponentKeepsConflictAndUnknownLookupAsNativeAjaxResponses(): void
    {
        $this->assertTrue(class_exists(\Vmod\CircularityUi\Components\Capture::class));
        $company = $this->company();
        $this->acting($company);
        $uuid = '7a63453a-7953-42b3-a352-8042d61e09b4';
        \Request::instance()->setMethod('POST');
        \Request::instance()->request->replace(['uuid' => $uuid, 'branch_ref' => (string) $company['branch']->id, 'editing' => '0']);
        Http::fake(['*' => Http::response(['code' => 'daily_total_exists', 'uuid' => $uuid, 'message' => 'private'], 409)]);
        $response = (new \Vmod\CircularityUi\Components\Capture)->onSaveEntry()->toResponse(\Request::instance());
        $this->assertSame(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame($uuid, $data['circularityConflict']['uuid']);
        $this->assertFalse($data['__ajax']['ok']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['message' => 'private'], 404)]);
        $result = (new \Vmod\CircularityUi\Components\Capture)->onCheckEntry();
        $this->assertSame('not_found', $result['circularityCheck']['status']);
        $this->assertArrayNotHasKey('version', $result['circularityCheck']);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/entries/' . $uuid));
    }

    public function testProviderValidationTextCannotInjectMarkupIntoNativeErrors(): void
    {
        $this->acting($this->company());
        Http::fake(['*' => Http::response(['errors' => ['notes' => ['Invalid <img src=x onerror=alert(1)>']]], 422)]);
        try {
            (new CircularityClient)->request('POST', '/entries');
            $this->fail('Validation expected.');
        } catch (\October\Rain\Exception\ValidationException $exception) {
            $this->assertSame(['notes' => ['Invalid &lt;img src=x onerror=alert(1)&gt;']], $exception->errors());
        }
    }

    private function summaryPayload(): array
    {
        $totals = [];
        foreach (['food_waste', 'packaging_waste', 'mixed_waste', 'used_cooking_oil', 'reusable_issued', 'reusable_returned', 'single_use_issued'] as $type) {
            $totals[$type] = ['unit' => in_array($type, ['reusable_issued', 'reusable_returned', 'single_use_issued'], true) ? 'count' : 'kg',
                'quantity' => null, 'entry_count' => 0, 'destinations' => []];
        }
        $totals['food_waste'] = ['unit' => 'kg', 'quantity' => '1999999999999.998', 'entry_count' => 2,
            'destinations' => [['destination' => 'composting', 'quantity' => '1999999999999.998', 'entry_count' => 2]]];
        return ['filters' => ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'],
            'reporting_timezone' => 'Asia/Kuala_Lumpur', 'totals' => $totals];
    }

    public function testSingleUseRequiresSevenMetricsAndWholeCountSupport(): void
    {
        $this->acting($this->company());
        $valid = $this->summaryPayload();
        $valid['totals']['single_use_issued'] = ['unit' => 'count', 'quantity' => '130', 'entry_count' => 2,
            'destinations' => [['destination' => 'not_applicable', 'quantity' => '130', 'entry_count' => 2]]];
        Http::fake(['*' => Http::response($valid)]);
        $this->assertSame($valid, (new CircularityClient)->request('GET', '/summary'));
        foreach (['missing', 'unit', 'fraction', 'float', 'destination'] as $problem) {
            $payload = $valid;
            if ($problem === 'missing') {
                unset($payload['totals']['single_use_issued']);
            } else {
                $key = ['unit' => 'unit', 'fraction' => 'quantity', 'float' => 'quantity',
                    'destination' => 'destinations.0.destination'][$problem];
                $value = ['unit' => 'kg', 'fraction' => '130.000', 'float' => 130,
                    'destination' => 'unknown'][$problem];
                \Illuminate\Support\Arr::set($payload, 'totals.single_use_issued.' . $key, $value);
            }
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($payload)]);
            $this->denied(fn () => (new CircularityClient)->request('GET', '/summary'), 503);
        }
        foreach ([null, 'kg', 'count'] as $unit) {
            $types = ['used_cooking_oil' => ['label' => 'Used cooking oil', 'unit' => 'kg']];
            if ($unit !== null) {
                $types['single_use_issued'] = ['label' => 'Single-use items issued', 'unit' => $unit];
            }
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['types' => $types, 'modes' => ['daily_total' => 'Daily total'],
                'destinations' => ['not_applicable' => 'Not applicable'], 'today' => '2026-01-31', 'reporting_timezone' => 'Asia/Kuala_Lumpur'])]);
            if ($unit === 'count') {
                $this->assertSame($types, (new CircularityClient)->request('GET', '/options')['types']);
            } else {
                $this->denied(fn () => (new CircularityClient)->request('GET', '/options'), 503);
            }
        }
    }

    public function testUsedCookingOilRejectsLegacyOrMalformedProviderSupport(): void
    {
        $this->acting($this->company());
        $valid = $this->summaryPayload();
        foreach (['missing', 'unit', 'unknown'] as $problem) {
            $payload = $valid;
            if ($problem === 'missing') {
                unset($payload['totals']['used_cooking_oil']);
            } elseif ($problem === 'unit') {
                $payload['totals']['used_cooking_oil']['unit'] = 'count';
            } else {
                $payload['totals']['unexpected'] = $payload['totals']['used_cooking_oil'];
                unset($payload['totals']['used_cooking_oil']);
            }
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($payload)]);
            $this->denied(fn () => (new CircularityClient)->request('GET', '/summary'), 503);
        }
        foreach ([null, 'count', 'kg'] as $unit) {
            $types = ['food_waste' => ['label' => 'Food waste', 'unit' => 'kg'],
                'single_use_issued' => ['label' => 'Single-use items issued', 'unit' => 'count']];
            if ($unit !== null) {
                $types['used_cooking_oil'] = ['label' => 'Used cooking oil', 'unit' => $unit];
            }
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['types' => $types, 'modes' => ['daily_total' => 'Daily total'],
                'destinations' => ['unknown' => 'Unknown'], 'today' => '2026-01-31', 'reporting_timezone' => 'Asia/Kuala_Lumpur'])]);
            if ($unit === 'kg') {
                $this->assertSame($types, (new CircularityClient)->request('GET', '/options')['types']);
            } else {
                $this->denied(fn () => (new CircularityClient)->request('GET', '/options'), 503);
            }
        }
        $single = $this->reportingPage(['measurement_type' => 'single_use_issued']);
        $this->assertStringContainsString('measurement_type=single_use_issued', $single['summaryLinks']['single_use_issued']['total']);
        $page = $this->reportingPage(['measurement_type' => 'used_cooking_oil']);
        $this->assertTrue(isset($page['comparison']));
        $this->assertStringContainsString('measurement_type=used_cooking_oil', $page['summaryLinks']['used_cooking_oil']['total']);
    }

    public function testSummaryRejectsMalformedResultsAndPreservesLargeExactStrings(): void
    {
        $this->acting($this->company());
        $valid = $this->summaryPayload();
        Http::fake(['*' => Http::response($valid)]);
        $this->assertSame($valid, (new CircularityClient)->request('GET', '/summary'));
        $invalid = [
            ['totals.food_waste.quantity', 1.25], ['totals.food_waste.unit', 'count'],
            ['totals.food_waste.entry_count', 0], ['totals.food_waste.destinations', []],
            ['totals.food_waste.destinations.0.destination', 'not_applicable'],
            ['totals.food_waste.destinations.0.quantity', '1e3'],
            ['totals.reusable_issued.quantity', '0.000'], ['totals.reusable_issued', null],
            ['filters.date_to', '2025-12-01'], ['filters.date_from', ['2026-01-01']],
            ['reporting_timezone', 'invalid'], ['totals.food_waste.destinations.0.entry_count', '2'],
        ];
        foreach ($invalid as [$key, $value]) {
            $payload = $valid;
            \Illuminate\Support\Arr::set($payload, $key, $value);
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($payload)]);
            $this->denied(fn () => (new CircularityClient)->request('GET', '/summary'), 503);
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->denied(fn () => (new CircularityClient)->request('GET', '/summary'), 503);
    }

    public function testOverviewUsesEffectiveFiltersAndGetErrorsStayCorrectable(): void
    {
        $this->acting($this->company());
        $options = ['types' => ['food_waste' => ['label' => 'Food waste', 'unit' => 'kg'],
                'used_cooking_oil' => ['label' => 'Used cooking oil', 'unit' => 'kg'],
                'single_use_issued' => ['label' => 'Single-use items issued', 'unit' => 'count']],
            'destinations' => ['composting' => 'Composting'], 'modes' => ['daily_total' => 'Daily total'],
            'today' => '2026-01-31', 'reporting_timezone' => 'Asia/Kuala_Lumpur'];
        Http::fake(['*/options' => Http::response($options), '*/areas' => Http::response(['areas' => []]),
            '*/summary' => Http::response($this->summaryPayload()), '*' => Http::response(['ok' => true])]);
        \Request::instance()->setMethod('GET');
        \Request::instance()->query->replace([]);
        $page = new \ArrayObject(['controller' => new \Cms\Classes\Controller], \ArrayObject::ARRAY_AS_PROPS);
        (new \Vmod\CircularityUi\Components\Capture($page, ['view' => 'overview']))->onRun();
        $this->assertArrayHasKey('date_from', $page['filters']);
        $this->assertSame('2026-01-01', $page['filters']['date_from']);
        $this->assertSame('1999999999999.998', $page['summary']['totals']['food_waste']['quantity']);
        $this->assertStringContainsString('date_from=2026-01-01', $page['measurementUrl']);
        $this->assertStringContainsString('measurement_type=food_waste', $page['summaryLinks']['food_waste']['total']);

        foreach ([['date_from' => '2026-01-01'], ['date_from' => ['bad'], 'date_to' => '2026-01-31']] as $query) {
            \Request::instance()->query->replace($query);
            $page = new \ArrayObject(['controller' => new \Cms\Classes\Controller], \ArrayObject::ARRAY_AS_PROPS);
            (new \Vmod\CircularityUi\Components\Capture($page, ['view' => 'overview']))->onRun();
            $this->assertNotEmpty($page['queryErrors']);
            $this->assertArrayHasKey('date_from', $page['queryValues']);
            $this->assertArrayHasKey('date_to', $page['queryValues']);
            $this->assertFalse(isset($page['summary']));
        }
        \Request::instance()->query->replace(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        Http::swap(new Factory);
        Http::fake(['*/options' => Http::response($options), '*/areas' => Http::response(['areas' => []]),
            '*/summary*' => Http::response(['errors' => ['date_to' => ['Choose an end date.']]], 422)]);
        $page = new \ArrayObject(['controller' => new \Cms\Classes\Controller], \ArrayObject::ARRAY_AS_PROPS);
        (new \Vmod\CircularityUi\Components\Capture($page, ['view' => 'overview']))->onRun();
        $this->assertSame('2026-01-31', $page['queryValues']['date_to']);
        $this->assertSame(['Choose an end date.'], $page['queryErrors']['date_to']);
    }

    public function testGroupedSummaryRequiresRequestedDimensionUniqueKeysAndValidMetrics(): void
    {
        $this->acting($this->company());
        foreach (['area' => '42', 'date' => '2026-01-01', 'branch' => 'Court-A'] as $dimension => $key) {
            $valid = $this->summaryPayload();
            $valid['group_by'] = $dimension;
            $valid['groups'] = [['key' => $key, 'totals' => $valid['totals']]];
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($valid)]);
            $this->assertSame($valid, (new CircularityClient)->request('GET', '/summary', ['group_by' => $dimension]));
            $invalid = [$this->summaryPayload(), array_replace($valid, ['group_by' => 'unexpected']),
                array_replace($valid, ['groups' => [$valid['groups'][0], $valid['groups'][0]]]),
                array_replace($valid, ['groups' => []])];
            if ($dimension === 'date') {
                foreach (['2025-12-31', '2026-02-01'] as $outside) {
                    $bad = $valid;
                    $bad['groups'][0]['key'] = $outside;
                    $invalid[] = $bad;
                }
            }
            foreach ([['groups.0.key', 42], ['groups.0.key', ''], ['groups.0.totals.food_waste.quantity', 1.25],
                ['groups.0.totals.packaging_waste.quantity', '0.000']] as [$path, $value]) {
                $bad = $valid;
                \Illuminate\Support\Arr::set($bad, $path, $value);
                $invalid[] = $bad;
            }
            foreach ($invalid as $payload) {
                Http::swap(new Factory);
                Http::fake(['*' => Http::response($payload)]);
                $this->denied(fn () => (new CircularityClient)->request('GET', '/summary', ['group_by' => $dimension]), 503);
            }
        }
    }

    public function testGroupedReportingKeepsInactiveHistoryAndScopesEmptyAreaRowsAndDailyLinks(): void
    {
        $company = $this->company();
        $this->acting($company);
        $areas = [];
        foreach ([42 => false, 43 => true, 44 => false] as $id => $active) {
            $areas[] = ['id' => $id, 'branch_ref' => (string) $company['branch']->id, 'name' => 'Area ' . $id,
                'kind' => 'stall', 'version' => 1, 'is_active' => $active];
        }
        $page = $this->reportingPage(['report' => 'area'], 200, $areas);
        $this->assertTrue(isset($page['reportRows']), 'Area reporting must render grouped rows.');
        $rows = array_column($page['reportRows'], null, 'key');
        $this->assertSame(['42', '43'], array_column($page['reportRows'], 'key'));
        $this->assertSame('1999999999999.998', $rows['42']['totals']['food_waste']['quantity']);
        $this->assertSame([], $rows['43']['totals']);
        $this->assertStringContainsString('area_id=42', $rows['42']['links']['food_waste']['total']);
        Http::assertSentCount(3);
        $html = (new \Cms\Classes\Controller)->run('/circularity/reporting')->getContent();
        $this->assertStringContainsString('colspan="7" class="text-secondary">No matching records', $html);
        $page = $this->reportingPage(['report' => 'daily', 'date_from' => '2024-01-01', 'date_to' => '2026-01-31']);
        $this->assertCount(1, $page['reportRows']);
        $this->assertSame('2026-01-01', $page['reportRows'][0]['key']);
        $link = $page['reportRows'][0]['links']['food_waste']['total'];
        $this->assertStringContainsString('date_from=2026-01-01', $link);
        $this->assertStringContainsString('date_to=2026-01-01', $link);
        $this->assertStringNotContainsString('group_by=', $link);
        $this->assertStringNotContainsString('report=', $link);
        Http::assertSentCount(3);
    }

    public function testAreaReportDistinguishesSameNamedAreasAcrossCourts(): void
    {
        $company = $this->company();
        $other = Branch::create(['company_id' => $company['company']->id, 'name' => 'Court B']);
        $company['membership']->branches()->attach($other->id);
        $this->acting($company, false);
        $areas = [];
        foreach ([42 => $company['branch']->id, 43 => $other->id] as $id => $branch) {
            $areas[] = ['id' => $id, 'branch_ref' => (string) $branch, 'name' => 'Stall 1',
                'kind' => 'stall', 'version' => 1, 'is_active' => true];
        }
        $page = $this->reportingPage(['report' => 'area'], 200, $areas);
        $this->assertStringContainsString('Court A', $page['reportRows'][0]['label']);
        $this->assertStringContainsString('Court B', $page['reportRows'][1]['label']);
        $html = (new \Cms\Classes\Controller)->run('/circularity/reporting')->getContent();
        $this->assertStringContainsString('Stall 1 · Court A</option>', $html);
        $this->assertStringContainsString('Stall 1 · Court B</option>', $html);
    }

    public function testGroupedTableKeepsMetricColumnsWhenProviderObjectKeysAreReordered(): void
    {
        $this->acting($this->company());
        $this->reportingPage(['report' => 'daily'], 200, [], true);
        $html = (new \Cms\Classes\Controller)->run('/circularity/reporting')->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $cells = (new \DOMXPath($document))->query('//tr[@data-report-key="2026-01-01"]/td');
        $this->assertSame('food_waste', $cells->item(0)->getAttribute('data-report-type'));
        $this->assertSame('1999999999999.998', trim($cells->item(0)->textContent));
        $this->assertSame('used_cooking_oil', $cells->item(3)->getAttribute('data-report-type'));
        $this->assertSame('reusable_returned', $cells->item(5)->getAttribute('data-report-type'));
    }

    private function reportingPage(array $query = [], int $comparisonStatus = 200, array $areas = [], bool $reorder = false): \ArrayObject
    {
        $types = [];
        foreach ($this->summaryPayload()['totals'] as $type => $total) {
            $types[$type] = ['label' => $type, 'unit' => $total['unit']];
        }
        $summaryCalls = 0;
        Http::swap(new Factory);
        Http::fake(function ($request) use ($types, $comparisonStatus, $areas, $reorder, &$summaryCalls) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/options')) {
                return Http::response(['types' => $types, 'destinations' => ['composting' => 'Composting'],
                    'modes' => ['daily_total' => 'Daily total'], 'today' => '2026-01-31',
                    'reporting_timezone' => 'Asia/Kuala_Lumpur']);
            }
            if (str_ends_with($path, '/areas')) {
                return Http::response(['areas' => $areas]);
            }
            if (str_ends_with($path, '/summary')) {
                $payload = $this->summaryPayload();
                $payload['filters'] = array_replace($payload['filters'], array_diff_key($request->data(), ['group_by' => true]));
                if (isset($request['group_by'])) {
                    $payload['group_by'] = $request['group_by'];
                    $payload['groups'] = [['key' => $request['group_by'] === 'date' ? '2026-01-01' : '42',
                        'totals' => $reorder ? array_reverse($payload['totals'], true) : $payload['totals']]];
                }
                return Http::response($payload, ++$summaryCalls === 1 ? 200 : $comparisonStatus);
            }
            return Http::response(['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0]);
        });
        \Request::instance()->setMethod('GET');
        \Request::instance()->query->replace($query);
        $page = new \ArrayObject(['controller' => new \Cms\Classes\Controller], \ArrayObject::ARRAY_AS_PROPS);
        (new \Vmod\CircularityUi\Components\Capture($page, ['view' => 'reporting']))->onRun();
        return $page;
    }

    public function testReportingAcceptsEmptyCourtWithoutBroadeningExplicitCourtScope(): void
    {
        $company = $this->company();
        $other = Branch::create(['company_id' => $company['company']->id, 'name' => 'Court B']);
        $company['membership']->branches()->attach($other->id);
        $this->acting($company, false);
        $page = $this->reportingPage(['report' => 'period', 'branch_ref' => '']);
        $this->assertTrue(isset($page['comparison']));
        Http::assertSent(function ($request) use ($company, $other) {
            $context = json_decode($request->header('X-Circularity-Context')[0], true);
            return str_ends_with($request->url(), '/summary')
                && $context['branch_refs'] === [(string) $company['branch']->id, (string) $other->id];
        });
        foreach (['0', 'foreign-court'] as $branch) {
            $this->denied(fn () => $this->reportingPage(['branch_ref' => $branch]), 404);
        }
        $this->acting($company);
        $this->reportingPage(['branch_ref' => '']);
        Http::assertSent(function ($request) use ($company) {
            $context = json_decode($request->header('X-Circularity-Context')[0], true);
            return str_ends_with($request->url(), '/summary')
                && $context['branch_refs'] === [(string) $company['branch']->id];
        });
    }

    public function testReportingComparesInclusivePeriodsAndKeepsDrilldownsScoped(): void
    {
        $this->acting($this->company());
        foreach ([
            [[], '2025-12-01', '2025-12-31'],
            [['date_from' => '2024-03-01', 'date_to' => '2024-03-01'], '2024-02-29', '2024-02-29'],
            [['date_from' => '2026-01-01', 'date_to' => '2026-01-01'], '2025-12-31', '2025-12-31'],
            [['date_from' => '2024-02-28', 'date_to' => '2024-03-01'], '2024-02-25', '2024-02-27'],
        ] as [$query, $from, $to]) {
            $page = $this->reportingPage($query + ['report' => 'period', 'destination' => 'composting']);
            $this->assertTrue(isset($page['comparison']), 'Reporting must load a comparison, not the entry list.');
            $this->assertSame($from, $page['comparison']['filters']['date_from']);
            $this->assertSame($to, $page['comparison']['filters']['date_to']);
            $this->assertSame('1999999999999.998', $page['summary']['totals']['food_waste']['quantity']);
            $this->assertNull($page['comparison']['totals']['packaging_waste']['quantity']);
            $link = $page['comparisonLinks']['food_waste']['total'];
            $this->assertStringContainsString('date_from=' . $from, $link);
            $this->assertStringContainsString('destination=composting', $link);
            $this->assertStringNotContainsString('report=', $link);
            Http::assertSentCount(4);
        }
        $page = $this->reportingPage(['report' => 'invalid']);
        $this->assertArrayHasKey('report', $page['queryErrors']);
        Http::assertNothingSent();
    }

    public function testReportingKeepsCurrentTotalsOnlyOnComparisonServiceFailure(): void
    {
        $this->acting($this->company());
        foreach ([429, 503] as $status) {
            $page = $this->reportingPage([], $status);
            $this->assertTrue($page['comparisonUnavailable'] ?? false);
            $this->assertFalse(isset($page['circularityUnavailable']));
            $this->assertSame('1999999999999.998', $page['summary']['totals']['food_waste']['quantity']);
        }
        foreach ([403, 404] as $status) {
            $this->denied(fn () => $this->reportingPage([], $status), $status);
        }
    }

    public function testUnavailableOverviewRendersTheNativeWarningWithoutTotals(): void
    {
        $this->acting($this->company());
        \Request::instance()->setMethod('GET');
        \Request::instance()->query->replace([]);
        Http::fake([
            '*/options' => Http::response(['types' => ['food_waste' => ['label' => 'Food waste', 'unit' => 'kg'],
                'used_cooking_oil' => ['label' => 'Used cooking oil', 'unit' => 'kg'],
                'single_use_issued' => ['label' => 'Single-use items issued', 'unit' => 'count']],
                'modes' => ['daily_total' => 'Daily total'], 'destinations' => ['unknown' => 'Unknown'],
                'reporting_timezone' => 'Asia/Kuala_Lumpur', 'today' => '2026-01-31']),
            '*/areas' => Http::response(['areas' => []]),
            '*' => Http::response(['message' => 'private upstream failure'], 503),
        ]);
        $response = (new \Cms\Classes\Controller)->run('/circularity');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('Circularity is temporarily unavailable.', $response->getContent());
        $this->assertStringNotContainsString('data-summary-quantity', $response->getContent());
        $this->assertStringNotContainsString('private upstream failure', $response->getContent());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/summary'));
        // Optional private browser fixture: actual native rendering with a simulated upstream outage.
        if (getenv('VMOD_CIRCULARITY_ERROR_HTML')) {
            file_put_contents(getenv('VMOD_CIRCULARITY_ERROR_HTML'), $response->getContent());
        }
    }
}
