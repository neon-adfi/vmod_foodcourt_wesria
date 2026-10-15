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
    [$service, $method] = match ($operation) {
        'confirm' => [new \Vmod\Erp\Services\ReceivingService, 'confirm'],
        'correct' => [new \Vmod\Erp\Services\ReceivingService, 'correct'],
        'save' => [new \Vmod\Erp\Services\PurchasingService, 'saveDraft'],
        'open' => [new \Vmod\Erp\Services\StockService, 'open'],
        'product' => [new \Vmod\Erp\Services\CatalogueService, 'saveProduct'],
        default => throw new RuntimeException('Unknown operation'),
    };
    $service->$method($input);
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
        if (!$observed) {
            $waits = $observer->query('SELECT requested.OBJECT_NAME FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = w.ENGINE')->fetchAll(PDO::FETCH_COLUMN);
            $status = proc_get_status($process);
            fwrite(STDERR, json_encode(['worker_ready' => (bool) $waiterId, 'running' => $status['running'], 'wait_tables' => $waits]) . "\n");
            if (!$status['running']) {
                fwrite(STDERR, stream_get_contents($pipes[2]));
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

function action($record, array $extra = []): array
{
    return array_replace(['id' => $record->id, 'version' => $record->fresh()->version, 'operation_key' => (string) \Illuminate\Support\Str::uuid()], $extra);
}

function po(array $f, string $quantity = '3')
{
    $service = new \Vmod\Erp\Services\PurchasingService;
    $po = $service->saveDraft(poInput($f, $quantity));
    return $service->approve(action($po));
}

function poInput(array $f, string $quantity = '3'): array
{
    return ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'supplier_id' => $f['supplier']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15',
        'price_mode' => 'exclusive', 'lines' => [['offer_id' => $f['offer']->id, 'quantity' => $quantity]]];
}

function grn(array $f, $po, string $quantity)
{
    return (new \Vmod\Erp\Services\ReceivingService)->saveDraft(['operation_key' => (string) \Illuminate\Support\Str::uuid(),
        'purchase_order_id' => $po->id, 'document_date' => '2026-10-15', 'supplier_reference' => 'DO-' . bin2hex(random_bytes(6)),
        'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => $quantity]]]);
}

function reconcile(array $f): void
{
    foreach (\Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->get() as $balance) {
        $sum = DB::table('vmod_erp_stock_movements')->where($balance->only(['company_id', 'branch_id', 'location_id', 'product_id']))->sum('quantity');
        check(\Brick\Math\BigDecimal::of((string) $sum)->isEqualTo($balance->on_hand), 'Ledger/balance mismatch.');
        check(!\Brick\Math\BigDecimal::of($balance->on_hand)->isNegative(), 'Negative stock.');
    }
}

try {
    $password = stream_get_contents(STDIN);
    check($password !== '', 'Pass the owned observer credential privately on stdin.');
    $observer = new PDO('mysql:host=mysql-test;dbname=performance_schema', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($password);
    check($kernel->call('october:migrate', ['--no-interaction' => true]) === 0, 'Migration failed.');
    $f = company();
    acting($f['owner']->id, $f['company']->id);
    $c = new \Vmod\Erp\Services\CatalogueService;
    $p = new \Vmod\Erp\Services\PurchasingService;
    $r = new \Vmod\Erp\Services\ReceivingService;
    $s = new \Vmod\Erp\Services\StockService;
    $f['supplier'] = $c->saveParty(['code' => 'S', 'name' => 'Supplier', 'is_supplier' => true, 'is_customer' => false]);
    $f['product'] = $c->saveProduct(['sku' => 'P', 'name' => 'Product', 'base_uom' => 'kg']);
    $package = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'PACK', 'name' => 'Pack', 'conversion' => '1']);
    $f['offer'] = $c->saveOffer(['supplier_id' => $f['supplier']->id, 'package_id' => $package->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
    $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Main']);
    $job = ['company_id' => $f['company']->id, 'user_id' => $f['owner']->id];
    $first = grn($f, po($f), '1');
    $second = grn($f, po($f), '2');
    forceWait(
        $observer,
        'Concurrent first balance creation',
        'vmod_erp_stock_balances',
        fn () => $r->confirm(action($first)),
        $job + ['operation' => 'confirm', 'input' => action($second)],
        200
    );
    check(\Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->count() === 1, 'Duplicate first balance.');
    check(\Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->sole()->on_hand === '3.0000', 'First receipt sum wrong.');
    reconcile($f);

    $duplicate = grn($f, po($f), '1');
    $input = action($duplicate);
    $before = \Vmod\Erp\Models\StockMovement::where('company_id', $f['company']->id)->count();
    forceWait(
        $observer,
        'Identical confirmation replay',
        'vmod_erp_operations',
        fn () => $r->confirm($input),
        $job + ['operation' => 'confirm', 'input' => $input],
        200
    );
    check(\Vmod\Erp\Models\StockMovement::where('company_id', $f['company']->id)->count() === $before + 1, 'Duplicate physical posting.');

    $order = po($f);
    $a = grn($f, $order, '2');
    $b = grn($f, $order, '2');
    forceWait(
        $observer,
        'Competing receipts exceed remainder',
        'vmod_erp_purchase_orders',
        fn () => $r->confirm(action($a)),
        $job + ['operation' => 'confirm', 'input' => action($b)],
        409
    );
    check($b->fresh()->state === 'draft', 'Over-receipt changed draft.');
    forceWait(
        $observer,
        'Close short before confirmation',
        'vmod_erp_purchase_orders',
        fn () => $p->closeShort(action($order, ['reason' => 'Close remaining'])),
        $job + ['operation' => 'confirm', 'input' => action($b)],
        409
    );
    check($b->fresh()->state === 'voided', 'Close did not void pending receipt.');

    $stock = \Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->sole();
    $adjust = ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'product_id' => $f['product']->id, 'location_id' => $f['location']->id, 'expected_base_uom' => 'kg',
        'quantity' => $stock->on_hand, 'direction' => 'decrease', 'reason' => 'Count correction'];
    forceWait(
        $observer,
        'Adjustment before unsafe reversal',
        'vmod_erp_stock_balances',
        fn () => $s->adjust($adjust),
        $job + ['operation' => 'correct', 'input' => action($second, ['reason' => 'Reverse receipt', 'lines' => []])],
        409
    );
    check($second->fresh()->state === 'confirmed', 'Unsafe reversal changed receipt.');
    reconcile($f);

    $count = \Vmod\Erp\Models\PurchaseOrder::where('company_id', $f['company']->id)->count();
    forceWait(
        $observer,
        'Concurrent document numbering',
        'vmod_erp_document_counters',
        fn () => $p->saveDraft(poInput($f)),
        $job + ['operation' => 'save', 'input' => poInput($f)],
        200
    );
    $numbers = \Vmod\Erp\Models\PurchaseOrder::where('company_id', $f['company']->id)->pluck('number');
    check($numbers->count() === $count + 2 && $numbers->unique()->count() === $numbers->count(), 'Numbering collision.');

    $loose = $c->saveProduct(['sku' => 'LOOSE', 'name' => 'Loose', 'base_uom' => 'kg']);
    $opening = ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'product_id' => $loose->id, 'location_id' => $f['location']->id, 'expected_base_uom' => 'kg', 'quantity' => '1', 'reason' => 'Opening'];
    forceWait(
        $observer,
        'First stock before UOM edit',
        'vmod_erp_products',
        fn () => $s->open($opening),
        $job + ['operation' => 'product', 'input' => ['id' => $loose->id, 'version' => $loose->version, 'sku' => 'LOOSE', 'name' => 'Loose', 'base_uom' => 'g']],
        409
    );
    check($loose->fresh()->base_uom === 'kg', 'Stock UOM changed.');
    $loose2 = $c->saveProduct(['sku' => 'LOOSE2', 'name' => 'Loose 2', 'base_uom' => 'kg']);
    forceWait(
        $observer,
        'UOM edit before stale opening form',
        'vmod_erp_products',
        fn () => $c->saveProduct(['id' => $loose2->id, 'version' => $loose2->version, 'sku' => 'LOOSE2', 'name' => 'Loose 2', 'base_uom' => 'g']),
        $job + ['operation' => 'open', 'input' => array_replace($opening, ['product_id' => $loose2->id, 'operation_key' => (string) \Illuminate\Support\Str::uuid()])],
        409
    );
    check(!\Vmod\Erp\Models\StockMovement::where('product_id', $loose2->id)->exists(), 'Stale UOM opening posted.');

    $other = company();
    $admin = new \Vmod\Core\Services\AdministrationService;
    $role = $admin->saveRole(['name' => 'Receiver', 'permissions' => ['goodsreceipt.read', 'goodsreceipt.confirm']]);
    $member = \Vmod\Core\Models\Membership::create(['company_id' => $f['company']->id, 'user_id' => $other['owner']->id, 'role_id' => $role->id]);
    $member->branches()->attach($f['branch']->id);
    $pending = grn($f, po($f), '1');
    forceWait(
        $observer,
        'Revocation before receipt confirmation',
        'vmod_core_companies',
        fn () => $admin->saveRole(['id' => $role->id, 'name' => 'Reader', 'permissions' => ['goodsreceipt.read']]),
        ['company_id' => $f['company']->id, 'user_id' => $other['owner']->id, 'operation' => 'confirm', 'input' => action($pending)],
        403
    );
    check($pending->fresh()->state === 'draft', 'Revoked confirmation wrote data.');
    reconcile($f);
    echo "Purchasing MySQL: 9 forced and observed waits passed; ledger reconciled; no schemas cleared.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
