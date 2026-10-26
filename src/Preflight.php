<?php

namespace Sayamaapps\Genba;

require_once __DIR__ . '/Text.php';

/**
 * The project's own generation step before the build (SVG motifs, fonts, data
 * imports): an npm script named `praeflug` (SPEC.md §3.2). Genba runs it
 * when it exists and says visibly when it does not, so the workflow line is
 * the same for every site. Kirby-free.
 */
final class Preflight
{
	private const ENV_HOOK = 'GENBA_PREFLIGHT_HOOK';

	/** Names tried in package.json, in this order. */
	private const SCRIPTS = ['preflight', 'praeflug'];

	/**
	 * Usage error, exit 2 (the same shape as Links::cannotRun()): the reason as an
	 * ::error:: line on $err with the usage, and on $out the last line every tool ends with
	 * (SPEC §3.0) — a log that shows stdout alone still says that nothing ran.
	 *
	 * @param resource $out
	 * @param resource $err
	 */
	private static function cannotRun($out, $err, string $reason, bool $usage = true): int
	{
		fwrite($err, "::error::$reason\n" . ($usage ? self::usage() : ''));
		fwrite($out, "Genba preflight: FAILED — cannot run\n");
		return 2;
	}

	/** The trimmed variable, or null when unset or empty. */
	private static function envHook(array $env): string|null
	{
		$value = $env[self::ENV_HOOK] ?? null;
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		return trim($value);
	}

