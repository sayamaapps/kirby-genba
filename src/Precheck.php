<?php

namespace Genba;

use RuntimeException;

require_once __DIR__ . '/Tree.php';

/**
 * Static pre-deploy checks under the site's Content Security Policy
 * ('unsafe-inline' forbidden, nothing foreign) plus "built assets are there",
 * and for the live deploy "no placeholder in the content" (MARKERS).
 * Kirby-free: no kirby(), no App, no option(). SPEC.md §3.3.
 *
 * Every rule carries a self-test sample that MUST match before the scan runs,
 * so a broken pattern cannot silently check nothing.
 */
final class Precheck
{
	/** What ends an address (address()): no URL holds these as they are. */
	private const ADDRESS_STOP = " \t\n\r\f\v\"'<>()`,;{}|\\";

	/** The rule the allow list applies to (exceptions(), and the self-test of its positive samples). */
	private const ALLOW_RULE = 'foreign-host';
	private const BINARY_HEAD = 8192;

	/** The extension of a content file the marker check reads, Kirby's default `content.extension`. */
	public const CONTENT_EXTENSION = 'txt';

	/** Directory names under the content root skipped at any depth, with the reason the log prints: content that is not delivered. */
	public const CONTENT_SKIP = ['_drafts' => 'Kirby drafts, not delivered'];
	public const DEFAULT_ALLOW = ['places.googleapis.com/v1/'];
	public const DEFAULT_REQUIRE = ['public/assets/css/tailwind.css'];

	/**
	 * The event handler content attributes Genba knows, lower case: the HTML standard's
	 * GlobalEventHandlers, WindowEventHandlers and Document handlers, Pointer and Touch Events, CSS
	 * animations and transitions, Fullscreen, Selection, scroll snap, SVG animation (`onbegin` …), and
	 * the engine-specific, prefixed or legacy names of WebKit, Gecko and Blink (`onwebkitendfullscreen`,
	 * `ongesturestart`, `onbeforescriptexecute`, `onmousewheel` …). A name on the list is a finding; an
	 * on… name outside it (`once`, `online`, `onready` — in code a variable or a constant) is no finding
	 * but a visible `--` line (notCounted()), so a handler the list does not know yet is never passed
	 * in silence.
	 */
	public const EVENT_HANDLERS = [
		'onabort', 'onactivate', 'onafterprint', 'onanimationcancel', 'onanimationend', 'onanimationiteration',
		'onanimationstart', 'onauxclick', 'onbeforecopy', 'onbeforecut', 'onbeforeinput', 'onbeforematch',
		'onbeforepaste', 'onbeforeprint', 'onbeforetoggle', 'onbeforeunload', 'onbeforexrselect', 'onbegin',
		'onblur', 'oncancel', 'oncanplay', 'oncanplaythrough', 'onchange', 'onclick', 'onclose', 'oncommand',
		'oncontentvisibilityautostatechange', 'oncontextlost', 'oncontextmenu', 'oncontextrestored', 'oncopy',
		'oncuechange', 'oncut', 'ondblclick', 'ondrag', 'ondragend', 'ondragenter', 'ondragexit', 'ondragleave',
		'ondragover', 'ondragstart', 'ondrop', 'ondurationchange', 'onemptied', 'onend', 'onended', 'onerror',
		'onfocus', 'onfocusin', 'onfocusout', 'onformdata', 'onfreeze', 'onfullscreenchange', 'onfullscreenerror',
		'ongotpointercapture', 'onhashchange', 'oninput', 'oninvalid', 'onkeydown', 'onkeypress', 'onkeyup',
		'onlanguagechange', 'onload', 'onloadeddata', 'onloadedmetadata', 'onloadend', 'onloadstart',
		'onlostpointercapture', 'onmessage', 'onmessageerror', 'onmousedown', 'onmouseenter', 'onmouseleave',
		'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onmousewheel', 'onmozfullscreenchange',
		'onmozfullscreenerror', 'onoffline', 'ononline', 'onorientationchange', 'onpagehide', 'onpagereveal',
		'onpageshow', 'onpageswap', 'onpaste', 'onpause', 'onplay', 'onplaying', 'onpointercancel', 'onpointerdown',
		'onpointerenter', 'onpointerleave', 'onpointerlockchange', 'onpointerlockerror', 'onpointermove',
		'onpointerout', 'onpointerover', 'onpointerrawupdate', 'onpointerup', 'onpopstate', 'onprerenderingchange',
		'onprogress', 'onratechange', 'onreadystatechange', 'onrejectionhandled', 'onrepeat', 'onreset',
		'onresize', 'onresume', 'onscroll', 'onscrollend', 'onscrollsnapchange', 'onscrollsnapchanging',
		'onsearch', 'onsecuritypolicyviolation', 'onseeked', 'onseeking', 'onselect', 'onselectionchange',
		'onselectstart', 'onshow', 'onslotchange', 'onstalled', 'onstorage', 'onsubmit', 'onsuspend',
		'ontimeupdate', 'ontoggle', 'ontouchcancel', 'ontouchend', 'ontouchmove', 'ontouchstart',
		'ontransitioncancel', 'ontransitionend', 'ontransitionrun', 'ontransitionstart', 'onunhandledrejection',
		'onunload', 'onvisibilitychange', 'onvolumechange', 'onwaiting', 'onwebkitanimationend',
		'onwebkitanimationiteration', 'onwebkitanimationstart', 'onwebkitfullscreenchange',
		'onwebkitfullscreenerror', 'onwebkittransitionend', 'onwheel', 'onzoom',
		// Engine-specific: WebKit (HTMLElement's handler table, media and force-touch events), Gecko, Blink.
		'onafterscriptexecute', 'onautocomplete', 'onautocompleteerror', 'onbeforefilter', 'onbeforeload',
		'onbeforescriptexecute', 'onencrypted', 'ongesturechange', 'ongestureend', 'ongesturestart',
		'oninstallresult', 'onlocation', 'onmozpointerlockchange', 'onmozpointerlockerror', 'onpromptaction',
		'onpromptdismiss', 'onstream', 'ontimezonechange', 'ontouchforcechange', 'onvalidationstatuschange',
		'onwaitingforkey', 'onwebkitbeginfullscreen', 'onwebkitcurrentplaybacktargetiswirelesschanged',
		'onwebkitendfullscreen', 'onwebkitkeyadded', 'onwebkitkeyerror', 'onwebkitkeymessage',
		'onwebkitmouseforcechanged', 'onwebkitmouseforcedown', 'onwebkitmouseforceup',
		'onwebkitmouseforcewillbegin', 'onwebkitneedkey', 'onwebkitplaybacktargetavailabilitychanged',
		'onwebkitpresentationmodechanged',
	];
	public const FINDING = 'finding';

	/**
	 * Placeholders that must not go live, for `--forbid-markers` (SPEC §3.3, since 1.6.0): applied per line
	 * to the content files (CONTENT_EXTENSION under the content root, CONTENT_SKIP left out). A placeholder
	 * is one of these words right behind an opening square bracket, any case, followed by the closing
	 * bracket, a colon or a dash: `[TODO]`, `[OFFEN]`, `[TBD: date]`, `[Platzhalter – photo]`. A Markdown
	 * link (`[TODO](…)`, `[TODO][1]`), a longer word (`[Todo-Liste]`, `[Offene Fragen]`) and the word
	 * without brackets are none. English and German words, the languages of the tools' texts. Each entry is
	 * a rule as in RULES (`pattern`, `sample`, `positives`, `negatives`) with the `message` of its finding,
	 * checked by the same self-test before the content is read.
	 */
	public const MARKERS = [
		'placeholder' => ['pattern' => '~\[\s*(?:todo|fixme|tbd|tba|tbc|xxx|placeholder|platzhalter|offen)(?:\s*\](?![(\[])|\s*:|\s*[–—]|\s+-)~iu',
			'sample' => 'Text: [TODO] The text follows.', 'message' => 'placeholder',
			'positives' => ['Text: [OFFEN] Text folgt.', 'Intro: [offen] Fakten folgen.', '[TODO: photo of the team]', '[ TBD ]',
				'[Platzhalter – Bild]', '[FIXME - date]', '(image: team.jpg alt: [placeholder])', '[XXX]', '[TODO] (see below)',
				'[TBC—venue]', 'Date: [tba]'],
			'negatives' => ['[Offene Fragen]', '[Todo-Liste]', '[TODO](https://example.com/todo)', '[TODO][1]', '[todolist]',
				'TODO without brackets', '[x] done', '[Open Source]', '(link: offen text: Offen)', '[TBDx]']],
	];

	/** The message of a finding of a pattern from --marker or GENBA_MARKERS. */
	private const OWN_MARKER = 'marker';

