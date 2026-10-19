<?php
/**
 * Fake site for tests/SmokeTest.php, run as the router of PHP's built-in
 * server: php -S 127.0.0.1:PORT tests/fixtures/smoke-server.php
 *
 * Port of the Python server in kirby-starter/tests/smoke-test-gegenprobe.sh.
 * It plays a healthy site and injects faults. The fault names are read from
 * the file named by GENBA_TEST_FAULT_FILE on every request, separated by
 * spaces (empty or missing = healthy; usually one, `auth prod-sitemap`
 * combines two); the build hash comes from GENBA_TEST_BUILD.
 *
 * Every request is answered here and the script exits: nothing is served as a
 * static file. HEAD gets the same headers as GET; the built-in server drops
 * the body itself.
 *
 * GENBA_TEST_REQUEST_LOG (optional): every request is appended to that file as
 * "<METHOD> <request URI>", so a test can count what the tool really asked for.
 *
 * Faults of the sitemap (see the sitemap block): lower-base, sitemap-get-500, sitemap-html,
 * huge-sitemap, long-loc, control-loc, entity-loc, prod-sitemap-variants, pretty-loc, cdata-loc,
 * unparsed-loc, numeric-entity-loc, double-entity-loc, relative-loc, short-sitemap and
 * stall-sitemap (a transfer that breaks after the headers), empty-loc, all-empty-loc,
 * other-numref-loc, nonascii-loc, named-entity-loc, comment-loc, invalid-byte-sitemap (a \xFF in
 * the body, no UTF-8), invalid-byte-loc.
 * Faults of the homepage: esc-href, esc-src, long-src, long-href, esc-build, long-build, amp-attr,
 * many-webp, nul-home (a NUL byte in the body), numref-attr, multiline-link, raw-c1-href,
 * comment-link, invalid-byte-home (a \xFF in the body, no UTF-8), comment-in-script, unquoted-attr,
 * uppercase-css, foreign-numref, abrupt-comment, base-href, js-base, data-base, mailto-base,
 * prod-link, huge-home, huge-home-stream, and variant:<name> (the homepage from
 * tests/fixtures/html-variants/<name>.html, with its own stylesheet holding a url(DATA:,y.woff2)).
 * Faults of the stylesheet: esc-ctype; of the http redirect: esc-redirect, raw-c1-redirect.
 * Panel and API under other slugs (panel.slug, api.slug): panel-slug (the Panel at /kontrolle, /panel
 * answers 404), api-slug (the API at /schnittstelle, /api answers 404).
 * ctrl-url: control characters and bytes that are no UTF-8 in addresses (homepage, stylesheet,
 * sitemap). A Location with CR or LF and a cut homepage need tests/fixtures/raw-server.php.
 */

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$build = (string) (getenv('GENBA_TEST_BUILD') ?: '');

$requestLog = (string) (getenv('GENBA_TEST_REQUEST_LOG') ?: '');
if ($requestLog !== '') {
    file_put_contents($requestLog, $method . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
}

$faultFile = (string) (getenv('GENBA_TEST_FAULT_FILE') ?: '');
$fault = '';
if ($faultFile !== '' && is_file($faultFile)) {
    $fault = trim((string) file_get_contents($faultFile));
}
$GLOBALS['faults'] = preg_split('/\s+/', $fault, -1, PREG_SPLIT_NO_EMPTY) ?: [];

/** Is this fault injected in the current run? */
function has(string $name): bool
{
    return in_array($name, $GLOBALS['faults'], true);
}

// lower-base: the site writes its host in lower case (Kirby's url option)
// while the operator typed GENBA_URL with capitals.
if (has('lower-base')) {
    $host = strtolower($host);
}
$base = 'http://' . $host;

// Response headers (fault `headers`): the set a hardened site sends. The Panel
// page carries its own Content-Security-Policy with inline scripts, the rest of
// the site the strict one.
if (has('headers')) {
    header('Strict-Transport-Security: max-age=15552000');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: " . ($path === '/panel'
        ? "script-src 'self' 'unsafe-inline' blob:; object-src 'none'"
        : "script-src 'self'; object-src 'none'"));
}

/**
 * Sends one response and ends the request. $declared: a Content-Length other than the body's
 * (short-sitemap: more than is sent, so the client sees the transfer break).
 */
