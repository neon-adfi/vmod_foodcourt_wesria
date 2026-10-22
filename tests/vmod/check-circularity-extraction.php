<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// phpcs:disable PSR1.Files.SideEffects -- Fixed, guarded disposable extraction qualification; never a production importer.
require_once __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
if (PHP_SAPI === 'fpm-fcgi') {
    return; // FPM auto_prepend_file guards the environment; index.php retains native HTTP boot.
}
// October's console exception renderer can return zero for a standalone script. Fail closed unless it finishes.
register_shutdown_function(static function () {
    if (empty($GLOBALS['extractionCompleted'])) {
        exit(1);
    }
});
require '/var/www/html/bootstrap/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->useStoragePath(getenv('STORAGE_PATH'));
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = config('database.connections.' . config('database.default'));
$config += ['environment' => $app->environment(), 'owner' => getenv('VMOD_TEST_DATABASE_OWNER'),
    'config_cache' => $app->getCachedConfigPath()];
\Vmod\Tests\DatabaseGuard::assertSafe($config, $app->configurationIsCached());
$identity = (array) DB::selectOne('SELECT DATABASE() db, CURRENT_USER() account, @@server_uuid server_uuid, VERSION() version');
extractionCheck($identity['db'] === 'vmod_wesria_test' && str_starts_with($identity['account'], 'vmod_wesria_test@'), 'Actual database identity differs.');
$target = getenv('VMOD_EXTRACTION_TARGET') === 'true';
if ($target) {
    extractionCheck($identity['server_uuid'] === getenv('VMOD_TARGET_UUID'), 'Target server UUID differs.');
}
$plugins = \System\Classes\PluginManager::instance();
$plugins->registerAll();
$plugins->bootAll();
const EXTRACTION_TABLES = ['vmod_circularity_areas', 'vmod_circularity_entries', 'system_plugin_versions', 'system_plugin_history'];

function extractionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function extractionRows(string $table): array
{
    $query = DB::table($table)->orderBy('id');
    if (str_starts_with($table, 'system_')) {
        $query->where('code', 'Vmod.Circularity');
    }
    return array_map(fn ($row) => (array) $row, $query->get()->all());
}

function extractionSchema(): array
{
    $schema = [];
    foreach (EXTRACTION_TABLES as $table) {
        $row = (array) DB::selectOne('SHOW CREATE TABLE `' . $table . '`');
        // ponytail: counters are not business state. Explicit IDs advance them; verify subsequent IDs separately.
        $schema[$table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $row['Create Table']);
    }
    return $schema;
}

function extractionState(): array
{
    return array_combine(EXTRACTION_TABLES, array_map('extractionRows', EXTRACTION_TABLES));
}

function extractionWrite(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    chmod($path, 0600);
}

function extractionValidate(array $snapshot): void
{
    extractionCheck(array_keys($snapshot) === ['identity', 'manifest', 'schema', 'rows'], 'Invalid snapshot envelope.');
    extractionCheck(array_keys($snapshot['rows']) === EXTRACTION_TABLES, 'Invalid transfer table allowlist.');
    extractionCheck($snapshot['schema'] === extractionSchema(), 'Schema or index mismatch.');
    $manifest = $snapshot['manifest'];
    foreach (EXTRACTION_TABLES as $table) {
        $ids = [];
        $columns = Schema::getColumnListing($table);
        sort($columns);
        foreach ($snapshot['rows'][$table] as $row) {
            $keys = array_keys($row);
            sort($keys);
            extractionCheck($keys === $columns && is_int($row['id']) && $row['id'] > 0 && !isset($ids[$row['id']]), 'Invalid row identity/columns.');
            $ids[$row['id']] = true;
            if (str_starts_with($table, 'system_')) {
                extractionCheck($row['code'] === 'Vmod.Circularity', 'Foreign migration metadata.');
            } else {
                extractionCheck($row['source_namespace'] === 'wesria-proof'
                    && in_array([$row['tenant_ref'], $row['branch_ref']], $manifest['scopes'], true), 'Foreign fixture scope.');
                if ($table === 'vmod_circularity_entries') {
                    extractionCheck(in_array($row['area_id'], $manifest['area_ids'], true)
                        && is_string($row['quantity']) && preg_match('/^\d+\.\d{3}$/D', $row['quantity']) === 1, 'Invalid quantity or area.');
                }
            }
        }
    }
    extractionCheck(array_column($snapshot['rows'][EXTRACTION_TABLES[0]], 'id') === $manifest['area_ids'], 'Area manifest mismatch.');
    extractionCheck(array_column($snapshot['rows'][EXTRACTION_TABLES[1]], 'uuid') === $manifest['uuids'], 'Entry manifest mismatch.');
    extractionCheck(count(array_unique($manifest['uuids'])) === count($manifest['uuids']), 'Duplicate UUID.');
    extractionCheck(count($snapshot['rows'][EXTRACTION_TABLES[2]]) === 1, 'Missing provider version.');
}

