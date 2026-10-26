<?php
/**
 * A tiny https site for the not-ready cases of tests/SmokeTest.php, which PHP's
 * built-in server cannot play (it speaks plain http only):
 *
 *   php tests/fixtures/tls-server.php PORT MODE CERT KEY
 *
 * MODE:
 *   protected   every request without Basic Auth answers 401, with it 404
 *               (enough for the gate; the expired-certificate run uses it)
 *   stall-home  as protected, but GET / WITH credentials gets no answer at all:
 *               a PHP that hangs behind the protection (curl ends in exit 28)
 *   flaky       the first connection is answered like protected, every later
 *               one with plain text instead of TLS (curl ends in exit 35)
 *   stall-first no answer to the first connection, not even TLS: it is held, so
 *               the handshake runs into curl's --max-time (exit 28); every
 *               later one gets plain text (exit 35), so the run stays short
 *
 * One process, one connection at a time; a connection that gets no answer is
 * kept open (never answered, never read again) until the test stops the
 * server. CERT/KEY are PEM files the test creates (helpers.php, genba_tls_cert).
 */

[, $port, $mode, $cert, $key] = $argv + [null, '0', 'protected', '', ''];
if (!in_array($mode, ['protected', 'stall-home', 'flaky', 'stall-first'], true)) {
	fwrite(STDERR, "unknown mode $mode\n");
	exit(2);
}
$context = stream_context_create(['ssl' => [
	'local_cert' => $cert,
	'local_pk' => $key,
	'verify_peer' => false,
]]);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
	fwrite(STDERR, "cannot listen on $port: $error\n");
	exit(1);
}
// The test waits for this line, not for a connection: a probe connection
// would count as the first one.
echo "listening\n";

$held = [];
$connections = 0;
while (true) {
	$connection = @stream_socket_accept($server, 3600);
	if ($connection === false) {
		continue;
	}
	$connections++;
	if ($mode === 'stall-first' && $connections === 1) {
		$held[] = $connection;
		continue;
	}
	if (($mode === 'flaky' || $mode === 'stall-first') && $connections > 1) {
		fwrite($connection, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
		fclose($connection);
		continue;
	}
	stream_set_timeout($connection, 5);
	if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
		// A client that refuses the certificate ends here.
		fclose($connection);
		continue;
	}
	$request = '';
	while (!str_contains($request, "\r\n\r\n")) {
		$chunk = fread($connection, 8192);
		if ($chunk === false || $chunk === '') {
			break;
		}
		$request .= $chunk;
	}
	$line = strtok($request, "\r\n");
	$authorized = preg_match('/^authorization: basic /mi', $request) === 1;
	if (!$authorized) {
		fwrite($connection, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"genba\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
	} elseif ($mode === 'stall-home' && is_string($line) && str_starts_with($line, 'GET / ')) {
		$held[] = $connection;
		continue;
	} else {
		fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
	}
	fclose($connection);
}
