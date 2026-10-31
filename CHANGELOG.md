# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to a message that the tests grep for, to an exit code, to an
  environment variable name, or to the `<meta name="build">` output** is a
  **major** version. Sites and their workflows match on these; a routine update
  must not change what a check reports or how a workflow reads its result.

## [1.9.0] — 2026-10-31

### Changed

- Options are read as `sayamaapps.genba.<name>`. The 1.x names `genba.<name>` keep working and are read without a warning. If both are set, the new one wins.
- `Options::OPTION_PREFIX` is new. `Options::PREFIX` keeps its 1.x value.

## [1.8.0] — 2026-10-29

### Added

- `genba-precheck` skips `site/templates/emails/`, Kirby's mail templates, with the line
  `  --   not checked: site/templates/emails (Kirby mail templates, sent as mail, not served under the CSP)`.
  A mail is no page under the site's CSP, and mail clients need inline styles. An `emails/` folder
  anywhere else (`site/snippets/emails/`) is site code and is still scanned.

## [1.7.0] — 2026-10-27

### Changed — code layout after the Kirby plugin conventions; no change in behaviour

- Classes live in the `Sayamaapps\Genba` namespace and are loaded through a class map (`autoload.php`).
  The former `Genba\` names stay as class aliases (`legacy.php`), created at load time, so `instanceof` and `catch` work before the old name is used.
- `index.php` only registers the plugin; the definitions are in `config/`.
- Code style after Kirby's (`getkirby/php-cs`, tabs), PHPUnit instead of `tests/run.sh`, and analysis in CI
  (Psalm, PHPMD, phpcpd, composer-require-checker, composer-unused). `LICENSE.md`, `.editorconfig` and `.gitattributes` added.
- Messages, exit codes, environment variables, snippets, site methods, translation keys and the stamp file are unchanged.
- `composer.lock` is tracked. CI runs the tests on the lowest, the highest and the locked dependency versions.
- `SPEC.md` moved to `docs/SPEC.md`.
- `composer ci` runs the analysis and the tests locally.
- No breaking change in 1.x. Breaking changes wait for the next major version (see README, "Planned for 2.0").

### Deprecated — documentation only, no runtime notice

- `genba_option()`: use `Sayamaapps\Genba\Options::get()`.
- The `Genba\` class names: use `Sayamaapps\Genba\…`.
- `Genba\Log::forget()`: test helper, `@internal`.

## [1.6.0] — 2026-10-24

### Added — `genba-precheck --forbid-markers`: no placeholder goes live

- Placeholders such as `[TODO]` or `[OFFEN] Text folgt` stand in the content while a text is still open.
  A preview shows them on purpose, but nothing stopped them on the way to the live site. With
  `--forbid-markers` the precheck also reads every `*.txt` file under `content/` (drafts left out) and
  fails with exit 1 on a placeholder: `::error::<file>:<line>: placeholder '<marker>' — not for the live
  site: <line>`. Use the switch in the live deploy only.
- The default marker: `TODO`, `FIXME`, `TBD`, `TBA`, `TBC`, `XXX`, `placeholder`, `Platzhalter` or `offen`
  right behind `[`, any case, followed by `]`, a colon or a dash. A Markdown link (`[TODO](…)`) and a
  longer word (`[Todo-Liste]`) are no marker. It has a self-test with positive and negative samples, like
  the CSP rules.
- Markers of your own: `--marker=PATTERN` (repeatable) or `GENBA_MARKERS` (one regular expression per
  line), added to the default; `none` drops it. `--content=DIR` names another content root.
- Nothing passes in silence: a missing content root, no content file, a content file that is not UTF-8,
  a pattern that does not compile or matches every line are exit 2. `--marker` and `--content` without
  the switch are usage errors; `GENBA_MARKERS` without it prints a `--` line.
- Without the switch nothing changes: every message, exit code and line of a run is the same as in 1.5.1.
  An older Genba refuses the switch with exit 2 (`Unknown argument`), so a workflow that relies on it
  cannot pass on an old version.

## [1.5.1] — 2026-10-20

### Fixed — `genba-smoke` check 7b: Kirby's reload after an update is no finding

- On the first Panel page after an update (a new Kirby version, an empty
  `media/panel`) Kirby links the Panel's files and sends the page back to its
  own address. Check 7b followed only the redirect to the login page and
  reported the reload: `/panel: HTTP 302 instead of 200` on the first run after
  every Kirby update, green on the next. A page that sends on to itself is now
  asked once more, said in a `--` line (`<path> sends on to itself (Kirby links
  the Panel files on the first call after an update) — asked once more`); a
  second reload is the finding as before. Messages and exit codes unchanged.

### Removed

- **`DATENSCHUTZ.md` and `PRIVACY.md`.** Genba processes no visitor data, so it has no place in a site's privacy policy. The README says so under "Privacy".

## [1.5.0] — 2026-10-20

### Added — `genba-smoke`: Panel and API address for a site with its own slug

- `GENBA_PANEL_PATH` (default `/panel`) names the Panel for checks 7 and 7b, and
  `GENBA_API_PATH` (default `/api`) the API for check 7 (`<path>/system`). A site
  with its own `panel.slug` or `api.slug` got a false alarm (`/panel: HTTP 404
  instead of 200`); the only way out was `GENBA_PANEL_ACCOUNT=off`, which also
  switched off check 7. The messages of these checks name the configured path.
- A value that is not a path below the site root (no leading slash, a trailing
  slash, `..`, a query, characters outside `A-Za-z0-9_~-`) is a usage error, exit 2:
  `GENBA_PANEL_PATH must be a path below the site root: <value>`.
- Defaults unchanged: every message, exit code and check is the same without the
  new variables.

## [1.4.1] — 2026-10-19

### Fixed — `genba-smoke` check 7b: Kirby's empty plugin bundles are no finding

- 1.4.0 read every Panel file as "must not be empty". Kirby names
  `/media/plugins/index.css` and `/media/plugins/index.js` on every Panel page and
  answers them with 0 bytes when no plugin brings Panel code — so a sound site failed
  with `Panel file /media/plugins/index.css: HTTP 200, 'text/css', 0 bytes`. Those two
  may be empty; their type is still checked. The Panel's own files must still not be
  empty.

### Fixed — `genba-links`: a playlist embed is no video

- `https://www.youtube-nocookie.com/embed/videoseries?list=…` embeds a playlist, but
  `videoseries` has 11 letters and was read as a video ID: `Video videoseries does not
  exist (oEmbed 404)`. A package that builds playlist embeds in its code failed every
  daily run. `/embed/videoseries` is no video reference now.

