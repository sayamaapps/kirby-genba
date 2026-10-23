<?php
/**
 * Genba\Stamp, Kirby-free (SPEC.md §9.1): read() (five cases, and more),
 * meta(), info(), write(), fromGit(),
 * the CLI through run() with memory streams, and one run of bin/genba-stamp.
 *
 *   ASDF_PHP_VERSION=8.5.3 php tests/StampTest.php
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Stamp.php';

use Genba\Stamp;

$hash = '0123456789abcdef0123456789abcdef01234567';

/** Runs Stamp::run() with memory streams and an empty environment unless given. */
function stampCli(array $argv, string $cwd, array $env = []): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = Stamp::run($env, array_merge(['genba-stamp'], $argv), $cwd, $out, $err);
    rewind($out);
    rewind($err);
    $result = [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    fclose($out);
    fclose($err);
    return $result;
}

/** A git repository with one commit in $dir; returns the commit hash. */
function stampRepo(string $dir): string
{
    $identity = [
        'GIT_AUTHOR_NAME' => 'Genba Test', 'GIT_AUTHOR_EMAIL' => 'test@example.invalid',
        'GIT_COMMITTER_NAME' => 'Genba Test', 'GIT_COMMITTER_EMAIL' => 'test@example.invalid',
    ];
    [$code] = genba_run(['git', 'init', '-q'], $dir, $identity);
    if ($code !== 0) {
        fwrite(STDERR, "git init failed in $dir\n");
        exit(1);
    }
    genba_run(['git', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '-m', 'fixture'], $dir, $identity);
    [, $head] = genba_run(['git', 'rev-parse', 'HEAD'], $dir);
    return trim($head);
}

