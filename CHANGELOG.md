# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to a message that the tests grep for, to an exit code, to an
  environment variable name, or to the `<meta name="build">` output** is a
  **major** version. Sites and their workflows match on these; a routine update
  must not change what a check reports or how a workflow reads its result.
- **A change to the privacy text** (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the package must then review its privacy
  policy.

## [1.0.0] — 2026-10-14

First release as a Composer package. Before 1.0.0 the same operations lived as
copies in every site built from kirby-starter: `site/snippets/stand.php` (the
build stamp), `scripts/smoke-test.sh` (300 lines), `scripts/deploy-vorpruefung.sh`
(pre-deploy checks), `scripts/pruefe-fremdlinks.php` (552 lines, the link check),
the matching tests (`tests/StandTest.php`, `tests/smoke-test-gegenprobe.sh` with
174 lines, `tests/FremdlinksTest.php`), the `praeflug` npm step and the inline
workflow steps for the stamp and the stylesheet. The changes below are measured
against those copies in kirby-starter.

### Changed — renamed to English (breaking)

Environment variables, options, snippets, tools and messages are English now.
There are no fallbacks for the old names: a site switching from the copies
updates its workflows and calls once, using the table below.

| Before (site copy) | Now |
|---|---|
| `site/snippets/stand.php`, `snippet('stand')` | `snippet('genba/stamp')` + `'genba.active' => true` |
| `git rev-parse HEAD > site/config/build.txt` (+ grep) | `vendor/bin/genba-stamp` |
| `npm run praeflug` | `vendor/bin/genba-preflight` (runs `praeflug`/`preflight` from package.json) |
| `test -s public/assets/css/tailwind.css` | part of `vendor/bin/genba-precheck` (`GENBA_REQUIRE_FILES`) |
| `bash scripts/deploy-vorpruefung.sh` | `vendor/bin/genba-precheck` |
| `ERWARTETER_STAND=$(git rev-parse HEAD) bash scripts/smoke-test.sh` | `vendor/bin/genba-smoke` |
| env `URL`, `ERWARTETER_STAND`, `MARKER`, `SMOKE_BENUTZER`, `SMOKE_PASSWORT`, `HTTPS_UMLEITUNG` (pflicht/warnen/aus), `PANEL_KONTO`, `SEITEN_MAX`, `VERSUCHE`, `PAUSE`, `SMOKE_HTTP_URL` | `GENBA_URL`, `GENBA_EXPECTED_BUILD`, `GENBA_MARKER`, `GENBA_USER`, `GENBA_PASSWORD`, `GENBA_HTTPS_REDIRECT` (require/warn/off), `GENBA_PANEL_ACCOUNT`, `GENBA_PAGES_MAX`, `GENBA_RETRIES`, `GENBA_PAUSE`, `GENBA_HTTP_URL` |
| workflow: "secrets missing → warning, exit 0" shell block | `GENBA_WITHOUT_CREDENTIALS: skip` |
| `php scripts/pruefe-fremdlinks.php --digistore-feld= --youtube-feld= --shop-host= --ohne-gegenprobe --nur-auflisten --oembed-basis= --digistore-basis= --pause=` | `vendor/bin/genba-links --digistore-field= --youtube-field= --shop-host= --skip-counter-check --list-only --oembed-base= --digistore-base= --pause=` |
| `tests/StandTest.php`, `tests/smoke-test-gegenprobe.sh`, `tests/FremdlinksTest.php` in every site | run once here (`tests/StampTest.php`, `SmokeTest.php`, `LinksTest.php`); a site deletes its copies |
| `<meta name="build">` | unchanged (the fleet check reads it) |
| `site/config/build.txt` | unchanged path, unchanged content |

### Added

- Package `sayamaapps/kirby-genba`: Kirby plugin id `sayamaapps/genba`, PHP
  namespace `Genba\`, installed to `site/plugins/genba/`, and five tools linked
  into `vendor/bin/`: `genba-stamp`, `genba-preflight`, `genba-precheck`,
  `genba-smoke`, `genba-links`.
- **Exit code 2 for "cannot run"**, in every tool: usage errors, missing
  directories, tools or settings, and failed self-tests. A check that cannot run
  is never reported as passed.
- **`GENBA_WITHOUT_CREDENTIALS`** (`genba-smoke`, `fail` or `skip`): what a
  protected host that answers 401 without credentials means. `skip` ends the run
  with a visible `SKIPPED` line and exit 0.
- **`GENBA_FOREIGN_SITEMAP`** (`genba-smoke`, `rewrite`, `warn` or `fail`): a
  sitemap entry on another origin, for staging hosts whose site URL is
  production. `rewrite` fetches the same path and query on the `GENBA_URL` origin.
  Unset, the default is `rewrite` on a preview run (`GENBA_EXPECT_PROTECTED=1` or
  `GENBA_USER` set) and `fail` otherwise; the log names the default taken.
- **`GENBA_SITEMAP_ORIGIN`** (`genba-smoke`, optional): the one foreign origin a
  sitemap may list (production). Only it is rewritten; an entry on any third
  origin is a finding.
- **`GENBA_EXPECT_PROTECTED`** and **`GENBA_NOT_READY`** (`genba-smoke`): the
  reachability gate of the preview workflows — 401 without credentials, DNS or
  certificate still pending as a visible SKIPPED.
- **`GENBA_PRIVATE_PATHS`** (`genba-smoke`): nothing above `public/` may answer
  2xx; on by default with a list of 17 paths, entries are added, `none` replaces
  or switches it off.
- **`GENBA_META`** (`genba-smoke`) and the option `genba.stamp.meta`: the name of
  the `<meta>` element, default `build`.
- **`GENBA_REQUIRE_FILES`** (`genba-precheck`, `none` turns it off) and
  **`GENBA_ALLOW`** (`genba-precheck`, added to the default allow entry, `none`
  clears it).
- **Self-test per rule**: every pre-deploy rule must match its sample line, and
  must not match its negative samples, before the scan runs;
  `Precheck::selfTestRules()` for tests of the rule table.
- **Snippet `genba/build-info`**: a visible build line with `genba-*` classes, for
  preview footers. Optional.
- **Site methods** `$site->genbaBuild()` and `$site->genbaBuildInfo()`, and the
  helper `genba_option()`.
- **Options** `genba.active`, `genba.stamp.file`, `genba.stamp.meta` and
  `genba.language`, with the translations `genba.build.*` and `genba.panel.build.*`
  (English and German).
- **Tests** run once in this package: `StampTest.php`, `PreflightTest.php`,
  `KirbyTest.php`, `PrecheckTest.php`, `SmokeTest.php` (against PHP's built-in
  server), `HomepageTest.php`, `LinksTest.php`, `MbstringTest.php`,
  `I18nTest.php`, `HtmlCorpusTest.php` (one homepage and one template in many
  spellings a browser reads the same) and `MessagesTest.php` (every line the
  tools print stands in the README).

### Changed behaviour

These changes against the site copies are deliberate. Each one can show on the
site after the switch. The README describes every rule in full.

#### All tools

1. **All messages are English.**
2. **"Cannot run" is exit 2, never a finding and never a pass.** A setting with
   a wrong value, an unknown argument (`Unknown argument: <argument>` in all five
   tools), an option without a value (`--digistore-field=`, `--hook=`, `--sha=`),
   an empty URL or path argument (an unset `${{ vars.X }}` in
   `vendor/bin/genba-smoke "${{ vars.PREVIEW_URL }}"`), a missing `git`, `curl` or
   `sh`, and a PHP without `mbstring` (`PHP extension mbstring is missing`) stop
   the run before it checks anything — never with a PHP error in the middle of
   a run. An argument error wins over `--help`, whatever the order. Every exit 2
   ends with `Genba <tool>: FAILED — cannot run` on stdout, the `::error::` reason
   and the usage on stderr, so a log that shows stdout alone still says that
   nothing was checked.
3. **Everything that comes from the checked site or from another server is log
   text.** Sitemap entries, `href`, `src`, `srcset`, a `url()` in a stylesheet,
   headers, redirect targets, the build hash in the `<meta>`, page and oEmbed
   titles, the error texts of curl and git, and the file names, places and found
   lines of `genba-precheck` and `genba-links` pass through one filter: C0
   control characters (line breaks included), DEL and C1 control characters —
   also as a raw byte 0x80–0x9F outside a UTF-8 character — are dropped or become
   spaces, so an escape sequence or a carriage return cannot paint
   `Genba smoke: PASSED.` over a finding or forge an annotation. A value above
   2048 characters is cut to its first 60 with its length, each piece of a line
   on its own (path, found text, URL, title, chain, reason); the line itself is
   never cut.

#### genba-stamp

1. **`genba-stamp` writes atomically and never through a symlink**: temp file
   plus `rename()`, then the file is read back; a stamp file that is a symbolic
   link is refused (exit 1).
2. **A missing git is "cannot run"** (`::error::git not found`, exit 2). A working
   directory that is no git checkout stays exit 1.
3. **`genbaBuildInfo()['file']` is relative to the project root**
   (`site/config/build.txt`), not the absolute server path.

#### genba-precheck

1. **The pre-deploy checks moved from bash to PHP.** The result no longer depends
   on whether `grep` is the BSD or the GNU variant, and the self-test no longer
   writes to `/tmp`. Every rule must match its samples and must not match its
   negative samples before the scan runs; a negative sample is judged with the
   whole rule, exceptions included.
2. **The stylesheet check runs in `genba-precheck`**, after the build, through
   `GENBA_REQUIRE_FILES`. A site without Tailwind sets
   `GENBA_REQUIRE_FILES=none`, and the log says so, naming where the `none` came
   from (`--require=none` or `GENBA_REQUIRE_FILES=none`).
3. **The check ships the Koe allow entry** (`places.googleapis.com/v1/`) by
   default, and uses the anchored `//fonts\.` pattern that all site copies use by
   now. The negative control of one site copy is kept: the foreign-host rule must
   not match `document.fonts.ready.then(remeasure)`, or the self-test fails. A
   site whose workflow ran only the style and host rules
   (`scripts/pruefe-ressourcen.sh`) now gets the handler and script rules too.
4. **The rules ignore case**: `<P STYLE="x">`, `<SCRIPT>`, `ONCLICK=` and
   `FONTS.GSTATIC.COM` are found; `<SCRIPT SRC="x">` and the JSON-LD type are
   still exceptions. The copies matched lower case only.
5. **Inline styles and handlers are found in every spelling the browser
   accepts**: `style='x'`, `style = "x"`, `style=color:red` without quotes, an
   attribute at the start of a line, right after a closing quote or after a `/`
   (`<p class="a"style="…">`, `<img src="x"/onerror="…">`), and any `on<event>=`,
   not only `style="` and four event names. A PHP variable `$style = 'card'`, a
   `data-style` attribute, `icon=` or `json=` are not findings; the rules carry
   them as negative samples.
6. **`embedded-script` judges every `<script …>` tag on its own, attribute by
   attribute.** The copies exempted the whole line when `src=` or
   `application/ld+json` stood anywhere in it, so
   `<script src="/a.js"></script><script>window.evil = 1</script>` and
   `<script>el.src="/x.js"</script>` passed. Only a real `src` attribute with a
   value, or a `type` that holds `application/ld+json`, exempts a tag —
   `data-src` or `src=` inside another attribute's value do not. A tag ends at
   the first `>` outside a quoted value and outside a PHP block (a Kirby
   `nonce="<?= $kirby->nonce() ?>"` does not end it), a tag that a formatter
   wrapped over several lines is read on to its `>` (up to 50 lines; a
   `<script` without one in reach is a finding), and a quote opens a value only
   behind the `=`, as in the browser's tokenizer.
7. **Kirby's `attr()` helper prints attributes too.** `inline-style` and
   `inline-handler` read every `attr()` / `Html::attr()` call in every form that
   prints a name — an array literal (over several lines included), a named
   argument (`attr:`, `name:`) and the single form
   `Html::attr('style', $css, ' ')`: `<div<?= attr(['style' => …], ' ') ?>>` is a
   finding, reported on the line of the key; `null`, `false` and `[]` print
   nothing, as in Kirby. The other way round, a `src` exempts a script only when
   `attr()` prints it for sure (`'src' => $x ?: null`, an `attr()` in a branch of
   a condition or inside another call's value exempt nothing).
8. **The allow list frees an address, not a line**: an entry frees every
   foreign-host match in the same address, so a forbidden host next to an allowed
   one on the same line is still a finding, and `--allow=gstatic.com` frees
   `https://fonts.gstatic.com/s/x.woff2`. An address begins at the last `//`
   before the match and ends at the first character no URL holds as it is
   (whitespace, quotes, `<`, `>`, parentheses, `` ` ``, `,`, `;`, `{`, `}`, `|`,
   `\`) or at the next `//` — two addresses in a minified bundle stay two. The
   self-test also runs the rule's positive samples with the default allow list.
9. **The check skips by place and names what it did not read**: `site/cache`,
   `site/sessions`, `site/accounts`, `site/logs` and `public/media` (only there,
   by place: `site/snippets/media/` is scanned), `node_modules` and `.git` at any
   depth, binary files, and Genba's own directory (`site/plugins/genba/`), whose
   source and README carry the rule samples. The copies scanned them. Symbolic
   links inside the project are followed, but never into a skipped place
   (compared by real path, also across the two roots: `public/p -> ..` does not
   reach `site/cache`); a symbolic link to a file outside the project is named
   and never read, and a file reached through two paths is read once per run.
   Every directory not entered is named with a
   `--   not checked: <dir> (<reason>)` line, an entry without search permission
   as `cannot be read (permission denied)`, and `Cannot read directory: <dir>`
   names the directory relative to the working directory.

#### genba-smoke

1. **The homepage is read by an HTML5 parser — breaking for the runner.** curl
   and bash still fetch, but the saved homepage is read by
   `bin/genba-smoke-read` with PHP's `Dom\HTMLDocument`, as a browser reads it,
   instead of being cut apart line by line with grep. Quotes double, single or
   none, capitals, attributes over several lines and in any order, comments, the
   text of `<script>`, `<style>`, `<title>` and `<textarea>`, `<template>` (inert,
   unless it is a declarative shadow root on an element that can carry one),
   `<svg>` and `<math>`, character references, `rel` as a token list and
   `srcset` split at its commas: a stylesheet or an image is requested when the
   browser loads it, and only then. The build `<meta>` is read the same way, so a
   minifier that writes `<meta name=build content=…>` no longer reads as a
   missing stamp, and a scheme in capitals (`HTTPS://cdn.example.com/lib.css`)
   is no path on the own origin. `<base href>` counts from where it stands, as
   in the browser (a `data:` or `javascript:` base is ignored with a `--` line,
   any other base that is no http(s) address is a finding); `<noscript>` is read
   as markup. **`genba-smoke` therefore needs PHP 8.4 or newer on the machine
   that runs it** (normally the CI runner, `setup-php` with
   `php-version: '8.4'`); below, it stops before the first request, exit 2, and
   names the version. The plugin part and the other four tools stay at PHP 8.2.
   Why only `Dom\HTMLDocument`: SPEC decision 24.
2. **A lost stylesheet is red.** When the marker is a `.css` address (the default
   `assets/css/tailwind.css`) and stands in the page, a stylesheet of the own
   origin with that address must have been read from the `<link>` elements, else
   that is a finding (`Homepage contains the stylesheet address …`) — a page the
   reader gets wrong never ends in "no stylesheet … not checked" and PASSED. A
   site that loads its stylesheet by a script or from another origin sets
   `GENBA_MARKER` to a text that is no `.css` address.
3. **The homepage reader has bounds.** A homepage above 10 MiB is one finding
   (`Homepage larger than 10485760 bytes — not read …`) and is not read, also
   when it comes without `Content-Length` and curl below 8.4 does not stop at
   `--max-filesize`: the size on disk counts too. The reader runs at most
   `GENBA_MAX_TIME` seconds; above, the run cannot run (exit 2).
4. **Check 5 runs only on a homepage that was read.** A homepage that answered no
   200, gave no answer or is larger than 10 MiB is said in one `--` line with the
   reason (`stylesheets, images and fonts not checked: …`), never as
   `no .webp file found on the homepage`.
5. **Addresses are requested as the browser requests them**, in the homepage, in
   a `url()` of a stylesheet and in a `<loc>`: leading and trailing control
   characters are stripped, tabs and line breaks dropped, every other control
   character is percent-encoded (`/a\x01b.webp` is `/a%01b.webp`, not `/ab.webp`),
   and bytes that are no UTF-8 become U+FFFD (`%EF%BF%BD`, one per broken
   sequence). A stylesheet is read as UTF-8 whatever it declares. An address
   above 2048 characters is a finding and is not fetched.
6. **An empty sitemap is a finding.** The copy passed a sitemap without pages.
   So are a missing sitemap or one without `<urlset>`
   (`::error::Sitemap not available for page check`), a sitemap above 10 MiB, an
   empty entry (`::error::Sitemap entry is empty (<loc> without an address)`) and
   an entry that is no absolute URL (`Sitemap entry is not an absolute URL:
   /kontakt — the sitemap protocol requires scheme and host`, in every
   `GENBA_FOREIGN_SITEMAP` mode).
7. **Sitemap entries are read however the generator wrote them.** The body is
   read as one line, so a pretty-printed entry with the address on its own line
   and an address wrapped in CDATA are fetched like any other; comments are
   dropped first, so a commented-out entry is no entry. The number of `<url>`
   elements is held against the addresses read; a difference is
   `Sitemap: <n> entries could not be parsed`, never a silent drop. The XML
   entities `&amp;`, `&lt;`, `&gt;`, `&quot;` and `&apos;` and the numeric
   references of `& < > " ' # ? =` are decoded before the fetch, each exactly
   once (a `<loc>` with `?a=1&amp;b=2` was requested as written and read as a
   404). Any other reference that is left (`&#233;`, `&eacute;`; Kirby's
   `Xml::encode()` writes every non-ASCII character so) is a finding and is not
   fetched: curl would read its `#` as the start of the fragment and check
   `/blog&#47;neu` as `/blog&`.
8. **On a preview run, a sitemap entry on another origin is rewritten to the
   `GENBA_URL` origin** and fetched there (`GENBA_FOREIGN_SITEMAP=rewrite`, the
   default when `GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` is set), announced once
   as `::notice::`. The copy made it a finding, and without credentials and
   without the gate that stays the default (`fail`, the live site); the log names
   which default was taken. `warn` prints a warning and fetches nothing.
   `GENBA_SITEMAP_ORIGIN` limits the rewrite to one origin; on such a run the
   stylesheets and files of check 5 on that origin are checked on the
   `GENBA_URL` origin too. **Site-visible:** a staging host whose site URL is
   production sets `GENBA_SITEMAP_ORIGIN: ${{ vars.LIVE_URL }}`, or the safety
   net of check 5 reports its stylesheet.
9. **Origins are compared like a browser does**: scheme and host without regard
   to case, `:80` and `:443` dropped — for the site's own origin,
   `GENBA_SITEMAP_ORIGIN` and the http→https target. `GENBA_URL=https://WWW.Example.de`
   against a sitemap written in lower case is no longer "a foreign host".
10. **The smoke test says when it checked less than it seems.** No sitemap page
    fetched is a `::warning::` (every entry on a foreign host in `warn` mode) or
    an `::error::` (every entry is a finding already), never
    `ok   0 sitemap pages fetched`; a sitemap cut by `GENBA_PAGES_MAX` prints
    `ok   1 of 2 sitemap pages fetched (GENBA_PAGES_MAX=1), the rest not
    checked`; more than five own `.webp`, `.avif` or `.woff2` files print
    `--   <n> .<ext> files of <origin> on the homepage, the first 5 checked`; a
    homepage without an own stylesheet prints a `--` line.
11. **Image and font Content-Types are checked on the own origin first**: the
    copy took five candidates and only then dropped the foreign ones, so five CDN
    files left the own files unchecked without a word. When only foreign files
    exist, the log says so.
12. **`robots.txt`, `sitemap.xml` and `llms.txt` are fetched once per method**,
    and the body of that one GET is what the content pattern and the sitemap page
    check read. The copy fetched a third time; a third answer that failed passed
    both checks unread.
13. **A transfer curl could not finish is not an answer.** The copy read curl's
    status code and ignored its exit code: a connection that closed with bytes
    missing after a 200 (curl 18), a body that never arrived (28) or a broken
    transfer (56) counted as `ok`, and the cut body was read. Now such a fetch is
    `HTTP 000`, the cut body is not read, and the finding carries curl's message
    in every check alike (`GET /sitemap.xml: HTTP 000 instead of 200 — curl: (18)
    transfer closed with 3926 bytes remaining to read`), the http→https check
    included (`… expected 301/308 to https://<host> — curl: (7) Failed to
    connect …`).
14. **A body is read as text whatever it holds.** Every `grep` on a body runs
    with `-a`, so a NUL byte does not turn a page "binary"; the commands that
    read text from the site (`sed`, `tr`, `grep`, `awk`, …) and bash's pattern
    matches on it run in the C locale, so a byte that is no UTF-8 (a Latin-1
    page) does not stop macOS `tr`, `sed` or bash in a UTF-8 locale. The byte
    itself is no finding.
15. **A protected host without credentials is a finding** (exit 1) by default. The
    workflow's "secrets missing → warning, run green" becomes
    `GENBA_WITHOUT_CREDENTIALS=skip`, which a site must set explicitly, and which
    never hides a finding: a run that already has one ends `FAILED`, exit 1, with
    a `--` line that the secrets are missing. A 401 homepage is not retried.
16. **Smoke-test credentials only over https.** With
    `GENBA_USER`/`GENBA_PASSWORD`, a `GENBA_URL` that is not `https://` is refused
    before the first request (exit 2); only 127.0.0.1, localhost and [::1] are
    exempt. Credentials inside the URL (`user:pw@host`) are refused too, without
    echoing the URL, and so is a line break in either value (a secret set from a
    file keeps the file's line breaks). Should curl still fail to read its
    credentials file, the run cannot run (`curl cannot read its credentials file
    (curl exit 26) …`) instead of a cascade of HTTP 000 findings. curl runs with
    `-q` (no `~/.curlrc`) and `-g` (no URL ranges).
17. **The smoke test uses no proxy from the environment** (`--noproxy '*'`), the
    rule the link check always had. Behind a proxy the proxy resolved the name,
    so a DNS entry still pending could never be told from a broken site, and a
    proxy could redirect a request that carries credentials. A runner that can
    only reach the site through a proxy sees findings, never a silent detour.
18. **The reachability gate of the preview workflow is in the smoke test**:
    `GENBA_EXPECT_PROTECTED=1` makes the first request, without credentials, prove
    the 401; `GENBA_NOT_READY=skip` ends a run on a name that does not resolve yet,
    or on an https connection that cannot be set up while http answers, as
    `Genba smoke: SKIPPED — …` (exit 0, with a `::warning::`). By default a name
    that does not resolve is a finding and stops the run. "Not ready" is decided
    once, on the first request (the gate's, when it runs); only a connect or TLS
    error counts (curl 7, 35, 51, 58, 59, 60, 77, 83, 90, 91), never a timeout,
    an empty answer or an expired certificate — a preview that answers 401 and
    then hangs behind the protection is red, not SKIPPED. A site deletes its own
    "Erreichbarkeit prüfen" step.
19. **Private paths are checked on every site** (one site did it inline):
    `.env`, `.git/HEAD`, `composer.json`, `site/config/config.php`, `vendor/` and
    twelve more must not answer 2xx; counted in one `ok` line, each reachable one
    a finding. `GENBA_PRIVATE_PATHS=none` turns it off, visibly.
20. **`genba-smoke` reads `GENBA_SHA`** when `GENBA_EXPECTED_BUILD` is unset,
    before `git rev-parse HEAD` — the same precedence as `genba-stamp`.
    **`GENBA_EXPECTED_BUILD` may be abbreviated** (7–64 hex digits) and is
    compared as the prefix of the live hash; whitespace around it, a trailing
    newline included, is dropped. **`GENBA_META` is validated** against
    `[A-Za-z0-9_-]+`, the rule of `genba.stamp.meta`; a space or quote in it is a
    usage error (exit 2) instead of "Homepage without <meta>".

#### genba-links

1. **No references found is said out loud**: the link check prints a `::notice::`
   and the summary with zeros, instead of a bare zero. `--list-only` ends with
   `Genba links: <n> references listed`.
2. **An empty `GENBA_DIGISTORE_FIELDS`, `GENBA_YOUTUBE_FIELDS` or
   `GENBA_SHOP_HOSTS` counts as unset** (GitHub Actions yields `""` for an unset
   `${{ vars.X }}`). An empty value no longer switches a field off silently.
3. **The link check follows redirects by hand** and refuses a hop into the
   runner's own network (loopback, private, link-local, CGNAT, ULA, site-local,
   multicast, IPv4 inside IPv6, `.local`/`.lan`/`.internal`/`localhost`) as a
   finding, without contacting it; the copy let curl follow up to 10 hops
   anywhere. URLs are rebuilt from validated parts (ambiguous ones, such as
   `user@host`, a backslash or `http://2130706433/`, are refused; a raw UTF-8
   path such as `/produkt/kräuter` is percent-encoded), each host is resolved once
   and exactly that address is pinned, and no proxy from the environment is
   used.
4. **Purchase links from the content are checked before the first request**:
   unless the host is exactly a `--shop-host` entry, a link whose host resolves
   to a private or local address is a finding (`address <host> refused (…) — not
   requested`). A refused oEmbed address is a finding with its reason
   (`Video <id>: URL refused (…)`), not "YouTube unreachable". A field with a URL
   and a bare YouTube ID checks both.
5. **The link check skips by place and says what it did not read**: `vendor`,
   `kirby`, `storage`, `site/cache`, `site/sessions`, `site/accounts`,
   `site/logs`, `public/media` (relative to the working directory),
   `node_modules` and `.git` anywhere. The copy skipped `media`, `cache` & co. at
   any depth, so a page `content/media/` was never checked. Symbolic links follow
   the rules of `genba-precheck`. A directory or file that cannot be read is
   `::error::Cannot read …`, exit 2 — not a PHP stack trace (directory), and not
   an empty text whose links go unchecked with exit 0 (file). Genba's own package
   is never read (installed from source, its `tests/` hold sample links). A
   missing default path and `--skip-counter-check` each print a `--` line.

### Unchanged (data continuity)

- **`site/config/build.txt`**: same path, same content (one lower-case 40-digit
  commit hash and a newline). Nothing in a site has to change for it.
- **`<meta name="build">`**: same name and same output. The weekly fleet check outside this repository keeps reading it unchanged.
- **The site's own checks** (its deploy script, its credential checks, its
  site-specific health checks) stay where they are.

### [privacy text]

- `DATENSCHUTZ.md` (German text block for a site's privacy policy) and
  `PRIVACY.md` (English summary) are new. They describe the implementation: the
  Kirby part prints a commit hash of the site's own code, with no cookies, no
  browser storage and no request to a third party from a visitor's browser. The
  tools run in CI and contact YouTube (oEmbed), Digistore24 and the shop hosts
  named in the site's content, sending only public URLs and a user agent string.
  Every site using the package reviews its privacy policy.