function respond(int $code, string $type, string $body, array $headers = [], ?int $declared = null): void
{
    http_response_code($code);
    header('Content-Type: ' . $type);
    header('Content-Length: ' . ($declared ?? strlen($body)));
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;
    exit;
}

// Protected preview: everything except the http redirect needs Basic Auth.
if (has('auth') && $path !== '/http-umleitung') {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($user !== 'preview' || $password !== 'ge"heim\x') {
        respond(401, 'text/html; charset=utf-8', 'Vorschau', ['WWW-Authenticate' => 'Basic realm="Vorschau"']);
    }
}

// The HTML variant corpus (tests/fixtures/html-variants/): the fault `variant:<name>` serves
// <name>.html as the homepage, @BUILD@ replaced by the build hash. Every variant is the same page —
// one stylesheet, a .webp in a src, two .avif in a srcset — in another spelling a browser reads the
// same; tests/SmokeTest.php holds every variant to the same set of requested files. The asset
// routes below are matched without regard to case (uppercase.html asks for tailwind.CSS), and a
// variant whose name starts with crlf is sent with CRLF line endings (.gitattributes keeps LF).
$variant = null;
foreach ($GLOBALS['faults'] as $name) {
    if (str_starts_with($name, 'variant:')) {
        $variant = basename(substr($name, 8));
    }
}
if ($variant !== null) {
    $path = strtolower($path);
    if ($path === '/') {
        $file = __DIR__ . '/html-variants/' . $variant . '.html';
        if (!is_file($file)) {
            respond(500, 'text/plain', "no variant $variant");
        }
        $page = str_replace('@BUILD@', $build, (string) file_get_contents($file));
        if (str_starts_with($variant, 'crlf')) {
            $page = str_replace("\n", "\r\n", $page);
        }
        respond(200, 'text/html; charset=utf-8', $page);
    }
    // A data: URI in capitals in a url() of the stylesheet is no address —
    // GET /assets/css/DATA:,y.woff2 would be counted.
    if ($path === '/assets/css/tailwind.css') {
        respond(200, 'text/css', '@font-face{src:url("../fonts/sans.woff2") format("woff2")}'
            . '.x{background:url(data:image/png;base64,AAAA)}.y{background:url(DATA:,y.woff2)}');
    }
}

