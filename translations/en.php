<?php

/**
 * English strings — the default and the fallback for missing keys.
 * `{name}` placeholders are filled in by Genba\I18n::t(). tests/I18nTest.php
 * checks that every language file has the same keys and placeholders as this
 * one. Keys are the contract (docs/SPEC.md §5); never rename one without a
 * CHANGELOG entry.
 *
 * Only the Kirby part is translated (snippets genba/stamp, genba/build-info).
 * The command-line tools print English only (docs/SPEC.md §11).
 */
return [
	// genba/build-info snippet: a visible build line, e.g. on a preview site.
	'build.label' => 'Build',
	'build.hash' => 'Build {short}',
	'build.writtenAt' => 'deployed {date}',
	'build.none' => 'no build stamp',

	// Panel-facing texts for a site's own blueprint (info field or label).
	'panel.build.label' => 'Build stamp',
	'panel.build.info' => 'The deploy writes the commit hash to {file}; the smoke test compares it with the live page.',
];
