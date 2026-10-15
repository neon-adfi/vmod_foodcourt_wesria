<?php

// phpcs:disable PSR1.Files.SideEffects -- Standalone guarded CLI acceptance check with local helper functions.
// Owned MySQL only. Root credential arrives privately on stdin for read-only lock observation; app writes use the guarded schema user.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
foreach (['CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_PATH' => '/tmp/wesria-erp-cache', 'STORAGE_PATH' => '/tmp/wesria-erp-storage'] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
\System\Classes\PluginManager::instance()->registerAll(true);
\System\Classes\PluginManager::instance()->bootAll(true);
(new \Vmod\Core\Services\BootstrapService)->assertOwnedEnvironment();

function check($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function acting(int $userId, int $companyId): void
{
    Auth::logoutQuietly();
    Auth::login(\Vmod\Core\Models\User::findOrFail($userId));
    \Vmod\Core\Classes\Workspace::clear();
    \Vmod\Core\Classes\Workspace::switchTo($companyId);
    Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
}

function company(): array
{
    $nonce = bin2hex(random_bytes(8));
    return (new \Vmod\Core\Services\BootstrapService)->create([
        'company_code' => 'erp-race-' . $nonce, 'company_name' => 'ERP race ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
        'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
    ]);
}

function operation(string $operation, array $input): void
{
    $catalogue = new \Vmod\Erp\Services\CatalogueService;
    switch ($operation) {
        case 'company':
            (new \Vmod\Core\Services\AdministrationService)->saveCompany($input);
            break;
        case 'package':
            $catalogue->savePackage($input);
            break;
        case 'product':
            $catalogue->saveProduct($input);
            break;
        default:
            throw new RuntimeException('Unknown test operation.');
    }
}

if (($argv[1] ?? '') === '--worker') {
    $firstRead = null;
    try {
        $job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        acting($job['user_id'], $job['company_id']);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
        file_put_contents($job['ready'], (string) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::listen(function ($query) use (&$firstRead) {
            if ($firstRead === null && DB::transactionLevel() > 0 && str_starts_with(strtolower($query->sql), 'select')) {
                $firstRead = $query->sql;
            }
        });
        operation($job['operation'], $job['input']);
        $status = 200;
    } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
        $status = $exception->getStatusCode();
    } catch (Throwable $exception) {
        fwrite(STDERR, get_class($exception));
        exit(1);
    }
    echo json_encode(['status' => $status, 'company_lock_first' => str_contains($firstRead ?? '', 'vmod_core_companies')
        && (str_contains($firstRead, 'lock in share mode') || str_contains($firstRead, 'for update'))]);
    exit(0);
}

function forceWait(PDO $observer, string $name, string $table, callable $holder, array $job, int $expected): void
{
    $ready = tempnam(sys_get_temp_dir(), 'wesria-erp-wait-');
    $process = null;
    $pipes = [];
    try {
        DB::beginTransaction();
        $holder();
        $holderId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Cannot start independent waiter.');
        fwrite($pipes[0], json_encode($job + ['ready' => $ready], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        $observed = false;
        $query = $observer->prepare('SELECT requested.OBJECT_NAME, requested.LOCK_STATUS
            FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
            JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID
            JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = w.ENGINE
            WHERE r.PROCESSLIST_ID = ? AND b.PROCESSLIST_ID = ? AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ?');
        while (!$observed && microtime(true) < $deadline) {
            $waiterId = (int) file_get_contents($ready);
            if ($waiterId) {
                $query->execute([$waiterId, $holderId, 'vmod_wesria_test', $table]);
                $lock = $query->fetch(PDO::FETCH_ASSOC);
                $observed = $lock && $lock['LOCK_STATUS'] === 'WAITING';
            }
            if (!$observed) {
                usleep(10000);
            }
        }
        check($observed, $name . ': required database lock wait was not observed.');
        DB::commit(); // Release only after MySQL proves this exact waiter is blocked by this holder on the required table.
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        check($exit === 0 && $error === '', $name . ': worker failed (private output withheld).');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        check($result['status'] === $expected && $result['company_lock_first'], $name . ': unexpected result or transactional read order.');
        echo $name . ': observed WAITING on ' . $table . '; waiter HTTP ' . $expected . "; company lock first.\n";
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

try {
    $password = stream_get_contents(STDIN);
    check($password !== '', 'Pass the owned mysql-test observer credential privately on stdin.');
    $observer = new PDO('mysql:host=mysql-test;dbname=performance_schema', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password);
    check($kernel->call('october:migrate', ['--no-interaction' => true]) === 0, 'Migration failed.');
    $service = new \Vmod\Erp\Services\CatalogueService;
    $admin = new \Vmod\Core\Services\AdministrationService;
    $one = company();
    acting($one['owner']->id, $one['company']->id);
    $product = $service->saveProduct(['sku' => 'RACE', 'name' => 'Race', 'base_uom' => 'kg']);
    $job = ['company_id' => $one['company']->id, 'user_id' => $one['owner']->id];
    $packageInput = ['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'PACK', 'name' => 'Pack', 'conversion' => '2', 'sales_price' => '1', 'expected_currency' => 'MYR'];
    forceWait(
        $observer,
        'Currency change before first price',
        'vmod_core_companies',
        fn () => $admin->saveCompany(['name' => 'Changed', 'currency' => 'SGD', 'expected_currency' => 'MYR']),
        $job + ['operation' => 'package', 'input' => $packageInput],
        409
    );
    check(!\Vmod\Erp\Models\ProductPackage::where('company_id', $one['company']->id)->exists(), 'Stale currency inserted a price.');
    check($one['company']->fresh()->currency === 'SGD' && !$one['company']->fresh()->currency_locked_at, 'Unexpected currency/lock.');
    $packageInput['expected_currency'] = 'SGD';
    forceWait(
        $observer,
        'First price before currency change',
        'vmod_core_companies',
        fn () => $service->savePackage($packageInput),
        $job + ['operation' => 'company', 'input' => ['name' => 'Denied', 'currency' => 'MYR', 'expected_currency' => 'SGD']],
        409
    );
    check($one['company']->fresh()->currency === 'SGD' && $one['company']->fresh()->currency_locked_at, 'First-price currency lock lost.');

    $product = $service->saveProduct(['sku' => 'UNIT', 'name' => 'Unit', 'base_uom' => 'kg']);
    $edit = ['id' => $product->id, 'version' => $product->version, 'sku' => 'UNIT', 'name' => 'Unit', 'base_uom' => 'g'];
    forceWait(
        $observer,
        'First unpriced package before base-UOM edit',
        'vmod_erp_products',
        fn () => $service->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '1', 'sales_price' => null]),
        $job + ['operation' => 'product', 'input' => $edit],
        409
    );
    check($product->fresh()->base_uom === 'kg', 'Package did not lock base UOM after a stale snapshot.');
    $edit['base_uom'] = 'kg';
    forceWait(
        $observer,
        'Concurrent stale record edit',
        'vmod_erp_products',
        fn () => $service->saveProduct(array_replace($edit, ['name' => 'Winner'])),
        $job + ['operation' => 'product', 'input' => array_replace($edit, ['name' => 'Loser'])],
        409
    );
    check($product->fresh()->name === 'Winner' && (int) $product->fresh()->version === 2, 'Stale edit changed the winner.');

    $product = $service->saveProduct(['sku' => 'UNIT-FIRST', 'name' => 'Unit first', 'base_uom' => 'kg']);
    forceWait(
        $observer,
        'Base-UOM edit before first package',
        'vmod_erp_products',
        fn () => $service->saveProduct(['id' => $product->id, 'version' => $product->version, 'sku' => 'UNIT-FIRST', 'name' => 'Unit first', 'base_uom' => 'g']),
        $job + ['operation' => 'package', 'input' => ['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'STALE', 'name' => 'Stale pack', 'conversion' => '2']],
        409
    );
    check($product->fresh()->base_uom === 'g' && !\Vmod\Erp\Models\ProductPackage::where('product_id', $product->id)->exists(), 'Stale pack-size semantics were accepted.');

    $two = company();
    $role = $admin->saveRole(['name' => 'Writer', 'permissions' => ['catalogue.read', 'catalogue.write']]);
    $member = \Vmod\Core\Models\Membership::create(['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $role->id]);
    $member->branches()->attach($one['branch']->id);
    forceWait(
        $observer,
        'Revocation before catalogue write',
        'vmod_core_companies',
        fn () => $admin->saveRole(['id' => $role->id, 'name' => 'Reader', 'permissions' => ['catalogue.read']]),
        ['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'operation' => 'product', 'input' => ['sku' => 'DENIED', 'name' => 'Denied', 'base_uom' => 'kg']],
        403
    );
    check(!\Vmod\Erp\Models\Product::where('company_id', $one['company']->id)->where('sku', 'DENIED')->exists(), 'Revoked writer inserted a product.');
    echo "ERP MySQL concurrency: 6 forced and observed lock waits passed; no schemas cleared.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
