<?php
/**
 * Precheck (SPEC §9.4). Kirby-free: loads the class file directly.
 * Run: ASDF_PHP_VERSION=8.5.3 php tests/PrecheckTest.php
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Precheck.php';

use Genba\Precheck;

/** Runs Precheck::run() with memory streams; returns [exit code, stdout, stderr]. */
function precheck_run(string $cwd, array $argv, array $env = []): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = Precheck::run($env, array_merge(['genba-precheck'], $argv), $cwd, $out, $err);
    rewind($out);
    rewind($err);
    $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    fclose($out);
    fclose($err);
    return $result;
}

/**
 * The §9.4 fixture. Five findings: a (inline style), b (inline handler),
 * c (ONE embedded script, line 3), d (foreign host), public x.css (foreign host).
 * Not reported: Places.php (default allow), site/cache, public/media, x.bin.
 */
function precheck_fixture(string $tmp): void
{
    genba_write($tmp . '/site/templates/a.php', "<p style=\"x\">\n");
    genba_write($tmp . '/site/snippets/b.php', "<button onclick=\"x\">go</button>\n");
    genba_write($tmp . '/site/snippets/c.php', "<script src=\"/assets/site.js\"></script>\n"
        . "<script type=\"application/ld+json\">{}</script>\n"
        . "<script>alert(1)</script>\n");
    genba_write($tmp . '/site/snippets/d.php', "<link href=\"https://fonts.googleapis.com/css\">\n");
    genba_write($tmp . '/public/assets/x.css', "@import url(\"//fonts.gstatic.com/x.css\");\n");
    genba_write($tmp . '/site/plugins/koe/src/Places.php', "<?php\n\$u = 'https://places.googleapis.com/v1/places:searchText';\n");
    genba_write($tmp . '/site/cache/junk.php', "<p style=\"x\">\n");
    genba_write($tmp . '/public/media/x.php', "https://www.jimcdn.com/x\n");
    genba_write($tmp . '/site/x.bin', "\0style=\"\n");
}

$packageRoot = dirname(__DIR__);
$dirs = [];

