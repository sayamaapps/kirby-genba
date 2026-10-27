<?php

/**
 * Counter-check for bin/genba-links (SPEC §9.6), without the network: a local
 * server plays YouTube oEmbed, Digistore product pages and purchase links with
 * redirect chains. The cases are the failure modes a status-code check misses: an
 * ID pointing to another own album, one to an empty page, one to a 404. The check must be
 * able to turn red — this test proves it.
 */
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\Links;

#[CoversClass(\Sayamaapps\Genba\Links::class)]
final class LinksTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			$packageRoot = dirname(__DIR__);
			$tmp = genba_tmp('links');
			$server = null;
			$lan = null;
			try {
				// ── classify(), no network ──────────────────────────────────────────
				$links = new Links();
				$cases = [
					'https://www.youtube.com/watch?v=61qhIemkozU' => ['youtube', '61qhIemkozU'],
					'https://www.youtube.com/watch?si=x&v=61qhIemkozU&feature=youtu.be' => ['youtube', '61qhIemkozU'],
					'https://youtu.be/UQz20_bjLgI?si=abc' => ['youtube', 'UQz20_bjLgI'],
					'https://www.youtube-nocookie.com/embed/2jTm0NZQ-Wg' => ['youtube-embed', '2jTm0NZQ-Wg'],
					'https://www.youtube.com/shorts/2jTm0NZQ-Wg' => ['youtube', '2jTm0NZQ-Wg'],
					'https://img.youtube.com/vi/2jTm0NZQ-Wg/maxresdefault.jpg' => ['youtube', '2jTm0NZQ-Wg'],
					'https://www.youtube.com/channel/UCBRboOIpQLe33Rwb0l9dqVw' => null,
					'https://www.youtube-nocookie.com/embed/' => null,
					// A playlist embed: videoseries has 11 letters, like an ID, and is none.
					'https://www.youtube-nocookie.com/embed/videoseries?list=' => null,
					'https://www.youtube.com/embed/videoseries?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG' => null,
					'https://www.digistore24.com/product/536437' => ['digistore', '536437'],
					'https://www.digistore24.com/product/536437/123456' => ['digistore', '536437'],
					'https://www.digistore24.com/redir/536437/sayama/' => ['purchase', 'https://www.digistore24.com/redir/536437/sayama/'],
					'https://www.digistore24.com/page/privacy' => null,
					'https://www.digistore24.com/service/js/orderform_widget.js' => null,
					'https://paypal.me/sayamamusic' => ['purchase', 'https://paypal.me/sayamamusic'],
					'https://www.copecart.com/products/abc/checkout' => ['purchase', 'https://www.copecart.com/products/abc/checkout'],
					'https://example.com/youtube.com/watch?v=61qhIemkozU' => null,
				];
				foreach ($cases as $url => $want) {
					$got = $links->classify($url);
					expect($want, $got === null ? null : [$got['kind'], $got['key']], "classify($url)");
				}
				expect('536437', ($links->classify('https://www.digistore24.com/redir/536437/sayama/')['product'] ?? null), 'Digistore redirect also checks the product');

				// Placeholders in templates are no references; HTML entities are decoded.
				$template = <<<'PHP'
            <img src="https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg">
            <?php $u = 'https://www.digistore24.com/product/' . $page->digistoreId(); ?>
            <a href="https://www.digistore24.com/product/<?= $id ?>">Buy</a>
            <a href="https://www.youtube.com/watch?v=61qhIemkozU&amp;t=10">Trailer</a>.
            PHP;
				$refs = $links->fromText($template, 'template.php');
				expect(1, count($refs), 'template: exactly one real reference');
				expect('61qhIemkozU', $refs[0]['key'] ?? null, 'template: the real video ID');
				expect('template.php:4', $refs[0]['place'] ?? null, 'template: place with line number');

				// Product names against the real cases of 91255fb.
				$names = [
					['Klang Chakra Aktivierung ~ ThymusChakra', ['Thymuschakra'], true],
					['Klang Chakra Aktivierung ~ AtlasChakra', ['Thymuschakra'], false],
					['Gartenhaus 77', ['Seelenstern'], false],
					['Klang Chakra Aktivierung ~ GalaktischesHerz', ['Galaktisches Herz'], true],
					['Sayama Solfeggio Frequenzen - Licht und Frieden', ['Licht & Frieden', 'Light & Peace'], true],
					['Klang Chakra Aktivierung ~ SET Extended Edition', ['Klang Chakra Aktivierung der NebenChakren Komplett-Set "Extended Editions"'], true],
					['Sternzeichen Affirmationen ~ Widder', ['Sternzeichen-Affirmationen Wassermann'], false],
					['Light & Peace', ['Licht & Frieden', 'Light & Peace'], true],
				];
				foreach ($names as [$product, $titles, $want]) {
					expect($want, Links::nameMatches($product, $titles), "nameMatches('$product', '{$titles[0]}')");
				}

				// ── Local server: oEmbed, Digistore, purchase chains ────────────────
				genba_write("$tmp/server/router.php", <<<'PHP'
            <?php
            $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
            $blind = str_starts_with($p, '/blind/');
            if (str_ends_with($p, '/oembed')) {
                parse_str((string) parse_url($q['url'] ?? '', PHP_URL_QUERY), $u);
                $v = $blind ? 'jNQXAC9IVRw' : ($u['v'] ?? '');
                $title = ['jNQXAC9IVRw' => 'Me at the zoo', 'gutesVideo1' => 'HerZEN Klang',
                          'boeseTitel1' => "Me\n::error file=README.md,line=1::forged\u{85}x"][$v] ?? null;
                if ($title !== null) { header('Content-Type: application/json'); echo json_encode(['title' => $title]); return true; }
                http_response_code(['privatVideo' => 403, 'gesperrtVid' => 401, 'wackelVideo' => 503][$v] ?? 404);
                return true;
            }
            if (preg_match('~/product/(\d+)$~', $p, $t)) {
                $title = $blind ? 'Irgendwas - Digistore24' : ([
                    '100' => 'HerZEN Klang - Digistore24',
                    '101' => 'Klang Chakra Aktivierung ~ AtlasChakra - Digistore24',
                    '102' => 'Digistore24 | Digistore24',
                    '103' => 'Klang Chakra Aktivierung ~ ThymusChakra - Digistore24',
                    '104' => 'Fake&#10;::error file=README.md,line=1::forged - Digistore24',
                ][$t[1]] ?? null);
                if ($title === null) { http_response_code(404); echo '<title>404 Page Not Found</title>'; return true; }
                echo "<html><head><title>$title</title></head><body>" . str_repeat('x', 200000) . '</body></html>';
                return true;
            }
            $query = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
            switch ($p) {
                case '/kauf/gut': header('Location: /kauf/zwischen' . $query, true, 301); return true;
                case '/kauf/zwischen': header('Location: /kauf/ziel' . $query, true, 302); return true;
                case '/kauf/ziel': echo 'Kasse'; return true;
                case '/kauf/verlust': header('Location: /kauf/ziel', true, 302); return true;
                case '/kauf/tot': header('Location: /kauf/weg', true, 302); return true;
                case '/kauf/schleife': header('Location: /kauf/schleife', true, 302); return true;
                // Vendor-controlled targets inside the runner's own network (SSRF).
                case '/kauf/lan': header('Location: ' . getenv('GENBA_TEST_LAN_NAME') . '/geheim', true, 302); return true;
                case '/kauf/lan-ip': header('Location: ' . getenv('GENBA_TEST_LAN_IP') . '/geheim', true, 302); return true;
                case '/kauf/metadaten': header('Location: http://169.254.169.254/latest/meta-data/', true, 302); return true;
                // Other spellings of the same loopback target, and URLs two parsers can read differently.
                case '/kauf/dezimal': header('Location: http://2130706433:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim', true, 302); return true;
                case '/kauf/kurz': header('Location: http://127.1:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim', true, 302); return true;
                case '/kauf/mapped': header('Location: http://[::ffff:127.0.0.1]:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim', true, 302); return true;
                case '/kauf/userinfo': header('Location: http://shop.example@127.0.0.1:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim', true, 302); return true;
                case '/kauf/backslash': header('Location: http://127.0.0.1:' . getenv('GENBA_TEST_LAN_PORT') . '\\@shop.example/geheim', true, 302); return true;
                case '/kauf/pin': header('Location: http://shop.genba-test.invalid:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim', true, 302); return true;
                case '/kauf/pin-punkt': header('Location: http://SHOP.genba-test.invalid.:' . getenv('GENBA_TEST_LAN_PORT') . '/geheim#teil', true, 302); return true;
                // A shop that answers with a raw UTF-8 path in Location, as some do.
                case '/kauf/umlaut': header("Location: /produkt/kr\xC3\xA4uter?aff=sayama", true, 302); return true;
                // A host and a scheme of thousands of characters in Location (the refusal names them).
                case '/kauf/longhost': header('Location: http://' . str_repeat('a', 60) . '.' . str_repeat('b', 60) . str_repeat('.c', 1500) . '.localhost/', true, 302); return true;
                case '/kauf/longscheme': header('Location: ' . str_repeat('s', 3000) . '://x/', true, 302); return true;
                case '/produkt/kr%C3%A4uter': echo 'Kasse'; return true;
            }
            http_response_code(404);
            return true;
            PHP);
				// A second listener plays a service in the runner's network; it notes every hit.
				$hits = "$tmp/lan/hits.log";
				genba_write($hits, '');
				genba_write("$tmp/lan/router.php", <<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] !== '/ready') {
                file_put_contents(getenv('GENBA_TEST_HITS'), $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
            }
            echo 'intern';
            return true;
            PHP);
				$lan = genba_server("$tmp/lan/router.php", ['GENBA_TEST_HITS' => $hits], '/ready');
				$lanPort = (string)parse_url($lan['base'], PHP_URL_PORT);
				$server = genba_server("$tmp/server/router.php", [
					'GENBA_TEST_LAN_NAME' => "http://localhost:$lanPort",
					'GENBA_TEST_LAN_IP' => $lan['base'],
					'GENBA_TEST_LAN_PORT' => $lanPort,
				], '/kauf/ziel');
				$basis = $server['base'];

				$links = new Links(
					shopHosts: ['127.0.0.1'],
					oembedBase: "$basis/oembed",
					digistoreBase: "$basis/product/",
					pause: 0.0
				);
				$status = fn (array $r): string => $r['status'];
				expect(Links::OK, $status($links->youtube('gutesVideo1')), 'existing video is ok');
				expect(Links::FINDING, $status($links->youtube('geloeschtXX')), 'deleted video (404) is a finding');
				expect(Links::FINDING, $status($links->youtube('privatVideo')), 'private video (403) is a finding');
				expect(Links::NOTICE, $status($links->youtube('gesperrtVid')), 'embedding blocked is only a notice for a link');
				expect(Links::FINDING, $status($links->youtube('gesperrtVid', true)), 'embedding blocked is a finding for an embed');
				expect(Links::DISRUPTION, $status($links->youtube('wackelVideo')), '503 is a disruption, not a finding');
				expect(Links::OK, $status($links->digistore('100', ['HerZEN Klang'])), 'correct Digistore ID is ok');
				$r = $links->digistore('101', ['Thymuschakra', 'Thymus Chakra']);
				expect(Links::FINDING, $r['status'], 'ID of another own album is a finding');
				contains('AtlasChakra', $r['text'], 'wrong-album finding names the product');
				expect(Links::FINDING, $status($links->digistore('102')), 'empty Digistore page is a finding');
				expect(Links::FINDING, $status($links->digistore('999')), 'Digistore 404 is a finding');
				expect(Links::OK, $status($links->purchaseLink("$basis/kauf/gut?aff=sayama&cam=herbst")), 'chain with kept parameters is ok');
				$r = $links->purchaseLink("$basis/kauf/verlust?aff=sayama");
				expect(Links::FINDING, $r['status'], 'lost affiliate parameter is a finding');
				contains('aff=sayama', $r['text'], 'lost-parameter finding names the parameter');
				expect(Links::FINDING, $status($links->purchaseLink("$basis/kauf/tot")), 'chain ending in 404 is a finding');
				expect(Links::FINDING, $status($links->purchaseLink("$basis/kauf/schleife")), 'redirect loop is a finding');
				expect([], $links->counterCheck(), 'counter-check passes against the honest server');

				// SSRF: a purchase redirect into the runner's own network is refused, before any request.
				$r = $links->purchaseLink("$basis/kauf/lan");
				expect(Links::FINDING, $r['status'], 'redirect to localhost is a finding');
				contains('redirect to localhost refused (local host name)', $r['text'], 'the refusal names the host');
				$r = $links->purchaseLink("$basis/kauf/lan-ip");
				expect(Links::FINDING, $r['status'], 'redirect to 127.0.0.1 on another port is a finding');
				contains('refused (private or local address 127.0.0.1)', $r['text'], 'the refusal names the address');
				$r = $links->purchaseLink("$basis/kauf/metadaten");
				expect(Links::FINDING, $r['status'], 'redirect to the cloud metadata address is a finding');
				contains('169.254.169.254', $r['text'], 'the metadata refusal names the address');
				expect('', (string)file_get_contents($hits), 'the service in the network was never contacted');
				$open = new Links(shopHosts: ['127.0.0.1'], pause: 0.0, allowPrivate: true);
				expect(Links::OK, $status($open->purchaseLink("$basis/kauf/lan")), 'with allowPrivate the redirect to localhost is followed');
				expect(Links::OK, $status($open->purchaseLink("$basis/kauf/lan-ip")), 'with allowPrivate the redirect to 127.0.0.1 is followed');
				expect("/geheim\n/geheim\n", (string)file_get_contents($hits), 'allowPrivate reached the listener (the probe is real)');
				genba_write($hits, '');

				// Other spellings of loopback: decimal, short form, IPv4-mapped IPv6.
				foreach (['dezimal' => '(numeric host in a non-canonical form)', 'kurz' => '(numeric host in a non-canonical form)', 'mapped' => '(private or local address ::ffff:127.0.0.1)'] as $case => $why) {
					$r = $links->purchaseLink("$basis/kauf/$case");
					expect(Links::FINDING, $r['status'], "redirect via /kauf/$case is a finding");
					contains($why, $r['text'], "redirect via /kauf/$case: reason");
				}
				// Parser differential: refused even with allowPrivate, because curl and parse_url could disagree.
				// The reason in parentheses: the path /kauf/<case> is in the chain text too.
				foreach (['userinfo' => '(user information in the address)', 'backslash' => '(whitespace, control character, backslash'] as $case => $why) {
					$r = $open->purchaseLink("$basis/kauf/$case");
					expect(Links::FINDING, $r['status'], "redirect via /kauf/$case is a finding, even with allowPrivate");
					contains($why, $r['text'], "redirect via /kauf/$case: reason");
				}
				expect('', (string)file_get_contents($hits), 'no other spelling or ambiguous URL reached the listener');

				// DNS: resolved once per hop, every address checked, and exactly the checked one is pinned.
				$lookups = [];
				$dns = static function (array $answer) use (&$lookups): Closure {
					return static function (string $host) use ($answer, &$lookups): array {
						$lookups[] = $host;
						return $answer;
					};
				};
				$pinned = new Links(shopHosts: ['127.0.0.1'], pause: 0.0, allowPrivate: true, resolver: $dns(['127.0.0.1']));
				expect(Links::OK, $status($pinned->purchaseLink("$basis/kauf/pin")), 'a name only the test resolver knows is reached through the pinned address');
				expect("/geheim\n", (string)file_get_contents($hits), 'the pinned address was the one connected to');
				expect(['shop.genba-test.invalid'], $lookups, 'the redirect host is resolved exactly once');
				genba_write($hits, '');
				// curl gets the rebuilt URL (lower case, no trailing dot, no fragment), so the pin applies to exactly that host.
				expect(Links::OK, $status($pinned->purchaseLink("$basis/kauf/pin-punkt")), 'a host in another spelling reaches the pinned address too');
				expect("/geheim\n", (string)file_get_contents($hits), 'the rebuilt URL was requested, not the original string');
				genba_write($hits, '');
				$lookups = [];
				$mixed = new Links(shopHosts: ['127.0.0.1'], pause: 0.0, resolver: $dns(['93.184.215.14', '127.0.0.1']));
				$r = $mixed->purchaseLink("$basis/kauf/pin");
				expect(Links::FINDING, $r['status'], 'a host with one public and one loopback address is refused');
				contains('private or local address 127.0.0.1', $r['text'], 'the loopback address among several is named');
				$lookups = [];
				$v6 = new Links(shopHosts: ['127.0.0.1'], pause: 0.0, resolver: $dns(['2606:2800:220:1::1', '::ffff:10.0.0.1']));
				contains('::ffff:10.0.0.1', $v6->purchaseLink("$basis/kauf/pin")['text'], 'a private AAAA record is refused too');
				expect('', (string)file_get_contents($hits), 'refused hosts were never contacted');

				// A raw non-ASCII path in Location is not a finding: it is requested percent-encoded.
				$r = $links->purchaseLink("$basis/kauf/umlaut?aff=sayama");
				expect(Links::OK, $r['status'], 'a redirect to /produkt/kräuter (raw UTF-8) is followed: ' . $r['text']);
				contains('/produkt/kr%C3%A4uter?aff=sayama', $r['text'], 'the target was requested percent-encoded');

				// The FIRST address of a purchase link from the content is checked too,
				// unless its host is exactly a configured shop host.
				$nas = new Links(shopHosts: ['example.test'], resolver: fn (string $h): array => ['192.168.1.10']);
				$r = $nas->purchaseLink('https://nas.example.test/x');
				expect(Links::FINDING, $r['status'], 'a content link to a host resolving to 192.168.1.10 is a finding');
				contains('address nas.example.test refused (private or local address 192.168.1.10) — not requested', $r['text'], 'the first address is refused, not requested');
				$probe = new Links(shopHosts: ['example.test'], pause: 0.0, resolver: fn (string $h): array => ['127.0.0.1']);
				expect(Links::FINDING, $status($probe->purchaseLink("http://nas.example.test:$lanPort/x")), 'a subdomain of a shop host is not trusted');
				expect('', (string)file_get_contents($hits), 'the refused first address was never contacted');
				$exact = new Links(shopHosts: ['nas.example.test'], pause: 0.0, resolver: fn (string $h): array => ['127.0.0.1']);
				expect(Links::OK, $status($exact->purchaseLink("http://nas.example.test:$lanPort/x")), 'a host that IS a configured shop host is requested');
				expect("/x\n", (string)file_get_contents($hits), 'the trusted first address reached the listener (the probe is real)');
				genba_write($hits, '');

				$urls = [
					'https://WWW.Example.com/a?b=c#frag' => 'https://www.example.com/a?b=c',
					'http://127.0.0.1:8080/x' => 'http://127.0.0.1:8080/x',
					'http://[::1]:8080/x' => 'http://[::1]:8080/x',
					'http://example.com' => 'http://example.com/',
					'http://2130706433/' => null, 'http://0x7f.1/' => null, 'http://017700000001/' => null, 'http://127.1/' => null,
					'http://0177.0.0.1/' => null, 'http://a@example.com/' => null, 'http://example.com\\@evil.example/' => null,
					'http://exa mple.com/' => null, "http://example.com/\nx" => null, 'ftp://example.com/' => null,
					'http://example.com:80:81/' => null, 'http://bücher.example/' => null, 'http://[::1/' => null,
					// A raw UTF-8 path is percent-encoded (as a browser does); the host stays refused.
					'https://example.com/produkt/kräuter?x=1' => 'https://example.com/produkt/kr%C3%A4uter?x=1',
					'https://bücher.example/kräuter' => null,
				];
				foreach ($urls as $url => $want) {
					$got = Links::requestUrl($url);
					expect($want, $got['url'] ?? null, 'requestUrl(' . json_encode($url) . ')');
				}
				$addresses = [
					'127.0.0.1' => true, '127.8.9.10' => true, '10.1.2.3' => true, '172.16.0.1' => true, '172.31.255.255' => true,
					'172.32.0.1' => false, '192.168.1.1' => true, '169.254.169.254' => true, '100.64.0.1' => true,
					'100.127.255.255' => true, '100.128.0.1' => false, '0.0.0.0' => true, '224.0.0.1' => true, '8.8.8.8' => false,
					'::1' => true, '::' => true, 'fe80::1' => true, 'febf::1' => true, 'fec0::1' => true, 'feff::1' => true, 'fd12:3456::1' => true,
					'fc00::1' => true, 'ff02::1' => true, '2001:4860:4860::8888' => false, '::ffff:127.0.0.1' => true,
					'::ffff:8.8.8.8' => false, '[::1]' => true, 'kein-name' => true,
					'::ffff:10.0.0.1' => true, '::127.0.0.1' => true, '2002:7f00:1::' => true, '2002:808:808::' => false,
					'64:ff9b::7f00:1' => true, '64:ff9b::808:808' => false, '198.18.0.1' => true, '198.19.255.255' => true,
					'198.20.0.1' => false, '255.255.255.255' => true, '192.0.0.8' => true, '2001:0:4136:e378::1' => true,
					// Deprecated site-local fec0::/10, SIIT ::ffff:0:0:0/96 (IPv4-translated, refused as a whole).
					'::ffff:0:7f00:1' => true, '::ffff:0:808:808' => true, '2001:4860::1' => false,
				];
				foreach ($addresses as $address => $private) {
					expect($private, Links::isPrivateAddress($address), "isPrivateAddress($address)");
				}

				// A field with a URL AND a bare ID: the URL comes from fromText(), the bare ID must not get lost.
				$mixed = $links->fromFields("Youtubevideos: https://www.youtube.com/watch?v=61qhIemkozU, UQz20_bjLgI\n", "$tmp/x.txt", 'x.txt');
				expect(['UQz20_bjLgI'], array_column($mixed, 'key'), 'fromFields(): the bare ID next to a URL is found, the URL ID is not doubled');

				// External text cannot forge a GitHub annotation: control characters become spaces.
				$r = $links->digistore('104');
				holds(!preg_match('/[\x00-\x1F]/', $r['text']), 'a Digistore title with &#10; prints on one line: ' . json_encode($r['text']));
				$r = $links->youtube('boeseTitel1');
				expect(Links::OK, $r['status'], 'a YouTube title with a line break is still an existing video');
				holds(!preg_match('/[\x00-\x1F]|\xC2[\x80-\x9F]/', $r['text']), 'a YouTube title with \\n and U+0085 prints on one line: ' . json_encode($r['text']));
				expect('a b', Links::clean("a\x7Fb"), 'clean(): DEL becomes a space');
				expect('a?b', str_replace("\u{FFFD}", '?', Links::clean("a\xFFb")), 'clean(): invalid UTF-8 is scrubbed');
				// A length limit: without one, a URL of 100 000 characters would be a log line of 100 073.
				// Above 2048 characters cut to the first 60 with the length, counted in characters (log_text).
				expect(str_repeat('é', 2048), Links::clean(str_repeat('é', 2048)), 'clean(): 2048 characters stay as they are');
				expect(str_repeat('é', 60) . '… (2049 characters)', Links::clean(str_repeat('é', 2049)), 'clean(): 2049 characters are cut to 60 with the length, in characters not bytes');
				expect(str_repeat('a', 3000), Links::clean(str_repeat('a', 3000), PHP_INT_MAX), 'clean() with PHP_INT_MAX does not cut');

				// youtube() reads `refused` like digistore() does — a refused oEmbed address is a
				// finding with its reason, not "YouTube unreachable: no response ()".
				$refusedBase = new Links(oembedBase: 'https://user@evil.example/oembed', pause: 0.0);
				$r = $refusedBase->youtube('gutesVideo1');
				expect(Links::FINDING, $r['status'], 'a refused oEmbed address is a finding');
				contains('Video gutesVideo1: URL refused (user information in the address)', $r['text'], 'the refusal names the reason');
				holds(!str_contains($r['text'], 'unreachable'), 'a refused address is not reported as unreachable: ' . $r['text']);

				$blind = new Links(oembedBase: "$basis/blind/oembed", digistoreBase: "$basis/blind/product/", pause: 0.0);
				expect(2, count($blind->counterCheck()), 'counter-check fails twice against a server that calls everything present');

				// ── Whole runs through the bin file ─────────────────────────────────
				$project = "$tmp/projekt";
				genba_write("$project/content/1_shop/1_thymus/product.de.txt", "Title: Thymuschakra\n\n----\n\nPrice: 14,00\n\n----\n\nDigistoreid: 101\n");
				genba_write("$project/content/1_shop/1_thymus/product.en.txt", "Title: Thymus Chakra\n");
				genba_write("$project/content/1_shop/1_thymus/cover.jpg.de.txt", "Title: AtlasChakra Cover\n");
				genba_write(
					"$project/content/1_shop/2_herz/product.de.txt",
					"Title: HerZEN Klang\n\n----\n\nTrailer: https://www.youtube.com/watch?v=gutesVideo1\n\n----\n\nVideoid: privatVideo\n\n----\n\nDigistoreid: 100\n"
				);
				genba_write(
					"$project/content/2_links/default.de.txt",
					"Title: Links\n\n----\n\nText: (link: $basis/kauf/gut?aff=sayama text: Buy) and $basis/kauf/verlust?aff=sayama\n"
				);
				genba_write("$project/site/snippets/video.php", '<iframe src="https://www.youtube-nocookie.com/embed/<?= $id ?>"></iframe>');
				genba_write("$project/content/3_still/default.txt", "Title: Still\n\n----\n\nText: https://www.youtube.com/watch?v=wackelVideo\n");
				genba_write("$project/leer/.keep", '');
				genba_write("$project/lan/default.txt", "Title: Kasse\n\n----\n\nText: $basis/kauf/lan-ip\n");
				genba_write(
					"$project/boese/product.txt",
					"Title: Fake\n\n----\n\nDigistoreid: 104\n\n----\n\nTrailer: https://www.youtube.com/watch?v=boeseTitel1\n"
				);
				genba_write("$project/felder/product.txt", "Title: Feld\n\n----\n\nDigistoreid: 12345\n");

				$bin = $packageRoot . '/bin/genba-links';
				$net = ["--oembed-base=$basis/oembed", "--digistore-base=$basis/product/", '--shop-host=127.0.0.1'];
				$run = fn (array $args): array => genba_run(array_merge([genba_php(), $bin, '--pause=0'], $args), $project);

				[$code, $out] = $run(array_merge($net, ['content', 'site']));
				expect(1, $code, "findings run exits 1:\n$out");
				contains('Digistore product 101 is called "Klang Chakra Aktivierung ~ AtlasChakra", but the page is "Thymuschakra" / "Thymus Chakra" — wrong ID?', $out, 'wrong-ID finding');
				contains('Video privatVideo is private', $out, 'private video finding');
				contains('Parameters lost on the way: aff=sayama', $out, 'lost parameter finding');
				contains('::warning::Disruption, not assessed', $out, 'disruption is a warning');
				contains('Counter-check passed', $out, 'counter-check passed line');
				contains('3 findings', $out, 'summary counts 3 findings');
				holds(!str_contains($out, 'AtlasChakra Cover'), "image metadata is not a page title:\n$out");

				// Every piece of a result is cut on its own, the whole text never: cut again as a whole, a chain
				// of 1 100 characters with a lost parameter of 1 100 would shrink to its first 60, and the chain
				// and the rest of the cause would be gone.
				$long = "$tmp/projekt-lang";
				genba_write("$long/content/default.txt", "Title: Lang\n\n----\n\nText: $basis/kauf/verlust?aff=sayama&campaign=" . str_repeat('c', 1100) . "\n");
				[$code, $out] = genba_run(array_merge([genba_php(), $bin, '--pause=0'], $net, ['content']), $long);
				expect(1, $code, "a lost parameter of 1 100 characters is a finding:\n" . substr($out, 0, 3000));
				contains(': Parameters lost on the way: aff=sayama, campaign=ccc', $out, 'the lost parameters are printed');
				contains(' (http://127.0.0.1:', $out, 'the chain after a long result text is still printed');
				contains(' — in content/default.txt', $out, 'and the place');

				[$code, $out] = $run(array_merge($net, ['content/3_still']));
				expect(0, $code, "disruptions only exit 0:\n$out");
				contains('1 disruptions', $out, 'summary counts 1 disruption');

				[$code, $out] = $run(["--oembed-base=$basis/blind/oembed", "--digistore-base=$basis/blind/product/", 'content/3_still']);
				expect(1, $code, "blind run exits 1:\n$out");
				contains('::error::Counter-check', $out, 'blind run reports the counter-check');
				holds(preg_match('~\nGenba links: FAILED — counter-check failed, no reference was checked\n$~', $out) === 1, "the blind run ends with the Genba links line:\n$out");

				[$code, $out] = $run(['--list-only', 'site']);
				expect(0, $code, "list-only exits 0:\n$out");
				contains('0 external references', $out, 'template placeholders are no references');
				// --list-only ends with the tool's line too (SPEC §3.0), and an empty list is said out loud.
				contains('::notice::No external references found under site', $out, 'list-only with nothing found says so');
				holds(preg_match('~\nGenba links: 0 references listed\n$~', $out) === 1, "list-only ends with the Genba links line:\n$out");

				// URLs and places from the site's own files are log text — an ESC in a content
				// file or a directory name must not reach the list or a result line.
				genba_write("$project/esc/default.txt", "Title: Esc\n\n----\n\nText: https://www.youtube.com/watch?v=gutesVideo1&x=\x1b[31mRED\x1b[0m\n");
				genba_write("$project/esc/d\x1br/default.txt", "Text: https://www.youtube.com/watch?v=privatVideo\n");
				[$code, $out] = $run(['--list-only', 'esc']);
				expect(0, $code, "list-only over files with ESC exits 0:\n$out");
				holds(!str_contains($out, "\x1b"), "list-only: no ESC from a URL or a directory name reaches the log:\n" . addcslashes($out, "\x00..\x1f"));
				contains('(esc/d r/default.txt:1)', $out, 'the place with the ESC is printed with a space instead');
				// fromText() ends a URL at `[`, so the reference is "…&x=" + ESC: printed as a space.
				contains('watch?v=gutesVideo1&x=   (esc/default.txt:5)', $out, 'the URL with ESC is printed with a space instead');
				[$code, $out] = $run(array_merge($net, ['--skip-counter-check', 'esc']));
				expect(1, $code, "the check run over files with ESC exits 1 (private video):\n$out");
				holds(!str_contains($out, "\x1b"), "check run: no ESC from a URL or a place reaches the log:\n" . addcslashes($out, "\x00..\x1f"));
				contains('— in esc/d r/default.txt:1', $out, 'the result line prints the cleaned place');

				[$code, $out] = $run(['/nowhere']);
				expect(2, $code, "no existing path exits 2:\n$out");
				contains('None of the paths exist', $out, 'missing path message');
				// SPEC §3.0: every exit-2 path ends with the tool's line, on stdout.
				contains("\nGenba links: FAILED — cannot run\n", $out, 'exit 2 prints the Genba links line');

				[$code, $out] = $run(['leer']);
				expect(0, $code, "empty directory exits 0:\n$out");
				contains('::notice::No external references found', $out, 'empty directory is said out loud');

				[$code, $out] = $run(array_merge($net, ['--skip-counter-check', 'lan']));
				expect(1, $code, "a redirect into the network fails the run:\n$out");
				holds(preg_match('~^::error::.*kauf/lan-ip: Purchase link: redirect to 127\.0\.0\.1 refused~m', $out) === 1, "the refused redirect is an ::error:: line:\n$out");
				contains('  --   counter-check skipped (--skip-counter-check)', $out, '--skip-counter-check leaves a visible line');
				expect('', (string)file_get_contents($hits), 'the CLI run did not contact the service in the network either');

				// The result line is built of cut pieces and not cut as a whole, so the refusals must cut the
				// host and the scheme of a Location header too — uncut, they make a line of 3 394 characters.
				// Both are cut where the text is made.
				genba_write("$project/lang-host/default.txt", "Title: Lang\n\n----\n\nText: $basis/kauf/longhost\n\n----\n\nShop: $basis/kauf/longscheme\n");
				[$code, $out] = $run(array_merge($net, ['--skip-counter-check', 'lang-host']));
				expect(1, $code, "redirects to a host and a scheme of thousands of characters fail the run:\n" . substr($out, 0, 800));
				$longest = max(array_map('strlen', explode("\n", $out)));
				holds($longest < 600, "no output line is longer than 600 bytes (longest: $longest)");
				holds(
					preg_match('~^::error::.*kauf/longhost: Purchase link: redirect to a{60}… \(3131 characters\) refused \(local host name\) — not followed .* — in lang-host/default\.txt:5$~m', $out) === 1,
					"the refused host is cut to 60 characters with its length, the place is still printed:\n" . substr($out, 0, 800)
				);
				holds(
					preg_match('~^::error::.*kauf/longscheme: .*refused \(scheme s{60}… \(3000 characters\)\).* — in lang-host/default\.txt:9$~m', $out) === 1,
					"the refused scheme is cut to 60 characters with its length:\n" . substr($out, 0, 800)
				);
				$guard = (new ReflectionMethod(Links::class, 'guard'));
				$long = str_repeat('d', 3000) . '.localhost';
				$refusal = $guard->invoke(new Links(pause: 0.0), ['url' => "http://$long/", 'origin' => "http://$long:80", 'host' => $long, 'port' => 80, 'literal' => false], true, true);
				expect('address ' . str_repeat('d', 60) . '… (3010 characters) refused (local host name) — not requested', $refusal['refused'] ?? null, 'guard() cuts the host of the first address too');

				// A proxy from the environment would connect on Genba's behalf, past every check.
				$viaProxy = genba_run(
					array_merge([genba_php(), $bin, '--pause=0'], $net, ['--skip-counter-check', 'content/3_still']),
					$project,
					['http_proxy' => $lan['base'], 'HTTPS_PROXY' => $lan['base'], 'ALL_PROXY' => $lan['base'], 'NO_PROXY' => null, 'no_proxy' => null]
				);
				contains('1 disruptions', $viaProxy[1], 'with a proxy in the environment the run still reaches the fake site');
				expect('', (string)file_get_contents($hits), 'http_proxy/HTTPS_PROXY/ALL_PROXY are ignored');

				// A URL of 100 000 characters in a content file — the list line and the
				// finding line are cut, the length is named, the place is still printed.
				genba_write("$project/lang/default.txt", "Title: Lang\n\n----\n\nText: https://youtu.be/aaaaaaaaaaa?x=" . str_repeat('a', 100000) . "\n");
				foreach (['--list-only' => [['--list-only', 'lang'], 0], 'check run' => [array_merge($net, ['--skip-counter-check', 'lang']), 1]] as $mode => [$args, $exit]) {
					[$code, $out] = $run($args);
					expect($exit, $code, "$mode over a URL of 100 000 characters exits $exit:\n" . substr($out, 0, 500));
					$longest = max(array_map('strlen', explode("\n", $out)));
					holds($longest <= 2100, "$mode: no output line is longer than 2100 bytes (longest: $longest)");
					contains('https://youtu.be/aaaaaaaaaaa?x=aaaaaaaaaaaaaaaaaaaaaaaaaaaaa… (100031 characters)', $out, "$mode: the URL is cut to 60 characters with its length");
					contains('lang/default.txt:5', $out, "$mode: the place is still printed");
				}

				[$code, $out] = $run(array_merge($net, ['--skip-counter-check', 'boese']));
				holds(preg_match('/^::error file=/m', $out) === 0, "no output line forges an annotation from a fetched title:\n$out");
				contains('::error file=README.md,line=1::forged', $out, 'the forged text is still shown, inside its own line');

				// GitHub Actions turns an unset ${{ vars.X }} into "": that must not switch the field off.
				$memOut = fopen('php://memory', 'w+');
				$memErr = fopen('php://memory', 'w+');
				expect(0, Links::run(['GENBA_DIGISTORE_FIELDS' => '', 'GENBA_YOUTUBE_FIELDS' => ' '], ['genba-links', '--list-only', 'felder'], $project, $memOut, $memErr), 'empty field variables: list-only exits 0');
				rewind($memOut);
				$listed = (string)stream_get_contents($memOut);
				contains('1 external references found', $listed, 'GENBA_DIGISTORE_FIELDS= keeps the default field digistoreid');
				holds(preg_match('~\nGenba links: 1 references listed\n$~', $listed) === 1, "list-only with one reference ends with the Genba links line:\n$listed");
				fclose($memOut);
				fclose($memErr);
				[$code, $out] = $run(['--list-only', '--digistore-field=', 'felder']);
				expect(2, $code, "--digistore-field= without a value exits 2:\n$out");
				contains('::error::--digistore-field needs a value', $out, 'the empty option is named');
				contains("Genba links: FAILED — cannot run\n", $out, 'the empty option ends with the Genba links line');

				// Skipped by place, not by name; symbolic links; missing default paths; Genba's own package.
				$site = "$tmp/ort";
				$outside = "$tmp/ausserhalb";
				genba_write("$site/content/media/default.txt", "Title: Media\n\n----\n\nText: https://www.youtube.com/watch?v=gutesVideo1\n");
				genba_write("$site/geteilt/shop/default.txt", "Title: Shop\n\n----\n\nText: https://www.youtube.com/watch?v=privatVideo\n");
				genba_write("$outside/default.txt", "Text: https://www.youtube.com/watch?v=geloeschtXX\n");
				genba_write("$site/site/cache/page.txt", "Text: https://www.youtube.com/watch?v=wackelVideo\n");
				symlink('../geteilt/shop', "$site/content/shop");
				symlink($outside, "$site/content/away");
				symlink("$outside/default.txt", "$site/content/leak.txt");
				[$code, $out] = genba_run([genba_php(), $bin, '--list-only'], $site);
				expect(0, $code, "list-only over the default paths exits 0:\n$out");
				contains('2 external references found', $out, 'content/media and the linked content/shop are read');
				contains('(content/media/default.txt:5)', $out, 'a page folder called media is content');
				contains('(content/shop/default.txt:5)', $out, 'a linked directory inside the project is followed');
				contains('  --   not checked: content/away (symbolic link to a directory outside the project)', $out, 'a link outside the project is named');
				contains('  --   not checked: content/leak.txt (symbolic link to a file outside the project)', $out, 'a file link outside the project is named, not read');
				contains('  --   not checked: site/cache (Kirby cache)', $out, 'site/cache is named');
				contains('  --   not checked: public (default path, does not exist)', $out, 'a missing default path is named');
				holds(!str_contains($out, 'geloeschtXX') && !str_contains($out, 'wackelVideo'), "nothing outside the project or from site/cache is listed:\n$out");

				// A link INTO a skipped place is not followed — compared by real path, not by name.
				genba_write("$site/site/snippets/ok.php", "<?php // nothing\n");
				symlink('../cache', "$site/site/snippets/c");
				@mkdir("$site/public", 0700, true);
				symlink('..', "$site/public/p");
				[$code, $out] = genba_run([genba_php(), $bin, '--list-only'], $site);
				expect(0, $code, "list-only with links into skipped places exits 0:\n$out");
				contains('  --   not checked: site/snippets/c (symbolic link into site/cache)', $out, 'a link into site/cache is named, not followed');
				contains('  --   not checked: public/p/site/cache (inside site/cache, reached through a symbolic link)', $out, 'site/cache reached through public/p -> .. is named, not read');
				holds(!str_contains($out, 'wackelVideo'), "site/cache is not read through a link:\n$out");
				// The content reached again through public/p -> .. is read once per run, not once per path.
				contains('2 external references found', $out, 'the files behind public/p -> .. are not counted a second time');
				holds(!str_contains($out, 'public/p/content/media') && !str_contains($out, 'public/p/geteilt'), "no place is listed under public/p/:\n$out");
				unlink("$site/public/p");
				unlink("$site/site/snippets/c");

				// An unreadable directory or file is "cannot run" (exit 2), never a pass with links unchecked.
				$locked = "$tmp/gesperrt";
				genba_write("$locked/content/home/home.txt", "Text: https://www.youtube.com/watch?v=gesperrtXX1\n");
				chmod("$locked/content/home", 0000);
				try {
					if (@scandir("$locked/content/home") !== false) {
						echo "  --   running as root: the unreadable-directory run of genba-links is not tested\n";
					} else {
						[$code, $out] = genba_run([genba_php(), $bin, '--list-only'], $locked);
						expect(2, $code, "an unreadable directory exits 2:\n$out");
						// Relative to the working directory, like `Cannot read file` — not the absolute path.
						contains("::error::Cannot read directory: content/home\n", $out, 'the unreadable directory is named in an ::error:: line, relative to the working directory');
						holds(!str_contains($out, $locked), "no absolute path in the message:\n$out");
						holds(!str_contains($out, 'Stack trace') && !str_contains($out, 'PHP Fatal'), "no PHP stack trace:\n$out");
					}
				} finally {
					chmod("$locked/content/home", 0700);
				}
				// The directory name is log text too (an ESC in it must not reach stderr raw).
				$lockedEsc = "$locked/content/sec\x1b[31mRET";
				@mkdir($lockedEsc, 0700, true);
				chmod($lockedEsc, 0000);
				try {
					if (@scandir($lockedEsc) !== false) {
						echo "  --   running as root: the unreadable-directory-with-ESC run of genba-links is not tested\n";
					} else {
						[$code, $out] = genba_run([genba_php(), $bin, '--list-only'], $locked);
						expect(2, $code, "an unreadable directory with ESC in its name exits 2:\n" . addcslashes($out, "\x00..\x1f"));
						contains("::error::Cannot read directory: content/sec [31mRET\n", $out, 'the directory name is printed with a space for the ESC');
						holds(!str_contains($out, "\x1b"), "no ESC from the directory name reaches the log:\n" . addcslashes($out, "\x00..\x1f"));
					}
				} finally {
					chmod($lockedEsc, 0700);
				}
				chmod("$locked/content/home/home.txt", 0000);
				try {
					if (@file_get_contents("$locked/content/home/home.txt") !== false) {
						echo "  --   running as root: the unreadable-file run of genba-links is not tested\n";
					} else {
						[$code, $out] = genba_run([genba_php(), $bin, '--list-only'], $locked);
						expect(2, $code, "an unreadable file exits 2, its links are not silently unchecked:\n$out");
						contains('::error::Cannot read file: content/home/home.txt', $out, 'the unreadable file is named');
						holds(!str_contains($out, 'external references found'), "no summary after a file that could not be read:\n$out");
						contains("Genba links: FAILED — cannot run\n", $out, 'an unreadable file ends with the Genba links line');
					}
				} finally {
					chmod("$locked/content/home/home.txt", 0600);
				}

				// Installed from source (path repository): site/plugins/genba carries tests/ full of sample links.
				$installed = "$tmp/installiert";
				$plugin = "$installed/site/plugins/genba";
				foreach (['bin/genba-links', 'src/Links.php', 'src/Tree.php', 'src/Text.php', 'tests/LinksTest.php'] as $file) {
					genba_write("$plugin/$file", (string)file_get_contents("$packageRoot/$file"));
				}
				genba_write("$installed/content/home/home.txt", "Title: Home\n");
				[, $out] = genba_run([genba_php(), $bin, '--list-only'], $installed);
				holds(preg_match('~^([1-9]\d*) external references~m', $out) === 1, "control: seen from another package, the copied tests DO hold references:\n$out");
				[$code, $out] = genba_run([genba_php(), "$plugin/bin/genba-links", '--list-only'], $installed);
				expect(0, $code, "the installed copy runs:\n$out");
				contains("  --   not checked: site/plugins/genba (Genba's own package: its tests and docs carry sample links)", $out, 'the own package is named');
				contains('0 external references found', $out, "the own package's sample links are not the site's");

				[$code, $out] = $run(['--help']);
				expect(0, $code, "--help exits 0:\n$out");
				contains('Usage: genba-links', $out, '--help prints the usage');

				[$code, $out] = $run(['--bogus']);
				expect(2, $code, "unknown option exits 2:\n$out");
				contains("Genba links: FAILED — cannot run\n", $out, 'an unknown option ends with the Genba links line');

				// An argument error wins over --help, whatever the order.
				foreach ([['--help', '--bogus'], ['--bogus', '--help'], ['--help', '--digistore-field='], ['--help', '--pause=x']] as $order) {
					[$code, $out] = $run($order);
					$given = implode(' ', $order);
					expect(2, $code, "$given exits 2:\n$out");
					contains("Genba links: FAILED — cannot run\n", $out, "$given ends with the Genba links line");
				}

				// An empty path argument ("${{ vars.LINK_PATHS }}" unset) must not read the whole working
				// directory — notes/ included — instead of the default paths. Refused as in the other four tools.
				genba_write("$project/notizen/private.md", "Text: https://www.youtube.com/watch?v=notizVideo1\n");
				[$code, $out] = $run(['--list-only', '']);
				expect(2, $code, "an empty path argument exits 2:\n$out");
				contains('::error::Path argument is empty (omit it for the default paths)', $out, 'the empty path argument is named');
				contains("Genba links: FAILED — cannot run\n", $out, 'the empty path argument ends with the Genba links line');
				holds(!str_contains($out, 'notizVideo1') && !str_contains($out, 'external references found'), "nothing is read:\n$out");

				// Same entry point, in-process with memory streams.
				$memOut = fopen('php://memory', 'w+');
				$memErr = fopen('php://memory', 'w+');
				expect(2, Links::run(['GENBA_SHOP_HOSTS' => false], ['genba-links', '--nonsense'], $project, $memOut, $memErr), 'run() rejects an unknown option with exit 2');
				rewind($memErr);
				rewind($memOut);
				contains('::error::Unknown argument: --nonsense', (string)stream_get_contents($memErr), 'unknown option is reported on the error stream (the wording of the other four tools)');
				expect("Genba links: FAILED — cannot run\n", (string)stream_get_contents($memOut), 'stdout holds the last line alone');
				fclose($memOut);
				fclose($memErr);
			} catch (Throwable $e) {
				holds(false, 'LinksTest aborted: ' . $e->getMessage());
			} finally {
				if (is_array($server)) {
					genba_stop($server);
				}
				if (is_array($lan)) {
					genba_stop($lan);
				}
				genba_remove_tree($tmp);
			}
		} finally {
			ob_end_clean();
		}
	}
}
