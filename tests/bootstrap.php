<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin as Kirby loads it: class map, legacy names and registration.
require_once dirname(__DIR__) . '/index.php';

require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
