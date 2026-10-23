<?php

namespace Genba;

/**
 * Strings of the Kirby part of the plugin, Kirby-free (SPEC.md §5).
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `genba.<key>` (index.php), so a site's own
 * snippet can use t('genba.build.label'). Genba itself reads its own tables:
 * a missing Kirby translation must never silently turn a German label English.
 *
 * Only the Kirby part (snippets genba/stamp and genba/build-info) is
 * translated. The command-line tools print English only — their output is
 * CI log text, read by GitHub's annotations and by the person who deploys,
 * and the smoke test is a bash script that could not share these tables
 * (SPEC.md §11).
 *
 * The language is set once per request (Options::applyLanguage()) and
 * defaults to English. Missing keys fall back to English, then to the key.
 */
final class I18n
{
    /** Languages Genba ships. English is the default and the fallback. */
    public const LANGUAGES = ['en', 'de'];

    private static string $language = 'en';

    /** @var array<string, array<string,string>> */
    private static array $tables = [];

    public static function setLanguage(string $language): void
    {
        self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
    }

    public static function language(): string
    {
        return self::$language;
    }

    /** @return array<string,string> */
    public static function table(string $language): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }
        return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
    }

    /** `{name}` placeholders are replaced with the given values, unescaped. */
    public static function t(string $key, array $values = []): string
    {
        $text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
        if ($values === []) {
            return $text;
        }
        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $pairs);
    }
}