if ($path === '/') {
    $stand = has('old-build') ? 'fedcba9876543210fedcba9876543210fedcba98' : $build;
    // The build hash is text from the page too. esc-build: the hash followed by
    // ESC, CR and LF and a forged annotation inside the attribute; long-build: 100 000 digits.
    if (has('esc-build')) {
        $stand = $build . "\x1b[31m\r\n::error::forged";
    }
    if (has('long-build')) {
        $stand = str_repeat('a', 100000);
    }
    $meta = has('no-build') ? '' : '<meta name="build" content="' . $stand . '">';
    $css = has('no-marker') ? '' : '<link rel="stylesheet" href="/assets/css/tailwind.css?v=1">';
    // What the page itself can put into the log. esc-href: an own-origin stylesheet
    // name with ESC and CR (a log-line injection through the stylesheet finding); esc-src: the
    // same through an image; long-src: an image name of 100 000 characters.
    if (has('esc-href')) {
        // ESC and CR (C0), and U+0085 NEXT LINE (C1, UTF-8 \xC2\x85).
        $css .= "<link rel=\"stylesheet\" href=\"/assets/css/x\x1b[31;1mRED\x1b[0m\r\xC2\x85Genba smoke: PASSED. .css\">";
    }
    if (has('long-href')) {
        $css .= '<link rel="stylesheet" href="/assets/css/' . str_repeat('c', 100000) . '.css">';
    }
    // An href, src and srcset written HTML-escaped (?v=2&amp;x=2), as every
    // template engine writes them — to be requested decoded, like a <loc>.
    if (has('amp-attr')) {
        $css .= '<link rel="stylesheet" href="/assets/css/tailwind.css?v=2&amp;x=2">';
    }
    // numref-attr: references the tool does not decode in an href, a src and —
    // the control — in the query of a .jpg, which is never fetched and so no finding.
    if (has('numref-attr')) {
        $css .= '<link rel="stylesheet" href="/assets/css/t&#233;.css">';
    }
    // multiline-link: the markup as a formatter writes it — the <link> wrapped over
    // three lines, a srcset split at its commas, an attribute in single quotes.
    if (has('multiline-link')) {
        $css = "<link\n  rel=\"stylesheet\"\n  href=\"/assets/css/tailwind.css?v=1\">";
    }
    // raw-c1-href: raw C1 bytes, not in their UTF-8 form — \x9B (the 8-bit CSI) at
    // the start of the name, after a non-ASCII byte (\xE9 is no character here) and as the
    // UTF-8 C1 \xC2\x9B, next to a real ß (\xC3\x9F, whose second byte is in the C1 range too).
    if (has('raw-c1-href')) {
        $css .= "<link rel=\"stylesheet\" href=\"/assets/css/\x9b31mA-stra\xc3\x9fe-\xe9\x9b[31m-\xc2\x9b0m-x.css\">";
    }
    // comment-link: a stylesheet and an image commented out, over two lines — not on
    // the page, so neither is requested (both answer 404).
    if (has('comment-link')) {
        $css .= "<!-- <link rel=\"stylesheet\" href=\"/assets/css/alt.css\">\n<img src=\"/media/alt.webp\"> -->";
    }
    // comment-in-script: an <!-- inside an inline script is JavaScript, not the start of a
    // comment; a reader that takes it for one drops everything up to the next -->
    // (the footer comment below) and never requests the 404 stylesheet behind it — PASSED.
    if (has('comment-in-script')) {
        $css .= "<script>function isComment(s){return s.indexOf('<!--')===0}</script>"
            . '<link rel="stylesheet" href="/assets/css/missing.css">';
    }
    // unquoted-attr: minified HTML writes values without quotes — a 404 stylesheet and an
    // image that a reader asking for a quote after href= and src= never reads.
    // uppercase-css: a stylesheet named in capitals, 404 — its kind and its selection must both
    // be read without regard to case, or it is never requested.
    if (has('uppercase-css')) {
        $css .= '<LINK REL="STYLESHEET" HREF="/assets/css/missing.CSS">';
    }
    // foreign-numref: a reference that is not decoded in the address of a stylesheet and
    // an image on another origin — never fetched, so no finding.
    if (has('foreign-numref')) {
        $css .= '<link rel="stylesheet" href="https://cdn.example.com/caf&#233;.css">';
    }
    if (has('unquoted-attr')) {
        $css .= '<link rel=stylesheet href=/assets/css/missing.css>';
    }
    // abrupt-comment: <!--> is a complete empty comment — read as the start of one that runs to
    // the next --> (the footer comment below), the 404 stylesheet would never be requested.
    if (has('abrupt-comment')) {
        $css .= '<!--><link rel="stylesheet" href="/assets/css/missing.css">';
    }
    // base-href: the addresses are resolved against <base href>, here /assets/../ — the
    // stylesheet is written relative to it, as is the image below (media/bild.webp).
    if (has('base-href')) {
        $css = '<base href="/assets/../"><link rel="stylesheet" href="assets/css/tailwind.css?v=1">';
    }
    // A data: or javascript: base is ignored by the browser, the addresses
    // behind it are resolved against the page's own address (js-base, data-base: DATA: in capitals),
    // never read as a finding that checks nothing. mailto-base: a base the browser resolves
    // nothing against — a finding. Each in front of the stylesheet, written relative.
    $bases = ['js-base' => 'javascript:void(0)', 'data-base' => 'DATA:text/html,x', 'mailto-base' => 'mailto:x@example.com'];
    foreach ($bases as $fault => $href) {
        if (has($fault)) {
            $css = '<base href="' . $href . '"><link rel="stylesheet" href="assets/css/tailwind.css?v=1">';
        }
    }
    // prod-link: a staging host whose site URL is production — Kirby's css() and
    // the image write the production origin. With GENBA_SITEMAP_ORIGIN on a rewrite run they are checked on
    // the own origin, as the sitemap's entries; without, the safety net reports the stylesheet.
    // huge-home: a homepage above the 10 MiB genba-smoke reads — the build and
    // the stylesheet are in it, a comment pads it.
    if (has('huge-home')) {
        respond(200, 'text/html; charset=utf-8', '<!doctype html><html><head>' . $meta . $css . '</head><body><!--'
            . str_repeat(' ', 10485761) . '--></body></html>');
    }
    // huge-home-stream: the same page without Content-Length, sent in pieces of
    // 64 KiB as PHP-FPM behind Apache or nginx sends it (chunked, HTTP/2) — curl below 8.4 stops at
    // --max-filesize only when the size is announced. SmokeTest runs it with a curl that drops the option.
    if (has('huge-home-stream')) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head>' . $meta . $css . '</head><body><!--';
        for ($sent = 0; $sent <= 10485760; $sent += 65536) {
            echo str_repeat(' ', 65536);
            flush();
        }
        echo '--></body></html>';
        exit;
    }
    if (has('prod-link')) {
        $css = '<link rel="stylesheet" href="https://prod.example/assets/css/tailwind.css?v=1">';
    }
    respond(200, 'text/html; charset=utf-8',
        '<!doctype html><html><head>' . $meta . $css
        . '<link rel="preload" href="/assets/fonts/serif.woff2" as="font" type="font/woff2" crossorigin>'
        . '</head><body>'
        // nul-home: a NUL byte in the body — grep without -a reads the page as binary.
        . (has('nul-home') ? "\0" : '')
        // invalid-byte-home: a byte that is no UTF-8 (\xFF) in the text of the page — no finding.
        . (has('invalid-byte-home') ? "<p>Caf\xff</p>" : '')
        . (has('esc-src') ? "<img src=\"/media/y\x1b[32mGREEN\x1b[0m\r::error::forged.webp\" alt=\"e\">" : '')
        . (has('long-src') ? '<img src="/media/' . str_repeat('b', 100000) . '.webp" alt="l">' : '')
        // foreign-webp: the only .webp is on a CDN. crowded-webp: five foreign ones sort before the own one.
        . (has('foreign-webp') ? '<img src="https://cdn.example/bild.webp" alt="x">'
            : (has('prod-link') ? '<img src="https://PROD.example:443/media/bild.webp" alt="x">' : '<img src="media/bild.webp" alt="x">'))
        . (has('crowded-webp') ? implode('', array_map(static fn (int $i): string => "<img src=\"http://0cdn.example/$i.webp\" alt=\"c\">", range(1, 5))) : '')
        // A name with brackets: curl must not read it as a range (x1, x2, x3).
        . (has('glob-src') ? '<img src="/media/x[1-3].webp" alt="z">' : '')
        . (has('amp-attr') ? '<img src="/media/bild.webp?a=1&amp;b=2" alt="a"><img srcset="/media/bild.avif?s=1&amp;t=2 1x, /media/bild-2x.avif?s=2&amp;t=2 2x" alt="b">' : '')
        . (has('numref-attr') ? '<img src="/media/x&#47;bild.webp" alt="n"><img src="/media/other.jpg?v=&#233;" alt="o">' : '')
        . (has('multiline-link') ? "<img src=\"/media/a.webp\"\n srcset=\"/media/b.webp 400w,\n /media/c.webp 800w\">\n<img alt='x' src='/media/d.webp'>" : '')
        // many-webp: seven own .webp files — the tool checks five and must say so.
        . (has('many-webp') ? implode('', array_map(static fn (int $i): string => "<img src=\"/media/m$i.webp\" alt=\"m\">", range(1, 7))) : '')
        . '<img srcset="/media/bild.avif 1x, /media/bild-2x.avif 2x" alt="y">'
        . (has('comment-in-script') || has('abrupt-comment') ? '<!-- footer -->' : '')
        . (has('unquoted-attr') ? '<img src=/media/a.webp alt=u>' : '')
        // ctrl-url: a control character inside an address — the browser asks for
        // /media/bi%01ld.webp; dropping it would ask for /media/bild.webp, which is there (PASSED).
        // The same in a url() of the stylesheet (s\x01ans.woff2, sans.woff2 is there) and in a <loc>.
        . (has('ctrl-url') ? "<img src=\"/media/bi\x01ld.webp\" alt=\"c\">" : '')
        . (has('foreign-numref') ? '<img src="https://cdn.example.com/caf&#233;.avif" alt="f">' : '')
        . '</body></html>');
}

