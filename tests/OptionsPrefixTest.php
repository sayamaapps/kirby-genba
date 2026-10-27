<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\Options;

/**
 * Options are read as genba.<key>, as in 1.x. The sayamaapps.genba.* spelling
 * is not read before the next major version. Nothing is reported as a
 * deprecation at runtime: the deprecations are in the README and the docblocks.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
	/** Runs $fn and returns the deprecation messages it triggered. */
	private function collectDeprecations(callable $fn): array
	{
		$messages = [];
		set_error_handler(function (int $level, string $message) use (&$messages): bool {
			$messages[] = $message;
			return true;
		}, E_USER_DEPRECATED);

		try {
			$fn();
		} finally {
			restore_error_handler();
		}

		return $messages;
	}

	public function testGenbaSpellingIsRead(): void
	{
		$this->withApp(['genba.active' => true], function (App $kirby): void {
			$this->assertTrue(Options::active($kirby), 'genba.active is read');
		});
	}

	public function testLegacyFunctionReadsGenbaSilently(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$this->withApp(['debug' => true, 'genba.active' => true], function (): void {
				$this->assertTrue(genba_option('active'), 'genba_option() reads genba.active');
			});
		});

		$this->assertSame([], $deprecations, 'genba_option() raises no runtime deprecation');
	}

	public function testNewSpellingIsNotReadAndTriggersNoDeprecation(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$this->withApp(['debug' => true, 'sayamaapps.genba.active' => true], function (App $kirby): void {
				$this->assertFalse(Options::active($kirby), 'sayamaapps.genba.active is not read in this version');
			});
		});

		$this->assertSame([], $deprecations, 'no runtime deprecation');
	}

	public function testNotSetFallsBackToTheDefault(): void
	{
		$this->withApp([], function (App $kirby): void {
			$this->assertFalse(Options::active($kirby), 'unset: default false');
			$this->assertSame('build', Options::metaName($kirby), 'unset: default meta name');
		});
	}

	public function testOldClassNamesAreAliases(): void
	{
		$this->assertSame(Options::class, (new \ReflectionClass(\Genba\Options::class))->getName());
	}

	/** Runs $fn with a Kirby app on a throwaway site and removes the site afterwards. */
	private function withApp(array $options, callable $fn): void
	{
		$tmp = genba_site();
		try {
			$kirby = genba_kirby($tmp, $options);
			$kirby->plugins();
			$fn($kirby);
		} finally {
			App::destroy();
			Dir::remove($tmp);
		}
	}
}
