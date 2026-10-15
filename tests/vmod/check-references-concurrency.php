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
        'save' => [new \Vmod\Erp\Services\InvoiceReferenceService, 'save'],
        'restore' => [new \Vmod\Erp\Services\InvoiceReferenceService, 'restore'],
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
        // Insert uniqueness can attribute an implicit blocking lock to the requesting thread in MySQL 8.0.46.
        // Resolve the blocker by its InnoDB transaction, and the requester by its observed connection.
        $query = $observer->prepare('SELECT requested.OBJECT_NAME, requested.LOCK_STATUS
            FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID
            JOIN information_schema.innodb_trx b ON b.trx_id = w.BLOCKING_ENGINE_TRANSACTION_ID
            JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = w.ENGINE
            WHERE r.PROCESSLIST_ID = ? AND b.trx_mysql_thread_id = ? AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ?');
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
            $waits = $observer->query('SELECT requested.OBJECT_NAME, requested.LOCK_STATUS, r.PROCESSLIST_ID AS waiter, b.PROCESSLIST_ID AS blocker FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = w.ENGINE')->fetchAll(PDO::FETCH_ASSOC);
            fwrite(STDERR, json_encode(['waits' => $observer->query('SELECT * FROM performance_schema.data_lock_waits')->fetchAll(PDO::FETCH_ASSOC), 'transactions' => $observer->query('SELECT trx_id, trx_mysql_thread_id, trx_state FROM information_schema.innodb_trx')->fetchAll(PDO::FETCH_ASSOC)]) . "\n");
            $status = proc_get_status($process);
            fwrite(STDERR, json_encode(['worker_id' => $waiterId, 'holder_id' => $holderId, 'running' => $status['running'], 'wait_tables' => $waits]) . "\n");
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


function reference(array $f, $po, string $number): array
{
    return ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $f['branch']->id,
        'party_id' => $f['supplier']->id, 'direction' => 'purchase', 'number' => $number,
        'document_date' => '2026-10-16', 'amount' => '0',
        'links' => [['kind' => 'po', 'id' => $po->id, 'revision_id' => $po->revision_id]]];
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
    $s = new \Vmod\Erp\Services\InvoiceReferenceService;
    $p = new \Vmod\Erp\Services\PurchasingService;
    $r = new \Vmod\Erp\Services\ReceivingService;
    $f['supplier'] = $c->saveParty(['code' => 'C', 'name' => 'Supplier', 'is_supplier' => true, 'is_customer' => false]);
    $f['product'] = $c->saveProduct(['sku' => 'P', 'name' => 'Product', 'base_uom' => 'kg']);
    $f['package'] = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'PACK', 'name' => 'Pack', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
    $f['offer'] = $c->saveOffer(['supplier_id' => $f['supplier']->id, 'package_id' => $f['package']->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
    $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Main']);
    $job = ['company_id' => $f['company']->id, 'user_id' => $f['owner']->id];
    $first = po($f);
    $second = po($f);
    $input = reference($f, $first, 'Race-1');
    forceWait(
        $observer,
        'Duplicate create',
        'vmod_erp_invoice_references',
        fn () => $s->save($input),
        $job + ['operation' => 'save', 'input' => reference($f, $second, ' race-1 ')],
        409
    );
    $ref = \Vmod\Erp\Models\InvoiceReference::where('company_id', $f['company']->id)->sole();
    check($ref->number === 'Race-1' && $ref->links()->count() === 1, 'Duplicate create changed record.');
    $retry = reference($f, $first, 'Retry');
    forceWait(
        $observer,
        'Identical retry',
        'vmod_erp_operations',
        fn () => $s->save($retry),
        $job + ['operation' => 'save', 'input' => $retry],
        200
    );
    $s->void(action($ref, ['reason' => 'Release number']));
    forceWait(
        $observer,
        'Restore versus create',
        'vmod_erp_invoice_references',
        fn () => $s->save(reference($f, $second, 'RACE-1')),
        $job + ['operation' => 'restore', 'input' => action($ref, ['reason' => 'Restore'])],
        409
    );
    check($ref->fresh()->state === 'voided', 'Failed restore changed state.');
    $ref = $s->save(reference($f, $first, 'Edit'));
    $edit = array_replace(reference($f, $first, 'Edit'), action($ref), ['reason' => 'Concurrent metadata']);
    forceWait(
        $observer,
        'Concurrent edits',
        'vmod_erp_invoice_references',
        fn () => $s->save($edit),
        $job + ['operation' => 'save', 'input' => array_replace($edit, ['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'amount' => '20'])],
        409
    );
    check($ref->fresh()->amount === '0.00' && $ref->fresh()->version === 2, 'Stale edit changed data.');
    $transition = po($f);
    forceWait(
        $observer,
        'Link versus reopen',
        'vmod_erp_purchase_orders',
        fn () => $p->reopen(action($transition, ['reason' => 'Revise'])),
        $job + ['operation' => 'save', 'input' => reference($f, $transition, 'Reopened')],
        409
    );
    $receipt = $r->confirm(action(grn($f, $first, '3')));
    $receiptInput = reference($f, $first, 'Corrected');
    $receiptInput['links'] = [['kind' => 'grn', 'id' => $receipt->id, 'revision_id' => $receipt->revision_id]];
    forceWait(
        $observer,
        'Link versus receipt correction',
        'vmod_erp_purchase_orders',
        fn () => $r->correct(action($receipt, ['reason' => 'Wrong receipt', 'lines' => []])),
        $job + ['operation' => 'save', 'input' => $receiptInput],
        409
    );
    $release = $s->save(reference($f, $second, 'Release'));
    forceWait(
        $observer,
        'Void releases active number',
        'vmod_erp_invoice_references',
        fn () => $s->void(action($release, ['reason' => 'Release'])),
        $job + ['operation' => 'save', 'input' => reference($f, $first, 'RELEASE')],
        200
    );
    forceWait($observer, 'Permission revocation', 'vmod_core_companies', function () use ($f) {
        \Vmod\Core\Models\Company::whereKey($f['company']->id)->lockForUpdate()->firstOrFail();
        $role = $f['membership']->role;
        $role->permissions = ['invoice_reference.read'];
        $role->save();
    }, $job + ['operation' => 'save', 'input' => reference($f, $second, 'Revoked')], 403);
    check(\Vmod\Erp\Models\InvoiceReference::where('company_id', $f['company']->id)->count() === 6, 'Unexpected reference count.');
    check(DB::table('vmod_erp_invoice_references')->where('company_id', $f['company']->id)->whereNotNull('active_number')
        ->select('direction', 'party_id', 'active_number')->groupBy('direction', 'party_id', 'active_number')->havingRaw('COUNT(*) > 1')->get()->isEmpty(), 'Active duplicate persisted.');
    check(\Vmod\Core\Models\Audit::where('company_id', $f['company']->id)->where('action', 'invoice_reference.change')->count() === 9, 'Detailed audit count differs from successful operations.');
    foreach (\Vmod\Erp\Models\StockBalance::where('company_id', $f['company']->id)->get() as $balance) {
        $sum = DB::table('vmod_erp_stock_movements')->where($balance->only(['company_id', 'branch_id', 'location_id', 'product_id']))->sum('quantity');
        check(\Brick\Math\BigDecimal::of((string) $sum)->isEqualTo($balance->on_hand), 'Stock reconciliation failed.');
        check(\Brick\Math\BigDecimal::of($balance->reserved)->isZero(), 'Invoice changed reservations.');
    }
    echo "PASS: 8 observed reference waits; duplicate keys, stock and reservations reconciled.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
