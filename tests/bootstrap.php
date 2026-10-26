<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin's classes, in the order index.php loads them.
foreach (['I18n', 'Log', 'Options', 'Stamp', 'Preflight', 'Text', 'Tree', 'Precheck', 'Links', 'Homepage'] as $class) {
	require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

require_once __DIR__ . '/helpers.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