## [1.4.0] — 2026-10-19

### Fixed — `genba-smoke` checks 9 and 7b: nothing is green that was not checked

A site that passed before can fail now — in exactly the cases that passed by mistake.

- **Check 9, a rule's path must answer 2xx or 3xx.** A path with a typo answered 404,
  and the rule was checked against the error page's headers: `ok … (HTTP 404)`. Any
  other code is now a finding, `<label>: HTTP <code> instead of 2xx or 3xx — the
  header was not checked (path wrong?)`. 3xx stays: `/panel` answers 302.
- **Check 9, `!~text` asks for a header that is sent.** A Content-Security-Policy that
  got lost passed `!~unsafe-inline`. A header not sent is now `<label>: not sent,
  expected not containing '<text>'`.
- **Check 9, `~` or `!~` without text** is a usage error, exit 2: `GENBA_HEADERS: the
  rule for <name> is '~' without text`. `~` alone was true of every value sent.
- **Check 7b, Panel files are read like stylesheets.** Each must answer 200 with a
  script type (`text/javascript`, `application/javascript`,
  `application/x-javascript`) or `text/css` and must not be empty. A fallback route
  that answers every path with an HTML page passed before; now `Panel file <path>:
  HTTP 200, '<type>', <bytes> bytes — expected …, not empty`.

### Fixed — `genba-precheck`: an attribute with its `=` on a line below

- `<button style⏎="color:red" onclick⏎="go()">` is valid HTML: the tokenizer reads any
  whitespace between an attribute name and its `=`, line breaks included. Both
  rules read the file line by line and passed it. `inline-style` and
  `inline-handler` now read a line that ends in a name with the lines below it, up
  to the first that is not blank; the finding is the line of the name. A name with
  no `=` after it is no finding, and a `style=` on the line below is counted on that
  line only. A site with such markup now fails the check where it passed.

### Fixed — `genba-smoke` check 7b: the Panel's redirect target

- **A target on another origin is no false alarm.** Kirby builds the target of its
  `Refresh: 0; url=…` from its `url` option. On staging with the production URL, or
  with `localhost` against `127.0.0.1`, it names another origin, and the run failed
  with `/panel: HTTP 302 to 'https://…/panel/login', not to a page under /panel`. Now
  its path counts: under `/panel` it is fetched on the `GENBA_URL` origin, said in a
  `--` line, `/panel sends on to <target> — checked as <path> on <origin> (Kirby's url
  option names another origin)`.
