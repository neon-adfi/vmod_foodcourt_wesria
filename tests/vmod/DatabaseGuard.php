<?php namespace Vmod\Tests;

use RuntimeException;

final class DatabaseGuard
{
    public static function assertSafe(array $config, bool $cached): void
    {
        // This fixed identity owns one disposable service; parallel suites need separate services and identities.
        $required = [
            'environment' => 'testing', 'owner' => 'vmod_wesria_phase1',
            'driver' => 'mysql', 'host' => 'mysql-test', 'port' => '3306',
            'database' => 'vmod_wesria_test', 'username' => 'vmod_wesria_test',
            'url' => '', 'unix_socket' => '',
            'config_cache' => '/tmp/wesria-test-config.php',
        ];
        foreach ($required as $key => $value) {
            if ((string) ($config[$key] ?? '') !== $value) {
                throw new RuntimeException('Vmod tests require the explicitly owned disposable MySQL configuration.');
            }
        }
        if ($cached || empty($config['password'])) {
            throw new RuntimeException('Vmod tests require uncached configuration and explicit test credentials.');
        }
    }

    public static function assertEnvironment(): void
    {
        $keys = [
            'environment' => 'APP_ENV', 'owner' => 'VMOD_TEST_DATABASE_OWNER',
            'driver' => 'DB_CONNECTION', 'host' => 'DB_HOST', 'port' => 'DB_PORT',
            'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME',
            'password' => 'DB_PASSWORD', 'url' => 'DB_URL', 'unix_socket' => 'DB_SOCKET',
            'config_cache' => 'APP_CONFIG_CACHE',
        ];
        $config = [];
        foreach ($keys as $key => $variable) {
            $value = getenv($variable);
            if ($value === false) {
                throw new RuntimeException('Vmod tests require explicit environment settings without .env fallbacks.');
            }
            $config[$key] = $value;
            // Laravel can read these ahead of getenv; reject conflicting targets before it boots.
            foreach ([$_SERVER, $_ENV] as $source) {
                if (isset($source[$variable]) && (string) $source[$variable] !== $config[$key]) {
                    throw new RuntimeException('Conflicting Vmod test environment sources.');
                }
            }
        }
        self::assertSafe($config, is_file($config['config_cache']));
    }
}
