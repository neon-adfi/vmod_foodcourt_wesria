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
        'approve' => [new \Vmod\Erp\Services\SalesService, 'approve'],
        'dispatch' => [new \Vmod\Erp\Services\DispatchService, 'dispatch'],
        'correct' => [new \Vmod\Erp\Services\DispatchService, 'correct'],
        'receiptCorrect' => [new \Vmod\Erp\Services\ReceivingService, 'correct'],
        'save' => [new \Vmod\Erp\Services\SalesService, 'saveDraft'],
        'adjust' => [new \Vmod\Erp\Services\StockService, 'adjust'],
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
        echo $name . ': observed WAITING on ' . $table . ' (waiter ' . $waiterId . ', blocker ' . $holderId . '); waiter HTTP ' . $expected . "; company lock first.\n";
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

function soInput(array $f, string $quantity = '6'): array
{
    return ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'customer_id' => $f['supplier']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15',
        'price_mode' => 'exclusive', 'lines' => [['package_id' => $f['package']->id, 'location_id' => $f['location']->id, 'quantity' => $quantity]]];
}

function delivery($so, string $quantity)
{
    return (new \Vmod\Erp\Services\DispatchService)->saveDraft(['operation_key' => (string) \Illuminate\Support\Str::uuid(),
        'sales_order_id' => $so->id, 'document_date' => '2026-10-15',
        'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => $quantity]]]);
}

function reconcile(array $f): void
{
    foreach (\Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->get() as $balance) {
        $key = $balance->only(['company_id', 'branch_id', 'location_id', 'product_id']);
        $sum = DB::table('vmod_erp_stock_movements')->where($key)->sum('quantity');
        $reserved = DB::table('vmod_erp_stock_reservations')->where($key)->selectRaw('COALESCE(SUM(approved - dispatched - released), 0) AS total')->first()->total;
        check(\Brick\Math\BigDecimal::of((string) $sum)->isEqualTo($balance->on_hand), 'Ledger/balance mismatch.');
        check(\Brick\Math\BigDecimal::of((string) $reserved)->isEqualTo($balance->reserved), 'Reservation/balance mismatch.');
        check(!\Brick\Math\BigDecimal::of($balance->reserved)->isNegative() && !\Brick\Math\BigDecimal::of($balance->on_hand)->isLessThan($balance->reserved), 'Stock bounds failed.');
    }
    foreach (\Vmod\Erp\Models\StockReservation::where('company_id', $f['company']->id)->get() as $reservation) {
        $sum = DB::table('vmod_erp_delivery_order_items as i')->join('vmod_erp_delivery_orders as d', 'd.id', '=', 'i.delivery_order_id')
            ->where('d.state', 'dispatched')->where('i.sales_order_item_id', $reservation->sales_order_item_id)->sum('i.base_quantity');
        check(\Brick\Math\BigDecimal::of((string) $sum)->isEqualTo($reservation->dispatched), 'Delivery/reservation mismatch.');
    }
    check(DB::table('vmod_erp_stock_movements')->where('company_id', $f['company']->id)->whereNotNull('delivery_item_id')
        ->groupBy('delivery_item_id', 'action')->havingRaw('COUNT(*) > 1')->get()->isEmpty(), 'Duplicate delivery posting.');
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
    $s = new \Vmod\Erp\Services\SalesService;
    $d = new \Vmod\Erp\Services\DispatchService;
    $r = new \Vmod\Erp\Services\ReceivingService;
    $stock = new \Vmod\Erp\Services\StockService;
    $f['supplier'] = $c->saveParty(['code' => 'C', 'name' => 'Customer and supplier', 'is_supplier' => true, 'is_customer' => true]);
    $f['product'] = $c->saveProduct(['sku' => 'P', 'name' => 'Product', 'base_uom' => 'kg']);
    $f['package'] = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'PACK', 'name' => 'Pack', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
    $f['offer'] = $c->saveOffer(['supplier_id' => $f['supplier']->id, 'package_id' => $f['package']->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
    $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Main']);
    $adjust = ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'product_id' => $f['product']->id, 'location_id' => $f['location']->id, 'expected_base_uom' => 'kg',
        'quantity' => '10', 'reason' => 'Opening'];
    $stock->open($adjust);
    $job = ['company_id' => $f['company']->id, 'user_id' => $f['owner']->id];
    $first = $s->saveDraft(soInput($f));
    $second = $s->saveDraft(soInput($f));
    forceWait(
        $observer,
        'Scarce-stock approvals',
        'vmod_erp_stock_balances',
        fn () => $s->approve(action($first)),
        $job + ['operation' => 'approve', 'input' => action($second)],
        409
    );
    check($second->fresh()->state === 'draft', 'Failed approval changed state.');
    check(\Vmod\Erp\Models\StockReservation::where('sales_order_id', $second->id)->count() === 0, 'Failed approval reserved stock.');
    $s->void(action($first, ['reason' => 'Release for retry scenario']));
    reconcile($f);

    $input = action($second);
    forceWait(
        $observer,
        'Identical approval replay',
        'vmod_erp_operations',
        fn () => $s->approve($input),
        $job + ['operation' => 'approve', 'input' => $input],
        200
    );
    check(\Vmod\Erp\Models\StockReservation::where('sales_order_id', $second->id)->count() === 1, 'Duplicate reservation.');
    $second = $second->fresh(['revision.items']);
    $shipment = delivery($second, '2');
    $input = action($shipment);
    $before = \Vmod\Erp\Models\StockMovement::where('company_id', $f['company']->id)->count();
    forceWait(
        $observer,
        'Identical dispatch replay',
        'vmod_erp_operations',
        fn () => $d->dispatch($input),
        $job + ['operation' => 'dispatch', 'input' => $input],
        200
    );
    check(\Vmod\Erp\Models\StockMovement::where('company_id', $f['company']->id)->count() === $before + 1, 'Duplicate physical dispatch.');
    $a = delivery($second, '3');
    $b = delivery($second, '3');
    forceWait(
        $observer,
        'Competing over-dispatch',
        'vmod_erp_sales_orders',
        fn () => $d->dispatch(action($a)),
        $job + ['operation' => 'dispatch', 'input' => action($b)],
        409
    );
    check($b->fresh()->state === 'draft', 'Over-dispatch changed draft.');
    forceWait(
        $observer,
        'Close short before dispatch',
        'vmod_erp_sales_orders',
        fn () => $s->closeShort(action($second, ['reason' => 'Close remainder'])),
        $job + ['operation' => 'dispatch', 'input' => action($b)],
        409
    );
    check($b->fresh()->state === 'voided', 'Closure did not void pending delivery.');
    reconcile($f);

    $increase = array_replace($adjust, ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'quantity' => '5', 'direction' => 'increase']);
    $stock->adjust($increase); // 10 on-hand, zero reserved.
    $order = $s->saveDraft(soInput($f));
    $decrease = array_replace($increase, ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'direction' => 'decrease']);
    forceWait(
        $observer,
        'Approval before unsafe adjustment',
        'vmod_erp_stock_balances',
        fn () => $s->approve(action($order)),
        $job + ['operation' => 'adjust', 'input' => $decrease],
        409
    );
    $s->void(action($order, ['reason' => 'Release demand']));
    $order = $s->saveDraft(soInput($f));
    forceWait(
        $observer,
        'Adjustment before scarce-stock approval',
        'vmod_erp_stock_balances',
        fn () => $stock->adjust($decrease),
        $job + ['operation' => 'approve', 'input' => action($order)],
        409
    );
    reconcile($f);

    $receipt = $r->confirm(action(grn($f, po($f, '5'), '5'))); // Back to 10 on-hand.
    forceWait(
        $observer,
        'Approval before unsafe GRN correction',
        'vmod_erp_stock_balances',
        fn () => $s->approve(action($order)),
        $job + ['operation' => 'receiptCorrect', 'input' => action($receipt, ['reason' => 'Reverse five', 'lines' => []])],
        409
    );
    $s->void(action($order, ['reason' => 'Release demand']));
    $order = $s->saveDraft(soInput($f));
    forceWait(
        $observer,
        'GRN correction before scarce-stock approval',
        'vmod_erp_stock_balances',
        fn () => $r->correct(action($receipt, ['reason' => 'Reverse five', 'lines' => []])),
        $job + ['operation' => 'approve', 'input' => action($order)],
        409
    );
    reconcile($f);

    $order = $s->approve(action($s->saveDraft(soInput($f, '4'))));
    $shipment = $d->dispatch(action(delivery($order, '2'))); // 3 on-hand, 2 reserved.
    $decrease = array_replace($decrease, ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'quantity' => '2']);
    forceWait(
        $observer,
        'Full reversal before competing stock reduction',
        'vmod_erp_stock_balances',
        fn () => $d->correct(action($shipment, ['reason' => 'Restore reservation', 'lines' => []])),
        $job + ['operation' => 'adjust', 'input' => $decrease],
        409
    );
    check($order->fresh()->first_dispatch_at !== null, 'Historical dispatch lock lost.');
    $s->closeShort(action($order, ['reason' => 'Release restored remainder']));
    reconcile($f);

    $count = \Vmod\Erp\Models\SalesOrder::where('company_id', $f['company']->id)->count();
    forceWait(
        $observer,
        'Concurrent SO numbering',
        'vmod_erp_document_counters',
        fn () => $s->saveDraft(soInput($f)),
        $job + ['operation' => 'save', 'input' => soInput($f)],
        200
    );
    $numbers = \Vmod\Erp\Models\SalesOrder::where('company_id', $f['company']->id)->pluck('number');
    check($numbers->count() === $count + 2 && $numbers->unique()->count() === $numbers->count(), 'Numbering collision.');

    $other = company();
    $admin = new \Vmod\Core\Services\AdministrationService;
    $role = $admin->saveRole(['name' => 'Approver', 'permissions' => ['salesorder.approve']]);
    $member = \Vmod\Core\Models\Membership::create(['company_id' => $f['company']->id, 'user_id' => $other['owner']->id, 'role_id' => $role->id]);
    $member->branches()->attach($f['branch']->id);
    $pending = $s->saveDraft(soInput($f, '1'));
    forceWait(
        $observer,
        'Revocation before approval',
        'vmod_core_companies',
        fn () => $admin->saveRole(['id' => $role->id, 'name' => 'Reader', 'permissions' => ['salesorder.read']]),
        ['company_id' => $f['company']->id, 'user_id' => $other['owner']->id, 'operation' => 'approve', 'input' => action($pending)],
        403
    );
    check($pending->fresh()->state === 'draft', 'Revoked approval wrote data.');
    reconcile($f);
    echo "Sales MySQL: 12 forced and observed waits passed; physical and reservation ledgers reconciled; no schemas cleared.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
