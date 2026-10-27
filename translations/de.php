<?php

/**
 * German strings. Keys and placeholders as in en.php; tests/I18nTest.php
 * checks that both files have the same keys and placeholders.
 *
 * "Stand" is the reference wording for the build stamp.
 */
return [
	// genba/build-info snippet: a visible build line, e.g. on a preview site.
	'build.label' => 'Stand',
	'build.hash' => 'Stand {short}',
	'build.writtenAt' => 'ausgeliefert {date}',
	'build.none' => 'kein Stand hinterlegt',

	// Panel-facing texts for a site's own blueprint (info field or label).
	'panel.build.label' => 'Stand (Commit)',
	'panel.build.info' => 'Der Deploy schreibt den Commit-Hash nach {file}; der Smoke-Test vergleicht ihn mit der ausgelieferten Seite.',
];
