<?php

namespace Sayamaapps\Genba;

use InvalidArgumentException;
use Kirby\Cms\App;

/**
 * Reads Genba's options: `sayamaapps.genba.<key>`, falling back to the 1.x name
 * `genba.<key>` (docs/SPEC.md §4).
 *
 * `null` counts as "not set", like Kirby's own option() (A::get() checks
 * isset() as well), so `'sayamaapps.genba.stamp.meta' => null` falls back to the default.
 *
 * The flat form (`'sayamaapps.genba.active' => true`) is the one to use. The nested
 * form (`'genba' => ['active' => true]`) works for the 1.x prefix only: Kirby resolves
 * `sayamaapps.genba.*` through the plugin's own options block first, which shadows a
 * nested `sayamaapps` array.
 *
 * Defaults are given here and at the call sites. The `options` block of the
 * plugin (config/options.php) lists the keys with null: a default there would
 * hide the 1.x spelling.
 *
 * The command-line tools never load this class: they run without Kirby and
 * take their settings from environment variables and arguments (docs/SPEC.md §3.0).
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Options
{
	/** Name of the <meta> element the stamp snippet prints; the smoke test and an external check read it. */
	public const DEFAULT_META = 'build';

	/** Where the deploy writes the commit hash, relative to the project root (docs/SPEC.md §6). */
	public const DEFAULT_STAMP_FILE = 'site/config/build.txt';

	/** Option prefix since 1.9. */
	public const OPTION_PREFIX = 'sayamaapps.genba.';

	/**
	 * Option prefix of 1.x (`genba.<key>`). Still read as a fallback until 2.0.
	 *
	 * @deprecated 1.9 use OPTION_PREFIX; removal planned for 2.0
	 */
	public const PREFIX = 'genba.';

	/** Master switch: only a real `true` switches the Kirby part on. */
	public static function active(App $kirby): bool
	{
		return self::get($kirby, 'active', false) === true;
	}

	/** Sets I18n's language for this request from language(). Called before any text is produced. */
	public static function applyLanguage(App $kirby): void
	{
		I18n::setLanguage(self::language($kirby));
	}

	/** Project root: Kirby's `base` root, otherwise the directory above `site/`. */
	public static function base(App $kirby): string
	{
		/** @psalm-suppress PossiblyNullArgument A Kirby app always has a site root. */
		return $kirby->root('base') ?? dirname($kirby->root('site'));
	}

	/**
	 * Reads an option under the sayamaapps.genba.* spelling, then under the
	 * 1.x genba.* spelling, which is read silently (README, Deprecations).
	 * Returns $default when neither is set.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::OPTION_PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		return $kirby->option(self::PREFIX . $key) ?? $default;
	}

	/**
	 * A broken option: with Kirby's `debug` option on it throws, so the
	 * developer sees it on the page; otherwise it is logged once and the
	 * caller uses the default.
	 */
	public static function invalid(App $kirby, string $message): void
	{
		if ($kirby->option('debug') === true) {
			throw new InvalidArgumentException($message);
		}
		Log::error($message);
	}

	/**
	 * Language of the plugin's text: `sayamaapps.genba.language`, otherwise the current
	 * language of a multi-language site, otherwise its default language,
	 * otherwise the first two letters of Kirby's `locale` option, otherwise
	 * English. Only languages Genba ships (I18n::LANGUAGES) are returned.
	 */
	public static function language(App $kirby): string
	{
		$candidates = [self::get($kirby, 'language')];
		try {
			$candidates[] = $kirby->language()?->code();
			$candidates[] = $kirby->defaultLanguage()?->code();
		} catch (\Throwable) {
			// No languages configured.
		}
		$locale = $kirby->option('locale');
		if (is_array($locale)) {
			$locale = $locale[LC_ALL] ?? reset($locale);
		}
		$candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
				return strtolower($candidate);
			}
		}
		return 'en';
	}

	/**
	 * `sayamaapps.genba.stamp.meta`: the name attribute of the stamp's <meta> element,
	 * default "build". Only [A-Za-z0-9_-] is accepted; anything else is
	 * reported and the default is used (the value lands unescaped in HTML).
	 */
	public static function metaName(App $kirby): string
	{
		$name = self::get($kirby, 'stamp.meta', self::DEFAULT_META);
		if (is_string($name) && preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1) {
			return $name;
		}
		self::invalid($kirby, 'Genba: ' . self::setKey($kirby, 'stamp.meta') . ' must match [A-Za-z0-9_-]+ — using ' . self::DEFAULT_META . '.');
		return self::DEFAULT_META;
	}

	/** Absolute path for $path: unchanged when absolute, otherwise under $base. */
	public static function resolve(string $path, string $base): string
	{
		if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1)) {
			return $path;
		}
		return rtrim($base, '/') . '/' . ltrim($path, '/');
	}

	/**
	 * The option name as the site spelled it, for messages: the 1.x genba.*
	 * name only when the site sets no sayamaapps.genba.* value for it.
	 */
	public static function setKey(App $kirby, string $key): string
	{
		if ($kirby->option(self::OPTION_PREFIX . $key) === null && $kirby->option(self::PREFIX . $key) !== null) {
			return self::PREFIX . $key;
		}
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * Absolute path of the stamp file. `sayamaapps.genba.stamp.file` may be absolute or
	 * relative to the project root (NOT to the working directory, which is
	 * public/ under Apache). Default: site/config/build.txt.
	 */
	public static function stampFile(App $kirby): string
	{
		$option = self::get($kirby, 'stamp.file', self::DEFAULT_STAMP_FILE);
		if (!is_string($option) || trim($option) === '') {
			self::invalid($kirby, 'Genba: ' . self::setKey($kirby, 'stamp.file') . ' must be a non-empty path — using ' . self::DEFAULT_STAMP_FILE . '.');
			$option = self::DEFAULT_STAMP_FILE;
		}
		return self::resolve(trim($option), self::base($kirby));
	}
}
