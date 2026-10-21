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
    if ($job['operation'] === 'remove') {
        return (new \Vmod\Circularity\Classes\EntryService)->remove($job['context'], $job['uuid'], $job['input']);
    }
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

function reconcile(array $context, $area, array $expected, ?string $quantity): void
{
    $rows = \Vmod\Circularity\Models\Entry::withTrashed()->forContext($context)->where('area_id', $area->id)->get();
    $actual = [];
    foreach ($rows as $row) {
        $actual[$row->uuid] = [$row->version, $row->quantity, $row->mode, (bool) $row->deleted_at, $row->destination];
        check($row->branch_ref === 'court' && $row->getRawOriginal('measurement_date') === '2026-01-01'
            && $row->measurement_type === 'mixed_waste', 'Entry group changed unexpectedly.');
    }
    ksort($actual);
    ksort($expected);
    check($rows->count() === count($expected), 'Physical entry count does not reconcile.');
    check($actual === $expected, 'Persisted identity/version/value mismatch in area ' . $area->name);
    $request = \Illuminate\Http\Request::create('/', 'GET', ['area_id' => $area->id,
        'date_from' => '2026-01-01', 'date_to' => '2026-01-01']);
    $request->attributes->set('circularity.context', $context);
    $summary = (new \Vmod\Circularity\Classes\CaptureApi)->summary($request)->getData(true)['totals'];
    $active = count(array_filter($expected, fn ($row) => !$row[3]));
    check(
        $summary['mixed_waste']['quantity'] === $quantity && $summary['mixed_waste']['entry_count'] === $active,
        'Filtered summary does not reconcile in area ' . $area->name
    );
    // Each scenario intentionally has one destination per area; cross-destination arithmetic is covered by provider tests.
    $destinations = array_values(array_unique(array_map(fn ($row) => $row[4], array_filter($expected, fn ($row) => !$row[3]))));
    $breakdown = $active ? [['destination' => $destinations[0], 'quantity' => $quantity, 'entry_count' => $active]] : [];
    check($summary['mixed_waste']['destinations'] === $breakdown, 'Destination values do not reconcile.');
    foreach (['landfill', 'composting'] as $destination) {
        $request->query->set('destination', $destination);
        $filtered = (new \Vmod\Circularity\Classes\CaptureApi)->summary($request)->getData(true)['totals']['mixed_waste'];
        $matches = in_array($destination, $destinations, true);
        check($filtered['quantity'] === ($matches ? $quantity : null)
            && $filtered['entry_count'] === ($matches ? $active : 0), 'Destination-filtered summary mismatch.');
    }
    foreach (array_diff(array_keys($summary), ['mixed_waste']) as $type) {
        check($summary[$type]['quantity'] === null && $summary[$type]['entry_count'] === 0, 'Mixed waste leaked into another metric.');
    }
}

