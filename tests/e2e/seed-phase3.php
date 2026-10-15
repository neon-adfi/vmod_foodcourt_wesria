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
        'company_code' => 'phase3-' . $key . '-' . $nonce, 'company_name' => 'Phase 3 ' . strtoupper($key) . ' ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner ' . strtoupper($key), 'email' => 'phase3-' . $key . '-' . $nonce . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $fixture[$key] = ['email' => $created['owner']->email, 'user_id' => $created['owner']->id, 'company_id' => $created['company']->id,
        'branch_id' => $created['branch']->id, 'member_id' => $created['membership']->id];
}
Auth::login(\Vmod\Core\Models\User::findOrFail($fixture['a']['user_id']));
\Vmod\Core\Classes\Workspace::switchTo($fixture['a']['company_id']);
Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
$branch = (new \Vmod\Core\Services\AdministrationService)->saveBranch(['name' => 'Restricted branch']);
$reader = (new \Vmod\Core\Services\AdministrationService)->saveRole(['name' => 'Catalogue reader', 'permissions' => ['catalogue.read']]);
$member = \Vmod\Core\Models\Membership::create(['company_id' => $fixture['a']['company_id'], 'user_id' => $fixture['b']['user_id'], 'role_id' => $reader->id]);
$member->branches()->attach($fixture['a']['branch_id']);
$service = new \Vmod\Erp\Services\CatalogueService;
for ($index = 1; $index <= 26; $index++) {
    $service->saveProduct(['sku' => sprintf('SEED-%02d', $index), 'name' => sprintf('Pagination item %02d', $index), 'base_uom' => 'piece']);
}
$service->saveLocation(['branch_id' => $fixture['a']['branch_id'], 'code' => 'MAIN', 'name' => 'Main store']);
$restricted = $service->saveLocation(['branch_id' => $branch->id, 'code' => 'RESTRICTED', 'name' => 'Restricted store']);
$fixture['restricted_branch_id'] = $branch->id;
$fixture['restricted_location_id'] = $restricted->id;
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
