<?php

namespace Sayamaapps\Genba;

use Dom\HTMLDocument;
use SplObjectStorage;

require_once __DIR__ . '/Text.php';

/**
 * The homepage reader of genba-smoke (SPEC §3.4 check 1 and 5): curl and bash fetch the
 * page, this class reads it — with PHP's HTML5 parser, Dom\HTMLDocument (Lexbor, PHP 8.4 and
 * later), which follows the tokenizer and tree construction of the HTML specification. A reader
 * built of awk and grep keeps meeting spellings it gets wrong where the browser does not (a quote
 * in an unquoted value, RCDATA in <title>, an attribute in an end tag, a bogus comment …).
 *
 * Only Dom\HTMLDocument: masterminds/html5, the parser for PHP 8.2 and 8.3, read
 * one corpus variant and 18 of 57 tokenizer probes otherwise than Lexbor (`<!--->` swallowed the page up to
 * the next -->, `</style >` and `</script/>` did not end the element, the script escape states, a <
 * in an attribute name, references without ;, HTML inside <svg>/<math>, <select>, <frameset>,
 * <plaintext>, a byte that is no UTF-8), and libxml's DOMDocument is no HTML5 parser at all. Correcting masterminds meant a
 * tokenizer of our own again. So genba-smoke needs PHP 8.4 on the machine that runs it (the CI
 * runner, not the web server); the plugin and the other four tools stay at PHP 8.2.
 *
 * What the browser does, and the reader with it (Lexbor does the first five):
 *   - comments, <script> (with its escape states), <style>, <title>, <textarea>, <xmp>, <iframe>,
 *     <noembed>, <noframes>, <plaintext>, end tags with attributes, bogus comments (<!x …>,
 *     <?php … ?>) are what the tokenizer makes of them;
 *   - the content of <template> is inert: nothing in it is read — unless the template is a
 *     declarative shadow root (<template shadowrootmode="open">), whose content the
 *     browser renders: that is read, parsed again from the template's markup;
 *   - <noscript> is read as markup, as a browser WITHOUT script reads it (Dom\HTMLDocument parses
 *     with scripting off): a stylesheet or an image there is checked too — the louder direction. A
 *     browser with script loads none of it;
 *   - only elements in the HTML namespace count: a <link> inside <svg> or <math> is no stylesheet,
 *     an <img> that breaks out of <svg> or stands in <foreignObject> is an image;
 *   - character references are decoded, every one of them, as the browser decodes them;
 *   - rel is a list of tokens, compared without regard to ASCII case (rel=" Stylesheet preload");
 *   - a URL is stripped of leading and trailing ASCII whitespace and control characters, its tabs
 *     and line breaks dropped, every other control character percent-encoded (the URL parser
 *     does so), and only srcset is split, by the
 *     specification's rules ("parse a srcset attribute"), a candidate with invalid descriptors
 *     dropped — a src="data:,x.webp" is one address;
 *   - the first <base> with an href is reported (`base`) where it stands, genba-smoke resolves
 *     the addresses after it against it — those before it against the page's own address, as the
 *     browser fetched them before the base was there.
 * The page is read as UTF-8, whatever it declares (Kirby writes UTF-8): a byte that is no UTF-8
 * becomes U+FFFD.
 *
 * Output, one record per line, the kind, a tab, the value:
 *   build   the content of the first <meta name="<meta>"> whose content is hex digits only
 *           (an empty content counts as absent); at most one line
 *   base    the href of the first <base> that has one; at most one line, in its place
 *   link    the href of a <link> whose rel holds the token stylesheet or preload
 *   src     the src of any element
 *   srcset  each candidate address of a srcset
 * build first, the others in the order of the elements. Every value is log text: control characters
 * (C0, DEL, C1) are percent-encoded as the browser's URL parser encodes them (url()), so a value
 * never holds a tab, a line break or an escape sequence. Kirby-free; this file loads on PHP 8.2 (it says why it cannot run there).
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-genba
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Homepage
{
	private const HTML_NS = 'http://www.w3.org/1999/xhtml';

	/** The PHP version the reader needs: Dom\HTMLDocument. */
	public const PHP_MIN = '8.4.0';

	/**
	 * Names the pattern of a custom element matches that are no valid custom element name (HTML,
	 * "valid custom element name": SVG and MathML elements with a hyphen). attachShadow() throws on
	 * them, and the browser leaves their <template shadowrootmode> inert.
	 */
	private const RESERVED_CUSTOM = ['annotation-xml', 'color-profile', 'font-face', 'font-face-src', 'font-face-uri', 'font-face-format', 'font-face-name', 'missing-glyph'];

	/** The elements attachShadow() accepts as a host (besides a custom element). */
	private const SHADOW_HOSTS = ['article', 'aside', 'blockquote', 'body', 'div', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'main', 'nav', 'p', 'section', 'span'];

	/** ASCII whitespace of the HTML and URL specifications. */
	private const SPACE = "\t\n\f\r ";

	/** The content of the <template> $template, parsed again from its markup (Lexbor gives no access to it). */
	private static function content(object $template): object
	{
		$document = HTMLDocument::createEmpty();
		$host = $document->createElement('div');
		$document->appendChild($host);
		$host->innerHTML = $template->innerHTML;
		return $host;
	}

	/**
	 * The descriptor parser of "parse a srcset attribute": at most one of w and x, h only with w,
	 * each once; w and h a positive integer, x a non-negative floating-point number.
	 *
	 * @param list<string> $descriptors
	 */
	private static function descriptorsValid(array $descriptors): bool
	{
		$width = $height = $density = false;
		foreach ($descriptors as $descriptor) {
			$number = substr($descriptor, 0, -1);
			switch (substr($descriptor, -1)) {
				case 'w':
					if ($width || $density || preg_match('/^[0-9]+$/D', $number) !== 1 || (int)$number === 0) {
						return false;
					}
					$width = true;
					break;
				case 'x':
					if ($width || $density || $height ||
						preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][-+]?[0-9]+)?$/D', $number) !== 1 || (float)$number < 0) {
						return false;
					}
					$density = true;
					break;
				case 'h':
					if ($height || $density || preg_match('/^[0-9]+$/D', $number) !== 1 || (int)$number === 0) {
						return false;
					}
					$height = true;
					break;
				default:
					return false;
			}
		}
		return !$height || $width;
	}

	/**
	 * The records of one HTML element.
	 *
	 * @param list<array{0: string, 1: string}> $records
	 */
	private static function element(object $element, string $name, string $meta, string|null &$build, string|null &$base, array &$records, bool $shadow): void
	{
		if (!$shadow && $name === 'meta' && $build === null && $element->getAttribute('name') === $meta && $element->hasAttribute('content')) {
			// As written: the snippet prints exactly the hash (Stamp::meta()).
			$content = $element->getAttribute('content');
			if ($content !== '' && preg_match('/^[0-9a-f]+$/D', $content) === 1) {
				$build = $content;
			}
		}
		if (!$shadow && $name === 'base' && $base === null && $element->hasAttribute('href')) {
			// In document order: the browser resolves what stands before the
			// <base> against the document's address — it fetched it before the base was there.
			$base = self::url($element->getAttribute('href'));
			$records[] = ['base', $base];
		}
		if ($name === 'link' && $element->hasAttribute('href')) {
			$tokens = preg_split('/[\t\n\f\r ]+/', strtolower($element->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if (in_array('stylesheet', $tokens, true) || in_array('preload', $tokens, true)) {
				$records[] = ['link', self::url($element->getAttribute('href'))];
			}
		}
		if ($element->hasAttribute('src')) {
			$records[] = ['src', self::url($element->getAttribute('src'))];
		}
		if ($element->hasAttribute('srcset')) {
			foreach (self::srcset($element->getAttribute('srcset')) as $url) {
				$records[] = ['srcset', self::url($url)];
			}
		}
	}

	/* --------------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------------- */

	/** Raises memory_limit to 128 MB plus 40 times $bytes, unless it is unlimited or higher already. */
	private static function memoryFor(int $bytes): void
	{
		$need = 134217728 + 40 * $bytes;
		$limit = trim((string)ini_get('memory_limit'));
		if ($limit === '-1') {
			return;
		}
		$value = (int)$limit;
		$unit = strtolower(substr($limit, -1));
		$value *= match ($unit) {
			'g' => 1073741824,
			'm' => 1048576,
			'k' => 1024,
			default => 1,
		};
		if ($value < $need) {
			// Declared in legacy.php, which the reader and index.php load before this call.
			/** @psalm-suppress UndefinedFunction */
			\Genba\raise_memory_limit((string)$need);
		}
	}

	/**
	 * Null when Dom\HTMLDocument is there, else why genba-smoke cannot read the homepage.
	 */
	public static function missingParser(): string|null
	{
		/** @psalm-suppress RedundantCondition Psalm analyses the PHP 8.2 platform, where the branches below are the ones that run. */
		if (PHP_VERSION_ID >= 80400 && class_exists('Dom\HTMLDocument')) {
			return null;
		}
		if (PHP_VERSION_ID >= 80400) {
			return 'PHP extension dom is missing (genba-smoke reads the homepage with Dom\HTMLDocument)';
		}
		return 'genba-smoke needs PHP ' . substr(self::PHP_MIN, 0, 3) . ' or later on the machine that runs it '
			. '(it reads the homepage with Dom\HTMLDocument), this is PHP ' . PHP_VERSION
			. ' — usually the CI runner, not the web server: setup-php with php-version: \'8.4\'';
	}

	/**
	 * The records of the page $html (see the class comment): list of [kind, value]. Needs PHP 8.4
	 * (missingParser() === null).
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function read(string $html, string $meta): array
	{
		// UTF-8 whatever the page declares; Lexbor makes every byte that is no UTF-8 a U+FFFD.
		$document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
		return self::records($document, $meta);
	}

	/**
	 * The records of a parsed \Dom\HTMLDocument.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function records(object $document, string $meta): array
	{
		$records = [];
		$build = null;
		$base = null;
		self::walk($document, $meta, $build, $base, $records, false);
		if ($build !== null) {
			array_unshift($records, ['build', $build]);
		}
		return $records;
	}

	/**
	 * CLI of the reader (bin/genba-smoke-read, called by genba-smoke only):
	 *   genba-smoke-read --check                 exit 0 when it can read, else 2 with the reason
	 *   genba-smoke-read --meta=NAME FILE        the records of FILE on $out, exit 0
	 * Exit 2 (cannot run) and 1 (the file cannot be read): the reason as one line on $err —
	 * genba-smoke prints it as its own `::error::` line, with its last line.
	 *
	 * @param list<string> $argv
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $argv, $out, $err): int
	{
		$check = false;
		$meta = null;
		$files = [];
		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === '--check') {
				$check = true;
			} elseif (preg_match('/^--meta=(.+)$/s', $arg, $m) === 1) {
				$meta = $m[1];
			} elseif (!str_starts_with($arg, '-') && $arg !== '') {
				$files[] = $arg;
			} else {
				fwrite($err, "genba-smoke-read: unknown argument\n");
				return 2;
			}
		}
		if (!$check && ($meta === null || count($files) !== 1)) {
			fwrite($err, "genba-smoke-read: --meta=NAME and one file are required\n");
			return 2;
		}
		$missing = Text::missingMbstring();
		if ($missing !== null) {
			fwrite($err, "$missing\n");
			return 2;
		}
		$parser = self::missingParser();
		if ($parser !== null) {
			fwrite($err, "$parser\n");
			return 2;
		}
		if ($check) {
			return 0;
		}
		$html = @file_get_contents($files[0]);
		if ($html === false) {
			fwrite($err, "genba-smoke-read: cannot read the saved homepage\n");
			return 1;
		}
		// Dom\HTMLDocument takes about 30 times the page in memory (measured: 4.5 MB → 135 MB peak, 9 MB →
		// 269 MB); PHP's default limit of 128 MB ended a 4.5 MB homepage with a fatal error. Raised for this
		// page, never lowered.
		self::memoryFor(strlen($html));
		foreach (self::read($html, $meta) as [$kind, $value]) {
			fwrite($out, $kind . "\t" . $value . "\n");
		}
		return 0;
	}

	/**
	 * True when the <template> $template is a declarative shadow root, as the HTML parser makes one
	 * ("in body", a template start tag): a shadowrootmode of open or closed (ASCII case-insensitive),
	 * on a host that may carry a shadow root (attachShadow(): article, aside, blockquote, body, div,
	 * footer, h1–h6, header, main, nav, p, section, span, a valid custom element name — not one of
	 * RESERVED_CUSTOM), and the first such template of that host — otherwise the template stays
	 * inert, as in the browser.
	 */
	private static function shadowRoot(object $template, SplObjectStorage $hosts): bool
	{
		$mode = strtolower($template->getAttribute('shadowrootmode') ?? '');
		$host = $template->parentNode;
		if (($mode !== 'open' && $mode !== 'closed') || $host === null || $host->nodeType !== XML_ELEMENT_NODE || $host->namespaceURI !== self::HTML_NS) {
			return false;
		}
		$name = strtolower($host->localName ?? '');
		if (!in_array($name, self::SHADOW_HOSTS, true) &&
			(preg_match('/^[a-z][^A-Z\t\n\f\r \/>]*-/D', $name) !== 1 || in_array($name, self::RESERVED_CUSTOM, true))) {
			return false;
		}
		if (isset($hosts[$host])) {
			return false;
		}
		$hosts[$host] = true;
		return true;
	}

	/**
	 * The candidate addresses of a srcset value, by "parse a srcset attribute" of the HTML
	 * specification: candidates separated by commas, an address runs to whitespace, a comma at its
	 * end is no part of it, a comma inside parentheses of a descriptor separates nothing, and a
	 * candidate whose descriptors are invalid (two densities, 0w, 2y …) is dropped.
	 *
	 * @return list<string>
	 */
	public static function srcset(string $value): array
	{
		$urls = [];
		$length = strlen($value);
		$at = 0;
		while (true) {
			$at += strspn($value, self::SPACE . ',', $at);
			if ($at >= $length) {
				return $urls;
			}
			$size = strcspn($value, self::SPACE, $at);
			$url = substr($value, $at, $size);
			$at += $size;
			$descriptors = [];
			if (str_ends_with($url, ',')) {
				$url = rtrim($url, ',');
			} else {
				$at += strspn($value, self::SPACE, $at);
				$current = '';
				$state = 'in';
				while (true) {
					$c = $at < $length ? $value[$at] : null;
					if ($state === 'in') {
						if ($c === null || $c === ',') {
							if ($c !== null) {
								$at++;
							}
							if ($current !== '') {
								$descriptors[] = $current;
							}
							break;
						}
						if (str_contains(self::SPACE, $c)) {
							if ($current !== '') {
								$descriptors[] = $current;
								$current = '';
							}
							$state = 'after';
						} else {
							$current .= $c;
							if ($c === '(') {
								$state = 'parens';
							}
						}
					} elseif ($state === 'parens') {
						if ($c === null) {
							$descriptors[] = $current;
							break;
						}
						$current .= $c;
						if ($c === ')') {
							$state = 'in';
						}
					} else {
						if ($c === null) {
							break;
						}
						if (!str_contains(self::SPACE, $c)) {
							// Reconsumed in the descriptor state (a comma ends the candidate there).
							$state = 'in';
							continue;
						}
					}
					$at++;
				}
			}
			if (self::descriptorsValid($descriptors)) {
				$urls[] = $url;
			}
		}
	}

	/**
	 * An attribute value as the URL parser takes it, as log text: leading and trailing C0 control
	 * characters and spaces stripped, tabs and line breaks dropped, every other control character
	 * (C0, DEL, C1) percent-encoded as UTF-8 — `\x01` is `%01`, U+0085 `%C2%85` (dropping them
	 * would make genba-smoke ask for /a.css where the browser asks for /a%01.css). Encoded, the
	 * value holds no control character for the log either.
	 */
	private static function url(string $value): string
	{
		$value = str_replace(["\t", "\n", "\r"], '', trim($value, "\x00..\x20"));
		return (string)preg_replace_callback(
			'/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/',
			static fn (array $m): string => strtoupper(implode('', array_map(static fn (string $byte): string => '%' . bin2hex($byte), str_split($m[0])))),
			$value
		);
	}

	/**
	 * The records of the nodes under $root, in document order, appended to $records. Without
	 * recursion (a page of 80 000 nested elements must not exhaust the stack): down to the first
	 * child, else on to the next sibling, else up and on. The content of a <template> is not
	 * entered — unless it is a declarative shadow root (shadowRoot()): the
	 * browser renders that content and loads what it names; Lexbor keeps it as template content
	 * (no shadow DOM), so it is parsed again from the template's markup and walked as $shadow, where
	 * a <base> and the build <meta> do not count (they are not in the document's tree).
	 *
	 * @param list<array{0: string, 1: string}> $records
	 */
	private static function walk(object $root, string $meta, string|null &$build, string|null &$base, array &$records, bool $shadow): void
	{
		$hosts = new SplObjectStorage();
		$node = $root->firstChild;
		while ($node !== null) {
			$enter = true;
			if ($node->nodeType === XML_ELEMENT_NODE) {
				if ($node->namespaceURI === self::HTML_NS) {
					$name = strtolower($node->localName ?? '');
					if ($name === 'template') {
						$enter = false;
						if (self::shadowRoot($node, $hosts)) {
							self::walk(self::content($node), $meta, $build, $base, $records, true);
						}
					} else {
						self::element($node, $name, $meta, $build, $base, $records, $shadow);
					}
				}
			}
			if ($enter && $node->firstChild !== null) {
				$node = $node->firstChild;
				continue;
			}
			while ($node !== null && $node->nextSibling === null) {
				$node = $node->parentNode;
				if ($node === $root) {
					$node = null;
				}
			}
			$node = $node?->nextSibling;
		}
	}
}