$tmp = genba_tmp('stamp');
try {
    /* --- read(): five cases, then more ---------------- */

    $file = $tmp . '/build.txt';
    genba_write($file, $hash . "\n");
    expect($hash, Stamp::read($file), 'read: "<40 hex>\n" gives the hash (StandTest: hash with newline)');

    expect(null, Stamp::read($tmp . '/missing.txt'), 'read: missing file gives null (StandTest: file missing)');

    genba_write($file, '');
    expect(null, Stamp::read($file), 'read: empty file gives null (StandTest: empty)');

    genba_write($file, 'main');
    expect(null, Stamp::read($file), 'read: "main" gives null (StandTest: no hash)');

    genba_write($file, '"><script>alert(1)</script>');
    expect(null, Stamp::read($file), 'read: HTML in the file gives null (StandTest: HTML)');

    genba_write($file, "ABCDEF1\n");
    expect(null, Stamp::read($file), 'read: upper-case hex gives null');

    genba_write($file, "abcdef0\n");
    expect('abcdef0', Stamp::read($file), 'read: 7-character hash is accepted');

    genba_write($file, str_repeat('a', 65));
    expect(null, Stamp::read($file), 'read: 65 hex digits give null');

    genba_write($file, str_repeat('b', 64) . "\n");
    expect(str_repeat('b', 64), Stamp::read($file), 'read: 64 hex digits are accepted');

    /* --- meta() ---------------------------------------------------------- */

    expect('<meta name="build" content="' . $hash . '">' . "\n", Stamp::meta($hash), 'meta: default name build, with newline');
    expect('', Stamp::meta(null), 'meta: null gives nothing');
    expect('', Stamp::meta('main'), 'meta: non-hash gives nothing');
    expect('<meta name="stand" content="' . $hash . '">' . "\n", Stamp::meta($hash, 'stand'), 'meta: custom name is used verbatim');

    /* --- info() ---------------------------------------------------------- */

    genba_write($file, $hash . "\n");
    $info = Stamp::info($file);
    expect($hash, $info['hash'] ?? null, 'info: hash');
    expect(substr($hash, 0, 7), $info['short'] ?? null, 'info: short is the first 7 characters');
    expect($file, $info['file'] ?? null, 'info: file as given');
    holds(
        is_string($info['writtenAt'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $info['writtenAt']) === 1,
        'info: writtenAt is ISO 8601 UTC'
    );
    expect(null, Stamp::info($tmp . '/missing.txt'), 'info: missing file gives null');
    genba_write($file, 'main');
    expect(null, Stamp::info($file), 'info: no valid hash gives null');

    /* --- write() --------------------------------------------------------- */

    $written = $tmp . '/written/build.txt';
    mkdir($tmp . '/written', 0700);
    Stamp::write($written, $hash);
    expect($hash . "\n", file_get_contents($written), 'write: content is hash plus newline');
    expect($hash, Stamp::read($written), 'write: read() gets it back');

    $threw = false;
    try {
        Stamp::write($written, 'main');
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    holds($threw, 'write: "main" throws InvalidArgumentException');

    $threw = false;
    try {
        Stamp::write($written, substr($hash, 0, 7));
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    holds($threw, 'write: a 7-character hash throws InvalidArgumentException (full hash required)');

    $threw = false;
    try {
        Stamp::write($tmp . '/no-such-dir/build.txt', $hash);
    } catch (RuntimeException) {
        $threw = true;
    }
    holds($threw, 'write: missing directory throws RuntimeException');
    holds(!is_dir($tmp . '/no-such-dir'), 'write: never creates the directory');
    expect([], glob($tmp . '/written/.build.txt.*') ?: [], 'write: leaves no temp file behind');

    // A committed symlink site/config/build.txt -> ../victim.txt must not redirect the write.
    $linked = $tmp . '/linked';
    genba_write($linked . '/victim.txt', "untouched\n");
    mkdir($linked . '/config', 0700);
    symlink('../victim.txt', $linked . '/config/build.txt');
    $message = null;
    try {
        Stamp::write($linked . '/config/build.txt', $hash);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    holds($message !== null && str_contains($message, 'symbolic link'), 'write: a symlinked stamp file is refused: ' . var_export($message, true));
    expect("untouched\n", file_get_contents($linked . '/victim.txt'), 'write: the symlink target is not written');
    holds(is_link($linked . '/config/build.txt'), 'write: the symlink is left as it is');
    expect([], glob($linked . '/config/.build.txt.*') ?: [], 'write: no temp file after a refusal');

    // info() with a root: the path relative to it, never the absolute server path.
    $rooted = Stamp::info($written, $tmp);
    expect('written/build.txt', $rooted['file'] ?? null, 'info: file relative to the root');
    expect('build.txt', Stamp::info($written, $tmp . '/elsewhere')['file'] ?? null, 'info: a file outside the root gives its name only');

    /* --- fromGit() ------------------------------------------------------- */

    $repo = $tmp . '/repo';
    mkdir($repo, 0700);
    $head = stampRepo($repo);
    holds(preg_match('/^[0-9a-f]{40}$/', $head) === 1, 'fixture: git rev-parse HEAD gives 40 hex');
    expect($head, Stamp::fromGit($repo), 'fromGit: equals git rev-parse HEAD of the fixture');

    $plain = $tmp . '/plain';
    mkdir($plain, 0700);
    $message = null;
    try {
        Stamp::fromGit($plain);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    holds(is_string($message) && $message !== '', 'fromGit: outside a repository throws with git\'s message');
    contains('not a git repository', (string) $message, 'fromGit: message names "not a git repository"');

    /* --- run() ----------------------------------------------------------- */

    mkdir($repo . '/site/config', 0700, true);
    [$code, $out, $err] = stampCli([], $repo);
    expect(0, $code, 'run: plain run in the fixture repository exits 0');
    expect($head . "\n", file_get_contents($repo . '/site/config/build.txt'), 'run: site/config/build.txt holds the hash of HEAD');
    contains('Genba stamp: wrote ' . $head . ' to site/config/build.txt', $out, 'run: prints "wrote <hash> to <file>"');
    contains('Genba stamp: PASSED', $out, 'run: last line is PASSED');
    expect('', $err, 'run: nothing on stderr');

    [$code, $out] = stampCli(['--show'], $repo);
    expect(0, $code, 'run --show: exits 0');
    expect($head . "\n", $out, 'run --show: the hash alone on stdout');

    [$code, $out] = stampCli(['--sha=main'], $repo);
    expect(1, $code, 'run --sha=main: exits 1');
    contains('::error::Not a full commit hash: main', $out, 'run --sha=main: message');

    [$code, $out] = stampCli(['--show'], $tmp . '/plain');
    expect(1, $code, 'run --show without file: exits 1');
    contains('::error::No valid build stamp in site/config/build.txt', $out, 'run --show without file: message');

    [$code, $out] = stampCli(['--file=other/build.txt'], $repo, ['GENBA_SHA' => $hash]);
    expect(1, $code, 'run --file=other/ (directory missing): exits 1');
    contains('::error::Cannot write other/build.txt', $out, 'run --file=other/: message');

    [$code, $out] = stampCli(['--file=' . $linked . '/config/build.txt'], $repo, ['GENBA_SHA' => $hash]);
    expect(1, $code, 'run with a symlinked stamp file: exits 1');
    contains('symbolic link, refusing to write through it', $out, 'run with a symlinked stamp file: message');
    expect("untouched\n", file_get_contents($linked . '/victim.txt'), 'run with a symlinked stamp file: target untouched');

    mkdir($repo . '/stamp-dir', 0700);
    [$code, $out] = stampCli([], $repo, ['GENBA_STAMP_FILE' => 'stamp-dir/build.txt', 'GENBA_SHA' => $hash]);
    expect(0, $code, 'run GENBA_STAMP_FILE and GENBA_SHA: exits 0');
    expect($hash . "\n", file_get_contents($repo . '/stamp-dir/build.txt'), 'run GENBA_SHA: writes the given hash, not git\'s');
    contains('wrote ' . $hash . ' to stamp-dir/build.txt', $out, 'run GENBA_STAMP_FILE: file shown as given');

    [$code, $out] = stampCli([], $plain);
    expect(1, $code, 'run outside a repository: exits 1');
    contains('::error::git rev-parse HEAD failed:', $out, 'run outside a repository: git failure named');

    [$code, $out, $err] = stampCli(['--help'], $repo);
    expect(0, $code, 'run --help: exits 0');
    contains('genba-stamp', $out, 'run --help: usage on stdout names genba-stamp');

    // Every exit-2 path ends with the tool's line on stdout (SPEC §3.0), the reason is an
    // ::error:: line on stderr with the usage — the same shape as genba-links; an empty --sha= or
    // --file= is a usage error, not a silent fall-back to GENBA_SHA / GENBA_STAMP_FILE.
    [$code, $out, $err] = stampCli(['--bogus'], $repo);
    expect(2, $code, 'run --bogus: exits 2');
    contains('::error::Unknown argument: --bogus', $err, 'run --bogus: ::error:: reason on stderr');
    contains('Usage: genba-stamp', $err, 'run --bogus: usage on stderr');
    expect("Genba stamp: FAILED — cannot run\n", $out, 'run --bogus: stdout holds the last line alone');

    // An argument error wins over --help, whatever the order: --help --bogus exits 2, not 0.
    foreach ([['--help', '--bogus'], ['--bogus', '--help'], ['--help', '--sha=']] as $order) {
        [$code, $out, $err] = stampCli($order, $repo);
        $given = implode(' ', $order);
        expect(2, $code, "run $given: exits 2");
        contains('::error::', $err, "run $given: the argument error is named on stderr");
        expect("Genba stamp: FAILED — cannot run\n", $out, "run $given: stdout holds the last line alone");
    }

    @unlink($repo . '/stamp-dir/build.txt');
    [$code, $out, $err] = stampCli(['--sha='], $repo, ['GENBA_SHA' => $hash, 'GENBA_STAMP_FILE' => 'stamp-dir/build.txt']);
    expect(2, $code, 'run --sha= (empty): exits 2');
    contains('::error::--sha needs a value', $err, 'run --sha=: the empty option is named on stderr');
    expect("Genba stamp: FAILED — cannot run\n", $out, 'run --sha=: stdout holds the last line alone');
    holds(!file_exists($repo . '/stamp-dir/build.txt'), 'run --sha=: nothing is written');
    [$code, $out, $err] = stampCli(['--file='], $repo, ['GENBA_SHA' => $hash, 'GENBA_STAMP_FILE' => 'stamp-dir/build.txt']);
    expect(2, $code, 'run --file= (empty): exits 2');
    contains('::error::--file needs a value', $err, 'run --file=: the empty option is named on stderr');
    holds(!file_exists($repo . '/stamp-dir/build.txt'), 'run --file=: nothing is written');

    /* --- bin/genba-stamp through proc_open ------------------------------- */

    [$code, $out] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-stamp', '--help'], $repo);
    expect(0, $code, 'bin/genba-stamp --help: exits 0');
    contains('genba-stamp', $out, 'bin/genba-stamp --help: prints usage');

    // git's stderr is foreign text like any other and goes through the log filter. A git that writes
    // ESC, CR, a forged annotation and a line of 5000 characters.
    $fakeGit = genba_tmp('stamp-fake-git');
    try {
        genba_write("$fakeGit/git", "#!/bin/sh\nprintf 'fatal: \\033[31mred\\033[0m\\rGenba stamp: PASSED %s\\n::error::forged\\n' \"$(printf '%5000s' '' | tr ' ' x)\" >&2\nexit 128\n");
        chmod("$fakeGit/git", 0755);
        [$code, $out] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-stamp'], $repo, ['PATH' => $fakeGit . PATH_SEPARATOR . (string) getenv('PATH'), 'GENBA_SHA' => null]);
        expect(1, $code, "bin/genba-stamp with a failing git: exits 1:\n$out");
        holds(!str_contains($out, "\x1b") && !str_contains($out, "\r"), 'git\'s ESC and CR do not reach the log: ' . addcslashes($out, "\x00..\x1f"));
        holds(preg_match('/^::error::forged/m', $out) === 0, "only git's first line, never a forged annotation line:\n$out");
        contains('::error::git rev-parse HEAD failed: fatal:  [31mred [0m Genba stamp: PASSED xxx', $out, 'the control characters became spaces');
        contains('… (5040 characters)', $out, 'a line above 2048 characters is cut to its first 60 with its length');
        holds(max(array_map('strlen', explode("\n", $out))) < 300, "no line of the output is longer than 300 bytes:\n$out");
        holds(str_ends_with($out, "Genba stamp: FAILED — git rev-parse HEAD failed\n"), "the real last line closes the run:\n$out");
    } finally {
        genba_remove_tree($fakeGit);
    }

    // Without git in PATH: a missing tool is "cannot run", exit 2 (SPEC §3.0), like curl, ext-curl
    // and sh in the other tools — not "git rev-parse HEAD failed", exit 1. A directory that is no
    // git checkout stays a finding, exit 1.
    [$code, $out] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-stamp'], $repo, ['PATH' => '/nonexistent', 'GENBA_SHA' => null]);
    expect(2, $code, "bin/genba-stamp without git in PATH: exits 2:\n$out");
    contains("::error::git not found\n", $out, 'bin/genba-stamp without git: the missing tool is named');
    holds(preg_match('~\nGenba stamp: FAILED — cannot run\n$~', $out) === 1, "bin/genba-stamp without git: the last line says cannot run:\n$out");
    [$code, $out] = stampCli([], $plain);
    expect(1, $code, 'run outside a git checkout: still exits 1 (a finding about the working directory)');
    contains('::error::git rev-parse HEAD failed: ', $out, 'run outside a git checkout: git\'s message is named');
    contains("Genba stamp: FAILED — git rev-parse HEAD failed\n", $out, 'run outside a git checkout: the last line names git');
} finally {
    genba_remove_tree($tmp);
}

finish('StampTest');
