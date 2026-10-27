<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The 1.x class names (Genba\…) are aliases, created when legacy.php loads.
 * instanceof and catch do not autoload, so an alias made on first use would
 * leave `$x instanceof Genba\Stamp` false until something had used the old name.
 *
 * Each test runs in its own process: no other test may have used a 1.x name before it.
 */
#[CoversNothing]
final class LegacyNamesTest extends TestCase
{
	/** The classes that were in the Genba\ namespace in 1.x. */
	private const NAMES = ['Homepage', 'I18n', 'Links', 'Log', 'Options', 'Precheck', 'Preflight', 'Stamp', 'Text', 'Tree'];

	#[RunInSeparateProcess]
	public function testEveryOneXNameExistsBeforeAnyUse(): void
	{
		foreach (self::NAMES as $name) {
			holds(class_exists('Genba\\' . $name, false), "Genba\\$name is an alias before first use");
		}
	}

	#[RunInSeparateProcess]
	public function testInstanceofMatchesWithoutPriorUse(): void
	{
		$stamp = new \Sayamaapps\Genba\Stamp();

		holds($stamp instanceof \Genba\Stamp, 'instanceof Genba\Stamp matches an object of Sayamaapps\Genba\Stamp');
	}
}