if ($path === '/assets/css/tailwind.css') {
    // esc-ctype: a Content-Type header carrying ESC — printed in the stylesheet finding.
    respond(200, has('esc-ctype') ? "text/css\x1b[35mMAGENTA\x1b[0m" : 'text/css',
        '@font-face{src:url("../fonts/sans.woff2") format("woff2")}'
        . '.x{background:url(data:image/png;base64,AAAA)}'
        . (has('ctrl-url') ? "@font-face{src:url(\"../fonts/s\x01ans.woff2\")}" : '')
        // Bytes that are no UTF-8 in a url() — a stylesheet is read as UTF-8 whatever
        // it declares (Kirby writes UTF-8), as the browser reads one without a charset parameter (this one has
        // none) and asks for U+FFFD (%EF%BF%BD), one per maximal subpart: \x9A is one, and so is
        // \xE9\x9B (a lead byte with the continuation byte that fits it) — never %9A or a raw \xE9.
        . (has('ctrl-url') ? "@font-face{src:url(\"../fonts/s\x9aans.woff2\")}@font-face{src:url(\"../fonts/x\xe9\x9by.woff2\")}" : ''));
}

if ($path === '/assets/fonts/sans.woff2' || $path === '/assets/fonts/serif.woff2') {
    $type = (has('woff2-type') && $path === '/assets/fonts/sans.woff2')
        ? 'application/octet-stream'
        : 'font/woff2';
    respond(200, $type, 'wOF2');
}