function extractionImport(array $snapshot, bool $interrupt = false): void
{
    extractionValidate($snapshot);
    extractionCheck(DB::table(EXTRACTION_TABLES[0])->count() === 0 && DB::table(EXTRACTION_TABLES[1])->count() === 0, 'Target must be empty.');
    foreach (array_slice(EXTRACTION_TABLES, 2) as $table) {
        extractionCheck(DB::table($table)->where('code', '!=', 'Vmod.Circularity')->count() === 0, 'Foreign plugin metadata.');
    }
    extractionCheck((int) DB::selectOne('SELECT @@foreign_key_checks enabled')->enabled === 1, 'Foreign keys must stay enabled.');
    DB::transaction(function () use ($snapshot, $interrupt) {
        foreach (EXTRACTION_TABLES as $table) {
            if (str_starts_with($table, 'system_')) {
                DB::table($table)->where('code', 'Vmod.Circularity')->delete();
            }
            foreach ($snapshot['rows'][$table] as $row) {
                DB::table($table)->insert($row);
            }
            if ($interrupt && $table === EXTRACTION_TABLES[0]) {
                throw new RuntimeException('Observed rollback after area insertion.');
            }
        }
    });
}

function extractionReject(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        extractionCheck($exception->getMessage() === $message, 'Unexpected rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$command = $argv[1] ?? '';
if ($command === 'export') {
    extractionCheck(!$target, 'Export requires source.');
    $fixture = json_decode(file_get_contents('/proof/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    $context = ['tenant_ref' => (string) $fixture['a']['company_id'],
        'branch_refs' => array_map('strval', [$fixture['a']['branch_id'], $fixture['other_branch_id']]),
        'actor_ref' => (string) $fixture['a']['user_id'], 'permissions' => ['circularity.read', 'circularity.write']];
    $http = Http::acceptJson()->withToken(getenv('CIRCULARITY_API_TOKEN'))->withHeaders(['X-Circularity-Context' => json_encode($context)])->timeout(15);
    $api = 'http://nginx-tech/api/circularity/v1';
    $area = $http->post($api . '/areas', ['branch_ref' => (string) $fixture['a']['branch_id'], 'name' => 'Historical 厨房 ♻', 'kind' => 'shared_area']);
    extractionCheck($area->status() === 201, 'Historical area seed failed.');
    $historyArea = $area->json('area.id');
    $uuids = array_values($fixture['entries']);
    for ($index = 0; $index < 27; $index++) {
        $payload = ['uuid' => (string) Str::uuid(), 'branch_ref' => (string) $fixture['a']['branch_id'], 'area_id' => $historyArea,
            'measurement_date' => '2026-01-01', 'measurement_type' => 'mixed_waste', 'mode' => 'individual',
            'quantity' => $index === 0 ? '1.125' : '2.000', 'destination' => 'unknown', 'reference' => null, 'notes' => '保留 café ♻'];
        extractionCheck($http->post($api . '/entries', $payload)->status() === 201, 'Historical entry seed failed.');
        $uuids[] = $payload['uuid'];
        if ($index === 0) {
            extractionCheck($http->patch($api . '/entries/' . $payload['uuid'], ['version' => 1, 'quantity' => '3.125'])->status() === 200, 'Historical edit failed.');
        }
        if ($index === 26) {
            extractionCheck($http->delete($api . '/entries/' . $payload['uuid'], ['version' => 1])->status() === 200, 'Historical removal failed.');
            $removed = $payload;
        }
    }
    extractionCheck($http->patch($api . '/areas/' . $historyArea, ['version' => 1, 'is_active' => false])->status() === 200, 'Historical archive failed.');
    // The source provider has one worker. Block mutations, then a health read drains all earlier work.
    file_put_contents('/proof/source-frozen', 'frozen');
    extractionCheck($http->get($api . '/health')->status() === 200, 'Source drain failed.');
    extractionCheck($http->post($api . '/entries', $removed)->status() === 503, 'Source mutation barrier failed.');
    $areaIds = array_merge(array_values($fixture['areas']), [$historyArea]);
    sort($areaIds);
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::statement('SET TRANSACTION READ ONLY');
    $snapshot = DB::transaction(function () use ($identity, $context, $areaIds, $uuids, $historyArea, $removed) {
        $rows = extractionState();
        extractionWrite('/proof/source-before.json', $rows);
        $rows[EXTRACTION_TABLES[0]] = array_values(array_filter($rows[EXTRACTION_TABLES[0]], fn ($row) => in_array($row['id'], $areaIds, true)));
        $rows[EXTRACTION_TABLES[1]] = array_values(array_filter($rows[EXTRACTION_TABLES[1]], fn ($row) => in_array($row['uuid'], $uuids, true)));
        extractionCheck(count($rows[EXTRACTION_TABLES[1]]) === count($uuids), 'Missing selected source rows.');
        return ['identity' => $identity, 'manifest' => ['context' => $context, 'area_ids' => $areaIds,
            'uuids' => array_column($rows[EXTRACTION_TABLES[1]], 'uuid'),
            'scopes' => array_map(fn ($branch) => [$context['tenant_ref'], $branch], $context['branch_refs']),
            'history_area' => $historyArea, 'removed' => $removed], 'schema' => extractionSchema(), 'rows' => $rows];
    });
    extractionValidate($snapshot);
    extractionWrite('/proof/snapshot.json', $snapshot);
    echo "PASS: fixed current-run manifest exported in a read-only snapshot; source mutations blocked.\n";
} elseif ($command === 'source-check') {
    extractionCheck(!$target, 'Source check requires source.');
    extractionCheck(extractionState() === json_decode(file_get_contents('/proof/source-before.json'), true, 512, JSON_THROW_ON_ERROR), 'Source circularity data changed.');
    echo "PASS: every source circularity row and provider migration row remains unchanged.\n";
} else {
    extractionCheck($target, 'Target command requires independent target.');
    extractionCheck(array_keys($plugins->getPlugins()) === ['Vmod.Circularity'], 'Unexpected plugin inventory.');
    foreach (['Vmod\\Core\\Classes\\Workspace', 'Vmod\\Erp\\Plugin', 'Vmod\\CircularityUi\\Plugin', 'RainLab\\User\\Plugin'] as $class) {
        extractionCheck(!class_exists($class), 'Forbidden application class available.');
    }
    foreach (['.env', '.git', 'themes/vmod', 'plugins/vmod/core', 'plugins/vmod/erp', 'plugins/vmod/circularityui', 'plugins/rainlab', 'storage/framework/compiled.php'] as $path) {
        extractionCheck(!file_exists('/var/www/html/' . $path), 'Forbidden application file available.');
    }
    if ($command === 'init') {
        extractionCheck($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]) === 0, 'Target migration failed.');
        echo "PASS: native framework/provider migrations on independent MySQL.\n";
        $GLOBALS['extractionCompleted'] = true;
        exit;
    }
    if ($command === 'health') {
        $response = Http::acceptJson()->withToken(getenv('CIRCULARITY_API_TOKEN'))->timeout(15)
            ->get('http://nginx-tech:8081/api/circularity/v1/health');
        extractionCheck(
            $response->status() === 200 && $response->json('status') === 'ok',
            'Native target health failed: ' . $response->status() . ' ' . substr($response->body(), 0, 300)
        );
        echo "PASS: independent native index.php serves authenticated HTTP.\n";
        $GLOBALS['extractionCompleted'] = true;
        exit;
    }
    $snapshot = json_decode(file_get_contents('/transfer/snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
    extractionCheck($identity['server_uuid'] !== $snapshot['identity']['server_uuid'], 'Source and target are the same server.');
    foreach (DB::select('SHOW TABLES') as $table) {
        $name = array_values((array) $table)[0];
        extractionCheck(in_array($name, ['cache', 'sessions', 'deferred_bindings', 'jobs', 'failed_jobs', 'migrations'], true)
            || preg_match('/^(system_|backend_|cms_|dashboard_|media_|editor_|tailor_|vmod_circularity_)/', $name) === 1, 'Foreign table: ' . $name);
    }
    if ($command === 'import') {
        $before = extractionState();
        $bad = $snapshot;
        $bad['rows']['foreign_table'] = [];
        extractionReject(fn () => extractionImport($bad), 'Invalid transfer table allowlist.');
        $bad = $snapshot;
        $bad['rows'][EXTRACTION_TABLES[0]][0]['tenant_ref'] = 'foreign';
        extractionReject(fn () => extractionImport($bad), 'Foreign fixture scope.');
        extractionReject(fn () => extractionImport($snapshot, true), 'Observed rollback after area insertion.');
        extractionCheck(extractionState() === $before, 'Failed import did not roll back.');
        extractionImport($snapshot);
        extractionReject(fn () => extractionImport($snapshot), 'Target must be empty.');
        extractionCheck(extractionState() === $snapshot['rows'], 'Raw rows differ after import.');
        extractionCheck(extractionSchema() === $snapshot['schema'], 'Schema differs after import.');
        extractionCheck($app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]) === 0, 'Second migration failed.');
        extractionCheck(extractionState() === $snapshot['rows'], 'Migration replay changed provider data or history.');
        extractionWrite('/evidence/transfer.json', ['source' => $snapshot['identity'], 'target' => $identity,
            'rows' => array_map('count', $snapshot['rows']), 'sha256' => hash('sha256', json_encode($snapshot['rows'])),
            'schema_equal' => true, 'raw_rows_equal' => true, 'migration_noop' => true, 'rejections' => 4,
            'plugins' => array_keys($plugins->getPlugins()), 'tables' => array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES'))]);
        echo "PASS: exact transfer, four rejection/rollback checks, schema/index parity and migration no-op.\n";
    } elseif ($command === 'snapshot') {
        echo json_encode(['entries' => extractionRows(EXTRACTION_TABLES[1])], JSON_THROW_ON_ERROR);
    } elseif ($command === 'contract') {
        $http = Http::acceptJson()->withToken(getenv('CIRCULARITY_API_TOKEN'))
            ->withHeaders(['X-Circularity-Context' => json_encode($snapshot['manifest']['context'])])->timeout(15);
        $api = 'http://nginx-tech:8081/api/circularity/v1';
        extractionCheck(Http::acceptJson()->get($api . '/health')->status() === 401, 'Target missing credential accepted.');
        extractionCheck(Http::acceptJson()->withToken('invalid')->get($api . '/health')->status() === 401, 'Target invalid credential accepted.');
        $health = $http->get($api . '/health');
        extractionCheck($health->status() === 200 && !$health->header('Set-Cookie'), 'Target health is not stateless.');
        $readOnly = array_replace($snapshot['manifest']['context'], ['permissions' => ['circularity.read']]);
        $denied = Http::acceptJson()->withToken(getenv('CIRCULARITY_API_TOKEN'))->withHeaders(['X-Circularity-Context' => json_encode($readOnly)]);
        extractionCheck($denied->post($api . '/entries', $snapshot['manifest']['removed'])->status() === 403, 'Target read-only write accepted.');
        extractionCheck($http->get($api . '/summary', ['branch_ref' => 'foreign-court'])->status() === 404, 'Target foreign court accepted.');
        $historical = ['area_id' => $snapshot['manifest']['history_area'], 'measurement_date' => '2026-01-01'];
        $list = $http->get($api . '/entries', $historical);
        extractionCheck($list->status() === 200 && $list->json('total') === 26 && count($list->json('data')) === 25, 'Historical pagination failed.');
        extractionCheck(count($http->get($api . '/entries', $historical + ['page' => 2])->json('data')) === 1, 'Second page failed.');
        extractionCheck($http->get($api . '/summary', $historical)->json('totals.mixed_waste.quantity') === '53.125', 'Inactive historical summary failed.');
        extractionCheck($http->post($api . '/entries', $snapshot['manifest']['removed'])->status() === 409, 'Imported removed UUID resurrected.');
        $area = $http->post($api . '/areas', ['branch_ref' => $snapshot['manifest']['context']['branch_refs'][0], 'name' => 'Target only area ' . bin2hex(random_bytes(4)), 'kind' => 'stall']);
        extractionCheck($area->status() === 201 && $area->json('area.id') > max($snapshot['manifest']['area_ids']), 'Target area ID did not advance.');
        $payload = array_replace($snapshot['manifest']['removed'], ['uuid' => (string) Str::uuid(), 'area_id' => $area->json('area.id'), 'quantity' => '1.125']);
        extractionCheck($http->post($api . '/entries', $payload)->status() === 201, 'Target create failed.');
        extractionCheck($http->post($api . '/entries', $payload)->status() === 200, 'Target replay failed.');
        $row = (array) DB::table(EXTRACTION_TABLES[1])->where('uuid', $payload['uuid'])->first();
        extractionCheck($row['id'] > max(array_column($snapshot['rows'][EXTRACTION_TABLES[1]], 'id')), 'Target entry ID did not advance.');
        extractionCheck($http->patch($api . '/entries/' . $payload['uuid'], ['version' => 1, 'quantity' => '2.125'])->json('entry.version') === 2, 'Target edit failed.');
        extractionCheck($http->patch($api . '/entries/' . $payload['uuid'], ['version' => 1, 'quantity' => '3'])->status() === 409, 'Target stale edit accepted.');
        extractionCheck($http->delete($api . '/entries/' . $payload['uuid'], ['version' => 2])->json('entry.status') === 'removed', 'Target removal failed.');
        extractionCheck($http->post($api . '/entries', $payload)->status() === 409, 'Target removed UUID resurrected.');
        $context = $snapshot['manifest']['context'];
        $context['tenant_ref'] = 'unrelated-tenant';
        $http->replaceHeaders(['X-Circularity-Context' => json_encode($context)]);
        extractionCheck($http->get($api . '/entries/' . $payload['uuid'])->status() === 404, 'Target cross-tenant lookup leaked.');
        extractionCheck($http->get($api . '/summary', $historical)->status() === 404, 'Target cross-tenant area leaked.');
        echo "PASS: native target HTTP pagination, inactive history, exact summary, CRUD, IDs, replay, stale writes and scope isolation.\n";
    } else {
        throw new RuntimeException('Unknown extraction command.');
    }
}

$GLOBALS['extractionCompleted'] = true;