	/**
	 * Rules, in this order. Each: `pattern` (PCRE with `~` delimiters, applied per line), `roots` it scans
	 * ('site' only, or 'site' and 'public'), `sample` (a line the rule MUST fire on — the self-test),
	 * `message`. Optional `each` (PCRE): the line is judged per match of `each` — a tag — instead of as a
	 * whole (embedded-script, so an embedded <script> next to an external one on the same line
	 * is found); every match of `start` (PCRE, default `pattern`) on the line is where a tag begins, and
	 * the tag may run on over the next TAG_LINES lines (a tag a formatter wrapped); a start
	 * without a complete tag in reach is a finding. A sample may hold "\n" to stand for the lines below.
	 * Optional `wrapped` (true, a rule without `each`): a line that ends in a name is read with the lines
	 * below it up to the first that is not blank (wrapped()), where the `=` of an attribute a formatter
	 * wrapped after its name stands; a match counts for the line it begins on (ownMatches()).
	 * Optional `attr` (PCRE on a lower-case attribute name): the rule also fires on a line where
	 * Kirby's attr() helper may print an attribute of that name (attrNames()) — every rule that forbids an
	 * attribute carries it, as every rule that exempts by one reads attr() through `exempt`.
	 * Optional `names` (list of lower-case names): a match counts only when the attribute name it
	 * captures (group `name` of `pattern`) or the name attr() prints is one of them — the handler rule
	 * takes EVENT_HANDLERS, so an identifier in code (`var once = key`) is no attribute.
	 * Optional `unless` (list of PCRE patterns): a piece — the `each` match, else the line —
	 * that one of them matches is NOT a finding. Optional `exempt` (with `each`): attribute
	 * name => PCRE; a tag that carries one of these attributes (attributes(): read one after the other)
	 * with a value the PCRE matches is NOT a finding — a pattern over the whole tag also matched inside
	 * another attribute's value. For `foreign-host` the constructor's allow list is
	 * applied at runtime, per match: every match of `pattern` must be allowed — an allow
	 * entry stands in the same address (from the last `//` before the match to the
	 * first character no URL holds, address()) or overlaps the match (allowed()). Optional `negatives`:
	 * lines the rule must NOT fire on (the self-test's negative control: an over-wide pattern fails
	 * as loudly as a dead one); optional `positives`:
	 * further lines it MUST fire on — those of foreign-host also with DEFAULT_ALLOW.
	 */
	public const RULES = [
		// All four case-insensitive: HTML attributes, tag names and host names do not
		// care about case, and <P STYLE="x"> is as much a CSP violation as <p style="x">.
		// Every spelling the browser accepts counts — style='x', style = "x", any on<event>=.
		// The leading whitespace is the attribute boundary: $style = 'card' (a PHP variable),
		// data-style="x", icon="x" and json="x" are not attributes the CSP forbids.
		// The value needs no quote (style=color:red is valid HTML5), and an attribute on
		// a line of its own may stand at the start of the line — the same boundary as the handler
		// rule, which never asked for a quote.
		// The boundary is what the HTML tokenizer ends an attribute name with — whitespace,
		// the closing quote of the value before (class="a"style="x", no space needed) or a `/`
		// (<img src="x"/onerror="y">) — and the closing tag of a PHP block right before the name (a
		// block that prints the attribute before it): the same boundary in both rules, as for a script's src.
		// Between the name and the = the tokenizer reads any whitespace, line breaks included
		// (style⏎="x"): both rules are `wrapped`, the finding is the line of the name.
		// An attribute Kirby's attr() helper may print is an attribute too — `attr`,
		// the name pattern, for every rule that forbids one (attrNames(): any call, a multi-line array
		// literal, `'style' => $s ?: null` counts, `'style' => null` prints nothing). Reading attr()
		// only to free a script would let a style or an onclick printed by it pass.
		'inline-style'    => ['pattern' => '~(^|[\s"\'/]|\?>)style\s*=~i', 'wrapped' => true, 'attr' => '~^style$~',                                'roots' => ['site'],           'sample' => '<p style="x">',               'message' => 'inline style — the CSP forbids it',
			'positives' => ['<p class="a"style="color:red">', '<p/style="color:red">', '<p <?= $x ?>style="color:red">',
				"<div<?= attr(['class' => 'card', 'style' => '--accent: ' . \$page->color()], ' ') ?>>",
				"<div<?= Html::attr(['style' => \$s ?: null], null, ' ') ?>>", "<div<?= \$wide ? attr(['style' => \$s], ' ') : '' ?>>",
				"<div<?= attr([\n  'class' => 'card',\n  'style' => \$css,\n], ' ') ?>>",
				// The single form and named arguments.
				"<div<?= Html::attr('style', \$css, ' ') ?>>", "<div<?= attr(attr: ['style' => 'color:red'], before: ' ') ?>>",
				// The = on a line below the name, a blank line between.
				"<button style\n=\"color:red\">", "<p STYLE\n\n  ='color:red'>"],
			'negatives' => ['<?php $style = \'card\'; ?>', '<div data-style="x">',
				// A name without = (an empty attribute), and a style that starts on the line below: that line's.
				"<p style\n  class=\"a\">", "<p class=\"a\"\nstyle=\"x\">",
				"<div<?= attr(['style' => null], ' ') ?>>", "<div<?= attr(['data-style' => \$s, 'class' => 'style'], ' ') ?>>",
				"<div<?= Html::attr('style', null, ' ') ?>>"]],
		'foreign-host'    => ['pattern' => '~(googleapis|gstatic|jimcdn|instagram\.com/embed|maps\.google|//fonts\.)~i',  'roots' => ['site', 'public'], 'sample' => 'https://fonts.gstatic.com/x', 'message' => 'foreign host — brings back the consent requirement',
			// A forbidden address next to the allowed one of the default entry,
			// separated by a backtick, a comma — checked with the default allow list too.
			'positives' => ['const a=`https://places.googleapis.com/v1/${id}`,b=`https://fonts.googleapis.com/css2?family=Inter`;',
				'preconnect=https://places.googleapis.com/v1/,https://fonts.gstatic.com'],
			// A browser API, not a host: the unanchored `fonts\.` of an earlier rule version matched it.
			'negatives' => ['document.fonts.ready.then(remeasure)']],
		// An on… name counts only when it is an event handler Genba knows (`names`, EVENT_HANDLERS):
		// `var once = key` in a script and `const ONLINE = 'online'` in a class are code, not attributes.
		// Any other on… name is printed as a `--` line (notCounted()), never passed in silence.
		// A PHP closing tag right before the name is a boundary too (a block printing the attribute before).
		'inline-handler'  => ['pattern' => '~(^|[\s"\'/]|\?>)(?<name>on[a-z]+)\s*=~i', 'wrapped' => true, 'attr' => '~^on[a-z]+$~', 'names' => self::EVENT_HANDLERS, 'roots' => ['site'], 'sample' => '<button onclick="x">', 'message' => 'inline event handler — the CSP forbids it',
			'positives' => ['<img src="x.png"/onerror="alert(1)">', '<a href="#"onclick="x()">', 'onclick="x()"', '<A ONCLICK="x()">',
				'<svg><animate onbegin="x()"/></svg>', '<body onpageshow="init()">', '<div onpointerdown="x()">',
				"el.innerHTML = '<button onclick=\"go()\">';", '<div once="x" onclick="go()">',
				'<button class="b"<?= $extra ?>onclick="go()">', '<video playsinline onwebkitendfullscreen="resume()">',
				"<button<?= attr(['type' => 'button', 'onclick' => 'toggle()'], ' ') ?>>", "<details<?= attr(['ontoggle'], ' ') ?>>",
				"<button<?= Html::attr('onclick', 'go()', ' ') ?>>", "<button onclick\n  =\"go()\">"],
			'negatives' => ['<i icon="x">', '<x json="y">', '<a href="?on=1">',
				"var once = key + '\\n' + detail;", "public const ONLINE = 'online';", 'let onReady = init;', '<div once="x">',
				"<button<?= attr(['type' => 'button', 'onclick' => false, 'data-on' => 'x'], ' ') ?>>",
				"<div<?= attr(['once' => \$x], ' ') ?>>", "<button onclick\n  class=\"b\">", "<p class=\"a\"\nonclick=\"x()\">"]],
		// `on` directly followed by a PHP block (a printed name such as onclick built at runtime): the name is
		// not known at scan time, so Genba cannot tell an event handler from anything else. A finding, with or without the `=`.
		'inline-handler-runtime' => ['pattern' => '~(^|[\s"\'/]|\?>)on(?=<\?)~i', 'roots' => ['site'], 'sample' => '<div on<?= $event ?>="go()">', 'message' => 'inline event handler with a name built at runtime — the CSP forbids it',
			'positives' => ['<div on<?= $event ?>="go()">', '<p on<?php echo $e ?>>x</p>', '<div class="a"on<?= $e ?>="x()">'],
			'negatives' => ['<i one<?= $x ?>="y">', '<a href="?on=1">', '<p><?= $icon ?></p>']],
		// Judged per <script …> tag (`each`), not per line — `src=` anywhere on the line would
		// exempt an embedded script next to an external one, and a script whose code says
		// `el.src=`. A tag is exempt with its own src attribute or type application/ld+json.
		// The tag ends at the first > outside a quoted value and outside a PHP block — with `[^>]*`
		// a Kirby nonce (nonce="<?= $kirby->nonce() …", a PHP block) would end the tag at the > of ->,
		// and the src behind it would not be seen. Possessive: no backtracking, whatever the line.
		// A tag a formatter wrapped over several lines (<script\n  type="module">) is read
		// on to its > on the lines below (`start`: where a tag begins), at most TAG_LINES further;
		// without a > in reach the line is a finding, not a tag nobody judged.
		// The tag is read by the tokenizer's grammar (START_TAG) —
		// a quote opens a value only right behind an = (title=it's opens none) — and the exemption
		// is bound to the attribute (`exempt`, attributes()): `src=` or `application/ld+json` in
		// another attribute's value (data-hint="loaded with src=/a.js") frees no embedded script.
		'embedded-script' => ['pattern' => '~<script~i', 'start' => '~<script\b~i', 'each' => '~<script\b' . self::START_TAG . '~is', 'exempt' => ['src' => '~~', 'type' => '~application/ld\+json~i'], 'roots' => ['site'], 'sample' => '<script>alert(1)</script>', 'message' => 'embedded <script> — the CSP forbids it',
			// A PHP block without quotes around it (nonce=<?= …, unquoted) is read as a block too.
			'positives' => ['<script src="/a.js"></script><script>x()</script>', '<script>el.src="/x.js"</script>', '<script nonce="<?= $kirby->nonce() ?>">x()</script>',
				"<script\n  type=\"module\">", '<script', '<script nonce=<?= $kirby->nonce() ?>>x()</script>',
				// src= and application/ld+json inside another value; a quote inside an unquoted value.
				'<script data-hint="loaded with src=/assets/js/app.js">track()</script>', '<script title=\'see application/ld+json\'>track()</script>',
				"<script title=it's>x()</script><script src='/a.js'>'</script>",
				// Kirby's attr() without a provable src (or ld+json type), and attr() inside a value.
				"<script<?= attr(['nonce' => \$kirby->nonce()], ' ') ?>>x()</script>", "<script<?= attr(['data-src' => \$u], ' ') ?>>x()</script>",
				"<script<?= attr(['src' => null], ' ') ?>>x()</script>", "<script<?= attr(\$attributes, ' ') ?>>x()</script>",
				"<script<?= attr(['type' => \$type], ' ') ?>>{}</script>", "<script data-x=\"<?= attr(['src' => \$u]) ?>\">x()</script>",
				"<script<?= attr(['title' => \"'src' => x\"], ' ') ?>>x()</script>",
				// A src that may print nothing, an attr() in a branch or inside a value.
				"<script<?= attr(['src' => \$page->tracker()->value() ?: null, 'nonce' => \$kirby->nonce()], ' ') ?>>init()</script>",
				"<script<?= \$external ? attr(['src' => \$u], ' ') : '' ?>>inline()</script>",
				"<script<?= attr(['nonce' => attr(['src' => 'x'])], ' ') ?>>evil()</script>",
				"<script<?= attr(['src' => \$file?->url()], ' ') ?>>x()</script>", "<script<?= attr(['src' => \$u ?? null], ' ') ?>>x()</script>",
				"<script<?= Html::attr('src', null, ' ') ?>>x()</script>"],
			'negatives' => ['<script src="/x.js"></script>', '<SCRIPT SRC="/x.js"></SCRIPT>', '<script type="application/ld+json">{}</script>',
				'<script nonce="<?= $kirby->nonce() ?>" src="/a.js"></script>', '<script data-config=\'{"a":"b>c"}\' src="/a.js"></script>',
				"<script\n  src=\"/a.js\"\n></script>", '<script nonce=<?= $kirby->nonce() ?> src="/assets/js/app.js"></script>',
				'<script async src=/a.js></script>', '<script title="a"src="/a.js"></script>', '<script/src="/a.js"></script>',
				// The src (or the ld+json type) comes from Kirby's attr() helper.
				"<script<?= attr(['src' => \$url, 'nonce' => \$kirby->nonce()], ' ') ?>></script>",
				"<script <?= attr(['type' => 'application/ld+json']) ?>>{}</script>",
				"<script<?= Html::attr(['defer' => true, \"src\" => url('assets/js/app.js')], null, ' ') ?>></script>",
				"<script<?= attr(['defer', 'src' => \$url], ' '); ?>></script>",
				// The single form frees too.
				"<script<?= Html::attr('src', \$u, ' ') ?>></script>"]],
	];

