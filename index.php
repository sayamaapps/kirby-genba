<?php

/**
 * Plugin registration for Genba (現場 — the place where the work actually happens):
 * operations of a deployed Kirby 5 site.
 *
 * Kirby loads this file. It registers the class map, the legacy names and the
 * definitions in config/. The logic lives in src/ (SPEC.md §1, §3). Off by
 * default: without `'genba.active' => true` the site methods return null and
 * the snippets print nothing.
 */

use Kirby\Filesystem\F;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

Kirby::plugin(
	name: 'sayamaapps/genba',
	extends: [
		'translations' => require __DIR__ . '/config/translations.php',
		'snippets'     => require __DIR__ . '/config/snippets.php',
		'siteMethods'  => require __DIR__ . '/config/site-methods.php',
	],
	root: __DIR__
);
