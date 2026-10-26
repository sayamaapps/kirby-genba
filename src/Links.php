<?php

namespace Sayamaapps\Genba;

require_once __DIR__ . '/Tree.php';

/**
 * Checks external references against their source: external references in the
 * content are checked against the source, never from memory.
 *
 * Background: five of nine product pages of one shop carried a wrong
 * Digistore ID. Two opened ANOTHER own album — the purchase went through and
 * the buyer got the wrong one —, one the product of a foreign provider, two an
 * empty or 404 page. A plain status-code check would have let four of the five
 * through: the pages answer 200. So the check looks at WHERE a reference leads:
 *
 *   YouTube     watch, youtu.be, embed, shorts links, thumbnails and ID fields
 *               (Videoid: …). Through the oEmbed endpoint: 400/404 means
 *               deleted, 403 private (both measured against the
 *               playabilityStatus of the watch page). 401 means embedding is
 *               blocked — a finding only for embeds, a notice for plain links.
 *   Digistore   /product/<id>, /redir/<id>/… and ID fields (Digistoreid: …).
 *               The product page must show an active product (title
 *               "<Name> - Digistore24"). For an ID field the product name must
 *               also match the page title, in any of its languages: every
 *               word of the shorter name must occur in the longer one
 *               ("Licht & Frieden" matches "Sayama Solfeggio Frequenzen -
 *               Licht und Frieden", "Atlaschakra" does not match "Klang Chakra
 *               Aktivierung ~ ThymusChakra").
 *   Purchase    Digistore redirects, CopeCart, ablefy/elopage, Gumroad,
 *               PayPal and own shop hosts (--shop-host=…): the redirect chain
 *               must end with 2xx, and every parameter of the starting URL
 *               (aff=, cam=, …) must still be there at the target.
 *
 * Read: content/, site/ and public/ (texts, templates, blueprints,
 * llms-full.txt). Placeholders in templates ({$id}, <?= … ?>) are skipped.
 *
 * Exit codes (see bin/genba-links): 0 when nothing is wrong, also when there
 * are only disruptions (timeouts, 5xx, 429 after three attempts, 403 of a shop
 * as a possible bot block); 1 for findings or a failed counter-check; 2 when
 * the check cannot run. Disruptions are printed as ::warning:: and do not
 * fail the run: a flaky network must not create a red run nobody reads.
 *
 * Redirects are followed by hand, never by curl, and never through a proxy:
 * every URL is rebuilt from validated parts before curl sees it, a hop to
 * another host is resolved once, and a target with any address on loopback, a
 * private or link-local network, CGNAT, ULA, multicast (also IPv4 inside
 * IPv6) or a local name (.local, .lan, .internal, localhost) is refused as a
 * finding — a vendor-controlled Location must not make the CI runner probe
 * its own network. The checked address is pinned for the request
 * (CURLOPT_RESOLVE) and compared with the one curl connected to.
 *
 * Text that comes from outside (page titles, oEmbed titles, Location headers)
 * is printed with control characters replaced, so it cannot forge a GitHub
 * annotation line.
 *
 * Requires ext-curl. Kirby-free.
 */
final class Links
{
	public const DEFAULT_DIGISTORE_BASE = 'https://www.digistore24.com/product/';
	public const DEFAULT_DIGISTORE_FIELDS = ['digistoreid'];
	public const DEFAULT_OEMBED_BASE = 'https://www.youtube.com/oembed';
	public const DEFAULT_YOUTUBE_FIELDS = ['videoid', 'youtubeid', 'youtubevideos'];
	public const DISRUPTION = 'disruption';
	private const EXTENSIONS = ['txt', 'md', 'php', 'yml', 'yaml', 'html', 'json', 'xml'];
	public const FINDING = 'finding';
	public const KIND_DIGISTORE = 'digistore';
	public const KIND_PURCHASE = 'purchase';
	public const KIND_YOUTUBE = 'youtube';
	public const KIND_YOUTUBE_EMBED = 'youtube-embed';

	/** Host names that only mean something inside a network. */
	private const LOCAL_SUFFIXES = ['localhost', 'local', 'lan', 'internal'];
	private const MAX_REDIRECTS = 10;
	public const NOTICE = 'notice';
	public const OK = 'ok';