- **The target of a `Refresh` starts right after the first `url=`.** An `url=` in its
  query (`/elsewhere?next=url=/panel/login`) was read as the target and passed.
- `/panel` is asked once; on a redirect it was asked twice.

## [1.3.0] — 2026-10-17

### Added — `genba-smoke` checks for headers and the Panel's files

Two checks that sites often ran on their own: the
response headers of a hardened site, and the Panel's own files.

- **Check 9, `GENBA_HEADERS`.** One rule per line, `[/path] Name: rule`: a value
  (exact), `*` (present), `none` (absent), `~text` (contains), `!~text` (does not
  contain). One request per rule, on the rule's own path, so a Panel page can carry
  a different Content-Security-Policy than the rest of the site. Read before the
  first request: a broken line is a usage error (exit 2), never a silent pass.
  Not set: a visible `--` line, as before.
- **Check 7b, Panel files.** `/panel` must answer 200, and every JS or CSS file it
  names under `/media/panel` or `/media/plugins` must answer 200. Off with
  `GENBA_PANEL_ACCOUNT=off`. A site with a Panel gets this check on upgrade; a
  page without Panel files is reported as a finding.

Check 9 changes nothing until `GENBA_HEADERS` is set. Check 7b runs on upgrade: a
site whose Panel files do not arrive fails (exit 1) where it passed before — and so,
by mistake, did a site whose Kirby `url` option names another origin than
`GENBA_URL` (fixed in the next release). No existing message changes. *(Corrected:
this entry first said that neither check changes an exit code.)*

## [1.2.0] — 2026-10-14

### Added — `genba-smoke`: closed preview

- **`GENBA_CLOSED_PREVIEW`** = `accept` | `fail`. A closed preview (the Sudare
  package: `robots.txt` with `Disallow: /` and no `Sitemap:` line, `sitemap.xml`
  an empty `<urlset>`) is accepted on a preview run, the default; `fail` makes it
  the two findings it was before. Unset, the default is `accept` on a preview run
  (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set), else `fail`. Only the combination
  counts: `Disallow: /` with pages in the sitemap is a finding under either value,
  and so is an empty sitemap with an open `robots.txt`. Any other value is exit 2.
  The log prints one line: `  --   closed preview: robots.txt Disallow: / and an empty sitemap, so no sitemap pages were fetched (GENBA_CLOSED_PREVIEW=accept)`.
- New finding under `Disallow: /` with pages: `robots.txt disallows all crawling (Disallow: /) but the sitemap lists pages — …`.

### Added — `genba-precheck`: event handler names built at runtime

- A new rule, `inline-handler-runtime`: an `on` directly followed by a PHP block
  (`<div on<?= $event ?>="go()">`, with or without the `=`) is a finding,
  `inline event handler with a name built at runtime — the CSP forbids it`.
  A site with such a line now fails the check where it passed silently before.
  The self-test reports five rules.

## [1.1.0] — 2026-10-14

### Fixed — `genba-precheck` false alarms in installed packages

A site with other Kirby packages installed failed the pre-deploy check on lines
that never reach a browser.

- **Inline handlers by name.** The handler rule counts an `on…` name when it is
  in `Precheck::EVENT_HANDLERS` (the HTML standard, SVG animation, Pointer and
  Touch Events, CSS animations and transitions, Fullscreen, Selection, and the
  engine-specific, prefixed and legacy names of WebKit, Gecko and Blink).
  `var once = key` in a script and `const ONLINE = 'online'` in a class were
  findings. Any other `on…` name is now no finding but a visible line:
  `  --   not counted: <file>:<line>: '<name>' is not an event handler Genba knows: <line>`.
- **PHP comments are not read.** In `.php` and `.phtml` files the rules read
  the code without its comments, blanked with PHP's tokenizer (line numbers
  kept, the finding shows the line as written). A doc comment that mentions
  `<script>` was a finding. Strings and HTML comments are still read; a file
  with a bare `<?` (short open tag) is read with its comments.
- **Package Markdown is not read.** Markdown files under `site/plugins/`
  (READMEs that document CSP rules) are counted in one line:
  `  --   not checked: <n> Markdown files in site/plugins (package documentation)`.
  A file whose real path is not Markdown or lies outside the real
  `site/plugins/` (a link) is read; Markdown elsewhere is read as before.

