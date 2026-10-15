<?php

// CLI-only additive synthetic records in the owned local database. Keep its JSON output private.
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
if ($kernel->call('october:migrate', ['--no-interaction' => true]) !== 0 || !Schema::hasTable('vmod_erp_products')) {
    throw new RuntimeException('Refresh the native plugin manifest and run additive migrations first.');
}
$nonce = bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(20)) . '7!';
$fixture = ['password' => $password, 'runtime' => ['php' => PHP_VERSION, 'mysql' => DB::selectOne('SELECT VERSION() AS v')->v,
    'database' => DB::connection()->getDatabaseName(), 'host' => config('database.connections.mysql.host'), 'theme' => config('cms.active_theme')]];
foreach (['a', 'b'] as $key) {
    $created = $bootstrap->create([
        'company_code' => 'phase5-' . $key . '-' . $nonce, 'company_name' => 'Phase 5 ' . strtoupper($key) . ' ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner ' . strtoupper($key), 'email' => 'phase5-' . $key . '-' . $nonce . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $fixture[$key] = ['email' => $created['owner']->email, 'user_id' => $created['owner']->id, 'company_id' => $created['company']->id,
        'branch_id' => $created['branch']->id, 'member_id' => $created['membership']->id];
}
Auth::login(\Vmod\Core\Models\User::findOrFail($fixture['a']['user_id']));
\Vmod\Core\Classes\Workspace::switchTo($fixture['a']['company_id']);
Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
$branch = (new \Vmod\Core\Services\AdministrationService)->saveBranch(['name' => 'Restricted branch']);
$reader = (new \Vmod\Core\Services\AdministrationService)->saveRole(['name' => 'Operations reader', 'permissions' => ['salesorder.read', 'deliveryorder.read']]);
$member = \Vmod\Core\Models\Membership::create(['company_id' => $fixture['a']['company_id'], 'user_id' => $fixture['b']['user_id'], 'role_id' => $reader->id]);
$member->branches()->attach($fixture['a']['branch_id']);
$c = new \Vmod\Erp\Services\CatalogueService;
$supplier = $c->saveParty(['code' => 'SUP', 'name' => 'Acceptance supplier', 'is_supplier' => true, 'is_customer' => true]);
$product = $c->saveProduct(['sku' => 'RICE', 'name' => 'Acceptance rice', 'base_uom' => 'kg']);
$loose = $c->saveProduct(['sku' => 'LOOSE', 'name' => 'Acceptance loose stock', 'base_uom' => 'kg']);
$package = $c->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
$offer = $c->saveOffer(['supplier_id' => $supplier->id, 'package_id' => $package->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
$tax = $c->saveTaxRate(['name' => 'Example tax', 'rate' => '6']);
$location = $c->saveLocation(['branch_id' => $fixture['a']['branch_id'], 'code' => 'MAIN', 'name' => 'Main store']);
$restricted = $c->saveLocation(['branch_id' => $branch->id, 'code' => 'RESTRICTED', 'name' => 'Restricted store']);
$fixture += ['supplier_id' => $supplier->id, 'product_id' => $product->id, 'loose_id' => $loose->id, 'offer_id' => $offer->id,
    'tax_id' => $tax->id, 'location_id' => $location->id, 'restricted_branch_id' => $branch->id, 'restricted_location_id' => $restricted->id];
$p = new \Vmod\Erp\Services\SalesService;
$blank = $c->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'BLANK', 'name' => 'Unpriced package', 'conversion' => '1']);
$zero = $c->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'ZERO', 'name' => 'Zero price package', 'conversion' => '1', 'sales_price' => '0', 'expected_currency' => 'MYR']);
$fixture += ['customer_id' => $supplier->id, 'package_id' => $package->id, 'blank_id' => $blank->id, 'zero_id' => $zero->id];
(new \Vmod\Erp\Services\StockService)->open(['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $fixture['a']['branch_id'],
    'product_id' => $product->id, 'location_id' => $location->id, 'expected_base_uom' => 'kg', 'quantity' => '10', 'reason' => 'Acceptance opening']);
for ($i = 1; $i <= 17; $i++) {
    $order = $p->saveDraft(['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $fixture['a']['branch_id'],
        'customer_id' => $supplier->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15', 'notes' => 'Pagination ' . $i,
        'price_mode' => 'exclusive', 'lines' => [['package_id' => $package->id, 'location_id' => $location->id, 'quantity' => '3', 'tax_rate_id' => $tax->id]]]);
    if ($i === 1) {
        $fixture['seed_order_id'] = $order->id;
    }
}
$restrictedOrder = $p->saveDraft(['operation_key' => (string) \Illuminate\Support\Str::uuid(), 'branch_id' => $branch->id,
    'customer_id' => $supplier->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15', 'price_mode' => 'exclusive',
    'lines' => [['package_id' => $package->id, 'location_id' => $restricted->id, 'quantity' => '1']]]);
$fixture['restricted_order_id'] = $restrictedOrder->id;
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
