<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\Options;

/**
 * Options are read as sayamaapps.genba.<key>, with the 1.x spelling genba.<key>
 * as a fallback until the next major version. The fallback is read without a
 * runtime deprecation: the deprecation is in the README and the docblocks.
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

	public function testLegacyFunctionReadsNewSpelling(): void
	{
		$this->withApp(['sayamaapps.genba.active' => true], function (): void {
			$this->assertTrue(genba_option('active'), 'genba_option() reads sayamaapps.genba.active');
		});
	}

	public function testLegacySpellingIsReadSilently(): void
	{
		$deprecations = $this->collectDeprecations(function (): void {
			$this->withApp(['debug' => true, 'genba.active' => true], function (App $kirby): void {
				$this->assertTrue(Options::active($kirby), 'genba.active is read as fallback');
			});
		});

		$this->assertSame([], $deprecations, 'the 1.x spelling raises no runtime deprecation');
	}

	public function testMessagesNameTheKeyAsTheSiteSpelledIt(): void
	{
		$this->withApp(['genba.stamp.meta' => 'x'], function (App $kirby): void {
			$this->assertSame('genba.stamp.meta', Options::setKey($kirby, 'stamp.meta'), '1.x spelling is named');
		});
		$this->withApp(['sayamaapps.genba.stamp.meta' => 'x'], function (App $kirby): void {
			$this->assertSame('sayamaapps.genba.stamp.meta', Options::setKey($kirby, 'stamp.meta'), 'new spelling is named');
		});
	}

	public function testNewSpellingIsRead(): void
	{
		$this->withApp(['sayamaapps.genba.active' => true], function (App $kirby): void {
			$this->assertTrue(Options::active($kirby), 'sayamaapps.genba.active is read');
		});
	}

	public function testNewSpellingOfNestedKeysIsRead(): void
	{
		$this->withApp(['sayamaapps.genba.stamp.meta' => 'stand', 'sayamaapps.genba.stamp.file' => 'site/config/stand.txt'], function (App $kirby): void {
			$this->assertSame('stand', Options::metaName($kirby), 'sayamaapps.genba.stamp.meta is read');
			$this->assertStringEndsWith('/site/config/stand.txt', Options::stampFile($kirby), 'sayamaapps.genba.stamp.file is read');
		});
	}

	public function testNewSpellingWinsWhenBothAreSet(): void
	{
		$this->withApp(['genba.language' => 'en', 'sayamaapps.genba.language' => 'de'], function (App $kirby): void {
			$this->assertSame('de', Options::language($kirby), 'the new spelling wins');
		});
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
