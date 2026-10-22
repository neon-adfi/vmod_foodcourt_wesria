<?php

// phpcs:disable PSR1.Files.SideEffects -- Guarded standalone HTTP runner and test front controller.
// Test-only front controller and runner. Explicit guard precedes any application boot or writes.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
if (PHP_SAPI === 'cli') {
    // October renders standalone console exceptions without guaranteeing a nonzero status.
    register_shutdown_function(static function () {
        if (empty($GLOBALS['circularityProofCompleted'])) {
            exit(1);
        }
    });
}
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
if (getenv('PLUGINS_PATH') === '/proof/plugins') {
    // PLUGINS_PATH changes discovery, but October's default autoloader still searches "plugins".
    // Replace that fallback too, so unrelated plugins are genuinely unavailable in this worker.
    $loader = \October\Rain\Composer\ClassLoader::instance();
    $loader->removeDirectories('plugins');
    $loader->withDirectories('../../../proof/plugins');
}
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
if (getenv('VMOD_BROWSER_PROOF') === 'true') {
    // Laravel resolves file cache/session paths before October applies its storage override.
    $app->useStoragePath(getenv('STORAGE_PATH'));
}
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.connections.' . config('database.default'));
$connection['environment'] = $app->environment();
$connection['owner'] = getenv('VMOD_TEST_DATABASE_OWNER');
$connection['config_cache'] = $app->getCachedConfigPath();
\Vmod\Tests\DatabaseGuard::assertSafe($connection, $app->configurationIsCached());
$plugins = \System\Classes\PluginManager::instance();
$plugins->registerAll(true);
$plugins->bootAll(true);

function proofReceipt(array $request, string $stage, array $extra = []): void
{
    file_put_contents('/proof/receipts.jsonl', json_encode($request + ['stage' => $stage, 'time' => microtime(true)] + $extra) . "\n", FILE_APPEND | LOCK_EX);
}