	/**
	 * Address ranges a redirect must not lead to: [network, prefix length].
	 * filter_var()'s private/reserved flags are applied on top; this table
	 * holds what they miss in some PHP versions (CGNAT, benchmark, multicast).
	 */
	private const PRIVATE_RANGES = [
		['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
		['172.16.0.0', 12], ['192.0.0.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4],
		['::', 128], ['::1', 128], ['fe80::', 10], ['fec0::', 10], ['fc00::', 7], ['ff00::', 8], ['64:ff9b:1::', 48], ['2001::', 32],
		// SIIT IPv4-translated ::ffff:0:a.b.c.d (RFC 2765); IPv4-mapped ::ffff:a.b.c.d is judged by its IPv4 part.
		['::ffff:0:0:0', 96],
	];
	private const PURCHASE_HOSTS = [
		'copecart.com', 'ablefy.io', 'elopage.com', 'gumroad.com', 'paypal.me', 'paypal.com',
	];

	/** Directory names not read at any depth: never site content. */
	private const SKIP_ANYWHERE = ['node_modules' => 'npm dependencies', '.git' => 'version control'];

	/**
	 * Directories not read, relative to the project root, with the reason the log prints. By place, not
	 * by name: a page folder content/media/ or content/blog/kirby/ is content and IS read.
	 */
	private const SKIP_PATHS = [
		'vendor' => 'Composer dependencies', 'kirby' => 'Kirby itself', 'storage' => 'Kirby storage',
		'site/cache' => 'Kirby cache', 'site/sessions' => 'Kirby sessions', 'site/accounts' => 'Panel accounts',
		'site/logs' => 'logs', 'public/media' => 'Kirby media folder, generated',
	];
	public const USER_AGENT = 'Mozilla/5.0 (compatible; Genba link check; +https://github.com/sayamaapps/kirby-genba)';

	/** @var array<string, array> Result per target, so each reference is fetched only once */
	private array $cache = [];

	/** @var array<string, float> Last request time per host (politeness pause) */
	private array $lastRequest = [];

	/** @var array<string, bool> Real paths of the files the current find() has listed: one read per run, not per path */
	private array $readTargets = [];

	/** @var list<array{dir: string, reason: string}> Directories the last find() did not read */
	private array $skipped = [];

	/**
	 * @param list<string> $digistoreFields ID fields for Digistore
	 * @param list<string> $youtubeFields ID fields for YouTube
	 * @param list<string> $shopHosts further hosts whose links count as purchase links
	 * @param $allowPrivate follow redirects into private and local networks too
	 *                     (tests with servers on 127.0.0.1 only; never in a site)
	 * @param $resolver host => list<string> addresses, instead of the system
	 *                 resolver (tests only: simulates DNS)
	 */
	public function __construct(
		private array $digistoreFields = self::DEFAULT_DIGISTORE_FIELDS,
		private array $youtubeFields = self::DEFAULT_YOUTUBE_FIELDS,
		private array $shopHosts = [],
		private string $oembedBase = self::DEFAULT_OEMBED_BASE,
		private string $digistoreBase = self::DEFAULT_DIGISTORE_BASE,
		private int $retries = 3,
		private float $pause = 0.3,
		private bool $allowPrivate = false,
		private \Closure|null $resolver = null,
	) {
	}

	/** A Location value resolved against the URL it came from (RFC 3986, without dot-segment removal). */
	private static function absolute(string $base, string $location): string
	{
		$location = trim(explode('#', $location, 2)[0]);
		if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
			return $location;
		}
		$parts = parse_url($base) ?: [];
		$scheme = $parts['scheme'] ?? 'https';
		if (str_starts_with($location, '//')) {
			return $scheme . ':' . $location;
		}
		$authority = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$path = $parts['path'] ?? '/';
		if ($location === '') {
			return $authority . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
		}
		if ($location[0] === '/') {
			return $authority . $location;
		}
		if ($location[0] === '?') {
			return $authority . $path . $location;
		}
		return $authority . substr($path, 0, (int)strrpos($path, '/') + 1) . $location;
	}

	/**
	 * Cannot run (exit 2): the reason on $err (SPEC §3.5), the usage after it when
	 * asked, and on $out the last line every tool ends with (SPEC §3.0) — so a log
	 * that shows stdout alone still says that nothing was checked.
	 *
	 * @param resource $out
	 * @param resource $err
	 */
	private static function cannotRun($out, $err, string $reason, bool $usage = false): int
	{
		fwrite($err, $reason . "\n" . ($usage ? self::usage() : ''));
		fwrite($out, "Genba links: FAILED — cannot run\n");
		return 2;
	}

	// ── Checking ────────────────────────────────────────────────────────

	/** @return array{status: string, text: string} */
	public function check(array $ref): array
	{
		$key = self::targetKey($ref);
		return $this->cache[$key] ??= match ($ref['kind']) {
			self::KIND_YOUTUBE, self::KIND_YOUTUBE_EMBED => $this->youtube($ref['key'], $ref['kind'] === self::KIND_YOUTUBE_EMBED),
			self::KIND_DIGISTORE => $this->digistore($ref['key'], $ref['expected']),
			self::KIND_PURCHASE => $this->purchaseLink($ref['url'], $ref['product'] ?? null),
		};
	}

	/** @return array{kind: string, key: string, url: string, expected: list<string>, place: string, product: ?string}|null */
	public function classify(string $url): array|null
	{
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host'])) {
			return null;
		}
		$host = strtolower($parts['host']);
		$path = $parts['path'] ?? '/';
		parse_str($parts['query'] ?? '', $query);
		$id = '[A-Za-z0-9_-]{11}';

		if (preg_match('/(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be|ytimg\.com)$/', $host)) {
			$video = null;
			$embed = false;
			if ($host === 'youtu.be' && preg_match("~^/($id)(/|$)~", $path, $t)) {
				$video = $t[1];
			} elseif ($path === '/watch' && is_string($query['v'] ?? null) && preg_match("/^$id$/", $query['v'])) {
				$video = $query['v'];
			} elseif (preg_match("~^/(embed|shorts|live|v)/($id)(/|$)~", $path, $t) && $t[2] !== 'videoseries') {
				// /embed/videoseries?list=… is a playlist: the word has 11 letters, like an ID.
				$video = $t[2];
				$embed = $t[1] === 'embed' || $t[1] === 'v';
			} elseif (preg_match("~^/vi(_webp)?/($id)/~", $path, $t)) {
				$video = $t[2]; // thumbnail
			}
			return $video === null ? null
				: self::reference($embed ? self::KIND_YOUTUBE_EMBED : self::KIND_YOUTUBE, $video, $url);
		}

		$isDigistore = preg_match('/(^|\.)(digistore24\.com|checkout-ds24\.com)$/', $host) === 1;
		if ($isDigistore && preg_match('~^/product/(\d+)(/|$)~', $path, $t)) {
			return self::reference(self::KIND_DIGISTORE, $t[1], $url);
		}
		if ($isDigistore && preg_match('~^/redir/(\d+)(/|$)~', $path, $t)) {
			// Both: the product must be alive, and the chain must arrive.
			return self::reference(self::KIND_PURCHASE, $url, $url, [], $t[1]);
		}
		if ($isDigistore) {
			return null; // privacy page, seals, scripts: not a purchase reference
		}

		foreach (array_merge(self::PURCHASE_HOSTS, $this->shopHosts) as $shop) {
			if ($host === $shop || str_ends_with($host, '.' . $shop)) {
				return self::reference(self::KIND_PURCHASE, $url, $url);
			}
		}
		return null;
	}

	/**
	 * External text made safe for a log line: control characters (C0, DEL, C1)
	 * become spaces, so a title like "x&#10;::error file=…" cannot start a line
	 * that GitHub reads as an annotation. Invalid UTF-8 is scrubbed first; above
	 * $max characters the text is cut to its first 60 with its length.
	 */
	public static function clean(string $text, int $max = Text::MAX): string
	{
		// Shared with Precheck and Tree (src/Text.php); kept here as the name the tests use.
		return Text::clean($text, $max);
	}

	// ── Counter-check ───────────────────────────────────────────────────

