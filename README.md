# Genba

Operations for a deployed [Kirby CMS](https://getkirby.com) 5 site: the build stamp in the HTML, the pre-deploy checks, the post-deploy smoke test and the check of external links. Command-line tools for CI plus a small Kirby plugin part. No styling.

*Genba* (現場) is the place where the work actually happens. The package is what runs at that place: before the upload, after the upload, and once a day against the sources of the links.

- **Build stamp.** The deployed commit hash is written to a file and printed as `<meta name="build">`, so a check can tell whether the new code is live, not just whether something answers.
- **Five tools.** Preflight hook, pre-deploy checks (Content Security Policy, built assets), stamp, smoke test (build, content, delivery, Panel) and link check (where a YouTube, Digistore24 or purchase link really leads).
- **No silent passes.** A check that cannot run says so and exits 2. A check that is deliberately not run prints a visible `--` line with the reason. See [No silent passes](#no-silent-passes).
- **Staging behind Basic Auth.** Credentials travel to `curl` through a file, never as an argument and never over plain http. See [Staging behind Basic Auth](#staging-behind-basic-auth).
- **Kirby part off by default.** `'genba.active' => true` switches on the snippets and the site methods. The tools do not need Kirby.
- **English and German.** The Kirby texts are translated (`genba.*`). The command-line output is English.
- **No cookies, no browser storage, no requests to third parties.** See [Privacy](#privacy).

## The tools

Five commands, linked into `vendor/bin/` by Composer:

| Tool | What it proves | When in the pipeline | Exit codes |
|---|---|---|---|
| `genba-preflight` | The project's own generation step (SVG motifs, fonts, data imports) ran, or visibly had nothing to run | Before `npm run build` | 0 passed or SKIPPED · 1 hook failed · 2 cannot run |
| `genba-precheck` | The built site obeys the Content Security Policy (no inline styles, handlers or scripts, no foreign hosts) and the required built assets exist | After the build, before the upload | 0 passed · 1 findings · 2 cannot run |
| `genba-stamp` | The deployed commit is written to the stamp file that the page prints | After the checks, before the upload | 0 written · 1 write or check failed · 2 usage error or git not found |
| `genba-smoke` | The live site (or a preview) serves the new build, with the expected content, delivery and Panel state | After the upload | 0 PASSED or SKIPPED · 1 findings · 2 cannot run |
| `genba-links` | Every external reference (YouTube, Digistore24, purchase links) leads where the source says it does | Daily, in its own schedule workflow, not in the deploy | 0 ok, disruptions only, or none found · 1 findings or failed counter-check · 2 cannot run |

Exit code 0 also covers a visible skip: the last output line then starts with `Genba <tool>: SKIPPED`. Exit code 1 means the check ran and found something wrong. Exit code 2 means it could not run at all (usage error, a missing directory, tool or setting, a failed self-test). A tool never returns 0 for a check that did not happen.

## Requirements

- **Plugin part and four tools** (`genba-stamp`, `genba-preflight`, `genba-precheck`, `genba-links`): PHP 8.2 or newer, with the extensions `curl`, `json`, `mbstring` and `dom` (declared by the package). Without `mbstring` every tool says so and exits 2 instead of breaking off in the middle
- **`genba-smoke`: PHP 8.4 or newer** on the machine that runs it — normally the CI runner, not the web server. It reads the homepage with PHP's HTML5 parser `Dom\HTMLDocument`, which exists from PHP 8.4 on; below, it stops before the first request with `::error::The homepage reader cannot run: genba-smoke needs PHP 8.4 or later …`, exit 2. In a workflow: `shivammathur/setup-php` with `php-version: '8.4'` (see the [Quick start](#quick-start)). The site itself may run on 8.2
- Kirby 5, for the plugin part only. The tools run without Kirby, without a site and without network access
- bash 3.2 or newer, `curl` and `php`, for `genba-smoke`
- `git`, for the default commit hash (`git rev-parse HEAD`) in `genba-stamp` and `genba-smoke`
- npm only where the site's own build needs it

## Installation

```sh
composer require sayamaapps/kirby-genba
```

Composer installs the plugin into `site/plugins/genba/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`) and links the five tools into `vendor/bin/`. Add `/site/plugins/genba` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-genba" }]
```

Manual installation (download into `site/plugins/genba/`) gives the Kirby part only. The tools in `vendor/bin/` come with Composer.

## Quick start

1. Switch the Kirby part on in `site/config/config.php`:

   ```php
   return [
       'genba.active' => true,
       'genba.language' => 'de',   // optional: German texts for genba/build-info
   ];
   ```

   > **Language:** Genba's texts are English unless it learns otherwise. It follows `genba.language`, then the current language of a multi-language site, then its default language, then Kirby's `locale` option. **A German single-language site without a `locale` must set `'genba.language' => 'de'`**, or its build line reads in English.

2. Print the stamp in `<head>` of your layout:

   ```php
   <?php snippet('genba/stamp') ?>
   ```

   It prints `<meta name="build" content="<hash>">`, or nothing when there is no valid stamp file. Optionally show a visible line, for example in a preview footer: `<?php snippet('genba/build-info') ?>`.

3. Ignore the stamp file in `.gitignore`. Checked in, it would always hold an old hash:

   ```gitignore
   /site/config/build.txt
   ```

4. Put the checks into the deploy workflow. Every step is one `run:` line, and the site differences are values in `env:`. The preview example below is started by hand. The names `.github/scripts/deploy-ftps.sh`, `vars.*` and `secrets.*` are the site's own; use your own deploy script and variable names.

   ```yaml
   # .github/workflows/preview.yml
   name: Preview

   on:
     workflow_dispatch:

   concurrency:
     group: preview
     cancel-in-progress: false

   jobs:
     deploy:
       runs-on: ubuntu-latest
       environment: preview
       steps:
         - uses: actions/checkout@v4

         - uses: shivammathur/setup-php@v2
           with:
             php-version: '8.4'                # genba-smoke reads the homepage with Dom\HTMLDocument (PHP 8.4+)
             extensions: mbstring, curl, dom

         - name: Install Kirby and Genba
           run: composer install --no-dev --optimize-autoloader --no-interaction

         - uses: actions/setup-node@v4
           with:
             node-version: '22'
             cache: npm

         - name: Install npm packages
           run: npm ci

         - run: vendor/bin/genba-preflight
         - run: npm run build
         - run: vendor/bin/genba-precheck
         - run: vendor/bin/genba-stamp
         - run: for f in tests/*Test.php; do php "$f" || exit 1; done
         - run: .github/scripts/deploy-ftps.sh
           env:
             FTP_HOST: ${{ vars.FTP_HOST }}
             FTP_USER: ${{ secrets.FTP_USERNAME }}
             FTP_PASS: ${{ secrets.FTP_PASSWORD }}
         - run: vendor/bin/genba-smoke
           env:
             GENBA_URL: ${{ vars.PREVIEW_URL }}
             GENBA_USER: ${{ secrets.PREVIEW_USERNAME }}
             GENBA_PASSWORD: ${{ secrets.PREVIEW_PASSWORD }}
             GENBA_EXPECT_PROTECTED: 1        # without credentials the preview must answer 401
             GENBA_NOT_READY: skip            # DNS or certificate still pending: SKIPPED, not red
             GENBA_HTTPS_REDIRECT: warn
             GENBA_WITHOUT_CREDENTIALS: skip
             GENBA_CLOSED_PREVIEW: accept     # Sudare closed robots.txt + empty sitemap: a preview, not a finding
             GENBA_SITEMAP_ORIGIN: ${{ vars.LIVE_URL }}   # sitemap entries on production are fetched here, any other host is red
   ```

   `php-version: '8.4'` is for `genba-smoke`: the runner needs PHP 8.4, the web server does not. A site whose CI must stay on 8.2 or 8.3 for its own tests runs `setup-php` a second time with `'8.4'` right before the smoke step.

   That one smoke step replaces the preview workflow's own "reachability" step (`getent hosts`, `curl` without credentials must give 401, the `bereit` output) and the "secrets missing" shell block in front of the old smoke test. See [Reachability gate](#reachability-gate).

   The live deploy uses the same steps. It has no credentials, keeps the default `GENBA_HTTPS_REDIRECT=require`, has `GENBA_FOREIGN_SITEMAP=fail` (the default of a run without credentials and without `GENBA_EXPECT_PROTECTED=1`; set explicitly here, because on the live site a sitemap entry on another host means the site URL is wrong), and points `GENBA_URL` at the live site:

   ```yaml
   # .github/workflows/live.yml
   name: Live

   on:
     workflow_dispatch:

   concurrency:
     group: live
     cancel-in-progress: false

   jobs:
     deploy:
       runs-on: ubuntu-latest
       environment: live
       steps:
         - uses: actions/checkout@v4

         - uses: shivammathur/setup-php@v2
           with:
             php-version: '8.4'                # genba-smoke reads the homepage with Dom\HTMLDocument (PHP 8.4+)
             extensions: mbstring, curl, dom

         - name: Install Kirby and Genba
           run: composer install --no-dev --optimize-autoloader --no-interaction

         - uses: actions/setup-node@v4
           with:
             node-version: '22'
             cache: npm

         - name: Install npm packages
           run: npm ci

         - run: vendor/bin/genba-preflight
         - run: npm run build
         - run: vendor/bin/genba-precheck
         - run: vendor/bin/genba-stamp
         - run: for f in tests/*Test.php; do php "$f" || exit 1; done
         - run: .github/scripts/deploy-ftps.sh
           env:
             FTP_HOST: ${{ vars.FTP_HOST }}
             FTP_USER: ${{ secrets.FTP_USERNAME }}
             FTP_PASS: ${{ secrets.FTP_PASSWORD }}
         - run: vendor/bin/genba-smoke
           env:
             GENBA_URL: ${{ vars.LIVE_URL }}
             GENBA_FOREIGN_SITEMAP: fail
   ```

   The link check runs on its own schedule, not in the deploy, so a slow or unavailable YouTube does not block a release:

   ```yaml
   # .github/workflows/links.yml
   name: Links

   on:
     schedule:
       - cron: '17 5 * * *'   # daily 05:17 UTC, off the full hour
     workflow_dispatch:

   permissions:
     contents: read

   jobs:
     links:
       runs-on: ubuntu-latest
       timeout-minutes: 30
       steps:
         - uses: actions/checkout@v4

         - uses: shivammathur/setup-php@v2
           with:
             php-version: '8.4'
             extensions: curl, mbstring
             coverage: none

         - name: Install Kirby and Genba
           run: composer install --no-dev --no-interaction --no-progress

         - run: vendor/bin/genba-links
           env:
             GENBA_SHOP_HOSTS: ${{ vars.SHOP_HOSTS }}
   ```

The examples run on GitHub-hosted runners (`runs-on: ubuntu-latest`). Any runner with bash, curl and git works the same way, `setup-php` brings PHP 8.4; on a self-hosted runner, change `runs-on` to its labels.

## genba-stamp

Writes the commit hash of the checkout to the stamp file. The Kirby part reads that file and prints it.

| Setting | Default | Values |
|---|---|---|
| `--file=PATH`, `GENBA_STAMP_FILE` | `site/config/build.txt` | A path, relative to the working directory. An absolute path stays absolute. An empty argument (`--file=`) is a usage error, exit 2; an empty variable counts as unset. |
| `--sha=HASH`, `GENBA_SHA` | `git rev-parse HEAD` | A full 40-digit lower-case hash. An empty argument (`--sha=`) is a usage error, exit 2; an empty variable counts as unset. |
| `--show` | — | Prints the hash currently in the file and writes nothing |
| `--help` | — | Usage on stdout, exit 0 — unless another argument is a usage error: that wins, exit 2, whatever the order |

The default is `git rev-parse HEAD` of the checkout. `GITHUB_SHA` is deliberately not used: on a pull request it is a merge commit that nobody deploys.

The file is written to a temporary file in the same directory and renamed into place. A stamp file that is a symbolic link (for example a committed `site/config/build.txt -> ../somewhere`) is refused, and nothing is written through it.

**Exit codes:** 0 written (or `--show` found a hash) · 1 the value is not a full hash, git failed (the working directory is no git checkout), the file cannot be written, or the file does not contain the hash just written · 2 usage error (an unknown argument, an empty `--file=` or `--sha=`), or git cannot be started (`::error::git not found` on stdout), or `mbstring` is missing (`::error::PHP extension mbstring is missing …` on stderr, without the usage). A usage error prints its `::error::` reason and the usage on stderr; stdout ends with `Genba stamp: FAILED — cannot run`, like every exit 2 of every tool (see [No silent passes](#no-silent-passes)).

**Messages:**

```
Genba stamp: wrote <hash> to site/config/build.txt
Genba stamp: PASSED                                     (the last line of a successful run)
::error::Not a full commit hash: <value>
Genba stamp: FAILED — not a full commit hash            (exit 1)
::error::git rev-parse HEAD failed: <first line of git's error>   (or: git rev-parse HEAD exited with <n>, git rev-parse HEAD printed no full commit hash, Directory not found: <working directory>;
                                                                   git's line is log text: control characters become spaces, above 2048 characters it is cut to 60 with its length)
Genba stamp: FAILED — git rev-parse HEAD failed         (exit 1; also outside a git checkout)
::error::git not found                                   (exit 2: git cannot be started)
::error::PHP extension mbstring is missing[ (<function> is not available)]   (exit 2, stderr; also in genba-preflight, genba-precheck, genba-links)
::error::Cannot write <file>: <reason>            (directory does not exist · it is a symbolic link, refusing to write through it · write failed)
Genba stamp: FAILED — cannot write the stamp file       (exit 1)
::error::<file> does not contain the hash just written
Genba stamp: FAILED — the stamp file does not verify    (exit 1)
::error::No valid build stamp in <file>                 (--show, exit 1)
Genba stamp: FAILED — no valid build stamp in <file>    (--show, exit 1, the last line)
::error::Unknown argument: <argument>                   (stderr, exit 2)
::error::--sha needs a value                            (stderr, exit 2; the same for --file)
Genba stamp: FAILED — cannot run                        (stdout, the last line of every exit 2)
```

With `--show`, the hash alone is printed on stdout.

## genba-preflight

Runs the project's own generation step before the build. Genba runs it when it exists and says so when it does not, so the workflow line is the same for every site.

| Setting | Default | Values |
|---|---|---|
| `--hook=CMD`, `GENBA_PREFLIGHT_HOOK` | the `preflight` script of `package.json`, else its `praeflug` script | A shell command, run with `sh -c` in the project root. The literal `none` disables the hook, and the log names where it came from (`--hook=none` or `GENBA_PREFLIGHT_HOOK=none`). An empty argument (`--hook=`) is a usage error, exit 2; an empty variable counts as unset. |
| `--help` | — | Usage on stdout, exit 0 — unless another argument is a usage error: that wins, exit 2, whatever the order |

Without the setting, `package.json` is read: `scripts.preflight` becomes `npm run preflight`, otherwise `scripts.praeflug` becomes `npm run praeflug`. The kirby-starter ships `praeflug` as a no-op, so the starter's npm script keeps working unchanged. An unreadable `package.json`, or one whose `scripts` is not an object, is reported with a `::warning::` that names the file. A valid `package.json` without `scripts` is not: the `--` line already says that no script was found.

**Exit codes:** 0 passed, or SKIPPED because no hook is configured · 1 the hook failed · 2 usage error (an unknown argument, an empty `--hook=`), or `sh` cannot be started, or `mbstring` is missing (`::error::PHP extension mbstring is missing[ (<function> is not available)]` on stderr). A usage error prints its `::error::` reason and the usage on stderr; when `sh` cannot be started the reason is an `::error::` line on stdout. Either way stdout ends with `Genba preflight: FAILED — cannot run`.

**Messages:**

```
Genba preflight: running 'npm run praeflug' (from package.json)
  … output of the hook …
  ok   preflight hook finished
Genba preflight: PASSED
::error::Preflight hook failed with exit <n>
Genba preflight: FAILED — hook exit <n>                 (exit 1)
  --   no preflight hook configured (no GENBA_PREFLIGHT_HOOK, no package.json script "preflight" or "praeflug")
  --   no preflight hook configured (--hook=none)
  --   no preflight hook configured (GENBA_PREFLIGHT_HOOK=none)
Genba preflight: SKIPPED — nothing to run
::warning::Cannot read <cwd>/package.json (not valid JSON); no preflight hook taken from it
::warning::<cwd>/package.json: "scripts" is not an object; no preflight hook taken from it
::error::Unknown argument: <argument>                   (stderr, exit 2)
::error::--hook needs a value (GENBA_PREFLIGHT_HOOK=none switches the step off)   (stderr, exit 2)
::error::Cannot start the preflight hook (sh is not available)                    (stdout, exit 2)
Genba preflight: FAILED — cannot run                    (stdout, the last line of every exit 2)
```

## genba-precheck

Static checks of the built site under the Content Security Policy, plus a check that the built assets exist. Each rule has a self-test: a sample line it must match, run before the scan. A broken pattern therefore fails loudly instead of checking nothing.

| Setting | Default | Values |
|---|---|---|
| `--site=DIR` | `site` | Directory with the templates, snippets and plugins |
| `--public=DIR` | `public` | Document root with the built assets |
| `--allow=a,b`, `GENBA_ALLOW` | `places.googleapis.com/v1/` | Comma list, **added** to the default. The literal `none` clears the default too. An entry frees a match of the foreign-host rule in the same address, not the whole line. The address begins at the last `//` before the match (with its scheme) and ends at the first character no URL holds as it is — whitespace, quotes, `<`, `>`, parentheses, `` ` ``, `,`, `;`, `{`, `}`, `|`, `\` — or where the next `//` begins. A match outside any address is free only where an entry overlaps it. `--allow=gstatic.com` frees `https://fonts.gstatic.com/s/x.woff2`, but `https://places.googleapis.com/v1/x https://fonts.googleapis.com/css2` on one line is still a finding for the second address, and so is a minified ``a=`https://places.googleapis.com/v1/${id}`,b=`https://fonts.googleapis.com/css2` ``. |
| `--require=f1,f2`, `GENBA_REQUIRE_FILES` | `public/assets/css/tailwind.css` | Comma list of files that must exist and not be empty; it replaces the default. The literal `none` turns the file check off, and the log says so, naming where it came from (`--require=none` or `GENBA_REQUIRE_FILES=none`). Next to other entries, `none` drops the default and the other entries count, as with `--allow`: `--require=none,public/x.css` checks `public/x.css`, not a file called `none`. A list without a single file (`--require=,`) is a usage error, exit 2. |
| `--help` | — | Usage on stdout, exit 0 — unless another argument is a usage error: that wins, exit 2, whatever the order |

The default allow entry is the server-side endpoint of [Koe](https://github.com/simsimmal/kirby-koe) (`places.googleapis.com/v1/`), which runs on the server and never in a page. Add more with `--allow` or `GENBA_ALLOW`.

The rules, in this order:

| Rule | Scans | Finds | Self-test sample | Must not match |
|---|---|---|---|---|
| `inline-style` | `site/` | `style=` attributes in every spelling the browser accepts — `style="x"`, `style='x'`, `style = "x"`, `style=x` without quotes, also at the start of a line, right after the closing quote of the attribute before or after a `/` (the CSP forbids them) — and a `style` that Kirby's `attr()` helper may print (see below) | `<p style="x">`, and `<p class="a"style="color:red">`, `<p/style="color:red">`, `<div<?= attr(['class' => 'card', 'style' => '--accent: ' . $page->color()], ' ') ?>>` | `<?php $style = 'card'; ?>`, `<div data-style="x">`, `<div<?= attr(['style' => null], ' ') ?>>` |
| `foreign-host` | `site/`, `public/` | Hosts that bring back the consent requirement: googleapis, gstatic, jimcdn, instagram.com/embed, maps.google and protocol-relative `//fonts.` hosts, minus the allow list — judged per match: an allowed address next to a forbidden one on the same line does not hide it | `https://fonts.gstatic.com/x` | `document.fonts.ready.then(remeasure)` |
| `inline-handler` | `site/` | Any event handler attribute — `onclick`, `onsubmit`, `onmouseenter`, `onbegin`, …, also after a `/` or right after a closing quote (the CSP forbids them) — and one that Kirby's `attr()` helper may print (see below). Only names Genba knows as event handlers count (`Precheck::EVENT_HANDLERS`, see below); any other `on…` name (`once`, `online`) gets a `--` line | `<button onclick="x">`, and `<img src="x.png"/onerror="alert(1)">`, `<a href="#"onclick="x()">`, `<svg><animate onbegin="x()"/></svg>`, `<button class="b"<?= $extra ?>onclick="go()">`, `<button<?= attr(['type' => 'button', 'onclick' => 'toggle()'], ' ') ?>>` | `<i icon="x">`, `<x json="y">`, `<a href="?on=1">`, `var once = key + '\n' + detail;`, `public const ONLINE = 'online';`, `<div once="x">` |
| `inline-handler-runtime` | `site/` | An `on` directly followed by a PHP block, with or without the `=` — `<div on<?= $event ?>="go()">`: the handler's name is built at runtime, so Genba cannot tell whether it is an event handler (the CSP cannot tell either). Message: `inline event handler with a name built at runtime — the CSP forbids it` | `<div on<?= $event ?>="go()">`, `<p on<?php echo $e ?>>x</p>`, `<div class="a"on<?= $e ?>="x()">` | `<i one<?= $x ?>="y">`, `<a href="?on=1">`, `<p><?= $icon ?></p>` |
| `embedded-script` | `site/` | A `<script …>` tag that carries neither a `src` attribute nor `application/ld+json` — judged per tag, so an embedded script next to an external one on the same line, or one whose code says `el.src=`, is found; the tag is read attribute by attribute, so `data-src`, or `src=` and `application/ld+json` inside another attribute's value (`data-hint="loaded with src=/a.js"`), exempt nothing — only a `src` attribute with a value, or a `type` whose value holds `application/ld+json`. Kirby's `attr()` helper counts as well (`<script<?= attr(['src' => $url, 'nonce' => $kirby->nonce()], ' ') ?>>`, also `Html::attr()`): a PHP block where an attribute stands, whose whole expression is the `attr()` call and whose array literal has the key `src` with a value that is printed for sure, or `type` with the string `application/ld+json`, exempts the tag. A value that may be null (`$x ?: null`, `$x ?? null`, `$file?->url()`, a condition), an `attr()` in one branch of a condition or inside another call's value, a block inside another attribute's value, `attr($attributes)` and `'src' => null` exempt nothing. The tag ends at the first `>` outside a quoted value and outside a PHP block, so a Kirby nonce (`nonce="<?= $kirby->nonce() ?>"`) does not cut it short; a tag a formatter wrapped over several lines is read on to its `>` (up to 50 lines further), and a `<script` without one in reach is a finding | `<script>alert(1)</script>`, and `<script src="/a.js"></script><script>x()</script>`, `<script>el.src="/x.js"</script>`, `<script nonce="<?= $kirby->nonce() ?>">x()</script>` | `<script src="/x.js"></script>`, `<SCRIPT SRC="/x.js"></SCRIPT>`, `<script type="application/ld+json">{}</script>`, `<script nonce="<?= $kirby->nonce() ?>" src="/a.js"></script>`, `<script data-config='{"a":"b>c"}' src="/a.js"></script>` |

**Kirby's `attr()` helper prints attributes too.** A PHP block that calls `attr()` or `Html::attr()` is read with PHP's own tokenizer, in every form that prints a name: an array literal (`attr([...])`, `array(...)`, over several lines included), also as a named argument (`attr(attr: [...], before: ' ')`, `Html::attr(name: [...])`), and the single form `Html::attr('style', $css, ' ')` (also `name:`/`value:`), which prints nothing without a value. For the rules that forbid an attribute (`style`, `on…`) every such call counts, wherever it stands, and every key whose value is not certainly left out: Kirby prints nothing for `null`, `false` and `[]` (`array()` alike), a list item (`['ontoggle']`) prints its name, and `$css ?: null` may print it — a finding, reported on the line of the key. For the script rule's exemption only a `src` that is printed for sure counts (above), `Html::attr('src', $url, ' ')` included. `attr($attributes)`, `Html::attr($name, $value)` and a name built at run time (`'on' . 'click'`), whose names are unknown, are no finding and no exemption.

The self-test fails when a rule does not fire on one of its samples, and also when it fires on a line in the last column: a pattern that is too wide fails as loudly as one that is broken. The negative samples are judged with the whole rule, exceptions included. All four rules, the exceptions of the script rule (a `src` attribute, `application/ld+json`) and the allow list ignore case: `<P STYLE="x">` is the same CSP violation as `<p style="x">`, and `<SCRIPT SRC="…">` is a script with a source. The style and handler rules match an attribute, not a word: whitespace, a quote or a `/` (where the browser's HTML tokenizer ends the attribute before: `<p class="a"style="x">`, `<img src="x"/onerror="y">`), a PHP closing tag (`<p <?= $x ?>style="x">`), or the start of the line (for an attribute written on a line of its own) must precede `style` or `on…`, so a PHP variable `$style = 'card'`, a `data-style` attribute, `icon="x"` or `json="y"` are not findings. Neither rule asks for a quote after the `=`: `<p style=color:red>` is valid HTML5 and as much a finding as `<p style="color:red">`.

**An `on…` name counts when Genba knows it as an event handler.** The handler rule reads the name it matched and counts it when it is in `Precheck::EVENT_HANDLERS`: the event handler attributes of the HTML standard (on elements, `<body>` and the document), Pointer and Touch Events, CSS animations and transitions, Fullscreen, Selection, scroll snap, SVG animation (`onbegin`, `onend`, `onrepeat`) and the engine-specific, prefixed or legacy names of WebKit, Gecko and Blink (`onwebkitendfullscreen`, `ongesturestart`, `onbeforescriptexecute`, `onmousewheel`, …). Any other `on…` name is no finding but is said in a `--` line with file, line and the line as written — in a script it is usually a variable (`var once = key`), in a class a constant (`const ONLINE = 'online'`); should it be a handler a browser has added since, the line shows it: `  --   not counted: site/plugins/counter/views/event.js:41: 'once' is not an event handler Genba knows: var once = key + '\n' + detail;`. A line with such a name and a known handler (`<div once="x" onclick="go()">`) is a finding. A PHP closing tag right before the name ends an attribute name too, in both attribute rules: `<p <?= $x ?>style="x">` and `<button class="b"<?= $extra ?>onclick="go()">` are findings.

**PHP comments are not read.** In a `.php` or `.phtml` file the rules read the code without its comments (`//`, `#`, block and doc comments), blanked with PHP's own tokenizer, so every line keeps its number; the finding shows the line as written. A comment is never printed, so a doc comment that explains the rules (`* The <script> tag for app.js …`) is no finding. The tokenizer knows where PHP code starts and ends: `echo '/* <p style="x"> */';` is a string that is printed, and stays a finding, and so does an HTML comment (`<!-- <p style="x"> -->`), which is sent to the browser. A file with a bare `<?` (not `<?php`, not `<?=`) is read with its comments: whether `<?` opens PHP depends on `short_open_tag`, and the PHP that runs the check need not be the server's — where it is off, `<? // a > <script>…` is text, and the script runs.

Skipped by place, not by name: `site/cache`, `site/sessions`, `site/accounts`, `site/logs` and `public/media` only directly under their root (generated or server-side state); `node_modules` and `.git` at any depth. A snippet folder `site/snippets/media/` is site code and is scanned. Symbolic links to directories are followed when their target is inside the project; a link outside the project, back to a directory already scanned, or broken is not followed, and neither is a link into a skipped place (`site/snippets/c -> ../cache`) or a skipped place reached through a link (`public/p -> ..` would reach `site/cache`): the places are compared by real path. A symbolic link to a **file** follows the same rules: outside the project or into a skipped place it is named and its content is never read (`symbolic link to a file outside the project`). A file reached twice, through a link and through its real place, is read once per run — also across the two roots. An entry of a directory that can be listed but not searched (read, no execute permission) is named as `cannot be read (permission denied)`; a directory that cannot be listed at all ends the run with exit 2 (`::error::Cannot read directory: <dir>`, relative to the working directory). Genba's own directory (`site/plugins/genba/` in a site) is skipped too: its source and its README contain the rule samples. Every directory not entered gets a `--` line with the reason. Binary files (a NUL byte in the first 8 KiB) are skipped. **Markdown files under `site/plugins/`** (`.md`, `.markdown`, any case, at any depth) are not read: they are the documentation of the installed packages, and a README that documents CSP rules (`<script>`, `style=`) would fail every site that installs the package. One `--` line counts them (`  --   not checked: 21 Markdown files in site/plugins (package documentation)`). A file counts only when its real path is Markdown and lies in the real `site/plugins/` too: `site/plugins/x/a.md -> ../../templates/default.php` is read, and so is site Markdown reached through a link below `plugins/` (`site/plugins/docs -> ../snippets`). Markdown anywhere else is read like any other file — a template may print it (`kirbytext(F::read(…))`, with raw HTML in it).

**Everything from the site is log text.** The found line, the file name, a directory name in a `--` line and the name in `Cannot read directory` come from the site's own files. Control characters in them (line breaks, escape sequences, C1 characters) become spaces, so a template line `<p style="x">` followed by an escape sequence and a carriage return cannot paint `Genba precheck: PASSED` over the finding, and a file name cannot start a line that GitHub would read as an annotation. A name above 2048 characters is cut to its first 60 with its length; the rest of the line — line number, message, reason — stays. The same filter as in `genba-links` (`Genba\Text::clean()`).

**Exit codes:** 0 passed · 1 findings, or a required file missing or empty · 2 cannot run: a self-test failed, a directory does not exist, no files were found, `mbstring` is missing (`::error::PHP extension mbstring is missing[ (<function> is not available)]` on stderr), or a usage error. A run that cannot start because `--require`/`GENBA_REQUIRE_FILES` names no file prints the reason and `Genba precheck: FAILED — cannot run` on stdout, the usage on stderr. A usage error (an unknown argument, an empty `--site=`, `--public=`, `--allow=` or `--require=`) prints its `::error::` reason and the usage on stderr; stdout ends with the same last line — as does every other exit 2, the failed self-test included: the reason is the `::error::` line before it.

**Messages:**

```
  ok   self-test: 5 rules fire on their samples and stay silent on their negative samples
::error::site/templates/a.php:1: inline style — the CSP forbids it: <p style="x">
::error::site/snippets/b.php:1: inline event handler — the CSP forbids it: <button onclick="x">
::error::site/snippets/c.php:3: embedded <script> — the CSP forbids it: <script>alert(1)</script>
::error::site/snippets/d.php:1: foreign host — brings back the consent requirement: <link href="https://fonts.googleapis.com/css">
::error::Self-test failed: rule <name> did not fire on its sample — pattern broken
::error::Self-test failed: rule <name> fired on its negative sample '<line>' — pattern broken
::error::Self-test failed: rule <name> did not fire on its positive sample '<line>' — pattern broken
::error::Self-test failed: rule foreign-host did not fire on its positive sample '<line>' with the default allow list — pattern broken
::error::Self-test failed: rule <name>: pattern does not compile — pattern broken
::error::Self-test failed: rule <name> has no pattern or sample — pattern broken
::error::Self-test failed: rule <name>: positives is not a list — pattern broken
::error::Self-test failed: rule <name>: negatives is not a list — pattern broken
::error::Self-test failed: rule <name>: names is not a list of lower-case names — pattern broken
::error::Self-test failed: rule <name>: names needs a pattern with a (?<name>…) group and no each — pattern broken
Genba precheck: FAILED — cannot run                      (after a failed self-test, exit 2)
  --   not checked: site/plugins/genba (Genba's own package, its rule samples would match every rule)
  --   not checked: site/cache (Kirby cache)
  --   not checked: site/templates/outside (symbolic link to a directory outside the project)
  --   not checked: site/templates/leak.php (symbolic link to a file outside the project)
  --   not checked: site/snippets/c (symbolic link into site/cache)
  --   not checked: public/p/site/cache (inside site/cache, reached through a symbolic link)
  --   not checked: site/templates/rx/a.php (cannot be read (permission denied))
  --   not checked: site/templates/loop (symbolic link to a directory already scanned)
  --   not checked: site/templates/broken (broken symbolic link)
  --   not checked: <n> Markdown files in site/plugins (package documentation)
  --   not counted: site/x.js:3: 'once' is not an event handler Genba knows: var once = key;
  --   not checked: <dir> (cannot be resolved)
::error::Cannot read directory: <dir>
::error::Cannot read file: <file>
::error::--require lists no file: ',' (use none to switch the file check off)
::error::GENBA_REQUIRE_FILES lists no file: ' , ' (use none to switch the file check off)
::error::Unknown argument: <argument>                    (stderr, exit 2)
::error::--site needs a value                            (stderr, exit 2; the same for --public, --allow, --require)
Genba precheck: FAILED — cannot run
::error::Directory not found: <dir>
::error::No files found under <roots>
  ok   public/assets/css/tailwind.css (12 bytes)
::error::Required file missing or empty: public/assets/css/tailwind.css
  --   no required files configured (GENBA_REQUIRE_FILES=none)
  --   no required files configured (--require=none)
Genba precheck: PASSED (<n> files scanned)
Genba precheck: FAILED — <n> findings, <m> required files missing
```

## genba-smoke

A bash script with `curl` that checks the live site or a preview after the upload. A status code alone proves little: after a half upload, on the hoster's default page, or with an old build, the server also answers 200. So the content is measured.

curl fetches, PHP reads: bash and curl make every request, and the saved homepage is read by `bin/genba-smoke-read` (an internal helper, not linked into `vendor/bin/`) with PHP's HTML5 parser `Dom\HTMLDocument` — **PHP 8.4 or newer on the runner**. See [Reading the homepage](#reading-the-homepage).

| Setting | Default | Values |
|---|---|---|
| URL (first positional argument) or `GENBA_URL` | — (required) | `https://…`, the base address of the site. An empty argument (`vendor/bin/genba-smoke ""`, a workflow variable that is not set) is a usage error, exit 2 — it never falls back to `GENBA_URL` without a word, the same rule as an empty `--option=` in the other tools. |
| `GENBA_EXPECTED_BUILD` | `GENBA_SHA` (the same variable `genba-stamp` reads), else `git rev-parse HEAD` in the working directory | 7–64 hex characters, normally the full commit hash. A shorter value is compared as the prefix of the live hash (`0123456` passes for `0123456789ab…`). Whitespace around the value, a trailing newline included, is dropped — in both variables. |
| `GENBA_META` | `build` | Name of the `<meta>` element. Only `[A-Za-z0-9_-]+`, the same rule as `genba.stamp.meta`; anything else is a usage error, exit 2. |
| `GENBA_MARKER` | `assets/css/tailwind.css` | A text the homepage must contain, anywhere in the HTML, comments included: `<!-- example -->` counts. Empty turns the check off. |
| `GENBA_USER`, `GENBA_PASSWORD` | — | Basic Auth for a protected host. Both or neither, only with an `https://` URL, and without a line break in either value (a secret set from a file keeps the file's line breaks): that is a usage error, exit 2, the value not echoed. See [Staging behind Basic Auth](#staging-behind-basic-auth). |
| `GENBA_WITHOUT_CREDENTIALS` | `fail` | `fail` or `skip`. `skip` ends a run as SKIPPED only while it has no finding; a finding before the 401 (the reachability gate got no answer) makes it FAILED, exit 1. |
| `GENBA_EXPECT_PROTECTED` | `0` | `1`: the first request, **without** credentials, must answer 401 (a protected preview); anything else is a finding. See [Reachability gate](#reachability-gate). |
| `GENBA_NOT_READY` | `fail` | `fail` or `skip`: a host whose name does not resolve yet, or whose https connection cannot be set up while http answers (certificate not issued yet). `skip` ends the run as SKIPPED, exit 0. Decided once, on the first request; a timeout or an expired certificate is never "not ready". |
| `GENBA_HTTPS_REDIRECT` | `require` | `require`, `warn` or `off`: whether `http://<host>/` must redirect to `https://<host>` |
| `GENBA_PANEL_ACCOUNT` | `require` | `require`, `warn` or `off`: whether a Panel account must exist |
| `GENBA_FOREIGN_SITEMAP` | `rewrite` on a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set), else `fail` | `rewrite`, `warn` or `fail`: what a sitemap entry on another origin is. `rewrite` fetches the same path and query on the `GENBA_URL` origin (staging listing production), `warn` prints a warning and fetches nothing, `fail` is a finding (the live site). An explicit value wins; unset (or empty), the log names the default taken: `  --   GENBA_FOREIGN_SITEMAP not set: rewrite (a preview run: …)` or `… not set: fail (not a preview run: …)`. |
| `GENBA_CLOSED_PREVIEW` | `accept` on a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set), else `fail` | `accept` or `fail`: a closed preview — `robots.txt` whose only rule is `Disallow: /` (comments and blank lines aside, a `Sitemap:` line optional) together with an empty sitemap `<urlset>`, as the Sudare package serves it on purpose — is accepted, with one `--` line. `fail` makes it two findings. Disallow with pages in the sitemap is a finding under either value, and so is an empty sitemap with an open `robots.txt`. Any other value is a usage error (exit 2). See [Closed preview](#closed-preview). |
| `GENBA_SITEMAP_ORIGIN` | — | `https://host[:port]`, no path (a trailing `/` is dropped): the one foreign origin the sitemap may list, normally production. Its entries follow `GENBA_FOREIGN_SITEMAP`; an entry on any third origin is always a finding. Anything else than an origin is a usage error, exit 2. |
| `GENBA_PRIVATE_PATHS` | the list below | Paths above `public/` that must not answer 2xx, comma- or space-separated, **added** to the default list. The entry `none` drops the default (`none,secret/x.txt` replaces it); `none` alone turns the check off, and the log says so. An entry with `..` or characters outside `A-Za-z0-9._~/-` is a usage error, exit 2. |
| `GENBA_HEADERS` | unset (not checked) | Response headers, one rule per line: `[/path] Name: rule`. The path defaults to `/`. Rules: a value (exact), `*` (present), `none` (absent), `~text` (contains), `!~text` (does not contain). The name is compared without case; several lines for one name all apply. A line without `Name: rule`, a path with characters outside `A-Za-z0-9._~/-`, or an empty rule is a usage error, exit 2, read before the first request. See check 9. |
| `GENBA_PAGES_MAX` | `30` | A whole number above 0: how many sitemap pages are fetched. When the sitemap lists more, the ok line says so (`<n> of <m> sitemap pages fetched (GENBA_PAGES_MAX=<n>), the rest not checked`). Anything else is a usage error, exit 2. |
| `GENBA_RETRIES` | `3` | A whole number above 0: how often the homepage is retried while a cache still holds the old build. A 401 is not retried. Anything else is a usage error, exit 2. |
| `GENBA_PAUSE` | `5` | A whole number of seconds between the retries (`0` allowed). Anything else is a usage error, exit 2. |
| `GENBA_HTTP_URL` | `http://<host>/` | Only for the tests of the tool itself |
| `GENBA_MAX_TIME` | `25` | Integer: seconds one request may take, and the reader of the homepage (above, the run cannot run: exit 2). Only for the tests of the tool itself |
| `--help` | — | Usage on stdout, exit 0 — unless another argument is a usage error: that wins, exit 2, whatever the order |

The checks, in this order:

| # | Check | Settings |
|---|---|---|
| 0 | Optional reachability gate: without credentials the homepage answers 401; a name that does not resolve yet, or a pending certificate, is SKIPPED with `GENBA_NOT_READY=skip` | `GENBA_EXPECT_PROTECTED`, `GENBA_NOT_READY` |
| 1 | The homepage is 200, carries `<meta name="build" content="<expected build>">` and the marker text. The `<meta>` is read by the HTML5 parser, so any spelling a browser reads the same counts — capitals, values without quotes, `content` before `name` — and a minifier that rewrites the page does not read as a missing stamp. The marker is looked for in the HTML as sent, comments and scripts included. A homepage larger than 10 MiB is a finding and is not read (the reader holds about 40 times the page in memory), and is not fetched again — also when it comes without `Content-Length` and curl (below 8.4) does not stop at the limit: the size on disk counts too | `GENBA_EXPECTED_BUILD`, `GENBA_META`, `GENBA_MARKER`, `GENBA_RETRIES`, `GENBA_PAUSE` |
| 2 | An unknown address returns 404 | — |
| 3 | `robots.txt`, `sitemap.xml` and `llms.txt` are 200 for GET and HEAD, with the right Content-Type and content (in a [closed preview](#closed-preview) the `robots.txt` pattern is not asked for, the closed pair is accepted instead). Each file is fetched once per method; the body of that one GET is what the content pattern and check 4 read, so a later answer that differs cannot slip through unread. A sitemap larger than 10 MiB is a finding and is not read, whether curl stopped at the limit or an older curl read it to its end. | — |
| 4 | Every sitemap page is 200. A `<loc>` on another origin is rewritten to the `GENBA_URL` origin and fetched there (the default on a preview run), or is a warning or a finding (the default otherwise); with `GENBA_SITEMAP_ORIGIN` only that origin may appear. An empty sitemap is a finding, except in a closed preview (`GENBA_CLOSED_PREVIEW`, then one `--` line and no page fetched), and so is a sitemap that could not be read (`Sitemap not available for page check`). The sitemap is read as one line, so a pretty-printed entry with the address on its own line and an address wrapped in CDATA are read like any other; a comment (`<!-- <url>…</url> -->`) is dropped first, a commented-out entry is no entry; the number of `<url>` elements is held against the addresses read, and a difference is a finding (`Sitemap: <n> entries could not be parsed`), never a silent drop. The XML entities `&amp;`, `&lt;`, `&gt;`, `&quot;` and `&apos;` and the numeric character references of `& < > " ' # ? =` (`&#38;`, `&#x26;`, …) in a `<loc>` are decoded before the fetch, each exactly once (`&amp;#38;` becomes the text `&#38;`, not `&`). A reference that is still there after that — a numeric one of any other character (`&#233;`, `&#47;`; Kirby's `Xml::encode()` writes every non-ASCII character so) or a named one (`&eacute;`) — is a finding and the entry is not fetched: curl would read the `#` of such a reference as the start of the fragment and request a cut address (`/blog&#47;neu` as `/blog&`). Control characters are percent-encoded, as the URL parser encodes them (`\x01` is `%01`; leading and trailing ones are stripped); an entry longer than 2048 characters is a finding and is not fetched. An entry that is empty after that (`<loc></loc>`, whitespace or control characters only) is a finding, and so is an entry that is not an absolute URL (`/kontakt`), in every mode: the sitemap protocol requires scheme and host. When no page at all was fetched, the log says why: a `::warning::` when every entry was on a foreign host in `warn` mode, otherwise an `::error::` because every entry is a finding already. Origins are compared the way a browser does: the case of scheme and host and a default port (`:80`, `:443`) do not count. | `GENBA_PAGES_MAX`, `GENBA_FOREIGN_SITEMAP`, `GENBA_SITEMAP_ORIGIN` |
| 5 | The stylesheets are 200 with `text/css` and are not empty. Only on a homepage that was read: one that answered no 200 or is larger than 10 MiB is said in one `--` line with the reason, never as "no .webp file found". Up to five `.webp`, `.avif` and `.woff2` files of the own origin, from the homepage and the stylesheets, have the right Content-Type; when there are more than five of a type, the log says how many and that the first five were checked. From the homepage: the `href` of every `<link>` whose `rel` holds `stylesheet` or `preload`, every `src` and every candidate of every `srcset`, as the browser reads them (see [Reading the homepage](#reading-the-homepage)); a `url()` in a stylesheet is CSS and is taken as written, its control characters percent-encoded and bytes that are no UTF-8 made U+FFFD as in every address. The kind of a file is read from its address without regard to case (`/x.CSS` is a stylesheet). A `data:` URI is no address. Files on other origins are not requested, and when only those exist the log says so — except, on a rewrite run with `GENBA_SITEMAP_ORIGIN`, the files on that origin: they are checked on the `GENBA_URL` origin, as the sitemap's entries are (see [Staging behind Basic Auth](#staging-behind-basic-auth)). An address longer than 2048 characters is a finding and is not fetched. **Safety net:** when the marker is a `.css` address (the default, `assets/css/tailwind.css`) and stands in the page, a stylesheet of the own origin with that address must be among those read from the `<link>` elements — otherwise that is a finding (`Homepage contains the stylesheet address …`), never a quiet `--   no stylesheet` and PASSED. A site that loads its stylesheet by a script or from another origin sets `GENBA_MARKER` to a text that is no `.css` address; a staging host whose site URL is production sets `GENBA_SITEMAP_ORIGIN` instead. | `GENBA_MARKER`, `GENBA_SITEMAP_ORIGIN` |
| 7 | `/api/system` reports `"isInstalled":true` and `"isInstallable":false`. An installable Panel is always a finding. | `GENBA_PANEL_ACCOUNT` |
| 7b | `/panel` answers 200, and every JS and CSS file it names under `/media/panel` or `/media/plugins` answers 200. A Panel without its files is an empty page for the editors. Off with `GENBA_PANEL_ACCOUNT=off`. | `GENBA_PANEL_ACCOUNT` |
| 8 | Nothing above `public/` is reachable: none of the private paths answers 2xx (an executed `config.php` answers 200 with an empty body, so any 2xx counts) or fails to answer. Sent with the credentials, so a protected preview is checked behind its protection. | `GENBA_PRIVATE_PATHS` |
| 9 | Response headers from `GENBA_HEADERS`: one request per rule, on the rule's own path. Not set: a visible `--` line. | `GENBA_HEADERS` |
| 6 | `http://<host>/` redirects with 301 or 308 to `https://<host>`. No credentials are sent. | `GENBA_HTTPS_REDIRECT`, `GENBA_HTTP_URL` |

The default private paths, from a live site's deploy workflow and extended: `.env`, `.git/HEAD`, `composer.json`, `composer.lock`, `site/`, `site/config/config.php`, `site/config/credentials.php`, `site/config/kizami.php`, `site/config/kennzahlen.php`, `content/`, `content/site.txt`, `kirby/bootstrap.php`, `vendor/`, `vendor/autoload.php`, `storage/`, `storage/kizami/kizami.sqlite`, `storage/kennzahlen/kennzahlen.sqlite`.

Credentials are sent only to the origin of `GENBA_URL`, never followed to another origin, and never over http (see [Staging behind Basic Auth](#staging-behind-basic-auth)). `curl` never follows redirects for the content checks, ignores `~/.curlrc` (`-q`), reads no URL as a range (`-g`, so `/x[1-4].webp` stays one request) and uses no proxy from the environment (`--noproxy '*'`: `http_proxy`, `HTTPS_PROXY` and `ALL_PROXY` are ignored, the site is reached directly — as `genba-links` does; a runner that can only reach the site through a proxy sees that as findings, never as a silent detour). Behind a proxy the proxy would resolve the name, and a DNS entry still pending could never be told from a broken site.

**A transfer curl could not finish is not an answer.** The status code is read only when curl ends with exit 0: a connection that closes with bytes missing after a 200 (curl 18 `transfer closed with N bytes remaining to read`), a body that never arrives after the headers (28), a broken transfer (56) is `HTTP 000`, the cut body is not read, and the finding carries curl's message — `GET /sitemap.xml: HTTP 000 instead of 200 — curl: (18) transfer closed with 3926 bytes remaining to read`, in every check alike (homepage, unknown address, the three files, sitemap pages, stylesheets, assets, `/api/system`, private paths). A sitemap cut short is therefore `Sitemap not available for page check`, never "complete" with fewer pages, and a homepage cut short never serves the build. The one exception is curl 63 (a homepage or a sitemap above 10 MiB), which has its own finding. A body that arrived is read as text whatever it holds: a NUL byte in a page does not turn the build meta, the marker or the stylesheets "missing" (every `grep` on a body runs with `-a`; GNU grep would print nothing for a "binary" file, BSD grep `Binary file … matches`).

A closed preview (see [Closed preview](#closed-preview)) is one `--` line when it is accepted. Under `Disallow: /` with pages in the sitemap, or with `GENBA_CLOSED_PREVIEW=fail`, the findings read:

```
  --   closed preview: robots.txt Disallow: / and an empty sitemap, so no sitemap pages were fetched (GENBA_CLOSED_PREVIEW=accept)
::error::robots.txt disallows all crawling (Disallow: /) but the sitemap lists pages — the live site would be closed by mistake (GENBA_CLOSED_PREVIEW=accept accepts Disallow: / only with an empty sitemap)
::error::/robots.txt does not contain what it should (pattern: ^Sitemap: )
::error::Sitemap lists no pages
```

### Reading the homepage

The homepage is read by `Dom\HTMLDocument` (PHP 8.4, the Lexbor parser), which follows the tokenizer and the tree construction of the HTML specification — not by awk and grep in bash: a reader built of those keeps meeting spellings it gets wrong where the browser does not. What that means for the checks:

- Comments, `<script>` (with its `<!--` escape states), `<style>`, the text of `<title>` and `<textarea>`, `<xmp>`, `<iframe>`, `<noembed>`, `<noframes>` and `<plaintext>` are what they are for the browser: `<title>Why <!-- …</title>` is a title, not a comment that hides the stylesheet behind it. Attributes in end tags (`</div title="<!--">`) and bogus comments (`<!x …>`, `<?php … ?>` in the output, `<![CDATA[ … ]]>` outside SVG) are read as the browser reads them.
- The content of `<template>` is inert and is not read — unless it is a declarative shadow root (`<template shadowrootmode="open">` or `"closed"`, the first one on an element that can carry a shadow root: `div`, `span`, `p`, `section`, a custom element — not `font-face`, `missing-glyph` and the other names the HTML specification reserves …): the browser renders that content and loads what it names, so it is read too. A `<base>` or the build `<meta>` inside it does not count; it is not in the document.
- **`<noscript>` is read as markup**, as a browser without script reads it (the parser runs with scripting off): a stylesheet or an image inside is checked too. A browser with script loads none of it; checking it as well is the louder direction.
- Only elements in the HTML namespace count: a `<link>` inside `<svg>` or `<math>` is no stylesheet, an `<img>` that breaks out of `<svg>` or stands in `<foreignObject>` is an image.
- Character references are decoded, every one of them (`&#233;` is é, `&#47;` is /), and the address is requested as decoded.
- `rel` is a list of tokens, without regard to case (`rel=" Stylesheet preload"`). Leading and trailing whitespace and control characters are stripped from an address, line breaks and tabs inside are dropped, and every other control character is percent-encoded (the URL parser does so: `/a\x01b.css` is requested as `/a%01b.css`, as by the browser, not as `/ab.css`). Only `srcset` is split at its commas, by the rules of the specification (a candidate with invalid descriptors is dropped): `src="data:,x.webp"` is one address.
- **`<base href>`** is honoured as the browser honours it: the relative addresses after it are resolved against it, and a `--` line says so; an address before it is resolved against the page's own address (the browser fetched it before the base was there). A `data:` or `javascript:` base is ignored, as the browser ignores it, with a `--` line. Any other `<base>` that is no http(s) address (`mailto:`, `about:`) is a finding, and no address after it is checked.
- The page is read as UTF-8, whatever it declares; a byte that is no UTF-8 becomes U+FFFD. A stylesheet is read as UTF-8 too, whatever it declares (Kirby writes UTF-8) — as the browser does without a charset parameter; a stylesheet sent as `text/css; charset=windows-1252` with such a byte in a `url()` is requested differently by the browser, and a 404 there can be false.

**Everything the site sends is log text.** A sitemap address, an `href`, `src` or `srcset`, a `url()` in a stylesheet, a `Content-Type` header, a redirect target, the build hash in the `<meta>` and the text of a curl error pass through one filter before they reach a log line: control characters (C0 including line breaks, DEL, and the C1 range U+0080–U+009F, also as a raw byte 0x80–0x9F outside a UTF-8 character, such as 0x9B, the 8-bit CSI) are dropped — in an address they are percent-encoded instead, as the browser requests it (`%1B`, `%C2%85`), and bytes that are no UTF-8 are U+FFFD (`%EF%BF%BD`, one per broken sequence), as the browser decodes a stylesheet without a charset parameter —, so an escape sequence or a `Genba smoke: PASSED.` behind a carriage return cannot rewrite the log or forge an annotation, and a value above 2048 characters is cut to its first 60 with its length. An address above 2048 characters is a finding and is not fetched (`Sitemap entry`, `Stylesheet address`, `Asset address … longer than 2048 characters`).

**Exit codes:** 0 PASSED, or SKIPPED (see `GENBA_WITHOUT_CREDENTIALS` and `GENBA_NOT_READY`; the last line then starts with `Genba smoke: SKIPPED`, after a `::warning::` that names the reason — and never after a finding: a run that already printed an `::error::` line is FAILED, whatever else is missing) · 1 findings · 2 cannot run: a missing or invalid setting, a line break in `GENBA_USER`/`GENBA_PASSWORD`, `curl` not installed or unable to read its credentials file (curl exit 26), `php` not installed or below 8.4, `mbstring` missing, a homepage the reader could not read or did not read within `GENBA_MAX_TIME` seconds, or no expected build and no git checkout or no git — the last line is then `Genba smoke: FAILED — cannot run`.

**Messages:**

```
Genba smoke against <url>, expected build <hash>
  ok   Protected: <url>/ answers 401 without credentials
::error::Without credentials <url>/ returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
::error::Without credentials <url>/ gives no answer (HTTP 000) — <first line of curl's error> — the preview protection is not verified
::error::<host> does not resolve (DNS entry missing?) — a preview whose DNS entry is still pending sets GENBA_NOT_READY=skip
::warning::Genba smoke: SKIPPED — <host> does not resolve yet (DNS entry pending). Nothing was checked; run again once the name resolves.
Genba smoke: SKIPPED — <host> does not resolve yet
::warning::Genba smoke: SKIPPED — https to <host> fails, but <http url> answers HTTP <code>: TLS certificate not issued yet? Nothing was checked.
Genba smoke: SKIPPED — https to <host> fails (certificate pending?)
::error::https to <host> fails, and without credentials <http url> returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
  ok   Homepage serves build <hash>
::error::Homepage: HTTP <code> instead of 200[ — <curl's message>]
::error::Homepage larger than 10485760 bytes — not read: its build, its marker and its addresses are not checked
  ok   Marker 'assets/css/tailwind.css' found
  --   marker not checked (GENBA_MARKER empty)
  ok   Unknown address returns 404
::error::Unknown address returns HTTP <code> instead of 404[ — <curl's message>]
  ok   GET /robots.txt: 200, text/plain
::error::<GET|HEAD> /<file>: HTTP <code> instead of 200[ — <curl's message>]
::error::<GET|HEAD> /<file>: Content-Type '<type>', expected <a or b>
::error::/<file> does not contain what it should (pattern: <pattern>)
::notice::Sitemap lists <n> pages on <foreign origins> instead of <origin> — rewritten to <origin> (path and query kept) and fetched there (GENBA_FOREIGN_SITEMAP=rewrite)
::notice::The homepage and its stylesheets name <n> addresses on <GENBA_SITEMAP_ORIGIN> instead of <origin> — rewritten to <origin> (path and query kept) and checked there (GENBA_FOREIGN_SITEMAP=rewrite, GENBA_SITEMAP_ORIGIN)
::error::Sitemap page <url>: HTTP <code> instead of 200[ (listed as <loc>)][ — <curl's message>]
::error::Sitemap entry is not an absolute URL: <loc> — the sitemap protocol requires scheme and host
  ok   <n> sitemap pages fetched
  ok   <n> of <m> sitemap pages fetched (GENBA_PAGES_MAX=<n>), the rest not checked
::warning::No sitemap page fetched: the entries point to a foreign host — the pages of <url> are NOT checked
::error::No sitemap page fetched: every entry is a finding (see above) — the pages of <url> are NOT checked
::error::Sitemap entry is empty (<loc> without an address)
  ok   Stylesheet: 200, text/css
::error::Stylesheet <url>: HTTP <code>, '<type>', <bytes> bytes — expected 200, text/css, not empty[ — <curl's message>]
::error::<asset>: HTTP <code> instead of 200[ — <curl's message>]
::error::<asset>: Content-Type '<type>', expected <type>
  ok   <asset>: <type>
  --   no stylesheet of <origin> found on the homepage, stylesheets not checked
::error::Homepage contains the stylesheet address '<marker>' (GENBA_MARKER), but no stylesheet of <origin> with that address was read from its <link> elements — the stylesheet is not checked (loaded by a script, from another origin, or read wrongly?)
  --   <base href="<href>"> on the homepage: the addresses after it are resolved against <url>
  --   <base href="<href>"> on the homepage is ignored, as the browser ignores a data: or javascript: base
::error::Homepage <base href="<href>"> is no http(s) address — the addresses after it are not checked
  --   stylesheets, images and fonts not checked: the homepage is larger than 10485760 bytes and was not read
  --   stylesheets, images and fonts not checked: the homepage gave no answer (HTTP 000)
  --   stylesheets, images and fonts not checked: the homepage answered HTTP <code>, not 200
  --   no .webp file found on the homepage, Content-Type not checked
  --   no .webp file of <origin> found on the homepage (<n> on other origins, not requested), Content-Type not checked
  --   <n> .webp files of <origin> on the homepage, the first 5 checked (the server configuration is tested, not every file)
  ok   Panel set up (account present, installer locked)
::error::/api/system: HTTP <code> without isInstalled/isInstallable — is this Kirby's Panel API?[ — <curl's message>]
  --   Panel not checked (GENBA_PANEL_ACCOUNT=off)
  ok   <n> private paths not reachable (none answers 2xx)
::error::Private path /<path> answers HTTP <code> — reachable from the web (document root not on public/?)
::error::Private path /<path>: no answer (HTTP 000)[ — <curl's message>] — not verified
  --   private paths not checked (GENBA_PRIVATE_PATHS=none)
  --   private paths not checked: the homepage gave no answer (HTTP 000)
  --   private paths not checked: the homepage answered HTTP <code>, not 200
  --   http→https not checked (GENBA_HTTPS_REDIRECT=off)
  --   http→https not checked: URL is not https
  ok   http redirects with <code> to <target>
::error::Homepage: HTTP 401 instead of 200 (protected: set GENBA_USER and GENBA_PASSWORD)
::error::Homepage without <meta name="build"> — stamp file not deployed or snippet('genba/stamp') missing in <head>
::error::Live build is <live>, expected <expected> — the upload did not (fully) arrive
::error::Homepage does not contain the marker '<marker>' — is this really our site?
::error::Sitemap points to a foreign host: <loc> (site URL misconfigured?)
::error::Sitemap points to a foreign host: <loc> (neither <origin> nor GENBA_SITEMAP_ORIGIN <origin>)
  --   GENBA_FOREIGN_SITEMAP not set: rewrite (a preview run: GENBA_EXPECT_PROTECTED=1 or GENBA_USER set)
  --   GENBA_FOREIGN_SITEMAP not set: fail (not a preview run: neither GENBA_EXPECT_PROTECTED=1 nor GENBA_USER)
::error::Sitemap lists no pages
::error::GET /sitemap.xml: larger than 10485760 bytes — not read
::error::Sitemap not available for page check
::error::Sitemap entry longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Sitemap entry with a character reference that is not decoded: <loc> — not fetched
::error::Sitemap: <n> entries could not be parsed (<u> <url> entries, <l> <loc> addresses)
::error::Stylesheet address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Asset address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::http→https missing: <http url> returns '<code>' with target '<target>', expected 301/308 to https://<host>[ — <curl's message>]
::error::Panel can be installed from outside (panel.install on?) — any visitor could become admin
::error::Panel without account: /panel shows the installer. Create the account on the server first
  ok   Panel: /panel answers 200, its <n> files arrive
::error::/panel names no JS or CSS under /media/panel — an empty Panel page?
::error::Panel file <path>: HTTP <code> instead of 200[ — <curl's message>]
  --   response headers not checked (GENBA_HEADERS not set)
  ok   homepage <name>: exactly '<value>' (HTTP 200)
::error::homepage <name>: sent '<value>', expected exactly '<value>' (HTTP 200)
::error::homepage <name>: not sent, expected present (HTTP 200)
::warning::Panel without account: /panel shows the installer. Create the account on the server first   (GENBA_PANEL_ACCOUNT=warn)
::warning::Genba smoke: SKIPPED — <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set. Whether the new build arrived is NOT verified.
Genba smoke: SKIPPED — build not verified, see the warning above.
  --   <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set — not SKIPPED: the run has findings already
Genba smoke: FAILED — see findings above.
Genba smoke: PASSED.
```

Validation errors, all exit 2, each followed by the last line `Genba smoke: FAILED — cannot run`:

```
::error::GENBA_URL missing (base address of the site)
::error::Unknown argument: <argument>                              (usage on stderr)
::error::Only one URL argument is allowed
::error::URL argument is empty (set GENBA_URL or pass the address)
::error::GENBA_EXPECTED_BUILD is not a commit hash: <value>      (or GENBA_SHA, or git rev-parse HEAD — whichever gave the value;
                                                                  git rev-parse HEAD in a repository without a commit prints HEAD)
::error::GENBA_META must match [A-Za-z0-9_-]+ (the name of the <meta> element): <value>
::error::GENBA_URL must start with http:// or https://
::error::GENBA_URL must not contain credentials (user:password@host) — use GENBA_USER and GENBA_PASSWORD
::error::GENBA_USER/GENBA_PASSWORD only over https: GENBA_URL is not https:// (plain http only to 127.0.0.1, localhost or [::1])
::error::GENBA_HTTPS_REDIRECT must be require, warn or off
::error::GENBA_PANEL_ACCOUNT must be require, warn or off
::error::GENBA_FOREIGN_SITEMAP must be rewrite, warn or fail
::error::GENBA_SITEMAP_ORIGIN must be an origin without path (https://host or https://host:port): <value>
::error::GENBA_WITHOUT_CREDENTIALS must be fail or skip
::error::GENBA_EXPECT_PROTECTED must be 0 or 1
::error::GENBA_NOT_READY must be fail or skip
::error::GENBA_PRIVATE_PATHS: not a path below the site root: <entry>
::error::GENBA_HEADERS: path '<path>' must be / followed by letters, digits, . _ ~ / -
::error::GENBA_HEADERS: a line needs 'Name: rule' (got: <line>)
::error::GENBA_HEADERS: '<name>' is not a header name
::error::GENBA_HEADERS: the rule for <name> is empty
::error::GENBA_PAGES_MAX must be a whole number above 0: <value>
::error::GENBA_RETRIES must be a whole number above 0: <value>
::error::GENBA_PAUSE must be a whole number of seconds: <value>
::error::GENBA_MAX_TIME must be a whole number of seconds above 0: <value>
::error::GENBA_USER and GENBA_PASSWORD must be set together
::error::GENBA_USER/GENBA_PASSWORD must not contain a line break (a secret set from a file keeps the file's line breaks)
::error::curl cannot read its credentials file (curl exit 26) — GENBA_USER/GENBA_PASSWORD were not sent; check them for characters curl's -K file cannot hold
::error::GENBA_EXPECTED_BUILD not set and the working directory is not a git checkout
::error::GENBA_EXPECTED_BUILD not set and git not found
::error::curl not found
::error::php not found (genba-smoke reads the homepage with PHP: bin/genba-smoke-read)
::error::The homepage reader cannot run: genba-smoke needs PHP 8.4 or later on the machine that runs it (it reads the homepage with Dom\HTMLDocument), this is PHP <version> — usually the CI runner, not the web server: setup-php with php-version: '8.4'
::error::The homepage reader cannot run: PHP extension mbstring is missing[ (<function> is not available)]
::error::The homepage reader cannot run: PHP extension dom is missing (genba-smoke reads the homepage with Dom\HTMLDocument)
::error::The homepage cannot be read: <reason>                    (the reader failed on the saved page: genba-smoke-read: cannot read the saved homepage, or a PHP error)
::error::The homepage cannot be read: the reader did not finish within <n> seconds (GENBA_MAX_TIME)
```

The reader's own usage errors (`genba-smoke-read: unknown argument`, `genba-smoke-read: --meta=NAME and one file are required`) can only appear when `bin/genba-smoke-read` is called by hand.

With `GENBA_HTTPS_REDIRECT=warn` or `GENBA_FOREIGN_SITEMAP=warn`, the same finding is printed as `::warning::` and the run does not fail on it.

## genba-links

Checks where each external reference leads, not whether it answers 200. Five of nine product pages of one shop once carried a wrong Digistore ID, and four of them answered 200.

It reads the files under `content/`, `site/` and `public/` (the existing ones of these by default; a default path that does not exist gets a `--` line), collects the references, and asks the source:

| Kind | Source | Finds |
|---|---|---|
| `youtube` | YouTube oEmbed | Deleted, private or non-embeddable videos, and a title that is missing |
| `youtube-embed` | YouTube oEmbed | The same, for embedded players |
| `digistore` | the Digistore24 product page | A product that does not exist, has no active product, or whose page title does not match the name in the site |
| `purchase` | the shop host, following the redirect chain | A chain that ends in an error, a loop, or a lost `aff=` or `cam=` parameter |

Disruptions (timeouts, 5xx, 429) are reported as warnings and do not turn the run red. A flaky network must not produce a red run that nobody reads.

**What is not read is named.** `vendor/`, `kirby/`, `storage/`, `site/cache`, `site/sessions`, `site/accounts`, `site/logs` and `public/media` are skipped by place (relative to the working directory), `node_modules` and `.git` at any depth; a page folder `content/media/` is content and is read. Symbolic links to directories are followed when their target is inside the project, but never into a skipped place (compared by real path: `site/snippets/c -> ../cache` is `symbolic link into site/cache`). A symbolic link to a file follows the same rules (`content/leak.txt -> /elsewhere/secret.txt` is `symbolic link to a file outside the project`, named and never read). A file reached twice — through `public/p -> ..` and through its real place — is read once per run. An entry that cannot be read for lack of search permission is named (`cannot be read (permission denied)`); a directory that cannot be listed or a file that cannot be read ends the run with exit 2 (`::error::Cannot read directory: <dir>`, `::error::Cannot read file: <file>`, both relative to the working directory), because its links would go unchecked. Genba's own package is never read: installed from source (a path repository), its `tests/` would add sample links that are not the site's. Each directory not read gets a `--   not checked: <dir> (<reason>)` line; the reasons besides the places: `symbolic link into <place>`, `inside <place>, reached through a symbolic link`, `symbolic link to a directory outside the project`, `symbolic link to a file outside the project`, `symbolic link to a directory already scanned`, `broken symbolic link`, `cannot be resolved`, `cannot be read (permission denied)`, and for the package itself `Genba's own package: its tests and docs carry sample links`.

**Redirects are followed by hand, and never into the runner's own network.** A purchase link or a Digistore page may redirect; where it leads is decided by the vendor. Every address is first rebuilt from validated parts. Raw non-ASCII bytes in the path (`/produkt/kräuter` in a `Location` header) are percent-encoded, as a browser does. An address with whitespace, control characters, a backslash, `user@` information, a non-http(s) scheme, a host that is not a plain ASCII DNS name, or a numeric host in a form other than a dotted quad (`2130706433`, `0x7f.1`, `127.1`) is refused, because two parsers could read it differently. Before each hop to another host, Genba resolves the host once, checks every A and AAAA address, and refuses loopback, private (10/8, 172.16/12, 192.168/16), link-local (169.254/16, fe80::/10), CGNAT (100.64/10), benchmark (198.18/15), unique-local (fc00::/7), the deprecated site-local fec0::/10, SIIT (`::ffff:0:a.b.c.d`), unspecified, reserved, broadcast and multicast addresses, IPv4 inside IPv6 (`::ffff:127.0.0.1`, 6to4, NAT64) by its IPv4 part, and the names `localhost`, `*.local`, `*.lan` and `*.internal`. A refused hop is a finding (`redirect to <host> refused (…) — not followed`), and the target is never contacted. The first address of a purchase link from the content is checked the same way (`address <host> refused (…) — not requested`), unless its host is exactly one of the `--shop-host` entries: a link in the content can point into the network as easily as a redirect. The oEmbed and Digistore endpoints come from the options and are trusted. The checked address is pinned for the request and compared with the address curl connected to, so a second DNS answer cannot swap it. No proxy from the environment (`http_proxy`, `HTTPS_PROXY`, `ALL_PROXY`) is used. At most 10 redirects are followed.

**External text is printed on one line.** Page titles, oEmbed titles and redirect targets come from other servers; the URLs, places and directory names come from the site's own files. Control characters in any of them (line breaks, escape sequences, C1 characters) become spaces, and invalid UTF-8 is scrubbed, so neither a title nor a link in a content file can start a line that GitHub would read as an annotation. A value above 2048 characters (a URL, a title, a place, a lost parameter) is cut to its first 60 with its length, `… (<n> characters)`, as in `genba-smoke` — each value on its own, never the line: the rest of the line (the chain, the place) stays. The filter is `Genba\Text::clean()`, shared with `genba-precheck`; the name in `Cannot read directory` goes through it too.

**Counter-check.** Before the scan, the tool proves that it can tell what exists from what does not: a deleted video and a missing product must be recognised as findings. If it cannot, the run fails with `::error::Counter-check`, because the result would be worthless. Skip it with `--skip-counter-check` only if you know why.

| Setting | Default | Values |
|---|---|---|
| `--digistore-field=a,b`, `GENBA_DIGISTORE_FIELDS` | `digistoreid` | Comma list of content fields that hold a Digistore product ID. An empty or blank variable counts as unset (GitHub Actions turns an unset `${{ vars.X }}` into an empty string), so the default stays. An option with an empty value (`--digistore-field=`) is a usage error, exit 2. The same holds for the next two settings. |
| `--youtube-field=a,b`, `GENBA_YOUTUBE_FIELDS` | `videoid,youtubeid,youtubevideos` | Comma list of content fields that hold YouTube IDs. A field may mix bare IDs and URLs; both are checked |
| `--shop-host=h1,h2`, `GENBA_SHOP_HOSTS` | none | Comma list of shop hosts whose links are purchase links (subdomains included). A link whose host is exactly one of them is requested even when it resolves to a private address |
| `--skip-counter-check` | off | Skips the proof described above, with a `--` line |
| `--list-only` | off | Lists the references and does not contact anything; the last line is `Genba links: <n> references listed` |
| `--oembed-base=URL` | the YouTube oEmbed endpoint | Used by the tests |
| `--digistore-base=URL` | the Digistore24 product endpoint | Used by the tests |
| `--pause=SECONDS` | `0.3` | Pause between requests |
| `--help` | — | Usage on stdout, exit 0 — unless another argument is a usage error: that wins, exit 2, whatever the order |
| paths | `content`, `site`, `public` under the working directory | Directories or files to scan |

The user agent sent with every request is `Mozilla/5.0 (compatible; Genba link check; +https://github.com/simsimmal/kirby-genba)`.

**Exit codes:** 0 no findings (disruptions and "no references found" included) · 1 findings, or the counter-check failed (last line `Genba links: FAILED — counter-check failed, no reference was checked`) · 2 cannot run: none of the paths exist, a directory or file under them cannot be read, the PHP extension `curl` or `mbstring` is missing, or a usage error (`::error::Unknown argument: <argument>`, an empty `--digistore-field=`, an empty path argument `""` — `::error::Path argument is empty (omit it for the default paths)`: an unset `${{ vars.X }}` must not read the whole working directory). The reason for an exit 2 is on stderr; stdout ends with `Genba links: FAILED — cannot run`.

**Messages:**

```
<n> external references found, <m> distinct targets.
  <kind>  <url>  (<places>)                                  (--list-only)
Genba links: <n> references listed                          (--list-only, the last line)
  ok   <url>: <text> — in <places>
::error::<url>: <text> — in <places>
::error::<url>: Purchase link: redirect to <host> refused (private or local address <address>) — not followed (<chain>) — in <places>
::error::<url>: Purchase link: address <host> refused (private or local address <address>) — not requested (<chain>) — in <places>
::warning::Disruption, not assessed: <url>: <text> — in <places>
::notice::<url>: <text> — in <places>
::error::Counter-check: <failure>
::error::Cannot read directory: <dir>          (exit 2, stderr)
::error::Cannot read file: <file>              (exit 2, stderr)
::error::Path argument is empty (omit it for the default paths)   (exit 2, stderr, with the usage)
::error::None of the paths exist: <paths>       (exit 2, stderr)
::error::PHP extension curl is missing          (exit 2, stderr)
::error::PHP extension mbstring is missing[ (<function> is not available)]   (exit 2, stderr)
::error::Unknown argument: <argument>           (exit 2, stderr, with the usage)
::error::--<option> needs a value               (exit 2, stderr, with the usage: --digistore-field=, --youtube-field=, --shop-host=, --oembed-base=, --digistore-base=, --pause=)
::error::--pause must be a number of seconds, not: <value>   (exit 2, stderr)
::warning::Path not found, skipped: <path>     (a path given as an argument that does not exist; the others are read)
Genba links: FAILED — cannot run               (exit 2, the last line on stdout)
The link check cannot currently tell what exists from what does not. The result would be worthless.
Genba links: FAILED — counter-check failed, no reference was checked
Counter-check passed: a deleted video and a missing product are recognised.
  --   counter-check skipped (--skip-counter-check)
  --   not checked: <dir> (<reason>)
  --   not checked: public (default path, does not exist)
::notice::No external references found under <paths>
Genba links: <ok> ok, <findings> findings, <disruptions> disruptions, <notices> notices.
```

The `<text>` of a result line, per kind:

```
youtube:    "<title>"
            Video <id> does not exist (oEmbed <code>)
            Video <id> is private (oEmbed 403)
            Video <id> cannot be embedded (oEmbed 401)          (a notice for a link, a finding for an embedded player)
            Video <id>: URL refused (<reason>)
            oEmbed answers 200 without a title
            YouTube unreachable: no response (<curl error>) | HTTP <code>
digistore:  "<name>"
            Digistore product <id> does not exist (HTTP <code>)
            Digistore product <id>: no response (<curl error>) | HTTP <code>
            Digistore product <id>: <refusal>
            Digistore product <id> shows no active product (page title "<title>")
            Digistore product <id> is called "<name>", but the page is "<title>" / "<title>" — wrong ID?
purchase:   <n> redirect(s), target <final>
            Parameters lost on the way: aff=<value>, cam=<value> (<chain joined by ' → '>)
            Purchase link ends with HTTP <code> (<chain>)
            Purchase link: no response (<curl error>) | HTTP <code> (<chain>)
            Purchase link: <refusal> (<chain>)
```

A `<refusal>` is `URL refused (<reason>)` for a first address that cannot be read as a plain http(s) address — an oEmbed or Digistore request, and a purchase link too (`Purchase link: URL refused (user information in the address) (https://user@shop.example/x)`) —, `address <host> refused (…) — not requested` for the first address of a purchase link whose host is local or private, `redirect to <host> refused (…) — not followed` for a hop (`redirect to localhost refused (local host name) — not followed`), or `connected to <ip> instead of the checked address <ip> for <host> — result discarded`. The reasons: `whitespace, control character, backslash or non-ASCII in the address`, `not an absolute http(s) address`, `scheme <scheme>`, `user information in the address`, `address cannot be parsed unambiguously`, `invalid IPv6 address`, `numeric host in a non-canonical form`, `host name is not a plain DNS name`, `local host name`, `private or local address <address>`. A `<curl error>` is curl's own message, or `Could not resolve host: <host>` when Genba's lookup before the request finds no address.

The counter-check names what it could not tell apart: `Existing video <id> not recognised: <text>`, `Missing video <id> not recognised as a finding: <status>, <text>`, `Missing Digistore product <id> not recognised as a finding: <status>, <text>`.

## Staging behind Basic Auth

A preview or staging host is often protected with HTTP Basic Auth. The smoke test can check it, as long as the credentials are in the step's `env:` block, taken from the repository's secrets:

```yaml
- run: vendor/bin/genba-smoke
  env:
    GENBA_URL: ${{ vars.PREVIEW_URL }}
    GENBA_USER: ${{ secrets.PREVIEW_USERNAME }}
    GENBA_PASSWORD: ${{ secrets.PREVIEW_PASSWORD }}
    GENBA_EXPECT_PROTECTED: 1
    GENBA_NOT_READY: skip
    GENBA_HTTPS_REDIRECT: warn
    GENBA_WITHOUT_CREDENTIALS: skip
    GENBA_CLOSED_PREVIEW: accept     # Sudare closed robots.txt + empty sitemap: a preview, not a finding
```

**How the credentials travel.** `genba-smoke` writes them into a `curl` configuration file (`-K`), created with `umask 077`, so only its owner can read it. Nothing reaches `curl` as a command-line argument, so they do not appear in the process list or in the log. They are sent only to the origin of `GENBA_URL`, never over plain http, and never to another origin. The output never prints them. `curl` starts with `-q`, so a `~/.curlrc` on the runner (for example with a trace setting) cannot write the `Authorization` header anywhere.

**https only.** With `GENBA_USER`/`GENBA_PASSWORD` set, a `GENBA_URL` that does not start with `https://` ends the run before the first request (exit 2). The only exception is the machine itself: `127.0.0.1`, `localhost` and `[::1]`, where nothing leaves the host (the package's own tests use it). Credentials written into the URL (`https://user:password@host`) are refused too, without echoing the URL.

**No line break in a secret.** A secret set from a file (`gh secret set NAME < file`) keeps the file's line breaks. A line break inside `GENBA_USER` or `GENBA_PASSWORD` would end the value in curl's configuration file early (curl exit 26, every request HTTP 000) or travel inside the password. Both are refused before the first request (`GENBA_USER/GENBA_PASSWORD must not contain a line break …`, exit 2), the value never echoed. Should curl still fail to read the file, the run ends the same way (`curl cannot read its credentials file (curl exit 26) …`) instead of a cascade of HTTP 000 findings.

**`GENBA_WITHOUT_CREDENTIALS`: fail or skip.** The default is `fail`: a homepage that answers 401 without credentials is a finding, and the run is red. The reason is a forgotten secret. With `skip` the same run would end green without ever seeing the new build. So `skip` is set on purpose, in the preview's `env:` block only, and it is visible in the log:

```
::warning::Genba smoke: SKIPPED — <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set. Whether the new build arrived is NOT verified.
Genba smoke: SKIPPED — build not verified, see the warning above.
```

`skip` never hides a finding: when the run already has one (the reachability gate got no answer, then the homepage answered 401), it ends `FAILED — see findings above`, exit 1, with a `--` line that the secrets are missing. Use `skip` only where an unverified preview deploy is acceptable. Never use it for the live site.

**`GENBA_HTTPS_REDIRECT=warn`.** Preview subdomains do not always redirect http to https. The live site keeps `require`. A missing redirect is then a warning on the preview and a finding on the live site.

**`GENBA_FOREIGN_SITEMAP=rewrite` (the default on a preview run).** A run with `GENBA_USER` or `GENBA_EXPECT_PROTECTED=1` is a preview run; there, unset, the mode is `rewrite`, and everywhere else `fail`. A staging host can have its site URL set to production, so its sitemap lists the production host. Genba then fetches the same path and query on the staging origin (`https://prod.example/kontakt?x=1` → `<GENBA_URL origin>/kontakt?x=1`), so staging is still checked page by page, and says so once:

```
::notice::Sitemap lists 2 pages on https://prod.example instead of https://staging.example — rewritten to https://staging.example (path and query kept) and fetched there (GENBA_FOREIGN_SITEMAP=rewrite)
```

The credentials go to the staging origin only, as for every other request; the production host is never contacted. A broken staging page is a finding (`Sitemap page <url>: HTTP 500 instead of 200 (listed as <loc>)`). `warn` keeps the older behaviour (a warning per entry, nothing fetched; when every entry is foreign, `::warning::No sitemap page fetched …` instead of an `ok` line). **The live site has `fail`** (its default, and set explicitly in the example above): there a sitemap entry on another host means the site URL is wrong.

**`GENBA_SITEMAP_ORIGIN`** narrows the rewrite to the one origin that is expected: with `GENBA_SITEMAP_ORIGIN: ${{ vars.LIVE_URL }}`, an entry on production is rewritten, and an entry on any third host (an old domain, a typo) is a finding instead of being fetched on staging under the wrong name:

```
::error::Sitemap points to a foreign host: https://alte-domain.example/ (neither https://staging.example nor GENBA_SITEMAP_ORIGIN https://prod.example)
```

Such a staging host writes its stylesheet and images with the production origin too (`css()` and `url()` follow the site URL: `<link href="https://prod.example/assets/css/tailwind.css">`). On a rewrite run with `GENBA_SITEMAP_ORIGIN`, the addresses of check 5 on that origin are checked on staging the same way, and the log says so once:

```
::notice::The homepage and its stylesheets name 2 addresses on https://prod.example instead of https://staging.example — rewritten to https://staging.example (path and query kept) and checked there (GENBA_FOREIGN_SITEMAP=rewrite, GENBA_SITEMAP_ORIGIN)
```

Without `GENBA_SITEMAP_ORIGIN` nothing of check 5 is rewritten (a CDN would be requested on staging under the wrong name), and the safety net reports the stylesheet that was not checked (`Homepage contains the stylesheet address …`): set `GENBA_SITEMAP_ORIGIN: ${{ vars.LIVE_URL }}` on such a preview.

## Closed preview

The Sudare package protects a preview with a closed site on purpose: `robots.txt` is `User-agent: *` with `Disallow: /` and no `Sitemap:` line, and `sitemap.xml` is an empty `<urlset>`, so search engines keep out of the preview. On a preview run that pair is the expected state, not a finding. The default is `GENBA_CLOSED_PREVIEW=accept` on a preview run (`GENBA_EXPECT_PROTECTED=1` or `GENBA_USER` set) and `fail` everywhere else:

```
  --   closed preview: robots.txt Disallow: / and an empty sitemap, so no sitemap pages were fetched (GENBA_CLOSED_PREVIEW=accept)
```

Only the combination counts. The rule is checked as a whole:

- `Disallow: /` **and** a sitemap with pages is a finding under either value: `::error::robots.txt disallows all crawling (Disallow: /) but the sitemap lists pages — the live site would be closed by mistake (…)`. The pages are checked as usual.
- An empty sitemap with an open `robots.txt` stays a finding: `::error::Sitemap lists no pages`.
- A `robots.txt` with any other rule (an `Allow`, a `Disallow` of one path, another `User-agent`) is not the closed one, whatever the sitemap says.
- `robots.txt` and `llms.txt` must still answer 200 with `text/plain`; `sitemap.xml` with 200 and `<urlset>`. Only the content of the closed pair is accepted.

With `GENBA_CLOSED_PREVIEW=fail` a closed preview is two findings, as before: `::error::/robots.txt does not contain what it should (pattern: ^Sitemap: )` and `::error::Sitemap lists no pages`.

## Reachability gate

A preview's workflow used to carry its own step in front of the smoke test: is the DNS entry there yet (`getent hosts`), does the preview answer **401 without credentials** (else it is open to everyone), and, if https fails, does http answer (the certificate is not issued yet)? Only then did the smoke test run. Genba does this itself, so the workflow keeps one line per step:

```yaml
- run: vendor/bin/genba-smoke
  env:
    GENBA_URL: ${{ vars.PREVIEW_URL }}
    GENBA_USER: ${{ secrets.PREVIEW_USERNAME }}
    GENBA_PASSWORD: ${{ secrets.PREVIEW_PASSWORD }}
    GENBA_EXPECT_PROTECTED: 1
    GENBA_NOT_READY: skip
```

- **`GENBA_EXPECT_PROTECTED=1`.** The first request goes out without credentials and must answer 401: `  ok   Protected: <url>/ answers 401 without credentials`. Any other answer is a finding (`… returns HTTP 200 instead of 401 — the preview protection does not work, the site may be open to everyone`). The protection is proven even when the secrets are missing and `GENBA_WITHOUT_CREDENTIALS=skip` skips the build check.
- **`GENBA_NOT_READY=skip`.** A name that does not resolve (curl exit 6) ends the run with `::warning::Genba smoke: SKIPPED — <host> does not resolve yet …` and the last line `Genba smoke: SKIPPED — <host> does not resolve yet`, exit 0. An https connection that cannot be set up while `http://<host>/` answers (with `GENBA_EXPECT_PROTECTED=1`: answers 401) ends it as `Genba smoke: SKIPPED — https to <host> fails (certificate pending?)`, exit 0. Neither is ever `PASSED`. With `GENBA_EXPECT_PROTECTED=1` and an http answer other than 401, the run is a finding.
- **"Not ready" is decided once, on the first request** — the gate's, without credentials, when `GENBA_EXPECT_PROTECTED=1`; else the first homepage request. A request after that which fails is a finding, never a skip: a preview that answers 401 at once and then hangs behind the protection is broken, not pending. And only a connection that cannot be set up counts (curl exit 7, 35, 51, 58, 59, 60, 77, 83, 90, 91: refused, TLS handshake, certificate not valid for the name). A timeout (28), an empty answer (52) or a broken transfer (56) means the server is there and fails; an **expired** certificate was issued once and nothing is pending. All three are findings.
- **Default `fail`.** A name that does not resolve is a finding (`<host> does not resolve (DNS entry missing?)`), exit 1, and the run stops there. The live site keeps the default.

A skipped run is green on purpose (the upload went through, there is nothing to measure yet), and the warning puts the reason into the run's summary. Run the workflow again once DNS and certificate are there.

## Options

The Kirby part reads these options from `site/config/config.php`. Each option takes the flat spelling (`'genba.active' => true`) or the nested one (`'genba' => ['active' => true]`). `null` counts as "not set".

| Option | Default | Meaning |
|---|---|---|
| `genba.active` | `false` | Master switch. Only a real `true` switches the snippets and site methods on. The tools do not read it. |
| `genba.stamp.file` | `'site/config/build.txt'` | The stamp file, absolute or relative to the project root (Kirby's `base` root, otherwise the directory above `site/`). An empty value or one that is not a string is reported and the default is used. |
| `genba.stamp.meta` | `'build'` | The `name` attribute of the `<meta>` element. Only `[A-Za-z0-9_-]+` is accepted. Otherwise it is reported and the default is used. `genba-smoke` reads `build` by default, so change both ends together. |
| `genba.language` | the site's language, else `en` | `en` or `de` for `genba/build-info`. See the note in the [quick start](#quick-start). |

A broken value is logged once per process. With Kirby's `debug` option on, it throws an exception instead, so the error shows while you develop.

## Your own markup

The snippets print unstyled markup with `genba-*` classes only. For your own markup, use the site methods and the helper:

```php
$build = $site->genbaBuild();       // '0123…4567' or null
$info  = $site->genbaBuildInfo();   // array or null
```

Both return `null` when `genba.active` is not `true`, when the stamp file is missing, or when it does not hold a valid hash. Nothing unchecked reaches the HTML. `genbaBuildInfo()` returns:

```php
[
    'hash'      => '0123456789abcdef0123456789abcdef01234567',  // 40 hex characters
    'short'     => '0123456',                                   // first 7 characters
    'file'      => 'site/config/build.txt',                     // relative to the project root
    'writtenAt' => '2026-10-11T03:12:45+00:00',                 // file time, UTC, or null
]
```

`file` is relative to the project root, so a page that prints it never shows the absolute server path. A stamp file outside the project root (an absolute `genba.stamp.file`) gives its file name only.

`writtenAt` is the modification time of the stamp file in ISO 8601 (UTC). It is `null` when that time cannot be read; Genba never makes up a date.

The `snippet('genba/build-info')` snippet takes two optional variables: `$label` (default `true`, prints the word "Build" or its German equivalent) and `$withDate` (default `false`, adds the deploy date). Its classes are `genba-build`, `genba-build-label`, `genba-build-hash`, `genba-build-date` and `genba-build--none` (no stamp).

To replace a snippet, create the same file in your site: `site/snippets/genba/stamp.php` or `site/snippets/genba/build-info.php`. Kirby looks in the site first, so the site's version wins.

`genba_option('stamp.file', 'site/config/build.txt')` reads any `genba.*` option in a snippet of your own, with a default.

The texts are registered as Kirby translations under `genba.build.label`, `genba.build.hash`, `genba.build.writtenAt`, `genba.build.none`, `genba.panel.build.label` and `genba.panel.build.info`.

## Switching from the starter copies

Before 1.0.0 these operations were copies in every site built from kirby-starter. Genba replaces them. The table lists each old form and its new form. Identifiers of the old copies are quoted in the left column on purpose.

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

**Steps for a site.**

1. Run `composer require sayamaapps/kirby-genba`, and add `/site/plugins/genba` to `.gitignore`.
2. In `site/config/config.php`, set `'genba.active' => true`, and add `/site/config/build.txt` to `.gitignore`.
3. In the layout, replace `snippet('stand')` with `snippet('genba/stamp')`.
4. In the preview and live workflows, replace these inline steps and the copied scripts with the lines of the [quick start](#quick-start):
   - the preflight step (`npm run praeflug`) with `vendor/bin/genba-preflight`;
   - the stylesheet check (the `test -s` step) with `vendor/bin/genba-precheck`, which checks it after the build;
   - the stamp step (`git rev-parse HEAD > site/config/build.txt`) with `vendor/bin/genba-stamp`;
   - the pre-deploy check step (`bash scripts/deploy-vorpruefung.sh`) with `vendor/bin/genba-precheck`;
   - the smoke-test step with `vendor/bin/genba-smoke` and its `env:` block;
   - in the preview workflow, the "Erreichbarkeit prüfen" step (`getent hosts`, 401 without credentials, `bereit` output) and the `if:` on the smoke step, with `GENBA_EXPECT_PROTECTED: 1` and `GENBA_NOT_READY: skip` in the smoke step's `env:`;
   - in a live workflow with an inline "nothing above `public/`" loop, that loop: the smoke test checks the private paths by default (`GENBA_PRIVATE_PATHS` adds site-specific ones).
5. Replace the starter's schedule workflow for the link check with the one of the quick start, which runs `vendor/bin/genba-links`.
6. In `tests.yml`, remove the step that runs `bash tests/smoke-test-gegenprobe.sh`. The PHP test loop can stay.
7. Delete these files from the site. Genba carries their logic and their tests:
   - `site/snippets/stand.php`
   - `scripts/smoke-test.sh`
   - `scripts/deploy-vorpruefung.sh`
   - `scripts/pruefe-fremdlinks.php`
   - `tests/StandTest.php`
   - `tests/smoke-test-gegenprobe.sh`
   - `tests/FremdlinksTest.php`
8. Keep the `praeflug` script in `package.json`. `genba-preflight` runs it.
9. Run the workflow once by hand, and read its log. Every check must show `ok`, `--` or a visible `SKIPPED` line, and nothing unexpected.

The site's own deploy script, its credential checks and its other checks stay where they are. See [Boundaries](#boundaries).

## Boundaries

Genba checks one deployed site from the inside, per deploy. Some checks belong elsewhere:

- The weekly fleet check outside this repository (a separate tool) looks at all sites from the outside, once a week. It reads `<meta name="build">`, which Genba keeps unchanged. Genba does not replace it.
- Site-specific checks stay in their site, for example a daily health check of a shop's live page.
- Not in Genba: the site's deploy script, the credential checks of the site's configuration (`pruefe-zugangsdaten.php`), and backups. The reachability gate of a preview (401 without credentials, DNS pending) and the check that nothing above `public/` is served are in `genba-smoke` (`GENBA_EXPECT_PROTECTED`, `GENBA_NOT_READY`, `GENBA_PRIVATE_PATHS`).

## No silent passes

A check that cannot run says so: it exits 2, or it prints a visible `SKIPPED` line. Nothing is ever reported `ok` or `PASSED` because a value was missing. An absent value is `null`, never `0` or an empty string.

A check that is deliberately not run prints a `  --   ` line with the reason in the text (`not checked: …`). When a list the check depends on is empty (no sitemap pages, no references found), the tool says so with `::error::` or `::notice::`, never with a bare "0 ok".

A missing PHP extension is "cannot run", never a crash in the middle: without `mbstring` every PHP tool says `PHP extension mbstring is missing` and exits 2 before it reads anything. `genba-smoke` says before its first request when its PHP is below 8.4. And a reader error that loses the stylesheet's `<link>` does not end in a quiet PASSED: when the marker names the stylesheet and stands in the page, the stylesheet must have been read (genba-smoke, check 5).

Every run ends with the tool's line, exit 2 included: a usage error (an unknown argument, an empty `--option=`) prints its `::error::` reason and the usage on stderr, and `Genba <tool>: FAILED — cannot run` as the last line on stdout — in all five tools alike, so a log that shows stdout alone still says that nothing was checked.

The output lines of every tool:

| Prefix | Use |
|---|---|
| `::error::<text>` | a finding (exit 1) or a reason why the tool cannot run (exit 2) |
| `::warning::<text>` | something not assessed but worth seeing, or a finding in warn mode |
| `::notice::<text>` | information |
| `  ok   <text>` | a check that passed |
| `  --   <text>` | a check deliberately not run, with the reason |
| last line | `Genba <tool>: PASSED`, `FAILED — …` or `SKIPPED — …` |

GitHub shows the `::` lines as annotations.

## Privacy

Genba sets no cookies, uses no browser storage and loads nothing from a third party in a visitor's browser. The Kirby part prints a commit hash of the site's own code. The tools run in CI, not in a visitor's browser, and they process no visitor data. The smoke test fetches the site's own pages as a client. The link check contacts YouTube (oEmbed), Digistore24 and the shop hosts named in the site's own content, and sends only those public URLs and the user agent string. Staging credentials pass to `curl` through a file, never as an argument, never over http.

[PRIVACY.md](PRIVACY.md) describes the implementation in English. [DATENSCHUTZ.md](DATENSCHUTZ.md) holds the German text block for a site's privacy policy. Both describe the implementation and are not legal advice.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency, used by the Kirby tests
composer test                                   # or: sh tests/run.sh
```

The tests are plain PHP scripts: each `tests/*Test.php` is a standalone script, and exit code 0 means pass. They need no PHPUnit and no network connection. Run one with `php tests/StampTest.php`.

- `I18nTest.php`: the English and German translation files have the same keys and placeholders.
- `StampTest.php`: reading, writing and the meaning of the stamp, including the hash from `git rev-parse HEAD` in a temporary repository, and the `genba-stamp` command.
- `PreflightTest.php`: which hook runs, its exit codes, and the `genba-preflight` command.
- `KirbyTest.php`: the Kirby part, with a real Kirby instance from the development dependency. It covers the switch, the stamp file options, the language, the translations and a site's own snippet.
- `PrecheckTest.php`: a fixture project with one finding per rule, the self-tests of the rules, the required files and the `genba-precheck` command.
- `SmokeTest.php`: runs `bin/genba-smoke` against PHP's built-in server, `php -S`, with the router `tests/fixtures/smoke-server.php`. The router plays a healthy site and injects one fault per run, named in the file given by `GENBA_TEST_FAULT_FILE`. The build hash comes from `GENBA_TEST_BUILD`. Every fault must turn the run red, and the two warning modes must stay green. `tests/fixtures/tls-server.php` plays the https cases, `tests/fixtures/raw-server.php` sends what PHP's server cannot (a `Location` with a bare CR or a line break; a first connection closed without a byte, then 401). The test also checks the script's source: `bash -n`, `set -euo pipefail`, `umask 077`, and that no credential is ever a `curl` argument. `shellcheck` runs when it is installed. If not, the test says so visibly.
- `HtmlCorpusTest.php`: the HTML variant corpus — one homepage (`tests/fixtures/html-variants/`) and one template (`tests/fixtures/precheck-variants/`) in many spellings a browser reads the same: quotes double, single or none, attributes over several lines, minified, capitals, comments. Every smoke variant must pass and request exactly the same files, every precheck variant must give exactly the same findings. A new spelling is a new file.
- `MessagesTest.php`: every message the five tools print stands in the README section of its tool. The test reads the message texts out of `bin/genba-smoke` and `src/` and looks each constant part up in the README.
- `LinksTest.php`: the classification of references, the name matching, a router with oEmbed, product and purchase pages, the status matrix, and the counter-check against an honest and a deliberately blind server, and the `genba-links` command.

The test suite does not start a real site, deploy anything or contact a third party.

## Maintenance

This module is open source under the MIT license and is maintained for the author's own projects. Issues and pull requests are welcome, but there is no support promise and no release schedule. Report security problems privately through GitHub's private vulnerability reporting (the "Report a vulnerability" button on the Security tab).

## License

MIT, see [LICENSE](LICENSE).