	/**
	 * Runs $command with `sh -c` in $cwd, copying stdout to $out and stderr to
	 * $err as they arrive. Returns the exit code, or null when the process
	 * cannot be started.
	 *
	 * @param resource $out
	 * @param resource $err
	 */
	private static function execute(string $command, string $cwd, $out, $err): int|null
	{
		// sh is looked up here, not left to proc_open(): up to PHP 8.2 proc_open()
		// forks and execs, so a missing sh still "starts" and the child ends with
		// 127 — indistinguishable from a hook whose own command is not found. From
		// 8.3 on proc_open() fails right away. Looking it up ourselves gives the
		// same "cannot run" on every PHP version (found by the CI matrix on 8.2).
		$sh = self::findSh();
		if ($sh === null) {
			return null;
		}
		$process = @proc_open([$sh, '-c', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
		if (!is_resource($process)) {
			return null;
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		/** @var array<int, array{0: resource, 1: resource}> $open stream id => [stream, target] */
		$open = [
			(int)$pipes[1] => [$pipes[1], $out],
			(int)$pipes[2] => [$pipes[2], $err],
		];
		while ($open !== []) {
			$read = [];
			foreach ($open as [$stream]) {
				$read[] = $stream;
			}
			$write = null;
			$except = null;
			if (@stream_select($read, $write, $except, 1) === false) {
				break;
			}
			foreach ($read as $stream) {
				$id = (int)$stream;
				$chunk = fread($stream, 8192);
				if (is_string($chunk) && $chunk !== '') {
					fwrite($open[$id][1], $chunk);
				}
				if (feof($stream)) {
					fclose($stream);
					unset($open[$id]);
				}
			}
		}
		return proc_close($process);
	}

	/**
	 * The first executable `sh` on PATH, or null. Without a PATH, or with an empty one, the
	 * default of execvp() applies (/bin:/usr/bin) — an empty PATH names no directory
	 * (execvp() would read it as the working directory; a workflow's `PATH: ""` means none).
	 */
	private static function findSh(): string|null
	{
		$path = getenv('PATH');
		$dirs = $path === false || $path === '' ? ['/bin', '/usr/bin'] : explode(PATH_SEPARATOR, $path);
		foreach ($dirs as $dir) {
			$candidate = ($dir === '' ? '.' : rtrim($dir, '/')) . '/sh';
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Which hook to run: ['command' => 'npm run praeflug', 'source' => 'GENBA_PREFLIGHT_HOOK'|'package.json'],
	 * or null (none configured, or GENBA_PREFLIGHT_HOOK=none). Order:
	 * GENBA_PREFLIGHT_HOOK, else package.json in $cwd with scripts.preflight,
	 * else scripts.praeflug. An unreadable or invalid package.json means null.
	 *
	 * @param array<string,string> $env
	 * @return array{command: string, source: string}|null
	 */
	public static function hook(array $env, string $cwd): array|null
	{
		$hook = self::envHook($env);
		if ($hook === 'none') {
			return null;
		}
		if ($hook !== null) {
			return ['command' => $hook, 'source' => self::ENV_HOOK];
		}

		[$state, $scripts] = self::package($cwd);
		if ($state !== 'ok') {
			return null;
		}
		foreach (self::SCRIPTS as $name) {
			if (isset($scripts[$name]) && is_string($scripts[$name]) && trim($scripts[$name]) !== '') {
				return ['command' => 'npm run ' . $name, 'source' => 'package.json'];
			}
		}
		return null;
	}

	/**
	 * package.json in $cwd: [state, scripts]. State 'missing' (no file),
	 * 'invalid' (unreadable or not a JSON object), 'no-scripts' (valid, no
	 * "scripts" key), 'bad-scripts' ("scripts" is not an object) or 'ok'
	 * (scripts is the array).
	 *
	 * @return array{0: string, 1: ?array}
	 */
	private static function package(string $cwd): array
	{
		$file = rtrim($cwd, '/') . '/package.json';
		if (!is_file($file)) {
			return ['missing', null];
		}
		$data = json_decode((string)@file_get_contents($file), true);
		if (!is_array($data)) {
			return ['invalid', null];
		}
		if (!array_key_exists('scripts', $data)) {
			return ['no-scripts', null];
		}
		return is_array($data['scripts']) ? ['ok', $data['scripts']] : ['bad-scripts', null];
	}

	/**
	 * CLI `genba-preflight` (SPEC.md §3.0, §3.2). Runs the hook through
	 * `sh -c` in $cwd and streams its output to $out and $err.
	 *
	 * @param array<string,string> $env
	 * @param list<string> $argv
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $env, array $argv, string $cwd, $out, $err): int
	{
		$args = array_slice($argv, 1);
		// Every argument is read before --help is honoured: an argument error wins, whatever
		// the order (SPEC §3.0 — `--help --bogus` must not exit 0).
		$help = false;
		$hookArg = null;
		foreach ($args as $arg) {
			if ($arg === '--help') {
				$help = true;
			} elseif (preg_match('/^--hook=(.*)$/s', $arg, $m) === 1) {
				if (trim($m[1]) === '') {
					// An empty value would fall back to the environment without a word;
					// the same rule as genba-links' `--digistore-field=`.
					return self::cannotRun($out, $err, '--hook needs a value (GENBA_PREFLIGHT_HOOK=none switches the step off)');
				}
				$hookArg = trim($m[1]);
			} else {
				return self::cannotRun($out, $err, "Unknown argument: $arg");
			}
		}
		if ($help) {
			fwrite($out, self::usage());
			return 0;
		}
		// Without mbstring the run would break off with "Call to undefined function mb_…()" — a
		// missing extension is "cannot run" (SPEC §3.0), said before anything runs.
		$missing = Text::missingMbstring();
		if ($missing !== null) {
			return self::cannotRun($out, $err, $missing, false);
		}

		$effective = $env;
		if ($hookArg !== null) {
			$effective[self::ENV_HOOK] = $hookArg;
		}

		$hook = self::hook($effective, $cwd);
		if ($hook === null) {
			$packageFile = rtrim($cwd, '/') . '/package.json';
			if (self::envHook($effective) === null) {
				// A package.json without "scripts" is fine (the "--" line below says
				// no script was found); only an unreadable one deserves a warning.
				[$state] = self::package($cwd);
				if ($state === 'invalid') {
					fwrite($out, '::warning::Cannot read ' . $packageFile . ' (not valid JSON); no preflight hook taken from it' . "\n");
				} elseif ($state === 'bad-scripts') {
					fwrite($out, '::warning::' . $packageFile . ': "scripts" is not an object; no preflight hook taken from it' . "\n");
				}
			}
			if (self::envHook($effective) === 'none') {
				// Named by where the none came from: --hook=none must not read as
				// GENBA_PREFLIGHT_HOOK=none, a variable the workflow may not even set.
				$source = $hookArg !== null ? '--hook=none' : 'GENBA_PREFLIGHT_HOOK=none';
				fwrite($out, "  --   no preflight hook configured ($source)\n");
			} else {
				fwrite($out, '  --   no preflight hook configured (no GENBA_PREFLIGHT_HOOK, no package.json script "preflight" or "praeflug")' . "\n");
			}
			fwrite($out, "Genba preflight: SKIPPED — nothing to run\n");
			return 0;
		}

		$source = $hookArg !== null ? '--hook' : $hook['source'];
		fwrite($out, "Genba preflight: running '" . $hook['command'] . "' (from " . $source . ")\n");

		$exit = self::execute($hook['command'], $cwd, $out, $err);
		if ($exit === null) {
			fwrite($out, "::error::Cannot start the preflight hook (sh is not available)\n");
			// The last line of every exit 2 (SPEC §3.0): the reason is the
			// ::error:: line above, the last line says only that nothing ran.
			fwrite($out, "Genba preflight: FAILED — cannot run\n");
			return 2;
		}
		if ($exit !== 0) {
			fwrite($out, '::error::Preflight hook failed with exit ' . $exit . "\n");
			fwrite($out, 'Genba preflight: FAILED — hook exit ' . $exit . "\n");
			return 1;
		}
		fwrite($out, "  ok   preflight hook finished\n");
		fwrite($out, "Genba preflight: PASSED\n");
		return 0;
	}

	private static function usage(): string
	{
		return <<<'TEXT'
Usage: genba-preflight [--hook=COMMAND] [--help]

Runs the project's own generation step before the build, e.g. SVG motifs or
font files. Without a hook it reports so and exits 0.

Hook, first hit wins: --hook, GENBA_PREFLIGHT_HOOK, package.json script
"preflight", package.json script "praeflug". The value none (--hook=none or
GENBA_PREFLIGHT_HOOK=none) switches the step off.
  --hook=COMMAND    shell command, run with sh -c in the working directory
  --help            this text

Exit codes: 0 passed or skipped, 1 the hook failed, 2 usage error or the hook
cannot be started.

TEXT;
	}
}
