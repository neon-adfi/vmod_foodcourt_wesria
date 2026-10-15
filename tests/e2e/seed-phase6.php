<?php

// Additive local-only identities; the principal catalogue/order/reference journey is created through the UI.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$bootstrap = new \Vmod\Core\Services\BootstrapService;
$bootstrap->assertOwnedEnvironment();
if (!$app->environment('local')) {
    throw new RuntimeException('Browser acceptance requires the owned local runtime.');
}
if ($kernel->call('october:migrate', ['--no-interaction' => true]) !== 0 || !Schema::hasTable('vmod_erp_invoice_references')) {
    throw new RuntimeException('Apply the additive Phase 6 migrations first.');
}
if (($argv[1] ?? '') === '--legacy-print') {
    $fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $company = \Vmod\Core\Models\Company::findOrFail($fixture['a']['company_id']);
    if (!str_starts_with($company->code, 'phase6-a-')) {
        throw new RuntimeException('Only this acceptance run may receive synthetic legacy print fixtures.');
    }
    $source = \Vmod\Erp\Models\PurchaseOrder::where('company_id', $company->id)->findOrFail($fixture['po_id']);
    $id = DB::transaction(function () use ($source) {
        $order = $source->replicate();
        $order->number = 'PO-LEGACY-' . bin2hex(random_bytes(4));
        $order->revision_id = null;
        $order->state = 'draft';
        $order->first_received_at = null;
        $order->save();
        $revision = $source->revision->replicate();
        $revision->purchase_order_id = $order->id;
        $revision->issuer_snapshot = null; // New synthetic legacy row; never erase snapshots from an existing row.
        $revision->approved_at = null;
        $revision->created_at = now();
        $revision->net = '600.00';
        $revision->tax = '36.00';
        $revision->gross = '636.00';
        $revision->save();
        for ($n = 1; $n <= 60; $n++) {
            $item = $source->revision->items->first()->replicate();
            $item->revision_id = $revision->id;
            $item->position = $n;
            $item->quantity = '1';
            $item->base_quantity = '1';
            $item->net = '10';
            $item->tax = '0.60';
            $item->gross = '10.60';
            $item->save();
        }
        $order->revision_id = $revision->id;
        $order->save();
        return $order->id;
    });
    echo json_encode(['legacy_po_id' => $id], JSON_THROW_ON_ERROR) . "\n";
    exit;
}
$nonce = bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(20)) . '7!';
$fixture = ['password' => $password, 'runtime' => ['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS v')->v,
    'database' => DB::connection()->getDatabaseName(), 'host' => config('database.connections.mysql.host'), 'theme' => config('cms.active_theme')]];
foreach (['a', 'b'] as $key) {
    $created = $bootstrap->create(['company_code' => 'phase6-' . $key . '-' . $nonce, 'company_name' => 'Phase 6 ' . strtoupper($key) . ' ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner ' . strtoupper($key), 'email' => 'phase6-' . $key . '-' . $nonce . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password]);
    $fixture[$key] = ['email' => $created['owner']->email, 'user_id' => $created['owner']->id, 'company_id' => $created['company']->id,
        'branch_id' => $created['branch']->id, 'member_id' => $created['membership']->id];
}
\Auth::login(\Vmod\Core\Models\User::findOrFail($fixture['a']['user_id']));
\Vmod\Core\Classes\Workspace::switchTo($fixture['a']['company_id']);
Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
$admin = new \Vmod\Core\Services\AdministrationService;
$branch = $admin->saveBranch(['name' => 'Restricted branch']);
$role = $admin->saveRole(['name' => 'Invoice references only', 'permissions' => ['invoice_reference.read', 'invoice_reference.write']]);
$member = \Vmod\Core\Models\Membership::create(['company_id' => $fixture['a']['company_id'], 'user_id' => $fixture['b']['user_id'], 'role_id' => $role->id]);
$member->branches()->attach($fixture['a']['branch_id']);
$fixture += ['restricted_branch_id' => $branch->id, 'reader_role_id' => $role->id];
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
