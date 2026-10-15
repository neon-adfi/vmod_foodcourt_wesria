# Wesria application tests

Use the guarded application suite in `phpunit-vmod.xml` through the PHP 8.2 `php-test` service described in [runtime instructions](../docs/runtime-phase-1.md). Extend `Vmod\Tests\ApplicationTestCase`; plugin PHPUnit files use `../../../tests/vmod/bootstrap.php`. It checks explicit disposable MySQL ownership before boot and rechecks resolved configuration before migrations. Do not use this root legacy plugin bootstrap for Vmod auth tests: October's `PluginTestCase::createApplication()` replaces native frontend auth with backend auth.

`composer test:vmod` discovers foundation and Vmod plugin tests, then runs `tests/vmod/check-core-concurrency.php`. That standalone guarded check synchronizes independent PHP processes against disposable MySQL for last-admin changes, concurrent branches, invitation replacement/acceptance and cross-company native account creation. `composer test:framework` uses the separate October fixture/SQLite suite. `composer test` runs both. Framework SQLite results are never locking evidence.

Phase 2 browser acceptance uses `tests/e2e/seed-phase2.php` in the owned **local** PHP runtime, writing its synthetic secrets to a mode-0600 file at `/private/tmp/wesria-phase2-acceptance.json`. Then run `ego-browser nodejs < tests/e2e/phase2.mjs` on the host. The runner creates one TaskSpace for a new run; supply `globalThis.vmodE2eSpaceId` to resume that same space after a failure. This acceptance used space 22 throughout. Never print the fixture or mailbox tokens. Screenshots and sanitized results go under ignored `output/ego-lite/phase-2`; visually inspect them before finishing the space. See [runtime commands](../docs/runtime-phase-1.md) and [Phase 2 evidence](../docs/evidence/phase-2-core-2026-10-15.md).

The historical October instructions below describe the framework test base. Their old `useConfigForTesting` option and `october:up` command do not apply to October 4.3; current migrations use `october:migrate`, and the guarded application suite uses explicit environment connection settings.

---

# Plugin testing

Plugin unit tests can be performed by running `phpunit` in the base plugin directory.

### Creating plugin tests

Plugins can be tested by creating a file called `phpunit.xml` in the base directory with the following content, for example, in a file **/plugins/acme/blog/phpunit.xml**:

    <?xml version="1.0" encoding="UTF-8"?>
    <phpunit backupGlobals="false"
             backupStaticAttributes="false"
             bootstrap="../../../tests/bootstrap.php"
             colors="true"
             convertErrorsToExceptions="true"
             convertNoticesToExceptions="true"
             convertWarningsToExceptions="true"
             processIsolation="false"
             stopOnFailure="false"
             syntaxCheck="false"
    >
        <testsuites>
            <testsuite name="Plugin Unit Test Suite">
                <directory>./tests</directory>
            </testsuite>
        </testsuites>
        <php>
            <env name="APP_ENV" value="testing"/>
            <env name="CACHE_DRIVER" value="array"/>
            <env name="SESSION_DRIVER" value="array"/>
        </php>
    </phpunit>

Then a **tests/** directory can be created to contain the test classes. The file structure should mimic the base directory with classes having a `Test` suffix. Using a namespace for the class is also recommended.

    <?php namespace Acme\Blog\Tests\Models;

    use Acme\Blog\Models\Post;
    use PluginTestCase;

    class PostTest extends PluginTestCase
    {
        public function testCreateFirstPost()
        {
            $post = Post::create(['title' => 'Hi!']);
            $this->assertEquals(1, $post->id);
        }
    }

The test class should extend the base class `PluginTestCase` and this is a special class that will set up the October database stored in memory, as part of the `setUp` method. It will also refresh the plugin being tested, along with any of the defined dependencies in the plugin registration file. This is the equivalent of running the following before each test:

    php artisan october:up
    php artisan plugin:refresh Acme.Blog
    [php artisan plugin:refresh <dependency>, ...]

> **Note:** If your plugin uses [configuration files](../plugin/settings#file-configuration), then you will need to run `System\Classes\PluginManager::instance()->registerAll(true);` in the `setUp` method of your tests. Below is an example of a base test case class that should be used if you need to test your plugin working with other plugins instead of in isolation.

    use System\Classes\PluginManager;

    class BaseTestCase extends PluginTestCase
    {
        public function setUp(): void
        {
            parent::setUp();

            // Get the plugin manager
            $pluginManager = PluginManager::instance();

            // Register the plugins to make features like file configuration available
            $pluginManager->registerAll(true);

            // Boot all the plugins to test with dependencies of this plugin
            $pluginManager->bootAll(true);
        }

        public function tearDown(): void
        {
            parent::tearDown();

            // Get the plugin manager
            $pluginManager = PluginManager::instance();

            // Ensure that plugins are registered again for the next test
            $pluginManager->unregisterAll();
        }
    }

#### Changing database engine for plugins tests

By default OctoberCMS uses SQLite stored in memory for the plugin testing environment. If you want to override the default behavior set the `useConfigForTesting` config to `true` in your `/config/database.php` file. When the `APP_ENV` is `testing` and the `useConfigForTesting` is `true` database parameters will be taken from `/config/database.php`.

You can override the `/config/database.php` file by creating `/config/testing/database.php`. In this case variables from the latter file will be taken.

## System testing

To perform unit testing on the core October files, you should download a development copy using composer or cloning the git repo. This will ensure you have the `tests/` directory.

### Unit tests

Unit tests can be performed by running `phpunit` in the root directory or inside `/tests/unit`.
