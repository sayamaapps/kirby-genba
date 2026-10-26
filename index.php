<?php

use Kirby\Cms\App;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Log.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Stamp.php';
require_once __DIR__ . '/src/Preflight.php';
require_once __DIR__ . '/src/Text.php';
require_once __DIR__ . '/src/Precheck.php';
require_once __DIR__ . '/src/Links.php';

use Genba\I18n;
use Genba\Options;
use Genba\Stamp;

/**
 * Genba (現場 — the place where the work actually happens): operations of a
 * deployed Kirby 5 site. This file is the Kirby plugin part: the build stamp
 * in the HTML (<meta name="build">) and a visible build line. Everything else
 * — preflight hook, pre-deploy checks, smoke test, foreign-link check — are
 * command-line tools in bin/ that run without Kirby (SPEC.md §1, §3).
 *
 * Off by default: without `'genba.active' => true` the site methods return
 * null and the snippets print nothing.
 *
 * The logic lives in src/Stamp.php (Kirby-free).
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under sayamaapps.genba.*
 * otherwise).
 *
 * A site's old copy, site/snippets/stand.php, may stay next to the package:
 * different snippet name, no class — both load without conflict. The site
 * replaces snippet('stand') with snippet('genba/stamp') once (CHANGELOG).
 */

/** Reads `genba.<key>`. Also meant for site snippets with their own markup. */
function genba_option(string $key, mixed $default = null): mixed
{
	return Options::get(App::instance(), $key, $default);
}

$genbaTranslations = [];
foreach (I18n::LANGUAGES as $genbaLanguage) {
	foreach (I18n::table($genbaLanguage) as $genbaKey => $genbaText) {
		$genbaTranslations[$genbaLanguage]['genba.' . $genbaKey] = $genbaText;
	}
}
unset($genbaLanguage, $genbaKey, $genbaText);

App::plugin('sayamaapps/genba', [
	// All strings, also usable as t('genba.<key>') and as blueprint labels.
	'translations' => $genbaTranslations,

	/**
	 * Plain defaults without CSS. A site with its own markup creates
	 * site/snippets/genba/<name>.php — Kirby looks there first — or uses
	 * the array method $site->genbaBuildInfo().
	 */
	'snippets' => [
		'genba/stamp'      => __DIR__ . '/snippets/genba/stamp.php',
		'genba/build-info' => __DIR__ . '/snippets/genba/build-info.php',
	],

	'siteMethods' => [
		/**
		 * The deployed commit hash from the stamp file (SPEC.md §3.1), or
		 * null: Genba off, file missing, empty, or not a 7–64 digit hex hash
		 * (nothing unchecked goes into the HTML).
		 */
		'genbaBuild' => function (): string|null {
			$kirby = $this->kirby();
			if (!Options::active($kirby)) {
				return null;
			}
			return Stamp::read(Options::stampFile($kirby));
		},

		/**
		 * Hash plus context for a site's own markup (SPEC.md §3.1), or null
		 * under the same conditions as genbaBuild():
		 *
		 *   ['hash' => '0123…4567', 'short' => '0123456', 'file' => 'site/config/build.txt',
		 *    'writtenAt' => '2026-10-11T03:12:45+00:00'|null]
		 *
		 * `file` is relative to the project root, never the absolute server
		 * path (a file outside the root: its name only).
		 *
		 * `writtenAt` is the file's modification time in ISO 8601 (UTC), or
		 * null when it cannot be read — never a made-up date.
		 */
		'genbaBuildInfo' => function (): array|null {
			$kirby = $this->kirby();
			if (!Options::active($kirby)) {
				return null;
			}
			return Stamp::info(Options::stampFile($kirby), Options::base($kirby));
		},
	],
]);
