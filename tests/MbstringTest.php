<?php

/**
 * Without mbstring every PHP tool says "cannot run" (exit 2, SPEC §3.0) instead of breaking off
 * with "Call to undefined function mb_substr()" in the middle of its run, without a last line.
 * mbstring is compiled into most PHP builds, so `php -n` does not remove it; the extension is
 * simulated missing with disable_functions, which makes its functions undefined
 * (`function_exists()` false) exactly as a PHP without mbstring has them.
 * Text::missingMbstring() counts a switched-off function as missing.
 */
use Sayamaapps\Genba\Text;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MbstringTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$root = dirname(__DIR__);
			$disabled = implode(',', Text::MBSTRING_FUNCTIONS);
			$project = genba_tmp('mbstring');
			$wrapper = genba_tmp('mbstring-php');
			try {
				expect(null, Text::missingMbstring(), 'Text::missingMbstring(): null when mbstring is there');

				// A project in which every tool would reach an mb_ function: a template with a finding
				// (precheck: mb_substr of the found line), a content file with a link (links), a stamp file.
				genba_write("$project/site/templates/home.php", "<p style=\"color:red\">x</p>\n");
				genba_write("$project/site/config/.keep", '');
				genba_write("$project/public/assets/css/tailwind.css", "body{margin:0}\n");
				genba_write("$project/content/home/home.txt", "Title: Home\n----\nText: https://www.youtube.com/watch?v=aaaaaaaaaaa\n");
				$sha = str_repeat('a', 40);

				$tools = [
					'genba-stamp' => ['args' => [], 'env' => ['GENBA_SHA' => $sha]],
					'genba-preflight' => ['args' => ['--hook=true'], 'env' => []],
					'genba-precheck' => ['args' => [], 'env' => []],
					'genba-links' => ['args' => ['--list-only'], 'env' => []],
				];
				echo "mbstring missing (disable_functions=$disabled):\n";
				foreach ($tools as $tool => $run) {
					$name = substr($tool, 6);
					[$code, $output] = genba_run(array_merge([genba_php(), '-d', "disable_functions=$disabled", "$root/bin/$tool"], $run['args']), $project, $run['env']);
					$ok = $code === 2 && str_contains($output, '::error::PHP extension mbstring is missing (mb_scrub is not available)') &&
						str_contains($output, "Genba $name: FAILED — cannot run\n") && !str_contains($output, 'Fatal error');
					holds($ok, "$tool without mbstring: exit 2 (got $code), the reason, the last line, no fatal error:\n$output");
					echo ($ok ? '  ok   ' : '  FAIL ') . "$tool\n";
					// Control: with mbstring the same run gets past the check.
					[, $control] = genba_run(array_merge([genba_php(), "$root/bin/$tool"], $run['args']), $project, $run['env']);
					holds(!str_contains($control, 'mbstring'), "$tool with mbstring: no mbstring message:\n$control");
				}
				// One function switched off is enough.
				[$code, $output] = genba_run([genba_php(), '-d', 'disable_functions=mb_substr', "$root/bin/genba-precheck"], $project);
				holds(
					$code === 2 && str_contains($output, '::error::PHP extension mbstring is missing (mb_substr is not available)'),
					"genba-precheck with mb_substr switched off: exit 2, the function named:\n$output"
				);

				// genba-smoke's reader: the same check, before its PHP-version check, on every version.
				[$code, $output] = genba_run([genba_php(), '-d', "disable_functions=$disabled", "$root/bin/genba-smoke-read", '--check'], $project);
				holds($code === 2 && trim($output) === 'PHP extension mbstring is missing (mb_scrub is not available)', "genba-smoke-read --check without mbstring: exit 2 and the reason (got $code):\n$output");

				// genba-smoke itself: a php in PATH that runs without mbstring — the run cannot start, before
				// its first request. The URL is never asked (port 9).
				genba_write("$wrapper/php", "#!/bin/sh\nexec '" . PHP_BINARY . "' -d disable_functions=$disabled \"\$@\"\n");
				chmod("$wrapper/php", 0755);
				[$code, $output] = genba_run(['bash', "$root/bin/genba-smoke"], $project, [
					'PATH' => $wrapper . PATH_SEPARATOR . (string)getenv('PATH'),
					'GENBA_URL' => 'http://127.0.0.1:9', 'GENBA_EXPECTED_BUILD' => $sha,
				]);
				$ok = $code === 2 && str_contains($output, "::error::The homepage reader cannot run: PHP extension mbstring is missing (mb_scrub is not available)\n") &&
					str_ends_with($output, "Genba smoke: FAILED — cannot run\n") && !str_contains($output, 'Genba smoke against');
				holds($ok, "genba-smoke with a php without mbstring: exit 2 before the first request (got $code):\n$output");
				echo ($ok ? '  ok   ' : '  FAIL ') . "genba-smoke (its reader)\n";
			} finally {
				genba_remove_tree($project);
				genba_remove_tree($wrapper);
			}
		} finally {
			ob_end_clean();
		}
	}
}