if ($path === '/media/x[1-3].webp' || preg_match('#^/media/(m[1-7]|[a-d])\.webp$#', $path) === 1) {
    respond(200, 'image/webp', 'RIFF');
}

if ($path === '/media/bild.webp') {
    respond(200, has('webp-type') ? 'text/plain' : 'image/webp', 'RIFF');
}

if ($path === '/media/bild.avif' || $path === '/media/bild-2x.avif') {
    respond(200, 'image/avif', 'avif');
}

if ($path === '/robots.txt') {
    if (has('robots-head') && $method === 'HEAD') {
        respond(404, 'text/html', 'nicht gefunden');
    }
    // closed-preview: the Sudare package's closed robots.txt (Disallow: /, no Sitemap
    // line); closed-robots: the same rule with the sitemap still listing pages.
    if (has('closed-preview') || has('closed-robots')) {
        respond(200, 'text/plain; charset=utf-8', "# Closed preview\nUser-agent: *\nDisallow: /\n");
    }
    respond(200, 'text/plain; charset=utf-8', "User-agent: *\nAllow: /\n\nSitemap: $base/sitemap.xml\n");
}

if ($path === '/sitemap.xml') {
    $foreign = has('foreign-sitemap') ? '<url><loc>https://alte-domain.example/</loc></url>' : '';
    if (has('empty-sitemap') || has('closed-preview')) {
        respond(200, 'application/xml', '<urlset></urlset>');
    }
    // The GET fails while HEAD is fine; the body is HTML; the body
    // is larger than the 10 MiB the tool reads; a <loc> of 100 000 characters;
    // a <loc> with ESC and CR (a log-line injection); XML entities in a query;
    // the production origin spelled with capitals and with its default port.
    if (has('sitemap-get-500') && $method === 'GET') {
        respond(500, 'text/html', 'Whoops');
    }
    // A transfer that breaks after the headers. short-sitemap: the headers promise
    // 4000 bytes, the body ends after the first entry and the connection closes (curl exit 18,
    // "transfer closed with N bytes remaining") — not to be read as a complete one-page
    // sitemap. stall-sitemap: the headers arrive, the body never does (curl exit 28 at
    // GENBA_MAX_TIME=2); the pause outlasts that timeout, and HEAD is answered at once so the
    // run goes on. Only the GET: the HEAD of check 3 is fine in both cases.
    if (has('short-sitemap') && $method === 'GET') {
        respond(200, 'application/xml', '<?xml version="1.0"?><urlset>' . "<url><loc>$base/</loc></url>", [], 4000);
    }
    if (has('stall-sitemap') && $method === 'GET') {
        http_response_code(200);
        header('Content-Type: application/xml');
        header('Content-Length: 100');
        flush();
        sleep(3);
        exit;
    }
    if (has('sitemap-html')) {
        respond(200, 'application/xml', '<html><body>Startseite</body></html>');
    }
    if (has('huge-sitemap')) {
        respond(200, 'application/xml', '<?xml version="1.0"?><urlset>' . "<url><loc>$base/</loc></url>"
            . '<!--' . str_repeat(' ', 10485761) . '--></urlset>');
    }
    if (has('long-loc')) {
        $foreign .= "<url><loc>$base/" . str_repeat('a', 100000) . '</loc></url>';
    }
    if (has('control-loc')) {
        $foreign .= "<url><loc>https://evil.example/\x1b[31;1mRED\x1b[0m\rGenba smoke: PASSED.</loc></url>";
    }
    if (has('entity-loc')) {
        $foreign .= "<url><loc>$base/kontakt?a=1&amp;b=2</loc></url><url><loc>$base/kontakt?q=&apos;x&apos;&amp;r=&quot;y&quot;</loc></url>";
    }
    // Numeric character references (&#38; and &#x26; are &, &#61; is =);
    // a pretty-printed entry with the address on its own line; CDATA around the
    // address; entries no parser could read (a <url> without <loc>, a <loc> with
    // markup inside) — they must be counted, not dropped.
    if (has('numeric-entity-loc')) {
        $foreign .= "<url><loc>$base/kontakt?a=1&#38;b=2</loc></url><url><loc>$base/kontakt?c=&#x26;d&#61;3</loc></url>";
    }
    if (has('pretty-loc')) {
        $foreign .= "<url>\n    <loc>\n        $base/kontakt?p=1\n    </loc>\n    <lastmod>2026-10-12</lastmod>\n</url>\n";
    }
    if (has('cdata-loc')) {
        $foreign .= "<url><loc><![CDATA[$base/kontakt?c=1]]></loc></url>";
    }
    if (has('unparsed-loc')) {
        $foreign .= "<url><lastmod>2026-10-12</lastmod></url><url><loc>$base/kontakt<b>x</b></loc></url>";
    }
    // &amp;#38; is the text "&#38;", escaped once — decoded once it is "&#38;",
    // whose # starts the fragment; decoded twice it would be "&", and ?v=1&w=2 would be fetched.
    if (has('double-entity-loc')) {
        $foreign .= "<url><loc>$base/kontakt?v=1&amp;#38;w=2</loc></url>";
    }
    // A relative entry — the sitemap protocol requires the full address.
    if (has('relative-loc')) {
        $foreign .= '<url><loc>/relativ</loc></url>';
    }
    // Entries that are empty after decoding and filtering — an empty <loc>, one
    // of whitespace, one of a control character only. empty-loc next to the two healthy
    // pages; all-empty-loc with nothing else, so no page of the site is fetched.
    if (has('empty-loc')) {
        $foreign .= '<url><loc></loc></url><url><loc>  </loc></url>';
    }
    // Character references the tool does not decode. other-numref-loc: &#47; in the
    // path (/blog/neu answers 500, the cut /blog& answers 200 — a cut address would pass);
    // nonascii-loc: &#233; (Kirby's Xml::encode() writes every non-ASCII character so);
    // named-entity-loc: a named HTML entity. None of them may be fetched.
    if (has('other-numref-loc')) {
        $foreign .= "<url><loc>$base/blog&#47;neu</loc></url>";
    }
    if (has('nonascii-loc')) {
        $foreign .= "<url><loc>$base/caf&#233;</loc></url>";
    }
    if (has('named-entity-loc')) {
        $foreign .= "<url><loc>$base/kontakt?q=caf&eacute;</loc></url>";
    }
    // comment-loc: commented-out entries — one on a line of its own, one over several
    // lines, one closed by "--->"; /alt answers 404. They are no entries of the sitemap.
    if (has('comment-loc')) {
        $foreign .= "<!-- <url><loc>$base/alt</loc></url> -->\n<!--\n  <url>\n    <loc>$base/alt?2</loc>\n  </url>\n--><!-- old - <url><loc>$base/alt?3</loc></url> --->";
    }
    // invalid-byte-sitemap: a byte that is no UTF-8 (\xFF) next to the entries, in an image
    // title of a third page — no finding, the three pages are fetched.
    if (has('invalid-byte-sitemap')) {
        $foreign .= "<url><loc>$base/kontakt?b=1</loc><image:image><image:title>Caf\xff</image:title></image:image></url>";
    }
    // invalid-byte-loc: bash's [[ =~ ]] in a UTF-8 locale matches nothing in a text with
    // a byte that is no UTF-8 (macOS bash 3.2): /caf\xFF&#233; would escape the reference check and be
    // fetched as the cut /caf\xFF&. The second entry carries the byte in its fragment (PHP's server
    // drops a request with a raw \xFF in its path; curl does not send the fragment): absolute, fetched.
    // The third has it right behind the :// — "not an absolute URL" in a UTF-8 locale, a foreign host.
    if (has('invalid-byte-loc')) {
        $foreign .= "<url><loc>$base/kontakt?y=1#caf\xff</loc></url><url><loc>$base/caf\xff&#233;</loc></url>"
            . "<url><loc>http://\xff.example/</loc></url>";
    }
    if (has('ctrl-url')) {
        $foreign .= "<url><loc>$base/kon\x01takt</loc></url><url><loc>$base/kon\xc3takt</loc></url>";
    }
    if (has('all-empty-loc')) {
        respond(200, 'application/xml',
            '<?xml version="1.0"?><urlset><url><loc></loc></url><url><loc>' . "\x1b" . '</loc></url></urlset>');
    }
    if (has('prod-sitemap-variants')) {
        respond(200, 'application/xml',
            '<?xml version="1.0"?><urlset><url><loc>https://PROD.example/</loc></url>'
            . '<url><loc>https://prod.example:443/kontakt?x=1</loc></url></urlset>');
    }
    if (has('only-foreign-sitemap')) {
        // A staging host whose site URL is production: not one own page.
        respond(200, 'application/xml',
            '<?xml version="1.0"?><urlset><url><loc>https://alte-domain.example/</loc></url>'
            . '<url><loc>https://alte-domain.example/kontakt</loc></url></urlset>');
    }
    if (has('prod-sitemap')) {
        // Staging whose site URL is production: every <loc> is on the live host
        // (with foreign-sitemap, one more on a third origin).
        respond(200, 'application/xml',
            '<?xml version="1.0"?><urlset><url><loc>https://prod.example/</loc></url>'
            . '<url><loc>https://prod.example/kontakt?x=1</loc></url>' . $foreign . '</urlset>');
    }
    respond(200, 'application/xml',
        '<?xml version="1.0"?><urlset>'
        . "<url><loc>$base/</loc></url><url><loc>$base/kontakt</loc></url>"
        . $foreign . '</urlset>');
}