try {
    /* ---- self-test ----------------------------------------------------- */

    expect([], (new Precheck())->selfTest(), 'selfTest() of the shipped rules is empty');
    foreach (Precheck::RULES as $name => $rule) {
        holds(preg_match($rule['pattern'], $rule['sample']) === 1, "shipped rule $name fires on its sample");
    }

    $broken = Precheck::RULES;
    $broken['inline-style']['pattern'] = '~style=ZZZ~';
    $failures = Precheck::selfTestRules($broken);
    // One for the sample and one per positive sample the rule carries — except the attr() samples,
    // which the `attr` name pattern catches, not the line pattern.
    $byPattern = array_filter(Precheck::RULES['inline-style']['positives'] ?? [], static fn (string $p): bool => !str_contains($p, 'attr('));
    expect(1 + count($byPattern), count($failures), 'a broken pattern fails on its sample and on each positive sample it alone catches, nothing else');
    contains('rule inline-style did not fire on its sample', implode("\n", $failures), 'self-test names the broken rule');
    // And a broken `attr` name pattern fails on each attr() positive sample, in both rules that carry one.
    foreach (['inline-style', 'inline-handler'] as $name) {
        $broken = Precheck::RULES;
        $broken[$name]['attr'] = '~^ZZZ$~';
        $byAttr = array_filter(Precheck::RULES[$name]['positives'] ?? [], static fn (string $p): bool => str_contains($p, 'attr('));
        holds(count($byAttr) >= 2, "$name carries attr() positive samples");
        expect(count($byAttr), count(Precheck::selfTestRules($broken)), "a broken attr pattern of $name fails on each attr() positive sample");
    }

    // Negative control: an unanchored `fonts\.` pattern
    // matched the browser API document.fonts.ready — an over-wide pattern must fail the self-test too.
    holds(in_array('document.fonts.ready.then(remeasure)', Precheck::RULES['foreign-host']['negatives'] ?? [], true), 'foreign-host carries the document.fonts negative sample');
    $wide = Precheck::RULES;
    $wide['foreign-host']['pattern'] = '~(googleapis|gstatic|jimcdn|instagram\.com/embed|maps\.google|fonts\.)~';
    $failures = Precheck::selfTestRules($wide);
    expect(["rule foreign-host fired on its negative sample 'document.fonts.ready.then(remeasure)'"], $failures, 'an over-wide pattern fails on its negative sample');

    $uncompilable = Precheck::RULES;
    $uncompilable['inline-handler']['pattern'] = '~(unclosed~';
    $failures = Precheck::selfTestRules($uncompilable);
    contains('rule inline-handler: pattern does not compile', implode("\n", $failures), 'a non-compiling pattern is reported by name');

    /* ---- scan ---------------------------------------------------------- */

    $tmp = genba_tmp('precheck');
    $dirs[] = $tmp;
    precheck_fixture($tmp);
    $roots = ['site' => $tmp . '/site', 'public' => $tmp . '/public'];

    $scanner = new Precheck();
    $findings = $scanner->scan($roots, $tmp);
    $summary = array_map(
        static fn (array $f): string => $f['rule'] . ' ' . $f['file'] . ':' . $f['line'],
        $findings
    );
    expect([
        'foreign-host public/assets/x.css:1',
        'inline-handler site/snippets/b.php:1',
        'embedded-script site/snippets/c.php:3',
        'foreign-host site/snippets/d.php:1',
        'inline-style site/templates/a.php:1',
    ], $summary, 'scan(): exactly the five fixture findings, sorted by file then line');
    expect('<script>alert(1)</script>', $findings[2]['text'] ?? null, 'embedded-script finding shows the trimmed line');
    expect(1, count(array_filter($findings, static fn (array $f): bool => $f['file'] === 'site/snippets/c.php')), 'c.php yields exactly ONE finding');
    expect(false, in_array('site/plugins/koe/src/Places.php', array_column($findings, 'file'), true), 'default allow hides Places.php');
    expect(false, in_array('site/cache/junk.php', array_column($findings, 'file'), true), 'site/cache is skipped');
    expect(false, in_array('public/media/x.php', array_column($findings, 'file'), true), 'public/media is skipped');
    expect(false, in_array('site/x.bin', array_column($findings, 'file'), true), 'binary file is skipped');
    expect(5, count($findings), 'fixture yields exactly 5 findings');
    expect(6, $scanner->filesScanned(), 'filesScanned() counts the text files read (binary and skipped dirs excluded)');

    $unfiltered = (new Precheck([]))->scan($roots, $tmp);
    holds(in_array('site/plugins/koe/src/Places.php', array_column($unfiltered, 'file'), true), 'new Precheck([]) reports Places.php');
    expect(6, count($unfiltered), 'without allow list the fixture yields 6 findings');

    $suppressed = (new Precheck(['fonts.googleapis.com']))->scan($roots, $tmp);
    expect(false, in_array('site/snippets/d.php', array_column($suppressed, 'file'), true), 'custom allow entry suppresses d.php');

    // Three lines that are easy to miss. An attribute right after the closing quote of the one
    // before, and one after a `/` — the HTML tokenizer ends an attribute name at both; and an allowed
    // address next to a forbidden one on the same line (an allow list that freed the whole line would
    // hide it).
    $edge = genba_tmp('precheck-edge');
    $dirs[] = $edge;
    genba_write($edge . '/site/templates/a.php', "<p class=\"a\"style=\"color:red\">\n"
        . "<img src=\"x.png\"/onerror=\"alert(1)\">\n"
        . "<link href=\"https://places.googleapis.com/v1/x https://fonts.googleapis.com/css2\">\n"
        // Controls: the allowed address alone, and the negatives of the rules, stay silent.
        . "<?php \$u = 'https://places.googleapis.com/v1/places:searchText'; ?>\n"
        . "<?php \$style = 'card'; ?><div data-style=\"x\"><i icon=\"x\"><a href=\"?on=1\">\n");
    genba_write($edge . '/public/ok.css', "body{}\n");
    $edgeFindings = array_map(
        static fn (array $f): string => $f['rule'] . ' ' . $f['file'] . ':' . $f['line'],
        (new Precheck())->scan(['site' => $edge . '/site', 'public' => $edge . '/public'], $edge)
    );
    expect([
        'inline-style site/templates/a.php:1',
        'inline-handler site/templates/a.php:2',
        'foreign-host site/templates/a.php:3',
    ], $edgeFindings, 'class="a"style=, src="x"/onerror= and a forbidden host next to an allowed one are findings; the controls are not');
    $edgeAllowed = (new Precheck(['places.googleapis.com/v1/', 'fonts.googleapis.com']))->scan(['site' => $edge . '/site', 'public' => $edge . '/public'], $edge);
    expect(false, in_array('foreign-host', array_column($edgeAllowed, 'rule'), true), 'with both addresses allowed, line 3 is free: every match overlaps an allow entry');

    // An allow entry frees every match in the same address (URL token), not only a match it
    // overlaps: the match `//fonts.` of https://fonts.gstatic.com/s/x.woff2 does not overlap
    // `gstatic.com`, and a site with --allow=gstatic.com must stay green. Two addresses on one line
    // stay two (line 3 above, separated by a space).
    $token = genba_tmp('precheck-token');
    $dirs[] = $token;
    genba_write($token . '/site/templates/f.php', "<link href=\"https://fonts.gstatic.com/s/x.woff2\">\n");
    genba_write($token . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($token, ['--allow=gstatic.com']);
    expect(0, $code, "--allow=gstatic.com frees https://fonts.gstatic.com/… (both matches in the one address):\n$out");
    [$code, $out] = precheck_run($token, []);
    expect(1, $code, "without the entry the address is a finding:\n$out");
    $twoAllowed = (new Precheck(['places.googleapis.com/v1/', 'gstatic.com']))->scan(['site' => $edge . '/site', 'public' => $edge . '/public'], $edge);
    holds(in_array('foreign-host', array_column($twoAllowed, 'rule'), true), 'an entry for another address does not free fonts.googleapis.com/css2 next to places.googleapis.com/v1/x');

    // Where an address ends: if only whitespace, quotes, angle brackets and parentheses ended it, a
    // backtick or a comma would separate nothing, and in a minified bundle the Places URL of the
    // default entry would free the Google Fonts URL beside it (PASSED). The address begins at the
    // last // before the match and ends at the first character no URL holds.
    $bundle = genba_tmp('precheck-bundle');
    $dirs[] = $bundle;
    genba_write($bundle . '/site/templates/home.php', "<p>x</p>\n");
    genba_write($bundle . '/public/assets/css/tailwind.css', "body{}\n");
    genba_write($bundle . '/public/assets/js/app.js', "const a=`https://places.googleapis.com/v1/\${id}`,b=`https://fonts.googleapis.com/css2?family=Inter`;\n"
        . "preconnect=https://places.googleapis.com/v1/,https://fonts.gstatic.com\n"
        . "const p=`https://places.googleapis.com/v1/\${id}`;\n"
        // The other way round, without a second scheme: the end of the address is the comma, the backtick.
        . "urls=https://fonts.gstatic.com/s.woff2,places.googleapis.com/v1/\n"
        . "x=`https://fonts.gstatic.com/s.woff2`+`places.googleapis.com/v1/`\n"
        // Two addresses run together: the next scheme:// ends the first.
        . "https://fonts.gstatic.com/s.woff2https://places.googleapis.com/v1/\n");
    [$code, $out] = precheck_run($bundle, []);
    expect(1, $code, "a foreign address beside the allowed one, separated by a backtick or a comma, is a finding:\n$out");
    contains('::error::public/assets/js/app.js:1: foreign host', $out, 'the fonts URL behind `, in a template literal is a finding');
    contains('::error::public/assets/js/app.js:2: foreign host', $out, 'the gstatic URL behind a comma is a finding');
    holds(!str_contains($out, 'app.js:3:'), "the allowed Places URL alone in a template literal stays free:\n$out");
    contains('::error::public/assets/js/app.js:4: foreign host', $out, 'an address ends at a comma: the allow entry behind it frees nothing before it');
    contains('::error::public/assets/js/app.js:5: foreign host', $out, 'an address ends at a backtick');
    contains('::error::public/assets/js/app.js:6: foreign host', $out, 'an address ends where the next scheme:// begins');
    contains('FAILED — 5 findings', $out, 'exactly the five lines with a foreign address');
    holds(count(Precheck::RULES['foreign-host']['positives'] ?? []) >= 2, 'foreign-host carries the two lines as positive samples');
    // The self-test checks those positives with the default allow list: a rule that frees them fails it.
    $freed = Precheck::RULES;
    $freed['foreign-host']['positives'][] = 'https://places.googleapis.com/v1/x';
    expect(["rule foreign-host did not fire on its positive sample 'https://places.googleapis.com/v1/x' with the default allow list"],
        Precheck::selfTestRules($freed), 'a positive sample the default allow list frees fails the self-test, named as such');

    $missingRoot = ['site' => $tmp . '/nope', 'public' => $tmp . '/public'];
    try {
        (new Precheck())->scan($missingRoot, $tmp);
        holds(false, 'a missing root throws');
    } catch (RuntimeException $e) {
        expect('Directory not found: site', $e->getMessage(), 'missing root throws "Directory not found: site"');
    }

    /* ---- requiredFiles ------------------------------------------------- */

    $req = $tmp . '/req';
    @mkdir($req, 0700, true);
    $relative = ['req/missing.css', 'req/empty.css', 'req/full.css'];
    genba_write($req . '/empty.css', '');
    genba_write($req . '/full.css', 'twelve bytes');
    $checks = Precheck::requiredFiles($relative, $tmp);
    expect(['file' => 'req/missing.css', 'ok' => false, 'bytes' => null], $checks[0], 'missing file: bytes null, ok false');
    expect(['file' => 'req/empty.css', 'ok' => false, 'bytes' => 0], $checks[1], 'empty file: bytes 0, ok false');
    expect(['file' => 'req/full.css', 'ok' => true, 'bytes' => 12], $checks[2], 'file with 12 bytes: ok true');

    /* ---- run() --------------------------------------------------------- */

    [$code, $out] = precheck_run($tmp, ['--site=site', '--public=public', '--require=none']);
    expect(1, $code, 'run() with findings exits 1');
    contains('::error::site/templates/a.php:1: inline style — the CSP forbids it: <p style="x">', $out, 'run() prints the finding line');
    contains('Genba precheck: FAILED — 5 findings', $out, 'run() summary names 5 findings');
    contains('  --   no required files configured (--require=none)', $out, 'run() --require=none prints the not-checked line, named by its origin');

    [$code, $out] = precheck_run($tmp, ['--site=site', '--public=public', '--require=none'], ['GENBA_ALLOW' => 'none']);
    expect(1, $code, 'GENBA_ALLOW=none exits 1');
    contains('::error::site/plugins/koe/src/Places.php:2: foreign host', $out, 'GENBA_ALLOW=none reports Places.php');

    $clean = genba_tmp('precheck-clean');
    $dirs[] = $clean;
    genba_write($clean . '/site/index.php', "<p>ok</p>\n");
    genba_write($clean . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($clean, []);
    expect(0, $code, 'clean project with the css file exits 0');
    contains('Genba precheck: PASSED (2 files scanned)', $out, 'clean run prints PASSED with the file count');
    contains('  ok   public/assets/css/tailwind.css (15 bytes)', $out, 'clean run lists the required file');

    genba_remove_tree($clean . '/public/assets/css');
    [$code, $out] = precheck_run($clean, []);
    expect(1, $code, 'missing stylesheet exits 1');
    contains('::error::Required file missing or empty: public/assets/css/tailwind.css', $out, 'missing stylesheet is reported');
    contains('FAILED — 1 required files missing', $out, 'summary names the missing file');

    [$code, $out] = precheck_run($clean, ['--require=none']);
    expect(0, $code, '--require=none on a clean project exits 0');
    contains('  --   no required files configured', $out, '--require=none prints the not-checked line');

    // The not-checked line names where the `none` came from: --require=none, not GENBA_REQUIRE_FILES=none
    // (a variable that is not set) — as genba-preflight names --hook=none. Both origins.
    contains('  --   no required files configured (--require=none)', $out, '--require=none is named as such');
    holds(!str_contains($out, 'GENBA_REQUIRE_FILES=none'), "--require=none does not name the variable:\n$out");
    [$code, $out] = precheck_run($clean, [], ['GENBA_REQUIRE_FILES' => 'none']);
    expect(0, $code, 'GENBA_REQUIRE_FILES=none on a clean project exits 0');
    contains('  --   no required files configured (GENBA_REQUIRE_FILES=none)', $out, 'GENBA_REQUIRE_FILES=none is named as such');

    // `none` in a list: --require=none,<file> must not check a file called "none". Like --allow:
    // none is no file name, it drops the default, the other entries count.
    genba_write($clean . '/build/x.css', 'x{}');
    foreach ([[['--require=none,build/x.css'], []], [[], ['GENBA_REQUIRE_FILES' => 'build/x.css, none']]] as [$argv, $env]) {
        [$code, $out] = precheck_run($clean, $argv, $env);
        $given = $argv === [] ? 'GENBA_REQUIRE_FILES=' . $env['GENBA_REQUIRE_FILES'] : $argv[0];
        expect(0, $code, "$given exits 0:\n$out");
        contains('  ok   build/x.css (3 bytes)', $out, "$given checks the listed file");
        holds(!str_contains($out, 'missing or empty: none'), "$given checks no file called none:\n$out");
        holds(!str_contains($out, 'no required files configured') && !str_contains($out, 'tailwind.css'), "$given: the list counts, no default and no not-checked line:\n$out");
    }
    [$code, $out] = precheck_run($clean, ['--require=none,build/missing.css']);
    expect(1, $code, '--require=none,<missing file> exits 1');
    contains('::error::Required file missing or empty: build/missing.css', $out, 'the listed file next to none is still required');

    $empty = genba_tmp('precheck-nosite');
    $dirs[] = $empty;
    [$code, $out] = precheck_run($empty, ['--require=none']);
    expect(2, $code, 'missing site/ exits 2');
    contains('::error::Directory not found: site', $out, 'missing site/ is named');

    @mkdir($empty . '/site', 0700, true);
    @mkdir($empty . '/public', 0700, true);
    [$code, $out] = precheck_run($empty, ['--require=none']);
    expect(2, $code, 'empty roots exit 2 (absent is not zero)');
    contains('::error::No files found under site, public', $out, 'empty roots are reported, not PASSED');
    holds(!str_contains($out, 'PASSED'), 'empty roots never print PASSED');

    // `--require=,` names no file: a usage error, not a silent "no file check". The reason
    // is an output line on stdout with the tool's last line (SPEC §3.0); usage goes to stderr.
    [$code, $out, $err] = precheck_run($clean, ['--require=,']);
    expect(2, $code, '--require=, exits 2');
    contains("::error::--require lists no file: ','", $out, '--require=, is named on stdout');
    holds(preg_match('~\nGenba precheck: FAILED — cannot run\n$~', $out) === 1, "--require=, ends with the Genba precheck line:\n$out");
    contains('Usage: genba-precheck', $err, '--require=, prints the usage on stderr');
    holds(!str_contains($out, 'PASSED'), '--require=, never prints PASSED');
    [$code, $out] = precheck_run($clean, [], ['GENBA_REQUIRE_FILES' => ' , ']);
    expect(2, $code, 'GENBA_REQUIRE_FILES=" , " exits 2');
    contains('::error::GENBA_REQUIRE_FILES lists no file', $out, 'GENBA_REQUIRE_FILES without a file is named on stdout');

    // Every exit-2 path ends with the tool's line on stdout (SPEC §3.0), the reason
    // is an ::error:: line on stderr, the usage follows it — the same shape as genba-links.
    [$code, $out, $err] = precheck_run($clean, ['--bogus']);
    expect(2, $code, 'unknown argument exits 2');
    contains('::error::Unknown argument: --bogus', $err, 'unknown argument is named on stderr as an ::error:: line');
    contains('Usage: genba-precheck', $err, 'unknown argument prints usage on stderr');
    expect("Genba precheck: FAILED — cannot run\n", $out, 'unknown argument: stdout holds the last line alone');
    [$code, $out, $err] = precheck_run($clean, ['--site=']);
    expect(2, $code, '--site= (empty) exits 2');
    contains('::error::--site needs a value', $err, '--site= is named on stderr');
    expect("Genba precheck: FAILED — cannot run\n", $out, '--site=: stdout holds the last line alone');

    // An argument error wins over --help, whatever the order.
    foreach ([['--help', '--bogus'], ['--bogus', '--help']] as $order) {
        [$code, $out, $err] = precheck_run($clean, $order);
        expect(2, $code, implode(' ', $order) . ' exits 2');
        contains('::error::Unknown argument: --bogus', $err, implode(' ', $order) . ': the unknown argument is named');
        expect("Genba precheck: FAILED — cannot run\n", $out, implode(' ', $order) . ': stdout holds the last line alone');
    }

    [$code, $out] = precheck_run($clean, ['--help']);
    expect(0, $code, '--help exits 0');
    contains('--site=DIR', $out, '--help prints usage on stdout');
    // The usage describes the allow list as it works: per match in the same address, not per line.
    contains('substrings that free a foreign-host match in the same address', $out, 'the usage describes the allow list per match');

    /* ---- skipped by place, not by name; symbolic links ----------------- */

    // A snippet folder called media/ is site code: only public/media (and site/cache …) are generated.
    $place = genba_tmp('precheck-place');
    $dirs[] = $place;
    genba_write($place . '/site/snippets/media/image.php', "<img style=\"x\" onload=\"y()\">\n");
    genba_write($place . '/site/cache/page.php', "<p style=\"x\">\n");
    genba_write($place . '/site/plugins/x/node_modules/lib.js', "<p style=\"x\">\n");
    genba_write($place . '/public/media/pages/x.php', "<p style=\"x\">\n");
    genba_write($place . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($place, []);
    expect(1, $code, "a site/snippets/media/ folder is scanned, its findings fail the run:\n$out");
    contains('::error::site/snippets/media/image.php:1: inline style', $out, 'inline style in site/snippets/media/ is a finding');
    contains('::error::site/snippets/media/image.php:1: inline event handler', $out, 'inline handler in site/snippets/media/ is a finding');
    contains('  --   not checked: site/cache (Kirby cache)', $out, 'site/cache is skipped with a visible line');
    contains('  --   not checked: public/media (Kirby media folder, generated)', $out, 'public/media is skipped with a visible line');
    contains('  --   not checked: site/plugins/x/node_modules (npm dependencies)', $out, 'node_modules is skipped at any depth, visibly');
    contains('FAILED — 2 findings', $out, 'exactly the two findings of site/snippets/media/image.php');

    // Case does not matter: <P STYLE="x"> is the same CSP violation as <p style="x">,
    // and <SCRIPT SRC="x"> is as much a script with a source as the lower-case one.
    $cased = genba_tmp('precheck-case');
    $dirs[] = $cased;
    genba_write($cased . '/site/templates/upper.php', "<p STYLE=\"x\"><SCRIPT>alert(1)</SCRIPT><a ONCLICK=\"y\">\n"
        . "<SCRIPT SRC=\"/x.js\"></SCRIPT>\n"
        . "<LINK HREF=\"HTTPS://FONTS.GSTATIC.COM/x\">\n"
        . "<SCRIPT TYPE=\"application/ld+json\">{}</SCRIPT>\n");
    genba_write($cased . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($cased, []);
    expect(1, $code, "upper-case markup is found:\n$out");
    contains('::error::site/templates/upper.php:1: inline style', $out, 'STYLE= is an inline style');
    contains('::error::site/templates/upper.php:1: inline event handler', $out, 'ONCLICK= is an inline handler');
    contains('::error::site/templates/upper.php:1: embedded <script>', $out, '<SCRIPT> is an embedded script');
    contains('::error::site/templates/upper.php:3: foreign host', $out, 'FONTS.GSTATIC.COM is a foreign host');
    holds(!str_contains($out, 'upper.php:2:') && !str_contains($out, 'upper.php:4:'), "<SCRIPT SRC> and the JSON-LD block are not findings:\n$out");
    contains('FAILED — 4 findings', $out, 'exactly the four upper-case findings');

    // An event handler whose name is built at runtime (on followed by a PHP block) is a finding, with or without the =.
    $runtime = genba_tmp('precheck-runtime');
    $dirs[] = $runtime;
    genba_write($runtime . '/site/templates/runtime.php', "<div on<?= \$event ?>=\"go()\">\n<p on<?= \$e ?>>x</p>\n<i one<?= \$x ?>=\"y\">\n");
    genba_write($runtime . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($runtime, []);
    expect(1, $code, "a runtime event handler name is a finding:\n$out");
    contains('::error::site/templates/runtime.php:1: inline event handler with a name built at runtime', $out, 'on<?= … ?>="…" is a finding');
    contains('::error::site/templates/runtime.php:2: inline event handler with a name built at runtime', $out, 'on<?= … ?> without = is a finding');
    holds(!str_contains($out, 'runtime.php:3:'), "one<?= … ?> is no event handler name:\n$out");
    contains('FAILED — 2 findings', $out, 'exactly the two runtime findings');

    // style= and on…= in every spelling the browser accepts — single quotes, spaces around =,
    // any event name — and NOT a PHP variable $style, a data-style attribute, icon= or json=.
    $spelled = genba_tmp('precheck-spelling');
    $dirs[] = $spelled;
    genba_write($spelled . '/site/templates/spelled.php', "<p style='color:red'>\n"
        . "<form onsubmit=\"x()\">\n"
        . "<p style = \"x\">\n"
        . "<a onmouseenter = 'y()'>\n"
        . "<?php \$style = 'card'; \$on = 1; ?>\n"
        . "<div data-style=\"x\" icon=\"y\" json=\"z\">\n"
        . "<a href=\"?on=1\" title=\"switched on=\">\n"
        // An unquoted value (valid HTML5) and an attribute on a line of its own, at the start
        // of the line.
        . "<p style=color:red>\n"
        . "style=\"x\">\n");
    genba_write($spelled . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($spelled, []);
    expect(1, $code, "other spellings of style= and on…= are found:\n$out");
    contains('::error::site/templates/spelled.php:1: inline style', $out, "style='…' (single quotes) is an inline style");
    contains('::error::site/templates/spelled.php:2: inline event handler', $out, 'onsubmit= is an inline handler');
    contains('::error::site/templates/spelled.php:3: inline style', $out, 'style = "x" (spaces) is an inline style');
    contains('::error::site/templates/spelled.php:4: inline event handler', $out, "onmouseenter = '…' is an inline handler");
    holds(!str_contains($out, 'spelled.php:5:') && !str_contains($out, 'spelled.php:6:') && !str_contains($out, 'spelled.php:7:'),
        "\$style, data-style, icon=, json= and ?on= are not findings:\n$out");
    contains('::error::site/templates/spelled.php:8: inline style', $out, 'style=color:red (no quotes) is an inline style');
    contains('::error::site/templates/spelled.php:9: inline style', $out, 'style="x" at the start of a line is an inline style');
    contains('FAILED — 6 findings', $out, 'exactly the six spelled findings');
    holds(count(Precheck::RULES['inline-style']['negatives'] ?? []) > 0, 'inline-style carries negative samples for its self-test');
    holds(count(Precheck::RULES['inline-handler']['negatives'] ?? []) > 0, 'inline-handler carries negative samples for its self-test');

    // The exception words of embedded-script count per <script …> tag, not per line: on the line,
    // `src=` anywhere would exempt an embedded script next to an external one, and a script whose
    // code says `el.src=`.
    $tagged = genba_tmp('precheck-tags');
    $dirs[] = $tagged;
    genba_write($tagged . '/site/templates/tags.php', "<script src=\"/a.js\"></script><script>window.evil = 1</script>\n"
        . "<script>el.src=\"/x.js\"</script>\n"
        . "<script src=\"/a.js\"></script><script type=\"application/ld+json\">{}</script>\n"
        . "<script data-src=\"/b.js\">x()</script>\n"
        . "<script src = '/c.js'></script>\n");
    genba_write($tagged . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($tagged, []);
    expect(1, $code, "an embedded script next to an external one is found:\n$out");
    contains('::error::site/templates/tags.php:1: embedded <script>', $out, 'the embedded script behind an external one on the same line is a finding');
    contains('::error::site/templates/tags.php:2: embedded <script>', $out, 'a script whose code contains src= is a finding');
    contains('::error::site/templates/tags.php:4: embedded <script>', $out, 'data-src is not a src attribute');
    holds(!str_contains($out, 'tags.php:3:') && !str_contains($out, 'tags.php:5:'), "an external script and a JSON-LD block, in any spelling, are not findings:\n$out");
    contains('FAILED — 3 findings', $out, 'exactly the three embedded scripts');
    // A src (or the JSON-LD type) that Kirby's attr() helper prints, read from the
    // array literal of a PHP block where an attribute stands — never from a block inside a value, never from
    // attr() without a literal array, never from 'src' => null.
    $attrProject = genba_tmp('precheck-attr');
    $dirs[] = $attrProject;
    genba_write($attrProject . '/site/templates/attr.php', "<script<?= attr(['src' => \$url, 'nonce' => \$kirby->nonce()], ' ') ?>></script>\n"
        . "<script <?= attr(['type' => 'application/ld+json']) ?>>{}</script>\n"
        . "<script<?= attr(['nonce' => \$kirby->nonce()], ' ') ?>>x()</script>\n"
        . "<script data-x=\"<?= attr(['src' => \$url]) ?>\">x()</script>\n"
        . "<script<?= attr(['src' => null], ' ') ?>>x()</script>\n"
        . "<script<?= attr(\$attributes, ' ') ?>>x()</script>\n");
    genba_write($attrProject . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($attrProject, []);
    holds(!str_contains($out, 'attr.php:1:') && !str_contains($out, 'attr.php:2:'), "a src and a JSON-LD type from attr() are no finding:\n$out");
    foreach ([3, 4, 5, 6] as $line) {
        contains("::error::site/templates/attr.php:$line: embedded <script>", $out, "attr() without a provable src stays a finding (line $line)");
    }
    expect(1, $code, "attr() runs: exit 1 for the four embedded scripts:\n$out");

    // Only a src attr() prints for sure frees a script — not one that may be null
    // (?:, ??, ?->), not an attr() in one branch of a condition or inside another call's value. Kirby leaves
    // a null src out (Xml::attr()), and <script nonce="n">init()</script> is an embedded script.
    $maybe = genba_tmp('precheck-attr-maybe');
    $dirs[] = $maybe;
    genba_write($maybe . '/site/templates/maybe.php',
        "<script<?= attr(['src' => \$page->tracker()->value() ?: null, 'nonce' => \$kirby->nonce()], ' ') ?>>init()</script>\n"
        . "<script<?= \$external ? attr(['src' => \$u], ' ') : '' ?>>inline()</script>\n"
        . "<script<?= attr(['nonce' => attr(['src' => 'x'])], ' ') ?>>evil()</script>\n"
        . "<script<?= attr(['src' => \$file?->url()], ' ') ?>>x()</script>\n"
        . "<script<?= attr(['src' => \$u ?? null], ' ') ?>>x()</script>\n"
        . "<script<?= attr(['defer', 'src' => \$url], ' '); ?>></script>\n"
        . "<script<?= Html::attr(['src' => \$url], null, ' ') ?>></script>\n");
    genba_write($maybe . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($maybe, []);
    foreach ([1, 2, 3, 4, 5] as $line) {
        contains("::error::site/templates/maybe.php:$line: embedded <script>", $out, "a src attr() may not print frees nothing (line $line)");
    }
    holds(!str_contains($out, 'maybe.php:6:') && !str_contains($out, 'maybe.php:7:'), "a src attr() prints for sure frees the script (a list item, Html::attr with \$before):\n$out");

    // What attr() prints is an attribute for every rule that forbids one, not only for freeing a
    // script: a style and an on… handler printed by attr() are findings. Any call counts (a branch too: it may print), an array literal over several lines is read
    // whole and reported on the line of its key; a key with null, false or [] prints nothing.
    $styled = genba_tmp('precheck-attr-style');
    $dirs[] = $styled;
    genba_write($styled . '/site/templates/styled.php',
        "<div<?= attr(['class' => 'card', 'style' => '--accent: ' . \$page->color()], ' ') ?>>x</div>\n"
        . "<button<?= attr(['type' => 'button', 'onclick' => 'toggle()'], ' ') ?>>y</button>\n"
        . "<div<?= attr([\n"
        . "  'class' => 'card',\n"
        . "  'style' => \$css,\n"
        . "], ' ') ?>>z</div>\n"
        . "<div<?= \$wide ? attr(['style' => \$s], ' ') : '' ?>>w</div>\n"
        . "<details<?= attr(['ontoggle'], ' ') ?>>v</details>\n"
        . "<div<?= attr(['style' => null, 'onclick' => false, 'onload' => []], ' ') ?>>no</div>\n"
        . "<div<?= attr(['data-style' => \$s, 'class' => 'style', 'data-on' => 'onclick'], ' ') ?>>no</div>\n"
        . "<div<?= attr(\$attributes, ' ') ?>>unknown</div>\n"
        . "<?php \$style = ['style' => 'x']; // an array, no attr() call ?>\n");
    genba_write($styled . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($styled, []);
    expect(1, $code, "attr() printing style or on… is a finding:\n$out");
    contains('::error::site/templates/styled.php:1: inline style', $out, 'a style printed by attr() is an inline style');
    contains('::error::site/templates/styled.php:2: inline event handler', $out, 'an onclick printed by attr() is an inline handler');
    contains("::error::site/templates/styled.php:5: inline style — the CSP forbids it: 'style' => \$css,", $out, 'a multi-line attr() array is read whole, the finding on the line of the key');
    contains('::error::site/templates/styled.php:7: inline style', $out, 'an attr() in a branch may print, and counts');
    contains('::error::site/templates/styled.php:8: inline event handler', $out, 'a list item prints its name: ontoggle');
    expect(5, substr_count($out, '::error::'), "exactly the five findings, nothing for null/false/[], data-style, attr(\$attributes) or an array without attr():\n$out");

    // Every form in which attr() prints a name — the table in SPEC §3.3, row by row and in both
    // directions: not only an array literal as the first positional argument, but also the single form
    // Html::attr('style', $css, ' ') and PHP 8 named arguments (attr: [...]); and an external script whose
    // src comes from Html::attr('src', $u, ' ') is no finding. Each row is run through the self-test of
    // its rule alone, as a positive sample (fires) or a negative one (silent).
    // [rule, line, fires]; for embedded-script, "fires" means the script is NOT freed.
    $attrForms = [
        // array literal, by position
        ['inline-style', "<div<?= attr(['style' => \$s], ' ') ?>>", true],
        ['inline-handler', "<button<?= Html::attr(array('onclick' => 'go()')) ?>>", true],
        ['embedded-script', "<script<?= attr(['src' => \$u], ' ') ?>></script>", false],
        // array literal, named (the helper's attr:, Html::attr()'s name:, in any order)
        ['inline-style', "<div<?= attr(attr: ['style' => \$s], before: ' ') ?>>", true],
        ['inline-style', "<div<?= attr(before: ' ', attr: ['style' => \$s]) ?>>", true],
        ['inline-handler', "<button<?= Html::attr(name: ['onclick' => 'go()']) ?>>", true],
        ['embedded-script', "<script<?= attr(attr: ['src' => \$u], before: ' ') ?>></script>", false],
        ['embedded-script', "<script<?= Html::attr(before: ' ', name: ['src' => \$u]) ?>></script>", false],
        // array literal with $value
        ['inline-style', "<div<?= Html::attr(['style' => \$s], null, ' ') ?>>", true],
        ['embedded-script', "<script<?= Html::attr(['src' => \$u], null, ' ') ?>></script>", false],
        // single name, by position (Html lower-cases it)
        ['inline-style', "<div<?= Html::attr('style', \$css, ' ') ?>>", true],
        ['inline-style', "<div<?= \\Kirby\\Toolkit\\Html::attr('STYLE', \$css, ' ') ?>>", true],
        ['inline-handler', "<button<?= Html::attr('onclick', 'go()', ' ') ?>>", true],
        ['embedded-script', "<script<?= Html::attr('src', \$u, ' ') ?>></script>", false],
        ['embedded-script', "<script<?= Html::attr('type', 'application/ld+json', ' ') ?>>{}</script>", false],
        ['embedded-script', "<script<?= Html::attr('type', 'module', ' ') ?>>x()</script>", true],
        // single name, named, also mixed with a positional one
        ['inline-style', "<div<?= Html::attr(name: 'style', value: \$css) ?>>", true],
        ['inline-style', "<div<?= Html::attr(value: \$css, name: 'style') ?>>", true],
        ['inline-style', "<div<?= Html::attr('style', \$css, before: ' ') ?>>", true],
        ['inline-style', "<div<?= Html::attr('style', value: \$css) ?>>", true],
        ['embedded-script', "<script<?= Html::attr(name: 'src', value: \$u, before: ' ') ?>></script>", false],
        ['embedded-script', "<script<?= Html::attr(name: 'type', value: 'application/ld+json', before: ' ') ?>>{}</script>", false],
        // single name, true: the name alone
        ['inline-handler', "<button<?= Html::attr('onclick', true, ' ') ?>>", true],
        ['embedded-script', "<script<?= Html::attr('src', true, ' ') ?>>x()</script>", true],
        // single name, no value / null / false / []: nothing printed
        ['inline-style', "<div<?= Html::attr('style') ?>>", false],
        ['inline-style', "<div<?= Html::attr('style', null, ' ') ?>>", false],
        ['inline-style', "<div<?= Html::attr('style', false) ?>>", false],
        ['inline-style', "<div<?= Html::attr('style', []) ?>>", false],
        // array() is the same empty array as []: in any case, with space inside, single form and array item
        ['inline-style', "<div<?= Html::attr('style', array()) ?>>", false],
        ['inline-style', "<div<?= attr(['style' => ARRAY( )], ' ') ?>>", false],
        ['embedded-script', "<script<?= Html::attr('src', array(), ' ') ?>>inline()</script>", true],
        ['embedded-script', "<script<?= attr(['src' => array()], ' ') ?>>inline()</script>", true],
        ['inline-style', "<div<?= Html::attr(name: 'style', before: ' ') ?>>", false],
        ['inline-handler', "<button<?= Html::attr('onclick', false, ' ') ?>>", false],
        ['embedded-script', "<script<?= Html::attr('src') ?>>x()</script>", true],
        ['embedded-script', "<script<?= Html::attr('src', null, ' ') ?>>x()</script>", true],
        // single name, a value that may be null
        ['inline-style', "<div<?= Html::attr('style', \$s ?: null) ?>>", true],
        ['embedded-script', "<script<?= Html::attr('src', \$u ?? null, ' ') ?>>x()</script>", true],
        ['embedded-script', "<script<?= \$ext ? Html::attr('src', \$u, ' ') : '' ?>>x()</script>", true],
        // another name, names unknown
        ['inline-style', "<div<?= Html::attr('data-style', \$s, ' ') ?>>", false],
        ['inline-style', "<div<?= attr(\$attributes) ?>>", false],
        ['inline-style', "<div<?= attr(...\$attributes) ?>>", false],
        ['inline-style', "<div<?= Html::attr(\$name, \$css) ?>>", false],
        ['inline-handler', "<button<?= Html::attr('on' . 'click', 'go()') ?>>", false],
        ['embedded-script', "<script<?= Html::attr(\$name, \$u, ' ') ?>>x()</script>", true],
        ['embedded-script', "<script<?= attr(...\$attributes) ?>>x()</script>", true],
    ];
    foreach ($attrForms as [$rule, $line, $fires]) {
        $single = [$rule => array_merge(Precheck::RULES[$rule], ['positives' => $fires ? [$line] : [], 'negatives' => $fires ? [] : [$line]])];
        expect([], Precheck::selfTestRules($single), "attr() form, $rule " . ($fires ? 'fires on' : 'is silent on') . ": $line");
    }
    // The same forms through the scan of a template (attrNames() reads the whole file): three findings,
    // the external script of line 4 none.
    $forms = genba_tmp('precheck-attr-forms');
    $dirs[] = $forms;
    genba_write($forms . '/site/templates/probe.php', "<div<?= Html::attr('style', \$css, ' ') ?>>1</div>\n"
        . "<button<?= Html::attr('onclick', 'go()', ' ') ?>>2</button>\n"
        . "<div<?= attr(attr: ['style' => 'color:red'], before: ' ') ?>>3</div>\n"
        . "<script<?= Html::attr('src', \$u, ' ') ?>></script>\n");
    genba_write($forms . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($forms, []);
    contains('::error::site/templates/probe.php:1: inline style', $out, 'Html::attr(\'style\', $css) is an inline style');
    contains('::error::site/templates/probe.php:2: inline event handler', $out, 'Html::attr(\'onclick\', …) is an inline handler');
    contains('::error::site/templates/probe.php:3: inline style', $out, 'attr(attr: [...]) is read');
    holds(!str_contains($out, 'probe.php:4:'), "Html::attr('src', \$u, ' ') frees the script:\n$out");
    expect([1, 3], [$code, substr_count($out, '::error::')], "exactly the three findings:\n$out");

    holds(count(Precheck::RULES['embedded-script']['negatives'] ?? []) > 0, 'embedded-script carries negative samples for its self-test');
    holds(count(Precheck::RULES['embedded-script']['positives'] ?? []) > 0, 'embedded-script carries a second positive sample for its self-test');
    // The self-test judges a negative sample with the whole rule, and names a positive one that stays silent.
    // A line-wise rule fails it: no `each`, and the exemption a pattern on the whole line instead of
    // one bound to the attribute (`exempt`).
    $lineWise = Precheck::RULES;
    unset($lineWise['embedded-script']['each'], $lineWise['embedded-script']['exempt']);
    $lineWise['embedded-script']['unless'] = ['~(^|[\s"\'/])src\s*=~i', '~application/ld\+json~i'];
    $failures = Precheck::selfTestRules($lineWise);
    expect(["rule embedded-script did not fire on its positive sample '<script src=\"/a.js\"></script><script>x()</script>'",
        "rule embedded-script did not fire on its positive sample '<script data-hint=\"loaded with src=/assets/js/app.js\">track()</script>'",
        "rule embedded-script did not fire on its positive sample '<script title='see application/ld+json'>track()</script>'",
        "rule embedded-script did not fire on its positive sample '<script title=it's>x()</script><script src='/a.js'>'</script>'",
        // And it fires on the src that Kirby's attr() prints.
        "rule embedded-script fired on its negative sample '<script<?= attr(['src' => \$url, 'nonce' => \$kirby->nonce()], ' ') ?>></script>'",
        "rule embedded-script fired on its negative sample '<script<?= Html::attr(['defer' => true, \"src\" => url('assets/js/app.js')], null, ' ') ?>></script>'",
        "rule embedded-script fired on its negative sample '<script<?= attr(['defer', 'src' => \$url], ' '); ?>></script>'",
        // And on the src of the single form Html::attr('src', $u).
        "rule embedded-script fired on its negative sample '<script<?= Html::attr('src', \$u, ' ') ?>></script>'"],
        $failures, 'the line-wise rule fails the self-test on its positive samples and on the attr() negatives');

    // A <script tag a formatter wrapped over several lines holds no complete tag on its line for
    // `each` to judge, so the tag is read on over the lines below (up to 50); an external script
    // written so is free, one without a > in reach is a finding.
    $wrapped = genba_tmp('precheck-wrapped');
    $dirs[] = $wrapped;
    genba_write($wrapped . '/site/templates/module.php', "<script\n  type=\"module\">\n  alert(1)\n</script>\n");
    genba_write($wrapped . '/site/templates/external.php', "<script\n  src=\"/a.js\"\n  defer\n></script>\n");
    genba_write($wrapped . '/site/templates/open.php', "<p>x</p><script\n" . str_repeat("  data-x=1\n", 60));
    genba_write($wrapped . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($wrapped, []);
    expect(1, $code, "a <script tag over several lines is judged:\n$out");
    contains('::error::site/templates/module.php:1: embedded <script> — the CSP forbids it: <script', $out, 'the wrapped embedded script is a finding, on the line of <script');
    contains('::error::site/templates/open.php:1: embedded <script>', $out, 'a <script without a > within 50 lines is a finding, not a silent pass');
    holds(!str_contains($out, 'external.php'), "a wrapped external script (src on the next line) is not a finding:\n$out");
    contains('FAILED — 2 findings', $out, 'exactly the two findings');

    // An attribute whose = a formatter put on a line below its name (style⏎="…", onclick⏎⏎  ="…") is
    // the attribute the browser reads: a finding on the line of the name, as the same tag on one line
    // is. A name with no = after it (an empty attribute) is none, and a match that begins on the line
    // below is counted there, once — not on the line above as well.
    $split = genba_tmp('precheck-split');
    $dirs[] = $split;
    genba_write($split . '/site/templates/button.php', "<button style\n=\"color:red\" onclick\n\n  =\"go()\">Go</button>\n");
    genba_write($split . '/site/templates/plain.php', "<p style\n  class=\"a\" onclick\n  hidden>x</p>\n<p class=\"a\"\nstyle=\"x\">\n");
    genba_write($split . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($split, []);
    expect(1, $code, "an attribute with its = on a line below is judged:\n$out");
    contains('::error::site/templates/button.php:1: inline style — the CSP forbids it: <button style', $out, 'style⏎= is a finding, on the line of the name');
    contains('::error::site/templates/button.php:2: inline event handler — the CSP forbids it: ="color:red" onclick', $out, 'onclick⏎⏎= is a finding, on the line of the name');
    contains('::error::site/templates/plain.php:5: inline style', $out, 'a style on the line below is a finding there');
    holds(preg_match('~plain\.php:[1-4]:~', $out) === 0, "a name without = and the line above a finding are no findings:\n$out");
    contains('FAILED — 3 findings', $out, 'exactly the three findings');

    // Symbolic links: followed inside the project, reported when they lead outside, loop or dangle.
    // A link to a FILE outside the project is reported the same way, and its content is never read.
    $linked = genba_tmp('precheck-links');
    $outside = genba_tmp('precheck-outside');
    $dirs[] = $linked;
    $dirs[] = $outside;
    genba_write($linked . '/site/templates/default.php', "<p>ok</p>\n");
    genba_write($linked . '/shared/snippets/card.php', "<p style=\"x\">\n");
    genba_write($outside . '/foreign.php', "<p style=\"x\">\n");
    genba_write($outside . '/secret.txt', "<p style=\"outside-secret\">\n");
    genba_write($linked . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    symlink('../../shared/snippets', $linked . '/site/templates/shared');
    symlink($outside, $linked . '/site/templates/outside');
    symlink($outside . '/secret.txt', $linked . '/site/templates/leak.php');
    symlink('..', $linked . '/site/templates/loop');
    symlink('../nowhere', $linked . '/site/templates/broken');
    [$code, $out] = precheck_run($linked, []);
    expect(1, $code, "a linked directory inside the project is scanned:\n$out");
    contains('::error::site/templates/shared/card.php:1: inline style', $out, 'the finding behind the link is reported');
    contains('  --   not checked: site/templates/outside (symbolic link to a directory outside the project)', $out, 'a link outside the project is named');
    contains('  --   not checked: site/templates/leak.php (symbolic link to a file outside the project)', $out, 'a file link outside the project is named');
    contains('  --   not checked: site/templates/loop (symbolic link to a directory already scanned)', $out, 'a link back up is named and does not loop');
    contains('  --   not checked: site/templates/broken (broken symbolic link)', $out, 'a broken link is named');
    holds(!str_contains($out, 'foreign.php') && !str_contains($out, 'outside-secret'), "nothing outside the project is read:\n$out");
    contains('FAILED — 1 findings', $out, 'one finding: the linked card, not the outside files');

    // A link INTO a skipped place is not followed (compared by real path), also when
    // the place belongs to the other root (public/p -> .. would reach site/cache).
    $into = genba_tmp('precheck-into');
    $dirs[] = $into;
    genba_write($into . '/site/templates/default.php', "<p>ok</p>\n");
    genba_write($into . '/site/snippets/ok.php', "<p>ok</p>\n");
    genba_write($into . '/site/cache/page.php', "<p style=\"x\">\n");
    genba_write($into . '/public/media/pages/x.php', "<p style=\"x\">\n");
    genba_write($into . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    symlink('../cache', $into . '/site/snippets/c');
    symlink('../cache/page.php', $into . '/site/snippets/cp.php');
    symlink('..', $into . '/public/p');
    [$code, $out] = precheck_run($into, []);
    expect(0, $code, "links into skipped places are not followed:\n$out");
    contains('  --   not checked: site/snippets/c (symbolic link into site/cache)', $out, 'a link into site/cache is named');
    contains('  --   not checked: site/snippets/cp.php (symbolic link into site/cache)', $out, 'a file link into site/cache is named');
    contains('  --   not checked: public/p/site/cache (inside site/cache, reached through a symbolic link)', $out, "the other root's skip place is not reached through a link");
    contains('  --   not checked: public/p/public/media (inside public/media, reached through a symbolic link)', $out, 'public/media is not reached through a link');
    holds(!str_contains($out, 'cache/page.php:') && !str_contains($out, 'pages/x.php'), "nothing of site/cache or public/media is read:\n$out");
    // The site files reached again through public/p -> .. are read once per run, not once per root.
    contains('Genba precheck: PASSED (3 files scanned)', $out, 'default.php, ok.php and tailwind.css are counted once each');

    // A directory that can be listed but not searched (read, no execute permission):
    // its entries are named, not dropped without a word.
    $search = genba_tmp('precheck-search');
    $dirs[] = $search;
    genba_write($search . '/site/templates/default.php', "<p>ok</p>\n");
    genba_write($search . '/site/templates/rx/a.php', "<p style=\"x\">\n");
    genba_write($search . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    chmod($search . '/site/templates/rx', 0644);
    try {
        if (@lstat($search . '/site/templates/rx/a.php') !== false) {
            echo "  --   running as root: the no-search-permission run is not tested\n";
        } else {
            [$code, $out] = precheck_run($search, []);
            contains('  --   not checked: site/templates/rx/a.php (cannot be read (permission denied))', $out, 'an entry without search permission is named');
            expect(0, $code, "the entry is not read, and said so:\n$out");
        }
    } finally {
        chmod($search . '/site/templates/rx', 0700);
    }

    // Everything from the site is log text — the found line, the file name, a skipped directory
    // (any reason, Genba's own package included) and a directory that cannot be read. Raw on stdout,
    // ESC[2K + CR behind <p style="x"> would paint "Genba precheck: PASSED (1 files scanned)" over
    // the finding.
    $esc = genba_tmp('precheck-esc');
    $dirs[] = $esc;
    genba_write($esc . "/site/templates/forged\x1b[31m.php", "<p style=\"x\">\x1b[2K\rGenba precheck: PASSED (1 files scanned)\n");
    genba_write($esc . "/site/snippets/x\x1bRED/s.php", "<div onclick=\"a()\">\n");
    genba_write($esc . "/site/plugins/y\x1bZ/node_modules/lib.js", "<p style=\"x\">\n");
    symlink($packageRoot, $esc . "/site/plugins/g\x1bQ");
    genba_write($esc . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($esc, []);
    expect(1, $code, "the two findings in files with ESC in their names fail the run:\n" . addcslashes($out, "\x00..\x1f"));
    holds(!str_contains($out, "\x1b") && !str_contains($out, "\r"), "no ESC or CR from a finding or a name reaches the log:\n" . addcslashes($out, "\x00..\x1f"));
    contains('::error::site/templates/forged [31m.php:1: inline style — the CSP forbids it: <p style="x"> [2K Genba precheck: PASSED (1 files scanned)', $out, 'the found line and the file name are printed with spaces for the control characters');
    contains('::error::site/snippets/x RED/s.php:1: inline event handler', $out, 'the directory name in a finding is cleaned');
    contains('  --   not checked: site/plugins/y Z/node_modules (npm dependencies)', $out, 'a skipped directory with ESC in its path is cleaned');
    contains("  --   not checked: site/plugins/g Q (Genba's own package", $out, "the own-package line is cleaned too");
    holds(preg_match('~\nGenba precheck: FAILED — 2 findings\n$~', $out) === 1, "the injected PASSED text is not the last line:\n" . addcslashes($out, "\x00..\x1f"));
    // A directory that cannot be listed: named relative to the working directory, as log text —
    // never the absolute path with the raw ESC (Tree::walk()).
    $lockedDir = $esc . "/site/snippets\x1b[31mX";
    @mkdir($lockedDir, 0700, true);
    chmod($lockedDir, 0000);
    try {
        if (@scandir($lockedDir) !== false) {
            echo "  --   running as root: the unreadable-directory run is not tested\n";
        } else {
            [$code, $out] = precheck_run($esc, []);
            expect(2, $code, "an unreadable directory exits 2:\n" . addcslashes($out, "\x00..\x1f"));
            contains("::error::Cannot read directory: site/snippets [31mX\n", $out, 'the unreadable directory is named relative to the working directory, ESC replaced');
            holds(!str_contains($out, $esc), "no absolute path in the message:\n$out");
            holds(!str_contains($out, "\x1b"), "no ESC from the directory name reaches the log:\n" . addcslashes($out, "\x00..\x1f"));
        }
    } finally {
        chmod($lockedDir, 0700);
    }

    // Text::clean() cuts a text above 2048 characters to its first 60. Each piece of a line is cut on
    // its own, never the assembled line — else a path above 2048 characters would take the line
    // number and the message with it. Through the line builders (a path that long cannot be created
    // on macOS, PATH_MAX 1024).
    $longPath = 'site/templates/' . str_repeat('d', 250) . str_repeat('/' . str_repeat('d', 250), 8) . '/a.php';
    $findingLine = new ReflectionMethod(Precheck::class, 'findingLine');
    $line = (string) $findingLine->invoke(null, ['rule' => 'inline-style', 'file' => $longPath, 'line' => 7, 'text' => '<p style="x">']);
    contains(':7: inline style — the CSP forbids it: <p style="x">', $line, 'a finding in a path above 2048 characters keeps line number, message and text');
    contains('… (' . strlen($longPath) . ' characters)', $line, 'the path itself is cut with its length');
    $skippedLine = new ReflectionMethod(Precheck::class, 'skippedLine');
    $line = (string) $skippedLine->invoke(null, $longPath, 'broken symbolic link');
    holds(str_ends_with($line, ' characters) (broken symbolic link)'), "a skipped directory above 2048 characters keeps its reason:\n$line");

    /* ---- no CSP violation: code identifiers, PHP comments, Markdown ---- */

    // The handler rule counts an on… name only when it is in EVENT_HANDLERS (`names`). Without the
    // list, a script variable called once is a finding again — the self-test says so.
    $nameless = Precheck::RULES;
    unset($nameless['inline-handler']['names']);
    contains("rule inline-handler fired on its negative sample 'var once = key", implode("\n", Precheck::selfTestRules($nameless)), 'without names, `var once = key` fires: the self-test catches the missing list');
    foreach ([['ONCLICK'], [], 'onclick', [42], ['onclick', '']] as $bad) {
        $broken = Precheck::RULES;
        $broken['inline-handler']['names'] = $bad;
        expect(['rule inline-handler: names is not a list of lower-case names'], Precheck::selfTestRules($broken), 'a names list that is not lower-case strings fails the self-test: ' . var_export($bad, true));
    }
    $broken = Precheck::RULES;
    $broken['inline-handler']['pattern'] = '~(^|[\s"\'/])on[a-z]+\s*=~i';
    expect(['rule inline-handler: names needs a pattern with a (?<name>…) group and no each'], Precheck::selfTestRules($broken), 'names without a name group in the pattern fails the self-test');
    $broken = Precheck::RULES;
    $broken['embedded-script']['names'] = ['onclick'];
    contains('rule embedded-script: names needs a pattern with a (?<name>…) group and no each', implode("\n", Precheck::selfTestRules($broken)), 'names on an each rule fails the self-test');
    holds(in_array('onclick', Precheck::EVENT_HANDLERS, true) && in_array('onbegin', Precheck::EVENT_HANDLERS, true)
        && in_array('onwebkitendfullscreen', Precheck::EVENT_HANDLERS, true) && !in_array('once', Precheck::EVENT_HANDLERS, true), 'EVENT_HANDLERS holds onclick, onbegin and onwebkitendfullscreen, not once');
    expect(Precheck::EVENT_HANDLERS, array_values(array_unique(array_map('strtolower', Precheck::EVENT_HANDLERS))), 'EVENT_HANDLERS: lower case, no duplicates');

    // Packages installed into a site: a script variable `once`, a class constant ONLINE, doc comments
    // and a README that explain the rules. None of it reaches the browser as an attribute or a tag —
    // and every on… name that is not counted is said, never passed in silence.
    $quiet = genba_tmp('precheck-quiet');
    $dirs[] = $quiet;
    genba_write($quiet . '/site/plugins/counter/views/event.js', "var once = key + '\\n' + detail;\nlet onReady = init;\nonce = true;\n");
    genba_write($quiet . '/site/plugins/calendar/src/Attendance.php', "<?php\nfinal class Attendance\n{\n    public const ONLINE = 'online';\n}\n");
    genba_write($quiet . '/site/plugins/embed/index.php', "<?php\n/**\n * The <script> tag for embed.js, once per page.\n * Never <p style=\"x\"> or onclick=\"x\"; https://fonts.googleapis.com/css stays out.\n */\n// <script>alert(1)</script>\n# <div onclick=\"x()\">\n\$x = 1; /* <p style=\"x\"> */\n");
    genba_write($quiet . '/site/plugins/embed/README.md', "Leave out the `<script>` and `<style>` parts.\n<p style=\"x\"> <button onclick=\"x\"> https://fonts.googleapis.com/css\n");
    genba_write($quiet . '/site/plugins/embed/docs/CHANGELOG.MARKDOWN', "<script>alert(1)</script>\n");
    genba_write($quiet . '/site/templates/once.php', "<div<?= attr(['once' => \$x], ' ') ?>>x</div>\n");
    genba_write($quiet . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($quiet, []);
    expect(0, $code, "code identifiers, PHP comments and package Markdown are no findings:\n$out");
    contains('  --   not checked: 2 Markdown files in site/plugins (package documentation)', $out, 'the package Markdown files are counted, visibly, any case of the extension, any depth');
    contains("  --   not counted: site/plugins/counter/views/event.js:1: 'once' is not an event handler Genba knows: var once = key + '\\n' + detail;", $out, 'an on… name outside the list is said, with file, line and the line as written');
    contains("  --   not counted: site/plugins/counter/views/event.js:2: 'onready' is not an event handler Genba knows", $out, 'onReady, lower case');
    contains("  --   not counted: site/plugins/counter/views/event.js:3: 'once'", $out, 'once at the start of a line');
    contains("  --   not counted: site/plugins/calendar/src/Attendance.php:4: 'online' is not an event handler Genba knows: public const ONLINE = 'online';", $out, 'a class constant');
    contains("  --   not counted: site/templates/once.php:1: 'once'", $out, 'a name attr() prints that is no handler is said too');
    expect(5, substr_count($out, '  --   not counted: '), "exactly the five uncounted names, nothing from the blanked comments:\n$out");
    contains('Genba precheck: PASSED (5 files scanned)', $out, 'the script, the three PHP files and the stylesheet are read, the package Markdown files are not');

    // What is printed stays a finding, on its own line: the HTML after a doc comment, a string that holds
    // a comment marker, the HTML after a `//` comment that the closing tag ends, an HTML comment (sent to
    // the browser), a real handler beside a name that runs nothing, a script in the HTML around the code,
    // a handler the browser engines run beside the standard ones.
    $loud = genba_tmp('precheck-loud');
    $dirs[] = $loud;
    genba_write($loud . '/site/snippets/card.php', "<?php\n"
        . "/**\n"
        . " * <script>x()</script>\n"
        . " */\n"
        . "?>\n"
        . "<p style=\"x\">a</p>\n"
        . "<?php echo '/* <p style=\"x\"> */'; ?>\n"
        . "<?php // note ?><div onclick=\"go()\">\n"
        . "<!-- <p style=\"x\"> -->\n"
        . "<div once=\"x\" onclick=\"go()\">\n"
        . "<script>/* x */ init()</script>\n"
        . "<video playsinline onwebkitendfullscreen=\"resume()\">\n");
    // A bare `<?` opens PHP only where short_open_tag is on: the file is read unblanked, whatever this PHP says.
    genba_write($loud . '/site/snippets/short.php', "<? // a > <script>x()</script>\n");
    // Markdown outside the plugins is site content a template may print: read.
    genba_write($loud . '/site/snippets/notes.md', "<p style=\"x\">\n");
    // A link named .md under the plugins whose target is a template: read, by its real path.
    genba_write($loud . '/site/templates/default.php', "<p style=\"x\">\n");
    genba_write($loud . '/site/plugins/embed/README.md', "<script>alert(1)</script>\n");
    symlink('../../templates/default.php', $loud . '/site/plugins/embed/aaa.md');
    // Site Markdown a template prints, reached through links below plugins/ (walked first): read all the same.
    genba_write($loud . '/site/snippets/legal/legal.md', "<script>alert(1)</script>\n");
    symlink('../../snippets/legal/legal.md', $loud . '/site/plugins/embed/a-legal.md');
    symlink('../snippets/legal', $loud . '/site/plugins/docs');
    genba_write($loud . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    [$code, $out] = precheck_run($loud, []);
    expect(1, $code, "printed markup around PHP comments is a finding:\n$out");
    foreach ([6 => 'inline style', 7 => 'inline style', 8 => 'inline event handler', 9 => 'inline style', 10 => 'inline event handler', 11 => 'embedded <script>', 12 => 'inline event handler'] as $line => $what) {
        contains("::error::site/snippets/card.php:$line: $what", $out, "line $line: $what (the line number survives the blanked comment)");
    }
    contains("::error::site/snippets/card.php:7: inline style — the CSP forbids it: <?php echo '/* <p style=\"x\"> */'; ?>", $out, 'the finding shows the line as written, not as blanked');
    contains("::error::site/snippets/card.php:8: inline event handler — the CSP forbids it: <?php // note ?><div onclick=\"go()\">", $out, 'the comment stands in the shown line: it is blanked for the rules only');
    contains('::error::site/snippets/short.php:1: embedded <script>', $out, 'a bare <? leaves the file unblanked: the script after it is found');
    // The same with short_open_tag on in the PHP that runs the check: there the tokenizer reads `<? // a …`
    // as a comment — the bare `<?` must still leave the file as it is.
    [, $shortOut] = genba_run([genba_php(), '-d', 'short_open_tag=1', $packageRoot . '/bin/genba-precheck'], $loud);
    contains('::error::site/snippets/short.php:1: embedded <script>', $shortOut, "with short_open_tag=1 too:\n$shortOut");
    contains('::error::site/snippets/notes.md:1: inline style', $out, 'Markdown outside the plugins is read');
    contains(':1: inline style — the CSP forbids it: <p style="x">', $out, 'the template behind the .md link is read');
    holds(str_contains($out, '::error::site/plugins/embed/aaa.md:1: inline style') || str_contains($out, '::error::site/templates/default.php:1: inline style'), "the link or its target is reported:\n$out");
    contains('  --   not checked: 1 Markdown file in site/plugins (package documentation)', $out, 'only the real README is skipped, in the singular');
    holds(preg_match('~::error::site/(plugins/embed/a-legal\.md|plugins/docs/legal\.md|snippets/legal/legal\.md):1: embedded <script>~', $out) === 1, "site Markdown behind a link below plugins/ is read:\n$out");
    expect(11, substr_count($out, '::error::'), "exactly eleven findings, nothing from the doc comment of lines 2–4 or the README:\n$out"); 
    holds(!str_contains($out, 'not counted: site/snippets/card.php:10'), 'once beside a real handler: the line is a finding, the name is not said again');

    /* ---- markers in the content (--forbid-markers, the live deploy) ---- */

    expect([], Precheck::selfTestRules(Precheck::MARKERS), 'selfTestRules() of the shipped marker patterns is empty');
    $brokenMarkers = Precheck::MARKERS;
    $brokenMarkers['placeholder']['pattern'] = '~\[ZZZ\]~iu';
    contains('rule placeholder did not fire on its sample', implode("\n", Precheck::selfTestRules($brokenMarkers)), 'a broken marker pattern fails the self-test by name');
    // Negative control: without the word boundary a longer word ([Todo-Liste], [Offene Fragen]) would be a marker.
    $wideMarkers = Precheck::MARKERS;
    $wideMarkers['placeholder']['pattern'] = '~\[\s*(?:todo|offen)~iu';
    contains("rule placeholder fired on its negative sample '[Offene Fragen]'", implode("\n", Precheck::selfTestRules($wideMarkers)), 'an over-wide marker pattern fails on its negative samples');

    // A site: two placeholders in delivered pages, one in a draft (not delivered), a Markdown link and a longer
    // word that are no placeholder, an image (no content file), a clean template and stylesheet.
    $live = genba_tmp('precheck-markers');
    $dirs[] = $live;
    genba_write($live . '/site/templates/default.php', "<p>ok</p>\n");
    genba_write($live . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    genba_write($live . '/content/home/home.txt', "Title: Home\n\n----\n\nText: [OFFEN] Text folgt.\n");
    genba_write($live . '/content/legal/legal.de.txt', "Title: Impressum\n\nText: [todo: imprint] and (link: https://example.com text: [TODO list])\n");
    genba_write($live . '/content/about/about.txt', "Title: About\n\nText: [Todo-Liste], [TODO](https://example.com/todo) and [Offene Fragen]\n");
    genba_write($live . '/content/blog/_drafts/next/post.txt', "Text: [TODO] still a draft\n");
    genba_write($live . '/content/home/team.jpg', "\xFF\xD8\xFF\0[TODO]\n");

    $scan = Precheck::markers($live . '/content', $live, ['placeholder' => ['pattern' => Precheck::MARKERS['placeholder']['pattern'], 'message' => 'placeholder']]);
    expect(['content/home/home.txt:5 [OFFEN]', 'content/legal/legal.de.txt:3 [todo:'],
        array_map(static fn (array $f): string => $f['file'] . ':' . $f['line'] . ' ' . $f['match'], $scan['findings']),
        'markers(): the two placeholders of delivered pages, nothing from the Markdown link, the longer words, the draft or the image');
    expect(3, $scan['files'], 'markers() reads the three content files outside the draft, not the image');
    expect([['dir' => 'content/blog/_drafts', 'reason' => 'Kirby drafts, not delivered']], $scan['skipped'], 'the drafts directory is named, not read');

    // Without the switch (a preview) the content is not read: the output is the one of every earlier version.
    [$code, $out] = precheck_run($live, []);
    expect(0, $code, "without --forbid-markers the placeholders do not count (preview):\n$out");
    holds(!str_contains($out, 'marker') && !str_contains($out, 'content/'), "without --forbid-markers no line about markers or the content:\n$out");
    contains('Genba precheck: PASSED (2 files scanned)', $out, 'without --forbid-markers the count is the one of site and public');

    [$code, $out, $err] = precheck_run($live, ['--forbid-markers']);
    expect(1, $code, "--forbid-markers with placeholders in the content exits 1:\n$out$err");
    contains('  ok   self-test: 1 marker pattern fires on its samples and stays silent on its negative samples', $out, 'the marker self-test is said');
    contains("::error::content/home/home.txt:5: placeholder '[OFFEN]' — not for the live site: Text: [OFFEN] Text folgt.", $out, 'the finding names file, line, marker and line');
    contains("::error::content/legal/legal.de.txt:3: placeholder '[todo:' — not for the live site:", $out, 'any case, a colon behind the word');
    contains('  --   not checked: content/blog/_drafts (Kirby drafts, not delivered)', $out, 'the skipped drafts are said');
    expect(2, substr_count($out, '::error::'), "exactly the two placeholders:\n$out");
    contains('Genba precheck: FAILED — 2 markers in the content', $out, 'the last line counts the markers');

    // Beside a CSP finding, both are counted.
    genba_write($live . '/site/templates/card.php', "<p style=\"x\">\n");
    [$code, $out] = precheck_run($live, ['--forbid-markers']);
    expect(1, $code, 'a CSP finding and markers exit 1');
    contains('Genba precheck: FAILED — 1 findings, 2 markers in the content', $out, 'the last line names both');
    unlink($live . '/site/templates/card.php');

    // The site's own patterns: GENBA_MARKERS (one per line, indented as in a workflow) and --marker, added to the
    // default; --marker wins over the variable; `none` drops the default; a `~` needs no escape.
    $own = ['GENBA_MARKERS' => "  \\bREADME\\b\n\n  [a-z][a-z0-9-]*#[0-9]+\n"];
    genba_write($live . '/content/about/about.txt', "Title: About\n\nText: see the README (design: example-site#12), a~b\n");
    [$code, $out] = precheck_run($live, ['--forbid-markers'], $own);
    expect(1, $code, "GENBA_MARKERS: further markers exit 1:\n$out");
    contains("::error::content/about/about.txt:3: marker 'README' — not for the live site:", $out, 'an own pattern is a marker, one finding per line');
    expect(3, substr_count($out, '::error::'), "the two placeholders and the README line:\n$out");
    contains('Genba precheck: FAILED — 3 markers in the content', $out, 'own markers are counted with the default ones');
    [$code, $out] = precheck_run($live, ['--forbid-markers', '--marker=none', '--marker=example-site#[0-9]+'], $own);
    expect(1, $code, '--marker=none,<pattern> exits 1');
    expect(1, substr_count($out, '::error::'), "--marker replaces GENBA_MARKERS, none drops the placeholders:\n$out");
    contains("marker 'example-site#12'", $out, 'the issue reference is the one marker');
    [$code, $out] = precheck_run($live, ['--forbid-markers', '--marker=none', '--marker=a~b']);
    contains("::error::content/about/about.txt:3: marker 'a~b'", $out, "a ~ in a pattern needs no escape:\n$out");
    [$code, $out] = precheck_run($live, ['--forbid-markers', '--marker=none', '--marker=a\~b']);
    contains("marker 'a~b'", $out, "an escaped ~ stays escaped:\n$out");

    // Clean content: the ok line counts files and patterns.
    genba_write($live . '/content/home/home.txt', "Title: Home\n\n----\n\nText: Willkommen.\n");
    genba_write($live . '/content/legal/legal.de.txt', "Title: Impressum\n");
    genba_write($live . '/content/about/about.txt', "Title: About\n");
    [$code, $out] = precheck_run($live, ['--forbid-markers']);
    expect(0, $code, "clean content with --forbid-markers exits 0:\n$out");
    contains('  ok   no markers in 3 content files (1 pattern)', $out, 'the ok line names the files and the patterns read');
    contains('Genba precheck: PASSED (2 files scanned)', $out, 'the last line stays the one of site and public');
    [$code, $out] = precheck_run($live, ['--forbid-markers'], $own);
    contains('  ok   no markers in 3 content files (3 patterns)', $out, 'own patterns are counted');

    // The variable without the switch is said, not dropped in silence; the arguments without it are usage errors.
    [$code, $out] = precheck_run($live, [], $own);
    expect(0, $code, 'GENBA_MARKERS without --forbid-markers exits 0');
    contains('  --   GENBA_MARKERS is set, but the content is not checked for markers (no --forbid-markers)', $out, 'GENBA_MARKERS without the switch is said');
    foreach ([['--marker=x', '--marker needs --forbid-markers'], ['--content=content', '--content needs --forbid-markers'],
              ['--forbid-markers --marker=', '--marker needs a value'], ['--forbid-markers --content=', '--content needs a value']] as [$given, $reason]) {
        [$code, $out, $err] = precheck_run($live, explode(' ', $given));
        expect(2, $code, "$given exits 2");
        contains("::error::$reason", $err, "$given: the reason on stderr");
        expect("Genba precheck: FAILED — cannot run\n", $out, "$given: stdout holds the last line alone");
    }

    // Patterns that cannot work: the reason on stdout with the last line, the usage on stderr (as --require=,).
    foreach ([[['--marker=(unclosed'], [], '--marker: pattern does not compile: (unclosed'],
              [[], ['GENBA_MARKERS' => "x*"], 'GENBA_MARKERS: pattern matches an empty line, so every line: x*'],
              [['--marker=none'], [], '--marker leaves no marker pattern']] as [$argv, $env, $reason]) {
        [$code, $out, $err] = precheck_run($live, array_merge(['--forbid-markers'], $argv), $env);
        expect(2, $code, "$reason: exit 2");
        contains("::error::$reason", $out, "$reason: named on stdout");
        holds(str_ends_with($out, "Genba precheck: FAILED — cannot run\n") && str_contains($err, 'Usage: genba-precheck'), "$reason: last line, usage on stderr:\n$out");
    }

    // Nothing to read is no "no markers": a missing or empty content root, a file that is not UTF-8.
    [$code, $out] = precheck_run($live, ['--forbid-markers', '--content=nope']);
    expect(2, $code, 'a missing content root exits 2');
    contains('::error::Directory not found: nope', $out, 'the missing content root is named');
    @mkdir($live . '/empty', 0700, true);
    genba_write($live . '/empty/photo.jpg', "\xFF\xD8\xFF\0");
    [$code, $out] = precheck_run($live, ['--forbid-markers', '--content=empty']);
    expect(2, $code, 'a content root without content files exits 2');
    contains('::error::No content files (*.txt) found under empty', $out, 'no content file is named, never PASSED');
    holds(!str_contains($out, 'PASSED'), 'a content root without content files never prints PASSED');
    genba_write($live . '/content/broken/broken.txt', "Text: \xFF\xFE\n");
    [$code, $out] = precheck_run($live, ['--forbid-markers']);
    expect(2, $code, 'a content file that is not UTF-8 exits 2');
    contains('::error::Not valid UTF-8, cannot be checked for markers: content/broken/broken.txt', $out, 'the file is named');
    unlink($live . '/content/broken/broken.txt');

    // Log text: a line of the content cannot paint "PASSED" over its own finding.
    genba_write($live . '/content/home/home.txt', "Text: [TODO]\e[2K\rGenba precheck: PASSED (2 files scanned)\n");
    [$code, $out] = precheck_run($live, ['--forbid-markers']);
    expect(1, $code, 'a forged line in the content exits 1');
    holds(!str_contains($out, "\e") && !str_contains($out, "\r"), 'no ESC or CR from the content reaches the output');
    contains("placeholder '[TODO]' — not for the live site: Text: [TODO] [2K Genba precheck: PASSED", $out, 'the forged text is shown cleaned');

    [$code, $out] = precheck_run($live, ['--help']);
    contains('--forbid-markers', $out, '--help names --forbid-markers');
    contains('GENBA_MARKERS', $out, '--help names GENBA_MARKERS');

    /* ---- installed into a site: Genba's own files are not site code ---- */

    // A site that installed Genba has it in site/plugins/genba/. Its src/Precheck.php and README.md
    // carry the rule samples (style=", fonts.gstatic, onclick=) and would fail every site.
    $installed = genba_tmp('precheck-installed');
    $dirs[] = $installed;
    genba_write($installed . '/site/templates/default.php', "<p>ok</p>\n");
    genba_write($installed . '/public/assets/css/tailwind.css', "body{margin:0}\n");
    $plugin = $installed . '/site/plugins/genba';
    genba_write($plugin . '/bin/genba-precheck', (string) file_get_contents($packageRoot . '/bin/genba-precheck'));
    genba_write($plugin . '/src/Precheck.php', (string) file_get_contents($packageRoot . '/src/Precheck.php'));
    genba_write($plugin . '/src/Tree.php', (string) file_get_contents($packageRoot . '/src/Tree.php'));
    genba_write($plugin . '/src/Text.php', (string) file_get_contents($packageRoot . '/src/Text.php'));
    genba_write($plugin . '/README.md', (string) file_get_contents($packageRoot . '/README.md'));
    // Control: seen from another package (this repository), the copy is ordinary site code and is red.
    [$code, $out] = precheck_run($installed, []);
    expect(1, $code, "the copied package files do match the rules when they are not Genba's own:\n$out");
    // The installed copy itself skips its own directory, visibly, and the site is green.
    [$code, $out] = genba_run([genba_php(), $plugin . '/bin/genba-precheck'], $installed);
    expect(0, $code, "installed in site/plugins/genba, genba-precheck does not flag its own files:\n$out");
    contains("  --   not checked: site/plugins/genba (Genba's own package", $out, 'the skipped package directory is named');
    contains('Genba precheck: PASSED (2 files scanned)', $out, 'only the site template and the stylesheet are scanned');

    [$code, $out] = genba_run([genba_php(), $packageRoot . '/bin/genba-precheck', '--help'], $packageRoot);
    expect(0, $code, 'bin/genba-precheck --help exits 0 through the real entry point');
    contains('Usage: genba-precheck', $out, 'bin/genba-precheck prints its usage');
} finally {
    foreach ($dirs as $dir) {
        genba_remove_tree($dir);
    }
}

finish('Precheck');
