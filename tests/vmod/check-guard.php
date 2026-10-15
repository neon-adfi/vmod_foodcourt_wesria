<?php

// Independent PHP processes prove rejected targets never reach the application autoloader.
$environment = [
    'APP_ENV' => 'testing', 'VMOD_TEST_DATABASE_OWNER' => 'vmod_wesria_phase1',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'mysql-test', 'DB_PORT' => '3306',
    'DB_DATABASE' => 'vmod_wesria_test', 'DB_USERNAME' => 'vmod_wesria_test',
    'DB_PASSWORD' => 'synthetic-unused-password', 'DB_URL' => '', 'DB_SOCKET' => '',
    'APP_CONFIG_CACHE' => '/tmp/wesria-test-config.php',
];
$cases = [
    'missing ownership' => ['VMOD_TEST_DATABASE_OWNER' => ''],
    'development schema' => ['DB_DATABASE' => 'vmod_wesria_dev'],
    'existing schema' => ['DB_DATABASE' => 'foodywesria'],
    'URL override' => ['DB_URL' => 'mysql://root@example/foodywesria'],
    'socket override' => ['DB_SOCKET' => '/tmp/mysql.sock'],
    'missing URL setting' => ['DB_URL' => null],
    'missing socket setting' => ['DB_SOCKET' => null],
    'cached configuration' => [],
    'framework cached configuration' => ['APP_CONFIG_CACHE' => '/tmp/wesria-framework-config.php'],
    'server conflict' => [],
    'env conflict' => [],
];
foreach ($cases as $name => $overrides) {
    $cache = $overrides['APP_CONFIG_CACHE'] ?? $environment['APP_CONFIG_CACHE'];
    if (file_exists($cache)) {
        throw new RuntimeException('Refusing to replace an existing configuration cache.');
    }
    $cached = str_contains($name, 'cached configuration');
    $bootstrap = $name === 'framework cached configuration' ? 'tests/framework-bootstrap.php' : 'tests/vmod/bootstrap.php';
    $prefix = $name === 'server conflict' ? '$_SERVER["DB_DATABASE"] = "foodywesria";' : '';
    $prefix .= $name === 'env conflict' ? '$_ENV["DB_DATABASE"] = "foodywesria";' : '';
    $code = $prefix . 'try { require ' . var_export($bootstrap, true) . '; exit(2); }
        catch (RuntimeException $e) {
            if (class_exists("Composer\\Autoload\\ClassLoader", false)) { exit(3); }
            echo "BLOCKED_BEFORE_BOOT";
        }';
    try {
        if ($cached) {
            file_put_contents($cache, '<?php throw new RuntimeException("Unsafe cache executed");');
        }
        $childEnvironment = array_filter(array_replace($environment, $overrides), static fn($value) => $value !== null);
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $childEnvironment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start independent guard check.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $output !== 'BLOCKED_BEFORE_BOOT' || $error !== '') {
            throw new RuntimeException('Guard did not reject before boot: ' . $name);
        }
    } finally {
        if ($cached && file_exists($cache)) {
            unlink($cache);
        }
    }
}
echo count($cases) . " unsafe targets rejected before application boot.\n";
