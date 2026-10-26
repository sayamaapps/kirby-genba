<?php

/**
 * The HTML variant corpus (SPEC §9.4 and §9.5): one page, many spellings a browser reads the
 * same. Each is a spelling that a reader in genba-smoke (stylesheets, src, srcset, marker,
 * comments) or genba-precheck (<script>, style=, handlers) can easily read wrongly; the corpus
 * is the yardstick for every rule that reads HTML.
 *
 *   tests/fixtures/html-variants/<name>.html     the homepage for bin/genba-smoke: every variant
 *                                                must PASS and request exactly the same files
 *   tests/fixtures/precheck-variants/<name>.php  a template for Genba\Precheck: every variant must
 *                                                give exactly the same findings (rule names)
 *
 * A new spelling is a new file; this test picks it up by itself.
 */
use Genba\Precheck;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HtmlCorpusTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$bin = dirname(__DIR__) . '/bin/genba-smoke';
			$router = __DIR__ . '/fixtures/smoke-server.php';
			$hash = '0123456789abcdef0123456789abcdef01234567';
			$tmp = genba_tmp('corpus');
			$faultFile = $tmp . '/fault';
			$requestLog = $tmp . '/requests.log';
			genba_write($faultFile, '');
			genba_write($requestLog, '');
			$server = null;
			$dirs = [];
			/**
			 * What every smoke variant must request: the stylesheet, the font its url() names, the .webp of
			 * the src and the two .avif of the srcset — compared without regard to case (uppercase.html
			 * spells the extensions in capitals). Nothing commented out, nothing inside <script> or <style>.
			 * Every GET is counted except the ones every run makes ($runRequests): a reference read wrongly
			 * can be requested anywhere — HTTPS://… read as a relative path would be GET /HTTPS://…, which a
			 * count of /assets/ and /media/ alone would not see.
			 */
			$expectedRequests = [
				'get /assets/css/tailwind.css?v=1',
				'get /assets/fonts/sans.woff2',
				'get /media/bild-2x.avif',
				'get /media/bild.avif',
				'get /media/bild.webp',
			];
			/** What every precheck variant must find: one finding per rule, nothing for the external script and the JSON-LD block. */
			$expectedFindings = ['embedded-script', 'foreign-host', 'inline-handler', 'inline-style'];
			/** The GET requests of every run, whatever the homepage: the checks 1 to 4, 6 to 8 and 7b (sitemap pages / and /kontakt, the Panel's own page and files). */
			$runRequests = '~^GET /(|genba-smoke-does-not-exist-\d+-\d+|robots\.txt|sitemap\.xml|llms\.txt|kontakt|api/system|http-umleitung|panel|panel/login|media/panel/js/app\.js|media/panel/css/app\.css)$~';
			try {
				// ---- genba-smoke -----------------------------------------------------

				$variants = glob(__DIR__ . '/fixtures/html-variants/*.html') ?: [];
				holds($variants !== [], 'the smoke corpus is not empty (tests/fixtures/html-variants/*.html)');
				$server = genba_server($router, ['GENBA_TEST_BUILD' => $hash, 'GENBA_TEST_FAULT_FILE' => $faultFile, 'GENBA_TEST_REQUEST_LOG' => $requestLog], '/assets/css/tailwind.css');
				$base = $server['base'];
				$unset = array_fill_keys(['GENBA_URL', 'GENBA_EXPECTED_BUILD', 'GENBA_META', 'GENBA_MARKER', 'GENBA_USER', 'GENBA_PASSWORD',
					'GENBA_WITHOUT_CREDENTIALS', 'GENBA_HTTPS_REDIRECT', 'GENBA_PANEL_ACCOUNT', 'GENBA_FOREIGN_SITEMAP', 'GENBA_PAGES_MAX',
					'GENBA_RETRIES', 'GENBA_PAUSE', 'GENBA_HTTP_URL', 'GENBA_SHA', 'GENBA_EXPECT_PROTECTED', 'GENBA_NOT_READY',
					'GENBA_PRIVATE_PATHS', 'GENBA_MAX_TIME', 'GENBA_SITEMAP_ORIGIN', 'CURL_CA_BUNDLE',
					'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'], null);
				$env = array_merge($unset, [
					'PATH' => genba_php_path(),
					'GENBA_URL' => $base,
					'GENBA_EXPECTED_BUILD' => $hash,
					'GENBA_HTTP_URL' => "$base/http-umleitung",
					'GENBA_RETRIES' => '1',
					'GENBA_PAUSE' => '0',
					'GENBA_PRIVATE_PATHS' => 'none',
					// Every variant carries the marker once — comments.html only inside a comment.
					'GENBA_MARKER' => 'genba-corpus',
				]);

				echo "HTML variant corpus, genba-smoke:\n";
				if (PHP_VERSION_ID < 80400) {
					// genba-smoke reads with Dom\HTMLDocument; below 8.4 it cannot run (SmokeTest checks that).
					echo '  --   PHP ' . PHP_VERSION . " is below 8.4: genba-smoke cannot read the homepage here, the smoke corpus runs on 8.4 and 8.5\n";
					$variants = [];
				}
				foreach ($variants as $file) {
					$name = basename($file, '.html');
					file_put_contents($faultFile, "variant:$name");
					file_put_contents($requestLog, '');
					[$code, $output] = genba_run(['bash', $bin], $tmp, $env);
					$requested = [];
					foreach (preg_split('/\n/', (string)file_get_contents($requestLog), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
						if (str_starts_with($line, 'GET ') && preg_match($runRequests, $line) !== 1) {
							$requested[] = strtolower($line);
						}
					}
					$requested = array_values(array_unique($requested));
					sort($requested);
					$ok = $code === 0 && str_ends_with($output, "Genba smoke: PASSED.\n") && $requested === $expectedRequests &&
						str_contains($output, "  ok   Marker 'genba-corpus' found") && substr_count($output, '  ok   Stylesheet: 200, text/css') === 1;
					holds($ok, sprintf("smoke variant %s: exit 0, PASSED, the marker, one stylesheet, and exactly the expected requests\n"
						. "--- expected:\n%s\n--- requested:\n%s\n--- output (exit %d):\n%s---", $name, implode("\n", $expectedRequests), implode("\n", $requested), $code, $output));
					echo ($ok ? '  ok   ' : '  FAIL ') . "smoke variant $name\n";
				}

				// ---- genba-precheck --------------------------------------------------

				$templates = glob(__DIR__ . '/fixtures/precheck-variants/*.php') ?: [];
				holds($templates !== [], 'the precheck corpus is not empty (tests/fixtures/precheck-variants/*.php)');
				echo "HTML variant corpus, genba-precheck:\n";
				foreach ($templates as $file) {
					$name = basename($file, '.php');
					$project = genba_tmp('corpus-precheck');
					$dirs[] = $project;
					$source = (string)file_get_contents($file);
					if (str_starts_with($name, 'crlf')) {
						// .gitattributes keeps the fixture files LF; the variant is CRLF on disk.
						$source = str_replace("\n", "\r\n", $source);
					}
					genba_write("$project/site/templates/$name.php", $source);
					genba_write("$project/public/assets/css/tailwind.css", "body{margin:0}\n");
					$findings = (new Precheck())->scan(['site' => "$project/site", 'public' => "$project/public"], $project);
					$rules = array_column($findings, 'rule');
					sort($rules);
					$ok = $rules === $expectedFindings;
					holds($ok, sprintf(
						"precheck variant %s: exactly one finding per rule\n--- expected:\n%s\n--- found:\n%s",
						$name,
						implode("\n", $expectedFindings),
						implode("\n", array_map(static fn (array $f): string => "{$f['rule']} line {$f['line']}: {$f['text']}", $findings))
					));
					echo ($ok ? '  ok   ' : '  FAIL ') . "precheck variant $name\n";
				}
			} finally {
				if ($server !== null) {
					genba_stop($server);
				}
				foreach ($dirs as $dir) {
					genba_remove_tree($dir);
				}
				genba_remove_tree($tmp);
			}
		} finally {
			ob_end_clean();
		}
	}
}
