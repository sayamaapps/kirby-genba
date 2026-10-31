<?php

/**
 * The Kirby part with real Kirby (docs/SPEC.md §9.3), in throwaway site directories:
 *  1. registered, off by default: site methods, snippets, genba_option();
 *  2. active with a valid stamp: genbaBuild(), genbaBuildInfo(), stamp snippet;
 *  3. active with 'main' or no file: nothing;
 *  4. nested spelling, custom stamp file and meta name, invalid meta name;
 *  5. build-info: en, de, withDate, label off, no stamp;
 *  6. translations, and I18n after Options::applyLanguage();
 *  7. a site's own snippets/genba/stamp.php wins.
 *
 * Kirby loads the plugin through the symlink genba_site() creates, so no src
 * file is required here.
 */
use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Runs $fn with a fresh throwaway site directory, removes it afterwards. */
function genbaWithSite(callable $fn): void
{
	$tmp = genba_site();
	try {
		$fn($tmp);
	} finally {
		App::destroy();
		genba_remove_tree($tmp);
	}
}

/** Runs $fn with the php error_log written to a file; returns what was logged. */
function genbaErrorLog(string $tmp, callable $fn): string
{
	$previous = ini_get('error_log');
	$file = $tmp . '/php-error.log';
	ini_set('error_log', $file);
	try {
		$fn();
	} finally {
		ini_set('error_log', (string)$previous);
	}
	return is_file($file) ? (string)file_get_contents($file) : '';
}

#[CoversClass(\Sayamaapps\Genba\Options::class)]
#[CoversClass(\Sayamaapps\Genba\I18n::class)]
#[CoversClass(\Sayamaapps\Genba\Stamp::class)]
final class KirbyTest extends TestCase
{
	protected function tearDown(): void
	{
		App::destroy();
		parent::tearDown();
	}

	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$hash = '0123456789abcdef0123456789abcdef01234567';
			$short = '0123456';
			// --- 1. registered, off by default -------------------------------------

			genbaWithSite(function (string $tmp) use ($hash): void {
				genba_write($tmp . '/site/config/build.txt', $hash . "\n");

				$off = genba_kirby($tmp);
				holds(array_key_exists('sayamaapps/genba', $off->plugins()), 'plugin sayamaapps/genba is registered');
				expect(null, $off->site()->genbaBuild(), 'off by default: genbaBuild() is null');
				expect(null, $off->site()->genbaBuildInfo(), 'off by default: genbaBuildInfo() is null');
				expect('', $off->snippet('genba/stamp', [], true), 'off by default: stamp snippet prints nothing');
				expect('', $off->snippet('genba/build-info', [], true), 'off by default: build-info snippet prints nothing');
				expect('fallback', genba_option('active', 'fallback'), 'off by default: genba_option() returns the fallback for an unset key');

				$yes = genba_kirby($tmp, ['genba.active' => 'yes']);
				expect(null, $yes->site()->genbaBuild(), '"yes" does not switch Genba on: genbaBuild() is null');
				expect('', $yes->snippet('genba/stamp', [], true), '"yes" does not switch Genba on: stamp snippet prints nothing');

				$on = genba_kirby($tmp, ['genba.active' => true]);
				expect(true, genba_option('active'), 'genba_option() reads genba.active');
				expect('x', genba_option('gibts-nicht', 'x'), 'genba_option() returns the fallback for an unset key');
				holds(is_string($on->translation('en')->get('genba.build.label')), 'translations are registered as genba.*');

				$new = genba_kirby($tmp, ['sayamaapps.genba.active' => true]);
				expect($hash, $new->site()->genbaBuild(), 'sayamaapps.genba.active switches Genba on');
			});
			// --- 2. active with a valid stamp ---------------------------------------

			genbaWithSite(function (string $tmp) use ($hash, $short): void {
				genba_write($tmp . '/site/config/build.txt', $hash . "\n");

				$kirby = genba_kirby($tmp, ['genba.active' => true]);
				expect($hash, $kirby->site()->genbaBuild(), 'active: genbaBuild() is the hash');
				$info = $kirby->site()->genbaBuildInfo();
				expect($short, $info['short'] ?? null, 'active: genbaBuildInfo() short is the first 7 characters');
				expect($hash, $info['hash'] ?? null, 'active: genbaBuildInfo() hash');
				expect('site/config/build.txt', $info['file'] ?? null, 'active: genbaBuildInfo() file is relative to the project root, not the server path');
				expect(
					'<meta name="build" content="' . $hash . '">' . "\n",
					$kirby->snippet('genba/stamp', [], true),
					'active: stamp snippet is the meta line'
				);
			});
			// --- 3. active with 'main' or no file -----------------------------------

			genbaWithSite(function (string $tmp): void {
				genba_write($tmp . '/site/config/build.txt', "main\n");
				$kirby = genba_kirby($tmp, ['genba.active' => true]);
				expect(null, $kirby->site()->genbaBuild(), 'active, file "main": genbaBuild() is null');
				expect(null, $kirby->site()->genbaBuildInfo(), 'active, file "main": genbaBuildInfo() is null');
				expect('', $kirby->snippet('genba/stamp', [], true), 'active, file "main": stamp snippet prints nothing');

				unlink($tmp . '/site/config/build.txt');
				$kirby = genba_kirby($tmp, ['genba.active' => true]);
				expect(null, $kirby->site()->genbaBuild(), 'active, file missing: genbaBuild() is null');
				expect('', $kirby->snippet('genba/stamp', [], true), 'active, file missing: stamp snippet prints nothing');
			});
			// --- 4. nested spelling, custom file, custom meta name ------------------

