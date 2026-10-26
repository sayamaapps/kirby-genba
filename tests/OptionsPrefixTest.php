<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\Options;

/**
 * Options are read as sayamaapps.genba.<key>, with the 2.x spelling genba.<key>
 * as fallback (OPT-1). The fallback is reported as a deprecation.
 */
#[CoversClass(Options::class)]
final class OptionsPrefixTest extends TestCase
{
    public function testNewSpellingSwitchesGenbaOn(): void
    {
        $this->withApp(['sayamaapps.genba.active' => true], function (App $kirby): void {
            $this->assertTrue(Options::active($kirby), 'sayamaapps.genba.active is read');
        });
    }

    public function testNewSpellingWinsOverTheOldOneAndSilencesTheFallback(): void
    {
        $deprecations = $this->collectDeprecations(function (): void {
            $this->withApp(['debug' => true, 'sayamaapps.genba.active' => false, 'genba.active' => true], function (App $kirby): void {
                $this->assertFalse(Options::active($kirby), 'sayamaapps.genba.active wins when both are set');
            });
        });

        $this->assertSame([], $deprecations, 'no fallback, no deprecation');
    }

    public function testOldSpellingStillWorksAndIsDeprecated(): void
    {
        $deprecations = $this->collectDeprecations(function (): void {
            // Kirby reports deprecations in debug mode only.
            $this->withApp(['debug' => true, 'genba.active' => true], function (App $kirby): void {
                $this->assertTrue(Options::active($kirby), 'genba.active is read as fallback');
            });
        });

        $this->assertSame(
            ['Genba: the option genba.active is deprecated, use sayamaapps.genba.active'],
            array_values(array_filter($deprecations, fn (string $message): bool => str_contains($message, 'genba.active')))
        );
    }

    public function testNotSetFallsBackToTheDefault(): void
    {
        $this->withApp([], function (App $kirby): void {
            $this->assertFalse(Options::active($kirby), 'unset: default false');
            $this->assertSame('build', Options::metaName($kirby), 'unset: default meta name');
        });
    }

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
