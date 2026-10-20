<?php

// phpcs:disable PSR1.Files.SideEffects -- Guarded standalone HTTP runner and test front controller.
// Test-only front controller and runner. Explicit guard precedes any application boot or writes.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
if (getenv('PLUGINS_PATH') === '/proof/plugins') {
    // PLUGINS_PATH changes discovery, but October's default autoloader still searches "plugins".
    // Replace that fallback too, so unrelated plugins are genuinely unavailable in this worker.
    $loader = \October\Rain\Composer\ClassLoader::instance();
    $loader->removeDirectories('plugins');
    $loader->withDirectories('../../../proof/plugins');
}
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.connections.' . config('database.default'));
$connection['environment'] = $app->environment();
$connection['owner'] = getenv('VMOD_TEST_DATABASE_OWNER');
$connection['config_cache'] = $app->getCachedConfigPath();
\Vmod\Tests\DatabaseGuard::assertSafe($connection, $app->configurationIsCached());
$plugins = \System\Classes\PluginManager::instance();
$plugins->registerAll(true);
$plugins->bootAll(true);

if (PHP_SAPI === 'fpm-fcgi') {
    $request = \Illuminate\Http\Request::capture();
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
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
    return;
}

function checkCircularity($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

checkCircularity($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]) === 0, 'Test migrations failed.');
// Let October create its full cache/storage tree inside the API worker's writable parent.
foreach (['ui-storage', 'ui-cache', 'api', 'plugins', 'plugins/vmod'] as $directory) {
    $path = '/proof/' . $directory;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
    chmod($path, 0777);
}
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
        ['reusable_issued', '8', 'not_applicable'], ['reusable_returned', '12', 'not_applicable'],
    ] as [$type, $quantity, $destination]) {
        checkCircularity($http->post($api . '/entries', array_replace($payload, [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'measurement_type' => $type,
            'quantity' => $quantity, 'destination' => $destination,
        ]))->status() === 201, 'HTTP summary fixture creation failed.');
    }
    $summary = $http->get($api . '/summary', $range);
    checkCircularity($summary->status() === 200 && $summary->json('totals.food_waste.quantity') === '12.125'
        && $summary->json('totals.mixed_waste.quantity') === '2.125'
        && $summary->json('totals.packaging_waste.quantity') === null
        && $summary->json('totals.reusable_issued.quantity') === '8'
        && $summary->json('totals.reusable_returned.quantity') === '12', 'HTTP summary reconciliation failed.');
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
} finally {
    proc_terminate($process, 3); // Native SIGQUIT: drain the test workers.
    proc_close($process);
}