			genbaWithSite(function (string $tmp) use ($hash): void {
				$other = '1111111222222233333334444444555555566666667777777';
				genba_write($tmp . '/site/config/build.txt', $other . "\n");
				genba_write($tmp . '/site/config/stand.txt', $hash . "\n");

				$nested = genba_kirby($tmp, ['genba' => ['active' => true, 'stamp' => ['file' => 'site/config/stand.txt']]]);
				expect($hash, $nested->site()->genbaBuild(), 'nested options: genba.stamp.file is read');

				$flat = genba_kirby($tmp, ['genba.active' => true, 'genba.stamp.file' => 'site/config/stand.txt']);
				expect($hash, $flat->site()->genbaBuild(), 'flat options: genba.stamp.file is read');

				$named = genba_kirby($tmp, ['genba.active' => true, 'genba.stamp.file' => 'site/config/stand.txt', 'genba.stamp.meta' => 'stand']);
				expect(
					'<meta name="stand" content="' . $hash . '">' . "\n",
					$named->snippet('genba/stamp', [], true),
					'genba.stamp.meta => stand: meta name is stand'
				);

				$bad = genba_kirby($tmp, ['genba.active' => true, 'genba.stamp.meta' => 'bad name']);
				$bad->plugins();
				$logged = genbaErrorLog($tmp, function () use ($bad, $other): void {
					$html = $bad->snippet('genba/stamp', [], true);
					expect('<meta name="build" content="' . $other . '">' . "\n", $html, 'invalid meta name: default build is used');
				});
				contains('genba.stamp.meta', $logged, 'invalid meta name: reported in the error log');

				$debug = genba_kirby($tmp, ['genba.active' => true, 'genba.stamp.meta' => 'bad name', 'debug' => true]);
				$threw = false;
				try {
					$debug->snippet('genba/stamp', [], true);
				} catch (\InvalidArgumentException) {
					$threw = true;
				}
				holds($threw, 'debug on: invalid genba.stamp.meta throws InvalidArgumentException');
			});
			// --- 5. build-info -------------------------------------------------------

			genbaWithSite(function (string $tmp) use ($hash, $short): void {
				genba_write($tmp . '/site/config/build.txt', $hash . "\n");

				$en = genba_kirby($tmp, ['genba.active' => true]);
				$html = $en->snippet('genba/build-info', [], true);
				contains('class="genba-build"', $html, 'build-info en: genba-build class');
				contains('data-build="' . $hash . '"', $html, 'build-info en: data-build holds the hash');
				contains('>Build<', $html, 'build-info en: label Build');
				contains('<code class="genba-build-hash">' . $short . '</code>', $html, 'build-info en: short hash in code');
				holds(!str_contains($html, 'genba-build-date'), 'build-info en: no date unless withDate');

				$de = genba_kirby($tmp, ['genba.active' => true, 'genba.language' => 'de']);
				contains('>Stand<', $de->snippet('genba/build-info', [], true), 'build-info de: label Stand');

				$dated = genba_kirby($tmp, ['genba.active' => true]);
				contains('deployed 2026-', $dated->snippet('genba/build-info', ['withDate' => true], true), 'build-info withDate: deployed <date>');

				$unlabelled = genba_kirby($tmp, ['genba.active' => true]);
				$html = $unlabelled->snippet('genba/build-info', ['label' => false], true);
				holds(!str_contains($html, 'genba-build-label'), 'build-info label=false: no label span');
				contains('genba-build-hash', $html, 'build-info label=false: hash still shown');

				unlink($tmp . '/site/config/build.txt');
				$none = genba_kirby($tmp, ['genba.active' => true]);
				$html = $none->snippet('genba/build-info', [], true);
				contains('genba-build--none', $html, 'build-info without stamp: genba-build--none');
				contains('no build stamp', $html, 'build-info without stamp: text');

				$noneDe = genba_kirby($tmp, ['genba.active' => true, 'genba.language' => 'de']);
				contains('kein Stand hinterlegt', $noneDe->snippet('genba/build-info', [], true), 'build-info without stamp de: text');
			});
			// --- 6. translations -----------------------------------------------------

			genbaWithSite(function (string $tmp): void {
				$kirby = genba_kirby($tmp, ['genba.active' => true, 'locale' => 'de_DE']);
				$kirby->plugins();
				expect('Stand', $kirby->translation('de')->get('genba.build.label'), 'translation genba.build.label in German');
				expect('Build', $kirby->translation('en')->get('genba.build.label'), 'translation genba.build.label in English');

				\Sayamaapps\Genba\Options::applyLanguage($kirby);
				expect('de', \Sayamaapps\Genba\I18n::language(), 'applyLanguage: locale de_DE gives German');
				expect('kein Stand hinterlegt', \Sayamaapps\Genba\I18n::t('build.none'), 'I18n::t() after applyLanguage() is German');
				\Sayamaapps\Genba\I18n::setLanguage('en');
			});
			// --- 7. the site's own snippet wins ---------------------------------------

			genbaWithSite(function (string $tmp) use ($hash): void {
				genba_write($tmp . '/site/config/build.txt', $hash . "\n");
				genba_write($tmp . '/site/snippets/genba/stamp.php', 'OWN SNIPPET');

				$kirby = genba_kirby($tmp, ['genba.active' => true]);
				expect('OWN SNIPPET', $kirby->snippet('genba/stamp', [], true), 'a site\'s own snippets/genba/stamp.php wins');
			});
		} finally {
			ob_end_clean();
		}
	}
}
