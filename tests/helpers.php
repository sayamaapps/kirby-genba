<?php
/**
 * Shared test helpers for the PHPUnit suite.
 *
 * Loads NO src file: bootstrap.php loads the classes. Kirby tests load the
 * plugin through Kirby (genba_site()). Every check is one PHPUnit assertion:
 * expect(), holds() and contains().
 *
 * Several tests start processes (bin/* scripts, PHP's built-in server):
 * genba_php() is the PHP binary to use, genba_free_port() finds a port,
 * genba_server() starts `php -S` with a router and waits until it answers,
 * genba_run() runs a command and returns [exit code, combined output].
 */

use PHPUnit\Framework\Assert;

/** Strict comparison ($expected === $actual): one assertion. */
function expect(mixed $expected, mixed $actual, string $what): void
{
	Assert::assertSame($expected, $actual, $what);
}

/** One assertion: $ok must be true. */
function holds(bool $ok, string $what): void
{
	Assert::assertTrue($ok, $what);
}

/** One assertion: the haystack contains the needle. */
function contains(string $needle, string $haystack, string $what): void
{
	Assert::assertStringContainsString($needle, $haystack, $what);
}

/* --------------------------------------------------------------------------
 * Files and processes
 * -------------------------------------------------------------------------- */

/** A fresh throwaway directory under the system temp dir; remove it with genba_remove_tree(). */
function genba_tmp(string $label): string
{
	$tmp = sys_get_temp_dir() . '/genba-' . $label . '-' . bin2hex(random_bytes(6));
	mkdir($tmp, 0700, true);
	return $tmp;
}

/** Writes $content to $path, creating parent directories. */
function genba_write(string $path, string $content): void
{
	@mkdir(dirname($path), 0700, true);
	file_put_contents($path, $content);
}

function genba_remove_tree(string $path): void
{
	if (!is_dir($path) || is_link($path)) {
		return;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($it as $file) {
		$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
	rmdir($path);
}

/** The PHP binary running this test — the same one the bin/ scripts get. */
function genba_php(): string
{
	return PHP_BINARY;
}

/**
 * PATH with the PHP running this test first: genba-smoke calls `php` for its homepage
 * reader, and it must be this one — a suite run on 8.2 must not read the page with an 8.4 from PATH.
 * A directory with a symbolic link `php`, removed at the end of the test.
 */
function genba_php_path(): string
{
	static $dir = null;
	if ($dir === null) {
		$dir = genba_tmp('php');
		symlink(PHP_BINARY, "$dir/php");
		register_shutdown_function(static function () use ($dir): void {
			genba_remove_tree($dir);
		});
	}
	return $dir . PATH_SEPARATOR . (string)getenv('PATH');
}

/** A free TCP port on 127.0.0.1 (bound and released; a tiny race is accepted). */
function genba_free_port(): int
{
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	if ($socket === false) {
		throw new RuntimeException('Cannot open a local socket');
	}
	$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
	fclose($socket);
	return $port;
}

/**
 * Starts PHP's built-in server with $router and the extra environment, and
 * waits until GET $readyPath answers (any status). Returns
 * ['process' => resource, 'base' => 'http://127.0.0.1:PORT', 'log' => path].
 * Stop it with genba_stop(). Fails the test loudly when it does not come up.
 *
 * @param array<string,string> $env
 */
function genba_server(string $router, array $env = [], string $readyPath = '/'): array
{
	$port = genba_free_port();
	$log = sys_get_temp_dir() . '/genba-server-' . bin2hex(random_bytes(4)) . '.log';
	$process = proc_open(
		[genba_php(), '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", $router],
		[0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
		$pipes,
		dirname($router),
		$env + getenv()
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Test server does not start');
	}
	fclose($pipes[0]);
	$base = "http://127.0.0.1:$port";
	$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]);
	for ($i = 0; $i < 50; $i++) {
		usleep(100000);
		if (@file_get_contents($base . $readyPath, false, $context) !== false) {
			return ['process' => $process, 'base' => $base, 'log' => $log];
		}
	}
	proc_terminate($process);
	throw new RuntimeException("Test server not reachable at $base\n" . (string)@file_get_contents($log));
}

/**
 * A self-signed certificate for 127.0.0.1, valid for $days days (0: it expires
 * the second it is made), written as cert.pem and key.pem into $dir. Needs the
 * openssl extension. Returns [cert path, key path].
 */
function genba_tls_cert(string $dir, int $days): array
{
	$config = $dir . '/openssl.cnf';
	genba_write($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = IP:127.0.0.1\nbasicConstraints = critical,CA:true\n");
	$options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
	$key = openssl_pkey_new($options);
	$csr = $key === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options + ['req_extensions' => 'ext']);
	$cert = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, $days, $options + ['x509_extensions' => 'ext'], random_int(1, PHP_INT_MAX));
	if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
		throw new RuntimeException('Cannot create a test certificate: ' . (string)openssl_error_string());
	}
	genba_write($dir . '/cert.pem', $certPem);
	genba_write($dir . '/key.pem', $keyPem);
	return [$dir . '/cert.pem', $dir . '/key.pem'];
}

