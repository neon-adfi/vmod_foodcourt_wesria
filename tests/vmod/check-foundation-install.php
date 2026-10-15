<?php

// Explicit fresh-install gate. Refuse a nonempty schema; never reset data from a test runner.
require __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
require __DIR__ . '/../../vendor/autoload.php';
foreach (['CACHE_PATH' => '/tmp/wesria-vmod-cache', 'STORAGE_PATH' => '/tmp/wesria-vmod-storage', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv($key . '=' . $value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}
require __DIR__ . '/bootstrap.php';
$case = new class extends \Vmod\Tests\ApplicationTestCase {
};
$app = $case->createApplication();
$db = $app['db']->connection();
$identity = $db->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS identity, VERSION() AS version');
if ($identity->db !== 'vmod_wesria_test' || $identity->identity !== 'vmod_wesria_test@%') {
    throw new RuntimeException('Unsafe fresh-install database identity.');
}
$before = count($db->select('SHOW TABLES'));
if ($before !== 0) {
    throw new RuntimeException('Fresh-install gate requires an independently verified empty disposable schema.');
}
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
foreach (['october:migrate', 'tailor:migrate'] as $command) {
    if ($kernel->call($command, ['--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Fresh migration failed: ' . $command);
    }
}
$tables = count($db->select('SHOW TABLES'));
$users = $db->table('users')->count();
$admins = $db->table('backend_users')->count();
if ($users || $admins || !$db->getSchemaBuilder()->hasTable('vmod_erp_invoice_reference_links')) {
    throw new RuntimeException('Fresh install must have Phase 6 schema and no seeded accounts.');
}
echo json_encode(['identity' => $identity, 'tables_before' => $before, 'tables_after' => $tables,
    'users' => $users, 'backend_users' => $admins, 'passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
