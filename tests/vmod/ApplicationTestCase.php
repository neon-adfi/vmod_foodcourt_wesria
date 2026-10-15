<?php namespace Vmod\Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use System\Classes\PluginManager;

abstract class ApplicationTestCase extends \PluginTestCase
{
    // createApplication registers the real application once; suppress per-plugin registration in setUp.
    protected $autoRegister = false;

    public function createApplication()
    {
        DatabaseGuard::assertEnvironment();
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $config = $app['config'];
        $connection = $config->get('database.connections.' . $config->get('database.default'), []);
        $connection['environment'] = $app->environment();
        $connection['owner'] = getenv('VMOD_TEST_DATABASE_OWNER');
        $connection['config_cache'] = $app->getCachedConfigPath();
        DatabaseGuard::assertSafe($connection, $app->configurationIsCached());
        if ($config->get('database.default') !== 'mysql') {
            throw new RuntimeException('Vmod tests require the mysql connection.');
        }
        if ($app['db']->connection()->getDatabaseName() !== 'vmod_wesria_test') {
            throw new RuntimeException('Unexpected resolved Vmod database.');
        }

        // PluginTestCase replaces auth with backend auth; use the normal application provider instead.
        PluginManager::instance()->registerAll(true);
        PluginManager::instance()->bootAll(true);
        return $app;
    }
}
