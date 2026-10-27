<?php

/**
 * Translations: every language has the same keys and placeholders as
 * English, no value is empty, and the helpers follow the language.
 */
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\I18n;

#[CoversClass(\Sayamaapps\Genba\I18n::class)]
final class I18nTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$placeholders = function (string $text): array {
				preg_match_all('/\{(\w+)\}/', $text, $m);
				$names = array_unique($m[1]);
				sort($names);
				return $names;
			};
			$en = I18n::table('en');
			holds(count($en) >= 4, 'English table loaded');
			foreach (I18n::LANGUAGES as $language) {
				$table = I18n::table($language);
				expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
				expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
				foreach ($en as $key => $text) {
					holds(isset($table[$key]) && is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
					expect($placeholders($text), $placeholders($table[$key] ?? ''), "$language.$key: same placeholders as English");
				}
			}
			expect([], I18n::table('fr'), 'unknown language: no table');
			// Every key the snippets rely on exists (docs/SPEC.md §5).
			foreach (['build.label', 'build.hash', 'build.writtenAt', 'build.none', 'panel.build.label', 'panel.build.info'] as $key) {
				holds(isset($en[$key]), "key $key exists");
			}
			// Language switching and placeholders.
			I18n::setLanguage('de');
			expect('de', I18n::language(), 'language set to de');
			expect('Stand abc1234', I18n::t('build.hash', ['short' => 'abc1234']), 'German with placeholder');
			I18n::setLanguage('xx');
			expect('en', I18n::language(), 'unknown language falls back to en');
			expect('Build abc1234', I18n::t('build.hash', ['short' => 'abc1234']), 'English with placeholder');
			expect('missing.key', I18n::t('missing.key'), 'missing key returns the key');
		} finally {
			ob_end_clean();
		}
	}
}
