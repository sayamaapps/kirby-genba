<?php

/**
 * Sayamaapps\Genba\Preflight, Kirby-free (SPEC.md §9.2): which hook is chosen (hook()),
 * the CLI through run() with memory streams, and one run of bin/genba-preflight.
 */
use Sayamaapps\Genba\Preflight;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** Runs Preflight::run() with memory streams; returns [exit code, stdout, stderr]. */
function preflightCli(array $argv, string $cwd, array $env = []): array
{
	$out = fopen('php://memory', 'w+');
	$err = fopen('php://memory', 'w+');
	$code = Preflight::run($env, array_merge(['genba-preflight'], $argv), $cwd, $out, $err);
	rewind($out);
	rewind($err);
	$result = [$code, (string)stream_get_contents($out), (string)stream_get_contents($err)];
	fclose($out);
	fclose($err);
	return $result;
}

#[CoversNothing]
final class PreflightTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$tmp = genba_tmp('preflight');
			try {
				// --- hook() -----------------------------------------------------------

				$bare = $tmp . '/bare';
				mkdir($bare, 0700);
				expect(
					['command' => 'echo hi', 'source' => 'GENBA_PREFLIGHT_HOOK'],
					Preflight::hook(['GENBA_PREFLIGHT_HOOK' => 'echo hi'], $bare),
					'hook: GENBA_PREFLIGHT_HOOK wins'
				);
				expect(null, Preflight::hook(['GENBA_PREFLIGHT_HOOK' => 'none'], $bare), 'hook: GENBA_PREFLIGHT_HOOK=none gives null');
				expect(null, Preflight::hook([], $bare), 'hook: no package.json and no variable gives null');

				$only = $tmp . '/only-praeflug';
				genba_write($only . '/package.json', json_encode(['scripts' => ['praeflug' => 'x']]));
				expect(
					['command' => 'npm run praeflug', 'source' => 'package.json'],
					Preflight::hook([], $only),
					'hook: package.json scripts.praeflug'
				);

				$both = $tmp . '/both';
				genba_write($both . '/package.json', json_encode(['scripts' => ['praeflug' => 'x', 'preflight' => 'y']]));
				expect(
					['command' => 'npm run preflight', 'source' => 'package.json'],
					Preflight::hook([], $both),
					'hook: scripts.preflight is preferred over praeflug'
				);

				$invalid = $tmp . '/invalid';
				genba_write($invalid . '/package.json', '{"scripts": {"praeflug": ');
				expect(null, Preflight::hook([], $invalid), 'hook: invalid package.json gives null');

				$noScripts = $tmp . '/no-scripts';
				genba_write($noScripts . '/package.json', json_encode(['name' => 'x']));
				expect(null, Preflight::hook([], $noScripts), 'hook: package.json without scripts gives null');

				// --- run() ------------------------------------------------------------

				[$code, $out] = preflightCli([], $bare, ['GENBA_PREFLIGHT_HOOK' => 'printf hook-ran']);
				expect(0, $code, 'run: successful hook exits 0');
				contains('hook-ran', $out, 'run: the hook output is streamed to stdout');
				contains('Genba preflight: PASSED', $out, 'run: last line is PASSED');

				[$code, $out] = preflightCli([], $bare, ['GENBA_PREFLIGHT_HOOK' => 'exit 3']);
				expect(1, $code, 'run: failing hook exits 1');
				contains('::error::Preflight hook failed with exit 3', $out, 'run: failing hook message');

				// The marker is assembled in the shell so that the command line itself does not contain it.
				[$code, $out, $err] = preflightCli([], $bare, ['GENBA_PREFLIGHT_HOOK' => "m=ERR; m=\$m'LINE'; printf '%s\\n' \"\$m\" >&2"]);
				expect(0, $code, 'run: hook writing to stderr still exits 0');
				contains('ERRLINE', $err, 'run: hook stderr goes to the error stream');
				expect(false, str_contains($out, 'ERRLINE'), 'run: hook stderr is not on stdout');

				[$code, $out] = preflightCli([], $bare);
				expect(0, $code, 'run: no hook exits 0');
				contains('  --   no preflight hook configured', $out, 'run: no hook is reported as not run');
				contains('Genba preflight: SKIPPED', $out, 'run: no hook ends SKIPPED');

				[$code, $out] = preflightCli([], $bare, ['GENBA_PREFLIGHT_HOOK' => 'none']);
				expect(0, $code, 'run: GENBA_PREFLIGHT_HOOK=none exits 0');
				contains('GENBA_PREFLIGHT_HOOK=none', $out, 'run: none is reported as the reason');
				contains('Genba preflight: SKIPPED', $out, 'run: none ends SKIPPED');

				[$code, $out] = preflightCli(['--hook=printf overridden'], $bare, ['GENBA_PREFLIGHT_HOOK' => 'exit 3']);
				expect(0, $code, '--hook overrides GENBA_PREFLIGHT_HOOK');
				contains('overridden', $out, '--hook: its output is shown');

				// --hook=none is named as such, not as GENBA_PREFLIGHT_HOOK=none.
				[$code, $out] = preflightCli(['--hook=none'], $bare, ['GENBA_PREFLIGHT_HOOK' => 'exit 3']);
				expect(0, $code, 'run: --hook=none exits 0');
				contains('  --   no preflight hook configured (--hook=none)', $out, 'run: --hook=none is named as the reason');
				holds(!str_contains($out, 'GENBA_PREFLIGHT_HOOK=none'), "run: --hook=none is not reported as the variable:\n$out");
				contains('Genba preflight: SKIPPED', $out, 'run: --hook=none ends SKIPPED');

				[$code, $out] = preflightCli([], $invalid);
				expect(0, $code, 'run: invalid package.json exits 0 (nothing to run)');
				contains('::warning::', $out, 'run: invalid package.json is warned about');
				contains($invalid . '/package.json', $out, 'run: the warning names the file');
				contains('Genba preflight: SKIPPED', $out, 'run: invalid package.json ends SKIPPED');

				// A valid package.json without "scripts" is not "not valid JSON".
				[$code, $out] = preflightCli([], $noScripts);
				expect(0, $code, 'run: package.json without scripts exits 0');
				holds(!str_contains($out, 'not valid JSON') && !str_contains($out, '::warning::'), "run: package.json without scripts gives no JSON warning:\n$out");
				contains('  --   no preflight hook configured', $out, 'run: package.json without scripts is reported as not run');

				$badScripts = $tmp . '/bad-scripts';
				genba_write($badScripts . '/package.json', json_encode(['scripts' => 'praeflug']));
				[$code, $out] = preflightCli([], $badScripts);
				expect(0, $code, 'run: "scripts" that is not an object exits 0');
				contains('"scripts" is not an object', $out, 'run: "scripts" that is not an object is named');

				// Every exit-2 path ends with the tool's line on stdout (SPEC §3.0), the reason is an
				// ::error:: line on stderr with the usage — the same shape as genba-links.
				[$code, $out, $err] = preflightCli(['--bogus'], $bare);
				expect(2, $code, 'run: unknown argument exits 2');
				contains('::error::Unknown argument: --bogus', $err, 'run: unknown argument is an ::error:: line on stderr');
				contains('Usage: genba-preflight', $err, 'run: unknown argument prints the usage on stderr');
				expect("Genba preflight: FAILED — cannot run\n", $out, 'run: unknown argument — stdout holds the last line alone');

				// An empty --hook= is a usage error, like genba-links' --digistore-field=, not a
				// silent fall-back to the environment.
				[$code, $out, $err] = preflightCli(['--hook='], $bare, ['GENBA_PREFLIGHT_HOOK' => 'printf fell-back']);
				expect(2, $code, 'run: --hook= (empty) exits 2');
				contains('::error::--hook needs a value', $err, 'run: the empty --hook is named on stderr');
				holds(!str_contains($out, 'fell-back') && !str_contains($out, 'PASSED') && !str_contains($out, 'SKIPPED'), "run: --hook= runs nothing:\n$out");
				expect("Genba preflight: FAILED — cannot run\n", $out, 'run: --hook= — stdout holds the last line alone');

				// An argument error wins over --help, whatever the order: --help --bogus exits 2, not 0.
				foreach ([['--help', '--bogus'], ['--bogus', '--help'], ['--help', '--hook=']] as $order) {
					[$code, $out, $err] = preflightCli($order, $bare);
					$given = implode(' ', $order);
					expect(2, $code, "run $given: exits 2");
					contains('::error::', $err, "run $given: the argument error is named on stderr");
					expect("Genba preflight: FAILED — cannot run\n", $out, "run $given: stdout holds the last line alone");
				}

				[$code, $out] = preflightCli(['--help'], $bare);
				expect(0, $code, 'run: --help exits 0');
				contains('genba-preflight', $out, 'run: --help prints usage');

				// --- bin/genba-preflight through proc_open ----------------------------

				[$code, $out] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-preflight', '--help'], $bare);
				expect(0, $code, 'bin/genba-preflight --help exits 0');
				contains('genba-preflight', $out, 'bin/genba-preflight --help prints usage');

				// sh is found through PATH. Without sh in PATH the hook cannot start: exit 2, the reason, the
				// last line.
				[$code, $out] = genba_run(
					[genba_php(), dirname(__DIR__) . '/bin/genba-preflight', '--hook=true'],
					$bare,
					['PATH' => '/nonexistent', 'GENBA_PREFLIGHT_HOOK' => null]
				);
				expect(2, $code, "bin/genba-preflight without sh in PATH: exits 2:\n$out");
				contains("::error::Cannot start the preflight hook (sh is not available)\n", $out, 'without sh: the reason is named');
				holds(preg_match('~\nGenba preflight: FAILED — cannot run\n$~', $out) === 1, "without sh: the last line says cannot run:\n$out");

				// Without findSh() the run above would be red only on PHP 8.2 (from 8.3 on proc_open() fails by
				// itself without sh). findSh() is tested directly, on every version.
				$findSh = new ReflectionMethod(Preflight::class, 'findSh');
				$savedPath = getenv('PATH');
				$noExec = "$tmp/no-exec";
				genba_write("$noExec/sh", "#!/bin/sh\n");
				chmod("$noExec/sh", 0644);
				try {
					putenv("PATH=$noExec");
					expect(null, $findSh->invoke(null), 'findSh(): a PATH with a non-executable sh only gives null');
					putenv('PATH=');
					expect('/bin/sh', $findSh->invoke(null), 'findSh(): an empty PATH falls back to /bin:/usr/bin');
					putenv('PATH');
					expect('/bin/sh', $findSh->invoke(null), 'findSh(): no PATH falls back to /bin:/usr/bin');
					putenv("PATH=/nonexistent:$noExec:/bin");
					expect('/bin/sh', $findSh->invoke(null), 'findSh(): the first executable sh on PATH, a non-executable one skipped');
				} finally {
					putenv($savedPath === false ? 'PATH' : "PATH=$savedPath");
				}
			} finally {
				genba_remove_tree($tmp);
			}
		} finally {
			ob_end_clean();
		}
	}
}
