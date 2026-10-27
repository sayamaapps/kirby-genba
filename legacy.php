<?php

/**
 * Names of the API before the Sayamaapps\Genba namespace, kept until the next major version (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. The old class
 * names are aliases, created when first used; nothing is loaded before that.
 * The old function behaves as in 1.x: no runtime deprecation, only the
 * @deprecated tag and the README.
 */

spl_autoload_register(static function (string $class): void {
	/** @var array<string, class-string> */
	static $aliases = [
		'Genba\\Homepage'  => \Sayamaapps\Genba\Homepage::class,
		'Genba\\I18n'      => \Sayamaapps\Genba\I18n::class,
		'Genba\\Links'     => \Sayamaapps\Genba\Links::class,
		'Genba\\Log'       => \Sayamaapps\Genba\Log::class,
		'Genba\\Options'   => \Sayamaapps\Genba\Options::class,
		'Genba\\Precheck'  => \Sayamaapps\Genba\Precheck::class,
		'Genba\\Preflight' => \Sayamaapps\Genba\Preflight::class,
		'Genba\\Stamp'     => \Sayamaapps\Genba\Stamp::class,
		'Genba\\Text'      => \Sayamaapps\Genba\Text::class,
		'Genba\\Tree'      => \Sayamaapps\Genba\Tree::class,
	];

	if (isset($aliases[$class])) {
		class_alias($aliases[$class], $class);
	}
});

/**
 * Reads `genba.<key>`.
 *
 * @deprecated Use Sayamaapps\Genba\Options::get($kirby, $key) instead. Removed in the next major version.
 */
function genba_option(string $key, mixed $default = null): mixed
{
	return Sayamaapps\Genba\Options::get(Kirby\Cms\App::instance(), $key, $default);
}