### Fixed — missed findings

- A PHP closing tag right before `style=` or an `on…` handler ends the
  attribute name, as whitespace does: `<p <?= $x ?>style="x">` and
  `<button class="b"<?= $extra ?>onclick="go()">` were not reported.

### Added

- `Precheck::EVENT_HANDLERS`, `skippedMarkdown()`, `markdownDir()`,
  `notCounted()`, and the rule key `names`. Two new self-test failures for a
  broken rule table: `rule <name>: names is not a list of lower-case names`,
  `rule <name>: names needs a pattern with a (?<name>…) group and no each`.

## [1.0.0] — 2026-10-14

First release as a Composer package. It replaces the operations that sites had as
per-site files: the build stamp snippet, the smoke test, the pre-deploy checks,
the link check, their tests, the `praeflug` npm step and the inline workflow
steps for the stamp and the stylesheet.

### Changed — English names

Environment variables, options, snippets, tools and messages are English. The
names are listed in the README and in `docs/reference.md`.

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

### Behaviour

The points below are deliberate. Each one can show on a site after an upgrade. The
README describes every rule in full.

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
   default, and uses the anchored `//fonts\.` pattern. The negative control is kept: the
   foreign-host rule must not match `document.fonts.ready.then(remeasure)`, or the
   self-test fails. A workflow that ran only the style and host rules now gets the
   handler and script rules too.
4. **The rules ignore case**: `<P STYLE="x">`, `<SCRIPT>`, `ONCLICK=` and
   `FONTS.GSTATIC.COM` are found; `<SCRIPT SRC="x">` and the JSON-LD type are
   still exceptions.
5. **Inline styles and handlers are found in every spelling the browser
   accepts**: `style='x'`, `style = "x"`, `style=color:red` without quotes, an
   attribute at the start of a line, right after a closing quote or after a `/`
   (`<p class="a"style="…">`, `<img src="x"/onerror="…">`), and any `on<event>=`,
   not only `style="` and four event names. A PHP variable `$style = 'card'`, a
   `data-style` attribute, `icon=` or `json=` are not findings; the rules carry
   them as negative samples.
6. **`embedded-script` judges every `<script …>` tag on its own, attribute by
   attribute.** A line that held `src=` or
   `application/ld+json` anywhere was exempted as a whole, so
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
   source and README carry the rule samples. Symbolic
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
   Why only `Dom\HTMLDocument`: see docs/SPEC.md §11.
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
6. **An empty sitemap is a finding.**
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
   as `::notice::`. Without credentials and without the gate, the default stays
   `fail` (the live site); the log names which default was taken. `warn` prints a warning and fetches nothing.
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
11. **Image and font Content-Types are checked on the own origin first**: foreign candidates are dropped before the five are taken, so five CDN
    files cannot leave the own files unchecked without a word. When only foreign files
    exist, the log says so.
12. **`robots.txt`, `sitemap.xml` and `llms.txt` are fetched once per method**,
    and the body of that one GET is what the content pattern and the sitemap page
    check read.
13. **A transfer curl could not finish is not an answer.** Curl's exit code counts as well as
    the status code: a connection that closed with bytes missing after a 200 (curl
    18), a body that never arrived (28) or a broken transfer (56) is not `ok`, and
    the cut body is not read. Such a fetch is `HTTP 000`, and the finding carries
    curl's message
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
    then hangs behind the protection is red, not SKIPPED. A workflow's own reachability
    step can be dropped.
19. **Private paths are checked on every site.**
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
   finding, without contacting it; curl never follows a redirect on its own. URLs are rebuilt from validated parts (ambiguous ones, such as
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
   `node_modules` and `.git` anywhere. Only the places listed are skipped, so a
   page under `content/media/` is checked. Symbolic links follow
   the rules of `genba-precheck`. A directory or file that cannot be read is
   `::error::Cannot read …`, exit 2 — not a PHP stack trace (directory), and not
   an empty text whose links go unchecked with exit 0 (file). Genba's own package
   is never read (installed from source, its `tests/` hold sample links). A
   missing default path and `--skip-counter-check` each print a `--` line.

### Unchanged (data continuity)

- **`site/config/build.txt`**: same path, same content (one lower-case 40-digit
  commit hash and a newline). Nothing in a site has to change for it.
- **`<meta name="build">`**: same name and same output. An external weekly check keeps reading it unchanged.
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
