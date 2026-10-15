<?php

// A config cache would override PHPUnit's forced in-memory database selection.
foreach ([getenv(), $_SERVER, $_ENV] as $source) {
    if (isset($source['APP_CONFIG_CACHE']) && $source['APP_CONFIG_CACHE'] !== '/tmp/wesria-framework-config.php') {
        throw new RuntimeException('Framework tests require their isolated config path.');
    }
}
if (is_file('/tmp/wesria-framework-config.php')) {
    throw new RuntimeException('Framework tests refuse cached application configuration.');
}
require __DIR__ . '/../modules/system/tests/bootstrap.php';
