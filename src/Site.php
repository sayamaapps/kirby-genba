<?php

namespace Sayamaapps\Genba;

use Kirby\Cms\Site as KirbySite;

/**
 * The logic behind the site methods in config/site-methods.php.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Site
{
	/** The deployed commit hash, or null (see genbaBuild()). */
	public static function build(KirbySite $site): ?string
	{
		$kirby = $site->kirby();
		if (!Options::active($kirby)) {
			return null;
		}
		return Stamp::read(Options::stampFile($kirby));
	}

	/** Hash plus context, or null (see genbaBuildInfo()). */
	public static function buildInfo(KirbySite $site): ?array
	{
		$kirby = $site->kirby();
		if (!Options::active($kirby)) {
			return null;
		}
		return Stamp::info(Options::stampFile($kirby), Options::base($kirby));
	}
}
