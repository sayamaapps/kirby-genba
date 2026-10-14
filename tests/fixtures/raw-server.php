<?php
/**
 * A raw http listener for tests/SmokeTest.php that answers with headers PHP's
 * built-in server refuses to send (a Location value carrying ESC, a
 * bare CR or a line break — a log-line injection through the redirect target):
 *
 *   php tests/fixtures/raw-server.php PORT TARGET MODE
 *
 * TARGET is the https origin the redirect must point to (the site under test,
 * `https://127.0.0.1:<port of the fake site>/`). MODE:
 *   cr-location   Location: <TARGET>ESC[31mRED ESC[0m CR Genba smoke: PASSED.
 *   lf-location   Location: <TARGET> LF ::error::forged   (a header injection;
 *                 curl reads the second line as a header of its own)
 *   empty-then-401  the first connection is closed without a byte
 *                 (curl exit 52, HTTP 000), every later one answers 401 without
 *                 a body: a protected preview whose first answer was lost.
 *                 TARGET is ignored.
 *   short-body    200 with Content-Length 9000, TARGET as the whole
 *                 body (far shorter), then the connection closes: a transfer
 *                 that breaks after the headers (curl exit 18 "transfer closed
 *                 with N bytes remaining to read"). TARGET is the body here —
 *                 the homepage's <head> with the build meta, as far as it got.
 *
 * Every connection gets the same answer and is closed. One process, one
 * connection at a time. Prints "listening" once it accepts connections.
 */

[, $port, $target, $mode] = $argv + [null, '0', '', 'cr-location'];
$locations = [
    'cr-location' => $target . "\x1b[31mRED\x1b[0m\rGenba smoke: PASSED.",
    'lf-location' => $target . "\n::error::forged",
];
if (!isset($locations[$mode]) && !in_array($mode, ['empty-then-401', 'short-body'], true)) {
    fwrite(STDERR, "unknown mode $mode\n");
    exit(2);
}
$connections = 0;
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen on $port: $error\n");
    exit(1);
}
echo "listening\n";

while (true) {
    $connection = @stream_socket_accept($server, 3600);
    if ($connection === false) {
        continue;
    }
    $connections++;
    stream_set_timeout($connection, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    if ($mode === 'empty-then-401') {
        if ($connections > 1) {
            fwrite($connection, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"genba\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        }
        fclose($connection);
        continue;
    }
    if ($mode === 'short-body') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 9000\r\nConnection: close\r\n\r\n" . $target);
        fclose($connection);
        continue;
    }
    fwrite($connection, "HTTP/1.1 301 Moved Permanently\r\nLocation: " . $locations[$mode] . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
}