function proofHold(array $receipt, array $barrier): void
{
    proofReceipt($receipt, 'held', ['barrier' => $barrier['id'], 'mode' => $barrier['mode']]);
    $deadline = microtime(true) + 35;
    do {
        clearstatcache();
        if (is_file('/proof/barriers/' . $barrier['id'] . '.release')) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Test barrier was not released within 35 seconds.');
}

if (PHP_SAPI === 'fpm-fcgi') {
    $request = \Illuminate\Http\Request::capture();
    if (getenv('VMOD_EXTRACTED_PROOF') === 'true' && getenv('PLUGINS_PATH') === '/proof/plugins'
        && is_file('/proof/source-frozen') && !in_array($request->method(), ['GET', 'HEAD'], true)) {
        response()->json(['message' => 'Source paused for extraction proof.'], 503)->send();
        return;
    }
    if ($request->path() === '__nested') {
        $health = (new \Vmod\CircularityUi\Classes\CircularityClient)->health();
        response()->json(['nested' => $health['status'] === 'ok'])->send();
        return;
    }
    $app['router']->get('/api/circularity/v1/__isolation', function () use ($plugins) {
        $isolated = array_keys($plugins->getPlugins()) === ['Vmod.Circularity']
            && !class_exists(\Vmod\Core\Classes\Workspace::class)
            && !class_exists(\Vmod\Erp\Plugin::class)
            && !class_exists(\Vmod\CircularityUi\Plugin::class);
        return response()->json(['isolated' => $isolated]);
    })->middleware(\Vmod\Circularity\Classes\ApiAccess::class);
    $app['router']->get('/api/circularity/v1/__scope/{branch}', function (\Illuminate\Http\Request $request, $branch) {
        \Vmod\Circularity\Classes\ApiAccess::requireBranch($request, $branch);
        return response()->json(['scope' => $request->attributes->get('circularity.context')]);
    })->middleware(\Vmod\Circularity\Classes\ApiAccess::class . ':read');
    $receipt = $barrier = null;
    // Test front controller only: exact fixture tenant and request, never production middleware.
    if (getenv('VMOD_BROWSER_PROOF') === 'true' && getenv('PLUGINS_PATH') === '/proof/plugins'
        && is_file('/proof/scope.json')) {
        $scope = json_decode(file_get_contents('/proof/scope.json'), true, 512, JSON_THROW_ON_ERROR);
        $context = json_decode($request->header('X-Circularity-Context', '{}'), true);
        if (in_array($context['tenant_ref'] ?? null, $scope['tenants'], true)) {
            ignore_user_abort(true);
            $uuid = $request->input('uuid') ?? (preg_match('#/entries/([a-f0-9-]+)$#', $request->path(), $match) ? $match[1] : null);
            $receipt = ['request' => bin2hex(random_bytes(8)), 'method' => $request->method(),
                'path' => $request->path(), 'uuid' => $uuid];
            proofReceipt($receipt, 'received');
            if (is_file('/proof/barriers/armed.json')) {
                $candidate = json_decode(file_get_contents('/proof/barriers/armed.json'), true, 512, JSON_THROW_ON_ERROR);
                if ($candidate['method'] === $receipt['method'] && $candidate['path'] === $receipt['path']
                    && $candidate['uuid'] === $uuid && preg_match('/\A[a-f0-9]{32}\z/', $candidate['id'])) {
                    rename('/proof/barriers/armed.json', '/proof/barriers/' . $candidate['id'] . '.claimed');
                    $barrier = $candidate;
                }
            }
            if ($barrier && in_array($barrier['mode'], ['before_dispatch', 'revocation'], true)) {
                proofHold($receipt, $barrier);
            }
            if ($barrier && in_array($barrier['mode'], ['before_dispatch', 'unavailable'], true)) {
                response()->json(['message' => 'Guarded proof unavailable.'], 503)->send();
                proofReceipt($receipt, 'drained', ['dispatched' => false]);
                return;
            }
            proofReceipt($receipt, 'dispatched');
        }
    }
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    if ($receipt) {
        proofReceipt($receipt, 'completed', ['status' => $response->getStatusCode()]);
        if (($barrier['mode'] ?? null) === 'after_commit') {
            proofHold($receipt, $barrier);
        }
    }
    $response->send();
    $kernel->terminate($request, $response);
    if ($receipt) {
        proofReceipt($receipt, 'drained', ['dispatched' => true]);
    }
    return;
}

function checkCircularity($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function browserFixture($http, string $api): array
{
    $bootstrap = new \Vmod\Core\Services\BootstrapService;
    $bootstrap->assertOwnedEnvironment();
    $fixture = ['nonce' => bin2hex(random_bytes(6)), 'password' => bin2hex(random_bytes(20)) . '7!',
        'base_url' => config('app.url'), 'proof_dir' => getenv('VMOD_PROOF_HOST_DIR'),
        'runtime' => ['php' => PHP_VERSION, 'mysql' => \DB::selectOne('select version() as version')->version,
            'database' => \DB::connection()->getDatabaseName(), 'host' => config('database.connections.mysql.host'),
            'session' => config('session.driver'), 'cache' => config('cache.default')]];
    foreach (['a', 'b'] as $key) {
        $created = $bootstrap->create([
            'company_code' => 'circularity4-' . $key . '-' . $fixture['nonce'],
            'company_name' => 'Circularity qualification ' . strtoupper($key), 'branch_name' => 'Main court',
            'first_name' => 'Qualification ' . strtoupper($key),
            'email' => 'circularity4-' . $key . '-' . $fixture['nonce'] . '@example.invalid',
            'password' => $fixture['password'], 'password_confirmation' => $fixture['password'],
        ]);
        $fixture[$key] = ['email' => $created['owner']->email, 'user_id' => $created['owner']->id,
            'company_id' => $created['company']->id, 'branch_id' => $created['branch']->id,
            'member_id' => $created['membership']->id];
    }
    $other = \Vmod\Core\Models\Branch::create(['company_id' => $fixture['a']['company_id'], 'name' => 'Second court']);
    \Vmod\Core\Models\Membership::findOrFail($fixture['a']['member_id'])->branches()->attach($other->id);
    $role = \Vmod\Core\Models\Role::create(['company_id' => $fixture['a']['company_id'], 'name' => 'Qualification staff',
        'permissions' => ['circularity.read', 'circularity.write']]);
    $staff = \Vmod\Core\Models\Membership::create(['company_id' => $fixture['a']['company_id'],
        'user_id' => $fixture['b']['user_id'], 'role_id' => $role->id]);
    $staff->branches()->sync([$fixture['a']['branch_id'], $other->id]);
    $fixture += ['staff_role_id' => $role->id, 'staff_member_id' => $staff->id, 'other_branch_id' => $other->id];
    \System\Models\SiteDefinition::syncPrimarySite();
    $site = \System\Models\SiteDefinition::where('is_primary', true)->firstOrFail();
    $site->theme = 'vmod';
    $site->is_enabled = $site->is_enabled_edit = true;
    $site->is_custom_url = true;
    $site->app_url = $fixture['base_url'];
    $site->save();
    $context = ['tenant_ref' => (string) $fixture['a']['company_id'],
        'branch_refs' => array_map('strval', [$fixture['a']['branch_id'], $other->id]),
        'actor_ref' => (string) $fixture['a']['user_id'], 'permissions' => ['circularity.read', 'circularity.write']];
    $http->replaceHeaders(['X-Circularity-Context' => json_encode($context)]);
    $fixture['today'] = $http->get($api . '/options')->json('today');
    foreach (['main' => $fixture['a']['branch_id'], 'other' => $other->id] as $key => $branch) {
        $response = $http->post($api . '/areas', ['branch_ref' => (string) $branch,
            'name' => ucfirst($key) . ' shared kitchen', 'kind' => 'shared_area']);
        checkCircularity($response->status() === 201, 'Browser area seed failed.');
        $fixture['areas'][$key] = $response->json('area.id');
    }
    foreach ([
        'food' => ['food_waste', '10.125', 'composting'], 'unknown' => ['food_waste', '2.000', 'unknown'],
        'oil' => ['used_cooking_oil', '10.125', 'recycling'], 'oil_unknown' => ['used_cooking_oil', '2.000', 'unknown'],
        'mixed' => ['mixed_waste', '3.250', 'unknown'], 'packaging' => ['packaging_waste', '0', 'recycling'],
        'issued' => ['reusable_issued', '8', 'not_applicable'], 'returned' => ['reusable_returned', '12', 'not_applicable'],
        'other' => ['food_waste', '100', 'unknown'],
    ] as $key => [$type, $quantity, $destination]) {
        $response = $http->post($api . '/entries', ['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'branch_ref' => (string) ($key === 'other' ? $other->id : $fixture['a']['branch_id']),
            'area_id' => $fixture['areas'][$key === 'other' ? 'other' : 'main'], 'measurement_date' => $fixture['today'],
            'measurement_type' => $type, 'mode' => 'daily_total', 'quantity' => $quantity, 'destination' => $destination]);
        checkCircularity($response->status() === 201, 'Browser measurement seed failed.');
        $fixture['entries'][$key] = $response->json('entry.uuid');
    }
    file_put_contents('/proof/scope.json', json_encode(['tenants' => array_map(fn ($key) => (string) $fixture[$key]['company_id'], ['a', 'b'])]));
    file_put_contents('/proof/fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod('/proof/fixture.json', 0600);
    return $fixture;
}

function browserControl(array $fixture, array $command): array
{
    // This CLI connection commits independently of the held provider request. Only synthetic IDs are addressable.
    \DB::transaction(function () use ($fixture, $command) {
        if ($command['action'] === 'permissions') {
            $permissions = ['full' => ['circularity.read', 'circularity.write'], 'read' => ['circularity.read'], 'none' => []];
            checkCircularity(isset($permissions[$command['value']]), 'Invalid proof permissions.');
            $role = \Vmod\Core\Models\Role::where('company_id', $fixture['a']['company_id'])->findOrFail($fixture['staff_role_id']);
            $role->permissions = $permissions[$command['value']];
            $role->save();
        } elseif ($command['action'] === 'branches') {
            $branches = ['all' => [$fixture['a']['branch_id'], $fixture['other_branch_id']], 'first' => [$fixture['a']['branch_id']]];
            checkCircularity(isset($branches[$command['value']]), 'Invalid proof branches.');
            \Vmod\Core\Models\Membership::where('company_id', $fixture['a']['company_id'])
                ->findOrFail($fixture['staff_member_id'])->branches()->sync($branches[$command['value']]);
        } else {
            checkCircularity($command['action'] === 'snapshot', 'Unknown proof control.');
        }
    });
    checkCircularity(\Vmod\Core\Models\Membership::findOrFail($fixture['a']['member_id'])->isFullAdministrator(), 'Proof must preserve its administrator.');
    if (getenv('VMOD_EXTRACTED_PROOF') === 'true') {
        checkCircularity($command['action'] !== 'snapshot', 'Extracted snapshots must come from target CLI.');
        return ['authorized' => true];
    }
    return ['entries' => \Vmod\Circularity\Models\Entry::withTrashed()->where('source_namespace', 'wesria-proof')
        ->whereIn('tenant_ref', array_map(fn ($key) => (string) $fixture[$key]['company_id'], ['a', 'b']))
        ->get(['uuid', 'tenant_ref', 'branch_ref', 'area_id', 'measurement_date', 'measurement_type', 'mode', 'quantity', 'destination', 'version', 'deleted_at'])->toArray()];
}

checkCircularity($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]) === 0, 'Test migrations failed.');
// Let October create its full cache/storage tree inside the API worker's writable parent.
foreach (['ui-storage', 'ui-cache', 'api', 'plugins', 'plugins/vmod', 'barriers', 'controls'] as $directory) {
    $path = '/proof/' . $directory;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
    chmod($path, 0777);
}
file_put_contents('/proof/receipts.jsonl', '');
chmod('/proof/receipts.jsonl', 0666);
foreach (['ui-storage', 'ui-cache'] as $directory) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('/proof/' . $directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $file) {
        chown($file->getPathname(), 'www-data');
    }
}
symlink('/var/www/html/plugins/vmod/circularity', '/proof/plugins/vmod/circularity');
file_put_contents('/proof/fpm.conf', <<<'FPM'
[global]
daemonize = no
error_log = /proof/fpm.log
[www]
user = www-data
group = www-data
listen = 9000
pm = static
pm.max_children = 1
clear_env = no
catch_workers_output = yes
chdir = /var/www/html
[circularity]
user = www-data
group = www-data
listen = 9001
pm = static
pm.max_children = 1
clear_env = no
catch_workers_output = yes
chdir = /var/www/html
env[PLUGINS_PATH] = /proof/plugins
env[STORAGE_PATH] = /proof/api/storage
env[CACHE_PATH] = /proof/api/cache
FPM
);
$process = proc_open(['php-fpm', '-F', '-y', '/proof/fpm.conf'], [
    0 => ['file', '/dev/null', 'r'], 1 => ['file', '/proof/fpm-stdout.log', 'a'], 2 => ['file', '/proof/fpm-stderr.log', 'a'],
], $pipes);
checkCircularity(is_resource($process), 'Could not start test FPM.');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 9001, $errno, $error, 0.1);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    checkCircularity($ready, 'Test FPM did not become ready.');
    $http = \Illuminate\Support\Facades\Http::acceptJson()->timeout(15)->withoutRedirecting()->withOptions(['cookies' => false]);
    $base = 'http://nginx-tech';
    $nested = $http->get($base . '/__nested');
    checkCircularity($nested->status() === 200 && $nested->json('nested') === true, 'Nested FPM HTTP call failed with status ' . $nested->status() . '.');
    echo "PASS: one UI worker calls one API worker through real HTTP.\n";
    $health = $http->get($base . '/api/circularity/v1/health');
    checkCircularity($health->status() === 401, 'Missing credential was not denied.');
    $http->withToken('invalid-proof-credential');
    checkCircularity($http->get($base . '/api/circularity/v1/health')->status() === 401, 'Invalid credential was not denied.');
    $http->withToken(getenv('CIRCULARITY_API_TOKEN'));
    $health = $http->get($base . '/api/circularity/v1/health');
    checkCircularity($health->status() === 200 && $health->json('api_version') === 'v1', 'Authenticated health failed.');
    checkCircularity(!$health->header('Set-Cookie'), 'API unexpectedly created a browser session.');
    $isolated = $http->get($base . '/api/circularity/v1/__isolation');
    checkCircularity($isolated->status() === 200 && $isolated->json('isolated') === true, 'Provider imports or loads another plugin.');
    echo "PASS: provider-only boot, service authentication, and stateless health.\n";
    $scopeUrl = $base . '/api/circularity/v1/__scope/court-a';
    checkCircularity($http->get($scopeUrl)->status() === 422, 'Missing context was accepted.');
    $http->withHeaders(['X-Circularity-Context' => json_encode([
        'tenant_ref' => 'company-a', 'branch_refs' => ['court-a'], 'actor_ref' => 'user-a',
        'permissions' => ['circularity.read'], 'source_namespace' => 'forged',
    ])]);
    $scoped = $http->get($scopeUrl);
    checkCircularity($scoped->status() === 200 && $scoped->json('scope.source_namespace') === 'wesria-proof', 'Trusted namespace or scoped context failed.');
    checkCircularity($http->get($base . '/api/circularity/v1/__scope/foreign')->status() === 404, 'Foreign branch was not hidden.');
    echo "PASS: scoped context and foreign-branch denial over HTTP.\n";
    $context = ['tenant_ref' => 'http-' . bin2hex(random_bytes(8)), 'branch_refs' => ['court-a'],
        'actor_ref' => 'frontend-user-http', 'permissions' => ['circularity.read', 'circularity.write']];
    $http->replaceHeaders(['X-Circularity-Context' => json_encode($context)]);
    $api = $base . '/api/circularity/v1';
    checkCircularity($http->get($api . '/options')->json('reporting_timezone') === 'Asia/Kuala_Lumpur', 'Options failed.');
    $areaResponse = $http->post($api . '/areas', ['branch_ref' => 'court-a', 'name' => 'HTTP shared area', 'kind' => 'shared_area']);
    checkCircularity($areaResponse->status() === 201, 'HTTP area creation failed: ' . $areaResponse->status());
    $area = $areaResponse->json('area');
    $payload = ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'branch_ref' => 'court-a', 'area_id' => $area['id'],
        'measurement_date' => '2026-01-01', 'measurement_type' => 'mixed_waste', 'mode' => 'daily_total',
        'quantity' => '0', 'destination' => 'unknown', 'created_actor_ref' => 'forged'];
    $invalid = $http->post($api . '/entries', array_replace($payload, ['quantity' => '1e3']));
    checkCircularity(
        $invalid->status() === 422 && is_array($invalid->json('errors.quantity')),
        'HTTP quantity validation format failed: ' . $invalid->status() . ' ' . json_encode($invalid->json())
    );
    $created = $http->post($api . '/entries', $payload);
    checkCircularity($created->status() === 201 && $created->json('entry.quantity') === '0.000'
        && $created->json('entry.created_actor_ref') === 'frontend-user-http', 'HTTP entry creation/actor failed.');
    $uuid = $payload['uuid'];
    checkCircularity($http->post($api . '/entries', $payload)->status() === 200, 'HTTP matching replay failed.');
    $updated = $http->patch($api . '/entries/' . $uuid, ['version' => 1, 'quantity' => '2.125']);
    checkCircularity($updated->status() === 200 && $updated->json('entry.version') === 2, 'HTTP edit failed.');
    checkCircularity($http->patch($api . '/entries/' . $uuid, ['version' => 1, 'quantity' => '3'])->status() === 409, 'HTTP stale edit was accepted.');
    checkCircularity($http->get($api . '/entries', ['area_id' => $area['id']])->json('total') === 1, 'HTTP scoped list failed.');
    $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'area_id' => $area['id']];
    foreach ([
        ['food_waste', '10.125', 'composting'], ['food_waste', '2.000', 'unknown'],
        ['used_cooking_oil', '10.125', 'recycling'], ['used_cooking_oil', '2.000', 'unknown'],
        ['reusable_issued', '8', 'not_applicable'], ['reusable_returned', '12', 'not_applicable'],
    ] as [$type, $quantity, $destination]) {
        checkCircularity($http->post($api . '/entries', array_replace($payload, [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'measurement_type' => $type,
            'quantity' => $quantity, 'destination' => $destination,
        ]))->status() === 201, 'HTTP summary fixture creation failed.');
    }
    $summary = $http->get($api . '/summary', $range);
    checkCircularity($summary->status() === 200 && $summary->json('totals.food_waste.quantity') === '12.125'
        && $summary->json('totals.used_cooking_oil.quantity') === '12.125'
        && $summary->json('totals.mixed_waste.quantity') === '2.125'
        && $summary->json('totals.packaging_waste.quantity') === null
        && $summary->json('totals.reusable_issued.quantity') === '8'
        && $summary->json('totals.reusable_returned.quantity') === '12', 'HTTP summary reconciliation failed.');
    foreach (['area' => (string) $area['id'], 'date' => '2026-01-01', 'branch' => 'court-a'] as $groupBy => $key) {
        $grouped = $http->get($api . '/summary', $range + ['group_by' => $groupBy]);
        checkCircularity($grouped->status() === 200 && $grouped->json('group_by') === $groupBy
            && $grouped->json('groups') === [['key' => $key, 'totals' => $summary->json('totals')]]
            && $grouped->json('filters') === $summary->json('filters')
            && $grouped->json('totals') === $summary->json('totals'), 'HTTP grouped summary failed: ' . $groupBy);
    }
    checkCircularity(
        $http->get($api . '/summary', $range + ['group_by' => 'unsupported'])->status() === 422,
        'HTTP invalid grouping accepted.'
    );
    echo "PASS: optional area/date/branch grouping reconciles to the legacy summary over HTTP.\n";
    checkCircularity($http->get($api . '/summary', $range + ['destination' => 'composting'])
        ->json('totals.reusable_returned.quantity') === null, 'HTTP destination filter did not apply uniformly.');
    checkCircularity($http->get($api . '/summary', ['date_from' => '2026-01-01'])->status() === 422, 'HTTP incomplete range accepted.');
    checkCircularity($http->delete($api . '/entries/' . $uuid, ['version' => 2])->json('entry.status') === 'removed', 'HTTP removal failed.');
    checkCircularity($http->get($api . '/entries/' . $uuid)->json('entry.status') === 'removed', 'HTTP removed lookup failed.');
    checkCircularity($http->get($api . '/summary', $range)->json('totals.mixed_waste.quantity') === null, 'HTTP removed entry still contributes.');
    checkCircularity($http->post($api . '/entries', $payload)->status() === 409, 'HTTP removed UUID resurrected.');
    checkCircularity($http->patch($api . '/areas/' . $area['id'], ['version' => 1, 'is_active' => false])->status() === 200, 'HTTP area update failed.');
    $http->replaceHeaders(['X-Circularity-Context' => json_encode(array_replace($context, ['tenant_ref' => 'other-tenant']))]);
    checkCircularity($http->get($api . '/entries/' . $uuid)->status() === 404, 'HTTP removed record leaked across tenants.');
    checkCircularity($http->get($api . '/summary', $range)->status() === 404, 'HTTP summary area leaked across tenants.');
    echo "PASS: provider-only summary, exact totals, uniform destination filters, range validation and next-read removal.\n";
    echo "PASS: provider-only HTTP options, area CRUD, measurement CRUD, exact zero, replay, stale writes and removed-record isolation.\n";
    if (getenv('VMOD_BROWSER_PROOF') === 'true') {
        $fixture = browserFixture($http, $api);
        if (getenv('VMOD_EXTRACTED_PROOF') === 'true') {
            passthru('php tests/vmod/check-circularity-extraction.php export', $status);
            checkCircularity($status === 0, 'Extraction export failed.');
            file_put_contents('/proof/source-ready', 'ready');
            $deadline = microtime(true) + 180;
            while (!is_file('/proof/extracted-ready')) {
                checkCircularity(microtime(true) < $deadline, 'Target import did not finish.');
                clearstatcache();
                usleep(50000);
            }
        }
        file_put_contents('/proof/ready', 'ready');
        echo "READY: native browser origin " . $fixture['base_url'] . "; private fixture in proof directory.\n";
        $deadline = microtime(true) + 7200;
        while (!is_file('/proof/complete')) {
            checkCircularity(microtime(true) < $deadline, 'Browser qualification did not finish within two hours.');
            foreach (glob('/proof/controls/*.request.json') as $path) {
                $command = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $result = browserControl($fixture, $command);
                file_put_contents(str_replace('.request.json', '.response.json', $path), json_encode($result, JSON_THROW_ON_ERROR));
                unlink($path);
            }
            clearstatcache();
            usleep(50000);
        }
        checkCircularity(trim(file_get_contents('/proof/complete')) === 'pass', 'Browser qualification failed.');
        if (getenv('VMOD_EXTRACTED_PROOF') === 'true') {
            passthru('php tests/vmod/check-circularity-extraction.php source-check', $status);
            checkCircularity($status === 0, 'Source preservation failed.');
        }
        echo "PASS: browser qualification completed.\n";
    }
} finally {
    proc_terminate($process, 3); // Native SIGQUIT: drain the test workers.
    proc_close($process);
}

$GLOBALS['circularityProofCompleted'] = true;
