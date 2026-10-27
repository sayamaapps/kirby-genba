<?php

namespace Sayamaapps\Genba;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/Text.php';

/**
 * The build stamp: the commit hash the deploy writes to a file, which the
 * snippet prints as <meta name="build"> and the smoke test compares with
 * `git rev-parse HEAD` (SPEC.md §3.1). Kirby-free: bin/genba-stamp and
 * tests/StampTest.php load this file without Kirby.
 *
 * Deliberately a file read through Kirby and not a file in public/: a real
 * file would be served by Apache past the preview protection, and it would
 * not prove that PHP renders the new code.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Stamp
{
	/** Default stamp file, relative to the project root. Same value as Options::DEFAULT_STAMP_FILE. */
	public const DEFAULT_FILE = 'site/config/build.txt';

	/** What the deploy must write: the full 40-digit hash of `git rev-parse HEAD`. */
	public const FULL_PATTERN = '/^[0-9a-f]{40}$/';

	/** Exception code of fromGit() when git cannot be started (run() makes that cannot run, exit 2). */
	public const GIT_MISSING = 127;

	/** What the snippet accepts from the file: a 7–64 digit lower-case hex hash. */
	public const PATTERN = '/^[0-9a-f]{7,64}$/';

	/** $path unchanged when absolute, otherwise relative to $cwd. */
	private static function absolute(string $path, string $cwd): string
	{
		if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
			return $path;
		}
		return rtrim($cwd, '/') . '/' . $path;
	}

	/**
	 * Usage error, exit 2 (the same shape as Links::cannotRun()): the reason as an
	 * ::error:: line on $err with the usage, and on $out the last line every tool ends with
	 * (SPEC §3.0) — a log that shows stdout alone still says that nothing was written.
	 *
	 * @param resource $out
	 * @param resource $err
	 */
	private static function cannotRun($out, $err, string $reason, bool $usage = true): int
	{
		fwrite($err, "::error::$reason\n" . ($usage ? self::usage() : ''));
		fwrite($out, "Genba stamp: FAILED — cannot run\n");
		return 2;
	}

	/**
	 * `git rev-parse HEAD` in $directory, started with proc_open and an argv
	 * array (no shell). Returns the 40-digit hash.
	 *
	 * @throws RuntimeException with git's first stderr line ("not a git
	 *                          repository …"), or "git not found" with the code GIT_MISSING when
	 *                          git cannot be started (run() makes that an exit 2)
	 */
	public static function fromGit(string $directory): string
	{
		if (!is_dir($directory)) {
			throw new RuntimeException('Directory not found: ' . $directory);
		}
		$process = @proc_open(
			['git', 'rev-parse', 'HEAD'],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			$directory
		);
		if (!is_resource($process)) {
			throw new RuntimeException('git not found', self::GIT_MISSING);
		}
		fclose($pipes[0]);
		$stdout = (string)stream_get_contents($pipes[1]);
		$stderr = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		if ($status === 127) {
			throw new RuntimeException('git not found', self::GIT_MISSING);
		}
		if ($status !== 0) {
			$firstLine = trim(strtok(trim($stderr), "\n") ?: '');
			throw new RuntimeException($firstLine !== '' ? $firstLine : 'git rev-parse HEAD exited with ' . $status);
		}
		$hash = trim($stdout);
		if (preg_match(self::FULL_PATTERN, $hash) !== 1) {
			throw new RuntimeException('git rev-parse HEAD printed no full commit hash');
		}
		return $hash;
	}

	/**
	 * ['hash' => '0123…', 'short' => first 7 chars, 'file' => see below,
	 *  'writtenAt' => filemtime as ISO 8601 UTC, or null when filemtime() fails]
	 * or null when read() is null. Never a made-up date, never "now".
	 *
	 * 'file' is $file relative to $root ('site/config/build.txt') when $root
	 * is given: the absolute server path is nothing a page should be able to
	 * print. A file outside $root gives its name only. Without $root, $file
	 * as given.
	 *
	 * @return array{hash: string, short: string, file: string, writtenAt: ?string}|null
	 */
	public static function info(string $file, string|null $root = null): array|null
	{
		$hash = self::read($file);
		if ($hash === null) {
			return null;
		}
		$mtime = @filemtime($file);
		return [
			'hash'      => $hash,
			'short'     => substr($hash, 0, 7),
			'file'      => $root === null ? $file : self::relativeTo($file, $root),
			'writtenAt' => $mtime === false ? null : gmdate('c', $mtime),
		];
	}

	/**
	 * '<meta name="build" content="<hash>">' . "\n" when $hash matches PATTERN,
	 * else ''. $name is used verbatim: Options::metaName() validated it.
	 */
	public static function meta(string|null $hash, string $name = 'build'): string
	{
		if ($hash === null || preg_match(self::PATTERN, $hash) !== 1) {
			return '';
		}
		return '<meta name="' . $name . '" content="' . $hash . '">' . "\n";
	}

	/**
	 * Trimmed file content when it matches PATTERN, else null (file missing,
	 * empty, 'main', HTML …). Nothing unchecked ever reaches the HTML.
	 */
	public static function read(string $file): string|null
	{
		if (!is_file($file)) {
			return null;
		}
		$content = @file_get_contents($file);
		if ($content === false) {
			return null;
		}
		$hash = trim($content);
		return preg_match(self::PATTERN, $hash) === 1 ? $hash : null;
	}

	/** $file relative to $root, or its name only when it lies outside $root. */
	private static function relativeTo(string $file, string $root): string
	{
		$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
		$file = str_replace('\\', '/', $file);
		return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
	}

	/**
	 * CLI `genba-stamp` (SPEC.md §3.0, §3.1). Plain run: hash from --sha or
	 * GENBA_SHA, else git in $cwd → write → read back and compare.
	 * --show prints the hash in the file and writes nothing.
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
		$show = false;
		$fileArg = null;
		$shaArg = null;
		foreach ($args as $arg) {
			if ($arg === '--help') {
				$help = true;
			} elseif ($arg === '--show') {
				$show = true;
			} elseif (preg_match('/^--(file|sha)=(.*)$/s', $arg, $m) === 1) {
				if (trim($m[2]) === '') {
					// An empty value would fall back to GENBA_STAMP_FILE / GENBA_SHA without a
					// word; the same rule as --hook= and --digistore-field=.
					return self::cannotRun($out, $err, "--{$m[1]} needs a value");
				}
				if ($m[1] === 'file') {
					$fileArg = $m[2];
				} else {
					$shaArg = $m[2];
				}
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

		$display = self::setting($fileArg, $env, 'GENBA_STAMP_FILE') ?? self::DEFAULT_FILE;
		$file = self::absolute($display, $cwd);

		if ($show) {
			$hash = self::read($file);
			if ($hash === null) {
				fwrite($out, '::error::No valid build stamp in ' . $display . "\n");
				fwrite($out, 'Genba stamp: FAILED — no valid build stamp in ' . $display . "\n");
				return 1;
			}
			fwrite($out, $hash . "\n");
			return 0;
		}

		$sha = self::setting($shaArg, $env, 'GENBA_SHA');
		if ($sha !== null) {
			if (preg_match(self::FULL_PATTERN, $sha) !== 1) {
				fwrite($out, '::error::Not a full commit hash: ' . $sha . "\n");
				fwrite($out, "Genba stamp: FAILED — not a full commit hash\n");
				return 1;
			}
			$hash = $sha;
		} else {
			try {
				$hash = self::fromGit($cwd);
			} catch (RuntimeException $e) {
				if ($e->getCode() === self::GIT_MISSING) {
					// A tool that is missing is "cannot run", exit 2 (SPEC §3.0) — as
					// curl in genba-smoke, ext-curl in genba-links, sh in genba-preflight. A
					// working directory that is no git checkout stays a finding, exit 1.
					fwrite($out, "::error::git not found\n");
					fwrite($out, "Genba stamp: FAILED — cannot run\n");
					return 2;
				}
				// git's stderr is foreign text: a hook, a wrapper or a
				// repository path can put an ESC, a CR or a forged `::error::` into it — log text, cut
				// like every other (Text::clean()).
				fwrite($out, '::error::git rev-parse HEAD failed: ' . Text::clean($e->getMessage()) . "\n");
				fwrite($out, "Genba stamp: FAILED — git rev-parse HEAD failed\n");
				return 1;
			}
		}

		try {
			self::write($file, $hash);
		} catch (RuntimeException $e) {
			fwrite($out, '::error::Cannot write ' . $display . ': ' . $e->getMessage() . "\n");
			fwrite($out, "Genba stamp: FAILED — cannot write the stamp file\n");
			return 1;
		}

		if (self::read($file) !== $hash) {
			fwrite($out, '::error::' . $display . " does not contain the hash just written\n");
			fwrite($out, "Genba stamp: FAILED — the stamp file does not verify\n");
			return 1;
		}

		fwrite($out, 'Genba stamp: wrote ' . $hash . ' to ' . $display . "\n");
		fwrite($out, "Genba stamp: PASSED\n");
		return 0;
	}

	/** First non-empty value of: the argument, the environment variable, null. */
	private static function setting(string|null $argument, array $env, string $name): string|null
	{
		if ($argument !== null && trim($argument) !== '') {
			return trim($argument);
		}
		$value = $env[$name] ?? null;
		if (is_string($value) && trim($value) !== '') {
			return trim($value);
		}
		return null;
	}

	private static function usage(): string
	{
		return <<<'TEXT'
Usage: genba-stamp [--file=PATH] [--sha=HASH] [--show] [--help]

Writes the commit hash of the checkout to the stamp file. The site's snippet
prints it as <meta name="build">, the smoke test compares it with the live page.

Settings, first hit wins: argument, environment variable, default.
  --file=PATH   GENBA_STAMP_FILE  stamp file, relative to the working directory
                                  (default site/config/build.txt)
  --sha=HASH    GENBA_SHA         full 40-digit commit hash
                                  (default: git rev-parse HEAD in the working directory)
  --show                          print the hash in the file, write nothing
  --help                          this text

GITHUB_SHA is deliberately not used: on a pull request it is a merge commit
that is never deployed.

Exit codes: 0 written or shown, 1 the stamp could not be written or verified
(or git failed: no git checkout), 2 usage error or git not found.

TEXT;
	}

	/**
	 * Writes "$hash\n" to $file. Never creates directories.
	 *
	 * @throws InvalidArgumentException when $hash is not a full 40-digit hash
	 * @throws RuntimeException when the directory does not exist or the write fails
	 */
	public static function write(string $file, string $hash): void
	{
		if (preg_match(self::FULL_PATTERN, $hash) !== 1) {
			throw new InvalidArgumentException('Not a full commit hash: ' . $hash);
		}
		$dir = dirname($file);
		if (!is_dir($dir)) {
			throw new RuntimeException('directory does not exist');
		}
		// A committed symlink (build.txt -> ../elsewhere) must not steer the
		// write to another file. rename() below would replace the link itself
		// anyway; refusing says so instead of silently dropping it.
		if (is_link($file)) {
			throw new RuntimeException('it is a symbolic link, refusing to write through it');
		}
		// Temp file in the same directory, then rename(): atomic, and it never
		// follows a link ('x' fails on any existing path, a link included).
		$temp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$handle = @fopen($temp, 'x');
		if ($handle === false) {
			throw new RuntimeException('write failed');
		}
		$written = fwrite($handle, $hash . "\n") === strlen($hash) + 1;
		$closed = fclose($handle);
		if (!$written || !$closed || !@rename($temp, $file)) {
			@unlink($temp);
			throw new RuntimeException('write failed');
		}
	}
}