	/**
	 * Proves before the run that the tool can see: a deleted video and a
	 * missing product MUST be findings, an existing video MUST be ok. If
	 * YouTube or Digistore change their behaviour, this shows up here instead
	 * of everything staying quietly green.
	 *
	 * @return list<string> failures; empty means passed
	 */
	public function counterCheck(string $goodVideo = 'jNQXAC9IVRw', string $missingVideo = 'aaaaaaaaaaa', string $missingProduct = '0'): array
	{
		$failures = [];
		$r = $this->youtube($goodVideo);
		if ($r['status'] !== self::OK) {
			$failures[] = "Existing video $goodVideo not recognised: {$r['text']}";
		}
		$r = $this->youtube($missingVideo);
		if ($r['status'] !== self::FINDING) {
			$failures[] = "Missing video $missingVideo not recognised as a finding: {$r['status']}, {$r['text']}";
		}
		$r = $this->digistore($missingProduct);
		if ($r['status'] !== self::FINDING) {
			$failures[] = "Missing Digistore product $missingProduct not recognised as a finding: {$r['status']}, {$r['text']}";
		}
		return $failures;
	}

	/** @param array{code: int, error: string} $a */
	private function describe(array $a): string
	{
		return $a['code'] === 0 ? 'no response (' . self::clean($a['error']) . ')' : 'HTTP ' . $a['code'];
	}

	/**
	 * @param list<string> $expected page titles the product name must match
	 * @return array{status: string, text: string}
	 */
	public function digistore(string $id, array $expected = []): array
	{
		$a = $this->withRetry($this->digistoreBase . $id, true, '</title>');
		// Digits only, but any number of them: cut like every piece of a result text.
		$shown = self::clean($id);
		if ($a['refused'] !== null) {
			return ['status' => self::FINDING, 'text' => "Digistore product $shown: {$a['refused']}"];
		}
		if (in_array($a['code'], [404, 410], true)) {
			return ['status' => self::FINDING, 'text' => "Digistore product $shown does not exist (HTTP {$a['code']})"];
		}
		if ($a['code'] !== 200) {
			return ['status' => self::DISRUPTION, 'text' => "Digistore product $shown: " . $this->describe($a)];
		}
		preg_match('~<title>\s*(.*?)\s*</title>~si', $a['body'], $t);
		// Decoded entities can carry a line break (&#10;) — cleaned before anything prints it.
		// Not cut here: the name is compared in full; what is printed is cut.
		$title = self::clean(html_entity_decode($t[1] ?? '', ENT_QUOTES | ENT_HTML5), PHP_INT_MAX);
		if (!preg_match('/^(.+?)\s+-\s+Digistore24$/u', $title, $n)) {
			return ['status' => self::FINDING, 'text' => "Digistore product $shown shows no active product (page title \"" . self::clean($title) . '")'];
		}
		$name = trim($n[1]);
		if ($expected !== [] && !self::nameMatches($name, $expected)) {
			return ['status' => self::FINDING,
				'text' => "Digistore product $shown is called \"" . self::clean($name) . '", but the page is "'
					. implode('" / "', array_map(static fn (string $e): string => self::clean($e), $expected)) . '" — wrong ID?'];
		}
		return ['status' => self::OK, 'text' => '"' . self::clean($name) . '"'];
	}

	/**
	 * GET $url. With $follow, redirects are followed here, hop by hop (at most
	 * MAX_REDIRECTS): curl itself never follows one, and never uses a proxy
	 * from the environment.
	 *
	 * Every hop, the first included, goes through requestUrl(): curl gets a
	 * URL rebuilt from the parsed and validated parts, never the original
	 * string, so parse_url() and curl cannot disagree about the host. Each new
	 * scheme/host/port is resolved ONCE; that exact address is pinned with
	 * CURLOPT_RESOLVE and compared with the address curl really connected to.
	 * The first URL comes from the options (oEmbed, Digistore base) and is
	 * trusted, unless $checkFirst (a purchase link from the content whose host
	 * is not exactly a configured shop host); every hop to another host:port
	 * is refused when any of its addresses is private or local. A refused hop
	 * ends the chain with `refused` set (a finding for the caller), without
	 * contacting the target.
	 *
	 * @return array{code: int, body: string, final: string, chain: list<string>, error: string, refused: ?string}
	 */
	public function fetch(string $url, bool $follow, string|null $untilText = null, bool $checkFirst = false): array
	{
		$chain = [];
		$pins = [];
		$current = $url;
		for ($hop = 0;; $hop++) {
			$target = self::requestUrl($current);
			if (isset($target['refused'])) {
				$why = $hop === 0 ? "URL refused ({$target['refused']})" : 'redirect to ' . self::clean($current) . " refused ({$target['refused']}) — not followed";
				return ['code' => 0, 'body' => '', 'final' => self::clean($current), 'chain' => $chain, 'error' => '', 'refused' => $why];
			}
			$origin = $target['origin'];
			if (!isset($pins[$origin])) {
				$guard = $this->guard($target, $hop > 0 || $checkFirst, $hop === 0);
				if (isset($guard['refused'])) {
					return ['code' => 0, 'body' => '', 'final' => $target['url'], 'chain' => $chain, 'error' => '', 'refused' => $guard['refused']];
				}
				if (isset($guard['error'])) {
					return ['code' => 0, 'body' => '', 'final' => $target['url'], 'chain' => $chain, 'error' => $guard['error'], 'refused' => null];
				}
				$pins[$origin] = $guard;
			}
			$a = $this->request($target['url'], $untilText, $pins[$origin]['resolve']);
			$pinned = $pins[$origin]['address'];
			if ($pinned !== null && $a['ip'] !== '' && @inet_pton($a['ip']) !== @inet_pton($pinned)) {
				return ['code' => 0, 'body' => '', 'final' => $target['url'], 'chain' => $chain, 'error' => '',
					'refused' => "connected to {$a['ip']} instead of the checked address $pinned for " . self::clean($target['host']) . ' — result discarded'];
			}
			$redirect = $a['code'] >= 300 && $a['code'] < 400 && $a['location'] !== null;
			if (!$follow || !$redirect || $hop >= self::MAX_REDIRECTS) {
				// After MAX_REDIRECTS the last 3xx is the result: an endless loop, not a network problem.
				return ['code' => $a['code'], 'body' => $a['body'], 'final' => $target['url'], 'chain' => $chain, 'error' => $a['error'], 'refused' => null];
			}
			$current = self::absolute($target['url'], $a['location']);
			$chain[] = self::clean($current);
		}
	}

