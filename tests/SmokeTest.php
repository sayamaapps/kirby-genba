<?php
/**
 * bin/genba-smoke against a fake site (tests/fixtures/smoke-server.php): the
 * run matrix of SPEC §9.5. Every run starts the real bash script, so bash and
 * curl must be installed, as on every runner. Each run injects one fault.
 *   php tests/SmokeTest.php
 */
require_once __DIR__ . '/helpers.php';

/** Below PHP 8.4 only the abort is checked. */
final class SmokeNeedsPhp84 extends Exception
{
}

$bin = dirname(__DIR__) . '/bin/genba-smoke';
$router = __DIR__ . '/fixtures/smoke-server.php';
$hash = '0123456789abcdef0123456789abcdef01234567';
$tmp = genba_tmp('smoke');
$faultFile = $tmp . '/fault';
genba_write($faultFile, '');
$requestLog = $tmp . '/requests.log';
genba_write($requestLog, '');
$server = null;
$serverGit = null;
$repo = null;
$empty = null;

// Every GENBA_ variable is unset unless a run sets it, so the developer's own
// environment cannot change the matrix. The proxy variables too: the
// tool ignores them, but the matrix must not depend on that — and the "does
// not resolve" runs would read differently behind a real proxy.
$genbaVariables = [
    'GENBA_URL', 'GENBA_EXPECTED_BUILD', 'GENBA_META', 'GENBA_MARKER', 'GENBA_USER', 'GENBA_PASSWORD',
    'GENBA_WITHOUT_CREDENTIALS', 'GENBA_HTTPS_REDIRECT', 'GENBA_PANEL_ACCOUNT', 'GENBA_FOREIGN_SITEMAP',
    'GENBA_PAGES_MAX', 'GENBA_RETRIES', 'GENBA_PAUSE', 'GENBA_HTTP_URL', 'GENBA_SHA',
    'GENBA_EXPECT_PROTECTED', 'GENBA_NOT_READY', 'GENBA_PRIVATE_PATHS', 'GENBA_MAX_TIME',
    'GENBA_SITEMAP_ORIGIN', 'GENBA_CLOSED_PREVIEW', 'CURL_CA_BUNDLE',
    'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy',
];
$unset = array_fill_keys($genbaVariables, null);

