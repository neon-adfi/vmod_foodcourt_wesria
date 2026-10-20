<?php

// phpcs:disable PSR1.Files.SideEffects -- Guarded standalone MySQL check.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
foreach (['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_PATH' => '/tmp/circularity-race-cache', 'STORAGE_PATH' => '/tmp/circularity-race-storage'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$connection = config('database.connections.' . config('database.default'));
$connection['environment'] = $app->environment();
$connection['owner'] = getenv('VMOD_TEST_DATABASE_OWNER');
$connection['config_cache'] = $app->getCachedConfigPath();
\Vmod\Tests\DatabaseGuard::assertSafe($connection, $app->configurationIsCached());
\System\Classes\PluginManager::instance()->registerAll(true);
\System\Classes\PluginManager::instance()->bootAll(true);

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operation(array $job)
{
    if ($job['operation'] === 'area') {
        $request = \Illuminate\Http\Request::create('/', 'PATCH', $job['input']);
        $request->attributes->set('circularity.context', $job['context']);
        return (new \Vmod\Circularity\Classes\CaptureApi)->saveArea($request, (string) $job['id']);
    }
    return (new \Vmod\Circularity\Classes\EntryService)->save($job['context'], $job['input'], $job['uuid'] ?? null);
}

if (($argv[1] ?? '') === '--worker') {
    $locks = [];
    try {
        $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
        file_put_contents($job['ready'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::listen(function ($query) use (&$locks) {
            if (str_contains($query->sql, 'from `vmod_circularity_areas`') && str_contains($query->sql, 'for update')) {
                $locks[] = (int) end($query->bindings);
            }
        });
        operation($job);
        $status = 200;
    } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
        $status = $exception->getResponse()->getStatusCode();
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $status = 422;
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        $status = $exception->getStatusCode();
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage());
        exit(1);
    }
    echo json_encode(['status' => $status, 'locks' => $locks]);
    exit(0);
}

function forceWait(PDO $observer, string $name, string $table, callable $holder, array $job, int $expected, array $expectedLocks): void
{
    $ready = tempnam(sys_get_temp_dir(), 'circularity-wait-');
    $process = null;
    try {
        DB::beginTransaction();
        $holder();
        $holderId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Cannot start worker.');
        fwrite($pipes[0], json_encode($job + ['ready' => $ready], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $query = $observer->prepare('SELECT requested.LOCK_STATUS FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
            JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
            JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = w.ENGINE
            WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ?');
        $observed = false;
        $deadline = microtime(true) + 15;
        while (!$observed && microtime(true) < $deadline) {
            $waiterId = (int) file_get_contents($ready);
            if ($waiterId) {
                $query->execute([$waiterId, $holderId, 'vmod_wesria_test', $table]);
                $observed = $query->fetchColumn() === 'WAITING';
            }
            if (!$observed) {
                usleep(10000);
            }
        }
        check($observed, $name . ': exact MySQL wait not observed.');
        DB::commit();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        check($exit === 0 && $error === '', $name . ': worker failed: ' . $error);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        check($result['status'] === $expected, $name . ': unexpected status ' . $result['status']);
        check($result['locks'] === $expectedLocks, $name . ': incorrect area lock order ' . json_encode($result['locks']));
        echo 'PASS: ' . $name . ' observed WAITING on ' . $table . '; status ' . $expected . "; ordered area locks.\n";
    } finally {
        while (DB::transactionLevel()) {
            DB::rollBack();
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($ready);
    }
}

function measurement($area, array $extra = []): array
{
    return array_replace(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'branch_ref' => 'court',
        'area_id' => $area->id, 'measurement_date' => '2026-01-01', 'measurement_type' => 'mixed_waste',
        'mode' => 'daily_total', 'quantity' => '1', 'destination' => 'landfill'], $extra);
}

try {
    $password = stream_get_contents(STDIN);
    check($password !== '', 'Provide the owned observer credential privately on stdin.');
    $observer = new PDO('mysql:host=mysql-test;dbname=performance_schema', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password);
    check($kernel->call('october:migrate', ['--no-interaction' => true]) === 0, 'Migration failed.');
    $context = ['source_namespace' => 'race', 'tenant_ref' => bin2hex(random_bytes(8)), 'branch_refs' => ['court'], 'actor_ref' => 'test-user'];
    $areas = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $name) {
        $area = new \Vmod\Circularity\Models\Area;
        $area->source_namespace = $context['source_namespace'];
        $area->tenant_ref = $context['tenant_ref'];
        $area->branch_ref = 'court';
        $area->fill(['name' => $name, 'kind' => 'stall', 'is_active' => true]);
        $area->save();
        $areas[] = $area;
    }
    [$a, $b, $c, $d, $e, $f, $g, $h] = $areas;
    $service = new \Vmod\Circularity\Classes\EntryService;
    $job = ['context' => $context, 'operation' => 'entry'];
    $first = measurement($a);
    forceWait(
        $observer,
        'daily duplicate',
        'vmod_circularity_areas',
        fn () => $service->save($context, $first),
        $job + ['input' => measurement($a)],
        409,
        [$a->id]
    );
    forceWait(
        $observer,
        'competing modes',
        'vmod_circularity_areas',
        fn () => $service->save($context, measurement($b)),
        $job + ['input' => measurement($b, ['mode' => 'individual', 'destination' => 'composting'])],
        409,
        [$b->id]
    );
    $collision = measurement($c);
    forceWait(
        $observer,
        'UUID across areas',
        'vmod_circularity_entries',
        fn () => $service->save($context, $collision),
        $job + ['input' => array_replace($collision, ['area_id' => $d->id])],
        409,
        [$d->id, $c->id, $d->id]
    );
    $replay = measurement($d);
    forceWait(
        $observer,
        'matching UUID replay',
        'vmod_circularity_areas',
        fn () => $service->save($context, $replay),
        $job + ['input' => $replay],
        200,
        [$d->id, $d->id]
    );
    $left = $service->save($context, measurement($e, ['mode' => 'individual']));
    $right = $service->save($context, measurement($f, ['mode' => 'individual']));
    forceWait(
        $observer,
        'opposite area moves',
        'vmod_circularity_areas',
        fn () => $service->save($context, ['area_id' => $f->id, 'version' => 1], $left->uuid),
        $job + ['uuid' => $right->uuid, 'input' => ['area_id' => $e->id, 'version' => 1]],
        200,
        [$e->id, $f->id]
    );
    check($left->fresh()->area_id === $f->id && $right->fresh()->area_id === $e->id, 'Moves did not preserve both entries.');
    forceWait(
        $observer,
        'deactivation before creation',
        'vmod_circularity_areas',
        fn () => operation(['context' => $context, 'operation' => 'area', 'id' => $g->id, 'input' => ['version' => 1, 'is_active' => false]]),
        $job + ['input' => measurement($g)],
        422,
        [$g->id]
    );
    forceWait(
        $observer,
        'creation before deactivation',
        'vmod_circularity_areas',
        fn () => $service->save($context, measurement($h)),
        ['context' => $context, 'operation' => 'area', 'id' => $h->id, 'input' => ['version' => 1, 'is_active' => false]],
        200,
        [$h->id]
    );
    check(\Vmod\Circularity\Models\Entry::forContext($context)->where('area_id', $h->id)->count() === 1 && !$h->fresh()->is_active, 'Deactivation lost captured history.');
    check(\Vmod\Circularity\Models\Entry::forContext($context)->where('area_id', $g->id)->count() === 0, 'Inactive area accepted creation.');
    check(\Vmod\Circularity\Models\Entry::where('uuid', $collision['uuid'])->count() === 1, 'UUID collision created duplicates.');
    echo "PASS: persisted modes, UUID identities, area moves and inactive history reconciled.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