// other-numref-loc: the decoded address is broken, the cut one answers 200.
if ($path === '/blog/neu') {
    respond(500, 'text/html', 'Whoops');
}
if ($path === '/blog&') {
    respond(200, 'text/html', '<p>cut</p>');
}

if ($path === '/llms.txt') {
    respond(200, 'text/plain; charset=utf-8', "# Testhof\n\n> Hofladen\n");
}

if ($path === '/kontakt') {
    if (has('page-500')) {
        respond(500, 'text/html', 'Whoops');
    }
    if ((has('entity-loc') || has('numeric-entity-loc')) && preg_match('/amp;|#\d+;|#x[0-9a-f]+;/i', (string) ($_SERVER['QUERY_STRING'] ?? '')) === 1) {
        // The tool sent the XML-escaped query as it stood: a real site would 404 too.
        respond(404, 'text/html', 'query mangled');
    }
    respond(200, 'text/html', '<p>Kontakt</p>');
}

// Files above public/ that a misconfigured document root would serve: an
// executed config.php answers 200 with an empty body.
if (has('private-open') && in_array($path, ['/site/config/config.php', '/.env', '/genba-extra/secret.txt'], true)) {
    respond(200, 'text/plain', $path === '/.env' ? "SECRET=1\n" : '');
}