	/** Directory names skipped at any depth: never site code. */
	public const SKIP_ANYWHERE = ['node_modules' => 'npm dependencies', '.git' => 'version control'];

	/**
	 * Directories skipped only directly under their root (generated or server-side state), with the reason
	 * the log prints. By place, not by name: `site/snippets/media/` is site code and IS scanned.
	 */
	public const SKIP_ROOT_DIRS = [
		'site'   => ['cache' => 'Kirby cache', 'sessions' => 'Kirby sessions', 'accounts' => 'Panel accounts', 'logs' => 'logs'],
		'public' => ['media' => 'Kirby media folder, generated'],
	];

	/** A start tag behind `<` and the first letter of its name, up to and with its `>`. */
	private const START_TAG = self::TAG_FIRST . '*+(?:[\t\n\f\r /](?:[\t\n\f\r /]|' . self::TAG_NAMES
		. '(?:/|=[\t\n\f\r ]*+(?:"[^"]*+"|\'[^\']*+\'|' . self::TAG_UNQUOTED . '[\t\n\f\r ])))*+(?:' . self::TAG_NAMES
		. '(?:=[\t\n\f\r ]*+(?:' . self::TAG_UNQUOTED . ')?)?)?)?>';

	/**
	 * The start tag as the HTML tokenizer reads it: PCRE pieces of the tokenizer's grammar,
	 * a PHP block (`<?… ?>`) one character
	 * (a `<` that opens none is a character too). A tag name runs to whitespace, `/` or `>`; an
	 * attribute name to whitespace, `/`, `>` or `=` (its first character may be `=`); a value is quoted only
	 * when the quote is the first character behind the `=` (whitespace aside) — `title=it's` is the unquoted
	 * value `it's`, its `'` opens nothing —, an unquoted value runs to whitespace or `>`. Built state by state
	 * from the tokenizer, possessive: one way to read a tag, no backtracking.
	 */
	private const TAG_CHAR = '(?:<\?.*?\?>|<(?!\?)|[^\t\n\f\r />=<])';

	/** The first character of an attribute name, and every character of a tag name: `=` too. */
	private const TAG_FIRST = '(?:=|' . self::TAG_CHAR . ')';

	/** How many lines below a tag start an `each` rule reads to find the tag's end. */
	private const TAG_LINES = 50;

	/** Attribute names up to the next `/`, `>` or `=`: whitespace separates two, the last one may take a value. */
	private const TAG_NAMES = self::TAG_FIRST . '(?:' . self::TAG_CHAR . '|[\t\n\f\r ])*+';

	/** An unquoted value. */
	private const TAG_UNQUOTED = '(?:<\?.*?\?>|<(?!\?)|[^\t\n\f\r "\'><])(?:<\?.*?\?>|<(?!\?)|[^\t\n\f\r ><])*+';
	private const TEXT_MAX = 200;

	/** @var list<string> substrings; a foreign-host match in the same address as one is no finding (per match) */
	private array $allow;

	/** The plugins/ directory of the site root of the last scan(), relative to $cwd: where Markdown is skipped. */
	private string $markdownDir = '';

	/** @var list<array{file: string, line: int, name: string, text: string}> on… names the last scan() did not count */
	private array $notCounted = [];

	/** @var array<string,bool> distinct files read by the last scan() */
	private array $seen = [];

	/** @var list<array{dir: string, reason: string}> every other directory the last scan() did not enter */
	private array $skipped = [];

	/** Markdown files under the site's plugins/ the last scan() did not read: package documentation. */
	private int $skippedMarkdown = 0;

	/** @var list<string> directories the last scan() left out because they are this package itself */
	private array $skippedOwn = [];

	/** @param list<string> $allow substrings; a foreign-host match in the same address (address()) as one is no finding — per match, not per line */
	public function __construct(array $allow = self::DEFAULT_ALLOW)
	{
		// An empty needle matches every line in str_contains(), so drop empty entries.
		$this->allow = array_values(array_filter(array_map('strval', $allow), static fn (string $s): bool => $s !== ''));
	}

	/**
	 * The address the match at byte $offset with $length bytes stands in, '' when it stands in none:
	 * the URL that begins at the last `//` at or before the match — with the scheme right
	 * before it, if there is one — and ends at the first character no URL holds as it is
	 * (ADDRESS_STOP: whitespace, quotes, angle brackets, parentheses, ` , ; { } | \) or where the
	 * next `//` begins (its scheme left out). A stop character between the `//` and the match: the
	 * match stands in no address. Whitespace, quotes, angle brackets and parentheses alone are not
	 * enough: in a minified bundle (`https://places.googleapis.com/v1/${id}`,b=`https://fonts.googleapis.com/…`)
	 * only a backtick, a comma or a semicolon separates the Places URL of the default entry from the
	 * Google Fonts URL beside it, which it would free.
	 */
	private static function address(string $line, int $offset, int $length): string
	{
		$slashes = strrpos(substr($line, 0, $offset + 2), '//');
		if ($slashes === false) {
			return '';
		}
		$tail = $slashes + 2;
		if ($offset > $tail && strpbrk(substr($line, $tail, $offset - $tail), self::ADDRESS_STOP) !== false) {
			return '';
		}
		$start = $slashes - self::schemeBefore($line, $slashes);
		$from = max($offset + $length, $tail);
		$end = $from + strcspn($line, self::ADDRESS_STOP, $from);
		$next = strpos(substr($line, $from, $end - $from), '//');
		if ($next !== false) {
			$end = max($from, $from + $next - self::schemeBefore($line, $from + $next));
		}
		return substr($line, $start, $end - $start);
	}

	/**
	 * True when the match at byte $offset with $length bytes is allowed: one of the allow entries
	 * stands in the same address (address()) — so `--allow=gstatic.com` frees
	 * https://fonts.gstatic.com/s/x.woff2, whose match `//fonts.` does not overlap the entry; asking
	 * for an overlap alone would make such a site red — or overlaps the
	 * match (an entry that holds such a character itself, a match outside any address).
	 * Case-insensitive, like the patterns.
	 *
	 * @param list<string> $allow
	 */
	private static function allowed(string $line, int $offset, int $length, array $allow): bool
	{
		$address = self::address($line, $offset, $length);
		foreach ($allow as $needle) {
			if ($needle === '') {
				continue;
			}
			if ($address !== '' && stripos($address, $needle) !== false) {
				return true;
			}
			$from = 0;
			while (($at = stripos($line, $needle, $from)) !== false) {
				if ($at < $offset + $length && $offset < $at + strlen($needle)) {
					return true;
				}
				$from = $at + 1;
			}
		}
		return false;
	}

	/**
	 * The attributes a PHP block prints for sure through Kirby's attr() helper (`attr([...])`,
	 * `Html::attr([...])`): name => value, for the `exempt` attributes of a tag. Only a call that is the
	 * whole expression of the block counts (an attr() inside a value of another
	 * call, or in one branch of a condition, may print nothing), and only an item attrCalls() marks
	 * certain — `'src' => $x ?: null` may print no src. The value is the text of a string literal, ''
	 * for any other expression, null for a name without value (`true`, or a list item `'defer'`).
	 *
	 * @return array<string,?string>
	 */
	private static function attrCall(string $block): array
	{
		$attributes = [];
		foreach (self::attrCalls($block) as $call) {
			if (!$call['whole']) {
				continue;
			}
			foreach ($call['items'] as $item) {
				if ($item['certain'] && !array_key_exists($item['name'], $attributes)) {
					$attributes[$item['name']] = $item['value'];
				}
			}
		}
		return $attributes;
	}

