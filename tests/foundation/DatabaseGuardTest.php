<?php namespace Vmod\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseGuardTest extends TestCase
{
    private function safeConfig(): array
    {
        return [
            'environment' => 'testing', 'owner' => 'vmod_wesria_phase1',
            'driver' => 'mysql', 'host' => 'mysql-test', 'port' => '3306',
            'database' => 'vmod_wesria_test', 'username' => 'vmod_wesria_test',
            'password' => 'synthetic-test-secret', 'url' => '', 'unix_socket' => '',
            'config_cache' => '/tmp/wesria-test-config.php',
        ];
    }

    public function testOwnedMysqlConfigurationIsAccepted(): void
    {
        DatabaseGuard::assertSafe($this->safeConfig(), false);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider unsafeConfiguration */
    public function testUnsafeTargetsFailClosed(string $key, string $value): void
    {
        $config = $this->safeConfig();
        $config[$key] = $value;
        $this->expectException(RuntimeException::class);
        DatabaseGuard::assertSafe($config, false);
    }

    public static function unsafeConfiguration(): array
    {
        return [
            ['environment', 'local'], ['owner', ''], ['driver', 'sqlite'],
            ['host', 'mysql'], ['port', '3307'], ['database', 'foodywesria'],
            ['database', 'vmod_wesria_dev'], ['database', ''], ['username', 'root'],
            ['password', ''], ['url', 'mysql://root@example/foodywesria'],
            ['unix_socket', '/var/run/mysql.sock'],
            ['config_cache', ''], ['config_cache', '/tmp/unsafe-config.php'],
        ];
    }

    public function testCachedConfigurationIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseGuard::assertSafe($this->safeConfig(), true);
    }

    /** @dataProvider conflictingSources */
    public function testConflictingEnvironmentSourcesAreRejected(string $source): void
    {
        $previous = $GLOBALS[$source]['DB_DATABASE'] ?? null;
        $GLOBALS[$source]['DB_DATABASE'] = 'foodywesria';
        try {
            $this->expectException(RuntimeException::class);
            DatabaseGuard::assertEnvironment();
        } finally {
            if ($previous === null) {
                unset($GLOBALS[$source]['DB_DATABASE']);
            } else {
                $GLOBALS[$source]['DB_DATABASE'] = $previous;
            }
        }
    }

    public static function conflictingSources(): array
    {
        return [['_SERVER'], ['_ENV']];
    }
}
