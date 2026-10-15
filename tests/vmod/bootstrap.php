<?php

require_once __DIR__ . '/DatabaseGuard.php';
\Vmod\Tests\DatabaseGuard::assertEnvironment();
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/ApplicationTestCase.php';
