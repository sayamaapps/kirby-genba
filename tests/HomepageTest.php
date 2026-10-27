<?php

/**
 * genba-smoke's homepage reader (src/Homepage.php): the srcset parser of the HTML
 * specification on every PHP version, the records of a page (Dom\HTMLDocument) from PHP 8.4 on.
 * The browser-level spellings are in the corpus (tests/HtmlCorpusTest.php); this file holds the
 * reader's own rules.
 */
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Genba\Homepage;

#[CoversNothing]
final class HomepageTest extends TestCase
{
	public function testSuite(): void
	{
		// The standalone checks of the former script run in one test method.
		ob_start();
		try {
			// "Parse a srcset attribute": candidates at commas, a comma at the end of an address is none of
			// it, a comma inside a descriptor's parentheses separates nothing, invalid descriptors drop the candidate.
			$srcset = [
				' /a.avif 1x, /b.avif 2x ' => ['/a.avif', '/b.avif'],
				"\n /a.avif 1x,\n /b.avif 2x\n" => ['/a.avif', '/b.avif'],
				'/a,b.avif 1x' => ['/a,b.avif'],
				'/a.avif,, /b.avif' => ['/a.avif', '/b.avif'],
				'/a.avif,/b.avif 2x' => ['/a.avif,/b.avif'],
				'data:image/png;base64,AAA= 2x, /b.avif' => ['data:image/png;base64,AAA=', '/b.avif'],
				'/a.avif 1.5x, /b.avif 1e1x, /c.avif 0x' => ['/a.avif', '/b.avif', '/c.avif'],
				'/a.avif 100w, /b.avif 100w 50h' => ['/a.avif', '/b.avif'],
				'/a.avif 1x 2x, /b.avif 0w, /c.avif 50h, /d.avif -1x, /e.avif 2X, /f.avif 100w 2x' => [],
				'/a.avif (x, y) 2x, /b.avif' => ['/b.avif'],
				'' => [],
				' , ,' => [],
			];
			foreach ($srcset as $value => $expected) {
				expect($expected, Homepage::srcset((string)$value), 'srcset ' . json_encode((string)$value));
			}
			if (Homepage::missingParser() !== null) {
				echo '  --   PHP ' . PHP_VERSION . " is below 8.4: the records of a page (Dom\\HTMLDocument) are not read here, they are on 8.4 and 8.5\n";
				contains('genba-smoke needs PHP 8.4 or later', (string)Homepage::missingParser(), 'below 8.4 the reader names the version it needs');
			} else {
				expect(null, Homepage::missingParser(), 'Dom\HTMLDocument is there from PHP 8.4 on');
				$page = '<!doctype html><html><head><meta charset="utf-8">'
					. '<meta name="build" content=""><meta name="Build" content="abc1234"><meta name="build" content="0123abc"><meta name="build" content="ffff">'
					. '<base href=" /sub/ "><base href="/other/">'
					. "<link rel=\"icon stylesheet\" href=\"\t/a.css\n\"><link rel=\"PRELOAD\" href=\"/f.woff2\"><link rel=\"stylesheets\" href=\"/no.css\"><link rel=\"stylesheet\">"
					. '<noscript><link rel="stylesheet" href="/ns.css"></noscript>'
					. '<template><img src="/t.webp"></template>'
					. '</head><body><svg><link rel="stylesheet" href="/svg.css"/><foreignObject><img src="/fo.webp"></foreignObject></svg>'
					. "<img src=\"/x&#233;\x1b[31m.webp\" srcset=\"/a.avif 1x, /b.avif 2x\"><iframe src=\"data:,x\"></iframe>"
					. '</body></html>';
				expect([
					['build', '0123abc'],
					['base', '/sub/'],
					['link', '/a.css'],
					['link', '/f.woff2'],
					['link', '/ns.css'],
					['src', '/fo.webp'],
					['src', "/x\u{e9}%1B[31m.webp"],
					['srcset', '/a.avif'],
					['srcset', '/b.avif'],
					['src', 'data:,x'],
				], Homepage::read($page, 'build'), 'the records of a page: first hex build meta (name as written), first base, rel tokens, '
					. 'trimmed and decoded addresses, control characters percent-encoded, noscript as markup, nothing from template or an svg <link>');
				// As the URL parser does — leading and trailing C0 and spaces stripped, tabs and line breaks dropped,
				// every other C0, DEL and C1 percent-encoded, not dropped (/a\x01b is /a%01b, not /ab).
				expect(
					[['link', '/assets/css/a%01b%7Fc%C2%85d.css'], ['src', '/x/yz.webp']],
					Homepage::read("<link rel=stylesheet href=\"\x01 /assets/css/a\x01b\x7fc\xc2\x85d.css\x1b \"><img src=\"/x/y\tz\n.webp\">", 'build'),
					'control characters in an address are encoded as the browser requests them'
				);
				// The base record stands where the <base> stands — the browser resolves a <link> before it against
				// the page's address, so genba-smoke must not resolve every address of the page against the base.
				expect(
					[['link', 'before.css'], ['base', '/sub/'], ['link', 'after.css'], ['src', 'x.webp']],
					Homepage::read('<link rel=stylesheet href=before.css><base href=/sub/><link rel=stylesheet href=after.css><base href=/other/><img src=x.webp>', 'build'),
					'the first <base> with an href is reported in its place, in document order'
				);
				// A declarative shadow root (<template shadowrootmode>) is rendered, and what it names is loaded — it
				// is no inert <template>. Only on a host attachShadow() accepts,
				// only the first of a host; a <base> or a build <meta> inside is not the document's.
				expect(
					[['build', 'abc1234'], ['base', '/b/'], ['link', '/s.css'], ['src', '/s.webp'], ['srcset', '/n.avif'], ['src', '/custom.webp'], ['src', '/after.webp']],
					Homepage::read('<!doctype html><head><meta name=build content=abc1234><base href=/b/></head><body>'
						. '<div><template shadowrootmode="OPEN"><link rel=stylesheet href=/s.css><img src=/s.webp><base href=/x/><meta name=build content=ffff>'
						. '<span><template shadowrootmode=closed><img srcset="/n.avif 1x"></template></span><template><img src=/inert.webp></template></template>'
						. '<template shadowrootmode=open><img src=/second.webp></template></div>'
						. '<a><template shadowrootmode=open><img src=/a-host.webp></template></a>'
						. '<x-card><template shadowrootmode=open><img src=/custom.webp></template></x-card>'
						. '<template><img src=/t.webp></template><template shadowrootmode=none><img src=/none.webp></template><img src=/after.webp>', 'build'),
					'a declarative shadow root is read (open, closed, nested, a custom element), an inert template, a second root of one host, an <a> host and mode none are not'
				);
				// The hosts of a declarative shadow root, as a table (SPEC §3.4, "Reading the
				// homepage"): the elements attachShadow() accepts (DOM, "attach a shadow root") and every valid custom
				// element name (HTML) — not the eight reserved names with a hyphen, on which attachShadow() throws and
				// the browser leaves the <template> inert (Chromium: host.shadowRoot === null). Written out here, not
				// taken from the class, so a changed list in src/Homepage.php shows.
				$hosts = [
					'article' => true, 'aside' => true, 'blockquote' => true, 'body' => true, 'div' => true, 'footer' => true,
					'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'header' => true,
					'main' => true, 'nav' => true, 'p' => true, 'section' => true, 'span' => true,
					'x-card' => true, 'my-element2' => true, 'font-face-x' => true, 'x-font-face' => true, 'missing-glyphs' => true,
					'annotation-xml' => false, 'color-profile' => false, 'font-face' => false, 'font-face-src' => false,
					'font-face-uri' => false, 'font-face-format' => false, 'font-face-name' => false, 'missing-glyph' => false,
					'a' => false, 'table' => false, 'ul' => false, 'button' => false, 'template' => false, 'xcard' => false,
				];
				foreach ($hosts as $host => $root) {
					$inner = '<template shadowrootmode=open><img src=/in.webp></template>';
					$hostPage = '<!doctype html><body>' . ($host === 'body' ? $inner : "<$host>$inner</$host>") . '<img src=/after.webp>';
					expect(
						$root ? [['src', '/in.webp'], ['src', '/after.webp']] : [['src', '/after.webp']],
						Homepage::read($hostPage, 'build'),
						"<$host> " . ($root ? 'is a shadow host: its declarative shadow root is read' : 'is no shadow host: its <template shadowrootmode> stays inert')
					);
				}
				$tmp = genba_tmp('homepage');
				try {
					genba_write("$tmp/page.html", $page);
					[$code, $output] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-smoke-read', '--meta=build', "$tmp/page.html"], $tmp);
					expect(0, $code, 'bin/genba-smoke-read reads a page: exit 0');
					holds(str_starts_with($output, "build\t0123abc\nbase\t/sub/\nlink\t/a.css\n"), "one record per line, kind TAB value:\n$output");
					// A 4.5 MB homepage with 80 000 tags under PHP's default memory_limit of 128 MB: Dom\HTMLDocument
					// needs about 30 times the page, the reader raises the limit for it (it ended in a fatal error before).
					$big = '<!doctype html><html><head><meta name="build" content="0123abc"><link rel="stylesheet" href="/a.css"></head><body>'
						. str_repeat("<span class=\"c\" title=\"t00000 &amp; tttttttt\">yy</span>\n", 80000) . '<img src="/z.webp"></body></html>';
					genba_write("$tmp/big.html", $big);
					[$code, $output] = genba_run([genba_php(), '-d', 'memory_limit=128M', dirname(__DIR__) . '/bin/genba-smoke-read', '--meta=build', "$tmp/big.html"], $tmp);
					expect([0, "build\t0123abc\nlink\t/a.css\nsrc\t/z.webp\n"], [$code, $output], sprintf('a page of %.1f MB with 80 000 tags is read under memory_limit=128M', strlen($big) / 1048576));
					[$code, $output] = genba_run([genba_php(), dirname(__DIR__) . '/bin/genba-smoke-read', '--check'], $tmp);
					expect([0, ''], [$code, $output], 'bin/genba-smoke-read --check on PHP 8.4+: exit 0, silent');
				} finally {
					genba_remove_tree($tmp);
				}
			}
		} finally {
			ob_end_clean();
		}
	}
}