	/**
	 * Every call of Kirby's attr() helper (`attr(`, `\attr(`, `Html::attr(`) in the PHP code of $text whose
	 * names can be read, read with PHP's own tokenizer: `whole` — the call is the whole
	 * expression of $text, nothing but `Html::` before it and `;` after it — and its `items` as Kirby
	 * prints them (Xml::attr()): a key with the value `null`, `false` or `[]` (`array()`) prints nothing and is
	 * left out; a list item (`'defer'`) prints its name. `certain` is false when the value may be null or
	 * false at run time (`?:`, `??`, `?->`, a `? :` branch, a `null` or `false` anywhere in it).
	 *
	 * Every form in which attr() prints a name (the table in SPEC §3.3): the
	 * names are the first argument — by position, or named `attr:` (the helper) or `name:`
	 * (`Html::attr()`), in any order among the named ones. An array literal (`[...]`, `array(...)`)
	 * gives its items. A string literal is the single form `Html::attr('style', $css, ' ')`: one item
	 * with that name and the second argument (by position or `value:`) as its value — no value prints
	 * nothing (`Html::attr('x')` is null), `true` the name alone. A first argument that is neither
	 * (`attr($attributes)`, `attr(...$a)`, `Html::attr($name, $v)`) gives no call: its names are unknown.
	 *
	 * @return list<array{whole: bool, items: list<array{name: string, value: ?string, certain: bool, line: int}>}>
	 */
	private static function attrCalls(string $text): array
	{
		$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_ECHO, T_INLINE_HTML];
		$tokens = array_values(array_filter(@token_get_all($text) ?: [], static fn ($t): bool => !is_array($t) || !in_array($t[0], $skip, true)));
		$calls = [];
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$name = is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) ? strtolower(ltrim($tokens[$i][1], '\\')) : '';
			if ($name !== 'attr' || ($tokens[$i + 1] ?? null) !== '(') {
				continue;
			}
			// Html::attr( — the class in any spelling (Html, \Kirby\Toolkit\Html …).
			$first = $i;
			if ($i >= 2 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON && is_array($tokens[$i - 2]) &&
				preg_match('~(^|\\\\)html$~i', $tokens[$i - 2][1]) === 1) {
				$first = $i - 2;
			}
			// The arguments of the call, separated by commas at depth 0, up to its closing ).
			$arguments = [];
			$argument = [];
			$depth = 0;
			for ($at = $i + 2; $at < $count; $at++) {
				$token = $tokens[$at];
				if (self::opens($token)) {
					$depth++;
				} elseif (in_array($token, [')', ']', '}'], true)) {
					if ($depth === 0) {
						break;
					}
					$depth--;
				} elseif ($token === ',' && $depth === 0) {
					$arguments[] = $argument;
					$argument = [];
					continue;
				}
				$argument[] = $token;
			}
			$arguments[] = $argument;
			$rest = array_slice($tokens, $at + 1);
			$whole = $first === 0 && ($rest === [] || $rest === [';']);
			// By position, then by name (PHP 8 named arguments: `attr: [...]`, `name: 'style'`, `value: $css`).
			$names = $value = null;
			$position = 0;
			foreach ($arguments as $argument) {
				$label = null;
				if (count($argument) >= 2 && is_array($argument[0]) && $argument[0][0] === T_STRING && $argument[1] === ':') {
					$label = strtolower($argument[0][1]);
					$argument = array_slice($argument, 2);
				}
				if ($label === 'attr' || $label === 'name' || ($label === null && $position === 0)) {
					$names = $argument;
				} elseif ($label === 'value' || ($label === null && $position === 1)) {
					$value = $argument;
				}
				if ($label === null) {
					$position++;
				}
			}
			if ($names === null || $names === []) {
				continue;
			}
			$items = [];
			if (is_array($names[0]) && $names[0][0] === T_CONSTANT_ENCAPSED_STRING && count($names) === 1) {
				// Html::attr('style', $css): the item 'style' => $css; without a value Kirby prints nothing.
				if ($value !== null && $value !== []) {
					$items = self::attrItem(array_merge($names, [[T_DOUBLE_ARROW, '=>', $names[0][2]]], $value));
				}
				$calls[] = ['whole' => $whole, 'items' => $items];
				continue;
			}
			if ($names[0] === '[') {
				$from = 1;
			} elseif (is_array($names[0]) && $names[0][0] === T_ARRAY && ($names[1] ?? null) === '(') {
				$from = 2;
			} else {
				continue;
			}
			// The items of the array literal: key => value, separated by commas at depth 0.
			$depth = 0;
			$item = [];
			$count2 = count($names);
			for ($at = $from; $at < $count2; $at++) {
				$token = $names[$at];
				if (self::opens($token)) {
					$depth++;
				} elseif (in_array($token, [')', ']', '}'], true)) {
					if ($depth === 0) {
						array_push($items, ...self::attrItem($item));
						break;
					}
					$depth--;
				} elseif ($token === ',' && $depth === 0) {
					array_push($items, ...self::attrItem($item));
					$item = [];
					continue;
				}
				$item[] = $token;
			}
			$calls[] = ['whole' => $whole, 'items' => $items];
		}
		return $calls;
	}

	/**
	 * The attributes of the start tag $tag (a match of `each`), name => value, read one after the other as
	 * the HTML tokenizer reads them — by the grammar of START_TAG: a name ends at whitespace, `/`, `>` or `=`; a value stands in
	 * double quotes, single quotes (only right behind the `=`) or none, with whitespace around the `=`; the
	 * first of two attributes with one name counts. Names in lower case, values without their quotes, null
	 * for a name without `=`. A PHP block is one character, as in START_TAG.
	 *
	 * @return array<string,?string>
	 */
	private static function attributes(string $tag, array|null &$blocks = null): array
	{
		$attributes = [];
		$blocks = [];
		$length = strlen($tag);
		// "<" and the tag name.
		$at = preg_match('~^<' . self::TAG_FIRST . '*+~s', $tag, $m) === 1 ? strlen($m[0]) : $length;
		self::phpBlocks($m[0] ?? '', $blocks);
		while (true) {
			$at += strspn($tag, "\t\n\f\r /", $at);
			// Never false behind whitespace and / but at an unterminated <? (no such tag matches `each`);
			// the guard keeps the loop finite whatever the input.
			if ($at >= $length || $tag[$at] === '>' || preg_match('~\G' . self::TAG_FIRST . self::TAG_CHAR . '*+~s', $tag, $m, 0, $at) !== 1) {
				break;
			}
			$name = strtolower($m[0]);
			self::phpBlocks($m[0], $blocks);
			$at += strlen($m[0]);
			$value = null;
			if (preg_match('~\G[\t\n\f\r ]*+=[\t\n\f\r ]*+~', $tag, $m, 0, $at) === 1) {
				$at += strlen($m[0]);
				$quote = $tag[$at] ?? '';
				if ($quote === '"' || $quote === "'") {
					$end = strpos($tag, $quote, $at + 1);
					$end = $end === false ? $length : $end;
					$value = substr($tag, $at + 1, $end - $at - 1);
					$at = $end + 1;
				} else {
					$value = preg_match('~\G' . self::TAG_UNQUOTED . '~s', $tag, $m, 0, $at) === 1 ? $m[0] : '';
					$at += strlen($value);
				}
			}
			if (!array_key_exists($name, $attributes)) {
				$attributes[$name] = $value;
			}
		}
		return $attributes;
	}

	/**
	 * One item of attr()'s array literal (tokens), as attrCalls() describes it: [] when it prints
	 * nothing for sure or has no name that can be read.
	 *
	 * @param list<mixed> $item
	 * @return list<array{name: string, value: ?string, certain: bool, line: int}>
	 */
	private static function attrItem(array $item): array
	{
		if ($item === [] || !is_array($item[0]) || $item[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
			return [];
		}
		$name = strtolower(stripcslashes(substr($item[0][1], 1, -1)));
		$line = (int)$item[0][2];
		if (count($item) === 1) {
			// A list item: Kirby prints its value as a name without value (`'defer'` → defer).
			return [['name' => $name, 'value' => null, 'certain' => true, 'line' => $line]];
		}
		if (!is_array($item[1]) || $item[1][0] !== T_DOUBLE_ARROW || count($item) < 3) {
			return [];
		}
		$value = array_slice($item, 2);
		// `[]` and `array()` (any case) are the same empty array: Kirby prints nothing.
		if ($value === ['[', ']'] || (count($value) === 3 && is_array($value[0]) && $value[0][0] === T_ARRAY && $value[1] === '(' && $value[2] === ')')) {
			return [];
		}
		if (count($value) === 1 && is_array($value[0])) {
			if ($value[0][0] === T_CONSTANT_ENCAPSED_STRING) {
				return [['name' => $name, 'value' => stripcslashes(substr($value[0][1], 1, -1)), 'certain' => true, 'line' => $line]];
			}
			$word = strtolower($value[0][1]);
			if ($value[0][0] === T_STRING && ($word === 'null' || $word === 'false')) {
				return [];
			}
			if ($value[0][0] === T_STRING && $word === 'true') {
				return [['name' => $name, 'value' => null, 'certain' => true, 'line' => $line]];
			}
		}
		$certain = true;
		foreach ($value as $token) {
			if ($token === '?' || (is_array($token) && (in_array($token[0], [T_COALESCE, T_NULLSAFE_OBJECT_OPERATOR], true) ||
				($token[0] === T_STRING && in_array(strtolower($token[1]), ['null', 'false'], true))))) {
				$certain = false;
				break;
			}
		}
		return [['name' => $name, 'value' => '', 'certain' => $certain, 'line' => $line]];
	}

	/**
	 * The attribute names Kirby's attr() helper may print in the PHP code of $text, by the line of
	 * their key (1 = the first line of $text) — for the rules that forbid an attribute (`attr`):
	 * every call, wherever it stands, and every item that is not certainly left
	 * out, a multi-line array literal included. `'style' => null` prints nothing; `'style' => $s ?: null`
	 * may print style and counts.
	 *
	 * @return array<int,list<string>>
	 */
	private static function attrNames(string $text): array
	{
		$names = [];
		if (stripos($text, 'attr') === false) {
			return $names;
		}
		foreach (self::attrCalls($text) as $call) {
			foreach ($call['items'] as $item) {
				$names[$item['line']][] = $item['name'];
			}
		}
		return $names;
	}

	/* --------------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------------- */

	/**
	 * Usage error, exit 2 (the same shape as Links::cannotRun()): every reason
	 * as an ::error:: line on $err, the usage after them, and on $out the last line every
	 * tool ends with (SPEC §3.0) — a log that shows stdout alone still says that nothing
	 * was checked.
	 *
	 * @param resource $out
	 * @param resource $err
	 * @param list<string> $reasons
	 */
	private static function cannotRun($out, $err, array $reasons, bool $usage = true): int
	{
		foreach ($reasons as $reason) {
			fwrite($err, "::error::$reason\n");
		}
		if ($usage) {
			fwrite($err, self::usage());
		}
		fwrite($out, "Genba precheck: FAILED — cannot run\n");
		return 2;
	}

	/**
	 * Allow substrings for this rule: the constructor's list for foreign-host, nothing
	 * for the others (their own `unless` patterns live in the rule).
	 *
	 * @return list<string>
	 */
	private function exceptions(string $ruleName): array
	{
		return $ruleName === self::ALLOW_RULE ? $this->allow : [];
	}

	/** True when one of the `unless` patterns matches $piece. */
	private static function exempt(string $piece, array $unless): bool
	{
		foreach ($unless as $exception) {
			if (is_string($exception) && @preg_match($exception, $piece) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the tag $tag carries one of the `exempt` attributes (name => PCRE) with a value the pattern
	 * matches — read attribute by attribute (attributes()), so a `src=` inside
	 * another attribute's value is no src attribute. An attribute without a value (`<script src>`) is none.
	 *
	 * @param array<string,mixed> $exempt
	 */
	private static function exemptAttribute(string $tag, array $exempt): bool
	{
		if ($exempt === []) {
			return false;
		}
		$attributes = self::attributes($tag, $blocks);
		// A PHP block where an attribute stands (in the tag name or an
		// attribute name, never in a value) that calls Kirby's attr() helper with an array literal
		// prints those attributes: `<script` followed by such a block with 'src' => $url is an external script.
		foreach ($blocks as $block) {
			$attributes += self::attrCall($block);
		}
		foreach ($attributes as $name => $value) {
			$pattern = $exempt[$name] ?? null;
			if (is_string($pattern) && $value !== null && @preg_match($pattern, $value) === 1) {
				return true;
			}
		}
		return false;
	}

	/** Number of distinct files read by the last scan(). */
	public function filesScanned(): int
	{
		return count($this->seen);
	}

	/**
	 * The line of a finding: file and found text are log text, each cleaned and cut on its own
	 * (Text::clean()), the line itself is not cut.
	 *
	 * @param array{rule: string, file: string, line: int, text: string} $finding
	 */
	private static function findingLine(array $finding): string
	{
		return '::error::' . Text::clean($finding['file']) . ':' . $finding['line'] . ': '
			. self::RULES[$finding['rule']]['message'] . ': ' . Text::clean($finding['text']);
	}

	private static function isAbsolute(string $path): bool
	{
		return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
	}

	/** True when $path ends in .md or .markdown, any case. */
	private static function isMarkdown(string $path): bool
	{
		return preg_match('~\.(md|markdown)$~i', $path) === 1;
	}

	/** @return list<string> one entry per line, trimmed, empty lines dropped */
	private static function lines(string $value): array
	{
		return array_values(array_filter(array_map('trim', explode("\n", $value)), static fn (string $s): bool => $s !== ''));
	}

	/** The directory skippedMarkdown() counts in, relative to the scan's $cwd ('' before a scan). */
	public function markdownDir(): string
	{
		return $this->markdownDir;
	}

	/**
	 * The line of a marker in the content: file, the marker as found (cut at 80 characters) and the line are
	 * log text, each cleaned on its own.
	 *
	 * @param array{file: string, line: int, message: string, match: string, text: string} $finding
	 */
	private static function markerLine(array $finding): string
	{
		return '::error::' . Text::clean($finding['file']) . ':' . $finding['line'] . ': ' . $finding['message']
			. " '" . Text::clean($finding['match'], 80) . "' — not for the live site: " . Text::clean($finding['text']);
	}

	/**
	 * MARKERS and the site's own patterns ($entries, from $source: `--marker` or `GENBA_MARKERS`), as
	 * markers() takes them. An entry is a regular expression without delimiters, matched in any case and as
	 * UTF-8; a `~` in it needs no escape. `none` is no pattern: it drops MARKERS, the other entries count.
	 * `error` (else null) when an entry does not compile, when it matches an empty line — it would mark
	 * every line —, or when no pattern is left.
	 *
	 * @param list<string> $entries
	 * @return array{patterns: array<string,array{pattern: string, message: string}>, error: ?string}
	 */
	private static function markerPatterns(array $entries, string $source): array
	{
		$patterns = [];
		if (!in_array('none', $entries, true)) {
			foreach (self::MARKERS as $name => $rule) {
				$patterns[$name] = ['pattern' => $rule['pattern'], 'message' => $rule['message']];
			}
		}
		foreach ($entries as $index => $entry) {
			if ($entry === 'none') {
				continue;
			}
			// `~` is the delimiter: one that is not escaped yet (an even number of backslashes before it) gets one.
			$pattern = '~' . preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\\\~', $entry) . '~iu';
			$empty = @preg_match($pattern, '');
			if ($empty === false) {
				return ['patterns' => [], 'error' => "$source: pattern does not compile: $entry"];
			}
			if ($empty === 1) {
				return ['patterns' => [], 'error' => "$source: pattern matches an empty line, so every line: $entry"];
			}
			$patterns['own-' . ($index + 1)] = ['pattern' => $pattern, 'message' => self::OWN_MARKER];
		}
		if ($patterns === []) {
			return ['patterns' => [], 'error' => "$source leaves no marker pattern (none drops the default; add a pattern or leave none out)"];
		}
		return ['patterns' => $patterns, 'error' => null];
	}

	/**
	 * The marker check of `--forbid-markers`: every content file (CONTENT_EXTENSION, any case) under $content,
	 * the content root, line by line against $patterns (name => `pattern`, `message`; MARKERS and the site's
	 * own, markerPatterns()). One finding per line, of the first pattern that matches. Directories named in
	 * CONTENT_SKIP or SKIP_ANYWHERE are not entered and are listed in `skipped`, like every directory the walk
	 * does not enter (Tree::walk()). No rendering: the markers stand in the content files, and the check runs
	 * before the upload, without Kirby. A file that is not valid UTF-8, a pattern that fails on a line
	 * (backtrack limit) and a root that does not exist throw RuntimeException: the check could not run.
	 *
	 * @param array<string,array{pattern: string, message: string}> $patterns
	 * @return array{findings: list<array{file: string, line: int, message: string, match: string, text: string}>, files: int, skipped: list<array{dir: string, reason: string}>}
	 *                                                                                                                                                                            file and dir relative to $cwd, match as found, text = the trimmed line (max 200 chars), files = the content files read
	 */
	public static function markers(string $content, string $cwd, array $patterns): array
	{
		$content = rtrim($content, '/');
		if (!is_dir($content)) {
			throw new RuntimeException('Directory not found: ' . Text::clean(self::relative($content, $cwd)));
		}
		$package = realpath(dirname(__DIR__));
		$tree = Tree::walk($content, $content, [], self::CONTENT_SKIP + self::SKIP_ANYWHERE, $cwd, $package === false ? null : $package);
		$findings = [];
		$files = 0;
		foreach ($tree['files'] as $path) {
			if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== self::CONTENT_EXTENSION) {
				continue;
			}
			$relative = self::relative($path, $cwd);
			$text = @file_get_contents($path);
			if ($text === false) {
				throw new RuntimeException('Cannot read file: ' . Text::clean($relative));
			}
			// The patterns read UTF-8 (`u`): on a broken sequence they would answer false, not "no marker".
			if (@preg_match('~~u', $text) !== 1) {
				throw new RuntimeException('Not valid UTF-8, cannot be checked for markers: ' . Text::clean($relative));
			}
			$files++;
			foreach (explode("\n", $text) as $index => $line) {
				foreach ($patterns as $pattern) {
					$found = @preg_match($pattern['pattern'], $line, $match);
					if ($found === false) {
						throw new RuntimeException('Marker pattern failed on ' . Text::clean($relative) . ':' . ($index + 1) . ' (' . preg_last_error_msg() . ')');
					}
					if ($found === 1) {
						$findings[] = ['file' => $relative, 'line' => $index + 1, 'message' => $pattern['message'],
							'match' => (string)$match[0], 'text' => mb_substr(trim($line), 0, self::TEXT_MAX)];
						break;
					}
				}
			}
		}
		usort($findings, static function (array $a, array $b): int {
			return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
		});
		$skipped = [];
		foreach ($tree['skipped'] as $directory) {
			$skipped[] = ['dir' => self::relative($directory['dir'], $cwd),
				'reason' => $directory['own'] ? "Genba's own package, its rule samples would match every rule" : $directory['reason']];
		}
		return ['findings' => $findings, 'files' => $files, 'skipped' => $skipped];
	}

	/**
	 * True when the rule fires on $line. With `each`, every tag that starts on the line (a match
	 * of `start`) is judged on its own: the line is a finding as soon as one tag matches none of
	 * the `unless` patterns (so `src=` anywhere on the line does not exempt every
	 * <script> on it) — or has no end in reach: $line may carry the lines below it,
	 * joined with "\n", and the tag (a match of `each` that begins exactly at the start) may run
	 * on over them; a start without one is a finding, never a silent pass. Without `each`, the
	 * pattern must match the line and no `unless` pattern may; $line may carry the lines below it
	 * too (`wrapped`), and only a match that begins on the line itself counts (ownMatches()). $extra are the allow substrings
	 * (foreign-host), compared without regard to case like the patterns.
	 *
	 * A rule with `attr` also fires when Kirby's attr() helper may print an attribute whose name
	 * the `attr` pattern matches: $names are those names on the line (attrNames(), read from the whole
	 * file by scan()); null reads them from $line itself (the self-test's samples).
	 *
	 * @param array<string,mixed> $rule
	 * @param list<string> $extra
	 * @param list<string>|null $names
	 */
	private static function matches(array $rule, string $line, array $extra, array|null $names = null): bool
	{
		$pattern = $rule['pattern'] ?? null;
		$each = $rule['each'] ?? null;
		$unless = $rule['unless'] ?? [];
		if (is_string($rule['attr'] ?? null)) {
			foreach ($names ?? array_merge([], ...array_values(self::attrNames($line))) as $name) {
				if (@preg_match($rule['attr'], $name) === 1 && self::named($rule, $name)) {
					return true;
				}
			}
		}
		if (is_string($each)) {
			// The line itself; what follows the first "\n" is only where a tag may end.
			$own = explode("\n", $line, 2)[0];
			if (!is_string($pattern) || @preg_match($pattern, $own) !== 1) {
				return false;
			}
			$start = is_string($rule['start'] ?? null) ? $rule['start'] : $pattern;
			if (@preg_match_all($start, $own, $starts, PREG_OFFSET_CAPTURE) === false) {
				return true;
			}
			foreach ($starts[0] as [, $offset]) {
				$found = @preg_match($each, $line, $tag, PREG_OFFSET_CAPTURE, (int)$offset);
				if ($found !== 1 || $tag[0][1] !== $offset ||
					!(self::exempt($tag[0][0], $unless) || self::exemptAttribute($tag[0][0], $rule['exempt'] ?? []))) {
					return true;
				}
			}
			return false;
		}
		if (!is_string($pattern) || @preg_match($pattern, $line) !== 1) {
			return false;
		}
		$found = self::ownMatches($pattern, $line);
		if ($found === null) {
			return true;
		}
		if ($found === []) {
			return false;
		}
		if (is_array($rule['names'] ?? null)) {
			// Only a match whose captured name is one of `names`: `once = key` beside nothing else is no
			// finding, `<div once="x" onclick="go()">` is one.
			$named = false;
			foreach ($found as $match) {
				if (self::named($rule, (string)($match['name'][0] ?? ''))) {
					$named = true;
					break;
				}
			}
			if (!$named) {
				return false;
			}
		}
		if (self::exempt($line, $unless)) {
			return false;
		}
		if ($extra === []) {
			return true;
		}
		// The allow list per match, not per line: with grep -v semantics, an
		// allowed address next to a forbidden one on the same line (a CSP header, a list of URLs)
		// hid the forbidden one. The line is free only when every match of the pattern is allowed:
		// an allow entry stands in the same address (allowed()).
		foreach ($found as $match) {
			[$text, $offset] = $match[0];
			if (!self::allowed($line, (int)$offset, strlen((string)$text), $extra)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the rule has no `names`, or $name (any case) is one of them.
	 *
	 * @param array<string,mixed> $rule
	 */
	private static function named(array $rule, string $name): bool
	{
		return !is_array($rule['names'] ?? null) || in_array(strtolower($name), $rule['names'], true);
	}

	/**
	 * The on… names the last scan() did not count as an inline handler because they are not in
	 * EVENT_HANDLERS (`var once = key`, `const ONLINE = 'online'`): file (relative to $cwd), line, the
	 * name in lower case and the trimmed line as written. run() prints each as a `--` line, so a
	 * handler the list does not know yet is seen, not passed in silence.
	 *
	 * @return list<array{file: string, line: int, name: string, text: string}>
	 */
	public function notCounted(): array
	{
		return $this->notCounted;
	}

	/** True when the token opens a bracket that a `)`, `]` or `}` closes — `{$` and `${` in a string too. */
	private static function opens(mixed $token): bool
	{
		return in_array($token, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
	}

	/**
	 * The matches of $pattern in $text that begin on its first line, as preg_match_all() gives them with
	 * PREG_SET_ORDER | PREG_OFFSET_CAPTURE; null when the pattern cannot be applied. $text may carry the
	 * lines below it (`wrapped`): a match that begins there is the finding of that line, not of this one.
	 *
	 * @return list<array<int|string, array{0: string, 1: int}>>|null
	 */
	private static function ownMatches(string $pattern, string $text): array|null
	{
		if (@preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
			return null;
		}
		$end = strcspn($text, "\n");
		return array_values(array_filter($all, static fn (array $match): bool => $match[0][1] < $end));
	}

	/**
	 * @param list<string> $argv
	 * @return array{help: bool, forbid: bool, site: ?string, public: ?string, content: ?string, allow: ?string, require: ?string, markers: list<string>, errors: list<string>}
	 */
	private static function parse(array $argv): array
	{
		$parsed = ['help' => false, 'forbid' => false, 'site' => null, 'public' => null, 'content' => null, 'allow' => null, 'require' => null,
			'markers' => [], 'errors' => []];
		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === '--help') {
				$parsed['help'] = true;
				continue;
			}
			if ($arg === '--forbid-markers') {
				$parsed['forbid'] = true;
				continue;
			}
			// Repeatable: each --marker adds one pattern.
			if (preg_match('/^--marker=(.*)$/s', $arg, $match) === 1) {
				if (trim($match[1]) === '') {
					$parsed['errors'][] = '--marker needs a value';
					continue;
				}
				$parsed['markers'][] = trim($match[1]);
				continue;
			}
			if (preg_match('/^--(site|public|content|allow|require)=(.*)$/s', $arg, $match) === 1) {
				if (trim($match[2]) === '') {
					$parsed['errors'][] = "--{$match[1]} needs a value";
					continue;
				}
				$parsed[$match[1]] = $match[2];
				continue;
			}
			$parsed['errors'][] = "Unknown argument: $arg";
		}
		// Without the switch they would check nothing, without a word.
		if (!$parsed['forbid']) {
			if ($parsed['content'] !== null) {
				$parsed['errors'][] = '--content needs --forbid-markers';
			}
			if ($parsed['markers'] !== []) {
				$parsed['errors'][] = '--marker needs --forbid-markers';
			}
		}
		return $parsed;
	}

	/**
	 * Appends the PHP blocks (`<?… ?>`) of $text — a tag name or an attribute name — to $blocks.
	 *
	 * @param list<string> $blocks
	 */
	private static function phpBlocks(string $text, array &$blocks): void
	{
		if (str_contains($text, '<?') && preg_match_all('~<\?.*?\?>~s', $text, $found) > 0) {
			array_push($blocks, ...$found[0]);
		}
	}

	private static function relative(string $path, string $cwd): string
	{
		$base = rtrim($cwd, '/') . '/';
		$relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
		return str_replace('\\', '/', $relative);
	}

	/**
	 * @param list<string> $files
	 * @return list<array{file: string, ok: bool, bytes: ?int}> bytes null when the file is missing (NOT 0)
	 */
	public static function requiredFiles(array $files, string $cwd): array
	{
		$result = [];
		foreach ($files as $file) {
			$path = self::isAbsolute($file) ? $file : rtrim($cwd, '/') . '/' . $file;
			$bytes = is_file($path) ? filesize($path) : false;
			$bytes = $bytes === false ? null : $bytes;
			$result[] = ['file' => $file, 'ok' => $bytes !== null && $bytes > 0, 'bytes' => $bytes];
		}
		return $result;
	}

	private static function resolve(string $path, string $cwd): string
	{
		return rtrim(self::isAbsolute($path) ? $path : rtrim($cwd, '/') . '/' . $path, '/');
	}

	/**
	 * CLI, SPEC §3.0 and §3.3. Exit 0 passed, 1 findings or missing required files, 2 cannot run.
	 *
	 * @param array<string,string> $env
	 * @param list<string> $argv
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $env, array $argv, string $cwd, $out, $err): int
	{
		$args = self::parse($argv);
		if ($args['errors'] !== []) {
			return self::cannotRun($out, $err, $args['errors']);
		}
		if ($args['help']) {
			fwrite($out, self::usage());
			return 0;
		}
		// Without mbstring the run would break off with "Call to undefined function mb_…()" — a
		// missing extension is "cannot run" (SPEC §3.0), said before anything runs.
		$missing = Text::missingMbstring();
		if ($missing !== null) {
			return self::cannotRun($out, $err, [$missing], false);
		}

		$failures = self::selfTestRules(self::RULES);
		if ($args['forbid']) {
			// The placeholders too, before anything is read: a broken pattern would pass every content file.
			$failures = array_merge($failures, self::selfTestRules(self::MARKERS));
		}
		if ($failures !== []) {
			foreach ($failures as $failure) {
				fwrite($out, "::error::Self-test failed: $failure — pattern broken\n");
			}
			// The last line of every exit 2 (SPEC §3.0): the reason is the
			// ::error:: line above, the last line says only that nothing ran.
			fwrite($out, "Genba precheck: FAILED — cannot run\n");
			return 2;
		}
		fwrite($out, '  ok   self-test: ' . count(self::RULES) . " rules fire on their samples and stay silent on their negative samples\n");
		if ($args['forbid']) {
			$defaults = count(self::MARKERS);
			fwrite($out, $defaults === 1 ? "  ok   self-test: 1 marker pattern fires on its samples and stays silent on its negative samples\n"
				: "  ok   self-test: $defaults marker patterns fire on their samples and stay silent on their negative samples\n");
		}

		// Allow list: argument, else GENBA_ALLOW, else default. Entries are ADDED to the default; `none` clears it.
		$allowRaw = self::setting($args['allow'], $env, 'GENBA_ALLOW');
		$allow = self::DEFAULT_ALLOW;
		if ($allowRaw !== null) {
			$items = self::split($allowRaw);
			if (in_array('none', $items, true)) {
				$allow = [];
			}
			$allow = array_values(array_unique(array_merge($allow, array_values(array_filter($items, static fn (string $i): bool => $i !== 'none')))));
		}

		// Required files: argument, else GENBA_REQUIRE_FILES, else default. A list REPLACES the
		// default; the entry `none` is no file name (like `--allow`): it drops the
		// default, the other entries count — `none,public/x.css` checks public/x.css, not a file
		// called none. `none` alone checks no file.
		$requireRaw = self::setting($args['require'], $env, 'GENBA_REQUIRE_FILES');
		$requireItems = $requireRaw === null ? [] : self::split($requireRaw);
		$required = $requireRaw === null ? self::DEFAULT_REQUIRE
			: array_values(array_filter($requireItems, static fn (string $i): bool => $i !== 'none'));
		$requireNone = in_array('none', $requireItems, true) && $required === [];
		if (!$requireNone && $requireRaw !== null && $required === []) {
			// `--require=,` would otherwise check no file at all, without saying so. The
			// reason is an output line (SPEC §3.0: stdout, with the last line); the usage
			// text goes to stderr like every other usage text.
			$source = $args['require'] !== null ? '--require' : 'GENBA_REQUIRE_FILES';
			fwrite($out, "::error::$source lists no file: '$requireRaw' (use none to switch the file check off)\n");
			fwrite($out, "Genba precheck: FAILED — cannot run\n");
			fwrite($err, self::usage());
			return 2;
		}

		// Markers (--forbid-markers, the live deploy): --marker, else GENBA_MARKERS with one pattern per line.
		// Entries are ADDED to MARKERS; `none` drops them (like --allow).
		$markerSource = $args['markers'] !== [] ? '--marker' : 'GENBA_MARKERS';
		$markerEntries = $args['markers'] !== [] ? $args['markers'] : self::lines(self::setting(null, $env, 'GENBA_MARKERS') ?? '');
		$markers = [];
		if ($args['forbid']) {
			$compiled = self::markerPatterns($markerEntries, $markerSource);
			if ($compiled['error'] !== null) {
				// A setting that cannot work, as --require=, : the reason on stdout with the last line, the usage on stderr.
				fwrite($out, '::error::' . Text::clean($compiled['error'], PHP_INT_MAX) . "\n");
				fwrite($out, "Genba precheck: FAILED — cannot run\n");
				fwrite($err, self::usage());
				return 2;
			}
			$markers = $compiled['patterns'];
		} elseif ($markerEntries !== []) {
			// A --marker without the switch is a usage error (parse()); the variable may be set for every run.
			fwrite($out, "  --   GENBA_MARKERS is set, but the content is not checked for markers (no --forbid-markers)\n");
		}

		$siteName = $args['site'] ?? 'site';
		$publicName = $args['public'] ?? 'public';
		$contentName = $args['content'] ?? 'content';
		$siteDir = self::resolve($siteName, $cwd);
		$publicDir = self::resolve($publicName, $cwd);
		$contentDir = self::resolve($contentName, $cwd);
		$roots = ['site' => [$siteDir, $siteName], 'public' => [$publicDir, $publicName]];
		if ($args['forbid']) {
			$roots['content'] = [$contentDir, $contentName];
		}
		foreach ($roots as [$dir, $given]) {
			if (!is_dir($dir)) {
				fwrite($out, "::error::Directory not found: $given\n");
				fwrite($out, "Genba precheck: FAILED — cannot run\n");
				return 2;
			}
		}

		// Every line below that holds something from the site — a directory or file name,
		// the found line itself, the message of a directory or file that could not be read —
		// is log text (the same rule as genba-links and genba-smoke): control
		// characters become spaces, so <p style="x"> followed by ESC[2K and CR cannot paint
		// "Genba precheck: PASSED" over the finding, and a line break in a file name cannot
		// start a forged ::error:: line. Each piece is cleaned and cut on its own, the
		// line is not cut — cutting the whole line would leave a path above 2048 characters as
		// its first 60, without the line number and the message (as in genba-links).
		$precheck = new self($allow);
		try {
			$findings = $precheck->scan(['site' => $siteDir, 'public' => $publicDir], $cwd);
			$marked = $args['forbid'] ? self::markers($contentDir, $cwd, $markers) : null;
		} catch (RuntimeException $e) {
			// The message is built of cleaned pieces (scan(), Tree::walk()).
			fwrite($out, '::error::' . Text::clean($e->getMessage(), PHP_INT_MAX) . "\n");
			fwrite($out, "Genba precheck: FAILED — cannot run\n");
			return 2;
		}
		foreach ($precheck->skippedOwn() as $directory) {
			fwrite($out, self::skippedLine($directory, "Genba's own package, its rule samples would match every rule") . "\n");
		}
		foreach ($precheck->skipped() as $directory) {
			fwrite($out, self::skippedLine($directory['dir'], $directory['reason']) . "\n");
		}
		$markdown = $precheck->skippedMarkdown();
		if ($markdown > 0) {
			fwrite($out, "  --   not checked: $markdown Markdown " . ($markdown === 1 ? 'file' : 'files') . ' in '
				. Text::clean($precheck->markdownDir()) . " (package documentation)\n");
		}
		foreach ($precheck->notCounted() as $uncounted) {
			fwrite($out, '  --   not counted: ' . Text::clean($uncounted['file']) . ':' . $uncounted['line'] . ": '"
				. $uncounted['name'] . "' is not an event handler Genba knows: " . Text::clean($uncounted['text']) . "\n");
		}
		foreach ($marked['skipped'] ?? [] as $directory) {
			fwrite($out, self::skippedLine($directory['dir'], $directory['reason']) . "\n");
		}
		$scanned = $precheck->filesScanned();
		if ($scanned === 0) {
			fwrite($out, "::error::No files found under $siteName, $publicName\n");
			fwrite($out, "Genba precheck: FAILED — cannot run\n");
			return 2;
		}
		if ($marked !== null && $marked['files'] === 0) {
			// No content file read is no "no markers": a wrong --content must not pass.
			fwrite($out, '::error::No content files (*.' . self::CONTENT_EXTENSION . ') found under ' . Text::clean($contentName) . "\n");
			fwrite($out, "Genba precheck: FAILED — cannot run\n");
			return 2;
		}

		foreach ($findings as $finding) {
			fwrite($out, self::findingLine($finding) . "\n");
		}

		if ($requireNone) {
			// Named by where the none came from (as in genba-preflight): --require=none must
			// not read as GENBA_REQUIRE_FILES=none, a variable that may not be set.
			$source = $args['require'] !== null ? '--require=none' : 'GENBA_REQUIRE_FILES=none';
			fwrite($out, "  --   no required files configured ($source)\n");
		}
		$missing = 0;
		foreach (self::requiredFiles($required, $cwd) as $check) {
			if ($check['ok']) {
				fwrite($out, "  ok   {$check['file']} ({$check['bytes']} bytes)\n");
			} else {
				$missing++;
				fwrite($out, "::error::Required file missing or empty: {$check['file']}\n");
			}
		}

		$marks = 0;
		if ($marked !== null) {
			foreach ($marked['findings'] as $finding) {
				fwrite($out, self::markerLine($finding) . "\n");
			}
			$marks = count($marked['findings']);
			if ($marks === 0) {
				$files = $marked['files'];
				$patterns = count($markers);
				fwrite($out, "  ok   no markers in $files content " . ($files === 1 ? 'file' : 'files')
					. " ($patterns " . ($patterns === 1 ? 'pattern' : 'patterns') . ")\n");
			}
		}

		$count = count($findings);
		if ($count === 0 && $missing === 0 && $marks === 0) {
			fwrite($out, "Genba precheck: PASSED ($scanned files scanned)\n");
			return 0;
		}
		$parts = [];
		if ($count > 0) {
			$parts[] = "$count findings";
		}
		if ($missing > 0) {
			$parts[] = "$missing required files missing";
		}
		if ($marks > 0) {
			$parts[] = "$marks markers in the content";
		}
		fwrite($out, 'Genba precheck: FAILED — ' . implode(', ', $parts) . "\n");
		return 1;
	}

	/**
	 * Scans the files under $roots (['site' => '/abs/site', 'public' => '/abs/public']); a root that does not exist
	 * throws RuntimeException("Directory not found: site"). Files with a NUL byte in the first 8 KiB are skipped as binary.
	 *
	 * @param array<string,string> $roots
	 * @return list<array{rule: string, file: string, line: int, text: string}> file relative to $cwd, text = the trimmed line (max 200 chars)
	 */
	public function scan(array $roots, string $cwd): array
	{
		$this->seen = [];
		$this->skippedOwn = [];
		$this->skipped = [];
		$this->skippedMarkdown = 0;
		$this->markdownDir = '';
		$this->notCounted = [];
		$findings = [];
		$package = realpath(dirname(__DIR__));
		// One set of real paths for both roots: a file reached again through a link
		// (public/p -> ..) is read once per run, not once per root.
		$read = [];
		foreach ($roots as $name => $root) {
			$root = rtrim($root, '/');
			if (!is_dir($root)) {
				throw new RuntimeException("Directory not found: $name");
			}
			$rules = [];
			foreach (self::RULES as $ruleName => $rule) {
				if (in_array($name, $rule['roots'], true)) {
					$rules[$ruleName] = $rule;
				}
			}
			if ($rules === []) {
				continue;
			}
			// The skip places of the other roots too: a link such as public/p -> .. must not
			// reach site/cache through the public walk.
			$elsewhere = [];
			foreach ($roots as $otherName => $otherRoot) {
				if ($otherName !== $name) {
					foreach (self::SKIP_ROOT_DIRS[$otherName] ?? [] as $dir => $reason) {
						$elsewhere[rtrim($otherRoot, '/') . '/' . $dir] = $reason;
					}
				}
			}
			$tree = Tree::walk($root, $root, self::SKIP_ROOT_DIRS[$name] ?? [], self::SKIP_ANYWHERE, $cwd, $package === false ? null : $package, $elsewhere, $read);
			$plugins = $root . '/plugins/';
			// The real plugins/ directory: a file only counts as package documentation when it really lies
			// there — a link plugins/docs -> ../snippets or plugins/x/a.md -> ../../snippets/legal.md
			// must not take the site's own Markdown out of the check.
			$pluginsReal = realpath($root . '/plugins');
			if ($name === 'site') {
				$this->markdownDir = self::relative($root . '/plugins', $cwd);
			}
			foreach ($tree['files'] as $path) {
				$relative = self::relative($path, $cwd);
				// Package documentation: a Markdown file under the site's plugins/, by its name and by its
				// real path, which lies in the real plugins/ too (a link named .md to a template is read, and
				// so is site Markdown reached through a link below plugins/).
				$real = realpath($path);
				if ($name === 'site' && $pluginsReal !== false && $real !== false && str_starts_with($path, $plugins) &&
					str_starts_with($real, $pluginsReal . '/') && self::isMarkdown($path) && self::isMarkdown($real)) {
					$this->skippedMarkdown++;
					continue;
				}
				$handle = fopen($path, 'rb');
				if ($handle === false) {
					throw new RuntimeException('Cannot read file: ' . Text::clean($relative));
				}
				$head = fread($handle, self::BINARY_HEAD);
				fclose($handle);
				if ($head === false) {
					throw new RuntimeException('Cannot read file: ' . Text::clean($relative));
				}
				if (str_contains($head, "\0")) {
					continue;
				}
				$lines = file($path, FILE_IGNORE_NEW_LINES);
				if ($lines === false) {
					throw new RuntimeException('Cannot read file: ' . Text::clean($relative));
				}
				$this->seen[$relative] = true;
				// What Kirby's attr() helper prints, by line, read once per file with PHP's tokenizer —
				// an array literal over several lines included.
				$printed = self::attrNames(implode("\n", $lines));
				// The rules read a PHP file without its comments (blanked, the line numbers kept): a
				// comment is never printed. The finding shows the line as written.
				$written = $lines;
				if (preg_match('~\.(php|phtml)$~i', $path) === 1) {
					$lines = explode("\n", self::uncomment(implode("\n", $lines)));
				}
				foreach ($lines as $index => $line) {
					foreach ($rules as $ruleName => $rule) {
						$text = $line;
						if (isset($rule['each']) && @preg_match($rule['pattern'], $line) === 1) {
							// The lines below, for a tag that runs on.
							$text = implode("\n", array_slice($lines, $index, self::TAG_LINES + 1));
						} elseif (($rule['wrapped'] ?? false) === true && preg_match('~[a-z]\s*$~i', $line) === 1) {
							// A name at the end of the line: its = may stand on a line below.
							$text = self::wrapped($lines, $index);
						}
						if (!self::matches($rule, $text, $this->exceptions($ruleName), $printed[$index + 1] ?? [])) {
							// An on… name the rule does not count is said, once per name and line.
							foreach (self::uncounted($rule, $text, $printed[$index + 1] ?? []) as $uncounted) {
								$this->notCounted[] = ['file' => $relative, 'line' => $index + 1, 'name' => $uncounted,
									'text' => mb_substr(trim($written[$index] ?? $line), 0, self::TEXT_MAX)];
							}
							continue;
						}
						$findings[] = [
							'rule' => $ruleName,
							'file' => $relative,
							'line' => $index + 1,
							'text' => mb_substr(trim($written[$index] ?? $line), 0, self::TEXT_MAX),
						];
					}
				}
			}
			foreach ($tree['skipped'] as $directory) {
				if ($directory['own']) {
					$this->skippedOwn[] = self::relative($directory['dir'], $cwd);
				} else {
					$this->skipped[] = ['dir' => self::relative($directory['dir'], $cwd), 'reason' => $directory['reason']];
				}
			}
		}
		usort($findings, static function (array $a, array $b): int {
			return [$a['file'], $a['line']] <=> [$b['file'], $b['line']];
		});
		usort($this->notCounted, static function (array $a, array $b): int {
			return [$a['file'], $a['line'], $a['name']] <=> [$b['file'], $b['line'], $b['name']];
		});
		return $findings;
	}

	/** The length of the scheme and its `:` right before byte $at (a `//`), 0 when there is none; at most 32 characters are looked at. */
	private static function schemeBefore(string $line, int $at): int
	{
		if ($at < 2 || $line[$at - 1] !== ':') {
			return 0;
		}
		$from = $at - 1;
		while ($from > 0 && $at - 1 - $from < 32 && (ctype_alnum($line[$from - 1]) || str_contains('+.-', $line[$from - 1]))) {
			$from--;
		}
		// A scheme starts with a letter: a digit, '+', '.' or '-' in front are no part of it.
		while ($from < $at - 1 && !ctype_alpha($line[$from])) {
			$from++;
		}
		return $from < $at - 1 ? $at - $from : 0;
	}

	/** selfTestRules(self::RULES): list<string> failures ('rule <name> did not fire on its sample'); [] = all rules work. */
	public function selfTest(): array
	{
		return self::selfTestRules(self::RULES);
	}

	/**
	 * Same, for any rule table (tests pass a deliberately broken copy). A pattern that does not compile is a failure too.
	 *
	 * @param array<string,mixed> $rules
	 * @return list<string>
	 */
	public static function selfTestRules(array $rules): array
	{
		$failures = [];
		foreach ($rules as $name => $rule) {
			$pattern = is_array($rule) ? ($rule['pattern'] ?? null) : null;
			$sample = is_array($rule) ? ($rule['sample'] ?? null) : null;
			if (!is_string($pattern) || !is_string($sample)) {
				$failures[] = "rule $name has no pattern or sample";
				continue;
			}
			if (@preg_match($pattern, '') === false) {
				$failures[] = "rule $name: pattern does not compile";
				continue;
			}
			if (array_key_exists('names', $rule) && (!is_array($rule['names']) || $rule['names'] === [] ||
				array_filter($rule['names'], static fn ($n): bool => !is_string($n) || $n === '' || $n !== strtolower($n)) !== [])) {
				$failures[] = "rule $name: names is not a list of lower-case names";
				continue;
			}
			// `names` judges the name a match captures: a pattern without the group would count no match,
			// and an `each` rule judges tags, where `names` would be ignored without a word.
			if (array_key_exists('names', $rule) && (!str_contains($pattern, '(?<name>') || isset($rule['each']))) {
				$failures[] = "rule $name: names needs a pattern with a (?<name>…) group and no each";
				continue;
			}
			if (!self::matches($rule, $sample, [])) {
				$failures[] = "rule $name did not fire on its sample";
			}
			$positives = $rule['positives'] ?? [];
			if (!is_array($positives)) {
				$failures[] = "rule $name: positives is not a list";
				continue;
			}
			// The rule of the allow list also with the default allow list: a positive
			// sample there is a forbidden address next to an allowed one, which must stay a finding.
			$allowLists = $name === self::ALLOW_RULE ? [[], self::DEFAULT_ALLOW] : [[]];
			foreach ($positives as $positive) {
				foreach ($allowLists as $allowList) {
					if (!is_string($positive) || !self::matches($rule, $positive, $allowList)) {
						$failures[] = "rule $name did not fire on its positive sample '" . (is_string($positive) ? $positive : gettype($positive)) . "'"
							. ($allowList === [] ? '' : ' with the default allow list');
						break;
					}
				}
			}
			$negatives = $rule['negatives'] ?? [];
			if (!is_array($negatives)) {
				$failures[] = "rule $name: negatives is not a list";
				continue;
			}
			// The whole rule (pattern, each, unless), not the pattern alone: <script src="x">
			// matches the pattern and must still not fire.
			foreach ($negatives as $negative) {
				if (!is_string($negative) || self::matches($rule, $negative, [])) {
					$failures[] = "rule $name fired on its negative sample '" . (is_string($negative) ? $negative : gettype($negative)) . "'";
				}
			}
		}
		return $failures;
	}

	/** Argument value first, then the environment variable (empty counts as unset), else null. */
	private static function setting(string|null $argument, array $env, string $name): string|null
	{
		if ($argument !== null) {
			return $argument;
		}
		$value = $env[$name] ?? null;
		return is_string($value) && trim($value) !== '' ? $value : null;
	}

	/**
	 * Directories the last scan() did not enter for any other reason: a skipped place
	 * (site/cache, public/media, node_modules, .git …) or a symbolic link it does not follow
	 * (target outside the project, already scanned, broken). Relative to the scan's $cwd.
	 *
	 * @return list<array{dir: string, reason: string}>
	 */
	public function skipped(): array
	{
		return $this->skipped;
	}

	/** The `--   not checked` line of a directory: the directory and the reason cleaned on their own. */
	private static function skippedLine(string $directory, string $reason): string
	{
		return '  --   not checked: ' . Text::clean($directory) . ' (' . Text::clean($reason) . ')';
	}

	/**
	 * How many Markdown files (`.md`, `.markdown`) under the site root's `plugins/` the last scan() did
	 * not read — package documentation: a package's README that documents the rules (`<script>`,
	 * `style=`) would otherwise fail every site that installs it. Only when the file and the target of a
	 * symbolic link are both Markdown: `plugins/x/a.md -> ../../templates/default.php` is read. Markdown
	 * anywhere else is read like any file — a template may print one (`kirbytext(F::read(…))`).
	 */
	public function skippedMarkdown(): int
	{
		return $this->skippedMarkdown;
	}

	/**
	 * Directories the last scan() did not read because they are this package
	 * (site/plugins/genba/ in a site): its rule samples and its README would
	 * match every rule. Relative to the scan's $cwd.
	 *
	 * @return list<string>
	 */
	public function skippedOwn(): array
	{
		return $this->skippedOwn;
	}

	/** @return list<string> comma list, trimmed, empty entries dropped */
	private static function split(string $value): array
	{
		return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== ''));
	}

	/**
	 * $text with the text of every PHP comment (`//`, `#`, block and doc comments) blanked: each byte but a
	 * line break becomes a space, so every line keeps its number. Read with PHP's tokenizer, which knows
	 * where PHP code starts and ends: a `/*` in a string or in the HTML around the code is no comment,
	 * and an HTML comment (`<!-- -->`) is sent to the browser and stays.
	 */
	private static function uncomment(string $text): string
	{
		// A bare `<?` (not `<?php`, not `<?=`) opens PHP only where short_open_tag is on — and the PHP
		// that runs this check need not be the server's. Such a file is read as it is: the louder way.
		if (!str_contains($text, '<?') || preg_match('~<\?(?!php(?:\s|$)|=)~i', $text) === 1) {
			return $text;
		}
		$result = '';
		foreach (@token_get_all($text) ?: [] as $token) {
			if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
				$result .= (string)preg_replace('~[^\n]~', ' ', $token[1]);
			} else {
				$result .= is_array($token) ? $token[1] : $token;
			}
		}
		return $result;
	}

	/**
	 * The names, lower case and once each, that the rule's pattern or the attr() names ($printed) on
	 * $line carry but `names` does not hold — [] for a rule without `names`.
	 *
	 * @param array<string,mixed> $rule
	 * @param list<string> $printed
	 * @return list<string>
	 */
	private static function uncounted(array $rule, string $line, array $printed): array
	{
		if (!is_array($rule['names'] ?? null) || !is_string($rule['pattern'] ?? null)) {
			return [];
		}
		$found = [];
		foreach (self::ownMatches($rule['pattern'], $line) ?? [] as $match) {
			$found[] = (string)($match['name'][0] ?? '');
		}
		if (is_string($rule['attr'] ?? null)) {
			foreach ($printed as $name) {
				if (@preg_match($rule['attr'], $name) === 1) {
					$found[] = $name;
				}
			}
		}
		$names = [];
		foreach ($found as $name) {
			$name = strtolower($name);
			if (!self::named($rule, $name) && !in_array($name, $names, true)) {
				$names[] = $name;
			}
		}
		return $names;
	}

	private static function usage(): string
	{
		return <<<'TEXT'
        Usage: genba-precheck [--site=DIR] [--public=DIR] [--allow=a,b] [--require=f1,f2]
                              [--forbid-markers [--content=DIR] [--marker=PATTERN ...]] [--help]

          --site=DIR        site root to scan for inline styles, inline handlers and embedded scripts (default: site)
          --public=DIR      public root to scan for foreign hosts (default: public)
          --allow=a,b       substrings that free a foreign-host match in the same address (not the
                            whole line), ADDED to the default
                            (places.googleapis.com/v1/); the literal 'none' clears the default
          --require=f1,f2   files that must exist and be non-empty (default: public/assets/css/tailwind.css);
                            the literal 'none' checks no file; next to files it only drops the default
          --forbid-markers  also check the content for placeholders that must not go live ([TODO], [TBD],
                            [OFFEN] …): for the live deploy, a preview leaves it out
          --content=DIR     content root for --forbid-markers (default: content); its *.txt files are
                            read, drafts (_drafts) are not
          --marker=PATTERN  a further marker for --forbid-markers: a regular expression without delimiters,
                            any case, ADDED to the default; repeatable; the literal 'none' drops the default

        Environment (used when the matching argument is absent):
          GENBA_ALLOW          same as --allow
          GENBA_REQUIRE_FILES  same as --require
          GENBA_MARKERS        same as --marker, one pattern per line

        Exit codes: 0 passed, 1 findings, missing required files or markers in the content,
        2 cannot run (usage error, missing directory, failed self-test).

        TEXT;
	}

	/**
	 * Line $index with the lines below it, joined with "\n", up to the first that is not blank and at most
	 * TAG_LINES further: where the = of an attribute whose name ends the line may stand — the tokenizer
	 * reads any whitespace between a name and its =, line breaks included.
	 *
	 * @param list<string> $lines
	 */
	private static function wrapped(array $lines, int $index): string
	{
		$text = $lines[$index];
		$last = min(count($lines) - 1, $index + self::TAG_LINES);
		for ($i = $index + 1; $i <= $last; $i++) {
			$text .= "\n" . $lines[$i];
			if (trim($lines[$i]) !== '') {
				break;
			}
		}
		return $text;
	}
}
