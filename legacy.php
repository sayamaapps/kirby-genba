<?php

/**
 * Names of the API before the Sayamaapps\Genba namespace, kept until the next major version (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered; bin/genba-smoke-read
 * loads it too. The old class names are aliases, created when first used; nothing
 * is loaded before that. The old function behaves as in 1.x: no runtime
 * deprecation, only the @deprecated tag and the README.
 */

namespace Genba {
	/**
	 * Raises the memory limit of the homepage reader.
	 *
	 * Lives in the 1.x namespace on purpose: when ini_set() is disabled, PHP's
	 * fatal error names the namespace of the calling code, and 1.x printed
	 * "Call to undefined function Genba\ini_set()". Kept until the next major version.
	 *
	 * @internal
	 */
	function raise_memory_limit(string $limit): void
	{
		ini_set('memory_limit', $limit);
	}
}

namespace {
	use Kirby\Cms\App;
	use Sayamaapps\Genba\Homepage;
	use Sayamaapps\Genba\I18n;
	use Sayamaapps\Genba\Links;
	use Sayamaapps\Genba\Log;
	use Sayamaapps\Genba\Options;
	use Sayamaapps\Genba\Precheck;
	use Sayamaapps\Genba\Preflight;
	use Sayamaapps\Genba\Stamp;
	use Sayamaapps\Genba\Text;
	use Sayamaapps\Genba\Tree;

	spl_autoload_register(static function (string $class): void {
		/** @var array<string, class-string> */
		static $aliases = [
			'Genba\\Homepage'  => Homepage::class,
			'Genba\\I18n'      => I18n::class,
			'Genba\\Links'     => Links::class,
			'Genba\\Log'       => Log::class,
			'Genba\\Options'   => Options::class,
			'Genba\\Precheck'  => Precheck::class,
			'Genba\\Preflight' => Preflight::class,
			'Genba\\Stamp'     => Stamp::class,
			'Genba\\Text'      => Text::class,
			'Genba\\Tree'      => Tree::class,
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
		return Options::get(App::instance(), $key, $default);
	}
}
