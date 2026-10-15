<?php

// CLI-only synthetic acceptance accounts. Never deploy this as a public bootstrap endpoint.
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
    throw new RuntimeException('Browser fixtures require the owned local development runtime.');
}
if ($kernel->call('october:migrate', ['--no-interaction' => true]) !== 0 || !Schema::hasTable('vmod_core_companies')) {
    fwrite(STDERR, "Core migrations did not complete. Refresh the native plugin manifest first.\n");
    exit(1);
}
$nonce = bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(20)) . '7!';
$fixtures = ['nonce' => $nonce, 'password' => $password, 'runtime' => [
    'php' => PHP_VERSION, 'database' => DB::connection()->getDatabaseName(),
    'host' => config('database.connections.mysql.host'), 'theme' => config('cms.active_theme'),
]];
foreach (['a', 'b'] as $key) {
    $created = $bootstrap->create([
        'company_code' => 'phase2-' . $key . '-' . $nonce,
        'company_name' => 'Phase 2 ' . strtoupper($key) . ' ' . $nonce,
        'branch_name' => 'Main', 'first_name' => 'Owner ' . strtoupper($key),
        'email' => 'phase2-' . $key . '-' . $nonce . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password,
    ]);
    $fixtures[$key] = [
        'email' => $created['owner']->email, 'user_id' => $created['owner']->id,
        'company_id' => $created['company']->id, 'company_name' => $created['company']->name,
        'branch_id' => $created['branch']->id, 'member_id' => $created['membership']->id,
        'role_id' => $created['membership']->role_id,
    ];
}
$second = \Vmod\Core\Models\Branch::create(['company_id' => $fixtures['a']['company_id'], 'name' => 'Restricted branch']);
\Vmod\Core\Models\Membership::find($fixtures['a']['member_id'])->branches()->attach($second->id);
$reader = \Vmod\Core\Models\Role::create([
    'company_id' => $fixtures['a']['company_id'], 'name' => 'Reader', 'permissions' => ['catalogue.read'],
]);
$cross = \Vmod\Core\Models\Membership::create([
    'company_id' => $fixtures['a']['company_id'], 'user_id' => $fixtures['b']['user_id'], 'role_id' => $reader->id,
]);
$cross->branches()->attach($fixtures['a']['branch_id']);
$fixtures['reader_role_id'] = $reader->id;
$fixtures['restricted_branch_id'] = $second->id;
$fixtures['cross_member_id'] = $cross->id;
$fixtures['invite_email'] = 'phase2-invite-' . $nonce . '@example.invalid';
echo json_encode($fixtures, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
