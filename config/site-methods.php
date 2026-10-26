<?php

use Sayamaapps\Genba\Site;

// Site methods. $this is the Kirby site; the logic lives in src/Site.php.
return [
	/**
	 * The deployed commit hash from the stamp file (SPEC.md §3.1), or null: Genba
	 * off, file missing, empty, or not a 7–64 digit hex hash (nothing unchecked
	 * goes into the HTML).
	 */
	'genbaBuild' => function (): ?string {
		return Site::build($this);
	},

	/**
	 * Hash plus context for a site's own markup (SPEC.md §3.1), or null under the
	 * same conditions as genbaBuild():
	 *
	 *   ['hash' => '0123…4567', 'short' => '0123456', 'file' => 'site/config/build.txt',
	 *    'writtenAt' => '2026-10-11T03:12:45+00:00'|null]
	 *
	 * `file` is relative to the project root, never the absolute server path (a
	 * file outside the root: its name only). `writtenAt` is the file's modification
	 * time in ISO 8601 (UTC), or null when it cannot be read.
	 */
	'genbaBuildInfo' => function (): ?array {
		return Site::buildInfo($this);
	},
];