// api-slug: the API under its own api.slug (/schnittstelle); /api answers 404 then.
$apiRoot = has('api-slug') ? '/schnittstelle' : '/api';
if ($path === $apiRoot . '/system') {
    $installed = (has('panel-empty') || has('panel-open')) ? 'false' : 'true';
    $installable = has('panel-open') ? 'true' : 'false';
    respond(200, 'application/json',
        '{"code":200,"data":{"isInstallable":' . $installable . ',"isInstalled":' . $installed . ',"isOk":true}}');
}

if ($path === '/http-umleitung') {
    if (has('no-redirect')) {
        respond(200, 'text/html', 'unverschluesselt');
    }
    // esc-redirect: the redirect target, printed in the ok line, carries ESC (a CR could
    // not be sent: PHP's header() refuses it).
    // raw-c1-redirect: raw C1 bytes in the target — at the start, after ß, after
    // ASCII — and the UTF-8 C1 \xC2\x9B. No other invalid byte: a lone \xE9 would stop macOS sed
    // in a UTF-8 locale further on, and the test is about the filter.
    respond(301, 'text/html', '', ['Location' => "https://$host/" . (has('esc-redirect') ? "\x1b[31mRED\x1b[0m" : '')
        . (has('raw-c1-redirect') ? "\x9b31mA-stra\xc3\x9f\x9be-\xc2\x9b0m-\x9b" : '')]);
}