/**
 * Starts tests/fixtures/tls-server.php in $mode with that certificate and
 * waits until it reports that it listens (a probe connection would count as
 * its first one). Returns the same shape as genba_server(), with an https
 * base; stop it with genba_stop().
 */
function genba_tls_server(string $mode, string $cert, string $key): array
{
	$port = genba_free_port();
	$log = sys_get_temp_dir() . '/genba-tls-' . bin2hex(random_bytes(4)) . '.log';
	$process = proc_open(
		[genba_php(), __DIR__ . '/fixtures/tls-server.php', (string)$port, $mode, $cert, $key],
		[0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
		$pipes
	);
	if (!is_resource($process)) {
		throw new RuntimeException('The TLS test server does not start');
	}
	fclose($pipes[0]);
	for ($i = 0; $i < 50; $i++) {
		usleep(100000);
		if (str_contains((string)@file_get_contents($log), "listening\n")) {
			return ['process' => $process, 'base' => "https://127.0.0.1:$port", 'log' => $log];
		}
	}
	proc_terminate($process);
	throw new RuntimeException("The TLS test server is not reachable on port $port:\n" . (string)@file_get_contents($log));
}

/**
 * Starts tests/fixtures/raw-server.php (plain http, raw headers) in $mode,
 * redirecting to $target, and waits until it listens. Same shape as
 * genba_server(); stop it with genba_stop().
 */
function genba_raw_server(string $mode, string $target): array
{
	$port = genba_free_port();
	$log = sys_get_temp_dir() . '/genba-raw-' . bin2hex(random_bytes(4)) . '.log';
	$process = proc_open(
		[genba_php(), __DIR__ . '/fixtures/raw-server.php', (string)$port, $target, $mode],
		[0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
		$pipes
	);
	if (!is_resource($process)) {
		throw new RuntimeException('The raw test server does not start');
	}
	fclose($pipes[0]);
	for ($i = 0; $i < 50; $i++) {
		usleep(100000);
		if (str_contains((string)@file_get_contents($log), "listening\n")) {
			return ['process' => $process, 'base' => "http://127.0.0.1:$port", 'log' => $log];
		}
	}
	proc_terminate($process);
	throw new RuntimeException("The raw test server is not reachable on port $port:\n" . (string)@file_get_contents($log));
}

function genba_stop(array $server): void
{
	if (is_resource($server['process'])) {
		proc_terminate($server['process']);
		proc_close($server['process']);
	}
	@unlink($server['log']);
}

/**
 * Runs a command and returns [exit code, stdout+stderr]. $env is added to the
 * current environment; a value of null removes that variable.
 *
 * @param list<string> $command
 * @param array<string,?string> $env
 */
function genba_run(array $command, string $cwd, array $env = []): array
{
	$environment = getenv();
	foreach ($env as $name => $value) {
		if ($value === null) {
			unset($environment[$name]);
		} else {
			$environment[$name] = $value;
		}
	}
	$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
	if (!is_resource($process)) {
		return [255, 'proc_open failed for ' . implode(' ', $command)];
	}
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	return [proc_close($process), $out . $err];
}

/* --------------------------------------------------------------------------
 * Kirby fixtures (need vendor/: composer install).
 * -------------------------------------------------------------------------- */

/**
 * A throwaway site directory with the package symlinked as
 * site/plugins/genba, a default template and a home page. Returns its path;
 * remove it with genba_remove_tree() in a finally block.
 */
function genba_site(): string
{
	$base = dirname(__DIR__);
	if (!is_file($base . '/vendor/autoload.php')) {
		throw new RuntimeException('Composer dependencies missing; run composer install first');
	}
	require_once $base . '/vendor/autoload.php';

	$tmp = genba_tmp('kirby');
	foreach (['public', 'content/home', 'site/config', 'site/plugins', 'site/snippets', 'site/templates', 'storage'] as $dir) {
		@mkdir($tmp . '/' . $dir, 0700, true);
	}
	symlink($base, $tmp . '/site/plugins/genba');
	file_put_contents($tmp . '/site/templates/default.php', 'Fixture');
	file_put_contents($tmp . '/content/home/home.txt', 'Title: Home');
	return $tmp;
}

/**
 * A fresh Kirby instance on a genba_site() directory with these options.
 * Snippet paths are cached per process by Kirby, so the cache is reset.
 */
function genba_kirby(string $tmp, array $options = []): Kirby\Cms\App
{
	Kirby\Template\Snippet::$cache = [];
	return new Kirby\Cms\App([
		'roots' => [
			'base'    => $tmp,
			'index'   => $tmp . '/public',
			'content' => $tmp . '/content',
			'site'    => $tmp . '/site',
			'storage' => $tmp . '/storage',
		],
		'options' => ['url' => 'http://example.invalid'] + $options,
	]);
}