try {
    $server = genba_server($router, ['GENBA_TEST_BUILD' => $hash, 'GENBA_TEST_FAULT_FILE' => $faultFile, 'GENBA_TEST_REQUEST_LOG' => $requestLog], '/assets/css/tailwind.css');
    $base = $server['base'];

    // The environment proves itself first: without a working fake site every
    // run below would be meaningless.
    [$probe] = genba_run(['curl', '-sf', '-o', '/dev/null', "$base/assets/css/tailwind.css"], $tmp);
    if ($probe !== 0) {
        throw new RuntimeException("The fake site does not answer 200 for the stylesheet (curl exit $probe)");
    }

    $defaults = [
        'PATH' => genba_php_path(),
        'GENBA_URL' => $base,
        'GENBA_EXPECTED_BUILD' => $hash,
        'GENBA_HTTP_URL' => "$base/http-umleitung",
        'GENBA_RETRIES' => '1',
        'GENBA_PAUSE' => '0',
    ];

    /**
     * One row of the matrix: writes the fault, empties the request log, runs
     * bin/genba-smoke with $args in $cwd, checks the exit code and the output
     * pattern. Returns the output for further checks.
     *
     * @param list<string> $args
     * @param array<string,?string> $env
     */
    $run = function (string $name, string $fault, int $expected, string $pattern, array $env = [], array $args = [], ?string $cwd = null) use ($bin, $tmp, $faultFile, $requestLog, $defaults, $unset): string {
        file_put_contents($faultFile, $fault);
        file_put_contents($requestLog, '');
        [$code, $output] = genba_run(
            array_merge(['bash', $bin], $args),
            $cwd ?? $tmp,
            array_merge($unset, $defaults, $env)
        );
        $matched = preg_match('~' . $pattern . '~', $output) === 1;
        $ok = $code === $expected && $matched;
        // SPEC §3.0: a run that cannot start still ends with the tool's line.
        if ($expected === 2) {
            holds(preg_match('~(?m)^Genba smoke: FAILED — cannot run$~', $output) === 1, "$name: an exit-2 run prints 'Genba smoke: FAILED — cannot run'\n--- output:\n$output\n---");
        }
        // Secrets never appear in the output (SPEC §3.0).
        $password = $env['GENBA_PASSWORD'] ?? null;
        if (is_string($password) && $password !== '') {
            holds(!str_contains($output, $password), "$name: the password must not appear in the output\n--- output:\n$output\n---");
        }
        // A run that ends SKIPPED has no finding — whatever else is missing,
        // an ::error:: line before the skip makes the run FAILED, exit 1.
        if (preg_match('~\nGenba smoke: SKIPPED~', $output) === 1) {
            holds(preg_match('/^::error::/m', $output) === 0, "$name: a SKIPPED run has no ::error:: line\n--- output:\n$output\n---");
        }
        holds($ok, sprintf(
            "%s: exit %d (expected %d), output must match /%s/\n--- output:\n%s\n---",
            $name,
            $code,
            $expected,
            $pattern,
            $output
        ));
        echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
        return $output;
    };

    // Without php on the machine the run cannot run, before the first request —
    // on every PHP version. The PATH holds bash, curl and the tools genba-smoke calls before the reader,
    // linked one by one (php shares /usr/bin with them on a runner), but no php.
    $noPhp = genba_tmp('smoke-no-php');
    try {
        foreach (['bash', 'curl', 'tr', 'sed', 'awk', 'grep', 'head', 'mktemp', 'rm', 'dirname', 'readlink', 'cat'] as $tool) {
            $found = trim((string) shell_exec('command -v ' . escapeshellarg($tool)));
            holds(str_starts_with($found, '/'), "$tool is found for the PATH without php");
            if (str_starts_with($found, '/')) {
                symlink($found, "$noPhp/$tool");
            }
        }
        holds(!is_file("$noPhp/php"), 'the PATH holds no php');
        $output = $run('php not found', '', 2, '(?m)^::error::php not found \(genba-smoke reads the homepage with PHP: bin/genba-smoke-read\)$', ['PATH' => $noPhp]);
        expect('', (string) file_get_contents($requestLog), 'without php nothing is requested');
        holds(!str_contains($output, 'Genba smoke against'), "without php the run stops before its first line:\n$output");
    } finally {
        genba_remove_tree($noPhp);
    }

    // The bytes of an address, as a table (SPEC §3.0 "Log text", the table "Bytes in an
    // address") — encode_controls, the encoder of the <loc> and url() readers, called on its own, with every awk
    // found (the PATH's own, gawk, mawk, original-awk/nawk, busybox): control characters percent-encoded, bytes
    // that are no UTF-8 one U+FFFD (%EF%BF%BD) per maximal subpart of the Encoding Standard — as the browser
    // decodes a stylesheet without a charset parameter, as Lexbor reads the homepage. Runs on every PHP
    // version: it needs bash, tr and awk only. The expectation is
    // written out by hand; on 8.4 and later the homepage reader (Homepage::read(), Lexbor) is held to the same
    // table below.
    $encodeRows = [
        "/a\x01b" => '/a%01b', "/d\x7f" => '/d%7F', "/c\xc2\x85" => '/c%C2%85', "/w\xc2\x9b0m" => '/w%C2%9B0m',
        "/ok\xc3\x9f\xe2\x82\xac\xf0\x9f\x98\x80" => "/ok\xc3\x9f\xe2\x82\xac\xf0\x9f\x98\x80", "/r\xef\xbf\xbd" => "/r\xef\xbf\xbd",
        "/x\x9ay" => '/x%EF%BF%BDy', "/x\xa0y" => '/x%EF%BF%BDy', "/l\xc3.png" => '/l%EF%BF%BD.png', "/l\xc3" => '/l%EF%BF%BD',
        "/e\xe9\x9b[x" => '/e%EF%BF%BD[x', "/t\xe2\x82" => '/t%EF%BF%BD', "/u\xe2\x82\xe2\x82\xac" => "/u%EF%BF%BD\xe2\x82\xac",
        "/f\xf0\x9f\x98.x" => '/f%EF%BF%BD.x', "/o\xc0\xaf" => '/o%EF%BF%BD%EF%BF%BD', "/o\xe0\x80\x80" => '/o%EF%BF%BD%EF%BF%BD%EF%BF%BD',
        "/s\xed\xa0\x80" => '/s%EF%BF%BD%EF%BF%BD%EF%BF%BD', "/h\xf4\x90\x80\x80" => '/h%EF%BF%BD%EF%BF%BD%EF%BF%BD%EF%BF%BD',
        "/v\xf5x" => '/v%EF%BF%BDx', "/v\xff" => '/v%EF%BF%BD', " \x01/edge\xff\x01 " => '/edge%EF%BF%BD', "/t\ta\rb" => '/tab',
    ];
    preg_match('/^encode_controls\(\) \{\n.*?^\}\n/ms', (string) file_get_contents($bin), $encodeFunction);
    holds(isset($encodeFunction[0]), 'encode_controls() is found in bin/genba-smoke');
    $awkDirs = [];
    $awkSeen = [];
    foreach (['awk', 'gawk', 'mawk', 'original-awk', 'nawk', 'busybox'] as $awk) {
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($awk) . ' 2>/dev/null'));
        $real = $found === '' ? false : realpath($found);
        if ($real === false || isset($awkSeen[$real])) {
            continue;
        }
        $awkSeen[$real] = $awk . ($awk === 'busybox' || basename($real) === $awk ? '' : ' = ' . basename($real));
        $dir = genba_tmp('awk-' . $awk);
        genba_write("$dir/awk", "#!/bin/sh\nexec '" . $found . "'" . ($awk === 'busybox' ? ' awk' : '') . " \"\$@\"\n");
        chmod("$dir/awk", 0755);
        $awkDirs[$awk] = $dir;
    }
    try {
        foreach ($awkDirs as $awk => $dir) {
            // The rows go in through a file: genba_run() has no stdin.
            genba_write("$dir/rows", implode("\n", array_keys($encodeRows)) . "\n");
            [$code, $encoded] = genba_run(['bash', '-c', ($encodeFunction[0] ?? '') . 'encode_controls < "$1"', '_', "$dir/rows"], $tmp,
                ['PATH' => $dir . PATH_SEPARATOR . getenv('PATH')]);
            expect(0, $code, "encode_controls with $awk: exit 0");
            $lines = explode("\n", rtrim($encoded, "\n"));
            foreach (array_keys($encodeRows) as $index => $row) {
                expect($encodeRows[$row], $lines[$index] ?? null, "encode_controls with $awk: " . addcslashes((string) $row, "\x00..\x20\x7f..\xff"));
            }
        }
        // Named by the binary behind the name (awk is mawk on Debian and Ubuntu, BWK awk on macOS).
        echo '  --   encode_controls checked with: ' . implode(', ', array_values($awkSeen)) . "\n";
    } finally {
        foreach ($awkDirs as $dir) {
            genba_remove_tree($dir);
        }
    }
    // The other reader of genba-smoke, the same rule: Homepage::read() (Lexbor) writes U+FFFD as the character,
    // curl sends it as %EF%BF%BD — compared with U+FFFD encoded on both sides. Not the NUL: awk cannot hold it,
    // encode_controls drops it (SPEC §3.0).
    if (PHP_VERSION_ID >= 80400) {
        require_once dirname(__DIR__) . '/src/Homepage.php';
        foreach ($encodeRows as $row => $expected) {
            $read = Genba\Homepage::read('<img src="' . $row . '">', 'build');
            expect(str_replace("\xef\xbf\xbd", '%EF%BF%BD', $expected), str_replace("\xef\xbf\xbd", '%EF%BF%BD', (string) ($read[0][1] ?? '')),
                'the homepage reader encodes the same: ' . addcslashes((string) $row, "\x00..\x20\x7f..\xff"));
        }
    }

    // genba-smoke reads the homepage with Dom\HTMLDocument, PHP 8.4 and later. Below, the
    // run ends before the first request, exit 2, with the reason and the version — the fault matrix
    // needs the reader and runs on 8.4 and 8.5 (CI).
    if (PHP_VERSION_ID < 80400) {
        echo "Genba smoke on PHP " . PHP_VERSION . ", below 8.4:\n";
        $output = $run('PHP below 8.4: cannot run, before the first request', '', 2,
            '(?m)^::error::The homepage reader cannot run: genba-smoke needs PHP 8\\.4 or later on the machine that runs it \\(it reads the homepage with Dom\\\\HTMLDocument\\), this is PHP ' . preg_quote(PHP_VERSION, '~')
            . ' — usually the CI runner, not the web server: setup-php with php-version: \'8\\.4\'$');
        expect('', (string) file_get_contents($requestLog), 'below PHP 8.4 nothing is requested');
        holds(!str_contains($output, 'Genba smoke against'), "below PHP 8.4 the run stops before its first line:\n$output");
        echo "  --   PHP below 8.4: the fault matrix needs the homepage reader (Dom\\HTMLDocument), not run on this version\n";
        throw new SmokeNeedsPhp84();
    }

    echo "Genba smoke, fault matrix:\n";
    $run('healthy site is green', '', 0, 'Genba smoke: PASSED');
    $run('credentials are sent', 'auth', 0, 'Genba smoke: PASSED', ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x']);
    $run('old build live', 'old-build', 1, 'Live build is fedcba98');
    $run('build missing in HTML', 'no-build', 1, 'without <meta name="build">');
    // Case-insensitive: SPEC §3.4 words the finding "the marker '…'", §9.5 "Marker '…'".
    $run('foreign content without marker', 'no-marker', 1, "(?i)Marker 'assets/css/tailwind.css'");
    $run('sitemap page with 500', 'page-500', 1, 'kontakt: HTTP 500');
    $run('HEAD on robots.txt 404', 'robots-head', 1, 'HEAD /robots.txt: HTTP 404');
    $run('webp as text/plain', 'webp-type', 1, "bild.webp: Content-Type 'text/plain'");
    $run('woff2 without type', 'woff2-type', 1, "sans.woff2: Content-Type 'application/octet-stream'");
    $run('sitemap on old domain, fail', 'foreign-sitemap', 1, 'foreign host: https://alte-domain.example/', ['GENBA_FOREIGN_SITEMAP' => 'fail']);
    $run('sitemap on old domain, warn', 'foreign-sitemap', 0, '::warning::Sitemap points to a foreign host', ['GENBA_FOREIGN_SITEMAP' => 'warn']);
    $run('empty sitemap', 'empty-sitemap', 1, 'Sitemap lists no pages');
    $run('error page returns 200', 'no-404', 1, 'Unknown address returns HTTP 200');
    $run('http without redirect', 'no-redirect', 1, 'http→https missing');
    $run('http without redirect, warn', 'no-redirect', 0, '::warning::http→https missing', ['GENBA_HTTPS_REDIRECT' => 'warn']);
    $run('https redirect off', 'no-redirect', 0, 'http→https not checked \(GENBA_HTTPS_REDIRECT=off\)', ['GENBA_HTTPS_REDIRECT' => 'off']);
    $run('Panel without account', 'panel-empty', 1, 'Panel without account');
    $run('Panel without account, warn', 'panel-empty', 0, '::warning::Panel without account', ['GENBA_PANEL_ACCOUNT' => 'warn']);
    $run('Panel installer open', 'panel-open', 1, 'can be installed from outside');
    $run('installer open, even in warn mode', 'panel-open', 1, 'can be installed from outside', ['GENBA_PANEL_ACCOUNT' => 'warn']);
    $run('preview without credentials', 'auth', 1, 'HTTP 401 instead of 200 \(protected');
    $run('preview without credentials, skip', 'auth', 0, '::warning::Genba smoke: SKIPPED.*\nGenba smoke: SKIPPED — build not verified', ['GENBA_WITHOUT_CREDENTIALS' => 'skip']);
    $run('empty user and empty password, skip', 'auth', 0, '\nGenba smoke: SKIPPED — build not verified', ['GENBA_USER' => '', 'GENBA_PASSWORD' => '', 'GENBA_WITHOUT_CREDENTIALS' => 'skip']);
    $run('empty user, password set', 'auth', 2, 'must be set together', ['GENBA_USER' => '', 'GENBA_PASSWORD' => 'x']);
    $run('wrong password', 'auth', 1, 'HTTP 401', ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'wrong']);
    $run('no hash expected', '', 2, 'is not a commit hash', ['GENBA_EXPECTED_BUILD' => 'main']);
    $run('URL missing', '', 2, 'GENBA_URL missing', ['GENBA_URL' => null]);
    $run('user without password', '', 2, 'must be set together', ['GENBA_USER' => 'preview']);
    $run('bad mode', '', 2, 'GENBA_HTTPS_REDIRECT must be', ['GENBA_HTTPS_REDIRECT' => 'maybe']);
    $run('help', '', 0, 'GENBA_URL', [], ['--help']);

    // Credentials over plain http and in the URL, sitemaps with foreign entries only, URL ranges,
    // ~/.curlrc, a homepage without stylesheet, no retry on 401.
    $output = $run('credentials over plain http', '', 2, '(?m)^::error::GENBA_USER/GENBA_PASSWORD only over https',
        ['GENBA_URL' => 'http://example.invalid', 'GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x']);
    holds(!str_contains($output, 'Genba smoke against'), "credentials over plain http: refused before any request\n$output");
    $output = $run('credentials in the URL', '', 2, 'GENBA_URL must not contain credentials',
        ['GENBA_URL' => 'https://user:s3cretpw@example.invalid']);
    holds(!str_contains($output, 's3cretpw') && !str_contains($output, 'example.invalid'), "credentials in the URL: the URL is not echoed\n$output");
    $output = $run('only foreign sitemap entries, warn', 'only-foreign-sitemap', 0, '::warning::No sitemap page fetched',
        ['GENBA_FOREIGN_SITEMAP' => 'warn']);
    holds(preg_match('~ok   0 sitemap~', $output) === 0, "zero fetched sitemap pages are never 'ok'\n$output");
    $run('only foreign sitemap entries, fail', 'only-foreign-sitemap', 1, 'Sitemap points to a foreign host', ['GENBA_FOREIGN_SITEMAP' => 'fail']);
    $run('brackets in an image name', 'glob-src', 0, 'ok   http://127\.0\.0\.1:\d+/media/x\[1-3\]\.webp: image/webp');
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, '/media/x1.webp'), "curl did not expand /media/x[1-3].webp into a range:\n$requests");
    contains('GET /media/x[1-3].webp', $requests, 'the bracketed name was requested literally');
    $curlHome = genba_tmp('smoke-curlrc');
    try {
        // A user's ~/.curlrc with a trace setting would log the Authorization header.
        genba_write($curlHome . '/.curlrc', 'trace-ascii = "' . $curlHome . '/trace.log"' . "\n");
        $run('a ~/.curlrc is ignored', 'auth', 0, 'Genba smoke: PASSED',
            ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x', 'CURL_HOME' => $curlHome, 'HOME' => $curlHome]);
        holds(!is_file($curlHome . '/trace.log'), 'curl did not read the .curlrc (no trace written)');
    } finally {
        genba_remove_tree($curlHome);
    }
    $run('no stylesheet on the homepage', 'no-marker', 0, '(?m)^  --   no stylesheet of http://127\.0\.0\.1:\d+ found on the homepage',
        ['GENBA_MARKER' => 'media/bild.webp']);
    $run('401 is not retried', 'auth', 1, 'HTTP 401 instead of 200 \(protected', ['GENBA_RETRIES' => '3']);
    expect(1, substr_count((string) file_get_contents($requestLog), "GET /\n"), 'a 401 homepage is fetched once, not GENBA_RETRIES times');

    // Decision 20: a staging sitemap listing production is fetched on staging (rewrite).
    $output = $run('staging sitemap lists production, rewritten', 'prod-sitemap', 0,
        '(?m)^::notice::Sitemap lists 2 pages on https://prod\.example instead of http://127\.0\.0\.1:\d+ — rewritten to http://127\.0\.0\.1:\d+ \(path and query kept\) and fetched there',
        ['GENBA_FOREIGN_SITEMAP' => 'rewrite']);
    holds(!str_contains($output, 'GENBA_FOREIGN_SITEMAP not set'), "an explicit value names no default:\n$output");
    contains('  ok   2 sitemap pages fetched', $output, 'the rewritten pages are counted');
    expect(1, substr_count($output, '::notice::Sitemap lists'), 'the rewrite is announced once');
    contains("GET /kontakt?x=1\n", (string) file_get_contents($requestLog), 'the rewritten page was fetched from staging, query kept');
    $run('staging sitemap, broken staging page', 'prod-sitemap page-500', 1,
        'Sitemap page http://127\.0\.0\.1:\d+/kontakt\?x=1: HTTP 500 instead of 200 \(listed as https://prod\.example/kontakt\?x=1\)',
        ['GENBA_FOREIGN_SITEMAP' => 'rewrite']);
    $run('foreign sitemap, bad mode', '', 2, 'GENBA_FOREIGN_SITEMAP must be rewrite, warn or fail', ['GENBA_FOREIGN_SITEMAP' => 'maybe']);

    // A closed preview (GENBA_CLOSED_PREVIEW): robots.txt Disallow: / without a
    // Sitemap line and an empty sitemap, as the Sudare package serves them. Only
    // that combination is accepted, and only with accept (the default on a preview).
    $closedLine = '(?m)^  --   closed preview: robots\.txt Disallow: / and an empty sitemap, so no sitemap pages were fetched \(GENBA_CLOSED_PREVIEW=accept\)$';
    $closedCredentials = ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x'];
    $closedPreview = $closedCredentials + ['GENBA_EXPECT_PROTECTED' => '1'];
    $output = $run('closed preview accepted on a preview run', 'auth closed-preview', 0, $closedLine, $closedPreview);
    holds(str_contains($output, 'Genba smoke: PASSED'), "a closed preview passes:\n$output");
    expect(1, preg_match_all('~(?m)^  --   closed preview: ~', $output), 'the closed preview is named once');
    holds(!str_contains($output, 'does not contain what it should') && !str_contains($output, 'Sitemap lists no pages'), "a closed preview has no findings for robots or sitemap:\n$output");
    contains('  ok   GET /robots.txt: 200, text/plain', $output, 'robots.txt still answers 200 text/plain');
    contains('  ok   HEAD /robots.txt: 200, text/plain', $output, 'robots.txt still answers 200 text/plain on HEAD');
    contains('  ok   GET /llms.txt: 200, text/plain', $output, 'llms.txt still answers 200 text/plain');
    $output = $run('closed preview, explicit accept', 'auth closed-preview', 0, $closedLine, $closedPreview + ['GENBA_CLOSED_PREVIEW' => 'accept']);
    $output = $run('closed preview, explicit fail on a preview run', 'auth closed-preview', 1,
        '(?m)^::error::/robots\.txt does not contain what it should \(pattern: \^Sitemap: \)$', $closedPreview + ['GENBA_CLOSED_PREVIEW' => 'fail']);
    holds(str_contains($output, '::error::Sitemap lists no pages') && !str_contains($output, 'closed preview:'), "fail names both findings and no closed line:\n$output");
    $output = $run('closed preview on a non-preview run: fail', 'closed-preview', 1,
        '(?m)^::error::Sitemap lists no pages$');
    holds(str_contains($output, '::error::/robots.txt does not contain what it should') && !str_contains($output, 'closed preview:'), "a closed site on a non-preview run is two findings:\n$output");
    $run('closed robots with pages, accept still fails', 'auth closed-robots', 1,
        '(?m)^::error::robots\.txt disallows all crawling \(Disallow: /\) but the sitemap lists pages — the live site would be closed by mistake', $closedPreview);
    $output = $run('empty sitemap with open robots, accept still fails', 'empty-sitemap', 1,
        '(?m)^::error::Sitemap lists no pages$', ['GENBA_CLOSED_PREVIEW' => 'accept']);
    holds(!str_contains($output, 'closed preview:'), "an open robots.txt is no closed preview:\n$output");
    $run('bad GENBA_CLOSED_PREVIEW', '', 2, 'GENBA_CLOSED_PREVIEW must be accept or fail', ['GENBA_CLOSED_PREVIEW' => 'yes']);

    // Decision 20, refined: unset, the default is rewrite on a
    // preview run (GENBA_EXPECT_PROTECTED=1 or GENBA_USER), else fail — named in the log.
    $credentials = ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x'];
    $output = $run('foreign sitemap entry, no settings: fail', 'foreign-sitemap', 1,
        '(?m)^::error::Sitemap points to a foreign host: https://alte-domain\.example/ \(site URL misconfigured\?\)');
    expect(1, preg_match_all('~(?m)^  --   GENBA_FOREIGN_SITEMAP not set: fail \(not a preview run~', $output), 'the default taken is named once (fail)');
    $output = $run('production sitemap behind credentials: rewrite by default', 'auth prod-sitemap', 0,
        '(?m)^::notice::Sitemap lists 2 pages on https://prod\.example instead of', $credentials);
    contains('  ok   2 sitemap pages fetched', $output, 'with credentials the production entries are fetched on staging');
    expect(1, preg_match_all('~(?m)^  --   GENBA_FOREIGN_SITEMAP not set: rewrite \(a preview run~', $output), 'the default taken is named once (rewrite)');
    $run('protected preview, production sitemap', 'auth prod-sitemap', 0, '(?m)^::notice::Sitemap lists 2 pages on https://prod\.example',
        $credentials + ['GENBA_EXPECT_PROTECTED' => '1']);
    // GENBA_EXPECT_PROTECTED=1 alone (no GENBA_USER) is a preview run too: the
    // open site fails the gate, but the sitemap is still rewritten, not a finding.
    $output = $run('GENBA_EXPECT_PROTECTED alone: rewrite by default', 'prod-sitemap', 1,
        '(?m)^::notice::Sitemap lists 2 pages on https://prod\.example', ['GENBA_EXPECT_PROTECTED' => '1']);
    holds(!str_contains($output, 'Sitemap points to a foreign host'), "GENBA_EXPECT_PROTECTED=1 takes rewrite:\n$output");
    $output = $run('explicit fail wins on a preview', 'auth prod-sitemap', 1, 'Sitemap points to a foreign host: https://prod\.example/',
        $credentials + ['GENBA_FOREIGN_SITEMAP' => 'fail']);
    holds(!str_contains($output, 'GENBA_FOREIGN_SITEMAP not set'), "an explicit value names no default:\n$output");
    $run('empty GENBA_FOREIGN_SITEMAP counts as unset', 'foreign-sitemap', 1, '(?m)^  --   GENBA_FOREIGN_SITEMAP not set: fail',
        ['GENBA_FOREIGN_SITEMAP' => '']);
    // GENBA_SITEMAP_ORIGIN: only that origin is rewritten, a third one is a finding.
    $output = $run('GENBA_SITEMAP_ORIGIN, third origin', 'auth prod-sitemap foreign-sitemap', 1,
        '(?m)^::error::Sitemap points to a foreign host: https://alte-domain\.example/ \(neither http://127\.0\.0\.1:\d+ nor GENBA_SITEMAP_ORIGIN https://prod\.example\)',
        $credentials + ['GENBA_SITEMAP_ORIGIN' => 'https://prod.example']);
    contains('Sitemap lists 2 pages on https://prod.example instead of', $output, 'the declared origin is still rewritten');
    $run('GENBA_SITEMAP_ORIGIN, only that origin', 'auth prod-sitemap', 0, 'ok   2 sitemap pages fetched',
        $credentials + ['GENBA_SITEMAP_ORIGIN' => 'https://prod.example/']);
    $run('GENBA_SITEMAP_ORIGIN with a path', '', 2, 'GENBA_SITEMAP_ORIGIN must be an origin without path', ['GENBA_SITEMAP_ORIGIN' => 'https://prod.example/de']);
    // The same staging host writes its stylesheet and images with the production
    // origin. With GENBA_SITEMAP_ORIGIN on a rewrite run they are the same path on staging, checked there
    // (else the safety net would make every such preview run red); production is never contacted.
    $output = $run('staging homepage names production: rewritten with GENBA_SITEMAP_ORIGIN', 'prod-link', 0,
        '(?m)^::notice::The homepage and its stylesheets name 2 addresses on https://prod\.example instead of http://127\.0\.0\.1:\d+ — rewritten to http://127\.0\.0\.1:\d+ \(path and query kept\) and checked there \(GENBA_FOREIGN_SITEMAP=rewrite, GENBA_SITEMAP_ORIGIN\)$',
        ['GENBA_FOREIGN_SITEMAP' => 'rewrite', 'GENBA_SITEMAP_ORIGIN' => 'https://prod.example']);
    $requests = (string) file_get_contents($requestLog);
    holds(str_contains($requests, "GET /assets/css/tailwind.css?v=1\n") && str_contains($requests, "GET /media/bild.webp\n") && str_contains($requests, "GET /assets/fonts/sans.woff2\n"),
        "the stylesheet, its font and the image are requested on the own origin:\n$requests");
    contains('  ok   Stylesheet: 200, text/css', $output, 'the rewritten stylesheet is checked');
    holds(!str_contains($output, 'Homepage contains the stylesheet address'), "the safety net is satisfied by the rewritten stylesheet:\n$output");
    // Without GENBA_SITEMAP_ORIGIN (rewrite mode alone, which a CDN would trip) or in fail mode: not rewritten,
    // and the safety net names the stylesheet — red, never a silent pass.
    foreach (['rewrite mode without GENBA_SITEMAP_ORIGIN' => ['GENBA_FOREIGN_SITEMAP' => 'rewrite'],
        'GENBA_SITEMAP_ORIGIN in fail mode' => ['GENBA_FOREIGN_SITEMAP' => 'fail', 'GENBA_SITEMAP_ORIGIN' => 'https://prod.example']] as $label => $settings) {
        $output = $run("staging homepage names production, $label", 'prod-link', 1,
            "(?m)^::error::Homepage contains the stylesheet address 'assets/css/tailwind\\.css' \\(GENBA_MARKER\\)", $settings);
        holds(!str_contains($output, '::notice::The homepage and its stylesheets') && !str_contains((string) file_get_contents($requestLog), 'GET /assets/css/tailwind.css'),
            "$label: nothing is rewritten:\n$output");
    }

    // The last line on exit 2, one GET per file and no
    // silent skip of check 4, abbreviated hashes, whitespace, GENBA_META,
    // sitemap entries as log text, XML entities, origins compared like a browser.
    $output = $run('exit 2 ends with the Genba smoke line', '', 2, 'GENBA_URL missing', ['GENBA_URL' => null]);
    holds(preg_match('~\nGenba smoke: FAILED — cannot run\n$~', $output) === 1, "the Genba smoke line is the last line:\n$output");
    $run('unknown argument', '', 2, '::error::Unknown argument: --bogus', [], ['--bogus']);
    // Next to --help the outcome must not depend on the order (--help --bogus and --bogus --help):
    // an argument error wins, whatever the order, in all five tools.
    foreach ([['--help', '--bogus'], ['--bogus', '--help']] as $order) {
        $run('unknown argument next to --help: ' . implode(' ', $order), '', 2, '(?m)^::error::Unknown argument: --bogus$', [], $order);
    }
    $run('empty URL argument next to --help', '', 2, '(?m)^::error::URL argument is empty', [], ['--help', '']);
    $run('healthy site, each file fetched once per method', '', 0, 'Genba smoke: PASSED');
    $requests = (string) file_get_contents($requestLog);
    expect(2, substr_count($requests, " /sitemap.xml\n"), 'sitemap.xml is requested exactly twice (GET and HEAD): the GET body is reused');
    expect(2, substr_count($requests, " /robots.txt\n"), 'robots.txt is requested exactly twice');
    expect(2, substr_count($requests, " /llms.txt\n"), 'llms.txt is requested exactly twice');
    $output = $run('sitemap GET fails, HEAD fine', 'sitemap-get-500', 1, '(?m)^::error::GET /sitemap\.xml: HTTP 500 instead of 200$');
    contains('::error::Sitemap not available for page check', $output, 'a sitemap that could not be read is said out loud');
    holds(!str_contains($output, 'sitemap pages fetched'), "no page count without a sitemap:\n$output");
    $output = $run('sitemap body is HTML', 'sitemap-html', 1, '/sitemap\.xml does not contain what it should');
    contains('::error::Sitemap not available for page check', $output, 'a sitemap without <urlset> is said out loud');
    $output = $run('sitemap larger than 10 MiB', 'huge-sitemap', 1, '(?m)^::error::GET /sitemap\.xml: larger than 10485760 bytes — not read$');
    contains('::error::Sitemap not available for page check', $output, 'the unread sitemap is said out loud');
    $run('abbreviated expected build', '', 0, "(?m)^  ok   Homepage serves build $hash$", ['GENBA_EXPECTED_BUILD' => substr($hash, 0, 7)]);
    $run('abbreviated expected build, wrong', '', 1, "Live build is $hash, expected abcdef0 —", ['GENBA_EXPECTED_BUILD' => 'abcdef0']);
    $run('expected build with a trailing newline', '', 0, 'Genba smoke: PASSED', ['GENBA_EXPECTED_BUILD' => "$hash\n"]);
    $run('bad GENBA_META', '', 2, 'GENBA_META must match \[A-Za-z0-9_-\]\+', ['GENBA_META' => 'bu"ild']);
    $run('other GENBA_META', '', 1, 'Homepage without <meta name="stand">', ['GENBA_META' => 'stand']);
    $output = $run('control characters in a sitemap entry', 'control-loc', 1, 'Sitemap points to a foreign host: https://evil\.example/', ['GENBA_FOREIGN_SITEMAP' => 'fail']);
    holds(!str_contains($output, "\x1b") && !str_contains($output, "\r"), "no ESC or CR reaches the log:\n" . addcslashes($output, "\x00..\x1f"));
    holds(preg_match('~\nGenba smoke: FAILED — see findings above\.\n$~', $output) === 1, "the injected PASSED text is not the last line:\n$output");
    $output = $run('sitemap entry of 100 000 characters', 'long-loc', 1, '(?m)^::error::Sitemap entry longer than 2048 characters \(\d+\), not fetched: http://127\.0\.0\.1:\d+/a{20,}…$');
    $longest = max(array_map('strlen', explode("\n", $output)));
    holds($longest < 400, "no output line is longer than 400 bytes (longest: $longest)");
    holds(!str_contains((string) file_get_contents($requestLog), str_repeat('a', 100)), 'the long entry is not fetched');
    $run('XML entities in a sitemap entry', 'entity-loc', 0, '(?m)^  ok   4 sitemap pages fetched$');
    $requests = (string) file_get_contents($requestLog);
    contains("GET /kontakt?a=1&b=2\n", $requests, '&amp; in the query is decoded before the fetch');
    contains("GET /kontakt?q='x'&r=\"y\"\n", $requests, '&apos;, &quot; and &amp; are decoded');
    $capitals = ['GENBA_URL' => str_replace('127.0.0.1', 'LOCALHOST', $base), 'GENBA_HTTP_URL' => str_replace('127.0.0.1', 'LOCALHOST', $base) . '/http-umleitung'];
    $output = $run('GENBA_URL with capitals, site in lower case', 'lower-base', 0, '(?m)^  ok   2 sitemap pages fetched$', $capitals);
    holds(!str_contains($output, 'foreign host'), "the own origin in another case is not foreign:\n$output");
    contains('  ok   http redirects with 301 to https://localhost:', $output, 'the redirect target in another case is the own host');
    $output = $run('production origin with capitals and default port', 'auth prod-sitemap-variants', 0, '(?m)^  ok   2 sitemap pages fetched$',
        $credentials + ['GENBA_SITEMAP_ORIGIN' => 'https://prod.example']);
    holds(!str_contains($output, 'neither'), "https://PROD.example and https://prod.example:443 are GENBA_SITEMAP_ORIGIN:\n$output");
    expect(1, substr_count($output, '::notice::Sitemap lists 2 pages on https://prod.example instead of'), 'both spellings count as one origin');

    // Sitemap entries on their own line, in CDATA, or unreadable;
    // numeric character references; ONE filter for everything the checked site puts
    // into the log — href, src, url(), Content-Type, redirect target, not only <loc>;
    // the exit-2 messages the documentation names.
    $run('pretty-printed sitemap entry', 'pretty-loc', 0, '(?m)^  ok   3 sitemap pages fetched$');
    contains("GET /kontakt?p=1\n", (string) file_get_contents($requestLog), 'an address on its own line inside <loc> is fetched');
    $run('CDATA in a sitemap entry', 'cdata-loc', 0, '(?m)^  ok   3 sitemap pages fetched$');
    contains("GET /kontakt?c=1\n", (string) file_get_contents($requestLog), 'an address wrapped in CDATA is fetched');
    $output = $run('sitemap entries no parser reads', 'unparsed-loc', 1, '(?m)^::error::Sitemap: 2 entries could not be parsed \(4 <url> entries, 2 <loc> addresses\)$');
    contains('  ok   2 sitemap pages fetched', $output, 'the readable entries are still fetched');
    $run('numeric character references in a sitemap entry', 'numeric-entity-loc', 0, '(?m)^  ok   4 sitemap pages fetched$');
    $requests = (string) file_get_contents($requestLog);
    contains("GET /kontakt?a=1&b=2\n", $requests, '&#38; in the query is decoded before the fetch');
    contains("GET /kontakt?c=&d=3\n", $requests, '&#x26; and &#61; are decoded before the fetch');
    $injected = [
        'esc-href' => 'Stylesheet http://127\.0\.0\.1:\d+/assets/css/x.*: HTTP \d+',
        'esc-src' => 'http://127\.0\.0\.1:\d+/media/y.*: HTTP \d+ instead of 200',
        'esc-ctype' => "Stylesheet http://127\.0\.0\.1:\d+/assets/css/tailwind\.css\?v=1: HTTP 200, 'text/css",
    ];
    foreach ($injected as $fault => $pattern) {
        $output = $run("control characters from the page: $fault", $fault, 1, "(?m)^::error::$pattern");
        holds(!str_contains($output, "\x1b") && !str_contains($output, "\r"), "$fault: no ESC or CR reaches the log:\n" . addcslashes($output, "\x00..\x1f"));
        holds(!str_contains($output, "\xC2\x85"), "$fault: no C1 control character (U+0085) reaches the log:\n" . addcslashes($output, "\x00..\x1f\x80..\xff"));
        holds(preg_match('~\nGenba smoke: FAILED — see findings above\.\n$~', $output) === 1, "$fault: the injected PASSED text is not the last line:\n$output");
    }
    $output = $run('control characters in the redirect target', 'esc-redirect', 0, '(?m)^  ok   http redirects with 301 to https://127\.0\.0\.1:\d+/');
    holds(!str_contains($output, "\x1b") && !str_contains($output, "\r"), "esc-redirect: no ESC or CR reaches the log:\n" . addcslashes($output, "\x00..\x1f"));
    // A Location with a bare CR or a line break cannot leave PHP's built-in server: a raw
    // listener sends it. Whatever curl makes of such a target, the log stays one clean line
    // per finding and the forged text never starts a line.
    foreach (['cr-location', 'lf-location'] as $mode) {
        $raw = genba_raw_server($mode, 'https://' . substr($base, 7) . '/');
        try {
            [$code, $output] = genba_run(['bash', $bin], $tmp, array_merge($unset, $defaults, ['GENBA_HTTP_URL' => $raw['base'] . '/']));
        } finally {
            genba_stop($raw);
        }
        holds(in_array($code, [0, 1], true), "$mode: the run ends with 0 or 1 (got $code):\n$output");
        holds(!str_contains($output, "\x1b") && !str_contains($output, "\r"), "$mode: no ESC or CR reaches the log:\n" . addcslashes($output, "\x00..\x1f"));
        holds(preg_match('/^(::error::forged|Genba smoke: PASSED\.$)/m', $output) === 0 || $code === 0, "$mode: no forged annotation line, no forged last line:\n$output");
        holds(preg_match('/^::error::forged/m', $output) === 0, "$mode: the injected annotation never starts a line:\n$output");
        holds(preg_match('~\nGenba smoke: (PASSED\.|FAILED — see findings above\.)\n$~', $output) === 1, "$mode: the real last line closes the run:\n$output");
        echo "  ok   redirect target with $mode\n";
    }
    $output = $run('control characters in the build meta', 'esc-build', 1, '(?m)^::error::Homepage without <meta name="build">');
    holds(!str_contains($output, "\x1b") && !str_contains($output, "\r") && preg_match('/^::error::forged/m', $output) === 0, "esc-build: nothing of the forged meta reaches the log:\n" . addcslashes($output, "\x00..\x1f"));
    $output = $run('build meta of 100 000 digits', 'long-build', 1, '(?m)^::error::Live build is a{60}… \(100000 characters\), expected ' . $hash);
    $longest = max(array_map('strlen', explode("\n", $output)));
    holds($longest < 400, "long-build: no output line is longer than 400 bytes (longest: $longest)");
    $output = $run('image address of 100 000 characters', 'long-src', 1, '(?m)^::error::Asset address longer than 2048 characters \(\d+\), not fetched: http://127\.0\.0\.1:\d+/media/b{20,}…$');
    $longest = max(array_map('strlen', explode("\n", $output)));
    holds($longest < 400, "long-src: no output line is longer than 400 bytes (longest: $longest)");
    holds(!str_contains((string) file_get_contents($requestLog), str_repeat('b', 100)), 'the long image address is not fetched');
    $output = $run('stylesheet address of 100 000 characters', 'long-href', 1, '(?m)^::error::Stylesheet address longer than 2048 characters \(\d+\), not fetched: http://127\.0\.0\.1:\d+/assets/css/c{20,}…$');
    $longest = max(array_map('strlen', explode("\n", $output)));
    holds($longest < 400, "long-href: no output line is longer than 400 bytes (longest: $longest)");
    holds(!str_contains((string) file_get_contents($requestLog), str_repeat('c', 100)), 'the long stylesheet address is not fetched');
    $run('two URL arguments', '', 2, '::error::Only one URL argument is allowed', [], [$base, $base]);
    $run('bad GENBA_PAGES_MAX', '', 2, 'GENBA_PAGES_MAX must be a whole number above 0: 0', ['GENBA_PAGES_MAX' => '0']);
    $run('bad GENBA_RETRIES', '', 2, 'GENBA_RETRIES must be a whole number above 0: x', ['GENBA_RETRIES' => 'x']);
    $run('bad GENBA_PAUSE', '', 2, 'GENBA_PAUSE must be a whole number of seconds: 1\.5', ['GENBA_PAUSE' => '1.5']);

    // Own-origin assets are chosen BEFORE the five are counted; none left is said out loud.
    $run('only foreign .webp on the homepage', 'foreign-webp', 0,
        '(?m)^  --   no \.webp file of http://127\.0\.0\.1:\d+ found on the homepage \(1 on other origins, not requested\), Content-Type not checked');
    $run('five foreign .webp do not crowd out the own one', 'crowded-webp webp-type', 1, "bild.webp: Content-Type 'text/plain'");

    // Decision 22: nothing above public/ is reachable — by default, counted, with credentials.
    $output = $run('private paths are not reachable', '', 0, '(?m)^  ok   17 private paths not reachable \(none answers 2xx\)');
    $requests = (string) file_get_contents($requestLog);
    contains("GET /site/config/config.php\n", $requests, 'the private paths were requested (config.php)');
    contains("GET /.git/HEAD\n", $requests, 'the private paths were requested (.git/HEAD)');
    $output = $run('private paths reachable', 'private-open', 1, 'Private path /site/config/config.php answers HTTP 200 — reachable from the web');
    contains('::error::Private path /.env answers HTTP 200', $output, 'every reachable private path is a finding');
    $run('private paths behind credentials', 'auth private-open', 1, 'Private path /site/config/config.php answers HTTP 200',
        ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x']);
    $output = $run('private paths: added entry', 'private-open', 1, 'Private path /genba-extra/secret.txt answers HTTP 200',
        ['GENBA_PRIVATE_PATHS' => '/genba-extra/secret.txt']);
    contains('Private path /site/config/config.php', $output, 'an added entry keeps the default list');
    $output = $run('private paths: replaced list', 'private-open', 1, 'Private path /genba-extra/secret.txt answers HTTP 200',
        ['GENBA_PRIVATE_PATHS' => 'none, genba-extra/secret.txt']);
    holds(!str_contains($output, 'config.php'), "none drops the default list:\n$output");
    $run('private paths off', 'private-open', 0, '(?m)^  --   private paths not checked \(GENBA_PRIVATE_PATHS=none\)', ['GENBA_PRIVATE_PATHS' => 'none']);
    $run('private paths: not a path', '', 2, 'GENBA_PRIVATE_PATHS: not a path below the site root: \.\./etc/passwd', ['GENBA_PRIVATE_PATHS' => '../etc/passwd']);

    // Decision 19: the reachability gate of the preview workflow, inside the tool.
    $output = $run('protected preview', 'auth', 0, '(?m)^  ok   Protected: http://127\.0\.0\.1:\d+/ answers 401 without credentials',
        ['GENBA_EXPECT_PROTECTED' => '1', 'GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x']);
    contains('Genba smoke: PASSED', $output, 'a protected preview with credentials passes');
    $run('preview protection off', '', 1, 'Without credentials http://127\.0\.0\.1:\d+/ returns HTTP 200 instead of 401 — the preview protection does not work',
        ['GENBA_EXPECT_PROTECTED' => '1']);
    $output = $run('protected preview, secrets missing', 'auth', 0, '\nGenba smoke: SKIPPED — build not verified',
        ['GENBA_EXPECT_PROTECTED' => '1', 'GENBA_WITHOUT_CREDENTIALS' => 'skip']);
    contains('  ok   Protected:', $output, 'the protection is proven even when the build cannot be');
    $run('bad GENBA_EXPECT_PROTECTED', '', 2, 'GENBA_EXPECT_PROTECTED must be 0 or 1', ['GENBA_EXPECT_PROTECTED' => 'yes']);
    $run('bad GENBA_NOT_READY', '', 2, 'GENBA_NOT_READY must be fail or skip', ['GENBA_NOT_READY' => 'later']);
    $unresolved = ['GENBA_URL' => 'https://genba-smoke-test.invalid', 'GENBA_HTTP_URL' => null];
    $output = $run('DNS pending, skip', '', 0, '(?m)^::warning::Genba smoke: SKIPPED — genba-smoke-test\.invalid does not resolve yet',
        $unresolved + ['GENBA_NOT_READY' => 'skip', 'GENBA_EXPECT_PROTECTED' => '1']);
    holds(preg_match('~\nGenba smoke: SKIPPED — genba-smoke-test\.invalid does not resolve yet\n$~', $output) === 1, "the last line says SKIPPED:\n$output");
    holds(!str_contains($output, 'PASSED') && !str_contains($output, '  ok   '), "a skipped run shows no ok:\n$output");
    $run('DNS pending, skip, no gate', '', 0, '\nGenba smoke: SKIPPED — genba-smoke-test\.invalid does not resolve yet', $unresolved + ['GENBA_NOT_READY' => 'skip']);
    $run('DNS missing, fail', '', 1, '(?m)^::error::genba-smoke-test\.invalid does not resolve \(DNS entry missing\?\)', $unresolved);
    // https fails (the fake site speaks plain http), http answers: the certificate is pending.
    $tls = ['GENBA_URL' => str_replace('http://', 'https://', $base), 'GENBA_HTTP_URL' => "$base/", 'GENBA_EXPECT_PROTECTED' => '1'];
    $run('certificate pending, protected over http', 'auth', 0, '\nGenba smoke: SKIPPED — https to 127\.0\.0\.1 fails \(certificate pending\?\)', $tls + ['GENBA_NOT_READY' => 'skip']);
    $run('certificate pending, open over http', '', 1, 'without credentials http://127\.0\.0\.1:\d+/ returns HTTP 200 instead of 401', $tls + ['GENBA_NOT_READY' => 'skip']);
    $run('certificate pending, fail', 'auth', 1, 'Homepage: HTTP 000 instead of 200', $tls);
    $run('bad GENBA_MAX_TIME', '', 2, 'GENBA_MAX_TIME must be a whole number of seconds above 0: 0', ['GENBA_MAX_TIME' => '0']);

    // GENBA_PAGES_MAX must not cut the list without a word — "1 sitemap pages
    // fetched" would read as "the sitemap has one page".
    $output = $run('GENBA_PAGES_MAX below the sitemap size', '', 0, '(?m)^  ok   1 of 2 sitemap pages fetched \(GENBA_PAGES_MAX=1\), the rest not checked$', ['GENBA_PAGES_MAX' => '1']);
    holds(!str_contains($output, 'ok   1 sitemap pages fetched'), "the cut list is never reported as the whole:\n$output");
    expect(1, substr_count((string) file_get_contents($requestLog), "GET /kontakt\n") + substr_count((string) file_get_contents($requestLog), "GET /\n") - 1, 'exactly one sitemap page was fetched (the homepage GET counted once for check 1)');
    $run('GENBA_PAGES_MAX equal to the sitemap size', '', 0, '(?m)^  ok   2 sitemap pages fetched$', ['GENBA_PAGES_MAX' => '2']);
    // The same for the five assets per type: more than five own files is said out loud.
    $output = $run('seven own .webp files: five checked, said so', 'many-webp', 0, '(?m)^  --   8 \.webp files of http://127\.0\.0\.1:\d+ on the homepage, the first 5 checked');
    expect(5, preg_match_all('~^  ok   http://127\.0\.0\.1:\d+/media/[^ ]+\.webp: image/webp$~m', $output), 'five .webp files are checked');

    // href, src and srcset are HTML-escaped like a <loc> — &amp; must not be
    // requested as written. Decoded before the fetch and before the srcset is split.
    $run('&amp; in href, src and srcset', 'amp-attr', 0, 'Genba smoke: PASSED');
    $requests = (string) file_get_contents($requestLog);
    contains("GET /assets/css/tailwind.css?v=2&x=2\n", $requests, '&amp; in an href is decoded before the fetch');
    contains("GET /media/bild.webp?a=1&b=2\n", $requests, '&amp; in a src is decoded before the fetch');
    contains("GET /media/bild.avif?s=1&t=2\n", $requests, '&amp; in a srcset is decoded before the fetch');
    holds(!str_contains($requests, 'amp;'), "no request carries a raw &amp;:\n$requests");

    // &amp;#38; is "&#38;" escaped once; decoded twice it would be "&". Decoded once it is
    // the text &#38; — a reference the tool does not decode: a finding, not fetched (curl
    // would ask for the cut ?v=1&).
    $output = $run('&amp;#38; in a sitemap entry is decoded once, then refused', 'double-entity-loc', 1,
        '(?m)^::error::Sitemap entry with a character reference that is not decoded: http://127\.0\.0\.1:\d+/kontakt\?v=1&#38;w=2 — not fetched$');
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, 'GET /kontakt?v=1&'), "neither the cut address nor a doubly decoded one is fetched:\n$requests");
    contains('  ok   2 sitemap pages fetched', $output, 'the healthy entries are still fetched');

    // Every other character reference stays as written, and curl reads its #
    // as the start of the fragment — /blog&#47;neu would be checked as /blog& (200), the real
    // /blog/neu (500) never. A reference decode_entities does not know is a finding, the address
    // not fetched.
    $output = $run('numeric reference of another character in a sitemap entry', 'other-numref-loc', 1,
        '(?m)^::error::Sitemap entry with a character reference that is not decoded: http://127\.0\.0\.1:\d+/blog&#47;neu — not fetched$');
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, '/blog'), "neither the cut /blog& nor /blog/neu is fetched:\n$requests");
    contains('  ok   2 sitemap pages fetched', $output, 'the healthy entries are still fetched');
    $run('non-ASCII reference in a sitemap entry', 'nonascii-loc', 1,
        '(?m)^::error::Sitemap entry with a character reference that is not decoded: http://127\.0\.0\.1:\d+/caf&#233; — not fetched$');
    holds(!str_contains((string) file_get_contents($requestLog), '/caf'), 'the cut /caf& is not fetched');
    $run('named entity in a sitemap entry', 'named-entity-loc', 1,
        '(?m)^::error::Sitemap entry with a character reference that is not decoded: http://127\.0\.0\.1:\d+/kontakt\?q=caf&eacute; — not fetched$');
    holds(!str_contains((string) file_get_contents($requestLog), 'eacute'), 'the entity is not fetched as written');
    // The homepage is read by an HTML5 parser, which decodes every
    // character reference as the browser does — &#233; is é, &#47; is /. The decoded addresses
    // are requested, as the browser requests them, and both answer 404 here. Never a cut address
    // (t&, x&).
    $output = $run('character references in an href and a src are decoded like the browser', 'numref-attr', 1,
        "(?m)^::error::Stylesheet http://127\\.0\\.0\\.1:\\d+/assets/css/t\u{e9}\\.css: HTTP 404, ");
    holds(preg_match('~(?m)^::error::http://127\\.0\\.0\\.1:\\d+/media/x/bild\\.webp: HTTP 404 instead of 200$~', $output) === 1, "the src with &#47; is requested as /media/x/bild.webp:\n$output");
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, '/assets/css/t&') && !str_contains($requests, '/media/x&'), "neither cut address is fetched:\n$requests");
    holds(!str_contains($output, 'not decoded'), "no reference is left undecoded on the homepage:\n$output");
    holds(!str_contains($output, 'other.jpg'), "a .jpg is never fetched, so its reference is no finding:\n$output");
    contains('  ok   Stylesheet: 200, text/css', $output, 'the healthy stylesheet is still checked');

    // A relative <loc> has no origin — it would read as "foreign host" in fail
    // mode and be fetched on the GENBA_URL origin without a word in rewrite mode.
    // A finding in every mode: the modes are about foreign origins, a relative entry has none.
    foreach (['fail' => ['GENBA_FOREIGN_SITEMAP' => 'fail'], 'rewrite' => $credentials, 'warn' => ['GENBA_FOREIGN_SITEMAP' => 'warn']] as $mode => $modeEnv) {
        $output = $run("relative sitemap entry, $mode mode", $mode === 'rewrite' ? 'auth relative-loc' : 'relative-loc', 1,
            '(?m)^::error::Sitemap entry is not an absolute URL: /relativ — the sitemap protocol requires scheme and host$', $modeEnv);
        holds(!str_contains($output, 'foreign host'), "$mode: a relative entry is not called a foreign host:\n$output");
        holds(!str_contains((string) file_get_contents($requestLog), '/relativ'), "$mode: the relative entry is not fetched");
    }

    // The gate gets no answer (a finding), the homepage then answers 401
    // and the secrets are missing — not SKIPPED, exit 0, with the
    // ::error:: line above it: a run with findings is FAILED, whatever else is missing.
    $flaky = genba_raw_server('empty-then-401', '');
    try {
        $output = $run('gate without answer, then 401 without secrets: FAILED, not SKIPPED', '', 1,
            '(?m)^::error::Without credentials http://127\.0\.0\.1:\d+/ gives no answer \(HTTP 000\)',
            ['GENBA_URL' => $flaky['base'], 'GENBA_EXPECT_PROTECTED' => '1', 'GENBA_WITHOUT_CREDENTIALS' => 'skip']);
    } finally {
        genba_stop($flaky);
    }
    holds(preg_match('/^(::warning::)?Genba smoke: SKIPPED/m', $output) === 0, "a run with a finding is never SKIPPED:\n$output");
    contains('— not SKIPPED: the run has findings already', $output, 'the missing secrets are still named, as a -- line');
    holds(preg_match('~\nGenba smoke: FAILED — see findings above\.\n$~', $output) === 1, "the last line says FAILED:\n$output");
    // Control: the same site answering 401 at once is the documented SKIPPED (the invariant in $run checks it).
    $run('401 at once without secrets, skip', 'auth', 0, '\nGenba smoke: SKIPPED — build not verified',
        ['GENBA_EXPECT_PROTECTED' => '1', 'GENBA_WITHOUT_CREDENTIALS' => 'skip']);

    // A line break inside GENBA_PASSWORD (a secret set from a file) makes
    // curl's -K file unreadable — curl exit 26, every request HTTP 000, the cause far from the
    // log line. Refused before the first request, LF and CR alike, the value never echoed.
    foreach (["ge\"heim\nSECRETPART" => 'LF', "ge\"heim\rSECRETPART" => 'CR'] as $broken => $kind) {
        $output = $run("line break ($kind) in the password", 'auth', 2, '(?m)^::error::GENBA_USER/GENBA_PASSWORD must not contain a line break',
            ['GENBA_USER' => 'preview', 'GENBA_PASSWORD' => $broken]);
        holds(!str_contains($output, 'SECRETPART') && !str_contains($output, 'Genba smoke against'), "$kind: refused before any request, nothing of the secret echoed:\n$output");
        holds(!str_contains($output, 'HTTP 000'), "$kind: no HTTP 000 finding, the cause is named instead:\n$output");
    }
    $output = $run('line break in the user name', 'auth', 2, 'must not contain a line break', ['GENBA_USER' => "pre\nview", 'GENBA_PASSWORD' => 'ge"heim\x']);
    holds(!str_contains($output, 'Genba smoke against'), "refused before any request:\n$output");

    // An entry that is empty after decoding and filtering is a finding, never skipped without
    // a word; with nothing else fetched, no "foreign host" is blamed.
    $output = $run('empty sitemap entries', 'empty-loc', 1, '(?m)^::error::Sitemap entry is empty \(<loc> without an address\)$');
    expect(2, preg_match_all('~^::error::Sitemap entry is empty~m', $output), 'both empty entries are findings');
    contains('  ok   2 sitemap pages fetched', $output, 'the healthy entries are still fetched');
    $output = $run('every sitemap entry empty', 'all-empty-loc', 1, '(?m)^::error::No sitemap page fetched: every entry is a finding \(see above\) — the pages of http://127\.0\.0\.1:\d+ are NOT checked$');
    expect(2, preg_match_all('~^::error::Sitemap entry is empty~m', $output), 'an entry of a control character only is empty too');
    holds(!str_contains($output, 'foreign host'), "no foreign entry was skipped, so none is blamed:\n$output");
    holds(!str_contains($output, 'sitemap pages fetched'), "zero fetched pages are never ok:\n$output");
    // Control: every entry foreign in warn mode still names the foreign host as the reason.
    $run('every entry foreign, warn: the warning names the cause', 'only-foreign-sitemap', 0, '::warning::No sitemap page fetched: the entries point to a foreign host', ['GENBA_FOREIGN_SITEMAP' => 'warn']);

    // <link>, href, src and srcset are not read line by line or only in
    // double quotes — a <link> a formatter wrapped over three lines is a stylesheet,
    // a srcset split at its commas and a src in single quotes are requested.
    $output = $run('markup wrapped over several lines', 'multiline-link', 0, 'Genba smoke: PASSED');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the stylesheet in a wrapped <link> is found');
    holds(!str_contains($output, 'no stylesheet of'), "no false 'no stylesheet' line:\n$output");
    expect(5, preg_match_all('~^  ok   http://127\.0\.0\.1:\d+/media/[^ ]+\.webp: image/webp$~m', $output), 'all five .webp files are checked (bild, a, b, c, d)');
    $requests = (string) file_get_contents($requestLog);
    foreach (['a', 'b', 'c', 'd'] as $name) {
        contains("GET /media/$name.webp\n", $requests, "/media/$name.webp is requested");
    }

    // The http→https check keeps curl's stderr — a connection that failed is not just
    // "returns '000' with target ''": the rule of fetch(), here too.
    $run('http check without answer names curl\'s message', '', 1,
        "(?m)^::error::http→https missing: http://127\.0\.0\.1:1/ returns '000' with target '', expected 301/308 to https://127\.0\.0\.1:\d+ — curl: \(7\) ",
        ['GENBA_HTTP_URL' => 'http://127.0.0.1:1/']);

    // strip_controls drops C1 not only in its UTF-8 form \xC2\x80–\xC2\x9F — a
    // raw byte \x80–\x9F (\x9B is the 8-bit CSI) too, wherever it is not part of a UTF-8
    // character; ß (\xC3\x9F) stays. The href in the C locale (in a UTF-8 locale macOS tr stops at
    // the raw byte already while the homepage is flattened), the redirect
    // target in a UTF-8 locale: it reaches the filter directly, whose own tr would stop there too.
    $c1Free = function (string $name, string $output): void {
        $characters = '~[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xf4][\x80-\xbf]{3}~';
        holds(preg_match('~[\x80-\x9f]~', (string) preg_replace($characters, '', $output)) === 0 && !str_contains($output, "\xc2\x9b"),
            "$name: no C1 byte outside a UTF-8 character reaches the log:\n" . addcslashes($output, "\x00..\x1f\x80..\xff"));
        holds(!str_contains($output, 'Illegal byte sequence'), "$name: tr does not stop at the raw byte:\n$output");
    };
    // The page is decoded as UTF-8 by the parser, as by the browser —
    // a byte that is no UTF-8 (\x9b alone, \xe9\x9b) is U+FFFD (\xef\xbf\xbd), never a raw
    // \xe9 in the log. The C1 character \xc2\x9b is percent-encoded, as
    // the browser requests it (%C2%9B).
    $output = $run('raw C1 bytes in an href', 'raw-c1-href', 1,
        "(?m)^::error::Stylesheet http://127\\.0\\.0\\.1:\\d+/assets/css/\xef\xbf\xbd31mA-stra\xc3\x9fe-\xef\xbf\xbd\\[31m-%C2%9B0m-x\\.css: HTTP 404",
        ['LC_ALL' => 'C']);
    $c1Free('raw-c1-href', $output);
    // A control character inside an address is percent-encoded, as the URL
    // parser does — in the homepage, in a url() of a stylesheet, in a <loc>. Dropped, it would ask
    // for the shorter name, which here is a file that exists: PASSED, while the browser gets a 404.
    $output = $run('a control character inside an address', 'ctrl-url', 1,
        '(?m)^::error::http://127\\.0\\.0\\.1:\\d+/media/bi%01ld\\.webp: HTTP 404 instead of 200$');
    contains('::error::Sitemap page http://127.0.0.1:' . parse_url($base, PHP_URL_PORT) . '/kon%01takt: HTTP 404 instead of 200', $output, 'a <loc> with a control character is requested encoded');
    holds(preg_match('~(?m)^::error::http://127\\.0\\.0\\.1:\\d+/assets/fonts/s%01ans\\.woff2: HTTP 404 instead of 200$~', $output) === 1, "a url() with a control character is requested encoded:\n$output");
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, "\x01") && substr_count($requests, "GET /media/bild.webp\n") <= 1, "nothing is requested with the raw byte or under the shortened name:\n$requests");
    // Bytes that are no UTF-8 in a url() and a <loc> are U+FFFD, one per maximal
    // subpart, as the browser asks for them — not /s%9Aans.woff2, and no raw \xE9 or \xC3.
    foreach (['/assets/fonts/s%EF%BF%BDans.woff2', '/assets/fonts/x%EF%BF%BDy.woff2', '/kon%EF%BF%BDtakt'] as $path) {
        contains("GET $path\n", $requests, "a byte that is no UTF-8 is requested as U+FFFD: $path");
    }
    contains('::error::Sitemap page http://127.0.0.1:' . parse_url($base, PHP_URL_PORT) . '/kon%EF%BF%BDtakt: HTTP 404 instead of 200', $output, 'a <loc> with a byte that is no UTF-8 is requested as U+FFFD');
    holds(mb_check_encoding($output, 'UTF-8'), 'no raw byte outside UTF-8 reaches the log:' . "\n" . addcslashes($output, "\x80..\xff"));
    $output = $run('raw C1 bytes in the redirect target', 'raw-c1-redirect', 0,
        "(?m)^  ok   http redirects with 301 to https://127\.0\.0\.1:\d+/31mA-stra\xc3\x9fe-0m-$",
        ['LC_ALL' => 'en_US.UTF-8']);
    $c1Free('raw-c1-redirect', $output);

    // A byte that is no UTF-8 (\xFF) in a body, run in a UTF-8 locale. macOS
    // tr and sed stop at it ("tr: Illegal byte sequence", "RE error: illegal byte sequence"),
    // and set -e would end the run with exit 1 and WITHOUT its closing line (SPEC §3.0). Every tool
    // that reads text from the site runs with LC_ALL=C; the byte itself is no finding.
    // GNU tr, sed and grep are lenient, so on Linux the runs would be green without
    // LC_ALL=C too. They run with strict stand-ins in front of PATH: tr, sed, grep, cut, sort,
    // paste and awk stop like macOS ("Illegal byte sequence", exit 1) when their locale is UTF-8 and
    // their input (stdin or a file argument) is no valid UTF-8 — a call without LC_ALL=C is red on
    // every system. And bash's own [[ =~ ]] (invalid-byte-loc): in a UTF-8 locale macOS bash 3.2
    // matches nothing in such a text — a reference check and the absolute-URL check would miss or
    // misjudge the entry; both run in the C locale (bytes_match). That part is red on macOS
    // without it, on glibc bash matches either way.
    [, $locales] = genba_run(['locale', '-a'], $tmp);
    $available = preg_split('/\R/', strtolower($locales)) ?: [];
    $utf8 = null;
    foreach (['en_US.UTF-8', 'C.UTF-8'] as $candidate) {
        if (in_array(strtolower($candidate), $available, true) || in_array(str_replace('-', '', strtolower($candidate)), $available, true)) {
            $utf8 = $candidate;
            break;
        }
    }
    [$iconvMissing] = genba_run(['sh', '-c', 'command -v iconv'], $tmp);
    if ($utf8 === null) {
        echo "  --   neither en_US.UTF-8 nor C.UTF-8 installed (locale -a), the invalid-byte runs are not tested\n";
    } elseif ($iconvMissing !== 0) {
        echo "  --   iconv not installed, the strict stand-ins cannot check UTF-8: the invalid-byte runs are not tested\n";
    } else {
        $strict = genba_tmp('smoke-strict');
        try {
            foreach (['tr', 'sed', 'grep', 'cut', 'sort', 'paste', 'awk'] as $tool) {
                [, $where] = genba_run(['sh', '-c', 'command -v ' . $tool], $tmp);
                genba_write("$strict/$tool", "#!/bin/sh\n"
                    . "# A strict $tool, as macOS has it in a UTF-8 locale (tests/SmokeTest.php).\n"
                    . "case \"\${LC_ALL:-\${LC_CTYPE:-\${LANG:-}}}\" in\n"
                    . "  *[Uu][Tt][Ff]-8*|*[Uu][Tt][Ff]8*) ;;\n"
                    . "  *) exec '" . trim($where) . "' \"\$@\" ;;\n"
                    . "esac\n"
                    . "in=\$(mktemp) || exit 2\n"
                    . "cat > \"\$in\"\n"
                    . "for f in \"\$in\" \"\$@\"; do\n"
                    . "  if [ -f \"\$f\" ] && ! iconv -f UTF-8 -t UTF-8 \"\$f\" > /dev/null 2>&1; then\n"
                    . "    echo \"$tool: Illegal byte sequence (strict stand-in)\" >&2\n"
                    . "    rm -f \"\$in\"\n"
                    . "    exit 1\n"
                    . "  fi\n"
                    . "done\n"
                    . "'" . trim($where) . "' \"\$@\" < \"\$in\"\n"
                    . "rc=\$?\n"
                    . "rm -f \"\$in\"\n"
                    . "exit \$rc\n");
                chmod("$strict/$tool", 0755);
            }
            // The stand-ins prove themselves: strict in a UTF-8 locale, the real tool in C.
            [$strictCode, $strictOut] = genba_run(['sh', '-c', 'printf "a\377b\n" | tr a x'], $tmp, ['PATH' => $strict . PATH_SEPARATOR . getenv('PATH'), 'LC_ALL' => $utf8]);
            holds($strictCode === 1 && str_contains($strictOut, 'Illegal byte sequence'), "the strict tr stops at \\xFF in $utf8 (exit $strictCode):\n$strictOut");
            [$strictCode, $strictOut] = genba_run(['sh', '-c', 'printf "a\377b\n" | tr a x'], $tmp, ['PATH' => $strict . PATH_SEPARATOR . getenv('PATH'), 'LC_ALL' => 'C']);
            holds($strictCode === 0 && str_starts_with($strictOut, "x\xffb"), "the strict tr is the real one in the C locale (exit $strictCode)");
            $strictEnv = ['LC_ALL' => $utf8, 'LANG' => $utf8, 'PATH' => $strict . PATH_SEPARATOR . getenv('PATH')];
            foreach (['invalid-byte-sitemap' => 3, 'invalid-byte-home' => 2] as $fault => $pages) {
                $output = $run("byte that is no UTF-8 ($fault, $utf8, strict tools)", $fault, 0, "(?m)^  ok   $pages sitemap pages fetched$", $strictEnv);
                holds(preg_match('~\nGenba smoke: (PASSED\.|FAILED — .+)\n?$~', $output) === 1, "$fault: the run ends with its closing line:\n$output");
                holds(!str_contains($output, 'Illegal byte sequence') && !str_contains($output, 'illegal byte sequence'), "$fault: no tool stops at the byte:\n$output");
            }
            // encode_controls makes the \xFF U+FFFD (%EF%BF%BD) before any check reads
            // the entry, so the log line holds no raw byte; the checks themselves stay in the C locale.
            $output = $run("byte that is no UTF-8 in a sitemap entry ($utf8, strict tools)", 'invalid-byte-loc', 1,
                "(?m)^::error::Sitemap entry with a character reference that is not decoded: http://127\\.0\\.0\\.1:\\d+/caf%EF%BF%BD&#233; — not fetched$", $strictEnv);
            contains('  ok   3 sitemap pages fetched', $output, 'the entry with \xFF and no reference is fetched (/kontakt?y=1#caf\xFF)');
            contains("GET /kontakt?y=1\n", (string) file_get_contents($requestLog), 'the absolute entry with \xFF in its fragment is requested');
            holds(!str_contains($output, 'not an absolute URL'), "an absolute entry with \\xFF is absolute:\n$output");
            contains("::error::Sitemap points to a foreign host: http://%EF%BF%BD.example/ (site URL misconfigured?)", $output, 'a byte right behind the :// still reads as an absolute address (a foreign host)');
            holds(!str_contains($output, 'Illegal byte sequence'), "invalid-byte-loc: no tool stops at the byte:\n$output");
            holds(!str_contains((string) file_get_contents($requestLog), 'GET /caf'), 'the entry with the reference is not fetched as a cut address');
        } finally {
            genba_remove_tree($strict);
        }
    }

    // Comments are dropped — a commented-out sitemap entry is neither counted
    // nor fetched (a 404 finding for an address that is no entry). The homepage alike: a stylesheet
    // or an image commented out is not on the page.
    $output = $run('commented-out sitemap entries', 'comment-loc', 0, '(?m)^  ok   2 sitemap pages fetched$');
    contains('Genba smoke: PASSED.', $output, 'a commented-out entry is no finding');
    holds(!str_contains((string) file_get_contents($requestLog), '/alt'), 'no commented-out entry is fetched');
    $output = $run('commented-out stylesheet and image on the homepage', 'comment-link', 0, 'Genba smoke: PASSED');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the real stylesheet is still checked');
    $requests = (string) file_get_contents($requestLog);
    holds(!str_contains($requests, '/alt.css') && !str_contains($requests, '/alt.webp'), "nothing commented out is requested:\n$requests");

    // An <!-- inside an inline <script> is JavaScript. Read as the start of a comment, it would drop
    // the page up to the next --> — the 404 stylesheet behind it never requested, the run PASSED.
    // The HTML5 parser reads the raw text of <script> and <style> as the browser's tokenizer does.
    $output = $run('<!-- inside an inline script', 'comment-in-script', 1,
        '(?m)^::error::Stylesheet http://127\.0\.0\.1:\d+/assets/css/missing\.css: HTTP 404, ');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the stylesheet before the script is still checked');
    expect(5, preg_match_all('~^  ok   http://127\.0\.0\.1:\d+/(media|assets)/[^ ]+: (image|font)/~m', $output), 'the images behind the script are checked (webp, two avif, two woff2)');

    // <!--> is a complete empty comment, as are <!---> and a comment closed by
    // --!>; read as the start of a comment that runs to the next -->, it would swallow the
    // 404 stylesheet behind it (PASSED). The other spellings are in the corpus (comment-ends.html,
    // lt-in-value.html).
    $output = $run('404 stylesheet behind an empty comment <!-->', 'abrupt-comment', 1,
        '(?m)^::error::Stylesheet http://127\.0\.0\.1:\d+/assets/css/missing\.css: HTTP 404, ');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the stylesheet before the empty comment is still checked');

    // A <base href> is honoured, as the browser honours it, and said in a -- line; ignored, every
    // relative address would be requested on the wrong path. In document
    // order — the corpus variant base-after-link.html holds a stylesheet before the <base>.
    $output = $run('<base href> on the homepage', 'base-href', 0, '(?m)^  --   <base href="/assets/\\.\\./"> on the homepage: the addresses after it are resolved against http://127\\.0\\.0\\.1:\\d+/$');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the stylesheet written relative to the base is found');
    contains("GET /assets/css/tailwind.css?v=1\n", (string) file_get_contents($requestLog), 'assets/css/tailwind.css is requested against the base');
    // The browser ignores a data: or javascript: base ("set the frozen base URL")
    // and resolves against the page's address — not a finding about a base that is no http(s)
    // address, with nothing checked.
    foreach (['js-base' => 'javascript:void\\(0\\)', 'data-base' => 'DATA:text/html,x'] as $fault => $href) {
        $output = $run("<base href> $fault is ignored, as by the browser", $fault, 0,
            '(?m)^  --   <base href="' . $href . '"> on the homepage is ignored, as the browser ignores a data: or javascript: base$');
        $requests = (string) file_get_contents($requestLog);
        holds(str_contains($requests, "GET /assets/css/tailwind.css?v=1\n") && str_contains($requests, "GET /media/bild.webp\n"),
            "$fault: the stylesheet and the image are requested against the page's address:\n$requests\n$output");
        contains('  ok   Stylesheet: 200, text/css', $output, "$fault: the stylesheet is checked");
    }
    $output = $run('<base href> that is no http(s) address', 'mailto-base', 1,
        '(?m)^::error::Homepage <base href="mailto:x@example\\.com"> is no http\\(s\\) address — the addresses after it are not checked$');
    holds(!str_contains((string) file_get_contents($requestLog), 'GET /assets/css/tailwind.css'), "with such a base no address after it is requested:\n$output");

    // A reader that fails on the saved page (here a PHP whose ini_set() is switched off: the call
    // that raises the memory limit ends the reader with a fatal error) is "cannot run", never a page read
    // as empty — that would be "Homepage without <meta>" with the wrong cause. PHP writes "PHP Fatal error"
    // or "Fatal error", depending on its ini (log_errors). The limit is set to PHP's default of 128 MB here:
    // setup-php leaves memory_limit at -1 (unlimited), the reader then never raises it, never calls
    // ini_set(), and reads the page — exit 0, and this run would be red on CI.
    $failing = genba_tmp('smoke-failing-php');
    try {
        genba_write("$failing/php", "#!/bin/sh\nexec '" . PHP_BINARY . "' -d memory_limit=128M -d disable_functions=ini_set \"\$@\"\n");
        chmod("$failing/php", 0755);
        $output = $run('the reader fails on the saved homepage', '', 2,
            '(?m)^::error::The homepage cannot be read: (PHP )?Fatal error: +Uncaught Error: Call to undefined function Genba\\\\ini_set\\(\\)',
            ['PATH' => $failing . PATH_SEPARATOR . (string) getenv('PATH')]);
        holds(!str_contains($output, 'Homepage without <meta'), "a failed reader is not read as a page without the build meta:\n$output");
    } finally {
        genba_remove_tree($failing);
    }

    // The safety net under the reader: the marker names the stylesheet (assets/css/tailwind.css by
    // default) and stands in the page, so the page names its stylesheet — if the reader then finds no
    // stylesheet of the own origin with that address, the run is red; a reader error would otherwise
    // end "no stylesheet … not checked", PASSED. Shown with a reader broken on purpose: a copy of the
    // package whose Homepage.php drops every <link>.
    $broken = genba_tmp('smoke-broken-reader');
    try {
        foreach (['bin/genba-smoke', 'bin/genba-smoke-read', 'src/Homepage.php', 'src/Text.php'] as $file) {
            genba_write("$broken/$file", (string) file_get_contents(dirname(__DIR__) . "/$file"));
        }
        chmod("$broken/bin/genba-smoke", 0755);
        $reader = (string) file_get_contents("$broken/src/Homepage.php");
        $dropped = str_replace("\$records[] = ['link', ", "\$dropped[] = ['link', ", $reader);
        holds($dropped !== $reader, 'the broken reader copy drops the <link> records');
        genba_write("$broken/src/Homepage.php", $dropped);
        file_put_contents($faultFile, '');
        file_put_contents($requestLog, '');
        [$code, $output] = genba_run(['bash', "$broken/bin/genba-smoke"], $tmp, array_merge($unset, $defaults));
        holds($code === 1 && preg_match("~(?m)^::error::Homepage contains the stylesheet address 'assets/css/tailwind\\.css' \\(GENBA_MARKER\\), but no stylesheet of http://127\\.0\\.0\\.1:\\d+ with that address was read from its <link> elements — the stylesheet is not checked~", $output) === 1,
            "a reader that loses the <link> is a finding, not a silent pass (exit $code):\n$output");
        contains('  --   no stylesheet of', $output, 'the -- line is still there');
        echo ($code === 1 ? '  ok   ' : '  FAIL ') . "safety net: a broken reader is red\n";
        // The net is off when the marker is no .css address (the site's choice), and the real reader passes.
        [$code, $output] = genba_run(['bash', "$broken/bin/genba-smoke"], $tmp, array_merge($unset, $defaults, ['GENBA_MARKER' => 'assets/css/tailwind']));
        holds($code === 0 && !str_contains($output, 'Homepage contains the stylesheet address'), "a marker that is no .css address switches the net off:\n$output");
    } finally {
        genba_remove_tree($broken);
    }
    $output = $run('the real reader on the same site', '', 0, 'Genba smoke: PASSED');
    holds(!str_contains($output, 'Homepage contains the stylesheet address'), "the net is silent when the stylesheet was read:\n$output");

    // The reader has bounds. The homepage is fetched with the 10 MiB of the sitemap
    // (Lexbor holds about 40 times the page): above, it is a finding and is not read — not "without <meta>",
    // not "marker missing" — and not fetched again. And the reader runs at most GENBA_MAX_TIME seconds
    // (Lexbor is quadratic in the nesting depth, and max_execution_time does not reach into it): here a PHP
    // whose reader never ends; without the bound the run would hang until the job's limit.
    $output = $run('homepage above 10 MiB', 'huge-home', 1,
        '(?m)^::error::Homepage larger than 10485760 bytes — not read: its build, its marker and its addresses are not checked$', ['GENBA_RETRIES' => '3']);
    holds(!str_contains($output, 'Homepage without <meta') && !str_contains($output, 'does not contain the marker') && !str_contains($output, 'Homepage contains the stylesheet address'),
        "a homepage too large is one finding, not the wrong causes:\n$output");
    // GET / twice: check 1 once (not GENBA_RETRIES times, that would be 3), and the sitemap's page / (check 4).
    expect(2, substr_count((string) file_get_contents($requestLog), "GET /\n"), 'a homepage too large is fetched once by check 1, not GENBA_RETRIES times');
    // Check 5 names the reason once — the homepage was not read — instead of three
    // "no .webp file found on the homepage" lines; and so for a homepage that answered no 200 (a 401 here).
    contains('  --   stylesheets, images and fonts not checked: the homepage is larger than 10485760 bytes and was not read', $output,
        'a homepage too large: check 5 says why it did not run');
    holds(!str_contains($output, 'found on the homepage'), "a homepage not read is no homepage without files:\n$output");
    $output = $run('homepage 401: check 5 not run', 'auth', 1, '(?m)^  --   stylesheets, images and fonts not checked: the homepage answered HTTP 401, not 200$');
    holds(!str_contains($output, 'found on the homepage'), "a homepage that answered 401 is no homepage without files:\n$output");
    // A homepage that gave no answer (nothing listens on the port, curl 7) is HTTP 000 — checks 5 and 8
    // say "the homepage gave no answer", never "answered HTTP 000, not 200": nothing answered.
    $output = $run('homepage without an answer: checks 5 and 8 not run', '', 1,
        '(?m)^  --   stylesheets, images and fonts not checked: the homepage gave no answer \(HTTP 000\)$',
        ['GENBA_URL' => 'http://127.0.0.1:' . genba_free_port(), 'GENBA_HTTP_URL' => null]);
    contains('  --   private paths not checked: the homepage gave no answer (HTTP 000)', $output, 'check 8 says the homepage gave no answer');
    holds(!str_contains($output, 'answered HTTP 000'), "a homepage without an answer never answered:\n$output");
    // curl below 8.4 (Ubuntu 22.04: 7.81, Debian 12: 7.88) stops at --max-filesize
    // only when the size is announced; a page sent without Content-Length (PHP-FPM, chunked, HTTP/2) is read
    // to its end — the 10 MiB bound of the reader would be gone. Such a curl is played by a wrapper that drops
    // --max-filesize and its value; fetch() holds the size on disk against the limit, for the homepage sent
    // in pieces without Content-Length and for the sitemap alike.
    $oldCurl = genba_tmp('smoke-old-curl');
    try {
        $curlPath = trim((string) shell_exec('command -v curl'));
        genba_write("$oldCurl/curl", "#!/bin/sh\n"
            . "# curl below 8.4 against an unannounced size: --max-filesize has no effect (tests/SmokeTest.php).\n"
            . "skip=0\n"
            . "for a do\n"
            . "  shift\n"
            . "  if [ \"\$skip\" = 1 ]; then skip=0; continue; fi\n"
            . "  if [ \"\$a\" = --max-filesize ]; then skip=1; continue; fi\n"
            . "  set -- \"\$@\" \"\$a\"\n"
            . "done\n"
            . "exec '" . $curlPath . "' \"\$@\"\n");
        chmod("$oldCurl/curl", 0755);
        $oldCurlEnv = ['PATH' => $oldCurl . PATH_SEPARATOR . genba_php_path(), 'GENBA_RETRIES' => '3'];
        foreach (['huge-home-stream', 'huge-home'] as $fault) {
            $output = $run("homepage above 10 MiB ($fault), a curl that reads it all", $fault, 1,
                '(?m)^::error::Homepage larger than 10485760 bytes — not read: its build, its marker and its addresses are not checked$', $oldCurlEnv);
            holds(!str_contains($output, 'Homepage without <meta') && !str_contains($output, 'cannot be read') && !str_contains($output, 'Homepage serves build'),
                "$fault: a homepage too large is one finding, never read:\n$output");
            expect(2, substr_count((string) file_get_contents($requestLog), "GET /\n"), "$fault: fetched once by check 1 (and once as the sitemap's page /)");
        }
        $output = $run('sitemap larger than 10 MiB, a curl that reads it all', 'huge-sitemap', 1,
            '(?m)^::error::GET /sitemap\.xml: larger than 10485760 bytes — not read$', $oldCurlEnv);
        contains('::error::Sitemap not available for page check', $output, 'the sitemap read to its end is not read');
    } finally {
        genba_remove_tree($oldCurl);
    }
    // And curl 8.4 or later on a page without Content-Length: curl stops itself.
    $run('homepage above 10 MiB without Content-Length', 'huge-home-stream', 1,
        '(?m)^::error::Homepage larger than 10485760 bytes — not read: its build, its marker and its addresses are not checked$');
    $slow = genba_tmp('smoke-slow-php');
    try {
        genba_write("$slow/php", "#!/bin/sh\ncase \"\$*\" in *--meta=*) exec sleep 30 ;; esac\nexec '" . PHP_BINARY . "' \"\$@\"\n");
        chmod("$slow/php", 0755);
        $started = microtime(true);
        $output = $run('the reader does not finish', '', 2,
            '(?m)^::error::The homepage cannot be read: the reader did not finish within 2 seconds \(GENBA_MAX_TIME\)$',
            ['PATH' => $slow . PATH_SEPARATOR . (string) getenv('PATH'), 'GENBA_MAX_TIME' => '2']);
        $took = microtime(true) - $started;
        holds($took < 15, sprintf("the reader is stopped after GENBA_MAX_TIME, the run took %.1f s:\n%s", $took, $output));
        holds(!str_contains($output, 'Terminated'), "the stopped reader leaves no job message in the log:\n$output");
    } finally {
        genba_remove_tree($slow);
    }

    // href, src and srcset without quotes — minified HTML (<link rel=stylesheet href=/x.css>)
    // must not read as "no stylesheet" and let a 404 stylesheet pass.
    $output = $run('unquoted attribute values', 'unquoted-attr', 1,
        '(?m)^::error::Stylesheet http://127\.0\.0\.1:\d+/assets/css/missing\.css: HTTP 404, ');
    contains('/media/a.webp: image/webp', $output, 'an unquoted src is requested too');

    // The kind check and the selection both read .CSS as a stylesheet — a 404 stylesheet in
    // capitals is requested.
    $run('stylesheet named in capitals', 'uppercase-css', 1,
        '(?m)^::error::Stylesheet http://127\.0\.0\.1:\d+/assets/css/missing\.CSS: HTTP 404, ');

    // A stylesheet or an asset on another origin is never fetched, so a reference in its address
    // is no finding either (judged after resolve() and same_origin). The parser decodes it; on
    // another origin it is still not fetched.
    $output = $run('reference that is not decoded on another origin', 'foreign-numref', 0, 'Genba smoke: PASSED');
    holds(!str_contains($output, 'not decoded') && !str_contains((string) file_get_contents($requestLog), 'caf'), "a foreign address is neither a finding nor fetched:\n$output");

    // curl's exit code counts — a transfer that broke after the headers
    // (18 "transfer closed with N bytes remaining", 28 timed out before the body) keeps the 200 and
    // leaves a cut body on disk: a cut sitemap would be checked "completely" with fewer pages, a cut
    // homepage would serve the build, a stalled sitemap would print ok and then grep's "No such file".
    // A fetch curl could not finish is HTTP 000 with curl's message, in every finding alike. The text
    // after "curl: (18) " is curl's own and changes between versions (curl 8.14: "end of response with N
    // bytes missing"): checked is that it stands there, not what it says.
    $short = genba_raw_server('short-body', '<!doctype html><html><head><meta name="build" content="' . $hash . '"><link rel="stylesheet" href="/assets/css/tailwind.css"></head><body>');
    try {
        $output = $run('homepage cut short after the headers', '', 1,
            '(?m)^::error::Homepage: HTTP 000 instead of 200 — curl: \(18\) \S.*$',
            ['GENBA_URL' => $short['base']]);
    } finally {
        genba_stop($short);
    }
    holds(!str_contains($output, 'Homepage serves build'), "a cut homepage never serves the build:\n$output");
    holds(preg_match('~(?m)^::error::Unknown address returns HTTP 000 instead of 404 — curl: \(18\) \S~', $output) === 1, "every finding of a fetch curl could not finish names curl's message:\n$output");
    holds(preg_match('~(?m)^::error::GET /robots\.txt: HTTP 000 instead of 200 — curl: \(18\) \S~', $output) === 1, "a check_file finding names it too:\n$output");
    $output = $run('sitemap cut short after the first entry', 'short-sitemap', 1,
        '(?m)^::error::GET /sitemap\.xml: HTTP 000 instead of 200 — curl: \(18\) \S.*$');
    contains('::error::Sitemap not available for page check', $output, 'a cut sitemap is said out loud');
    holds(!str_contains($output, 'sitemap pages fetched'), "no page count from a cut sitemap:\n$output");
    contains('  ok   HEAD /sitemap.xml: 200, application/xml', $output, 'the HEAD of the same file is still fine: only the GET broke');
    $output = $run('sitemap stalls after the headers', 'stall-sitemap', 1,
        '(?m)^::error::GET /sitemap\.xml: HTTP 000 instead of 200 — curl: \(28\) Operation timed out', ['GENBA_MAX_TIME' => '2']);
    holds(!str_contains($output, 'grep:'), "no grep error on a body that never arrived:\n$output");
    holds(!str_contains($output, 'ok   GET /sitemap.xml'), "a stalled sitemap GET is never ok:\n$output");
    contains('::error::Sitemap not available for page check', $output, 'the stalled sitemap is said out loud');

    // curl takes no proxy from the environment (genba-links never did).
    // Behind one, the proxy resolves the name — "does not resolve" would never happen, a pending
    // DNS entry would read as findings with "Proxy CONNECT aborted". A dead proxy in every variable
    // curl reads: the site is reached directly, the run stays green.
    $deadProxy = 'http://127.0.0.1:1';
    $run('a proxy from the environment is not used', '', 0, 'Genba smoke: PASSED',
        ['HTTPS_PROXY' => $deadProxy, 'https_proxy' => $deadProxy, 'http_proxy' => $deadProxy, 'ALL_PROXY' => $deadProxy, 'all_proxy' => $deadProxy]);
    $run('DNS pending behind a proxy is still "does not resolve"', '', 0,
        '\nGenba smoke: SKIPPED — genba-smoke-test\.invalid does not resolve yet',
        $unresolved + ['GENBA_NOT_READY' => 'skip', 'HTTPS_PROXY' => $deadProxy, 'https_proxy' => $deadProxy, 'ALL_PROXY' => $deadProxy]);

    // An empty URL argument ("${{ vars.PREVIEW_URL }}" with the variable unset) must not
    // fall back to GENBA_URL without a word — the other tools refuse an empty --option= too.
    $output = $run('empty URL argument', '', 2, '(?m)^::error::URL argument is empty \(set GENBA_URL or pass the address\)$', [], ['']);
    holds(!str_contains($output, 'Genba smoke against'), "refused before any request, no fall-back to GENBA_URL:\n$output");

    // A NUL byte in the homepage makes grep read the page as binary — GNU
    // grep prints nothing, BSD grep "Binary file … matches" — so the meta, the marker and the
    // stylesheets would be "missing": loud, with the wrong cause. Every grep on a body runs with -a.
    $output = $run('NUL byte in the homepage', 'nul-home', 0, "(?m)^  ok   Homepage serves build $hash$");
    contains("  ok   Marker 'assets/css/tailwind.css' found", $output, 'the marker is found past the NUL byte');
    contains('  ok   Stylesheet: 200, text/css', $output, 'the stylesheet is found past the NUL byte');
    holds(!str_contains($output, 'Binary file'), "no grep binary-file text reaches the log:\n$output");

    // Not ready is decided once, on the first request, and only
    // by a connection that cannot be set up — never by a timeout, never by an
    // expired certificate, never by a later request. A real https fake site
    // (tests/fixtures/tls-server.php), trusted through CURL_CA_BUNDLE; plain
    // http ($base, fault auth) answers 401, so a wrong decision reads
    // "SKIPPED — certificate pending?" and exits 0.
    if (!extension_loaded('openssl')) {
        echo "  --   openssl extension missing, the https not-ready runs are not tested\n";
    } else {
        $expiredDir = genba_tmp('smoke-tls-expired');
        $certDir = genba_tmp('smoke-tls');
        try {
            // Valid for 0 days: expired one second after it was made.
            $expiredAt = time();
            $expired = genba_tls_cert($expiredDir, 0);
            $valid = genba_tls_cert($certDir, 2);
            $tlsRun = function (string $name, string $mode, int $expected, string $pattern, array $env = [], ?array $cert = null) use ($run, $base, $valid): string {
                $cert ??= $valid;
                $tlsServer = genba_tls_server($mode, $cert[0], $cert[1]);
                try {
                    $output = $run($name, 'auth', $expected, $pattern, array_merge([
                        'GENBA_URL' => $tlsServer['base'],
                        'GENBA_HTTP_URL' => "$base/",
                        'GENBA_NOT_READY' => 'skip',
                        'GENBA_MAX_TIME' => '2',
                        'CURL_CA_BUNDLE' => $cert[0],
                    ], $env));
                } finally {
                    genba_stop($tlsServer);
                }
                if ($expected !== 0) {
                    holds(!str_contains($output, 'SKIPPED'), "$name: a run with findings is never SKIPPED\n$output");
                }
                return $output;
            };
            // The environment proves itself: curl trusts the test certificate.
            $tlsServer = genba_tls_server('protected', $valid[0], $valid[1]);
            try {
                [, $probeCode] = genba_run(['curl', '-q', '-sS', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', $tlsServer['base'] . '/'],
                    $tmp, ['CURL_CA_BUNDLE' => $valid[0]]);
            } finally {
                genba_stop($tlsServer);
            }
            if (trim($probeCode) !== '401') {
                throw new RuntimeException("curl does not reach the TLS test server with CURL_CA_BUNDLE (answer: $probeCode)");
            }
            $preview = ['GENBA_EXPECT_PROTECTED' => '1', 'GENBA_USER' => 'preview', 'GENBA_PASSWORD' => 'ge"heim\x'];
            $output = $tlsRun('401 at once, the homepage hangs behind the protection', 'stall-home', 1,
                '(?m)^::error::Homepage: HTTP 000 instead of 200 — curl: \(28\)', $preview);
            contains('  ok   Protected:', $output, 'the gate got its 401 before the homepage hung');
            $tlsRun('TLS breaks after the gate: a finding, not pending', 'flaky', 1,
                '(?m)^::error::Homepage: HTTP 000 instead of 200 — curl: \(35\)', $preview);
            $tlsRun('gate times out: not "not ready"', 'stall-first', 1,
                '(?m)^::error::Without credentials https://127\.0\.0\.1:\d+/ gives no answer \(HTTP 000\) — curl: \(28\)', $preview);
            $tlsRun('first homepage request times out, no gate: not "not ready"', 'stall-first', 1,
                '(?m)^::error::Homepage: HTTP 000 instead of 200 — curl: \(28\)');
            // A certificate the client does not trust (the hoster's default) is pending: skipped.
            $tlsRun('untrusted certificate: pending', 'protected', 0,
                '\nGenba smoke: SKIPPED — https to 127\.0\.0\.1 fails \(certificate pending\?\)\n$', $preview + ['CURL_CA_BUNDLE' => $expired[0]]);
            while (time() <= $expiredAt + 1) {
                usleep(100000);
            }
            $tlsRun('expired certificate: a finding, not pending', 'protected', 1,
                '(?m)^::error::Without credentials https://127\.0\.0\.1:\d+/ gives no answer \(HTTP 000\) — curl: \(60\) SSL certificate problem: certificate has expired', $preview, $expired);
        } finally {
            genba_remove_tree($expiredDir);
            genba_remove_tree($certDir);
        }
    }

    $empty = genba_tmp('smoke-empty');
    $run('no expected build, not a git checkout', '', 2,
        'GENBA_EXPECTED_BUILD not set and the working directory is not a git checkout',
        ['GENBA_EXPECTED_BUILD' => null], [], $empty);
    $run('expected build from GENBA_SHA', '', 0, 'Genba smoke: PASSED', ['GENBA_EXPECTED_BUILD' => null, 'GENBA_SHA' => $hash], [], $empty);
    $run('GENBA_SHA not a hash', '', 2, 'GENBA_SHA is not a commit hash: main', ['GENBA_EXPECTED_BUILD' => null, 'GENBA_SHA' => 'main'], [], $empty);
    // Without git the reason is "git not found", as in genba-stamp — "not a git checkout"
    // would be the wrong cause. A PATH with bash, curl and tr only, what the run needs up to there.
    $noGit = genba_tmp('smoke-nogit');
    try {
        foreach (['bash', 'curl', 'tr'] as $tool) {
            [, $where] = genba_run(['sh', '-c', 'command -v ' . $tool], $tmp);
            symlink(trim($where), "$noGit/$tool");
        }
        $output = $run('no expected build, git not found', '', 2, '(?m)^::error::GENBA_EXPECTED_BUILD not set and git not found$',
            ['GENBA_EXPECTED_BUILD' => null, 'PATH' => $noGit], [], $empty);
        holds(!str_contains($output, 'not a git checkout'), "a missing git is not called a missing checkout:\n$output");
    } finally {
        genba_remove_tree($noGit);
    }

    // The expected build from git: a second fake site serves the HEAD of a
    // throwaway repository, and the tool reads that HEAD itself.
    [$gitProbe] = genba_run(['git', '--version'], $tmp);
    if ($gitProbe !== 0) {
        echo "  --   git not found, \"expected build from git\" not tested\n";
    } else {
        $repo = genba_tmp('smoke-repo');
        [$init] = genba_run(['git', 'init', '-q'], $repo);
        holds($init === 0, 'git init succeeds for the throwaway repository');
        [$commit] = genba_run(['git', '-c', 'user.name=t', '-c', 'user.email=t@x.invalid', '-c', 'commit.gpgsign=false',
            'commit', '-q', '--allow-empty', '-m', 'x'], $repo);
        holds($commit === 0, 'git commit succeeds in the throwaway repository');
        [, $head] = genba_run(['git', 'rev-parse', 'HEAD'], $repo);
        $head = trim($head);
        holds(preg_match('/^[0-9a-f]{40}$/', $head) === 1, "git rev-parse HEAD yields a 40-digit hash: $head");

        $faultFile2 = $tmp . '/fault2';
        genba_write($faultFile2, '');
        $serverGit = genba_server($router, ['GENBA_TEST_BUILD' => $head, 'GENBA_TEST_FAULT_FILE' => $faultFile2], '/assets/css/tailwind.css');
        $gitBase = $serverGit['base'];
        [$code, $output] = genba_run(['bash', $bin], $repo, array_merge($unset, [
            'GENBA_URL' => $gitBase,
            'GENBA_HTTP_URL' => "$gitBase/http-umleitung",
            'GENBA_RETRIES' => '1',
            'GENBA_PAUSE' => '0',
        ]));
        $ok = $code === 0 && preg_match('~Genba smoke: PASSED~', $output) === 1;
        holds($ok, "expected build from git: exit $code (expected 0), output must match /Genba smoke: PASSED/\n--- output:\n$output\n---");
        echo ($ok ? '  ok   ' : '  FAIL ') . "expected build from git (GENBA_EXPECTED_BUILD unset)\n";
    }

    echo "Genba smoke, source:\n";
    $source = (string) file_get_contents($bin);
    holds(is_executable($bin), 'bin/genba-smoke is executable');
    [$syntax, $syntaxOutput] = genba_run(['bash', '-n', $bin], $tmp);
    holds($syntax === 0, "bash -n bin/genba-smoke exits 0:\n$syntaxOutput");
    contains('set -euo pipefail', $source, 'the script sets set -euo pipefail');
    contains('umask 077', $source, 'the credentials file is created under umask 077');
    holds(preg_match('/curl[^|]*(-u|--user)[ =]/', $source) === 0, 'credentials never go to curl as an argument (-u / --user)');
    holds(preg_match('/^CURL_BASIC=\(-q /m', $source) === 1, 'CURL_BASIC starts with -q (curl only honours it as the first argument)');
    holds(preg_match("/^CURL_BASIC=\\([^)]*--noproxy '\\*'/m", $source) === 1, "CURL_BASIC carries --noproxy '*' (no proxy from the environment)");
    // bash's =~ on page text runs byte-wise only inside bytes_match (LC_ALL=C)
    // — but glibc bash matches across a byte that is no UTF-8 in either locale, so on
    // Linux CI the invalid-byte runs above stay green without LC_ALL=C or with a new =~ outside
    // bytes_match. A source check instead: bytes_match sets LC_ALL=C, and every other =~ reads a
    // setting, named in a fixed list of left operands. A new =~ on page text is red on every system.
    $regexCheck = static function (string $source): array {
        $problems = [];
        $settings = ['$URL', '$EXPECTED', '$META', '$entry', '$PAGES_MAX', '$RETRIES', '$PAUSE', '$MAX_TIME', '$SITEMAP_ORIGIN'];
        if (preg_match('~^bytes_match\(\) \(\n((?:.*\n)*?)\)\n~m', $source, $function, PREG_OFFSET_CAPTURE) !== 1) {
            return ['bytes_match() ( … ) not found'];
        }
        if (preg_match('~^\s*LC_ALL=C\s*$~m', $function[1][0]) !== 1) {
            $problems[] = 'bytes_match does not set LC_ALL=C';
        }
        $from = substr_count(substr($source, 0, $function[0][1]), "\n") + 1;
        $to = $from + substr_count($function[0][0], "\n") - 1;
        foreach (explode("\n", $source) as $index => $line) {
            $number = $index + 1;
            if (!str_contains($line, '=~') || preg_match('~^\s*#~', $line) === 1 || ($number > $from && $number < $to)) {
                continue;
            }
            preg_match_all('/"?(\$[A-Za-z_][A-Za-z0-9_]*)"?\s+=~/', $line, $operands);
            $known = array_filter($operands[1], static fn (string $operand): bool => in_array($operand, $settings, true));
            if (count($known) !== substr_count($line, '=~')) {
                $problems[] = "line $number: =~ outside bytes_match on something that is not a setting: " . trim($line);
            }
        }
        return $problems;
    };
    expect([], $regexCheck($source), 'every =~ is in bytes_match (LC_ALL=C) or reads a setting');
    // The check proves itself: a planted =~ on page text, and bytes_match without LC_ALL=C.
    $planted = str_replace("HOME_CODE=\$CODE\n", "HOME_CODE=\$CODE\nif [[ \$STAND =~ ^[0-9a-f]+\$ ]]; then :; fi\n", $source);
    holds($planted !== $source && count($regexCheck($planted)) === 1 && str_contains($regexCheck($planted)[0], '[[ $STAND =~'), 'a planted =~ on page text is named');
    $unset = preg_replace('~^(bytes_match\(\) \(\n)\s*LC_ALL=C\n~m', '$1', $source, 1, $replaced);
    holds($replaced === 1 && $regexCheck((string) $unset) === ['bytes_match does not set LC_ALL=C'], 'bytes_match without LC_ALL=C is named');

    [$lint] = genba_run(['shellcheck', '--version'], $tmp);
    if ($lint === 0) {
        [$lintExit, $lintOutput] = genba_run(['shellcheck', $bin], dirname(__DIR__));
        holds($lintExit === 0, "shellcheck bin/genba-smoke exits 0:\n$lintOutput");
        echo "  ok   shellcheck bin/genba-smoke\n";
    } else {
        echo "  --   shellcheck not installed, bin/genba-smoke not linted\n";
    }
} catch (SmokeNeedsPhp84) {
    // Said above, with the checks of the abort.
} catch (RuntimeException $e) {
    holds(false, 'Smoke test cannot run: ' . $e->getMessage());
} finally {
    if ($server !== null) {
        genba_stop($server);
    }
    if ($serverGit !== null) {
        genba_stop($serverGit);
    }
    if ($repo !== null) {
        genba_remove_tree($repo);
    }
    if ($empty !== null) {
        genba_remove_tree($empty);
    }
    genba_remove_tree($tmp);
}

finish('Smoke');