try {
    $password = stream_get_contents(STDIN);
    check($password !== '', 'Provide the owned observer credential privately on stdin.');
    $observer = new PDO('mysql:host=mysql-test;dbname=performance_schema', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password);
    check($kernel->call('october:migrate', ['--no-interaction' => true]) === 0, 'Migration failed.');
    $context = ['source_namespace' => 'race', 'tenant_ref' => bin2hex(random_bytes(8)), 'branch_refs' => ['court'], 'actor_ref' => 'test-user'];
    $areas = [];
    foreach (range('A', 'Q') as $name) {
        $area = new \Vmod\Circularity\Models\Area;
        $area->source_namespace = $context['source_namespace'];
        $area->tenant_ref = $context['tenant_ref'];
        $area->branch_ref = 'court';
        $area->fill(['name' => $name, 'kind' => 'stall', 'is_active' => true]);
        $area->save();
        $areas[] = $area;
    }
    [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j, $k, $l, $m, $n, $o, $p, $q] = $areas;
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
    reconcile($context, $a, [$first['uuid'] => [1, '1.000', 'daily_total', false, 'landfill']], '1.000');
    $mode = measurement($b);
    forceWait(
        $observer,
        'competing modes',
        'vmod_circularity_areas',
        fn () => $service->save($context, $mode),
        $job + ['input' => measurement($b, ['mode' => 'individual', 'destination' => 'composting'])],
        409,
        [$b->id]
    );
    reconcile($context, $b, [$mode['uuid'] => [1, '1.000', 'daily_total', false, 'landfill']], '1.000');
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
    reconcile($context, $c, [$collision['uuid'] => [1, '1.000', 'daily_total', false, 'landfill']], '1.000');
    reconcile($context, $d, [], null);
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
    reconcile($context, $d, [$replay['uuid'] => [1, '1.000', 'daily_total', false, 'landfill']], '1.000');
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
    reconcile($context, $e, [$right->uuid => [2, '1.000', 'individual', false, 'landfill']], '1.000');
    reconcile($context, $f, [$left->uuid => [2, '1.000', 'individual', false, 'landfill']], '1.000');
    forceWait(
        $observer,
        'deactivation before creation',
        'vmod_circularity_areas',
        fn () => operation(['context' => $context, 'operation' => 'area', 'id' => $g->id, 'input' => ['version' => 1, 'is_active' => false]]),
        $job + ['input' => measurement($g)],
        422,
        [$g->id]
    );
    reconcile($context, $g, [], null);
    $beforeDeactivation = measurement($h);
    forceWait(
        $observer,
        'creation before deactivation',
        'vmod_circularity_areas',
        fn () => $service->save($context, $beforeDeactivation),
        ['context' => $context, 'operation' => 'area', 'id' => $h->id, 'input' => ['version' => 1, 'is_active' => false]],
        200,
        [$h->id]
    );
    check(\Vmod\Circularity\Models\Entry::forContext($context)->where('area_id', $h->id)->count() === 1 && !$h->fresh()->is_active, 'Deactivation lost captured history.');
    check(\Vmod\Circularity\Models\Entry::forContext($context)->where('area_id', $g->id)->count() === 0, 'Inactive area accepted creation.');
    check(\Vmod\Circularity\Models\Entry::where('uuid', $collision['uuid'])->count() === 1, 'UUID collision created duplicates.');
    reconcile($context, $h, [$beforeDeactivation['uuid'] => [1, '1.000', 'daily_total', false, 'landfill']], '1.000');
    check($g->fresh()->version === 2 && $h->fresh()->version === 2, 'Area versions did not advance once.');

    $individual = measurement($i, ['mode' => 'individual']);
    forceWait(
        $observer,
        'individual first rejects daily',
        'vmod_circularity_areas',
        fn () => $service->save($context, $individual),
        $job + ['input' => measurement($i)],
        409,
        [$i->id]
    );
    reconcile($context, $i, [$individual['uuid'] => [1, '1.000', 'individual', false, 'landfill']], '1.000');

    $one = measurement($j, ['mode' => 'individual', 'quantity' => '1.125']);
    $two = measurement($j, ['mode' => 'individual', 'quantity' => '2.000']);
    forceWait(
        $observer,
        'independent individual readings',
        'vmod_circularity_areas',
        fn () => $service->save($context, $one),
        $job + ['input' => $two],
        200,
        [$j->id]
    );
    reconcile($context, $j, [$one['uuid'] => [1, '1.125', 'individual', false, 'landfill'],
        $two['uuid'] => [1, '2.000', 'individual', false, 'landfill']], '3.125');

    $edited = $service->save($context, measurement($k));
    forceWait(
        $observer,
        'edit rejects stale edit',
        'vmod_circularity_areas',
        fn () => $service->save($context, ['version' => 1, 'quantity' => '2.125'], $edited->uuid),
        $job + ['uuid' => $edited->uuid, 'input' => ['version' => 1, 'quantity' => '9']],
        409,
        [$k->id]
    );
    reconcile($context, $k, [$edited->uuid => [2, '2.125', 'daily_total', false, 'landfill']], '2.125');

    $kept = $service->save($context, measurement($l));
    forceWait(
        $observer,
        'edit rejects stale removal',
        'vmod_circularity_areas',
        fn () => $service->save($context, ['version' => 1, 'quantity' => '2.125'], $kept->uuid),
        ['context' => $context, 'operation' => 'remove', 'uuid' => $kept->uuid, 'input' => ['version' => 1]],
        409,
        [$l->id]
    );
    reconcile($context, $l, [$kept->uuid => [2, '2.125', 'daily_total', false, 'landfill']], '2.125');

    $removed = $service->save($context, measurement($m));
    forceWait(
        $observer,
        'removal rejects stale edit',
        'vmod_circularity_areas',
        fn () => $service->remove($context, $removed->uuid, ['version' => 1]),
        $job + ['uuid' => $removed->uuid, 'input' => ['version' => 1, 'quantity' => '9']],
        409,
        [$m->id]
    );
    reconcile($context, $m, [$removed->uuid => [2, '1.000', 'daily_total', true, 'landfill']], null);

    $original = measurement($n);
    $service->save($context, $original);
    forceWait(
        $observer,
        'removal rejects original UUID replay',
        'vmod_circularity_areas',
        fn () => $service->remove($context, $original['uuid'], ['version' => 1]),
        $job + ['input' => $original],
        409,
        [$n->id]
    );
    reconcile($context, $n, [$original['uuid'] => [2, '1.000', 'daily_total', true, 'landfill']], null);

    $last = $service->save($context, measurement($o));
    $fresh = measurement($o, ['mode' => 'individual', 'quantity' => '3.125']);
    forceWait(
        $observer,
        'last removal permits a new mode',
        'vmod_circularity_areas',
        fn () => $service->remove($context, $last->uuid, ['version' => 1]),
        $job + ['input' => $fresh],
        200,
        [$o->id]
    );
    reconcile($context, $o, [$last->uuid => [2, '1.000', 'daily_total', true, 'landfill'],
        $fresh['uuid'] => [1, '3.125', 'individual', false, 'landfill']], '3.125');

    $source = $service->save($context, measurement($p, ['mode' => 'individual', 'quantity' => '1.125']));
    $blocker = measurement($q, ['quantity' => '2.000', 'destination' => 'composting']);
    forceWait(
        $observer,
        'target conflict rejects move atomically',
        'vmod_circularity_areas',
        fn () => $service->save($context, $blocker),
        $job + ['uuid' => $source->uuid, 'input' => ['version' => 1, 'area_id' => $q->id]],
        409,
        [$p->id, $q->id]
    );
    reconcile($context, $p, [$source->uuid => [1, '1.125', 'individual', false, 'landfill']], '1.125');
    reconcile($context, $q, [$blocker['uuid'] => [1, '2.000', 'daily_total', false, 'composting']], '2.000');
    echo "PASS: 15 observed races; physical/active identities, versions, modes, quantities and summaries reconciled.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
