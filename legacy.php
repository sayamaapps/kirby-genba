<?php

/**
 * Names of the API before the Sayamaapps\Genba namespace, kept until the next major version (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered; bin/genba-smoke-read
 * loads it too, after the class map as well. The old class names are aliases,
 * created here, eagerly: instanceof and catch do not autoload, so an alias made
 * on first use would be missing exactly when the old name is checked first.
 * The old function behaves as in 1.x: no runtime deprecation, only the
 * @deprecated tag and the README.
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

	// Eager: class_alias() autoloads the class, so the classes come from the class map.
	class_alias(Homepage::class, 'Genba\\Homepage');
	class_alias(I18n::class, 'Genba\\I18n');
	class_alias(Links::class, 'Genba\\Links');
	class_alias(Log::class, 'Genba\\Log');
	class_alias(Options::class, 'Genba\\Options');
	class_alias(Precheck::class, 'Genba\\Precheck');
	class_alias(Preflight::class, 'Genba\\Preflight');
	class_alias(Stamp::class, 'Genba\\Stamp');
	class_alias(Text::class, 'Genba\\Text');
	class_alias(Tree::class, 'Genba\\Tree');

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
