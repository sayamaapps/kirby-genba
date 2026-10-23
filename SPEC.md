# Genba — build specification

Genba (現場, "the place where the work actually happens") is the operations
package for a deployed Kirby 5 site: the **build stamp** in the HTML, the
**preflight hook**, the **pre-deploy checks** (CSP rules, built assets), the
**post-deploy smoke test** (build, content, delivery, Panel) and the
**foreign-link check** against the source (YouTube, Digistore, purchase links).

Package `sayamaapps/kirby-genba`, Kirby plugin id `sayamaapps/genba`, PHP
namespace `Genba`, option prefix `genba.`, installed by Composer into
`site/plugins/genba/`, five command-line tools linked into `vendor/bin/`.

It replaces, in every site built from kirby-starter, the copies
`site/snippets/stand.php`, `scripts/smoke-test.sh`, `scripts/deploy-vorpruefung.sh`,
`scripts/pruefe-fremdlinks.php`, the `praeflug` npm step and the inline
workflow steps "Stand vermerken" and "Stylesheet darf nicht leer sein".

This document is the contract. Section numbers are referenced from code
comments and task prompts.

---

## 0. Sources (read-only — never edit, no git commands except log/show/diff)

| What | Path (relative to this repository) |
|---|---|
| Stamp snippet + test | `../kirby-starter/site/snippets/stand.php`, `../kirby-starter/tests/StandTest.php` (identical in all sites) |
| Smoke test + counter-check | `../kirby-starter/scripts/smoke-test.sh` (300 lines, current), `../kirby-starter/tests/smoke-test-gegenprobe.sh` (174 lines). An older per-site copy of `smoke-test.sh` (270 lines) is the variant WITHOUT check 7 (Panel) — port the 300-line one |
| Foreign-link check + test | `../kirby-starter/scripts/pruefe-fremdlinks.php` (552 lines, identical everywhere), `../kirby-starter/tests/FremdlinksTest.php` |
| Pre-deploy checks | `../kirby-starter/scripts/deploy-vorpruefung.sh` (42 lines, = the copies of three live sites since 10.10.2026) · an older per-site copy (39 lines, which that site's workflow does not run) · that site's `scripts/pruefe-ressourcen.sh` (what its `deploy.yml` runs). Differences: §3.3 (as read 11.10.2026) |
| Where the pieces run | `../kirby-starter/.github/workflows/deploy-vorschau.yml` (praeflug, build, stylesheet check, stamp, PHP tests, vorpruefung, deploy, reachability, smoke test), `tests.yml` (counter-checks on every push), `fremdlinks.yml` (daily 05:17 UTC); the deploy workflow of one live site, lines 285–295 and 770–785 (live variant: stamp verified with grep, `HTTPS_UMLEITUNG` pflicht/warnen by `OEFFENTLICH`) |
| package.json | `../kirby-starter/package.json` script `praeflug` (no-op echo; one live site: `node scripts/schriften.mjs`) |
| Boundaries, NOT duplicated | the weekly fleet check outside this repository (reads `<meta name="build">`), a live site's own daily health check (site-specific) |
| Family conventions | `../kirby-noren` (SPEC, README, CHANGELOG, privacy notes, tests/helpers.php), `../kirby-koe` (bin/koe-fetch, src/Fetch.php: CLI `run($env, $argv, …, $out, $err)` pattern), `../kirby-kizami`, `../kirby-fude/.github/workflows/ci.yml` (fork guard) |

---

## 1. Ground rules for every builder

- PHP 8.2 compatible code (`src/Homepage.php` too — it must load on 8.2 to say that
  genba-smoke's reader needs 8.4; only its `read()` calls the 8.4 class `Dom\HTMLDocument`)
  (no typed class constants, no `readonly` classes,
  no 8.3+ functions). Run with `ASDF_PHP_VERSION=8.5.3 php …` (asdf otherwise
  says "No version is set"). Composer: `ASDF_PHP_VERSION=8.5.3 composer`.
- Code, identifiers, comments, messages, README in **English**. German only
  in `translations/de.php`. The CLI tools print English.
- **Only edit files your task owns (§2).** Need a change in a file you do not
  own (a helper, a translation key, index.php, a stub signature)? Write it into
  `NOTES-<task>.md` in the package root: file, problem, proposed change. Do not
  work around it with a copy of the logic.
- **No silent passes (§3.0).** A check that cannot run says so and exits 2, or
  prints a visible `SKIPPED` line. Nothing is ever reported `ok` or `PASSED`
  because a value was missing. An absent value is `null`, not `0` or `''`.
- Logic classes are **Kirby-free** (`src/Stamp.php`, `Preflight.php`,
  `Precheck.php`, `Links.php`): no `kirby()`, no `App`, no `option()`. Only
  `src/Options.php` and the snippets touch Kirby.
- Tests: standalone PHP scripts `tests/<Name>Test.php`, exit code 0 = pass, no
  PHPUnit. Use `tests/helpers.php` (`expect()`, `holds()`, `contains()`,
  `finish()`, `genba_tmp()`, `genba_write()`, `genba_remove_tree()`,
  `genba_php()`, `genba_free_port()`, `genba_server()`, `genba_stop()`,
  `genba_run()`, Kirby fixtures `genba_site()`, `genba_kirby()`). Kirby-free
  tests require the src files they need directly, never `index.php`. Kirby
  tests never require src files; Kirby loads the plugin through the symlink
  `genba_site()` creates. Clean up temp directories in `finally`.
- Tests must not need the internet. Fake servers are PHP's built-in server
  (`genba_server()` with a router file under `tests/fixtures/`).
- Bash (only `bin/genba-smoke`): must run on **bash 3.2** (macOS) and Linux
  runners. `set -euo pipefail`. No `declare -A`, no `${var,,}`, no `mapfile`,
  no `read -a`, no `|&`, no `;&`. `shellcheck`-clean (the test runs shellcheck
  when installed and says visibly when it is not).
- Do not push, do not create GitHub repos, do not touch any other repository.
  Never delete files outside this directory. Do not run `git commit` (the
  integrator commits).
- Throwaway probe scripts go to the scratchpad directory named in your task
  prompt, never into a repository.

Run everything: `cd <this repository> && ASDF_PHP_VERSION=8.5.3 sh tests/run.sh`.
One file: `ASDF_PHP_VERSION=8.5.3 php tests/<Name>Test.php`.

---

## 2. File tree and owners

`architect` = written before the build, shared, **builders never edit**.
`stub → <task>` / `placeholder → <task>` = a file that exists so the skeleton
loads; the named task replaces it completely.

```
kirby-genba/
├── composer.json                    architect   (bin list, require, extra)
├── composer.lock, vendor/           generated (composer install), not committed
├── .gitignore, .gitattributes       architect
├── LICENSE                          architect   (MIT)
├── .github/workflows/ci.yml         architect   (ubuntu-latest, fork guard, PHP 8.2–8.5, shellcheck)
├── SPEC.md                          architect
├── index.php                        architect   (complete registration, §3.7)
├── src/
│   ├── I18n.php                     architect   (§5)
│   ├── Log.php                      architect
│   ├── Options.php                  architect   (§4)
│   ├── Stamp.php                    stub → stamp      (§3.1)
│   ├── Preflight.php                stub → stamp      (§3.2)
│   ├── Precheck.php                 stub → precheck   (§3.3)
│   ├── Links.php                    stub → links      (§3.5)
│   ├── Tree.php                     shared            (directory walk shared by Precheck and Links)
│   ├── Text.php                     shared            (Text::clean(): text from the site as one log line, shared by Precheck, Tree and Links)
│   └── Homepage.php                 smoke             (genba-smoke's homepage reader, Dom\HTMLDocument, PHP 8.4+)
├── bin/
│   ├── genba-stamp                  placeholder → stamp     (PHP entry point, §3.0)
│   ├── genba-preflight              placeholder → stamp     (PHP entry point)
│   ├── genba-precheck               placeholder → precheck  (PHP entry point)
│   ├── genba-smoke                  placeholder → smoke     (BASH, the whole tool, §3.4)
│   ├── genba-smoke-read             smoke             (PHP, its homepage reader; not in composer.json bin)
│   └── genba-links                  placeholder → links     (PHP entry point)
├── snippets/genba/
│   ├── stamp.php                    placeholder → stamp     (§3.6)
│   └── build-info.php               placeholder → stamp     (§3.6)
├── translations/en.php, de.php      architect   (§5)
├── tests/
│   ├── run.sh                       architect
│   ├── helpers.php                  architect
│   ├── I18nTest.php                 architect
│   ├── StampTest.php                stamp      (§9.1)
│   ├── PreflightTest.php            stamp      (§9.2)
│   ├── KirbyTest.php                stamp      (§9.3, needs vendor/)
│   ├── PrecheckTest.php             precheck   (§9.4)
│   ├── SmokeTest.php                smoke      (§9.5)
│   ├── fixtures/smoke-server.php    smoke      (router for php -S, §9.5)
│   ├── HtmlCorpusTest.php           shared     (the HTML variant corpus, §9.4 and §9.5)
│   ├── MessagesTest.php             shared     (every message literal of the tools is in docs/messages.md, §9.7)
│   ├── fixtures/html-variants/      smoke      (homepage variants for genba-smoke, §9.5)
│   ├── fixtures/precheck-variants/  precheck   (template variants for genba-precheck, §9.4)
│   └── LinksTest.php                links      (§9.6)
├── README.md                        docs   (§10)
├── docs/reference.md                docs   (§10)
├── docs/messages.md                 docs   (§10, read by tests/MessagesTest.php)
└── CHANGELOG.md                     docs
```

`bin/*` files must keep their executable bit (`chmod +x`); Composer links them
into `vendor/bin/`. Verified 2026-10-11 with a path repository: the package
lands in `site/plugins/genba/`, `vendor/bin/genba-smoke` is a plain `exec`
proxy (bash script), the other four are PHP proxies that `include` the file.

---

## 3. Architecture and public API

```
                 CI / deploy workflow (one line per step)              Kirby (plugin part)
                 ─────────────────────────────────────────              ───────────────────
 before upload   vendor/bin/genba-preflight   Preflight::run()          snippet('genba/stamp')
                 npm run build (site's own)                              → <meta name="build" content="…">
                 vendor/bin/genba-precheck    Precheck::run()           snippet('genba/build-info')
                 vendor/bin/genba-stamp       Stamp::run() → site/config/build.txt
                 php tests/*Test.php (site's own)                        $site->genbaBuild()
 upload          (site's own deploy script)                              $site->genbaBuildInfo()
 after upload    vendor/bin/genba-smoke       bash + curl, reads the live <meta name="build">
 daily schedule  vendor/bin/genba-links       Links::run()
```

### 3.0 Conventions shared by all five tools

**Entry points.** `bin/genba-<name>` is tiny: CLI guard (`PHP_SAPI !== 'cli'`
→ 404 and exit), `require_once` of the src files it needs, then
`exit(Genba\<Class>::run(getenv(), $argv, (string) getcwd(), STDOUT, STDERR));`
— exactly like `../kirby-koe/bin/koe-fetch`. All logic lives in the class so
tests call `run()` with their own streams (`fopen('php://memory', 'w+')`) and
only one test per tool goes through `proc_open` to prove the bin file itself.
`genba-smoke` is the exception: bash end to end (§3.4).

**`run(array $env, array $argv, string $cwd, $out, $err): int`** — `$env` is
`getenv()` (string values), `$argv[0]` is the script, `$cwd` the project root
the tool was started from (the site workflow runs it from the repository
root). Settings are read in this order, first hit wins: argument `--name=value`
→ environment variable `GENBA_…` → default. Unknown arguments → usage on
`$err`, exit 2. `--help` → usage on `$out`, exit 0. An argument error (an unknown
argument, an empty `--option=`, an empty positional argument, a `--pause` that is no number)
wins over `--help`, whatever the order — every argument is read before `--help` is honoured,
in all five tools.

**Exit codes** (all tools):

| Exit | Meaning |
|---|---|
| 0 | passed — or visibly skipped (the last line then starts with `Genba <tool>: SKIPPED`). Never after a finding — a run that has printed an `::error::` line ends FAILED, exit 1, whatever else is missing (`genba-smoke`'s `GENBA_WITHOUT_CREDENTIALS=skip` after a failed gate) |
| 1 | findings: the check ran and found something wrong |
| 2 | cannot run: usage error, a missing directory/tool/setting, a failed self-test. Never returned as 0. A missing tool is exit 2 in every tool: `curl` in genba-smoke, `ext-curl` in genba-links, `sh` in genba-preflight, `git` in genba-stamp and — for the expected build — in genba-smoke. A working directory that is no git checkout is NOT a missing tool: genba-stamp reports it as a finding about the working directory, exit 1 (`git rev-parse HEAD failed: not a git repository …`); genba-smoke cannot run without an expected build, exit 2, and names which of the two it is. In all five tools a usage error (unknown argument, empty `--option=`) prints `::error::<reason>` and the usage on stderr and `Genba <tool>: FAILED — cannot run` as the last line on stdout (`cannotRun()` in Stamp, Preflight, Precheck, Links; `cannot_run()` in genba-smoke, reason on stdout there); the wording is `Unknown argument: <argument>` everywhere |

**Output lines** (stdout), so GitHub shows them as annotations and a person
can scan a log:

| Prefix | Use |
|---|---|
| `::error::<text>` | a finding (sets exit 1) or a cannot-run reason (exit 2) |
| `::warning::<text>` | something not assessed but worth seeing (a disruption, a skipped check in warn mode) |
| `::notice::<text>` | information (a hint) |
| `  ok   <text>` | a passed check |
| `  --   <text>` | a check deliberately not run, WITH the reason in the text (`not checked: …`) |
| last line | `Genba <tool>: PASSED…` / `Genba <tool>: FAILED — …` / `Genba <tool>: SKIPPED — …` (tool = `stamp`, `preflight`, `precheck`, `smoke`, `links`) |

Secrets (`GENBA_PASSWORD`) never appear in output, never as a process argument.

**Log text.** Whatever a line holds that
was not typed by the operator — a line of a template, a file or directory name, a fetched title,
a header, an address from a page — passes a filter before it is printed: control characters
(C0, DEL, C1) become spaces or are dropped, so an ESC or CR cannot repaint the line and a line
break cannot start a forged annotation. PHP side: `Genba\Text::clean()` (src/Text.php;
`Links::clean()` delegates to it); bash side: `strip_controls`/`log_text` in genba-smoke. Both cut
a value above 2048 characters to its first 60 with its length (`… (<n> characters)`;
`Text::clean(string $text, int $max = 2048)`). The cut applies
to each value, never to a line assembled from several — every PHP line that joins pieces cleans
them one by one and the line with `PHP_INT_MAX` (precheck's finding and `--` lines, links' result
text, `--` lines and list line), so a long path or chain cannot cut away the line number, the
message or the cause.

**Absent is not zero.** When a list that the check depends on turns out empty
(no sitemap pages, no references found, no files scanned), the tool says so
with `::error::` (if the check is meaningless without it) or `::notice::` (if
it is plausible), never a bare "0 ok".

### 3.1 `Genba\Stamp` (src/Stamp.php — owner `stamp`, Kirby-free)

The commit hash the deploy writes to a file, which the snippet prints as
`<meta name="build">` and the smoke test compares with `git rev-parse HEAD`.
Deliberately through Kirby and not as a file in `public/`: a real file would
be served by Apache past the preview protection, and it would not prove that
PHP renders the new code. The hash itself gives nothing away without access to
the repository.

```php
final class Stamp
{
    public const DEFAULT_FILE  = 'site/config/build.txt';      // same as Options::DEFAULT_STAMP_FILE
    public const PATTERN       = '/^[0-9a-f]{7,64}$/';         // what read() accepts (stand.php's rule)
    public const FULL_PATTERN  = '/^[0-9a-f]{40}$/';           // what write() demands (a live site's grep)

    /** Trimmed file content when it matches PATTERN, else null (file missing, empty, 'main', HTML…). */
    public static function read(string $file): ?string;

    /**
     * ['hash' => '0123…', 'short' => first 7 chars, 'file' => $file relative to $root ('site/config/build.txt';
     *  outside $root: the file name only; $root null: $file as given),
     *  'writtenAt' => filemtime as ISO 8601 UTC, e.g. '2026-10-11T03:12:45+00:00', or null when filemtime() fails]
     * or null when read() is null. genbaBuildInfo() passes the project root: no absolute server path in a page.
     */
    public static function info(string $file, ?string $root = null): ?array;

    /** '<meta name="build" content="<hash>">' . "\n" when $hash matches PATTERN, else ''. $name is used verbatim (Options::metaName() validated it). */
    public static function meta(?string $hash, string $name = 'build'): string;

    /** Writes $hash . "\n" to a temp file in $file's directory and rename()s it into place. Throws InvalidArgumentException when $hash does not match FULL_PATTERN, RuntimeException when the directory does not exist, $file is a symbolic link ('it is a symbolic link, refusing to write through it') or the write fails. Never creates directories. */
    public static function write(string $file, string $hash): void;

    /** `git rev-parse HEAD` in $directory via proc_open (no shell). Returns the 40-digit hash or throws RuntimeException with git's stderr ("not a git repository", "git not found"). */
    public static function fromGit(string $directory): string;

    /** CLI, §3.0. */
    public static function run(array $env, array $argv, string $cwd, $out, $err): int;
}
```

**CLI `genba-stamp`** — settings: `--file=PATH` / `GENBA_STAMP_FILE`, default
`site/config/build.txt` (relative to `$cwd`, absolute stays absolute);
`--sha=HASH` / `GENBA_SHA` overrides git (full 40-digit hash required);
`--show` prints the hash currently in the file and writes nothing; `--help`.
An empty `--file=` or `--sha=` is a usage error (`::error::--sha needs a value`
on stderr with the usage, `Genba stamp: FAILED — cannot run` on stdout, exit 2, nothing
written — the same rule as `--hook=`); an empty variable counts as unset.

Behaviour of a plain run: hash from `--sha`/`GENBA_SHA`, else `fromGit($cwd)`
→ `write()` → re-`read()` the file and compare (the file is the only proof) →
`Genba stamp: wrote <hash> to <file>` (file as given, not absolute), then the last line
`Genba stamp: PASSED` (§3.0: every run ends with the tool's line), exit 0.
Errors (exit 1), each followed by its last line: `::error::Not a full commit hash: <value>`
(`Genba stamp: FAILED — not a full commit hash`) ·
`::error::git rev-parse HEAD failed: <stderr first line>` (`… — git rev-parse HEAD failed`; outside a
git checkout too — a finding about the working directory) ·
`::error::Cannot write <file>: <reason>` (`… — cannot write the stamp file`) ·
`::error::<file> does not contain the hash just written` (`… — the stamp file does not verify`).
Exit 2: git cannot be started (`fromGit()` throws with the code `Stamp::GIT_MISSING`) →
`::error::git not found` and `Genba stamp: FAILED — cannot run` on stdout — a missing tool, like
`curl` in genba-smoke.
`--show`: `<hash>` alone on stdout, exit 0; else `::error::No valid build stamp in <file>` and
`Genba stamp: FAILED — no valid build stamp in <file>`, exit 1.
Note: `GITHUB_SHA` is deliberately NOT used — on a PR it is a merge commit
that is never deployed; `git rev-parse HEAD` of the checkout is what the smoke
test compares with.

### 3.2 `Genba\Preflight` (src/Preflight.php — owner `stamp`, Kirby-free)

The project's own generation step before the build (SVG motifs, fonts, data
imports) — the starter's `npm run praeflug`. Genba runs it when it exists and
says visibly when it does not; the workflow line is the same for every site.

```php
final class Preflight
{
    /**
     * Which hook to run: ['command' => 'npm run praeflug', 'source' => 'GENBA_PREFLIGHT_HOOK'|'package.json']
     * or null (none configured). Order: $env['GENBA_PREFLIGHT_HOOK'] (the literal 'none' → null, visibly),
     * else package.json in $cwd with scripts.preflight → 'npm run preflight', else scripts.praeflug → 'npm run praeflug'.
     * Unreadable/invalid package.json, or "scripts" not an object → null (and run() prints a ::warning:: naming the file);
     * a valid package.json without "scripts" → null without a warning (the `--` line says no script was found).
     */
    public static function hook(array $env, string $cwd): ?array;

    /** CLI, §3.0. Runs the hook with proc_open through `sh -c` in $cwd, streaming its output to $out/$err; returns its exit code (non-zero → prints ::error::Preflight hook failed with exit <n> and returns 1). */
    public static function run(array $env, array $argv, string $cwd, $out, $err): int;
}
```

Output: `Genba preflight: running 'npm run praeflug' (from package.json)` …
hook output … `  ok   preflight hook finished`, `Genba preflight: PASSED`. Hook exit n ≠ 0:
`::error::Preflight hook failed with exit <n>`, `Genba preflight: FAILED — hook exit <n>`, exit 1.
`sh` cannot be started: `::error::Cannot start the preflight hook (sh is not available)` on
stdout and the last line `Genba preflight: FAILED — cannot run`, exit 2 (the last line
of every exit 2, §3.0; the reason is the
`::error::` line, the last line says only that nothing ran. Tested through the bin file
— `proc_open([PHP_BINARY, 'bin/genba-preflight', '--hook=true'], …)` with `PATH=/nonexistent`:
`sh` is found through PATH only, so the path needs no injectable executor.
`Preflight::findSh()` looks `sh` up on PATH itself — the first executable one, `/bin:/usr/bin`
without a PATH or with an empty one — and calls `proc_open([$sh, '-c', …])`: up to PHP 8.2 `proc_open()`
forks and execs, so a missing `sh` would otherwise be a hook exit 127; a test calls `findSh()` through Reflection). No hook:
`  --   no preflight hook configured (no GENBA_PREFLIGHT_HOOK, no package.json script "preflight" or "praeflug")`
then `Genba preflight: SKIPPED — nothing to run` exit 0. `--hook=CMD` overrides
the env variable; an empty `--hook=` is a usage error (`::error::--hook needs a value`
on stderr, exit 2 — the same rule as `--digistore-field=`; with the
usage on stderr and `Genba preflight: FAILED — cannot run` on stdout, like `--bogus`). `--help`.
`none` is named by where it came from — `  --   no preflight hook configured (--hook=none)`
or `(GENBA_PREFLIGHT_HOOK=none)`.

### 3.3 `Genba\Precheck` (src/Precheck.php — owner `precheck`, Kirby-free)

Static pre-deploy checks under the site's Content Security Policy
(`'unsafe-inline'` forbidden, nothing foreign), plus "built assets are there".
Both only show up in the browser otherwise. Every rule has a **self-test**: a
sample line it MUST match, run before the scan — a broken pattern must not
silently check nothing (that is why the bash original wrote `/tmp/gegenprobe.html`).
A rule may also carry **negative samples** it must NOT match (the negative
control of one site variant below): a pattern that has grown too wide fails the self-test as
loudly as a dead one.

The site variants (read 11.10.2026, read-only):

| | starter / three live sites (since 10.10.) | an older per-site copy (`deploy-vorpruefung.sh`, unused by its site's workflow) | that site (`scripts/pruefe-ressourcen.sh`, run by `deploy.yml`) |
|---|---|---|---|
| rules | inline style, foreign host, inline handler, embedded script | same four | **two**: inline style, foreign host |
| foreign-host pattern | `…\|//fonts\.` | `…\|//fonts\.` | `…\|//fonts\.` |
| allow list | `places.googleapis.com/v1/` (Koe's server-side endpoint) | none | none |
| self-test | one positive sample each for style and onclick, written to `/tmp` | same | positive samples for style and foreign host (four hosts) **and a negative control**: `document.fonts.ready.then(remeasure)` must NOT match |
| binary files | scanned (`grep -rEn`) | scanned | skipped (`grep -I`) |

**Directory walk (`Genba\Tree::walk()`, shared with Links).** Directories are skipped by
place: `site/cache|sessions|accounts|logs` and `public/media` only directly under their root,
`node_modules` and `.git` at any depth. Symbolic links to directories are followed when their target
(realpath) lies inside the project (`$cwd`); a link outside the project, to a directory already
entered (ancestor, loop) or a broken link is not followed. Every directory not entered is named:
`  --   not checked: <dir> (<reason>)` (`Precheck::skipped()`). A file reached twice is read once.
The skip places are also compared by real path (resolved once per walk): a link whose
target equals or lies inside one is `symbolic link into <place>`, a directory reached through a
link that lies inside one is `inside <place>, reached through a symbolic link` — for Precheck the
places of BOTH roots (`Tree::walk()`'s `$elsewhere`), so `public/p -> ..` does not reach
`site/cache`. A listed name whose `lstat()` fails (directory with read but no search permission)
is `cannot be read (permission denied)`; a directory that cannot be listed throws
`Cannot read directory: <dir>` (exit 2).
A symbolic link to a FILE follows the same rules — target outside the project
`symbolic link to a file outside the project`, inside a skipped place `symbolic link into
<place>`; named, never read. The set of real paths already read is shared across the walks
of one run (`Tree::walk()`'s `&$read`): a file reached again through `public/p -> ..` is
read once per run, not once per root or per path. All four rule patterns carry the `i`
flag, and the `unless`/allow words are compared without regard to case (`<P STYLE="x">`,
`<SCRIPT SRC="x">`).
`inline-style` matches every spelling the browser accepts (`style='x'`, `style = "x"`, and
`<p style=color:red>` without a quote — valid HTML5), `inline-handler` every `on<event>=` attribute
(`onsubmit`, `onmouseenter`, …); neither asks for a quote. Both require an attribute boundary
before the attribute name, `(^|[\s"'/])`: whitespace, the start of the line (an attribute on a
line of its own), or where the HTML tokenizer ends an attribute name — at the closing quote of the
value before (`<p class="a"style="x">`) and at a `/` (`<img src="x"/onerror="y">`); both are
`positives` of the rules. So `$style = 'card'` (a PHP variable), `data-style="x"`, `icon="x"` and
`json="y"` are not findings (`$` and `-` precede their `style`); they are the rules' `negatives`.
The allow list of `foreign-host` applies per match, not per line: every match of the
pattern (`preg_match_all`, `PREG_OFFSET_CAPTURE`) must be freed by an allow entry, else
the line is a finding — `https://places.googleapis.com/v1/x https://fonts.googleapis.com/css2` in
one line (a CSP header in `config.php`) is not free because the allowed address stands in it. A match
is free when an allow entry stands in the same address — `https://fonts.gstatic.com/s/x.woff2` has two
matches, `//fonts.` and `gstatic`, and `--allow=gstatic.com` frees both — or overlaps the match (an entry
that holds a stop character itself). Overlap, not containment: the match `//fonts.` of
`https://fonts.googleapis.com/css` begins before an entry `fonts.googleapis.com`, which must still free it.
The address (`Precheck::address()`) is
taken from the URL boundary: it begins at the last `//` at or before the match, with the scheme right
before it, and ends at the first character of `ADDRESS_STOP` (whitespace, `"`, `'`, `<`, `>`, `(`, `)`,
`` ` ``, `,`, `;`, `{`, `}`, `|`, `\`) or where the next `//` begins (its scheme left out). Two addresses
separated by a space are two addresses: the CSP-header line stays a finding. A backtick, comma or semicolon
separates too, so in a minified bundle (``a=`https://places.googleapis.com/v1/${id}`,b=`https://fonts.googleapis.com/css2` ``)
the Places URL of the default entry does not free the fonts URL beside it. A stop
character between that `//` and the match: the match stands in no address, and only an overlapping entry
frees it. The self-test checks the `positives` of `foreign-host` without and with `DEFAULT_ALLOW`; two
minified lines (addresses separated by a backtick and by a comma) are among them. A usage error (`--bogus`, `--site=`) prints every `::error::` reason and the
usage on stderr and `Genba precheck: FAILED — cannot run` on stdout (`Precheck::cannotRun()`).
Every line that holds something from the site goes through `Text::clean()` in
`Precheck::run()` — the finding (`<file>:<line>: <message>: <text>`), both `--   not checked`
loops, and the `RuntimeException` message from `scan()` (`Cannot read directory`, `Cannot read
file`) — so `<p style="x">` + ESC[2K + CR cannot paint `Genba precheck: PASSED` over the finding.
`Tree::walk()` throws `Cannot read directory: <dir>` relative to `$project` (lexically as given,
else by real path) and cleaned. The
finding line (`findingLine()`: file and text cleaned on their own), the `--` lines (`skippedLine()`:
directory and reason) and the exception message (`Cannot read file: <file>` cleaned in `scan()`) are
built of cleaned pieces and not cut as a whole: cut as a whole, a path above 2048 characters would
leave `::error::site/templates/dddd…dd… (2250 characters)`, line number and message gone.

Genba uses the anchored `//fonts\.`, ships the Koe allow entry as default
(a site with Koe installed would fail without it), runs all four
rules, skips binary files like `grep -I`, and keeps the two-rule variant's negative
control as `negatives` of the foreign-host rule. Sites add allow entries with
`--allow`. Genba's own install directory (`site/plugins/genba/`, found by the
realpath of the package root) is never scanned — its source and README hold
the rule samples — and the log says so with a `--` line.

**Since 1.1.0.** `inline-handler` carries `names` (`EVENT_HANDLERS`): a match counts when its
captured name (group `name`) — or a name attr() prints — is an event handler Genba knows; any other
on… name is no finding but an entry of `notCounted()`, printed as a `--` line (never a silent pass:
a handler added to a browser after the list shows up there). In `.php`/`.phtml` files the rules read
the text with every PHP comment blanked (`uncomment()`: tokenizer, every byte but `\n` a space, line
numbers kept; the finding shows the line as written); a file with a bare `<?` is not blanked
(short_open_tag of the checking PHP need not be the server's). Markdown files under the site root's
`plugins/` whose real path is Markdown too and lies in the real `plugins/` are not read, counted in one `--` line
(`skippedMarkdown()`, `markdownDir()`). Both attribute rules take a PHP closing tag as a boundary
(`(^|[\s"'/]|\?>)`). The self-test fails a `names` that is not a list of non-empty lower-case
strings, and `names` on a rule without a `(?<name>…)` group or with `each`.

```php
final class Precheck
{
    public const FINDING = 'finding';

    /**
     * Rules, in this order. Each: `pattern` (PCRE with `~` delimiters, applied per line), `roots` it scans
     * ('site' only, or 'site' and 'public'), `sample` (a line the rule MUST fire on — the self-test),
     * `message`. Optional `each` (PCRE): the line is judged per match of `each` (a tag), not as a
     * whole; a tag begins at every match of `start` (PCRE, default `pattern`) on the line and may
     * run on over the next 50 lines, a start without a tag in reach is a finding. Optional `wrapped` (true, without
     * `each`): a line that ends in a name is read with the lines below it up to the first that is not blank, and a
     * match counts for the line it begins on. Optional `unless` (list of PCRE patterns): a piece — the `each` match, else the line — that one
     * of them matches is NOT a finding. Optional `exempt` (with `each`): attribute name => PCRE; a tag that carries
     * one of these attributes (attributes(), read one after the other) with a value the PCRE matches is NOT a finding. For `foreign-host` the constructor's allow list applies at runtime.
     * Optional `negatives`: lines the rule must NOT fire on; `positives`: further lines it MUST fire on.
     */
    public const RULES = [
        'inline-style'    => ['pattern' => '~(^|[\s"\'/])style\s*=~i',                                                     'roots' => ['site'],           'sample' => '<p style="x">',               'message' => 'inline style — the CSP forbids it', 'positives' => ['<p class="a"style="color:red">', '<p/style="color:red">'], 'negatives' => ['<?php $style = \'card\'; ?>', '<div data-style="x">']],
        'foreign-host'    => ['pattern' => '~(googleapis|gstatic|jimcdn|instagram\.com/embed|maps\.google|//fonts\.)~i',  'roots' => ['site', 'public'], 'sample' => 'https://fonts.gstatic.com/x', 'message' => 'foreign host — brings back the consent requirement', 'negatives' => ['document.fonts.ready.then(remeasure)']],
        'inline-handler'  => ['pattern' => '~(^|[\s"\'/])on[a-z]+\s*=~i',                                                  'roots' => ['site'],           'sample' => '<button onclick="x">',        'message' => 'inline event handler — the CSP forbids it', 'positives' => ['<img src="x.png"/onerror="alert(1)">', '<a href="#"onclick="x()">', 'onclick="x()"'], 'negatives' => ['<i icon="x">', '<x json="y">', '<a href="?on=1">']],
        'embedded-script' => ['pattern' => '~<script~i', 'start' => '~<script\b~i', 'each' => '~<script\b' . self::START_TAG . '~is', 'exempt' => ['src' => '~~', 'type' => '~application/ld\+json~i'], 'roots' => ['site'], 'sample' => '<script>alert(1)</script>', 'message' => 'embedded <script> — the CSP forbids it',
                              'positives' => ['<script src="/a.js"></script><script>x()</script>', '<script>el.src="/x.js"</script>', '<script nonce="<?= $kirby->nonce() ?>">x()</script>',
                                              "<script\n  type=\"module\">", '<script'],
                              'negatives' => ['<script src="/x.js"></script>', '<SCRIPT SRC="/x.js"></SCRIPT>', '<script type="application/ld+json">{}</script>',
                                              '<script nonce="<?= $kirby->nonce() ?>" src="/a.js"></script>', '<script data-config=\'{"a":"b>c"}\' src="/a.js"></script>',
                                              "<script\n  src=\"/a.js\"\n></script>"]],
    ];
    public const EVENT_HANDLERS = ['onabort', …];             // the on… names inline-handler counts (`names`)
    public const DEFAULT_ALLOW = ['places.googleapis.com/v1/'];
    public const DEFAULT_REQUIRE = ['public/assets/css/tailwind.css'];
    /** Skipped only directly under their root, name => reason: by place, not by name (site/snippets/media/ IS scanned). */
    public const SKIP_ROOT_DIRS = ['site' => ['cache' => …, 'sessions' => …, 'accounts' => …, 'logs' => …], 'public' => ['media' => …]];
    /** Skipped at any depth, name => reason: never site code. */
    public const SKIP_ANYWHERE = ['node_modules' => 'npm dependencies', '.git' => 'version control'];

    /** @param list<string> $allow substrings; a foreign-host match in the same address (address(): from the last // before it to the first character no URL holds) as one is no finding — per match, not per line */
    public function __construct(array $allow = self::DEFAULT_ALLOW);

    /** selfTestRules(self::RULES): list<string> failures ('rule <name> did not fire on its sample', "rule <name> did not fire on its positive sample '<line>'" (for foreign-host also "… '<line>' with the default allow list"), "rule <name> fired on its negative sample '<line>'", 'rule <name>: pattern does not compile', 'rule <name> has no pattern or sample', 'rule <name>: positives is not a list', 'rule <name>: negatives is not a list'); [] = all rules work. */
    public function selfTest(): array;

    /** Same, for any rule table (tests pass a deliberately broken copy). A pattern that does not compile is a failure too ('rule <name>: pattern does not compile'). */
    public static function selfTestRules(array $rules): array;

    /**
     * Scans the files under $roots (['site' => '/abs/site', 'public' => '/abs/public']); a root that does not exist
     * throws RuntimeException("Directory not found: site"). Files with a NUL byte in the first 8 KiB are skipped as binary.
     * @return list<array{rule: string, file: string, line: int, text: string}>  file relative to $cwd, text = the trimmed line (max 200 chars)
     */
    public function scan(array $roots, string $cwd): array;

    /** @return list<array{file: string, ok: bool, bytes: ?int}>  bytes null when the file is missing (NOT 0) */
    public static function requiredFiles(array $files, string $cwd): array;

    /** CLI, §3.0. */
    public static function run(array $env, array $argv, string $cwd, $out, $err): int;
}
```

The embedded-script rule began as `grep '<script' | grep -v 'src=' | grep -v 'application/ld+json'`
per line. It is judged per `<script …>` tag (`each`), because `src=` anywhere on the line would
exempt an embedded script next to an external one (`<script src="/a.js"></script><script>x()</script>`)
and a script whose code says `el.src=` — the CSP blocks the script while the pre-deploy check
passes. A tag is exempt when it carries a `src` attribute itself (`data-src` is none) or the type
`application/ld+json`. The exemption is bound to the attribute: `exempt` (`src` with any value,
`type` with a value holding `application/ld+json`), and `attributes()` reads the tag one attribute
after the other by the grammar of `each` (a PHP block one character; names in lower case; the first
of two attributes with one name counts; `<script src>` without a value is no exemption). A pattern
over the whole tag would take a space inside a quoted value for one between attributes —
`<script data-hint="loaded with src=/a.js">` and `<script title='see application/ld+json'>` would be
exempt, inline scripts the CSP blocks. A PCRE bound to the attribute position would have had to spell the whole
grammar again for each exemption (attribute names run on over whitespace: `async src=`), the reader
spells it once. Both lines are `positives`; `<script async src=/a.js>`, `<script title="a"src="/a.js">` and
`<script/src="/a.js">` are `negatives`.
The self-test judges the `negatives` with the whole rule (`matches()`), not with the pattern
alone, and checks the `positives` the same way. `each` is `<script\b` followed by `START_TAG`, the
grammar of the HTML tokenizer built state by state (PCRE pieces `TAG_CHAR`, `TAG_FIRST`, `TAG_NAMES`,
`TAG_UNQUOTED`, a PHP block one character, possessive: one way to read a tag, no backtracking). The tag ends at the first `>` outside a quoted value and outside a PHP
block: a tag that ended at the first `>` would end `<script nonce="<?= $kirby->nonce() ?>" src="/a.js">`
at the `>` of `->`, the piece would have no `src`, and an external script of every CSP site with a
nonce would be "embedded". The nonce line and a `>` in a single-quoted JSON value are `negatives`, an
embedded script with a nonce a `positive`. A quote opens a value only as the first character behind
the `=` (whitespace aside), so in `title=it's` the `'` is part of the unquoted value. A value opened at
every quote would, with an even number of them, run one "tag" into the next one —
`<script title=it's>x()</script><script src='/a.js'>'</script>` would be one tag with a `src=`, exempt;
it is a `positive`. A tag starts at
every match of the rule's `start` (`<script\b`) on the line and may run on over the next `TAG_LINES` (50)
lines — `scan()` hands `matches()` the line and the lines below, joined with `\n`, for an `each` rule whose
pattern matches the line; the tag is the match of `each` that begins exactly at the start. A start without
one is a finding: a formatter's `<script\n  type="module">` holds no complete tag on its first line, and a
`<script` with no `>` in reach is never a silent pass. `<script\n  src="/a.js"\n></script>` stays free.
Samples may hold `\n` for the lines below: `"<script\n  type=\"module\">"` and `'<script'` are `positives`, the
wrapped external script a `negative`.
The two attribute rules, `inline-style` and `inline-handler`, are `wrapped`: the tokenizer reads any
whitespace between an attribute name and its `=`, line breaks included, so `<button style⏎="color:red">` sets
a style. A line that ends in a name (`[a-z]\s*$`, case-insensitive) is handed to `matches()` with the lines
below it up to the first that is not blank (`wrapped()`, at most `TAG_LINES`), and only a match that begins on
the line itself counts (`ownMatches()`): the finding is the line of the name, and a `style=` on the line below
is that line's finding, not the line above's as well. A name with no `=` after it (`<p style⏎  class="a">`, an
empty attribute) is none. `"<button style\n=\"color:red\">"` is a `positive`, `"<p class=\"a\"\nstyle=\"x\">"` a
`negative` — `matches()` sees the whole sample, so the negative holds the first-line rule.
Kirby's `attr()` helper prints attributes from a PHP array — a block where an
attribute stands (tag name or attribute name, `attributes()` collects them) that calls `attr([...])` /
`Html::attr([...])` is read with `token_get_all()` (`attrCalls()`), and a `src` or the JSON-LD `type` in its
literal exempts the script. The rule table closes the class: **every rule
that names an attribute reads `attr()`** — `inline-style` and `inline-handler` carry `attr` (a PCRE on the
attribute name, `~^style$~`, `~^on[a-z]+$~`), the script rule `exempt`. The two directions read differently:
a forbidding rule counts every call in the file wherever it stands (a branch of a condition may print) and
every item that is not certainly left out (Kirby's `Xml::attr()` drops `null`, `false`, `[]` / `array()`; a list item
`'defer'` prints its name; `$s ?: null` may print), read once per file (`attrNames()`, so an array literal
over several lines counts, reported on the line of its key); the exemption counts only a call that is the
whole expression of its block (`Html::` before it, `;` after it, nothing else) and only an item printed for
sure — a value with `?`, `??`, `?->`, `null` or `false` in it may print no `src`, and
`<script nonce="n">init()</script>` is embedded.

**The call forms of `attr()`.** Besides a call whose first argument is an
array literal, Kirby's `Html::attr()` (and `Xml::attr()`) also takes one name as a string,
`Html::attr(string $name, $value, $before, $after)` (`vendor/getkirby/cms/src/Toolkit/Html.php`), and PHP 8
names arguments — `<div<?= Html::attr('style', $css, ' ') ?>>` and `attr(attr: ['style' => …])` must not pass. Every
form in which `attr()` prints a name counts (`attrCalls()`): the names are the first argument by position
or the one named `attr:` (the helper) or `name:` (`Html::attr()`), the value of the single form the second by
position or `value:`. A single name runs through the same `attrItem()` as an array item (`'style' => $css`),
so `null`, `false`, `[]` / `array()` and a missing value print nothing and `certain` reads the same. Every call named
`attr` counts, as for the array form (`$obj->attr(…)` included — the louder direction). The table is the
measure; `tests/PrecheckTest.php` runs it row by row in both directions, the shipped rules carry rows of it as
samples, and the corpus variant `kirby-attr-forms` has one line per kind:

| Form | Example | Forbidding rule (`inline-style`, `inline-handler`) | Exemption (`embedded-script`) |
|---|---|---|---|
| array literal, by position | `attr(['style' => $s], ' ')`, `Html::attr(array('onclick' => 'go()'))` | counts | a `src` printed for sure frees |
| array literal, named | `attr(attr: ['style' => $s], before: ' ')`, `attr(before: ' ', attr: [...])`, `Html::attr(name: [...])` | counts | frees |
| array literal with `$value` | `Html::attr(['style' => $s], null, ' ')` | counts | frees |
| single name, by position | `Html::attr('style', $css, ' ')` | counts | `Html::attr('src', $u, ' ')` frees |
| single name, named | `Html::attr(name: 'style', value: $css)`, `Html::attr(value: $css, name: 'style')` | counts | frees |
| single name, `true` | `Html::attr('onclick', true)` (prints the name alone) | counts | a `src` without value frees nothing |
| single name, no value / `null` / `false` / `[]` / `array()` | `Html::attr('style')`, `Html::attr('style', null, ' ')`, `Html::attr('src', array(), ' ')` | nothing printed | frees nothing |
| single name, value that may be null | `Html::attr('style', $s ?: null)`, `Html::attr('src', $u ?? null)` | counts | frees nothing |
| names unknown | `attr($attributes)`, `attr(...$a)`, `Html::attr($name, $v)`, `Html::attr('on' . 'click', …)` | no finding | frees nothing |

The other three rules are
line patterns without `[^>]`; genba-smoke reads tags by the same tokenizer rules, through an HTML5 parser (§3.4). The `foreign-host` rule has no `unless` of its
own — the constructor's allow list (`DEFAULT_ALLOW` or `--allow`) is applied at scan time, so
`(new Precheck([]))` has no exception at all.

**CLI `genba-precheck`** — `--site=DIR` (default `site`), `--public=DIR`
(default `public`), `--allow=a,b` / `GENBA_ALLOW` (ADDED to DEFAULT_ALLOW;
the literal `none` clears the default too), `--require=f1,f2` /
`GENBA_REQUIRE_FILES` (default `public/assets/css/tailwind.css`, a list replaces it; literal `none`
= no file check, printed as `  --   no required files configured (GENBA_REQUIRE_FILES=none)` or
`(--require=none)` — named by its origin, as in genba-preflight; `none` next to
other entries is no file name, like in `--allow`: it drops the default, the other entries count
(`--require=none,public/x.css` checks `public/x.css`);
a value that names no file, such as `,`, is a usage error, exit 2 — the `::error::<--require|GENBA_REQUIRE_FILES> lists no file: '<value>' (use none to switch the file check off)` (named by where the value came from)
and the last line `Genba precheck: FAILED — cannot run` on stdout, the usage on stderr),
`--help`. Order: `  ok   self-test: 4 rules fire on their samples and stay silent on their
negative samples`, or fail → `::error::Self-test failed: rule <name> did
not fire on its sample — pattern broken` per rule and the last line `Genba precheck: FAILED —
cannot run`, exit 2 (§3.0
asks for one last line on every exit 2, the reason stays in the `::error::` line. Deliberately
without a test, unlike genba-preflight's exit-2 path: `run()`
reads `self::RULES`, a constant; `selfTestRules()` is tested with a broken copy, and an injectable
rule table for one line is not worth it) → scan (missing root →
`::error::Directory not found: <dir>` exit 2) → required files. Finding line:
`::error::<file>:<line>: <message>: <text>`. Required file:
`  ok   <file> (<bytes> bytes)` / `::error::Required file missing or empty: <file>`.
Summary: `Genba precheck: PASSED (<n> files scanned)` or
`Genba precheck: FAILED — <n> findings, <m> required files missing` (omit the
part that is zero, keep the other). Zero files scanned with existing roots →
`::error::No files found under <roots>` exit 2.

### 3.4 `bin/genba-smoke` (bash — owner `smoke`)

Port of `../kirby-starter/scripts/smoke-test.sh` (the 300-line variant, all
seven checks), English, `GENBA_*` names, `set -euo pipefail` (the original has
`set -uo pipefail` — with `-e` every command whose failure is expected gets
`|| true` or sits in an `if`). Keep the structure, the curl handling (`-q -g -sS
--noproxy '*' --max-time 25 --proto '=http,https'` — `-q` first, so `~/.curlrc` is never
read; `-g`, so a bracket in a page URL is not a range; `--noproxy '*'`, so no
proxy from the environment (`http_proxy`, `HTTPS_PROXY`, `ALL_PROXY`) is used, the same
rule as `Links` (`CURLOPT_PROXY` `''`): behind a proxy the proxy resolves the name, curl
exit 6 never happens and a pending DNS entry read as 14 findings with "Proxy CONNECT
aborted"; and a proxy could redirect a request that carries credentials — never `-L`,
credentials only via a `-K` config file with `umask 077`, never to a
different origin, never over http: with credentials, a non-https `GENBA_URL`
is refused with exit 2 unless its host is 127.0.0.1, localhost or [::1]), the
`resolve()` URL function (awk) and the asset logic. Translate every
message exactly as listed below — `tests/SmokeTest.php` greps for them.

A status code proves little: after a half upload, on the hoster's default page
or with an old build the server also answers 200. So the content is measured:

| # | Check | Settings |
|---|---|---|
| 0 | (decision 19) Reachability gate, optional: with `GENBA_EXPECT_PROTECTED=1` the FIRST request goes out WITHOUT credentials and must be 401 (else a finding). A name that does not resolve (curl exit 6, on the gate request or the first homepage request) is a finding and ends the run — or, with `GENBA_NOT_READY=skip`, a `::warning::` + last line `Genba smoke: SKIPPED — <host> does not resolve yet`, exit 0. With `skip`, an https connection that fails (000) while `GENBA_HTTP_URL`/`http://<host>/` answers (401 when protection is expected; another code then is a finding) is `SKIPPED — https to <host> fails (certificate pending?)`, exit 0. Decided ONCE, on the first request of the run: the gate's when it runs (the homepage loop then never decides it), else the first homepage request. Only a connection that cannot be set up counts — curl exit 7, 35, 51, 58, 59, 60, 77, 83, 90, 91; never 28 (timeout), 52, 56, and never a certificate whose curl error says `certificate has expired`: those are findings. A gate request without answer is `::error::Without credentials <url>/ gives no answer (HTTP 000) — <curl error> — the preview protection is not verified` | `GENBA_EXPECT_PROTECTED` (`0`\|`1`, default `0`), `GENBA_NOT_READY` (`fail`\|`skip`, default `fail`) |
| 1 | Homepage `GENBA_URL/` is 200, carries `<meta name="<meta>" content="<build>">` whose content STARTS WITH the expected build (`GENBA_EXPECTED_BUILD` may be an abbreviated hash, 7–64 digits, compared as a prefix — `ok   Homepage serves build <live hash>`), and the marker text. Up to `GENBA_RETRIES` × `GENBA_PAUSE` s while a cache still holds the old build (a 401 ends the retries at once). The `<meta>` is one record of the homepage reader (`read_homepage`, `bin/genba-smoke-read`, `src/Homepage.php`, see "Reading the homepage" below): the first `<meta>` in the HTML namespace whose `name` equals `GENBA_META` (as written) and whose `content` is hex digits only (an empty `content` counts as absent) — in any spelling the HTML5 parser reads the same (capitals, missing quotes, `content` before `name`) | `GENBA_EXPECTED_BUILD` (whitespace dropped, like `GENBA_SHA`), `GENBA_META` (default `build`; `[A-Za-z0-9_-]+` like `Options::metaName()`, else exit 2), `GENBA_MARKER` (default `assets/css/tailwind.css`; empty = not checked, printed as `  --   marker not checked (GENBA_MARKER empty)`; looked for anywhere in the HTML, comments included — `start.text`, the body on one line with nothing dropped, so a marker in a comment such as `<!-- site name -->` counts) |
| 2 | An unknown address `/genba-smoke-does-not-exist-$$-$RANDOM` returns 404 | — |
| 3 | `robots.txt`, `sitemap.xml`, `llms.txt`: GET **and** HEAD 200 with the right Content-Type (`text/plain` · `application/xml\|text/xml` · `text/plain\|text/markdown`) and content pattern (`^Sitemap: ` · `<urlset` · `^# `). ONE GET per file: its body is the one the pattern and check 4 read — a second GET could answer differently and, unread, pass. The sitemap GET runs with `--max-filesize 10485760`: a larger body is `::error::GET /sitemap.xml: larger than 10485760 bytes — not read` (also when curl read it to its end — below 8.4 `--max-filesize` stops only a transfer whose size is announced; `fetch()` holds the size on disk against `MAX_BYTES` and sets 63 itself) | — |
| 4 | Every sitemap page (up to `GENBA_PAGES_MAX`) is 200; a `<loc>` on a foreign origin is (decision 20) rewritten to the `GENBA_URL` origin with path and query kept and fetched there with the same credentials, announced once as `::notice::Sitemap lists <n> pages on <origins> instead of <origin> — rewritten …` (`rewrite`), or a warning (`warn`), or a finding (`fail`, site URL misconfigured). Unset or empty, the mode is `rewrite` on a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set) and `fail` otherwise, named once right after the first line: `  --   GENBA_FOREIGN_SITEMAP not set: rewrite (a preview run: GENBA_EXPECT_PROTECTED=1 or GENBA_USER set)` / `  --   GENBA_FOREIGN_SITEMAP not set: fail (not a preview run: neither GENBA_EXPECT_PROTECTED=1 nor GENBA_USER)`. With `GENBA_SITEMAP_ORIGIN` an entry on that origin follows the mode, an entry on any third origin is always `::error::Sitemap points to a foreign host: <loc> (neither <origin> nor GENBA_SITEMAP_ORIGIN <origin>)`. An empty sitemap is a finding; no page fetched at all (every entry foreign, warn mode) is a `::warning::`, never `ok   0 …`. A sitemap body that is missing or has no `<urlset` (the finding is already printed by check 3) is `::error::Sitemap not available for page check` — the block is never skipped silently. The body is flattened to one line (`tr -d '\n\r'`) — a pretty-printed sitemap puts the address on its own line, where a line-wise `grep` loses it without a word —, CDATA directly inside `<loc>` unwrapped and whitespace around the address dropped before `grep -o '<loc>[^<]*</loc>'` cuts the entries out; comments are dropped first (`strip_comments` `s/<!--([^-]|-[^-]|--+[^->])*--+>//g` — a comment ends at the first `-->`, `--->` included), so a commented-out `<url>` is neither counted nor fetched. The number of `<url>` elements is held against the entries read, a difference is `::error::Sitemap: <n> entries could not be parsed (<u> <url> entries, <l> <loc> addresses)`. The `<loc>` values are decoded (`decode_entities`: `&lt; &gt; &quot; &apos;` and the numeric references `&#NN;`/`&#xHH;` of `& < > " ' # ? =`, then `&amp;`/`&#38;`/`&#x26;`; everything else unchanged), percent-encoded (`encode_controls`, see "Log text" below: C0/DEL/C1 encoded, bytes that are no UTF-8 U+FFFD), and an entry longer than `LOC_MAX` 2048 characters is `::error::Sitemap entry longer than 2048 characters (<n>), not fetched: <first 60 chars>…` (`too_long`). Origins (`same_origin`, `GENBA_SITEMAP_ORIGIN`, the http→https target) are compared normalised: scheme and host lower case, `:80`/`:443` dropped. `decode_entities` decodes each reference exactly once: the three spellings of `&` are ONE final `s/&(amp\|#0*38\|#[xX]0*26);/\&/g`, so `&amp;#38;` becomes the text `&#38;`, not `&`. A reference still there after `decode_entities` — `reference_re` `&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);`: `&#233;`, `&#47;`, `&eacute;`, the text `&#38;` of a doubly escaped `&` included — is `::error::Sitemap entry with a character reference that is not decoded: <loc> — not fetched` (`undecoded`): curl reads the `#` as the start of the fragment and requests a cut address (`/blog&#47;neu` as `/blog&`, `?v=1&amp;#38;w=2` as `?v=1&`). Never a cut address; the minimal variant (a finding) was chosen over a decoder for every character. An entry that does not start with `<scheme>://` is `::error::Sitemap entry is not an absolute URL: <loc> — the sitemap protocol requires scheme and host` in every mode, never "foreign host", never fetched on `$ORIGIN`. An entry that is empty after `decode_entities` and `encode_controls` (`<loc></loc>`, whitespace or control characters only) is `::error::Sitemap entry is empty (<loc> without an address)` — the `<url>`/`<loc>` count does not catch it, the element is there. With `fetched == 0` the reason is named by what happened: `foreign_skipped > 0` (entries skipped in warn mode) → the `::warning::No sitemap page fetched: the entries point to a foreign host …`; else every entry is a finding already and the run adds `::error::No sitemap page fetched: every entry is a finding (see above) — the pages of <url> are NOT checked` — a count of zero never passes, and the warning never blames a foreign host for empty or relative entries. When `head -n GENBA_PAGES_MAX` cut the list, the ok line is `  ok   <n> of <loc_count> sitemap pages fetched (GENBA_PAGES_MAX=<max>), the rest not checked` | `GENBA_PAGES_MAX` (30), `GENBA_FOREIGN_SITEMAP` (`rewrite`\|`warn`\|`fail`, default see left), `GENBA_SITEMAP_ORIGIN` (`https://host[:port]`, no path, trailing `/` dropped; else exit 2) |
| 5 | Only on a homepage that was read: larger than `BODY_MAX_BYTES` → `  --   stylesheets, images and fonts not checked: the homepage is larger than 10485760 bytes and was not read`, no answer (000) → `  --   stylesheets, images and fonts not checked: the homepage gave no answer (HTTP 000)`, not 200 → `  --   stylesheets, images and fonts not checked: the homepage answered HTTP <code>, not 200` — one line with the reason, as check 8 says it, not three type lines `no .webp file found on the homepage`, which would name the wrong cause (the finding about the homepage stands above). Stylesheets (200, `text/css`, not empty; none of the own origin → `  --   no stylesheet of <origin> found on the homepage, stylesheets not checked`), then up to five `.webp`, `.avif`, `.woff2` OF THE OWN ORIGIN (filtered first, then the five) from the homepage and the own stylesheets with the right Content-Type (`image/webp`, `image/avif`, `font/woff2`). None of a type found → `  --   no .webp file found on the homepage, Content-Type not checked`; only foreign ones → `  --   no .webp file of <origin> found on the homepage (<n> on other origins, not requested), Content-Type not checked`. More than five own files of a type: `  --   <n> .<ext> files of <origin> on the homepage, the first 5 checked (the server configuration is tested, not every file)`. An address above `LOC_MAX` is `::error::Stylesheet address longer than 2048 characters (<n>), not fetched: <first 60>…` / `::error::Asset address longer than …` (`too_long`), the stylesheet body file is removed before each fetch. The addresses of the homepage are the `link`, `src` and `srcset` records of the reader (see "Reading the homepage" below): the `href` of every `<link>` whose `rel` holds the token `stylesheet` or `preload` (ASCII case-insensitive; `rel=" stylesheet"` holds the token), every `src`, every candidate of every `srcset` — decoded by the parser (every character reference, as the browser decodes it), trimmed, control characters encoded (see "Log text" below). A `data:` URI is skipped in any case; every other address goes through `resolve()` against the base: `GENBA_URL/`, or — for the addresses after it in document order (the browser fetches those before it against the page's address) — the first `<base href>` resolved against it, said as `  --   <base href="<href>"> on the homepage: the addresses after it are resolved against <url>`; a `data:` or `javascript:` base (any case) is ignored as the browser ignores it ("set the frozen base URL"), `  --   <base href="<href>"> on the homepage is ignored, as the browser ignores a data: or javascript: base`; a `<base href>` with any other scheme than http(s) is `::error::Homepage <base href="<href>"> is no http(s) address — the addresses after it are not checked`. `resolve()` takes a reference with a scheme in any case as absolute (`bytes_match`). The kind of a file is read without regard to case (`grep -iE '\.css(\?|$)'`). A `url()` in a stylesheet is CSS, not HTML — no entities, taken as written. **Safety net (§3.0 "no silent passes"):** when `GENBA_MARKER` ends in `.css` (any case; the default `assets/css/tailwind.css` does) and stands in `start.text`, and the homepage answered 200, some stylesheet of the own origin read from the `<link>` records must hold the marker (case-insensitive substring) — else `::error::Homepage contains the stylesheet address '<marker>' (GENBA_MARKER), but no stylesheet of <origin> with that address was read from its <link> elements — the stylesheet is not checked (loaded by a script, from another origin, or read wrongly?)`. A finding, not a warning: a reader error that loses a `<link>` would end with the `--` line and PASSED, and a warning would leave the run green. A site whose stylesheet is not in a `<link>` of its own origin sets a marker that is no `.css` address. Tested with a reader broken on purpose (`tests/SmokeTest.php`: a copy whose `Homepage.php` drops the `<link>` records → exit 1). **Staging that names production:** a staging host whose site URL is production writes its stylesheet and images with the production origin, and the net alone would make every such preview run red. On a rewrite run (`GENBA_FOREIGN_SITEMAP=rewrite`, explicit or the preview default) with `GENBA_SITEMAP_ORIGIN`, every address of check 5 on that origin — homepage and `url()` alike, `add_asset` — is the same path and query on the `GENBA_URL` origin (`on_own_origin`, the sitemap's rewrite) and is checked there, said once: `::notice::The homepage and its stylesheets name <n> addresses on <GENBA_SITEMAP_ORIGIN> instead of <origin> — rewritten to <origin> (path and query kept) and checked there (GENBA_FOREIGN_SITEMAP=rewrite, GENBA_SITEMAP_ORIGIN)`. Only the declared origin, never in rewrite mode alone: a CDN address on staging would be a false 404. Without it the net reports the stylesheet (README, Staging). | `GENBA_MARKER` (the net), `GENBA_SITEMAP_ORIGIN`, `GENBA_FOREIGN_SITEMAP` |
| 6 | `http://<host>/` redirects 301/308 to `https://<host>` (no credentials sent) | `GENBA_HTTPS_REDIRECT` (`require`\|`warn`\|`off`, default `require`), `GENBA_HTTP_URL` (tests only) |
| 7 | `/api/system` reports `"isInstalled":true` and `"isInstallable":false`. An installable Panel is ALWAYS a finding; a missing account follows the mode. The API is read at `GENBA_API_PATH` + `/system` (default `/api/system`) | `GENBA_PANEL_ACCOUNT` (`require`\|`warn`\|`off`, default `require`), `GENBA_API_PATH` (default `/api`) |
| 7b | `/panel` answers 200 — Kirby sends a visitor who is not signed in on with 302 and `Refresh: 0; url=…` (or a `Location`), read from the headers of the same request (`fetch … HEADERS`, `/panel` is asked once). The target of a `Refresh` starts right after the `url=` that follows the seconds and runs to the end, quotes around it dropped (an `url=` in its query is part of it). Kirby writes it absolute, from its `url` option: only its path counts (`resolve()`, dot segments resolved), and under `/panel` of `GENBA_URL` it is followed once on the `GENBA_URL` origin — another origin (staging with the production URL, `localhost` against `127.0.0.1`) is said as `  --   /panel sends on to <target> — checked as <path> on <origin> (Kirby's url option names another origin)`, the sitemap's rewrite; any other path is `::error::/panel: HTTP <code> to '<location>', not to a page under /panel` — and every JS and CSS file the page names under `/media/panel` or `/media/plugins` (`grep -o`, `.js`, `.mjs`, `.css`) answers 200 with `text/javascript`, `application/javascript` or `application/x-javascript` (a `.css`: `text/css`) and a body that is not empty — except Kirby's plugin bundles `/media/plugins/index.css` and `/media/plugins/index.js`, empty when no plugin brings Panel code (`?0`): a fallback route that answers every path with an HTML page, 200, is `::error::Panel file <path>: HTTP 200, '<type>', <bytes> bytes — expected <types>, not empty`. A page that names no such file is a finding. `  ok   Panel: /panel answers 200, its <n> files arrive` | `GENBA_PANEL_ACCOUNT` (`off` turns 7 and 7b off), `GENBA_PANEL_PATH` (the Panel's address, default `/panel`: every `/panel` above reads as the configured path, messages included) |
| 8 | (decision 22) Private paths: none of them answers 2xx (an executed config.php answers 200 with an empty body) or 000; sent WITH the credentials. Default list (a live site's deploy workflow, extended): `.env .git/HEAD composer.json composer.lock site/ site/config/config.php site/config/credentials.php site/config/kizami.php site/config/kennzahlen.php content/ content/site.txt kirby/bootstrap.php vendor/ vendor/autoload.php storage/ storage/kizami/kizami.sqlite storage/kennzahlen/kennzahlen.sqlite`. `  ok   <n> private paths not reachable (none answers 2xx)`; homepage without answer → `  --   private paths not checked: the homepage gave no answer (HTTP 000)`, not 200 → `  --   private paths not checked: the homepage answered HTTP <code>, not 200` | `GENBA_PRIVATE_PATHS` (comma/space list ADDED to the default; entry `none` drops the default; `none` alone → `  --   private paths not checked (GENBA_PRIVATE_PATHS=none)`; an entry with `..` or outside `[A-Za-z0-9._~/-]` → exit 2) |
| 9 | Response headers. `GENBA_HEADERS` is read before the first request — a broken line is exit 2, never a silent pass: one rule per line, `[/path] Name: rule`, blank lines and `#` lines skipped, the path `/` by default. One request per rule on its path, with the credentials, no redirect followed; the header is read from that response, the name without case, several lines of one name joined with `, `. Rules: a value (exact), `*` (present), `none` (absent), `~text` (contains), `!~text` (sent and does not contain — a header not sent is a finding: a CSP that got lost never passes `!~unsafe-inline`); `~` or `!~` without text is exit 2 (`~` alone would be true of every value). The path must answer 2xx or 3xx (`/panel` answers 302): any other code is `::error::<label>: HTTP <code> instead of 2xx or 3xx — the header was not checked (path wrong?)`, no answer is `::error::<label>: no answer (HTTP 000)[ — <curl error>]` — never a header read from an error page. `<label>` is `homepage <Name>` for `/`, else `<path> <Name>`. Unset: `  --   response headers not checked (GENBA_HEADERS not set)` | `GENBA_HEADERS` |

Order in the script: 0, 1, 2, 3, 4, 5, 7, 7b, 8, 9, 6 (the original's 1–7 order, gate first, private paths and headers before http).

**Log text.** Everything the checked site
sends can end up in an `ok`/`--`/`::warning::`/`::error::` line or the last line, and an ESC, a
CR (`Genba smoke: PASSED.` behind it) or a line break (`::error::forged` after it) in such a
value rewrites the log or forges an annotation. ONE filter for all of it: `strip_controls`
(stream stage: C0 except the newline that separates records, DEL, and C1 U+0080–U+009F as UTF-8
`\302\200`–`\302\237` via `LC_ALL=C awk`, byte-wise, BSD awk, gawk, mawk and busybox awk alike;
also a raw byte `\200`–`\237` that is not part of a well-formed UTF-8 character — a
Latin-1 page, or `\233`, the 8-bit CSI — while `ß` `\303\237` and `€` `\342\202\254` stay; a line
with such a byte is walked byte by byte, linear; `tr` runs with `LC_ALL=C` too, because macOS
`tr` in a UTF-8 locale stops at the first such byte and loses the rest of the stream), `log_text` (one
value: the same plus the newline, and a value above `LOC_MAX` cut to its first 60 characters with
its length), `too_long` (an address above `LOC_MAX`: finding, not fetched). An
**address** is not filtered but encoded, as the URL parser takes it — leading and trailing C0 and spaces
stripped, tab, LF and CR dropped, every other C0, DEL and C1 percent-encoded (`%01`, `%7F`, `%C2%85`; a NUL
dropped: CSS makes it U+FFFD, XML forbids it, awk cannot hold it): dropping them would turn the address the browser
requests as `/a%01b.css` into `/ab.css`, and a file that exists under the shorter name would pass. One rule in both
readers: `Homepage::url()` (`href`/`src`/`srcset`/`base`) and `encode_controls` (bash, the UTF-8 walk of
`strip_controls`: `<loc>` in `pages.txt`, `url()` in `cssrefs.txt`). Encoded is log text too.
**Bytes that are no UTF-8** are U+FFFD — a stylesheet is read as UTF-8 whatever it declares
(Kirby writes UTF-8), as the browser reads one without a charset parameter and requests `%EF%BF%BD`; the
homepage reader gets U+FFFD from Lexbor, and a `<loc>` that is no UTF-8 is no XML. No raw byte reaches the
request or the log line.
`encode_controls` writes `%EF%BF%BD` (`Homepage::url()` keeps the character, which curl sends as `%EF%BF%BD`):
one per maximal subpart, as the Encoding Standard's UTF-8 decoder — a lead byte with the continuation bytes
that fit it is one, a byte that starts nothing is one, the byte that broke a sequence starts the next. Only a
line of printable ASCII passes unwalked (a line without a control byte may still hold a lone `\303`). The table is the measure; `tests/SmokeTest.php` runs `encode_controls` on it with every awk on the
machine (BSD awk, gawk, mawk, busybox where installed) and, on PHP 8.4+, `Homepage::read()` on the same rows:

| Bytes | Address |
|---|---|
| `\001`, `\177`, `\302\205` (C0, DEL, C1) | `%01`, `%7F`, `%C2%85` |
| `ß` `\303\237`, `€` `\342\202\254`, `\360\237\230\200`, U+FFFD `\357\277\275` | kept as they are |
| `\232`, `\240` (a continuation byte alone), `\365`, `\377` (no lead byte) | `%EF%BF%BD` |
| `\303` before ASCII or at the end, `\351\233` before ASCII, `\342\202`, `\360\237\230` (cut short) | `%EF%BF%BD` (one) |
| `\300\257`, `\340\200\200` (overlong), `\355\240\200` (surrogate), `\364\220\200\200` (above U+10FFFF) | one `%EF%BF%BD` per byte (2, 3, 3, 4) |
| leading and trailing C0 and spaces, tab, CR | dropped |
| NUL | dropped (awk cannot hold it) | Filtered
(`strip_controls`/`log_text`): the Content-Type
(`TYPE` in `fetch()`), the redirect target (`%{redirect_url}`), the build hash from the `<meta>`
(the reader admits hex digits only, but any number of them), the first line of `curl.err` at
the gate and the homepage. Needs no filter, checked: `%{http_code}` (digits), `isInstalled`/
`isInstallable` (grep on `true|false`), byte counts, every path, host and setting of the run.
Never printed: status line, reason phrase, other response headers, body excerpts; a sitemap
index is "no `<urlset`", a static message. Tests: `esc-href` (ESC, CR, U+0085), `raw-c1-href`
and `raw-c1-redirect` (raw `\233` at the start, after ASCII, after `ß`, after a non-ASCII byte), `esc-src`,
`esc-ctype`, `esc-redirect`, `esc-build`, `long-src`, `long-href`, `long-build`, and
`tests/fixtures/raw-server.php` for a `Location` with a bare CR or a line break, which PHP's
`header()` refuses to send.

**Reading the homepage (decision 24).** curl fetches, PHP reads: `read_homepage FILE TEXT RECORDS` writes `start.text` (the body on one line, CR dropped, LF and tab as spaces — the marker) and runs `php -d display_errors=stderr bin/genba-smoke-read --meta=<GENBA_META> FILE` (`src/Homepage.php`), whose stdout is `home.txt`, one record per line `<kind>\t<value>`: `build`, `base` (at most one each), `link`, `src`, `srcset`, in the order of the elements. The reader parses with `Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8')` (Lexbor, PHP 8.4+; the page is read as UTF-8 whatever it declares, a byte that is no UTF-8 is U+FFFD) and walks the tree without recursion: only elements in the HTML namespace, nothing inside `<template>` — except a declarative shadow root (`shadowrootmode` open or closed, ASCII case-insensitive, on a host `attachShadow()` accepts — article, aside, blockquote, body, div, footer, h1–h6, header, main, nav, p, section, span, or a valid custom element name, which is none of the eight reserved names with a hyphen, `annotation-xml`, `color-profile`, `font-face`, `font-face-src`, `font-face-uri`, `font-face-format`, `font-face-name`, `missing-glyph` (`RESERVED_CUSTOM`; the browser throws there and leaves the template inert; table in `tests/HomepageTest.php`; Chromium's preload scanner may still request what such a template names, as in every `<template shadowrootmode>`, on `<a>` too (measured), but the page never shows or uses it: the reader follows the rendering, not the speculative fetch) —, the first of its host; Lexbor keeps it as template content, reachable only as `innerHTML`, so it is parsed again in a `div` of an empty document and walked, a `<base>` and the build `<meta>` there not counting); `<noscript>` is read as markup (the parser runs with scripting off — the content a browser without script loads is checked too, the louder direction); `rel` as an ASCII-case-insensitive token list; an attribute value as the URL parser takes it (`Homepage::url()`, see "Log text" above) — leading and trailing C0 and spaces stripped, tab and line breaks dropped, every other control character percent-encoded; `srcset` by "parse a srcset attribute" (`Homepage::srcset()`: candidates at commas, a comma inside a descriptor's parentheses separates nothing, invalid descriptors drop the candidate), never `src`. Before the first request `genba-smoke` runs `genba-smoke-read --check`: PHP below 8.4 → `::error::The homepage reader cannot run: genba-smoke needs PHP 8.4 or later on the machine that runs it (it reads the homepage with Dom\HTMLDocument), this is PHP <version> — usually the CI runner, not the web server: setup-php with php-version: '8.4'`, exit 2; `mbstring` missing (or a function of it disabled) → `… cannot run: PHP extension mbstring is missing[ (<function> is not available)]`; no `php` → `::error::php not found (genba-smoke reads the homepage with PHP: bin/genba-smoke-read)`. A reader that fails on the saved page is `::error::The homepage cannot be read: <its stderr line>`, exit 2. Bounds — the homepage is fetched with `--max-filesize` `BODY_MAX_BYTES` (10 MiB, as the sitemap; the reader holds about 40 × the page, `memoryFor()`; curl below 8.4 — Ubuntu 22.04, Debian 12 — stops there only when the size is announced and reads a page without `Content-Length` to its end, so `fetch()` also holds the size on disk against the limit): 63 is `::error::Homepage larger than 10485760 bytes — not read: its build, its marker and its addresses are not checked`, no retry, no reader, no marker or net finding; and the reader runs in the background under a watchdog (`reader_watch`, `sleep` + `kill`, bash 3.2 has no `timeout`; its output on `/dev/null` so no sleep holds the caller's pipe; `wait … 2>/dev/null` keeps bash's "Terminated" job message out of the log) for at most `GENBA_MAX_TIME` seconds — Lexbor is quadratic in the nesting depth (80 000 nested `<div>`: 16 s) and `max_execution_time` does not reach into it: `::error::The homepage cannot be read: the reader did not finish within <n> seconds (GENBA_MAX_TIME)`, exit 2. `bin/genba-smoke-read` is found next to the real `genba-smoke` (symbolic links resolved; Composer's `vendor/bin` proxy `exec`s the real file) and is not in composer.json's `bin`.

**Failed transfers.** `fetch()` reads curl's status code only when curl
ends with exit 0 (or 63, the size finding the caller reports itself). Any other exit — 18
`transfer closed with N bytes remaining to read` after a 200, 28 timed out after the headers
with no byte of the body, 56 — sets `CODE=000`, removes the output file (a cut body is never
read: a cut sitemap would be checked "completely" with fewer pages, a cut homepage whose
`<head>` arrived would serve the build) and keeps curl's first stderr line as log text in
`FETCH_ERROR`. ONE reader of that text, `curl_error FILE` (the gate's probe and `fetch()`
alike), ONE writer into findings, `fetch_detail` (` — <text>`): appended to every finding that
names the code of a fetch — homepage, unknown address, `check_file` (`GET /sitemap.xml: HTTP 000
instead of 200 — curl: (18) …`), sitemap pages, stylesheets, assets, `/api/system`, private paths — and the
http→https check, whose own curl call keeps its stderr in `http.err` and names it with `'000'`. With the file gone and
the code 000 nothing greps a body that did not arrive (`grep: … No such file` on stderr is
gone). `not_ready()` is unchanged: a 000 from a timeout or a broken transfer is never "not ready".

**Bodies with a NUL byte.** Every `grep` on a fetched body — `start.text` (the marker; the homepage's elements are read by the parser), the `check_file` pattern, `sitemap.xml`
(`<urlset`) and `sitemap.flat` (`<loc>`, `<url>`), `style.css` (`url()`), `system.json`
(`isInstalled`/`isInstallable`) — runs with `-a`: without it GNU grep prints nothing on stdout
for a "binary" file and BSD grep answers `Binary file … matches` (even `-q` fails on macOS),
so a page with a NUL byte would read as `Homepage without <meta>`, marker missing, no stylesheet,
`Sitemap lists no pages` — loud, with the wrong cause. The greps on `assets.txt`/`typ-all.txt`
need no `-a`: those files hold `strip_controls` output. Fixture fault `nul-home` → PASSED.

**Bytes that are no UTF-8.** Text from the site is processed byte-wise:
every `sed`, `tr`, `grep`, `awk`, `cut`, `sort` and `paste` that reads it runs with `LC_ALL=C`,
per call. In a UTF-8 locale macOS `tr`, `sed`, `cut` and `sort` stop at a byte such as
`\377` (`tr: Illegal byte sequence`), `paste` and BSD `grep -o` cut the text without a word,
and the run would end without its closing line. Not once for the whole script: bash keeps the
caller's locale, so `log_text` and `too_long` count and cut characters, not bytes. Fixture
faults `invalid-byte-sitemap`, `invalid-byte-home`, run with `LC_ALL=en_US.UTF-8` (Linux:
`C.UTF-8`) → PASSED. Bash's own `[[ … =~ … ]]` is a matcher too — in a UTF-8
locale macOS bash 3.2 gives up on a text with such a byte (`/caf\377&#233;` would escape `undecoded` and
be fetched as the cut `/caf\377&`; `http://\377.example/` would read as "not an absolute URL"). Every `=~`
on text from the site goes through `bytes_match TEXT ERE`, a function whose body is a subshell with
`LC_ALL=C` — the reference check (`undecoded`) and the absolute-URL check of a sitemap entry; the
other `=~` read settings. The locale of the run itself stays.

**Settings**: positional argument 1 = URL (same as `GENBA_URL`; an empty argument is
`::error::URL argument is empty (set GENBA_URL or pass the address)`, exit 2); `--help`.
Required: `GENBA_URL` (credentials in it, `user:pw@host`, → `::error::GENBA_URL must not contain credentials (user:password@host) — use GENBA_USER and GENBA_PASSWORD`
exit 2, the URL is not echoed). `GENBA_EXPECTED_BUILD` defaults to `GENBA_SHA` (same precedence as
`genba-stamp`), then to `git rev-parse HEAD` in the working directory; if that fails: `::error::GENBA_EXPECTED_BUILD not set and the working directory is not a git checkout` exit 2; without git: `::error::GENBA_EXPECTED_BUILD not set and git not found` exit 2.
Basic Auth for a protected staging/preview host: `GENBA_USER` + `GENBA_PASSWORD`
(both or neither: `::error::GENBA_USER and GENBA_PASSWORD must be set together` exit 2).
`GENBA_WITHOUT_CREDENTIALS` = `fail` (default) | `skip`: with `skip`, a homepage
401 without credentials ends the run with
`::warning::Genba smoke: SKIPPED — <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set. Whether the new build arrived is NOT verified.`
and exit 0 (the preview workflow's "secrets missing" case, now inside the
tool and visibly marked) — only while `FAILED == 0`; with a finding
already printed (the gate got no answer, then 401) it is
`  --   <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set — not SKIPPED: the run has findings already`
and `Genba smoke: FAILED — see findings above.`, exit 1. A line break (LF or
CR) inside `GENBA_USER` or `GENBA_PASSWORD` is refused before the first
request (`::error::GENBA_USER/GENBA_PASSWORD must not contain a line break (a secret set from a file keeps the file's line breaks)`,
exit 2, the value not echoed): curl reads its `-K` file line by line, a LF
ended the quoted value early (curl exit 26, every request HTTP 000) and a CR
would travel inside the password. Should `fetch()` still see curl exit 26, it
is `::error::curl cannot read its credentials file (curl exit 26) — GENBA_USER/GENBA_PASSWORD were not sent; check them for characters curl's -K file cannot hold`,
`cannot_run`. Validation errors (exit 2; every one of them,
`curl not found` and `Unknown argument` included, is followed by the last line
`Genba smoke: FAILED — cannot run` on stdout — `cannot_run()`):
`::error::GENBA_URL missing (base address of the site)` ·
`::error::Unknown argument: <argument>` (usage on stderr) ·
`::error::Only one URL argument is allowed` ·
`::error::URL argument is empty (set GENBA_URL or pass the address)` (an empty positional
argument is a usage error like an empty `--option=`, never a silent fall-back to `GENBA_URL`) ·
`::error::GENBA_PAGES_MAX must be a whole number above 0: <value>` ·
`::error::GENBA_RETRIES must be a whole number above 0: <value>` ·
`::error::GENBA_PAUSE must be a whole number of seconds: <value>` ·
`::error::GENBA_EXPECTED_BUILD is not a commit hash: <value>` ·
`::error::GENBA_META must match [A-Za-z0-9_-]+ (the name of the <meta> element): <value>` ·
`::error::GENBA_URL must start with http:// or https://` ·
`::error::GENBA_HTTPS_REDIRECT must be require, warn or off` ·
`::error::GENBA_PANEL_ACCOUNT must be require, warn or off` ·
`::error::GENBA_PANEL_PATH must be a path below the site root: <value>` ·
`::error::GENBA_API_PATH must be a path below the site root: <value>` ·
`::error::GENBA_FOREIGN_SITEMAP must be rewrite, warn or fail` ·
`::error::GENBA_SITEMAP_ORIGIN must be an origin without path (https://host or https://host:port): <value>` ·
`::error::GENBA_WITHOUT_CREDENTIALS must be fail or skip` ·
`::error::GENBA_EXPECT_PROTECTED must be 0 or 1` ·
`::error::GENBA_NOT_READY must be fail or skip` ·
`::error::GENBA_PRIVATE_PATHS: not a path below the site root: <entry>` ·
`::error::GENBA_HEADERS: path '<path>' must be / followed by letters, digits, . _ ~ / -` ·
`::error::GENBA_HEADERS: a line needs 'Name: rule' (got: <line>)` ·
`::error::GENBA_HEADERS: '<name>' is not a header name` ·
`::error::GENBA_HEADERS: the rule for <name> is empty` ·
`::error::GENBA_HEADERS: the rule for <name> is '~' without text` (or `'!~'`) ·
`::error::GENBA_MAX_TIME must be a whole number of seconds above 0: <value>` ·
`::error::GENBA_USER/GENBA_PASSWORD only over https: GENBA_URL is not https:// (plain http only to 127.0.0.1, localhost or [::1])` ·
`::error::GENBA_USER/GENBA_PASSWORD must not contain a line break (a secret set from a file keeps the file's line breaks)` ·
`::error::curl cannot read its credentials file (curl exit 26) — …` ·
`::error::curl not found`.

**Messages** (exact; `<…>` are values):

```
Genba smoke against <url>, expected build <hash>
::error::Homepage: HTTP 401 instead of 200 (protected: set GENBA_USER and GENBA_PASSWORD)
::error::Homepage: HTTP <code> instead of 200[ — <first line of curl stderr>]                 (the curl text on every finding below that names the code of a fetch curl could not finish)
::error::Homepage without <meta name="build"> — stamp file not deployed or snippet('genba/stamp') missing in <head>
::error::Live build is <live>, expected <expected> — the upload did not (fully) arrive
  ok   Homepage serves build <hash>
::error::Homepage does not contain the marker '<marker>' — is this really our site?
  ok   Marker '<marker>' found
  --   marker not checked (GENBA_MARKER empty)
  ok   Unknown address returns 404
::error::Unknown address returns HTTP <code> instead of 404[ — <first line of curl stderr>]
::error::<GET|HEAD> /<file>: HTTP <code> instead of 200[ — <first line of curl stderr>]
::error::<GET|HEAD> /<file>: Content-Type '<type>', expected <a or b>
  ok   <GET|HEAD> /<file>: 200, <type>
::error::/<file> does not contain what it should (pattern: <pattern>)
::error::Sitemap points to a foreign host: <loc> (site URL misconfigured?)        (or ::warning:: in warn mode)
::error::Sitemap points to a foreign host: <loc> (neither <origin> nor GENBA_SITEMAP_ORIGIN <origin>)
  --   GENBA_FOREIGN_SITEMAP not set: rewrite (a preview run: GENBA_EXPECT_PROTECTED=1 or GENBA_USER set)
  --   GENBA_FOREIGN_SITEMAP not set: fail (not a preview run: neither GENBA_EXPECT_PROTECTED=1 nor GENBA_USER)
::error::Sitemap page <loc>: HTTP <code> instead of 200[ — <first line of curl stderr>]
::error::Sitemap page <url>: HTTP <code> instead of 200 (listed as <loc>)[ — <first line of curl stderr>]   (rewritten)
::notice::Sitemap lists <n> pages on <origins> instead of <origin> — rewritten to <origin> (path and query kept) and fetched there (GENBA_FOREIGN_SITEMAP=rewrite)
::error::Sitemap lists no pages
::error::GET /sitemap.xml: larger than 10485760 bytes — not read
::error::Sitemap not available for page check
::error::Sitemap entry longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Sitemap entry with a character reference that is not decoded: <loc> — not fetched
::error::Sitemap: <n> entries could not be parsed (<u> <url> entries, <l> <loc> addresses)
::error::Sitemap entry is not an absolute URL: <loc> — the sitemap protocol requires scheme and host
::error::Stylesheet address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Asset address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
  ok   <n> sitemap pages fetched
  ok   <n> of <m> sitemap pages fetched (GENBA_PAGES_MAX=<n>), the rest not checked
::warning::No sitemap page fetched: the entries point to a foreign host — the pages of <url> are NOT checked
::error::No sitemap page fetched: every entry is a finding (see above) — the pages of <url> are NOT checked
::error::Sitemap entry is empty (<loc> without an address)
  --   <n> .<ext> files of <origin> on the homepage, the first 5 checked (the server configuration is tested, not every file)
::error::Stylesheet <url>: HTTP <code>, '<type>', <bytes> bytes — expected 200, text/css, not empty[ — <first line of curl stderr>]
  ok   Stylesheet: 200, text/css
  --   no stylesheet of <origin> found on the homepage, stylesheets not checked
  --   no .<ext> file found on the homepage, Content-Type not checked
::error::<asset>: HTTP <code> instead of 200[ — <first line of curl stderr>]
::error::<asset>: Content-Type '<type>', expected <expected>
  ok   <asset>: <type>
  --   Panel not checked (GENBA_PANEL_ACCOUNT=off)
::error::/api/system: HTTP <code> without isInstalled/isInstallable — is this Kirby's Panel API?[ — <first line of curl stderr>]
::error::Panel can be installed from outside (panel.install on?) — any visitor could become admin
::error::Panel without account: /panel shows the installer. Create the account on the server first   (or ::warning:: in warn mode)
  ok   Panel set up (account present, installer locked)
  ok   Panel: /panel answers 200, its <n> files arrive
::error::/panel: HTTP <code> instead of 200[ — <first line of curl stderr>]
::error::/panel: HTTP <code> to '<location>', not to a page under /panel
::error::/panel names no JS or CSS under /media/panel — an empty Panel page?
::error::Panel file <path>: HTTP <code> instead of 200[ — <first line of curl stderr>]
::error::Panel file <path>: HTTP 200, '<type>', <bytes> bytes — expected <types, joined by " or ">, not empty   (without ", not empty" for /media/plugins/index.css and index.js)
  --   response headers not checked (GENBA_HEADERS not set)
  ok   <label>: <expected> (HTTP <code>)         (<expected>: exactly '<value>' | present | absent | containing '<text>' | not containing '<text>')
::error::<label>: sent '<value>', expected <expected> (HTTP <code>)
::error::<label>: not sent, expected <expected> (HTTP <code>)
::error::<label>: HTTP <code> instead of 2xx or 3xx — the header was not checked (path wrong?)
::error::<label>: no answer (HTTP 000)[ — <first line of curl stderr>]
  --   http→https not checked (GENBA_HTTPS_REDIRECT=off)
  --   http→https not checked: URL is not https
  ok   Protected: <url>/ answers 401 without credentials
::error::Without credentials <url>/ returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
::error::Without credentials <url>/ gives no answer (HTTP 000)[ — <first line of curl stderr>] — the preview protection is not verified
::error::<host> does not resolve (DNS entry missing?) — a preview whose DNS entry is still pending sets GENBA_NOT_READY=skip
::warning::Genba smoke: SKIPPED — <host> does not resolve yet (DNS entry pending). Nothing was checked; run again once the name resolves.
Genba smoke: SKIPPED — <host> does not resolve yet
::warning::Genba smoke: SKIPPED — https to <host> fails, but <http url> answers HTTP <code>: TLS certificate not issued yet? Nothing was checked.
Genba smoke: SKIPPED — https to <host> fails (certificate pending?)
::error::https to <host> fails, and without credentials <http url> returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
::warning::Genba smoke: SKIPPED — <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set. Whether the new build arrived is NOT verified.
Genba smoke: SKIPPED — build not verified, see the warning above.
  --   <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set — not SKIPPED: the run has findings already
  ok   <n> private paths not reachable (none answers 2xx)
::error::Private path /<path> answers HTTP <code> — reachable from the web (document root not on public/?)
::error::Private path /<path>: no answer (HTTP 000)[ — <first line of curl stderr>] — not verified
  ok   http redirects with <code> to <target>
::error::http→https missing: <http url> returns '<code>' with target '<target>', expected 301/308 to https://<host>[ — <first line of curl stderr>]   (or ::warning:: in warn mode; the curl text with '000')
Genba smoke: FAILED — see findings above.
Genba smoke: FAILED — cannot run                                                   (exit 2, after the ::error::)
Genba smoke: PASSED.
```

### 3.5 `Genba\Links` (src/Links.php — owner `links`, Kirby-free)

Port of `Fremdlinks` (`../kirby-starter/scripts/pruefe-fremdlinks.php`) with
English names, same behaviour, same exit codes. It checks WHERE a reference
leads, not whether something answers 200 (five of nine product pages of
a live site once carried a wrong Digistore ID; four answered 200).

Names: class `Fremdlinks` → `Links`; constants `OK`, `BEFUND` → `FINDING`,
`STOERUNG` → `DISRUPTION`, `HINWEIS` → `NOTICE`; `KAUF_HOSTS` → `PURCHASE_HOSTS`,
`ENDUNGEN` → `EXTENSIONS`, `UEBERGEHEN` → `SKIP_PATHS` (project-relative places: `vendor`, `kirby`,
`storage`, `site/cache|sessions|accounts|logs`, `public/media`) + `SKIP_ANYWHERE` (`node_modules`,
`.git`) — by place through `Genba\Tree::walk()`: a page `content/media/` is read, symbolic
links inside the project are followed (never into a skipped place, by real path), Genba's
own package (realpath) is never read, and every directory not read is printed as
`  --   not checked: <dir> (<reason>)`; an unreadable directory or file is
`::error::Cannot read directory: <dir>` / `::error::Cannot read file: <file>` on stderr, exit 2,
never a stack trace and never an empty text; a symbolic link to a file is treated like one to a
directory (`symbolic link to a file outside the project`, `symbolic link into <place>`,
named and never read), and a file reached through two paths is read once per run; methods `finde` → `find`,
`dateien` → `files`, `ausText` → `fromText`, `einordnen` → `classify`,
`ausFeldern` → `fromFields`, `felder` → `fields`, `seitentitel` → `pageTitles`,
`nameBestaetigt` → `nameMatches`, `woerter` → `words`, `pruefe` → `check`,
`youtube`, `digistore`, `kauflink` → `purchaseLink`, `mitWiederholung` → `withRetry`,
`abruf` → `fetch`, `beschreibe` → `describe`, `gegenprobe` → `counterCheck`.
Reference array keys: `art` → `kind`, `schluessel` → `key`, `url`, `erwartet` →
`expected`, `ort` → `place`, `produkt` → `product`. Kinds: `youtube`,
`youtube-einbettung` → `youtube-embed`, `digistore`, `kauflink` → `purchase`.
Result: `['status' => …, 'text' => …]`. Fetch result keys: `code`, `body`,
`final`, `kette` → `chain`, `fehler` → `error`, plus `refused` (null, or why a
redirect hop was not followed). Constructor parameters:
`digistoreFields`, `youtubeFields`, `shopHosts`, `oembedBase`, `digistoreBase`,
`retries`, `pause`, `allowPrivate` (default false; only tests set it),
`resolver` (default null = system DNS; only tests set it).

**Redirects (SSRF guard).** curl never follows a redirect (`CURLOPT_FOLLOWLOCATION`
false) and never uses a proxy (`CURLOPT_PROXY` `''`, so `http_proxy`/`HTTPS_PROXY`/
`ALL_PROXY` cannot redirect a request). `fetch()` follows `Location` by hand, at
most 10 hops, resolving relative targets. Every URL, the first included, goes
through `Links::requestUrl()`: curl gets `scheme://host[:port]path[?query]`
rebuilt from the parsed parts (lower-case host, no trailing dot, no fragment),
and a URL that parse_url and curl could read differently is refused —
whitespace/control characters, backslash, `@` in the authority, an authority
other than `host[:port]`, a non-ASCII or non-DNS host name, a numeric host other
than a canonical dotted quad (`2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`).
Raw non-ASCII bytes in the PATH are percent-encoded (`/produkt/kräuter` →
`/produkt/kr%C3%A4uter`); in the host they stay refused.
Each new scheme/host/port is resolved ONCE (A and AAAA). The first URL is
trusted when it comes from the options (oEmbed, Digistore base) or is a
purchase link whose host is EXACTLY a configured shop host (decision 18,
closed 11.10.: any other purchase link from the content is checked like a
hop, `address <host> refused (…) — not requested`); for every hop to
another host:port the name `localhost`/`*.local`/`*.lan`/`*.internal`, or ANY
resolved address that is private, refuses the hop (`Links::isPrivateAddress()`:
loopback, RFC 1918, link-local 169.254/16 and fe80::/10, CGNAT 100.64/10,
192.0.0/24, benchmark 198.18/15, 0.0.0.0/8, 240/4 incl. broadcast, `::`, ULA
fc00::/7, site-local fec0::/10, SIIT ::ffff:0:0:0/96, multicast, Teredo 2001::/32, NAT64 local 64:ff9b:1::/48, plus
`filter_var` NO_PRIV/NO_RES; IPv4-mapped/-compatible, 6to4 2002::/16 and NAT64
64:ff9b::/96 judged by the embedded IPv4). Exactly the checked address (IPv4
first) is pinned with `CURLOPT_RESOLVE`, and `CURLINFO_PRIMARY_IP` must equal
it, else the result is discarded as refused. A refused hop is a FINDING:
`Purchase link: redirect to <host> refused (…) — not followed (<chain>)`,
`Digistore product <id>: redirect to <host> refused (…)`; it is not retried.
The refusal texts (listed in full): `requestUrl()` → `URL refused (<reason>)` with the
reason `whitespace, control character, backslash or non-ASCII in the address` · `not an absolute
http(s) address` · `scheme <scheme>` · `user information in the address` · `address cannot be
parsed unambiguously` · `invalid IPv6 address` · `numeric host in a non-canonical form` · `host
name is not a plain DNS name`; the address guard → `<address|redirect to> <host> refused (local
host name) — <not requested|not followed>` / `… refused (private or local address <address>) — …`;
the pinning → `connected to <ip> instead of the checked address <ip> for <host> — result discarded`.
A constructor `resolver` closure (host → addresses) replaces the system
resolver in tests only.

**External text** (Digistore `<title>` after entity decoding, oEmbed `title`,
`Location` values) goes through `Links::clean()` before it reaches a message:
C0, DEL and C1 control characters → space, invalid UTF-8 scrubbed
(`mb_scrub`; mbstring is required, `ext-mbstring` in composer.json — there is no fallback
without it, which would cut inside a UTF-8 character and count bytes). A title cannot forge a `::error file=…` annotation line.
The site's own files are no safer — the URL, the places and the directory
names in the list line, the result line, the `--   not checked` lines and
`Cannot read file` go through `clean()` as whole lines too. `youtube()` reads
`refused` like `digistore()` (`Video <id>: <reason>`, a finding).
`clean()` is `Genba\Text::clean()` (src/Text.php), shared with Precheck and
Tree; `Links::clean()` delegates. `Cannot read directory: <dir>` from
`Tree::walk()` is relative to the working directory and cleaned, like
`Cannot read file`. `clean()` cuts a text above `$max` (default `Text::MAX` 2048)
characters to its first `Text::KEEP` 60 with `… (<n> characters)`, counted in characters (mb),
the cut of genba-smoke's `log_text` — uncut, a content URL of 100 000 characters would be a log
line of 100 073, a Digistore title from a body of up to 2 MB the same. The list line and the result line
are assembled from pieces cleaned one by one (URL, result text, each place) and the assembled line
is cleaned with `PHP_INT_MAX`: a long list of places is not cut. Nor is the result
text cut as a whole — its pieces (the first address, each chain link, each lost parameter
`k=v`, titles, the Digistore ID, curl's error in `describe()`) are cut where the text is built, and
`run()` cleans the result text with `PHP_INT_MAX`: a chain of 1 100 characters with a lost parameter
of 1 100 keeps its chain. The `--   not checked` line is built of the cleaned directory and reason. The Digistore title is cleaned
uncut for the name comparison and cut where it is printed; the first address of a purchase chain
is cut like every hop. So are the host and the scheme a refusal names — they come
from a `Location` header: `requestUrl()` (`scheme <scheme>`), `guard()` (`<address|redirect to> <host>
refused (…)`, `Could not resolve host: <host>`) and the pin check in `fetch()` (`… for <host> — result
discarded`) cut them where the text is made; uncut, a redirect to a `.localhost` host of 3 131 characters
would be a line of 3 394.

**Empty settings.** An empty or blank `GENBA_DIGISTORE_FIELDS`,
`GENBA_YOUTUBE_FIELDS` or `GENBA_SHOP_HOSTS` counts as unset (GitHub Actions
yields `""` for an unset `${{ vars.X }}`); an option with an empty value
(`--digistore-field=`) is a usage error, exit 2. User agent:
`Mozilla/5.0 (compatible; Genba link check; +https://github.com/sayamaapps/kirby-genba)`.
The `words()` filler list keeps `sayama` and `digistore24` (they are brand
words that never distinguish products).

**Messages** (exact; straight double quotes around titles, not „“):

```
youtube:    "<title>"
            Video <id> does not exist (oEmbed <code>)
            Video <id> is private (oEmbed 403)
            Video <id> cannot be embedded (oEmbed 401)
            Video <id>: URL refused (<reason>)                       (a finding; also "connected to … instead of the checked address")
            oEmbed answers 200 without a title
            YouTube unreachable: <describe>
digistore:  "<name>"
            Digistore product <id> does not exist (HTTP <code>)
            Digistore product <id>: <describe>
            Digistore product <id> shows no active product (page title "<title>")
            Digistore product <id> is called "<name>", but the page is "<t1>" / "<t2>" — wrong ID?
purchase:   <n> redirect(s), target <final>
            Parameters lost on the way: aff=sayama, cam (<chain joined by ' → '>)
            Purchase link ends with HTTP <code> (<chain>)
            Purchase link: <describe> (<chain>)
describe:   no response (<curl error>)   |   HTTP <code>
counterCheck failures:
            Existing video <id> not recognised: <text>
            Missing video <id> not recognised as a finding: <status>, <text>
            Missing Digistore product <id> not recognised as a finding: <status>, <text>
```

**CLI `genba-links`** — arguments: `--digistore-field=a,b` / `GENBA_DIGISTORE_FIELDS`
(default `digistoreid`), `--youtube-field=a,b` / `GENBA_YOUTUBE_FIELDS` (default
`videoid,youtubeid,youtubevideos`), `--shop-host=h1,h2` / `GENBA_SHOP_HOSTS`,
`--skip-counter-check`, `--list-only`, `--oembed-base=URL`, `--digistore-base=URL`,
`--pause=SECONDS` (default 0.3), `--help`, then paths (default: the existing
ones of `content`, `site`, `public` under `$cwd`; a missing default path prints
`  --   not checked: <path> (default path, does not exist)`; a path given as an argument that
does not exist prints `::warning::Path not found, skipped: <path>` and the others are read — a
warning, not exit 2, deliberately (unlike genba-precheck's missing
`--site=`, exit 2: precheck has exactly two roots and needs both, links reads any number of paths
and fails only when none exists). `--pause` that is no number ≥ 0 →
`::error::--pause must be a number of seconds, not: <value>`, exit 2). `--skip-counter-check`
prints `  --   counter-check skipped (--skip-counter-check)`. A YouTube ID field
holding URLs and bare IDs yields the bare IDs (the URLs come from `fromText()`). Output:

```
<n> external references found, <m> distinct targets.
  <kind padded 18>  <url>  (<places>)                                     (--list-only)
Genba links: <n> references listed                                        (--list-only, the last line; n = targets listed)
::error::Counter-check: <failure>
The link check cannot currently tell what exists from what does not. The result would be worthless.
Counter-check passed: a deleted video and a missing product are recognised.
  ok   <url>: <text> — in <up to 5 places[ …]>
::error::<url>: <text> — in <places>
::warning::Disruption, not assessed: <url>: <text> — in <places>
::notice::<url>: <text> — in <places>
Genba links: <ok> ok, <findings> findings, <disruptions> disruptions, <notices> notices.
Genba links: FAILED — counter-check failed, no reference was checked              (exit 1)
Genba links: FAILED — cannot run                                                  (exit 2, stdout; the reason on stderr)
```

Exit 1 when findings > 0 or the counter-check failed (then the last line
is `Genba links: FAILED — counter-check failed, no reference was checked`); 0 otherwise (disruptions
are warnings: a flaky network must not produce a red run nobody looks at any
more). Cannot run (exit 2): none of the paths exists →
`::error::None of the paths exist: <list>`; `ext-curl` missing →
`::error::PHP extension curl is missing`; every exit-2 reason goes to stderr and
stdout ends with `Genba links: FAILED — cannot run` (`Links::cannotRun()`,
§3.0 "last line"). No references at all →
`::notice::No external references found under <paths>` then the summary with
zeros, exit 0 (plausible for a site without shop or videos — but said out loud;
`--list-only` prints the notice too, then `Genba links: 0 references listed`).
The counter-check runs only when there is at least one target (as before).
Unknown argument: `::error::Unknown argument: <argument>` (the wording of
the other four tools). An empty path argument (`genba-links ""`, an unset `${{ vars.X }}`) is
`::error::Path argument is empty (omit it for the default paths)` + usage, exit 2 (read as a path
it would become `realpath("$base/")`, the whole working directory, without the default paths and
their `--` lines — the rule of the other four tools for an empty argument).

### 3.6 Snippets (owner `stamp`)

`snippets/genba/stamp.php` — in `<head>`:

```php
<?php
$genbaKirby = kirby();
if (!\Genba\Options::active($genbaKirby)) { return; }
echo \Genba\Stamp::meta(site()->genbaBuild(), \Genba\Options::metaName($genbaKirby));
```

Output exactly `<meta name="build" content="<hash>">` + newline, or nothing.
The content is only ever a hash that matched `Stamp::PATTERN`, so no escaping
is needed — still pass it through `esc()`? No: `Stamp::meta()` is Kirby-free
and the pattern already excludes every unsafe character; do not add `esc()`.

`snippets/genba/build-info.php` — a visible line, e.g. in a preview footer.
Variables (optional): `$label` (bool, default true: prefix "Build"/"Stand"),
`$withDate` (bool, default false). Output, unstyled, only `genba-*` classes:

```html
<p class="genba-build" data-build="<hash>"><span class="genba-build-label">Build</span> <code class="genba-build-hash">0123456</code><?php if date ?> <span class="genba-build-date">deployed 2026-10-11</span><?php endif ?></p>
<p class="genba-build genba-build--none">no build stamp</p>      <!-- active, but genbaBuildInfo() is null -->
```

Nothing when `genba.active` is off. Texts via `I18n::t()` after
`Options::applyLanguage(kirby())`: `build.label`, `build.writtenAt` with
`{date}` = `writtenAt` cut to `Y-m-d` (never printed when `writtenAt` is
null), `build.none`. Every value through `esc()` except the hash in
`data-build` (pattern-safe, but `esc()` it anyway — it costs nothing).

### 3.7 Site methods and helper (index.php — written)

`genba_option(string $key, mixed $default = null): mixed` ·
`$site->genbaBuild(): ?string` · `$site->genbaBuildInfo(): ?array` (shape in
§3.1 `info()`). Both null when `genba.active` is not `true`. Translations are
registered as `genba.<key>`.

---

## 4. Options (Kirby part only; the tools use environment variables, §6)

| Option | Default | Meaning |
|---|---|---|
| `genba.active` | `false` | Master switch. Only `true` switches the snippets and site methods on. The tools do not read it. |
| `genba.stamp.file` | `'site/config/build.txt'` | Stamp file, absolute or relative to Kirby's `base` root (else the directory above `site/`). Empty/non-string → reported, default used. |
| `genba.stamp.meta` | `'build'` | `name` attribute of the `<meta>`. `[A-Za-z0-9_-]+` only; otherwise reported, default used. The fleet check outside this package and `genba-smoke` read `build` — change both ends if you change this. |
| `genba.language` | site language → `en` | `en` or `de` for `genba/build-info`. Else the current language of a multi-language site, its default language, Kirby's `locale`, English. |

A broken value is logged once per process (`Log::error`), or thrown with
Kirby's `debug` on (`Options::invalid()`).

---

## 5. Translation keys (translations/en.php, de.php — complete list)

| Key | en | de |
|---|---|---|
| `build.label` | Build | Stand |
| `build.hash` | Build {short} | Stand {short} |
| `build.writtenAt` | deployed {date} | ausgeliefert {date} |
| `build.none` | no build stamp | kein Stand hinterlegt |
| `panel.build.label` | Build stamp | Stand (Commit) |
| `panel.build.info` | The deploy writes the commit hash to {file}; the smoke test compares it with the live page. | Der Deploy schreibt den Commit-Hash nach {file}; der Smoke-Test vergleicht ihn mit der ausgelieferten Seite. |

Registered in Kirby as `genba.build.label` etc. Need another key? Write it
into `NOTES-<task>.md`; do not edit the tables.

---

## 6. Configuration contract (files, environment variables)

**Stamp file** `site/config/build.txt`: one line, the 40-digit lower-case hex
hash of the deployed commit, written by `genba-stamp` in CI, gitignored by the
site (checked in it would always hold an old hash), deployed with the code
set. Locally it is absent → no `<meta>` → the smoke test against localhost
would complain, which is right.

**Environment variables** (all `GENBA_`; a site workflow sets them in the
step's `env:` block, one `run:` line per step):

| Variable | Tool | Default | Values |
|---|---|---|---|
| `GENBA_STAMP_FILE` | stamp | `site/config/build.txt` | path |
| `GENBA_SHA` | stamp, smoke | `git rev-parse HEAD` | 40-hex (smoke: fallback when `GENBA_EXPECTED_BUILD` is unset) |
| `GENBA_PREFLIGHT_HOOK` | preflight | package.json `preflight`/`praeflug` | shell command, `none` |
| `GENBA_ALLOW` | precheck | `places.googleapis.com/v1/` | comma list added to default, `none` |
| `GENBA_REQUIRE_FILES` | precheck | `public/assets/css/tailwind.css` | comma list, `none` |
| `GENBA_URL` | smoke | — (required) | `https://…` |
| `GENBA_EXPECTED_BUILD` | smoke | `GENBA_SHA`, else `git rev-parse HEAD` | 7–64 hex, a prefix of the live hash; whitespace dropped |
| `GENBA_META` | smoke | `build` | meta name, `[A-Za-z0-9_-]+` |
| `GENBA_MARKER` | smoke | `assets/css/tailwind.css` | text, empty = off |
| `GENBA_USER`, `GENBA_PASSWORD` | smoke | — | Basic Auth, both or neither, https only (loopback excepted), no line break in either (exit 2) |
| `GENBA_WITHOUT_CREDENTIALS` | smoke | `fail` | `fail`, `skip` (SKIPPED only while the run has no finding) |
| `GENBA_EXPECT_PROTECTED` | smoke | `0` | `0`, `1` |
| `GENBA_NOT_READY` | smoke | `fail` | `fail`, `skip` |
| `GENBA_HTTPS_REDIRECT` | smoke | `require` | `require`, `warn`, `off` |
| `GENBA_PANEL_ACCOUNT` | smoke | `require` | `require`, `warn`, `off` |
| `GENBA_PANEL_PATH` | smoke | `/panel` | a path below the site root: `/` and segments of `A-Za-z0-9_~-` separated by single `/`; anything else exit 2 |
| `GENBA_API_PATH` | smoke | `/api` | the same rule; the API is read at `<path>/system` |
| `GENBA_FOREIGN_SITEMAP` | smoke | `rewrite` on a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER`), else `fail` | `rewrite`, `warn`, `fail` |
| `GENBA_SITEMAP_ORIGIN` | smoke | — | `https://host[:port]`: the one foreign origin allowed |
| `GENBA_PRIVATE_PATHS` | smoke | see §3.4 check 8 | comma/space list added to the default, `none` |
| `GENBA_HEADERS` | smoke | — (not checked) | one rule per line, `[/path] Name: rule`; rule = value, `*`, `none`, `~text`, `!~text`; see §3.4 check 9 |
| `GENBA_PAGES_MAX`, `GENBA_RETRIES`, `GENBA_PAUSE` | smoke | `30`, `3`, `5` | whole numbers: `PAGES_MAX` and `RETRIES` above 0, `PAUSE` seconds (0 allowed); else exit 2. A sitemap longer than `PAGES_MAX` is said in the ok line |
| `GENBA_HTTP_URL` | smoke | `http://<host>/` | tests only |
| `GENBA_MAX_TIME` | smoke | `25` | seconds per request and for the homepage reader, tests only |
| `GENBA_DIGISTORE_FIELDS`, `GENBA_YOUTUBE_FIELDS`, `GENBA_SHOP_HOSTS` | links | `digistoreid` · `videoid,youtubeid,youtubevideos` · — | comma lists |

**Site workflow after the switch** (docs/reference.md lists the order and the settings; this is the
shape the site keeps):

```yaml
- run: vendor/bin/genba-preflight
- run: npm run build
- run: vendor/bin/genba-precheck
- run: vendor/bin/genba-stamp
- run: for f in tests/*Test.php; do php "$f" || exit 1; done
- run: .github/scripts/deploy-ftps.sh
- run: vendor/bin/genba-smoke
  env: { GENBA_URL: "${{ vars.LIVE_URL }}" }
# preview:  GENBA_USER/GENBA_PASSWORD from secrets, GENBA_EXPECT_PROTECTED: 1, GENBA_NOT_READY: skip,
#           GENBA_HTTPS_REDIRECT: warn, GENBA_WITHOUT_CREDENTIALS: skip,
#           GENBA_SITEMAP_ORIGIN: ${{ vars.LIVE_URL }}
# live:     GENBA_FOREIGN_SITEMAP: fail   (its default; explicit)
# schedule: vendor/bin/genba-links   (daily 05:17 UTC, own workflow)
```

---

## 7. Delivery shape (decision) and rejected alternatives

**Chosen: one Composer package = Kirby plugin part + `bin/` tools.**
`composer require sayamaapps/kirby-genba` gives a site the snippet AND five
`vendor/bin/genba-*` commands; the deploy ships `site/plugins/genba/` like
every other plugin (the smoke test's bash file travels along but is never
served — `public/` is the document root). CI on the site's runner
needs nothing new: the site already runs `composer install`. One line per
workflow step holds (`run: vendor/bin/genba-smoke`), site differences are
`env:` values, and the counter-checks that today run in every site's
`tests.yml` (smoke-test-gegenprobe.sh, FremdlinksTest.php) run ONCE here —
each site drops ~750 lines of copied scripts and tests.

Rejected:

1. **A reusable composite GitHub Action** in this repository
   (`uses: sayamaapps/kirby-genba/.github/actions/deploy-checks@v1`). It would
   duplicate what Composer already delivers, add a second version to pin
   (action tag vs. package version), require the repository to be public or
   the Actions "access from other repositories" setting, and carry fork-PR
   surface. Not worth it while every site already installs the package. Can be
   added later without changing the tools.
2. **Kirby CLI commands** (`kirby genba:smoke`). Needs `getkirby/cli` and a
   bootable Kirby with the site's config — in CI the config may require
   credentials files that only exist after the deploy, and the smoke test
   must run against a REMOTE host, not the checkout.
3. **Python** (like the fleet check and a site's own health check). A second runtime on
   every site; the sites are PHP and the runner has PHP. The fleet check stays the
   weekly outside view across all sites; Genba is the per-deploy inside view.
4. **Keeping the pre-deploy checks in bash.** `grep -rEn` differs between BSD
   and GNU, binary files and `site/cache` get scanned, and the self-test wrote
   to `/tmp`. A PHP class with a per-rule self-test is testable and portable.
5. **Porting the smoke test to PHP.** Possible, but the bash+curl original is
   proven, 1:1 portable, and curl's TLS/redirect/HEAD handling would have to
   be re-implemented. Bash stays — with bash 3.2 compatibility and shellcheck.
   Precisely: **curl fetches, PHP reads.** Every request, the
   checks, the messages and the exit codes stay in bash; reading the saved
   homepage is done by `bin/genba-smoke-read` (`src/Homepage.php`), an HTML5
   parser, because a reader of HTML in awk and grep kept getting spellings
   wrong that the browser reads differently (decision 24). The sitemap is XML
   and is read in bash.
6. **A config file for the tools** (`site/config/genba.php`). The tools must
   run before Kirby exists and without the site's config; environment
   variables and arguments are what the workflows already use. Kept simple.

---

## 8. Compatibility (old copies → Genba)

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

Behaviour changes, all deliberate (CHANGELOG lists them):
empty sitemap is a finding · `GENBA_FOREIGN_SITEMAP=rewrite` (default on a
preview run, else `fail`) fetches the pages of a staging host whose site URL is
production on the staging origin ·
reachability gate (`GENBA_EXPECT_PROTECTED`, `GENBA_NOT_READY`) and private paths
(`GENBA_PRIVATE_PATHS`) are in the smoke test · the precheck ships the Koe allow entry by
default and uses the anchored `//fonts\.` pattern · the precheck skips
`site/cache|sessions|accounts|logs`, `public/media` (by place), `node_modules`, `.git`
(anywhere), each named with a `--` line ·
no references found is said out loud · all messages English.

---

## 9. Test plan

Every test file starts with a docblock (what it covers, how to run), uses
`tests/helpers.php`, ends with `finish('<Suite>')`.

### 9.1 tests/StampTest.php (task `stamp`, Kirby-free)

`require_once __DIR__ . '/../src/Stamp.php'`. Cases:

- `read()` — port of StandTest's five: `"<40hex>\n"` → the hash; file missing
  → null; `''` → null; `'main'` → null; `'"><script>alert(1)</script>'` → null.
  Plus `'ABCDEF1'` (upper case) → null, 7-char hash → accepted, 65 hex → null.
- `meta()` — `meta($hash)` === `'<meta name="build" content="' . $hash . '">' . "\n"`;
  `meta(null)` === `''`; `meta('main')` === `''`; `meta($hash, 'stand')` uses `name="stand"`.
- `info()` — hash, `short` (7 chars), `file`, `writtenAt` matches
  `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/`; missing file → null.
- `write()` — writes `hash . "\n"`, `read()` gets it back; `write($f, 'main')`
  throws InvalidArgumentException; 7-char hash throws too (FULL_PATTERN);
  directory missing → RuntimeException.
- `fromGit()` — `git init` + one commit in a temp dir (via `genba_run()` with
  `GIT_AUTHOR_*`/`GIT_COMMITTER_*` env so it works without global config) →
  40-hex === `git rev-parse HEAD` output; a non-git dir → RuntimeException
  whose message contains `not a git repository` (or git's actual wording —
  assert it is non-empty and mentions git).
- CLI through `Stamp::run()` with memory streams in that temp repo (with
  `site/config/` created): exit 0, file content, stdout
  `Genba stamp: wrote <hash> to site/config/build.txt`; `--sha=main` → exit 1
  `::error::Not a full commit hash: main`; `--show` → hash on stdout; `--show`
  with no file → exit 1 `::error::No valid build stamp in …`; `--file=other/build.txt`
  where `other/` is missing → exit 1 `::error::Cannot write`; `--help` exit 0
  containing `genba-stamp`; `--bogus` exit 2.
- One `proc_open` run of `bin/genba-stamp --help` via `genba_run()` → exit 0.
- `--bogus` → stderr `::error::Unknown argument: --bogus` + usage, stdout exactly
  `Genba stamp: FAILED — cannot run`; `--sha=` and `--file=` (empty, with `GENBA_SHA`/`GENBA_STAMP_FILE`
  set) → exit 2, `::error::--sha needs a value` / `--file needs a value`, nothing written.

### 9.2 tests/PreflightTest.php (task `stamp`, Kirby-free)

- `hook()`: env `GENBA_PREFLIGHT_HOOK=echo hi` → `['command' => 'echo hi', 'source' => 'GENBA_PREFLIGHT_HOOK']`;
  `none` → null; package.json `{"scripts":{"praeflug":"x"}}` → `npm run praeflug`
  source `package.json`; with both `preflight` and `praeflug` → `npm run preflight`;
  no package.json → null; invalid JSON → null.
- `run()`: hook `printf hook-ran` → output contains `hook-ran` and
  `Genba preflight: PASSED`, exit 0; hook `exit 3` → exit 1 with
  `::error::Preflight hook failed with exit 3`; no hook → exit 0 with
  `  --   no preflight hook configured` and `Genba preflight: SKIPPED`;
  `--hook=printf x` overrides env; `--help` 0; unknown argument 2.
- `bin/genba-preflight --help` via `genba_run()` → exit 0.
- `--bogus` and `--hook=` → stderr `::error::…` + usage, stdout exactly
  `Genba preflight: FAILED — cannot run`.
- `--hook=none` → `  --   no preflight hook configured (--hook=none)`, not
  `GENBA_PREFLIGHT_HOOK=none`, `Genba preflight: SKIPPED`, exit 0.
- `bin/genba-preflight --hook=true` with `PATH=/nonexistent` → exit 2,
  `::error::Cannot start the preflight hook (sh is not available)`, last line `FAILED — cannot run`
  (red without `findSh()` on PHP 8.2 only: from 8.3 on `proc_open()` fails by itself).
- `findSh()` through Reflection, on every PHP version: `PATH` with only a
  non-executable `sh` → null; `PATH=` (empty) and no `PATH` → `/bin/sh`; `/nonexistent:<no-exec>:/bin`
  → `/bin/sh`.

### 9.3 tests/KirbyTest.php (task `stamp`, needs vendor/)

With `genba_site()`/`genba_kirby()` in `try … finally genba_remove_tree()`:

1. Plugin registered (`$kirby->plugin('sayamaapps/genba')` not null); off by
   default: `genbaBuild()` null, `genbaBuildInfo()` null, `snippet('genba/stamp', [], true)` === `''`,
   `snippet('genba/build-info', [], true)` === `''`; `genba_option('active', 'x')` === `'x'`.
2. `'genba.active' => true`, `site/config/build.txt` = 40-hex + `"\n"` →
   `genbaBuild()` === hash; `genbaBuildInfo()['short']` === first 7;
   `snippet('genba/stamp')` === `'<meta name="build" content="<hash>">' . "\n"`.
3. Active, file `main` → `genbaBuild()` null, stamp snippet `''`;
   file missing → same.
4. Nested spelling `'genba' => ['active' => true, 'stamp' => ['file' => 'site/config/stand.txt']]`
   reads the other file; `'genba.stamp.meta' => 'stand'` → `name="stand"`;
   `'genba.stamp.meta' => 'bad name'` → logged, `name="build"`;
   with `'debug' => true` → InvalidArgumentException.
5. `build-info`: en → contains `class="genba-build"`, `data-build="<hash>"`,
   `>Build<`, `<code class="genba-build-hash">0123456</code>`; `'genba.language' => 'de'`
   → `>Stand<`; `['withDate' => true]` → `deployed 2026-` prefix present;
   `['label' => false]` → no `genba-build-label`; no file → `genba-build--none`
   with `no build stamp` / `kein Stand hinterlegt`.
6. Translations: `$kirby->translation('de')->get('genba.build.label')` === `'Stand'`;
   `I18n::t('build.none')` after `Options::applyLanguage()` on a `'locale' => 'de_DE'` site === `'kein Stand hinterlegt'`.
7. A site's own `site/snippets/genba/stamp.php` wins over the package snippet.

### 9.4 tests/PrecheckTest.php (task `precheck`, Kirby-free)

Build a fixture project in `genba_tmp()`:
`site/templates/a.php` with `<p style="x">`, `site/snippets/b.php` with
`<button onclick="x">`, `site/snippets/c.php` with `<script>alert(1)</script>`
AND a clean line `<script src="/assets/site.js"></script>` AND
`<script type="application/ld+json">{}</script>`, `site/snippets/d.php` with
`https://fonts.googleapis.com/css`, `public/assets/x.css` with `//fonts.gstatic.com`,
`site/plugins/koe/src/Places.php` with `https://places.googleapis.com/v1/places:searchText`,
`site/cache/junk.php` with `style="` (must be skipped), `public/media/x.php`
with `jimcdn` (skipped), a binary file `site/x.bin` (`"\0style=\""`, skipped).

- `selfTest()` === `[]`.
- `scan()` findings, sorted by file then line: exactly the four rules fire on
  a.php, b.php, c.php (ONE finding, line of `<script>alert`), d.php, x.css
  (public, foreign-host) — and NOT on Places.php (default allow), cache,
  media, x.bin. `(new Precheck([]))->scan()` DOES report Places.php.
  `new Precheck(['fonts.googleapis.com'])` suppresses d.php.
- Missing root → RuntimeException `Directory not found: site`.
- `requiredFiles(['public/assets/css/tailwind.css'], $cwd)`: missing → `bytes` null, ok false;
  empty file → bytes 0, ok false; 12 bytes → ok true, bytes 12.
- `run()` with memory streams: fixture with findings → exit 1, output contains
  `::error::site/templates/a.php:1: inline style — the CSP forbids it: <p style="x">`
  and `Genba precheck: FAILED — 5 findings` (count your fixture; state it);
  a clean project with the css file → exit 0 `Genba precheck: PASSED (`;
  clean project without css → exit 1 `::error::Required file missing or empty: public/assets/css/tailwind.css`;
  `--require=none` → `  --   no required files configured (--require=none)` exit 0, `GENBA_REQUIRE_FILES=none`
  → `(GENBA_REQUIRE_FILES=none)`; `--require=none,build/x.css` and `GENBA_REQUIRE_FILES=build/x.css, none`
  → `  ok   build/x.css (3 bytes)`, no file `none`, no default, no not-checked line;
  `--allow=none` → Places.php reported; empty dir (no site/) → exit 2
  `::error::Directory not found: site`; `--help` 0; `--bogus` 2.
- Self-test failure path: subclass is final — instead test
  `Precheck::RULES` each `sample` matches its `pattern` and that a deliberately
  broken rule table (pass a modified copy through a protected/static helper
  `selfTestRules(array $rules)`) reports the rule name. Expose
  `public static function selfTestRules(array $rules): array` for that.
- `bin/genba-precheck --help` via `genba_run()` → exit 0.

Symbolic links and permissions: `site/snippets/c -> ../cache` and `public/p -> ..` (site/cache and public/media
with findings): exit 0, `symbolic link into site/cache`, `inside site/cache, reached through a
symbolic link`, `inside public/media, …`; a directory with mode 0644 (read, no search):
`not checked: site/templates/rx/a.php (cannot be read (permission denied))` (skipped visibly
when running as root).

Capitals and file links: `site/templates/upper.php` with `<P STYLE="x"><SCRIPT>alert(1)</SCRIPT><a ONCLICK="y">`,
`<SCRIPT SRC="/x.js">`, `HTTPS://FONTS.GSTATIC.COM/x` and a JSON-LD block in capitals → exactly
4 findings (lines 1 and 3). `site/templates/leak.php -> <outside>/secret.txt` →
`symbolic link to a file outside the project`, its content never in the output;
`site/snippets/cp.php -> ../cache/page.php` → `symbolic link into site/cache`; with `public/p -> ..`
the run says `PASSED (3 files scanned)` — the site files are not counted again through the link.
`--require=,` → the `::error::` and `Genba precheck: FAILED — cannot run` on stdout, usage on stderr.

Spellings: `site/templates/spelled.php` with `<p style='color:red'>`, `<form onsubmit="x()">`,
`<p style = "x">`, `<a onmouseenter = 'y()'>` (lines 1–4, findings) and `<?php $style = 'card'; $on = 1; ?>`,
`<div data-style="x" icon="y" json="z">`, `<a href="?on=1" title="switched on=">` (lines 5–7, no
findings) → exactly 4 findings; `RULES['inline-style']` and `['inline-handler']` carry `negatives`.
The same file with `<p style=color:red>` (line 8) and a line that begins with
`style="x">` (line 9) → exactly 6 findings, lines 8 and 9 `inline style`.
`--bogus` and `--site=` → stderr `::error::Unknown argument: --bogus` / `--site needs a value` + usage,
stdout exactly `Genba precheck: FAILED — cannot run`.

Log text: `site/templates/forged<ESC>[31m.php` with `<p style="x">` + ESC[2K + CR + `Genba precheck:
PASSED (1 files scanned)`, `site/snippets/x<ESC>RED/s.php` with an onclick, `site/plugins/y<ESC>Z/node_modules/`,
`site/plugins/g<ESC>Q -> <package root>` → exit 1, no ESC or CR in the output, the finding printed as
`site/templates/forged [31m.php:1: … <p style="x"> [2K Genba precheck: PASSED (1 files scanned)`, the
cleaned `--   not checked` lines for both directories, last line `FAILED — 2 findings`; a directory
`site/snippets<ESC>[31mX` with mode 0000 → exit 2, `::error::Cannot read directory: site/snippets [31mX`,
no absolute path (skipped visibly as root). The installed-copy fixture copies `src/Text.php` too.

Tags over several lines: `site/templates/module.php` with `<script\n  type="module">` → `module.php:1: embedded <script>`;
`external.php` with `<script\n  src="/a.js"\n  defer\n></script>` → no finding; `open.php` with `<script` and 60
lines without `>` → `open.php:1: embedded <script>`; exactly 2 findings, exit 1. Attributes over several lines:
`button.php` with `<button style\n="color:red" onclick\n\n  ="go()">` → `button.php:1: inline style` and
`button.php:2: inline event handler`; `plain.php` with `<p style\n  class="a" onclick\n  hidden>` and
`<p class="a"\nstyle="x">` → only `plain.php:5: inline style`; exactly 3 findings, exit 1. Allow entries: `site/templates/f.php`
with `<link href="https://fonts.gstatic.com/s/x.woff2">` → exit 0 with `--allow=gstatic.com`, exit 1 without;
`new Precheck(['places.googleapis.com/v1/', 'gstatic.com'])` still reports the two-address line. Long pieces:
`findingLine()` (Reflection) with a path of 2 258 characters keeps `:7: inline style — the CSP forbids it: <p style="x">`
and cuts the path to `… (2258 characters)`; `skippedLine()` keeps ` (broken symbolic link)`.
`embedded-script` carries `<script nonce=<?= $kirby->nonce() ?> src="/assets/js/app.js"></script>`
as a negative and `<script nonce=<?= $kirby->nonce() ?>>x()</script>` as a positive — the PHP block without quotes,
which only the `<\?.*?\?>` alternative of `each` reads (the other nonce samples stand in quotes).

**The HTML variant corpus** (`tests/HtmlCorpusTest.php`, shared with §9.5): one template in
many spellings a browser reads the same, `tests/fixtures/precheck-variants/<name>.php`. Each is scanned
alone (`site/templates/<name>.php`); every variant must give exactly the same findings — one
`foreign-host`, one `inline-style`, one `inline-handler`, one `embedded-script`, nothing for the
external script and the JSON-LD block. A new spelling is a new file, the test picks it up. A finding
about how precheck reads HTML names the spelling that is missing here or that the code reads wrongly.

| Variant | Spelling |
|---|---|
| `base` | double quotes, one tag per line |
| `single-quotes` | every value in single quotes |
| `unquoted` | `style=color:red`, `onclick=go()`, `src=/assets/js/app.js`, `type=application/ld+json` |
| `minified` | the whole template on one line |
| `uppercase` | tags, attributes and the host in capitals |
| `spaced-equals` | `style = "…"`, `onclick = "…"`, `src = "…"` |
| `crlf-tabs` | CRLF line endings (written so by the test; `.gitattributes` keeps the file LF), tabs before the attributes |
| `prettier` | every attribute on a line of its own, `>` on a line of its own, `<script\n  type="module"\n>` |
| `src-in-value` | `src=` and `application/ld+json` inside other values: `data-src` beside the `src` of the external script, `title='see application/ld+json'` beside the `type` of the JSON-LD block, both inside values of the embedded script (`data-hint="loaded with src=…"`) — the counterpart of the smoke variant |
| `kirby-nonce` | `nonce="<?= $kirby->nonce() ?>"` on every script, `src="<?= url('…') ?>"`, PHP blocks with `->` and `>` in `class`, `data-id` and an `<?php if (… > 0): ?>` line; an external script with `nonce=<?= $kirby->nonce() ?>` without quotes (only the PHP-block alternative of `each` reads it) |
| `gt-in-value` | `title="a > b"`, `title="->"`, `data-config='{"a":"b>c"}'` and `data-x="->"` before `src` and `type` |
| `kirby-attr` | the external script and the JSON-LD block through Kirby's `attr()` / `Html::attr()`; the style and the handler written out beside an `attr()` call |
| `kirby-attr-style` | the style through `attr()` on one line, the handler through an `attr()` array literal over four lines, a script whose `attr()` src may be null (`?: null`, embedded), a `<div>` whose `attr()` prints neither style nor onclick (`null`, `false`) |
| `kirby-attr-forms` | the style through the single form `Html::attr('style', …, ' ')`, the handler through named arguments `attr(before: ' ', attr: [...])` over four lines, the external script through `Html::attr('src', url(…), ' ')`, the JSON-LD block through `Html::attr(name: 'type', value: 'application/ld+json')`, a script whose single-form src may be null (embedded), a `<div>` whose two single-form calls print nothing (`null`, no value) |

### 9.5 tests/SmokeTest.php + tests/fixtures/smoke-server.php (task `smoke`)

Port of `smoke-test-gegenprobe.sh`: a smoke test that never goes red is worse
than none. The router `smoke-server.php` plays the healthy site and injects
ONE fault per run, named in the file `GENBA_TEST_FAULT_FILE` (env, read on
every request; empty = healthy); the build hash comes from `GENBA_TEST_BUILD`
(env). Port the Python server 1:1: paths `/`, `/assets/css/tailwind.css`,
`/assets/fonts/{sans,serif}.woff2`, `/media/bild.webp`, `/media/bild{,-2x}.avif`,
`/robots.txt`, `/sitemap.xml`, `/llms.txt`, `/kontakt`, `/api/system`,
`/http-umleitung`, everything else 404 (`kein-404` fault → 200). Faults:
`old-build`, `no-build`, `no-marker`, `page-500`, `robots-head`, `webp-type`,
`woff2-type`, `foreign-sitemap`, `no-404`, `no-redirect`, `panel-empty`,
`panel-open`, `auth` (Basic `preview` / `ge"heim\x` via `$_SERVER['PHP_AUTH_USER']`/`PHP_AUTH_PW`;
401 with `WWW-Authenticate` for every path except `/http-umleitung`),
`empty-sitemap` (new: `<urlset></urlset>`). Base URL for the sitemap and
robots from `$_SERVER['HTTP_HOST']`. HEAD must behave like GET (PHP's server
drops the body itself).

Start with `genba_server(__DIR__ . '/fixtures/smoke-server.php', ['GENBA_TEST_BUILD' => $hash, 'GENBA_TEST_FAULT_FILE' => $faultFile], '/assets/css/tailwind.css')`
and FIRST prove the environment (T1): the CSS answers 200 (`curl -sf`), else
fail loudly. Then `run($name, $fault, $expectedExit, $pattern, $env)` =
write fault, `genba_run([ 'bash', $bin ], $tmp, ['GENBA_URL' => $base, 'GENBA_EXPECTED_BUILD' => $hash, 'GENBA_HTTP_URL' => "$base/http-umleitung", 'GENBA_RETRIES' => '1', 'GENBA_PAUSE' => '0'] + $env)`,
check exit and `preg_match($pattern)`; on failure print the whole output.

Runs (name · fault · exit · pattern · extra env):

```
healthy site is green                 ''              0  'Genba smoke: PASSED'
credentials are sent                  auth            0  'Genba smoke: PASSED'      GENBA_USER=preview GENBA_PASSWORD='ge"heim\x'
old build live                        old-build       1  'Live build is fedcba98'
build missing in HTML                 no-build        1  'without <meta name="build">'
foreign content without marker        no-marker       1  "Marker 'assets/css/tailwind.css'"
sitemap page with 500                 page-500        1  'kontakt: HTTP 500'
HEAD on robots.txt 404                robots-head     1  'HEAD /robots.txt: HTTP 404'
webp as text/plain                    webp-type       1  "bild.webp: Content-Type 'text/plain'"
woff2 without type                    woff2-type      1  "sans.woff2: Content-Type 'application/octet-stream'"
sitemap on old domain, fail           foreign-sitemap 1  'foreign host: https://alte-domain.example/'   GENBA_FOREIGN_SITEMAP=fail
sitemap on old domain, warn           foreign-sitemap 0  '::warning::Sitemap points to a foreign host'   GENBA_FOREIGN_SITEMAP=warn
empty sitemap                         empty-sitemap   1  'Sitemap lists no pages'
error page returns 200                no-404          1  'Unknown address returns HTTP 200'
http without redirect                 no-redirect     1  'http→https missing'
http without redirect, warn           no-redirect     0  '::warning::http→https missing'   GENBA_HTTPS_REDIRECT=warn
https redirect off                    no-redirect     0  'http→https not checked \(GENBA_HTTPS_REDIRECT=off\)'   GENBA_HTTPS_REDIRECT=off
Panel without account                 panel-empty     1  'Panel without account'
Panel without account, warn           panel-empty     0  '::warning::Panel without account'   GENBA_PANEL_ACCOUNT=warn
Panel installer open                  panel-open      1  'can be installed from outside'
installer open, even in warn mode     panel-open      1  'can be installed from outside'   GENBA_PANEL_ACCOUNT=warn
preview without credentials           auth            1  'HTTP 401 instead of 200 \(protected'
preview without credentials, skip     auth            0  'Genba smoke: SKIPPED'   GENBA_WITHOUT_CREDENTIALS=skip
wrong password                        auth            1  'HTTP 401'   GENBA_USER=preview GENBA_PASSWORD=wrong
no hash expected                      ''              2  'is not a commit hash'   GENBA_EXPECTED_BUILD=main
URL missing                           ''              2  'GENBA_URL missing'   GENBA_URL=null (unset) and positional arg absent
user without password                 ''              2  'must be set together'   GENBA_USER=preview
bad mode                              ''              2  'GENBA_HTTPS_REDIRECT must be'   GENBA_HTTPS_REDIRECT=maybe
help                                  ''              0  'GENBA_URL'   (argument --help, no env needed)
no expected build, not a git checkout ''              2  'GENBA_EXPECTED_BUILD not set and the working directory is not a git checkout'   GENBA_EXPECTED_BUILD=null (unset), cwd = an empty temp dir
```

Further runs (fixture faults may be combined, space-separated): `prod-sitemap`
(staging sitemap lists `https://prod.example/…`: rewritten, counted, notice once,
query kept; with `page-500` red; with `auth` + credentials green), `foreign-webp`,
`crowded-webp webp-type` (own assets chosen before the five), `private-open`
(config.php/.env/genba-extra 200: red, also behind credentials; added entry, `none`
replace, `none` off, `../` exit 2; healthy: `ok   17 private paths`), the gate
(`GENBA_EXPECT_PROTECTED=1` against `auth` green with the ok line, against a healthy
open site red, with `skip` and missing secrets SKIPPED with the ok line; DNS
`genba-smoke-test.invalid` SKIPPED with `GENBA_NOT_READY=skip`, red without; https
against the plain-http fixture: certificate pending SKIPPED, open over http red,
default red).

Checks 7 and 7b with their own slugs: `panel-slug` (the Panel at `/kontrolle`, `/panel` answers 404) is red
without `GENBA_PANEL_PATH` and green with `GENBA_PANEL_PATH=/kontrolle`; `api-slug` (the API at
`/schnittstelle`) is red without `GENBA_API_PATH` and green with `GENBA_API_PATH=/schnittstelle`; a path
that is not below the site root (`panel`, `/panel/`, `/`, `/../etc`, `/panel?x=1`, `/a b`) is exit 2
before the first request.

Checks 7b and 9: the Panel faults `panel-no-files`, `panel-css-404`, `panel-redirect-out` red,
`panel-location` (a `Location` instead of `Refresh`), `panel-refresh-quoted` (`0;URL='…'`),
`panel-absolute` (the target on `https://prod.example`: the `--` line, `/panel` asked once) and
`panel-js-text` (`text/javascript`) green, `panel-refresh-query` (`url=` in the query of a target
outside `/panel`) red,
`panel-html-fallback` (the stylesheet answered with an HTML page, 200) and `panel-js-empty` (0 bytes)
red, `panel-plugins-empty` (Kirby's plugin bundles named, both 0 bytes) green; `headers` (the set a hardened site sends, `/panel` its own CSP) with the rules as set green, a
value that differs, a header not sent and one that must be absent red, `!~` on a header not sent
red, a rule on a path that answers 404 red; `~` or `!~` without text, a line without colon and a
bad path exit 2.

Decision 20 (`prod-sitemap` may be combined with
`foreign-sitemap` for a third origin): foreign entry without settings red with the
`not set: fail` line; `auth prod-sitemap` with credentials green with the notice
and the `not set: rewrite` line, also with `GENBA_EXPECT_PROTECTED=1`;
`GENBA_EXPECT_PROTECTED=1` alone on an open site: gate red, sitemap still
rewritten; explicit `fail` wins on a preview; empty value = unset;
`GENBA_SITEMAP_ORIGIN=https://prod.example` with a third origin red (prod still
rewritten), with only prod and a trailing `/` green, with a path exit 2.

A real https fake site, `tests/fixtures/tls-server.php` (one PHP
process, certificate made by `genba_tls_cert()` in helpers.php, trusted through
`CURL_CA_BUNDLE`, `GENBA_MAX_TIME=2`; plain http = the main fixture with `auth`,
so a wrong decision reads SKIPPED, exit 0). Red, never SKIPPED: 401 at once and
the homepage hangs with credentials (`stall-home`, curl 28); TLS breaks after the
gate (`flaky`, 35 on the homepage); the gate itself times out (`stall-first`,
28), and the same without the gate on the first homepage request; an expired
certificate (valid 0 days, curl 60 `certificate has expired`). SKIPPED: a
certificate the client does not trust (curl 60, not expired). Without the
openssl extension: `  --   openssl extension missing, the https not-ready runs
are not tested`.

Sitemap, build and origins (fixture faults `lower-base`, `sitemap-get-500`, `sitemap-html`, `huge-sitemap`,
`long-loc`, `control-loc`, `entity-loc`, `prod-sitemap-variants`): every exit-2 run prints
`Genba smoke: FAILED — cannot run` (asserted in `$run`; `--bogus` too); a healthy run requests
robots.txt, sitemap.xml and llms.txt exactly twice each (GET + HEAD, request log); the sitemap GET
500 while HEAD is 200, an HTML body and a body above 10 MiB are red with
`Sitemap not available for page check` and no `sitemap pages fetched`; `GENBA_EXPECTED_BUILD`
= first 7 digits passes with `ok   Homepage serves build <full hash>`, `abcdef0` is
`Live build is …`; a trailing newline passes; `GENBA_META='bu"ild'` exit 2, `stand` → `without
<meta name="stand">`; a `<loc>` with ESC and CR leaves neither in the output and the last line is
FAILED; a 100 000-character `<loc>` is `Sitemap entry longer than 2048 characters`, no output line
above 400 bytes, not fetched; `&amp;`, `&apos;`, `&quot;` are decoded (`GET /kontakt?a=1&b=2`);
`GENBA_URL=http://LOCALHOST:<port>` against a lower-case site is green with 2 pages and the
redirect ok; `https://PROD.example/` and `https://prod.example:443/…` with
`GENBA_SITEMAP_ORIGIN=https://prod.example` are rewritten, one notice.

Sitemap spellings and log text (fixture faults `pretty-loc`, `cdata-loc`, `unparsed-loc`, `numeric-entity-loc`, `esc-href`,
`esc-src`, `esc-ctype`, `esc-redirect`, `esc-build`, `long-src`, `long-href`, `long-build`; plus
`tests/fixtures/raw-server.php` via `genba_raw_server()` for `cr-location`/`lf-location`): an address on
its own line inside `<loc>` and one in CDATA are fetched (`3 sitemap pages fetched`, `GET /kontakt?p=1`);
a `<url>` without `<loc>` and a `<loc>` with markup inside are `Sitemap: 2 entries could not be parsed
(4 <url> entries, 2 <loc> addresses)`, the readable ones still fetched; `&#38;`, `&#x26;`, `&#61;` are
decoded (`GET /kontakt?a=1&b=2`, `GET /kontakt?c=&d=3`); ESC, CR and U+0085 in an href, a src, a
Content-Type, a redirect target or the build `<meta>` leave neither in the output, a `::error::forged`
never starts a line and the real last line closes the run; a 100 000-character image, stylesheet or
build value is `… longer than 2048 characters`/`Live build is a{60}… (100000 characters)` with no line
above 400 bytes and nothing fetched; `Only one URL argument is allowed`, `GENBA_PAGES_MAX`/`GENBA_RETRIES
must be a whole number above 0`, `GENBA_PAUSE must be a whole number of seconds` exit 2.

Findings before SKIPPED, secrets and counts (fixture faults `amp-attr`, `many-webp`, `double-entity-loc`, `relative-loc`; raw-server
mode `empty-then-401`): `$run` asserts over the whole matrix that a run whose last line is
`Genba smoke: SKIPPED` has no `::error::` line. `empty-then-401` with `GENBA_EXPECT_PROTECTED=1`
and `GENBA_WITHOUT_CREDENTIALS=skip` → exit 1, `Without credentials … gives no answer (HTTP 000)`,
the `--   … not SKIPPED: the run has findings already` line, last line FAILED; `auth` with the
same settings stays SKIPPED. LF or CR in `GENBA_PASSWORD`, LF in `GENBA_USER` → exit 2 `must not
contain a line break`, nothing of the secret and no `Genba smoke against` in the output, no
`HTTP 000`. `GENBA_PAGES_MAX=1` on the two-page sitemap → `ok   1 of 2 sitemap pages fetched
(GENBA_PAGES_MAX=1), the rest not checked`, one page requested; `=2` → `ok   2 sitemap pages
fetched`. `many-webp` (seven own files, eight with `bild.webp`) → `--   8 .webp files of <origin> on
the homepage, the first 5 checked`, five ok lines. `amp-attr` → `GET /assets/css/tailwind.css?v=2&x=2`,
`GET /media/bild.webp?a=1&b=2`, `GET /media/bild.avif?s=1&t=2`, no request with `amp;`.
`double-entity-loc` → `GET /kontakt?v=1&` (the fragment cut by curl), never `?v=1&w=2`, 3 pages.
`relative-loc` in fail, rewrite (`auth` + credentials) and warn mode → exit 1, `Sitemap entry is
not an absolute URL: /relativ — …`, no "foreign host", `/relativ` never requested.

Failed transfers (fixture faults `short-sitemap`, `stall-sitemap`; raw-server mode `short-body`, TARGET
= the body): the homepage cut after its `<head>` (`short-body`, Content-Length 9000) → exit 1,
`Homepage: HTTP 000 instead of 200 — curl: (18) transfer closed with <n> bytes remaining to
read`, no `Homepage serves build`, the same curl text on `Unknown address returns HTTP 000` and
`GET /robots.txt: HTTP 000`; `short-sitemap` (Content-Length 4000, body ends after the first
entry) → `GET /sitemap.xml: HTTP 000 instead of 200 — curl: (18) …`, `Sitemap not available for
page check`, no `sitemap pages fetched`, HEAD still ok; `stall-sitemap` with `GENBA_MAX_TIME=2`
(headers, then a 3 s pause on the GET only) → `… — curl: (28) Operation timed out`, no `ok   GET
/sitemap.xml`, no `grep:` line in the output. A dead proxy (`http://127.0.0.1:1`)
in `HTTPS_PROXY`, `https_proxy`, `http_proxy`, `ALL_PROXY`, `all_proxy` → still `Genba smoke:
PASSED`, and the unresolved host with `GENBA_NOT_READY=skip` is still `SKIPPED — … does not
resolve yet`; source: `CURL_BASIC` carries `--noproxy '*'`. `$unset` clears the eight proxy
variables (`HTTPS_PROXY`, `https_proxy`, `HTTP_PROXY`, `http_proxy`, `ALL_PROXY`, `all_proxy`,
`NO_PROXY`, `no_proxy`) for every run, so the matrix reads the same behind a real proxy.
The argument `''` with `GENBA_URL` set → exit 2, `URL argument is empty (set GENBA_URL
or pass the address)`, no `Genba smoke against`. Fixture fault `nul-home` (a NUL byte
after `<body>`) → exit 0, `ok   Homepage serves build <hash>`, marker and stylesheet found, no
`Binary file` text in the output.

Bytes that are no UTF-8 (fixture faults `invalid-byte-sitemap`, `invalid-byte-home`: a `\xFF` in an
image title of a third sitemap page, in the text of the homepage), run with `LC_ALL` and `LANG`
set to `en_US.UTF-8`, else `C.UTF-8` (from `locale -a`; neither installed → a visible `  --`
line) → exit 0, `ok   3`/`2 sitemap pages fetched`, the output ends with `Genba smoke: PASSED.`
or `FAILED — …`, no `Illegal byte sequence`. Red on macOS without `LC_ALL=C` on the tools; on
Linux (GNU tools are lenient) they alone would be a regression guard only, so the runs use
strict stand-ins in front of `PATH` — `tr`, `sed`, `grep`, `cut`, `sort`, `paste`, `awk` as small sh
scripts that, in a UTF-8 locale (`LC_ALL`, else `LC_CTYPE`, else `LANG`), check their stdin and every
file argument with `iconv -f UTF-8 -t UTF-8` and stop with `Illegal byte sequence`, exit 1, as macOS
does; in any other locale they `exec` the real tool. So a call without `LC_ALL=C` is red on every
system. They prove themselves first (`tr` on `a\377b`: exit 1 in UTF-8, the real output in C);
without `iconv` a visible `--` line. Fixture fault `invalid-byte-loc`: `<loc>` entries
`…/kontakt?y=1#caf\xFF` (absolute, fetched: `GET /kontakt?y=1` — PHP's server drops a request with a
raw `\xFF` in its path, curl does not send the fragment), `…/caf\xFF&#233;` and `http://\xFF.example/`
→ exit 1, `Sitemap entry with a character reference that is not decoded: …/caf%EF%BF%BD&#233; — not fetched`,
`Sitemap points to a foreign host: http://%EF%BF%BD.example/ …`, no `not an absolute URL`, `ok   3 sitemap
pages fetched`. Red on macOS without `bytes_match` (5 checks); glibc bash matches either way.
`encode_controls` makes the `\xFF` `%EF%BF%BD` before any check reads the entry; `bytes_match` stays,
although no address reaches a check with a byte outside UTF-8.

Fixture fault `comment-in-script` (an inline `<script>` with `s.indexOf('<!--')` before a 404
stylesheet, a `<!-- footer -->` further down) → exit 1, `Stylesheet …/assets/css/missing.css: HTTP 404`,
the stylesheet before it and the five images behind it still checked. `unquoted-attr` (`<link rel=stylesheet
href=/assets/css/missing.css>`, `<img src=/media/a.webp>`) → exit 1, the same 404 finding, `/media/a.webp: image/webp`. `uppercase-css`
(`<LINK REL="STYLESHEET" HREF="/assets/css/missing.CSS">`) → exit 1, `Stylesheet …/missing.CSS: HTTP 404`. `foreign-numref`
(`https://cdn.example.com/caf&#233;.css` and `….avif`) → exit 0, PASSED, no `not decoded` line, nothing with `caf` requested.
`abrupt-comment` (`<!--><link rel="stylesheet" href="/assets/css/missing.css">`, a
`<!-- footer -->` further down) → exit 1, `Stylesheet …/missing.css: HTTP 404`, the stylesheet before it still `ok`.

**The HTML variant corpus** (`tests/HtmlCorpusTest.php`): one homepage in many spellings a
browser reads the same, `tests/fixtures/html-variants/<name>.html`, served by the router for the fault
`variant:<name>` (`@BUILD@` replaced by the build hash, asset routes matched without regard to case,
a name starting with `crlf` sent with CRLF line endings). Every variant runs with `GENBA_MARKER=genba-corpus`
and `GENBA_PRIVATE_PATHS=none` and must end `Genba smoke: PASSED.` with `ok   Marker 'genba-corpus' found`,
exactly one `ok   Stylesheet` line, and exactly these GET requests besides the ones every run makes (`/`,
the unknown address, `robots.txt`, `sitemap.xml`, `llms.txt`, `/kontakt`, `/api/system`, `/http-umleitung`;
every other request counts, not only those below `/assets/` and `/media/`, so a reference read as a
relative path — `GET /HTTPS://…` — is seen) (compared in lower case): `/assets/css/tailwind.css?v=1`,
`/assets/fonts/sans.woff2` (the stylesheet's `url()`; in a variant run the stylesheet also carries
`url(DATA:,y.woff2)`, a `data:` URI in capitals — not requested), `/media/bild.webp` (a `src`), `/media/bild.avif` and
`/media/bild-2x.avif` (a `srcset`) — nothing commented out, nothing from inside `<script>` or `<style>`. The `<meta name="build">` is read in every spelling too
(`minified` without quotes, `uppercase` as `<META NAME="build" CONTENT=…>`, `attribute-order`
with `content` before `name`). A new spelling is a new file. A finding about how smoke reads HTML names the
spelling that is missing here or that the code reads wrongly.

| Variant | Spelling |
|---|---|
| `base` | double quotes, one element per line |
| `single-quotes` | every value in single quotes |
| `prettier` | each attribute on a line of its own, `/>`, the marker on a line of its own, a `srcset` over four lines |
| `crlf-tabs` | CRLF line endings, tabs between the attributes and inside the `srcset` |
| `attribute-order` | `href` before `rel`, `media`/`type`/`loading`/`sizes` around them, `/>` without a space |
| `conditional-comments` | `<!--[if lt IE 9]><link …ie.css>…<![endif]-->` (a comment: not requested) and the downlevel-revealed `<!--[if !IE]><!--><link …tailwind.css><!--<![endif]-->` (on the page) |
| `comments` | the marker only inside `<!-- genba-corpus -->`, a commented-out `<link>`, an `<img>` commented out over three lines, `<!---->`, `<!-- a -- b -->`, a comment closed by `--->` |
| `unquoted` | `rel=stylesheet href=/assets/css/tailwind.css?v=1`, `src=/media/bild.webp`, a `srcset` per image without quotes |
| `minified` | the page on one line, no whitespace between tags, values without quotes where possible, a `srcset` without a space after the comma |
| `spaced-equals` | `rel = "stylesheet" href = "…"`, `src = "…"`, `srcset = '…'` |
| `gt-in-value` | `title="a > b"` and `data-x="->"` before `rel` and `href`, `alt="1 > 0"`, `alt='-> b'` |
| `uppercase` | tags and attributes in capitals (`<LINK REL="STYLESHEET" HREF=…>`, `<IMG SRC=…>`, `SRCSET`), extensions `.CSS`, `.WEBP`, `.AVIF`, `.Avif`; `<META NAME="build" CONTENT=…>`; a foreign stylesheet `HTTPS://CDN.EXAMPLE.COM/x.css` and image `Https://Cdn.Example.Com/y.webp` — not requested; `<LINK REL=STYLESHEET HREF="DATA:,x.css">`, a `data:` URI in capitals — not requested |
| `raw-text` | an `<!--` inside a `<script>` and a `<style>`, a `<script type="text/template">` and a `<SCRIPT>` string holding a `<link>` and `<img>` (raw text: not requested), a `<!-- footer -->` further down |
| `src-in-value` | `src=`/`srcset=` inside other values (`href="/bild?src=…&amp;srcset=…"`, `alt='src=…'`, `title="x srcset=…"`), `data-src`, `data-srcset`, `data-href` on the `<link>` — none requested |
| `lt-in-value` | a `<` in quoted values: `content="Tipp: <!-- in HTML"`, `title="a <style> b"` on the `<link>`, `data-hint="put <script> tags at the end"`, `alt='1 <!-- 2'`, `alt="<b>Bild</b>"`, a `<!-- footer -->` further down |
| `apos-unquoted-even` | a `'` inside unquoted values, an even number of them: `content=Kirby's` before a commented-out `<link>` and the stylesheet, `title=don't` further down |
| `apos-unquoted-odd` | one `'` inside an unquoted value: `<img alt=Kirby's src="/media/bild.webp">` |
| `lt-many-in-value` | 60 `<` in one quoted value (`data-tpl="<b>x</b>…<!-- hint"`), the `-->` that would close its `<!--` in the text behind the stylesheet |
| `comment-ends` | `<!-->` right before the `<link>`, `<!--->` before the `<img>`, an `<img>` commented out and closed by `--!>`, `<!-- old --!>` before the `srcset`, a footer comment closed by `--!>` |
| `base-after-link` | the stylesheet written relative (`assets/css/…`) BEFORE `<base href="/media/">`, the images relative after it (`bild.webp`, `bild.avif 1x, bild-2x.avif 2x`): the base counts from where it stands |
| `shadow-root` | the stylesheet, the `.webp` and (in a nested `<template shadowrootmode="closed">` inside a `<span>`) the `srcset` only inside `<template shadowrootmode="open">` of a `<div>`; not requested: an inert `<template>` inside it, a shadow root on an `<a>` (no valid host), the second shadow root of one `<div>`, a shadow root on `<font-face>` (a reserved name, no valid host) |

One more run, "expected build from git" (exit 0, `Genba smoke: PASSED`), needs
a server whose build equals a real git HEAD: create a temp git repo with one
commit (`git init`, `git -c user.name=t -c user.email=t@x commit --allow-empty -m x`),
read its HEAD with `git rev-parse HEAD`, start a SECOND `genba_server()` with
`GENBA_TEST_BUILD=<that HEAD>` and an empty fault file, and run the tool with
`GENBA_EXPECTED_BUILD` unset (`null`) and cwd = that repo. Stop both servers in
`finally`. If `git` is not on PATH, print `  --   git not found, "expected build
from git" not tested` and skip only that run (visible, not green-by-default).

Deliberately untested: the curl exit 26 path in `fetch()` (`::error::curl
cannot read its credentials file (curl exit 26) — …`, `cannot_run`). The only local file curl is
given is the `-K` credentials file the tool itself writes under `umask 077`, so the path is
reachable only through a file that became unreadable between the write and the request — not
through any input (a line break in the secret, the one real cause, is refused before the first
request, tested). A test would need a fake `curl` on PATH or a test switch
in the tool (`GENBA_TEST_CURL`), and a test switch in a production tool is not worth one line;
the mutation `FETCH_RC == 26` → impossible value stays green by design. Do not measure it again.

Plus, source-level: `bash -n bin/genba-smoke` exits 0; the script never passes
credentials as a curl argument: `preg_match('/curl[^|]*(-u|--user)[ =]/', $source)` must be 0;
the script contains `set -euo pipefail`; if `shellcheck` is on PATH
(`genba_run(['shellcheck', '--version'], …)` exit 0) → `shellcheck bin/genba-smoke`
exit 0 with its output shown on failure; else print
`  --   shellcheck not installed, bin/genba-smoke not linted` (visible, not a failure).
Also: the credentials file must be created with mode 0600 — assert the source
contains `umask 077`.
`bytes_match()` must set `LC_ALL=C`, and every other line with `=~` (comments
aside) must have a setting as its left operand — `$URL`, `$EXPECTED`, `$META`, `$entry`, `$PAGES_MAX`,
`$RETRIES`, `$PAUSE`, `$MAX_TIME`, `$SITEMAP_ORIGIN`, one per `=~`. glibc bash matches across a byte
that is no UTF-8 in any locale, so the invalid-byte runs stay green on Linux without the C locale;
the source check is red on every system. It proves itself on a planted `[[ $STAND =~ … ]]` and on a
`bytes_match` without `LC_ALL=C`.

### 9.6 tests/LinksTest.php (task `links`, Kirby-free)

Port `FremdlinksTest.php` completely with the English names and messages of
§3.5: the 16 `classify()` cases (same URLs, kinds `youtube`, `youtube-embed`,
`digistore`, `purchase`), `product` on the redir link, the template with
placeholders and HTML entities (one reference, `vorlage.php:4`), the eight
`nameMatches()` cases, then the router (`tests/fixtures/` is owned by `smoke` —
put the router inline as a heredoc string written into the temp dir, exactly
like the original does) with oEmbed, product pages, purchase chains and the
`/blind/` variant, started with `genba_server()`; the status matrix
(`gutesVideo1` OK, `geloeschtXX` FINDING, `privatVideo` FINDING, `gesperrtVid`
NOTICE as link / FINDING as embed, `wackelVideo` DISRUPTION, product 100 OK,
101 FINDING containing `AtlasChakra`, 102 FINDING, 999 FINDING, `/kauf/gut?aff=sayama&cam=herbst`
OK, `/kauf/verlust?aff=sayama` FINDING containing `aff=sayama`, `/kauf/tot`
FINDING, `/kauf/schleife` FINDING), `counterCheck()` === [] against the honest
server and 2 failures against `/blind/`; then the full CLI runs through
`genba_run([genba_php(), 'bin/genba-links', '--pause=0', …], "$tmp/projekt")`:
findings run exit 1 with `Digistore product 101 is called "Klang Chakra Aktivierung ~ AtlasChakra", but the page is "Thymuschakra" / "Thymus Chakra" — wrong ID?`,
`Video privatVideo is private`, `Parameters lost on the way: aff=sayama`,
`::warning::Disruption, not assessed`, `Counter-check passed`, `3 findings`,
and NOT `AtlasChakra Cover`; disruptions-only run exit 0 with `1 disruptions`;
blind run exit 1 with `::error::Counter-check`; `--list-only site` exit 0 with
`0 external references`. New: a run with no existing paths
(`genba-links /nowhere`) → exit 2 `None of the paths exist`; an empty existing
dir → exit 0 with `::notice::No external references found`; `--help` 0.

---

Symbolic links and permissions: `site/snippets/c -> ../cache` and `public/p -> ..` under `--list-only`: named, the
`site/cache` video not listed; a directory with mode 0000 → exit 2,
`::error::Cannot read directory: …content/home`, no stack trace; a file with mode 0000 → exit 2,
`::error::Cannot read file: content/home/home.txt`, no summary line (both skipped visibly when
running as root).

File links and last lines: `content/leak.txt -> <outside>/default.txt` → `symbolic link to a file outside the
project`, its video not listed; with `public/p -> ..` still `2 external references found` and no
place under `public/p/`; every exit-2 run (`/nowhere`, `--digistore-field=`, `--bogus`, unreadable
file, `--nonsense` in-process) has `Genba links: FAILED — cannot run` on stdout — in-process
exactly that line alone; the blind run ends with `Genba links: FAILED — counter-check failed, no
reference was checked`. §9.2: `--hook=` → exit 2, `::error::--hook needs a value` on stderr,
nothing run.

Refusals and log text: `new Links(oembedBase: 'https://user@evil.example/oembed')->youtube()` → FINDING
`Video <id>: URL refused (user information in the address)`, never "unreachable"; `--list-only site`
ends with `::notice::No external references found under site` and `Genba links: 0 references listed`,
the `felder` listing with `Genba links: 1 references listed`; `esc/default.txt` with a URL ending in ESC
and `esc/d<ESC>r/default.txt` → no ESC in the listing or the result lines, the place printed as
`esc/d r/default.txt:1`; `--nonsense` → `::error::Unknown argument: --nonsense` on stderr.

The mode-0000 directory is `::error::Cannot read directory: content/home` exactly (relative,
no absolute path); `content/sec<ESC>[31mRET` with mode 0000 → `::error::Cannot read directory:
content/sec [31mRET`, no ESC in the output. The installed-copy fixture copies `src/Text.php` too.

### 9.7 tests/MessagesTest.php

`docs/messages.md` lists every line the tools print, and a line missing there is a test failure, not
something left for a reader to find. The test reads the message literals of `bin/genba-smoke` (the double-quoted
argument of `fail`, `warn`, `ok`, `note`, `cannot_run`, `echo`, and `message="…"`, split at every
`$name`, `${…}`, `$(…)`) and of `src/{Stamp,Preflight,Precheck,Tree,Links}.php` (`token_get_all()`:
strings in `fwrite()`, `cannotRun()` and exception calls, after the keys `text`, `refused`, `message`,
`reason`, `error`, assigned to `$why` or added to `$failures`; split at `\n` and sprintf conversions,
regular expressions skipped), and requires every fragment with at least ten letters in the
section of its tool in `docs/messages.md` (`## genba-<tool>`; Tree's in precheck's and links'), whitespace
collapsed and `[ ]` dropped. It proves itself first: a planted PHP and bash message is found, a documented one not.
The `GENBA_MAX_TIME` row of `docs/reference.md` must name both bounds.

## 10. Documentation (owner `docs`)

- **README.md** (English), at most 150 lines and 1,300 words: what it is (one tagline, a three-sentence intro), features (at most six
  bullets), requirements (PHP 8.2+, genba-smoke 8.4+, Kirby 5, bash + curl + git), installation
  (`composer require`, the vcs repository line until Packagist, `.gitignore` entry `/site/plugins/genba`,
  "deploy it like kirby/ and vendor/"), usage (`genba.active`, `snippet('genba/stamp')` in `<head>`,
  `/site/config/build.txt` in `.gitignore`, the five tools in one table with exit codes, one preview step of
  `genba-smoke`), options table of the Kirby part (§4), privacy (short statement), links to the two files
  below, maintenance (tests, issues, security reporting, CHANGELOG), license.
- **docs/reference.md** (English, at most 200 lines, terse tables): the Kirby part's functions, site methods,
  snippets and translation keys; one settings table per tool (§6, every setting with its default); the
  `genba-smoke` checks and the default private paths; the `genba-precheck` rules; the `genba-links` kinds;
  the workflow order and the preview and live settings of `genba-smoke`; the test list (every `tests/*Test.php`).
- **docs/messages.md** (English): a plain list per tool of every line the tools print, `<…>` for values,
  no prose. Read by `tests/MessagesTest.php` (§9.7).
- Not in the docs: the "Staging behind Basic Auth" essay, the "Switching from the starter copies" table, the
  "Boundaries" section and the history of the old copies; the CHANGELOG keeps the history.
- **CHANGELOG.md**: Keep a Changelog, SemVer, `## [1.0.0] — <release date>`, with
  "Changed — renamed to English (breaking)" (table §8), "Added", "Changed
  behaviour" (the list at the end of §8, numbered), "Unchanged (data
  continuity)" (`site/config/build.txt`, `<meta name="build">`).

---

## 11. Design decisions

1. **Delivery shape** (§7): one Composer package, tools under `vendor/bin/`,
   no composite GitHub Action. Reversible later.
2. **The Kirby part is off by default** (`genba.active`), like every family
   member — so a site that forgets the option gets a loud smoke-test failure
   ("Homepage without <meta name="build">") rather than a silent stamp. That
   is the right failure direction.
3. **`GITHUB_SHA` is not used** for the stamp; `git rev-parse HEAD` of the
   checkout is. On a PR build `GITHUB_SHA` is a merge commit nobody deploys.
4. **Preflight is a separate tool** (`genba-preflight`) that only runs the
   project hook; the stylesheet check moved into `genba-precheck`
   (`GENBA_REQUIRE_FILES`, default `public/assets/css/tailwind.css`). Reason:
   the hook runs BEFORE `npm run build`, the file check AFTER — one tool could
   not do both in one step. A site without Tailwind sets
   `GENBA_REQUIRE_FILES=none` (said out loud in the log).
5. **Pre-deploy checks moved from bash to PHP** with a per-rule self-test
   (positive sample plus optional negative samples), default allow entry
   `places.googleapis.com/v1/` (Koe) and the anchored `//fonts\.` pattern that
   every site copy uses by now (11.10.2026; the unanchored `fonts\.` of the
   older per-site copies is gone). One site's workflow runs only
   two rules (`pruefe-ressourcen.sh`); with Genba it gets all four — if its
   templates trip the handler or script rule, that is a real CSP finding.
6. **CLI output is English**; only the Kirby snippets are translated
   (`build.*`, `panel.*` keys). The family's I18n convention is kept in shape
   but the log lines are not run through it — a bash script could not share
   the tables, and two message tables would drift.
7. **"Secrets missing → skip"** moved from the workflow into the tool as
   `GENBA_WITHOUT_CREDENTIALS=skip`, printed as `Genba smoke: SKIPPED — …`
   with exit 0. The **default is `fail`** (a 401 without credentials is a
   finding, exit 1): a preview site that wants today's workflow behaviour
   ("secrets missing → warning, run green") sets `skip` explicitly in its
   `env:` block and thereby documents that it accepts an unverified deploy.
   The alternative, default `skip`, would make the tool green on a forgotten
   secret — the silent pass requirement 3 forbids.
8. **New findings the old script did not have**: empty sitemap is red;
   `GENBA_FOREIGN_SITEMAP=warn` for staging whose site URL is production.
   Both can be turned back.
9. **The smoke test stays bash**, the other four tools are PHP (§7, 4–5). Bash fetches, PHP 8.4 reads the homepage (decision 24).
10. **`genba-links` keeps "disruptions are warnings, exit 0"** — the reasoning
    from the original (a flaky network must not create a red run nobody reads)
    still holds. It also keeps the daily schedule in a separate workflow, not
    in the deploy.
11. **Test fixtures use PHP's built-in server** instead of Python
    (`smoke-test-gegenprobe.sh` used python3). One runtime less on the runner;
    verified that `php -S` handles HEAD and Basic Auth.
12. **`genba/build-info` snippet** is new (a visible "Stand abc1234" line for
    preview footers). Small, optional, gives the translations a purpose.
13. **Not done, by design**: the deploy script itself (`deploy-ftps.sh`, its
    dry-run test), the preview-protection reachability step (`401 without
    credentials` — that is Sudare's area), `pruefe-zugangsdaten.php`,
    `pruefe-kizami.php`, sicherung. They stay in the starter.
14. **Open question (settled by 22)**: should `genba-smoke` also check that nothing above
    `public/` is reachable (`/site/config/config.php` → not 200), as
    a live site's workflow does inline? It fits the smoke test; it was left
    out first to keep the port 1:1. Easy follow-up (`GENBA_PRIVATE_PATHS`).
15. **shellcheck comes with the runner.** GitHub's ubuntu runners have
    `shellcheck`, so `ci.yml` only prints its version; on a runner without it the
    step installs it with `sudo apt-get` and prints a `::warning::`
    instead of failing when that does not work, and `tests/SmokeTest.php` then
    prints its visible `  --   shellcheck not installed` line.
16. **The `embedded-script` rule is a substring test with exceptions, not one
    regex**: `RULES` entries carry an optional `unless` list (grep -v
    semantics). A single negative-lookahead regex would have been harder to
    read and to self-test; the exception words are now data, like the allow
    list.

### Decided 11.10.2026

Decided and implemented:

17. **Credentials only over https — done:** with `GENBA_USER`/`GENBA_PASSWORD`, `genba-smoke` stops with exit 2 before the first request when `GENBA_URL` is not https; the only exceptions are 127.0.0.1, localhost and [::1] (the machine itself, where the tests run).
18. **SSRF guard in the link check — decided 11.10.: check the first address too.** Every address is parsed, checked and rebuilt. Purchase links from the content are checked for private/local targets before the first request (`address <host> refused (…) — not requested`), unless their host is **exactly** a configured shop host (`--shop-host`, subdomains do not count). Only the endpoints from the options (oEmbed, Digistore) stay trusted. The CLI tests run with `--shop-host=127.0.0.1` and so stay green without a hidden switch.
19. **Reachability gate of the preview workflow — decided 11.10.: moved into Genba.** `GENBA_EXPECT_PROTECTED=1`: the first request goes out WITHOUT credentials and must be 401, else a finding ("… the site may be open to everyone"). `GENBA_NOT_READY=skip`: when the name does not resolve (curl exit 6), the run ends with a `::warning::` and the last line `Genba smoke: SKIPPED — <host> does not resolve yet`, exit 0; when https fails while http answers (with protection expected: 401), likewise as `SKIPPED — https to <host> fails (certificate pending?)`. Never `PASSED`. Default `fail`: a name that does not resolve is a finding, exit 1. Ported from `kirby-starter/.github/workflows/deploy-vorschau.yml` (step "Erreichbarkeit prüfen", identical in two more sites; one site has the variant 401/200 by `OEFFENTLICH`, which `GENBA_EXPECT_PROTECTED` 1/0 covers). The preview workflow gets one step, one line (README "Reachability gate"). **Sharpened:** "not ready" is decided exactly once, on the first request (for the gate, the one without credentials); for https only a connection or TLS error counts (curl 7, 35, 51, 58, 59, 60, 77, 83, 90, 91), never a timeout (28), an empty answer (52), a broken transfer (56) or an expired certificate — those are findings. Reason: a preview that answers 401 at once and hangs with credentials must not pass as "certificate pending?".
20. **Foreign sitemap addresses — decided 11.10., refined: rewriting is the default only on a preview run.** Without a value (empty included) `rewrite` applies only when the run is a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set), else `fail` — a live site that forgets the setting stays red instead of rewriting silently. An explicit value always wins. Which default was taken is one line in the log (`  --   GENBA_FOREIGN_SITEMAP not set: rewrite (a preview run: …)` or `… fail (not a preview run: …)`). New and optional: `GENBA_SITEMAP_ORIGIN=https://prod…` — then only this origin is rewritten, and every address on a third origin is a finding (`… (neither <origin> nor GENBA_SITEMAP_ORIGIN <origin>)`). The rewrite itself: `GENBA_FOREIGN_SITEMAP=rewrite` fetches path and query of every foreign `<loc>` on the `GENBA_URL` origin, with the same credentials (only there), and reports it once as `::notice::` with both origins and the count. `warn` and `fail` remain available; **the live site sets `fail`** (there a foreign address means: the site URL is wrong) — the README example does so. 0 fetched pages stays a warning, never `ok`.
21. **Default `GENBA_WITHOUT_CREDENTIALS=fail` — decided 11.10.: stays.** Preview workflows set `skip` explicitly.
22. **`GENBA_PRIVATE_PATHS` — decided 11.10.: for every site, on by default.** Check 8 of the smoke test: none of the private addresses (the list from a live site's deploy workflow, extended by `.env`, `.git/HEAD`, `composer.*`, `site/`, `content/`, `vendor/`, `storage/`, `credentials.php`) may answer 2xx; counted, with credentials. Entries are added; `none` replaces the list or switches the check off (visibly).
23. **Credentials only over https (17) — decided 11.10.: stays.**

This supersedes 13 (reachability gate "not in Genba, by design") and 14 (the open question `GENBA_PRIVATE_PATHS`).

### Decided 13.10.2026

24. **The homepage reader is `Dom\HTMLDocument` (PHP ≥ 8.4), and only it.** curl/bash fetch, PHP reads (§7, 5). Measured against the corpus variants and 57 tokenizer probes: `Dom\HTMLDocument` (Lexbor) reads identically on 8.4 and 8.5, and as the browser does. libxml (`DOMDocument`, 2.9.13) is no HTML5 parser (it reads a `<link>` in `<textarea>`, `<xmp>`, `<noembed>`, `<iframe>`, in `<svg>`/`<math>`). `masterminds/html5` 2.11 (identical on 8.2–8.5) read one corpus variant (`comment-ends`: `<!--->` swallowed the rest up to the next `-->`) and 18 of the 57 probes differently from Lexbor — `</style >` and `</script/>` did not end the element, script escape states, `<` in an attribute name, character references without `;`, invalid bytes, HTML in `<svg>`/`<math>`, `<select>`, `<frameset>`, `<plaintext>`. Correcting these places in a subclass would have been a tokenizer of its own in borrowed clothes (rejected). Consequence: **`genba-smoke` needs PHP 8.4 on the machine that runs it** (the CI runner, not the web server); below that it ends before the first fetch with "cannot run", exit 2, and names the version. The plugin part and the four other tools stay at PHP ≥ 8.2. CI: matrix 8.2–8.5; the smoke fault matrix and the smoke corpus on 8.4/8.5, on 8.2/8.3 `SmokeTest` checks the abort. `<noscript>` is read as markup (scripting off, as Lexbor parses): louder, not quieter. `<base href>` is honoured and named in a `--` line.
25. **Safety net under the reader** (§3.4 check 5): the default marker is the stylesheet path; when it stands in the page, a stylesheet of the own origin with that address must have been read, else a finding (exit 1). A finding rather than a warning, because a warning leaves the run green — exactly the silent pass a reader error would otherwise cause. To switch it off: `GENBA_MARKER` set to a text that is no `.css` address.
26. **mbstring missing → "cannot run"** in every PHP tool (`Text::missingMbstring()`, also for a function switched off with `disable_functions`), instead of a fatal error in the middle of a run.
27. **Every rule that names an attribute reads Kirby's `attr()`** (§3.3): the forbidding ones (`inline-style`, `inline-handler`) through `attr` in the rule table — every call, also in a branch, every item that is not certainly left out; the exempting one (`embedded-script`) through `exempt` — only a call that is the whole expression of its block, and only a `src` printed for sure. Two directions, because "maybe printed" is a finding when forbidding and none when exempting.
28. **`<base>` and declarative shadow DOM as in the browser** (§3.4): `<base>` counts from where it stands, a `data:`/`javascript:` base is ignored (specification "frozen base URL"); `<template shadowrootmode>` on an allowed host is read (Lexbor knows no shadow DOM, so it is parsed again from `innerHTML`). The measure stays the browser, not the convenience of the reader.
29. **Check 5 on staging with the production URL**: with `GENBA_SITEMAP_ORIGIN` on a rewrite run, stylesheets and files on that origin are checked on the `GENBA_URL` origin, like the sitemap entries. Only this declared origin, never in rewrite mode alone (a CDN would become a false 404). Without it the net stays red — said in the README and the CHANGELOG.
30. **The reader has bounds**: homepage at most 10 MiB (like the sitemap; a finding instead of an attempt to read it), reader at most `GENBA_MAX_TIME` seconds (a watchdog in bash, because `max_execution_time` does not reach into Lexbor and macOS has no `timeout`); a timeout is "cannot run" like every other reader error.
31. **Control characters in an address are encoded, not removed**: one rule for every address reader (`Homepage::url()`, `encode_controls` for `<loc>` and `url()`), as the URL parser does: edges stripped, tab and line breaks removed, the rest percent-encoded. It is still log text.
32. **`attr()` in every form that prints a name** (§3.3): names are the first argument by position or as `attr:`/`name:`, an array literal yields its items, a single string name (`Html::attr('style', $css, ' ')`) is one item with the second argument (`value:`) as its value — without a value, or with `null`/`false`/`[]`, Kirby prints nothing. The measure is the rule table "The call forms of `attr()`" in §3.3, tested row by row in both directions. Every call named `attr` counts (as for the array form), `$obj->attr(…)` too: louder rather than silent.
33. **Bytes outside UTF-8 in an address are U+FFFD** (§3.0 "Log text"): `encode_controls` writes `%EF%BF%BD`, one per maximal subpart, as the UTF-8 decoder of the Encoding Standard does (measured: Lexbor makes one U+FFFD of `\351\233[`, two of `\300\257`) — not one per byte. So `<loc>`, `url()` and the homepage follow the same rule as the browser, and no raw byte reaches a request or the log. The measure is the table "Bytes in an address" in §3.0, tested with every awk of the machine and against `Homepage::read()`. NUL stays removed (awk cannot hold it).
34. **The 10 MiB bound holds with every curl** (reproduced with curl 8.7.1 and a wrapper without `--max-filesize`): curl below 8.4 (Ubuntu 22.04: 7.81, Debian 12: 7.88) stops at `--max-filesize` only when the size is announced; a page without Content-Length (PHP-FPM, chunked, HTTP/2) would be read in full. `fetch()` therefore also holds the size on disk against the bound and sets 63 itself — for homepage and sitemap, one rule.
35. **Check 5 only on a homepage that was read**: too large or not 200 → one `--` line with the reason, as check 8 already had it; never "no .webp file found" for a page that was not read. Also for a homepage ≠ 200: the run is red there anyway, and the error page is no homepage.
36. **Reserved names are no shadow host**: a valid custom element name excludes the eight reserved names with a hyphen (`annotation-xml`, `color-profile`, `font-face` …, `missing-glyph`); there `attachShadow()` throws, and the browser leaves the `<template shadowrootmode>` inert (Chromium: `shadowRoot === null`). The measure is the table of hosts in `tests/HomepageTest.php`, written by hand, not read from the class.