	/** @return array<string, array{0: string, 1: int}> field => [value, line] */
	public static function fields(string $text): array
	{
		$fields = [];
		$line = 1;
		foreach (preg_split('/^----\s*$/m', $text) as $block) {
			if (preg_match('/^\s*([A-Za-z0-9_-]+)\s*:(.*)$/s', $block, $t)) {
				$leading = strlen($block) - strlen(ltrim($block));
				$fields[strtolower($t[1])] = [trim($t[2]), $line + substr_count($block, "\n", 0, $leading)];
			}
			$line += substr_count($block, "\n");
		}
		return $fields;
	}

	/**
	 * Files to read under $path. Genba's own package is never read: installed from source (a path
	 * repository, or a vcs install with its tests/), its tests and docs carry sample links that are
	 * not the site's.
	 *
	 * @return list<string>
	 */
	private function files(string $path, string $basis): array
	{
		if (is_file($path)) {
			return [$path];
		}
		if (!is_dir($path)) {
			return [];
		}
		$base = $basis !== '' ? $basis : $path;
		$package = realpath(dirname(__DIR__));
		$tree = Tree::walk($path, $base, self::SKIP_PATHS, self::SKIP_ANYWHERE, $base, $package === false ? null : $package, [], $this->readTargets);
		foreach ($tree['skipped'] as $d) {
			$dir = str_starts_with($d['dir'], $base . '/') ? substr($d['dir'], strlen($base) + 1) : $d['dir'];
			$this->skipped[] = ['dir' => $dir, 'reason' => $d['own'] ? "Genba's own package: its tests and docs carry sample links" : $d['reason']];
		}
		$list = [];
		foreach ($tree['files'] as $file) {
			if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
				$list[] = $file;
			}
		}
		sort($list);
		return $list;
	}

	// ── Finding ─────────────────────────────────────────────────────────

	/**
	 * A directory that cannot be listed or a file that cannot be read throws
	 * \RuntimeException: links left unread are never reported as checked.
	 *
	 * @param list<string> $paths
	 * @return list<array{kind: string, key: string, url: string, expected: list<string>, place: string, product: ?string}>
	 */
	public function find(array $paths, string $basis = ''): array
	{
		$refs = [];
		$this->skipped = [];
		$this->readTargets = [];
		foreach ($paths as $path) {
			foreach ($this->files($path, $basis) as $file) {
				$display = $basis !== '' && str_starts_with($file, $basis . '/') ? substr($file, strlen($basis) + 1) : $file;
				$text = @file_get_contents($file);
				if ($text === false) {
					throw new \RuntimeException('Cannot read file: ' . self::clean($display));
				}
				array_push($refs, ...$this->fromText($text, $display));
				if (str_ends_with($file, '.txt')) {
					array_push($refs, ...$this->fromFields($text, $file, $display));
				}
			}
		}
		return $refs;
	}

	/** ID fields of a Kirby content file. */
	public function fromFields(string $text, string $file, string $place): array
	{
		$fields = self::fields($text);
		$refs = [];
		foreach ($fields as $name => [$value, $line]) {
			if (in_array($name, $this->digistoreFields, true) && preg_match('/^\s*(\d+)\s*$/', $value, $t)) {
				$ref = self::reference(self::KIND_DIGISTORE, $t[1], $this->digistoreBase . $t[1], self::pageTitles($file));
				$ref['place'] = "$place:$line";
				$refs[] = $ref;
			}
			if (in_array($name, $this->youtubeFields, true)) {
				// URLs in the field are covered by fromText(); the bare IDs next to them are not.
				// A URL ends at a comma too: Kirby keeps list fields comma-separated.
				$bare = (string)preg_replace('~https?://[^\s,"\'<>()\[\]{}\\\\|`]+~i', ' ', $value);
				preg_match_all('/(?<![A-Za-z0-9_-])[A-Za-z0-9_-]{11}(?![A-Za-z0-9_-])/', $bare, $ids);
				foreach ($ids[0] as $video) {
					$ref = self::reference(self::KIND_YOUTUBE, $video, "https://www.youtube.com/watch?v=$video");
					$ref['place'] = "$place:$line";
					$refs[] = $ref;
				}
			}
		}
		return $refs;
	}

	/** Every URL of a text, classified. */
	public function fromText(string $text, string $place): array
	{
		$refs = [];
		preg_match_all('~https?://[^\s"\'<>()\[\]{}\\\\|`]+~i', $text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [$url, $pos]) {
			$url = rtrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), '.,;:!?*\'');
			if (str_contains($url, '$') || str_contains($url, '%s')) {
				continue; // placeholder in a template
			}
			$ref = $this->classify($url);
			if ($ref !== null) {
				$ref['place'] = $place . ':' . (substr_count($text, "\n", 0, $pos) + 1);
				$refs[] = $ref;
			}
		}
		return $refs;
	}

	/**
	 * Resolves the host of a validated target ONCE and returns the pin for it.
	 * With $check (a redirect hop or a checked first address, and allowPrivate off), refuses local names and
	 * any target with a private, loopback, link-local, CGNAT, ULA, multicast or
	 * otherwise reserved address among ALL its A and AAAA records.
	 *
	 * @param array{url: string, origin: string, host: string, port: int, literal: bool} $target
	 * @return array{resolve: list<string>, address: ?string}|array{refused: string}|array{error: string}
	 */
	private function guard(array $target, bool $check, bool $first = false): array
	{
		$host = $target['host'];
		// The host comes from a Location header and may be thousands of characters
		// long; the texts below are built of cut pieces (the result line is not cut as a whole).
		$shown = self::clean($host);
		$check = $check && !$this->allowPrivate;
		[$what, $outcome] = $first ? ['address', 'not requested'] : ['redirect to', 'not followed'];
		if ($check) {
			foreach (self::LOCAL_SUFFIXES as $suffix) {
				if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
					return ['refused' => "$what $shown refused (local host name) — $outcome"];
				}
			}
		}
		if ($target['literal']) {
			$address = trim($host, '[]');
			if ($check && self::isPrivateAddress($address)) {
				return ['refused' => "$what $shown refused (private or local address $address) — $outcome"];
			}
			return ['resolve' => [], 'address' => $address];
		}
		$addresses = $this->resolve($host);
		if ($addresses === []) {
			return ['error' => "Could not resolve host: $shown"];
		}
		if ($check) {
			foreach ($addresses as $address) {
				if (self::isPrivateAddress($address)) {
					return ['refused' => "$what $shown refused (private or local address $address) — $outcome"];
				}
			}
		}
		// IPv4 first: a runner without IPv6 must not fail on a pinned AAAA record.
		usort($addresses, static fn (string $a, string $b): int => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));
		$address = $addresses[0];
		$entry = $host . ':' . $target['port'] . ':' . (str_contains($address, ':') ? "[$address]" : $address);
		return ['resolve' => [$entry], 'address' => $address];
	}

	/**
	 * Loopback, private (RFC 1918), link-local, CGNAT, benchmark, unspecified,
	 * reserved, broadcast, ULA, deprecated site-local fec0::/10, SIIT ::ffff:0:0:0/96,
	 * multicast, Teredo? IPv4 carried inside IPv6
	 * (mapped ::ffff:a.b.c.d, compatible ::a.b.c.d, 6to4 2002::/16, NAT64
	 * 64:ff9b::/96) is judged by its IPv4 part. Anything that is not an
	 * address counts as private (refused).
	 */
	public static function isPrivateAddress(string $address): bool
	{
		$address = trim($address, '[]');
		$packed = @inet_pton($address);
		if ($packed === false) {
			return true;
		}
		if (strlen($packed) === 16) {
			$embedded = null;
			if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($packed, str_repeat("\0", 12))) {
				$embedded = substr($packed, 12);
			} elseif (str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
				$embedded = substr($packed, 12);
			} elseif (str_starts_with($packed, "\x20\x02")) {
				$embedded = substr($packed, 2, 4);
			}
			if ($embedded !== null) {
				return self::isPrivateAddress((string)inet_ntop($embedded));
			}
		}
		$canonical = (string)inet_ntop($packed);
		if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return true;
		}
		foreach (self::PRIVATE_RANGES as [$network, $bits]) {
			$net = inet_pton($network);
			if (strlen($net) !== strlen($packed)) {
				continue;
			}
			$bytes = intdiv($bits, 8);
			$rest = $bits % 8;
			if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
				continue;
			}
			if ($rest === 0) {
				return true;
			}
			$mask = (0xff << (8 - $rest)) & 0xff;
			if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the product name match one of the page titles? Every word of the
	 * shorter name must occur in the longer one — as a whole word, as the start
	 * of a word (Edition/Editions) or as part of a compound word
	 * (Chakra/NebenChakren). Filler words and "&"/"und" do not count. Before,
	 * the names were compared without spaces: does one name lie entirely inside
	 * the other ("Galaktisches Herz" inside "… ~ GalaktischesHerz")?
	 *
	 * @param list<string> $titles
	 */
	public static function nameMatches(string $product, array $titles): bool
	{
		$p = self::words($product);
		$pk = implode('', $p);
		foreach ($titles as $title) {
			$w = self::words($title);
			$wk = implode('', $w);
			if (strlen($pk) >= 6 && strlen($wk) >= 6 && (str_contains($pk, $wk) || str_contains($wk, $pk))) {
				return true;
			}
			if ($p === [] || $w === []) {
				continue;
			}
			[$short, $long] = count($w) <= count($p) ? [$w, $p] : [$p, $w];
			$missing = array_filter($short, function (string $word) use ($long) {
				foreach ($long as $other) {
					if ($word === $other ||
						(strlen($word) >= 5 && strlen($other) >= 5 && (str_starts_with($other, $word) || str_starts_with($word, $other))) ||
						(strlen($word) >= 5 && str_contains($other, $word))) {
						return false;
					}
				}
				return true;
			});
			if ($missing === []) {
				return true;
			}
		}
		return false;
	}

	/** Titles of all language versions of the same page (product.de.txt, product.en.txt, …). */
	public static function pageTitles(string $file): array
	{
		$titles = [];
		foreach (glob(dirname($file) . '/*.txt') ?: [] as $version) {
			// Page files only, not the metadata of images (image.jpg.de.txt)
			if (!preg_match('/^[^.]+(\.[a-z]{2})?\.txt$/', basename($version))) {
				continue;
			}
			$value = self::fields((string)file_get_contents($version))['title'][0] ?? '';
			if ($value !== '') {
				$titles[] = $value;
			}
		}
		return array_values(array_unique($titles));
	}

	/** @return array{status: string, text: string} */
	public function purchaseLink(string $url, string|null $product = null): array
	{
		if ($product !== null) {
			$p = $this->digistore($product);
			if ($p['status'] !== self::OK) {
				return $p;
			}
		}
		// The first address comes from the site's content, where a mistyped or planted link
		// (https://nas.example.test/…) is as possible as a vendor redirect: it is checked like a
		// hop, unless its host is exactly one of the configured shop hosts (--shop-host).
		$host = self::requestUrl($url)['host'] ?? null;
		$trusted = is_string($host) && in_array($host, array_map('strtolower', $this->shopHosts), true);
		$a = $this->withRetry($url, true, null, !$trusted);
		// The first address is the content's, printed in findings: cut like every hop.
		$chain = implode(' → ', array_merge([self::clean($url)], $a['chain']));
		if ($a['refused'] !== null) {
			return ['status' => self::FINDING, 'text' => "Purchase link: {$a['refused']} ($chain)"];
		}
		if ($a['code'] >= 200 && $a['code'] < 300) {
			parse_str((string)parse_url($url, PHP_URL_QUERY), $before);
			parse_str((string)parse_url($a['final'], PHP_URL_QUERY), $after);
			$lost = [];
			foreach ($before as $k => $v) {
				if (!array_key_exists($k, $after) || $after[$k] !== $v) {
					// Each entry cut on its own: the whole result text is not cut.
					$lost[] = self::clean(is_array($v) ? $k : "$k=$v");
				}
			}
			if ($lost !== []) {
				return ['status' => self::FINDING, 'text' => 'Parameters lost on the way: ' . implode(', ', $lost) . " ($chain)"];
			}
			return ['status' => self::OK, 'text' => count($a['chain']) . ' redirect(s), target ' . self::clean($a['final'])];
		}
		if (in_array($a['code'], [404, 410], true) || ($a['code'] >= 300 && $a['code'] < 400)) {
			return ['status' => self::FINDING, 'text' => "Purchase link ends with HTTP {$a['code']} ($chain)"];
		}
		return ['status' => self::DISRUPTION, 'text' => 'Purchase link: ' . $this->describe($a) . " ($chain)"];
	}

	/** @return array{kind: string, key: string, url: string, expected: list<string>, place: string, product: ?string} */
	private static function reference(string $kind, string $key, string $url, array $expected = [], string|null $product = null): array
	{
		return ['kind' => $kind, 'key' => $key, 'url' => $url, 'expected' => $expected, 'place' => '', 'product' => $product];
	}

	/**
	 * One request, no redirect following, no proxy.
	 *
	 * @param list<string> $resolve CURLOPT_RESOLVE entries ("host:port:address"), [] = an address literal
	 * @return array{code: int, body: string, location: ?string, error: string, ip: string}
	 */
	private function request(string $url, string|null $untilText, array $resolve): array
	{
		$host = (string)parse_url($url, PHP_URL_HOST);
		$wait = ($this->lastRequest[$host] ?? 0) + $this->pause - microtime(true);
		if ($wait > 0) {
			usleep((int)($wait * 1_000_000));
		}
		$body = '';
		$location = null;
		$aborted = false;
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_FOLLOWLOCATION => false,
			// '' switches proxies off, also those from http_proxy/HTTPS_PROXY/ALL_PROXY:
			// a proxy would connect to the target itself, past the checked address.
			CURLOPT_PROXY => '',
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_USERAGENT => self::USER_AGENT,
			CURLOPT_HTTPHEADER => ['Accept-Language: de,en;q=0.8'],
			CURLOPT_RESOLVE => $resolve,
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$location) {
				if (preg_match('/^location:\s*(.+)$/i', trim($line), $t)) {
					$location = $t[1];
				}
				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION => function ($ch, string $part) use (&$body, &$aborted, $untilText) {
				$body .= $part;
				// Digistore pages weigh 600 kB; only the <title> is needed.
				if (($untilText !== null && stripos($body, $untilText) !== false) || strlen($body) > 2_000_000) {
					$aborted = true;
					return -1;
				}
				return strlen($part);
			},
		]);
		$ok = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$ip = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
		$error = $ok === false && !$aborted ? curl_error($ch) : '';
		$this->lastRequest[$host] = microtime(true);
		if ($error !== '') {
			$code = 0;
		}
		return ['code' => $code, 'body' => $body, 'location' => $location, 'error' => $error, 'ip' => $ip];
	}

	/**
	 * The URL curl is given, rebuilt from validated parts: scheme://host[:port]path[?query],
	 * lower-case scheme and host, no fragment, raw non-ASCII bytes of the path
	 * percent-encoded. Refused (with the reason) when the parts could be read
	 * differently by curl than by parse_url(): whitespace or control characters,
	 * a backslash, non-ASCII outside the path, userinfo (@ in the authority), a scheme other than
	 * http/https, a host that is not a plain ASCII DNS name (an IDN host must come as
	 * punycode), an authority that does not equal host[:port], or a numeric host in
	 * any form other than a canonical dotted quad (2130706433, 0x7f.1, 0177.0.0.1, 127.1).
	 *
	 * @return array{url: string, origin: string, host: string, port: int, literal: bool}|array{refused: string}
	 */
	public static function requestUrl(string $url): array
	{
		if (preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
			return ['refused' => 'whitespace, control character, backslash or non-ASCII in the address'];
		}
		if (!preg_match('~^([a-z][a-z0-9+.-]*)://([^/?#]*)(.*)$~is', $url, $m)) {
			return ['refused' => 'not an absolute http(s) address'];
		}
		if (preg_match('/[\x80-\xFF]/', $m[2])) {
			return ['refused' => 'whitespace, control character, backslash or non-ASCII in the address'];
		}
		if (preg_match('/[\x80-\xFF]/', $m[3])) {
			// A raw UTF-8 path (/produkt/kräuter in a Location header) is what a browser sends
			// percent-encoded; so does this. Only the path: the host stays refused above.
			$m[3] = (string)preg_replace_callback('~^[^?#]*~', static fn (array $p): string => (string)preg_replace_callback(
				'/[\x80-\xFF]/',
				static fn (array $b): string => sprintf('%%%02X', ord($b[0])),
				$p[0]
			), $m[3]);
			$url = $m[1] . '://' . $m[2] . $m[3];
		}
		$scheme = strtolower($m[1]);
		$authority = $m[2];
		if ($scheme !== 'http' && $scheme !== 'https') {
			// The scheme comes from a Location header — cut on its own (any length).
			return ['refused' => 'scheme ' . self::clean($scheme)];
		}
		if (str_contains($authority, '@')) {
			return ['refused' => 'user information in the address'];
		}
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
			return ['refused' => 'address cannot be parsed unambiguously'];
		}
		$host = strtolower($parts['host']);
		$port = $parts['port'] ?? null;
		if (strtolower($authority) !== $host . ($port !== null ? ':' . $port : '')) {
			return ['refused' => 'address cannot be parsed unambiguously'];
		}
		$literal = false;
		if (str_starts_with($host, '[')) {
			if (!str_ends_with($host, ']') || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
				return ['refused' => 'invalid IPv6 address'];
			}
			$literal = true;
		} else {
			$host = rtrim($host, '.');
			if (preg_match('/^[0-9a-fx.]+$/i', $host) && preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*$/i', $host)) {
				// Numeric: only the canonical dotted quad, which curl and PHP read alike.
				if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || long2ip((int)ip2long($host)) !== $host) {
					return ['refused' => 'numeric host in a non-canonical form'];
				}
				$literal = true;
			} elseif (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
				return ['refused' => 'host name is not a plain DNS name'];
			}
		}
		$port = $port ?? ($scheme === 'https' ? 443 : 80);
		$path = $parts['path'] ?? '/';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';
		$shownPort = isset($parts['port']) ? ':' . $parts['port'] : '';
		return [
			'url' => "$scheme://$host$shownPort$path$query",
			'origin' => "$scheme://$host:$port",
			'host' => $host,
			'port' => $port,
			'literal' => $literal,
		];
	}

	/** @return list<string> every A and AAAA address of $host */
	private function resolve(string $host): array
	{
		if ($this->resolver !== null) {
			return array_values(array_map('strval', ($this->resolver)($host)));
		}
		$addresses = gethostbynamel($host) ?: [];
		foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
			if (isset($record['ipv6'])) {
				$addresses[] = $record['ipv6'];
			}
		}
		return array_values(array_unique($addresses));
	}

	// ── Command line ────────────────────────────────────────────────────

	/**
	 * CLI entry point (bin/genba-links). Settings: --flag=value, then the
	 * GENBA_… environment variable, then the default. Findings and the summary
	 * go to $out; cannot-run reasons and usage errors go to $err.
	 *
	 * @param array<string, string|false> $env
	 * @param list<string> $argv
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $env, array $argv, string $cwd, $out, $err): int
	{
		$flags = [];
		$paths = [];
		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === '-h' || $arg === '--help') {
				$flags['help'] = true;
			} elseif (preg_match('/^--(skip-counter-check|list-only)$/', $arg, $t)) {
				$flags[$t[1]] = true;
			} elseif (preg_match('/^--(digistore-field|youtube-field|shop-host|oembed-base|digistore-base|pause)=(.*)$/s', $arg, $t)) {
				$flags[$t[1]] = $t[2];
			} elseif (str_starts_with($arg, '-')) {
				return self::cannotRun($out, $err, "::error::Unknown argument: $arg", true);
			} elseif ($arg === '') {
				// "${{ vars.LINK_PATHS }}" unset is "" — realpath("$base/") would read the whole
				// working directory instead of the default paths (the other four tools refuse an empty argument too).
				return self::cannotRun($out, $err, '::error::Path argument is empty (omit it for the default paths)', true);
			} else {
				$paths[] = $arg;
			}
		}
		foreach (['digistore-field', 'youtube-field', 'shop-host', 'oembed-base', 'digistore-base', 'pause'] as $flag) {
			if (isset($flags[$flag]) && trim($flags[$flag]) === '') {
				// An empty value would switch a check off without a word.
				return self::cannotRun($out, $err, "::error::--$flag needs a value", true);
			}
		}
		$pause = 0.3;
		if (isset($flags['pause'])) {
			if (!is_numeric($flags['pause']) || (float)$flags['pause'] < 0) {
				return self::cannotRun($out, $err, "::error::--pause must be a number of seconds, not: {$flags['pause']}");
			}
			$pause = (float)$flags['pause'];
		}

		// An argument error wins over --help, whatever the order (SPEC §3.0): the
		// unknown argument and the empty path return in the loop above, an empty --flag= and a
		// --pause that is no number here.
		if (isset($flags['help'])) {
			fwrite($out, self::usage());
			return 0;
		}
		if (!extension_loaded('curl')) {
			return self::cannotRun($out, $err, '::error::PHP extension curl is missing');
		}
		// mbstring alike — without it the run would break off with "Call to undefined function
		// mb_strtolower()" in the middle (SPEC §3.0).
		$missing = Text::missingMbstring();
		if ($missing !== null) {
			return self::cannotRun($out, $err, '::error::' . $missing);
		}

		// Empty or blank counts as unset: GitHub Actions turns an unset `${{ vars.X }}` into "".
		$setting = static function (string $flag, string $variable) use ($flags, $env): string|null {
			if (isset($flags[$flag]) && is_string($flags[$flag])) {
				return $flags[$flag];
			}
			$value = $env[$variable] ?? null;
			return is_string($value) && trim($value) !== '' ? $value : null;
		};
		$list = static fn (string|null $raw, array $default): array => $raw === null ? $default
			: array_values(array_filter(array_map(fn ($s) => strtolower(trim($s)), explode(',', $raw)), fn ($s) => $s !== ''));

		$base = realpath($cwd) ?: $cwd;
		$defaults = $paths === [];
		$shown = $defaults ? ['content', 'site', 'public'] : $paths;
		$existing = [];
		$missing = [];
		foreach ($shown as $path) {
			$real = realpath(str_starts_with($path, '/') ? $path : $base . '/' . $path);
			if ($real !== false) {
				$existing[] = $real;
			} elseif (!$defaults) {
				fwrite($out, "::warning::Path not found, skipped: $path\n");
			} else {
				// A site without public/ is plausible, but the log says what was not read.
				$missing[] = $path;
			}
		}
		if ($existing === []) {
			return self::cannotRun($out, $err, '::error::None of the paths exist: ' . implode(', ', $shown));
		}
		$label = implode(', ', $shown);
		foreach ($missing as $path) {
			fwrite($out, "  --   not checked: $path (default path, does not exist)\n");
		}

		$links = new self(
			$list($setting('digistore-field', 'GENBA_DIGISTORE_FIELDS'), self::DEFAULT_DIGISTORE_FIELDS),
			$list($setting('youtube-field', 'GENBA_YOUTUBE_FIELDS'), self::DEFAULT_YOUTUBE_FIELDS),
			$list($setting('shop-host', 'GENBA_SHOP_HOSTS'), []),
			$flags['oembed-base'] ?? self::DEFAULT_OEMBED_BASE,
			$flags['digistore-base'] ?? self::DEFAULT_DIGISTORE_BASE,
			3,
			$pause,
		);

		try {
			$refs = $links->find($existing, $base);
		} catch (\RuntimeException $e) {
			// An unreadable directory or file: the links in it would go unchecked.
			return self::cannotRun($out, $err, '::error::' . $e->getMessage());
		}
		// Directory names, URLs and places come from the site's own files; they are log text
		// like the fetched titles: clean() before anything prints them.
		foreach ($links->skipped() as $dir) {
			// Each piece cleaned and cut on its own, the line not cut (as below).
			fwrite($out, '  --   not checked: ' . self::clean($dir['dir']) . ' (' . self::clean($dir['reason']) . ")\n");
		}
		$groups = [];
		foreach ($refs as $ref) {
			$key = self::targetKey($ref);
			$groups[$key] ??= ['ref' => $ref, 'places' => []];
			$groups[$key]['places'][] = $ref['place'];
		}
		fwrite($out, count($refs) . ' external references found, ' . count($groups) . " distinct targets.\n");

		if ($groups === []) {
			fwrite($out, "::notice::No external references found under $label\n");
		}
		if (isset($flags['list-only'])) {
			foreach ($groups as $g) {
				// Each piece cleaned and cut on its own: a URL of 100 000 characters is
				// cut, a long list of places is not.
				fwrite($out, self::clean(sprintf(
					'  %-18s %s  (%s)',
					$g['ref']['kind'],
					self::clean($g['ref']['url']),
					implode(', ', array_map(static fn (string $p): string => self::clean($p), $g['places']))
				), PHP_INT_MAX) . "\n");
			}
			// SPEC §3.0: every run ends with the tool's line, a listing too.
			fwrite($out, 'Genba links: ' . count($groups) . " references listed\n");
			return 0;
		}

		if ($groups === []) {
			self::summary($out, [self::OK => 0, self::FINDING => 0, self::DISRUPTION => 0, self::NOTICE => 0]);
			return 0;
		}

		if (!isset($flags['skip-counter-check'])) {
			$failures = $links->counterCheck();
			if ($failures !== []) {
				foreach ($failures as $failure) {
					fwrite($out, "::error::Counter-check: $failure\n");
				}
				fwrite($out, "The link check cannot currently tell what exists from what does not. The result would be worthless.\n");
				fwrite($out, "Genba links: FAILED — counter-check failed, no reference was checked\n");
				return 1;
			}
			fwrite($out, "Counter-check passed: a deleted video and a missing product are recognised.\n");
		} else {
			fwrite($out, "  --   counter-check skipped (--skip-counter-check)\n");
		}

		$counts = [self::OK => 0, self::FINDING => 0, self::DISRUPTION => 0, self::NOTICE => 0];
		foreach ($groups as $g) {
			$r = $links->check($g['ref']);
			$counts[$r['status']]++;
			$places = implode(', ', array_map(static fn (string $p): string => self::clean($p), array_slice($g['places'], 0, 5)))
				. (count($g['places']) > 5 ? ' …' : '');
			// The URL, the result text and each place cleaned and cut on their own. The
			// result text is built of pieces cut where they are made — URL, chain links, lost
			// parameters, titles: cut as a whole, a long chain would lose its end and the cause.
			$line = self::clean(self::clean($g['ref']['url']) . ': ' . self::clean($r['text'], PHP_INT_MAX) . " — in $places", PHP_INT_MAX);
			fwrite($out, match ($r['status']) {
				self::OK => "  ok   $line\n",
				self::FINDING => "::error::$line\n",
				self::DISRUPTION => "::warning::Disruption, not assessed: $line\n",
				self::NOTICE => "::notice::$line\n",
			});
		}
		self::summary($out, $counts);
		return $counts[self::FINDING] > 0 ? 1 : 0;
	}

	/**
	 * Directories the last find() did not read: a skipped place (vendor, site/cache, public/media,
	 * node_modules …), Genba's own package, or a symbolic link it does not follow. Relative to the
	 * $basis given to find().
	 *
	 * @return list<array{dir: string, reason: string}>
	 */
	public function skipped(): array
	{
		return $this->skipped;
	}

	/** @param array<string, int> $counts */
	private static function summary($out, array $counts): void
	{
		fwrite($out, sprintf(
			'Genba links: %d ok, %d findings, %d disruptions, %d notices.',
			$counts[self::OK],
			$counts[self::FINDING],
			$counts[self::DISRUPTION],
			$counts[self::NOTICE]
		) . "\n");
	}

	/** Identifies a target: references with the same key are checked once. */
	public static function targetKey(array $ref): string
	{
		return $ref['kind'] . '|' . $ref['key'] . '|' . implode('|', $ref['expected']);
	}

	public static function usage(): string
	{
		return "Usage: genba-links [--digistore-field=a,b] [--youtube-field=a,b] [--shop-host=h1,h2]\n"
			. "                     [--skip-counter-check] [--list-only] [--oembed-base=URL]\n"
			. "                     [--digistore-base=URL] [--pause=SECONDS] [--help] [path ...]\n"
			. "\n"
			. "Environment: GENBA_DIGISTORE_FIELDS (default digistoreid), GENBA_YOUTUBE_FIELDS\n"
			. "(default videoid,youtubeid,youtubevideos), GENBA_SHOP_HOSTS (default none).\n"
			. "Paths default to content, site and public under the current directory.\n"
			. "Exit codes: 0 no findings (disruptions are warnings), 1 findings or failed\n"
			. "counter-check, 2 cannot run.\n";
	}

	// ── HTTP ────────────────────────────────────────────────────────────

	/** Repeats only what looks like a disruption: no network, 5xx, 429. A refused redirect is final. */
	private function withRetry(string $url, bool $follow, string|null $untilText = null, bool $checkFirst = false): array
	{
		for ($i = 1;; $i++) {
			$a = $this->fetch($url, $follow, $untilText, $checkFirst);
			$disrupted = $a['refused'] === null && ($a['code'] === 0 || $a['code'] >= 500 || $a['code'] === 429);
			if (!$disrupted || $i >= $this->retries) {
				return $a;
			}
			usleep((int)(1_000_000 * $this->pause * 5 * $i));
		}
	}

	/** @return list<string> */
	private static function words(string $s): array
	{
		$s = mb_strtolower($s);
		$s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => ' und ']);
		$filler = ['und', 'and', 'der', 'die', 'das', 'des', 'den', 'dem', 'von', 'vom', 'zur', 'zum', 'mit',
			'the', 'for', 'with', 'ein', 'eine', 'sayama', 'digistore24'];
		$words = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) >= 3 && !in_array($w, $filler, true))));
	}

	/** @return array{status: string, text: string} */
	public function youtube(string $video, bool $embed = false): array
	{
		$target = 'https://www.youtube.com/watch?v=' . $video;
		$a = $this->withRetry($this->oembedBase . '?format=json&url=' . rawurlencode($target), false);
		if ($a['refused'] !== null) {
			// The same as digistore(): a refused address (an oEmbed base that cannot be read
			// unambiguously, a connection to another address than the checked one) is a
			// finding with its reason, not "unreachable: no response ()".
			return ['status' => self::FINDING, 'text' => "Video $video: {$a['refused']}"];
		}
		if ($a['code'] === 200) {
			$data = json_decode($a['body'], true);
			return is_array($data) && is_string($data['title'] ?? null)
				? ['status' => self::OK, 'text' => '"' . self::clean($data['title']) . '"']
				: ['status' => self::DISRUPTION, 'text' => 'oEmbed answers 200 without a title'];
		}
		if (in_array($a['code'], [400, 404], true)) {
			return ['status' => self::FINDING, 'text' => "Video $video does not exist (oEmbed {$a['code']})"];
		}
		if ($a['code'] === 403) {
			return ['status' => self::FINDING, 'text' => "Video $video is private (oEmbed 403)"];
		}
		if ($a['code'] === 401) {
			return ['status' => $embed ? self::FINDING : self::NOTICE,
				'text' => "Video $video cannot be embedded (oEmbed 401)"];
		}
		return ['status' => self::DISRUPTION, 'text' => 'YouTube unreachable: ' . $this->describe($a)];
	}
}
