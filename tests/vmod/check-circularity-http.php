<?php

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
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/proof/' . $directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST) as $file) {
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
} finally {
    proc_terminate($process, 3); // Native SIGQUIT: drain the test workers.
    proc_close($process);
}