# The Panel: a visitor who is not signed in is sent on to the login page, which
# names two files under /media/panel (fault panel-no-files: none; panel-css-404:
# the stylesheet is missing); panel-redirect-out: the redirect leaves /panel.
// Kirby 5 sends the redirect as a Refresh header; panel-location: as a Location.
// panel-absolute: the target absolute, built from Kirby's url option — on staging the
// production origin; panel-refresh-query: a target outside /panel with url= in its query;
// panel-refresh-quoted: the target in quotes after URL= in capitals.
// panel-slug: the Panel under its own panel.slug (/kontrolle); /panel answers 404 then.
$panelRoot = has('panel-slug') ? '/kontrolle' : '/panel';
if ($path === $panelRoot) {
    $target = has('panel-redirect-out') ? '/elsewhere' : $panelRoot . '/login';
    if (has('panel-absolute')) {
        $target = 'https://prod.example' . $panelRoot . '/login';
    }
    if (has('panel-refresh-query')) {
        $target = '/elsewhere?next=url=' . $panelRoot . '/login';
    }
    $refresh = has('panel-refresh-quoted') ? "0;URL='$target'" : '0; url=' . $target;
    $redirect = has('panel-location') ? ['Location' => $target] : ['Refresh' => $refresh];
    respond(302, 'text/html; charset=utf-8', '', $redirect);
}
if ($path === $panelRoot . '/login') {
    $files = has('panel-no-files') ? '' : '<script src="/media/panel/js/app.js"></script>'
        . '<link rel="stylesheet" href="/media/panel/css/app.css">';
    if (has('panel-plugins-empty')) {
        $files .= '<link rel="stylesheet" href="/media/plugins/index.css?0"><script src="/media/plugins/index.js?0"></script>';
    }
    respond(200, 'text/html; charset=utf-8', '<!doctype html><title>Panel</title>' . $files);
}
// panel-plugins-empty: the page also names Kirby's plugin bundles, which answer 200
// without a byte when no plugin brings Panel code (?0: no plugin file).
if ($path === '/media/plugins/index.css') {
    respond(200, 'text/css; charset=UTF-8', '');
}
if ($path === '/media/plugins/index.js') {
    respond(200, 'application/javascript; charset=UTF-8', '');
}
// panel-js-empty: the script answers 200 without a byte; panel-js-text: as text/javascript
// (RFC 9239, newer Apache and nginx); panel-html-fallback: a fallback route answers the
// stylesheet with an HTML page, 200.
if ($path === '/media/panel/js/app.js') {
    respond(200, has('panel-js-text') ? 'text/javascript; charset=utf-8' : 'application/javascript', has('panel-js-empty') ? '' : 'window.panel = true;');
}
if ($path === '/media/panel/css/app.css') {
    if (has('panel-css-404')) {
        respond(404, 'text/plain', 'nicht gefunden');
    }
    if (has('panel-html-fallback')) {
        respond(200, 'text/html; charset=utf-8', '<!doctype html><title>Startseite</title>');
    }
    respond(200, 'text/css', 'body{margin:0}');
}

if (has('no-404')) {
    respond(200, 'text/html', 'Startseite statt Fehlerseite');
}
respond(404, 'text/html', 'nicht gefunden');
