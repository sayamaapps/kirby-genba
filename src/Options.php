<?php

namespace Sayamaapps\Genba;

use Kirby\Cms\App;
use Kirby\Cms\Helpers;

/**
 * Reads the options of the Kirby part of the plugin (SPEC.md §4): `sayamaapps.genba.<key>`,
 * falling back to the 2.x name `genba.<key>` (OPT-1). The fallback is reported as a deprecation.
 *
 * `null` counts as "not set", like Kirby's own option() (A::get() checks
 * isset() as well), so `'sayamaapps.genba.stamp.meta' => null` falls back to the default.
 *
 * Both spellings work, flat (`'sayamaapps.genba.stamp.file' => '…'`) and nested
 * (`'sayamaapps' => ['genba' => ['stamp' => ['file' => '…']]]`), because Kirby's option() finds
 * both.
 *
 * Defaults are given here and at the call sites. The `options` block of the plugin lists
 * the keys with null: a default there would hide the 2.x spelling.
 *
 * The command-line tools never load this class: they run without Kirby and
 * take their settings from environment variables and arguments (SPEC.md §3.0).
 */
final class Options
{
	/** Name of the <meta> element the stamp snippet prints; the smoke test and an external check read it. */
	public const DEFAULT_META = 'build';

	/** Where the deploy writes the commit hash, relative to the project root (SPEC.md §6). */
	public const DEFAULT_STAMP_FILE = 'site/config/build.txt';

	/** Option prefix since 2.x (OPT-1). */
	public const PREFIX = 'sayamaapps.genba.';

	/** Option prefix of 1.x, read as a fallback until the next major version. */
	public const LEGACY_PREFIX = 'genba.';

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
		return $kirby->root('base') ?? dirname($kirby->root('site'));
	}

	/**
	 * `sayamaapps.genba.<key>`; when it is not set, `genba.<key>` (deprecated), otherwise $default.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		$legacy = $kirby->option(self::LEGACY_PREFIX . $key);
		if ($legacy !== null) {
			Helpers::deprecated(
				'Genba: the option genba.' . $key . ' is deprecated, use sayamaapps.genba.' . $key,
				'option-prefix'
			);
			return $legacy;
		}

		return $default;
	}

	/**
	 * A broken option: with Kirby's `debug` option on it throws, so the
	 * developer sees it on the page; otherwise it is logged once and the
	 * caller uses the default.
	 */
	public static function invalid(App $kirby, string $message): void
	{
		if ($kirby->option('debug') === true) {
			throw new \InvalidArgumentException($message);
		}
		Log::error($message);
	}

	/**
	 * Language of the plugin's text: `genba.language`, otherwise the current
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
	 * `genba.stamp.meta`: the name attribute of the stamp's <meta> element,
	 * default "build". Only [A-Za-z0-9_-] is accepted; anything else is
	 * reported and the default is used (the value lands unescaped in HTML).
	 */
	public static function metaName(App $kirby): string
	{
		$name = self::get($kirby, 'stamp.meta', self::DEFAULT_META);
		if (is_string($name) && preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1) {
			return $name;
		}
		self::invalid($kirby, 'Genba: genba.stamp.meta must match [A-Za-z0-9_-]+ — using ' . self::DEFAULT_META . '.');
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
	 * Absolute path of the stamp file. `genba.stamp.file` may be absolute or
	 * relative to the project root (NOT to the working directory, which is
	 * public/ under Apache). Default: site/config/build.txt.
	 */
	public static function stampFile(App $kirby): string
	{
		$option = self::get($kirby, 'stamp.file', self::DEFAULT_STAMP_FILE);
		if (!is_string($option) || trim($option) === '') {
			self::invalid($kirby, 'Genba: genba.stamp.file must be a non-empty path — using ' . self::DEFAULT_STAMP_FILE . '.');
			$option = self::DEFAULT_STAMP_FILE;
		}
		return self::resolve(trim($option), self::base($kirby));
	}
}
