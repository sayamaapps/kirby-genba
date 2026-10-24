# Reference

Settings, checks and rules of Genba. A setting takes its argument first, then its environment variable, then the default. Messages: [messages.md](messages.md).

## Kirby part

| Name | Kind | Meaning |
|---|---|---|
| `genba_option('stamp.file', 'site/config/build.txt')` | function | Reads any `genba.*` option in a snippet of your own, with a default. |
| `$site->genbaBuild()` | site method | The deployed hash (40 hex characters) or `null`. |
| `$site->genbaBuildInfo()` | site method | `['hash', 'short' (7 characters), 'file' (relative to the project root), 'writtenAt' (ISO 8601 UTC or null)]`, or `null`. |
| `snippet('genba/stamp')` | snippet | `<meta name="build" content="…">`, nothing without a valid stamp. |
| `snippet('genba/build-info')` | snippet | Visible build line. `$label` (default `true`), `$withDate` (default `false`). Classes: `genba-build`, `genba-build-label`, `genba-build-hash`, `genba-build-date`, `genba-build--none`. |

Override a snippet by creating the same file in the site (`site/snippets/genba/stamp.php`). Translation keys: `genba.build.label`, `genba.build.hash`, `genba.build.writtenAt`, `genba.build.none`, `genba.panel.build.label`, `genba.panel.build.info`.

## genba-stamp

Writes the commit hash of the checkout to the stamp file.

| Setting | Default | Meaning |
|---|---|---|
| `--file=PATH`, `GENBA_STAMP_FILE` | `site/config/build.txt` | Stamp file, relative to the working directory. A symbolic link is refused. |
| `--sha=HASH`, `GENBA_SHA` | `git rev-parse HEAD` | Full 40-digit lower-case hash. `GITHUB_SHA` is never used (a merge commit on a pull request). |
| `--show` | | Print the hash in the file, write nothing. |
| `--help` | | Usage, exit 0. |

Exit codes: 0 written or shown · 1 not written or not verified, or git failed · 2 usage error, git not found, mbstring missing.

## genba-preflight

Runs the project's own generation step before the build.

| Setting | Default | Meaning |
|---|---|---|
| `--hook=COMMAND`, `GENBA_PREFLIGHT_HOOK` | `package.json` script `preflight`, else `praeflug` | Shell command, run with `sh -c` in the working directory. `none` switches the step off. |
| `--help` | | Usage, exit 0. |

Without a hook the tool reports `SKIPPED` and exits 0. Exit codes: 0 passed or skipped · 1 hook failed · 2 usage error, or `sh` cannot start.

## genba-precheck

Static checks of the built site under the Content Security Policy, a check that required built files exist and, for the live deploy, a check of the content for placeholders.

| Setting | Default | Meaning |
|---|---|---|
| `--site=DIR` | `site` | Templates, snippets and plugins to scan. |
| `--public=DIR` | `public` | Document root with the built assets. |
| `--allow=a,b`, `GENBA_ALLOW` | `places.googleapis.com/v1/` | Added to the default. `none` clears it. Frees a foreign-host match in the same address only. |
| `--require=f1,f2`, `GENBA_REQUIRE_FILES` | `public/assets/css/tailwind.css` | Files that must exist and be non-empty. Replaces the default. `none` turns the check off. |
| `--forbid-markers` | off | Also check the content for markers that must not go live. For the live deploy; a preview leaves it out. |
| `--content=DIR` | `content` | Content root for `--forbid-markers`. |
| `--marker=PATTERN` (repeatable), `GENBA_MARKERS` (one per line) | none | Further markers for `--forbid-markers`: regular expressions without delimiters, any case, UTF-8. Added to the default. `none` drops it. |
| `--help` | | Usage, exit 0. |

| Rule | Finds |
|---|---|
| `inline-style` | `style=` attributes, also printed through Kirby's `attr()` helper |
| `foreign-host` | googleapis, gstatic, jimcdn, `instagram.com/embed`, maps.google, protocol-relative `//fonts.` hosts, minus the allow list |
| `inline-handler` | `on…` event handler attributes (names Genba knows as handlers) |
| `inline-handler-runtime` | an `on` followed by a PHP block, so the handler name is built at runtime |
| `embedded-script` | a `<script>` with neither `src` nor `application/ld+json` |

Each rule has a self-test that runs before the scan. Not scanned: `site/cache`, `site/sessions`, `site/accounts`, `site/logs` and `public/media` (directly under their roots), `node_modules` and `.git` at any depth, Markdown under `site/plugins/`, and Genba's own directory. PHP comments are not read.

With `--forbid-markers`, every `*.txt` file under the content root is read line by line, except under `_drafts` (not delivered). The default marker is a placeholder: one of the words `TODO`, `FIXME`, `TBD`, `TBA`, `TBC`, `XXX`, `placeholder`, `Platzhalter`, `offen`, in any case, right behind `[` and followed by `]`, a colon or a dash: `[TODO]`, `[OFFEN] Text folgt`, `[TBD: date]`. A Markdown link (`[TODO](…)`) and a longer word (`[Todo-Liste]`) are no marker. A finding names file, line and the marker. The content is not rendered: the markers stand in the content files, and the check runs before the upload, without Kirby. Text a template prints itself is not checked.

Exit codes: 0 passed · 1 findings, a missing required file or a marker in the content · 2 cannot run (self-test failed, directory missing, no files, no content files, a content file not valid UTF-8, a marker pattern that does not compile or matches an empty line, mbstring missing, usage error).

## genba-smoke

Post-deploy check of a live site or a preview. The address is the first argument or `GENBA_URL`.

| Setting | Default | Meaning |
|---|---|---|
| `GENBA_URL` (or argument) | required | Base address, `https://…`. |
| `GENBA_EXPECTED_BUILD` | `GENBA_SHA`, else `git rev-parse HEAD` | Hash that must be live, 7–64 hex characters; a short one is matched as a prefix. |
| `GENBA_META` | `build` | Name of the build `<meta>`, `[A-Za-z0-9_-]+`. |
| `GENBA_MARKER` | `assets/css/tailwind.css` | Text the homepage must contain. Empty turns the check off. |
| `GENBA_USER`, `GENBA_PASSWORD` | | Basic Auth. Both or neither. https only, except `127.0.0.1`, `localhost`, `[::1]`. No line break in either value. |
| `GENBA_WITHOUT_CREDENTIALS` | `fail` | `skip`: a 401 without credentials ends the run as SKIPPED. Never use it on the live site. |
| `GENBA_EXPECT_PROTECTED` | `0` | `1`: the first request, without credentials, must answer 401. |
| `GENBA_NOT_READY` | `fail` | `skip`: a name that does not resolve, or an https connection that cannot be set up while http answers, ends the run as SKIPPED. |
| `GENBA_HTTPS_REDIRECT` | `require` | `require`, `warn` or `off`: `http://<host>/` must redirect to https with 301 or 308. |
| `GENBA_PANEL_ACCOUNT` | `require` | `require`, `warn` or `off`: a Panel account must exist. |
| `GENBA_PANEL_PATH` | `/panel` | Panel address, for a site with its own `panel.slug`. |
| `GENBA_API_PATH` | `/api` | Panel API address (`<path>/system`), for a site with its own `api.slug`. |
| `GENBA_FOREIGN_SITEMAP` | `rewrite` on a preview run, else `fail` | `rewrite`: sitemap entries on another origin are fetched on the `GENBA_URL` origin. `warn`: a warning. `fail`: a finding. |
| `GENBA_CLOSED_PREVIEW` | `accept` on a preview run, else `fail` | `accept` or `fail`: a closed preview (`robots.txt` with `Disallow: /` and an empty sitemap) is accepted or a finding. |
| `GENBA_SITEMAP_ORIGIN` | | `https://host[:port]`: the one foreign origin the sitemap may list, normally production. |
| `GENBA_PRIVATE_PATHS` | the default list below | Paths that must not answer 2xx. Added to the default. `none` drops the default; `none` alone turns the check off. |
| `GENBA_HEADERS` | not checked | One rule per line: `[/path] Name: rule`. Rule: a value (exact), `*` (present), `none` (absent), `~text` (contains), `!~text` (sent, does not contain). |
| `GENBA_PAGES_MAX` | `30` | Sitemap pages fetched. |
| `GENBA_RETRIES` | `3` | Homepage attempts while a cache still holds the old build. |
| `GENBA_PAUSE` | `5` | Seconds between the attempts. |
| `GENBA_HTTP_URL` | `http://<host>/` | Tests only. |
| `GENBA_MAX_TIME` | `25` | Seconds one request, and the reader of the homepage, may take. Tests only. |

Default private paths: `.env`, `.git/HEAD`, `composer.json`, `composer.lock`, `site/`, `site/config/config.php`, `site/config/credentials.php`, `site/config/kizami.php`, `site/config/kennzahlen.php`, `content/`, `content/site.txt`, `kirby/bootstrap.php`, `vendor/`, `vendor/autoload.php`, `storage/`, `storage/kizami/kizami.sqlite`, `storage/kennzahlen/kennzahlen.sqlite`.

| # | Check |
|---|---|
| 0 | Reachability gate (`GENBA_EXPECT_PROTECTED`, `GENBA_NOT_READY`) |
| 1 | Homepage 200, build `<meta>` equal to the expected hash, marker text present |
| 2 | Unknown address returns 404 |
| 3 | `robots.txt`, `sitemap.xml`, `llms.txt`: 200 with the right type and content, GET and HEAD |
| 4 | Every sitemap page 200; foreign origins per `GENBA_FOREIGN_SITEMAP` and `GENBA_SITEMAP_ORIGIN` |
| 5 | Stylesheets 200 with `text/css`; up to five `.webp`, `.avif` and `.woff2` files per type have the right type |
| 7 | `/api/system` reports installed and not installable |
| 7b | `/panel` answers 200 and its JS and CSS files load |
| 8 | No private path answers 2xx |
| 9 | Response headers from `GENBA_HEADERS` |
| 6 | `http://<host>/` redirects to https |

Credentials are sent only to the origin of `GENBA_URL`, never over plain http. `curl` ignores `~/.curlrc` and proxy variables. Exit codes: 0 passed or skipped · 1 findings · 2 cannot run.

## genba-links

Checks where each external reference leads, not whether it answers 200.

| Setting | Default | Meaning |
|---|---|---|
| `--digistore-field=a,b`, `GENBA_DIGISTORE_FIELDS` | `digistoreid` | Content fields that hold Digistore product IDs. |
| `--youtube-field=a,b`, `GENBA_YOUTUBE_FIELDS` | `videoid,youtubeid,youtubevideos` | Content fields that hold YouTube IDs or URLs. |
| `--shop-host=h1,h2`, `GENBA_SHOP_HOSTS` | none | Shop hosts whose links are purchase links. Requested even when they resolve to a private address. |
| `--skip-counter-check` | off | Skips the proof that a deleted video and a missing product are recognised. |
| `--list-only` | off | Lists the references, contacts nothing. |
| `--oembed-base=URL` | YouTube oEmbed endpoint | Tests only. |
| `--digistore-base=URL` | Digistore24 product endpoint | Tests only. |
| `--pause=SECONDS` | `0.3` | Pause between requests. |
| `--help` | | Usage, exit 0. |
| paths | `content`, `site`, `public` | Directories or files to scan, relative to the working directory. |

| Kind | Source | Finds |
|---|---|---|
| `youtube`, `youtube-embed` | YouTube oEmbed | Deleted, private or non-embeddable videos, a missing title |
| `digistore` | Digistore24 product page | No such product, no active product, or a title that does not match the name in the site |
| `purchase` | the shop, following the redirects | A chain that ends in an error or a loop, or a lost `aff=` or `cam=` parameter |

Timeouts, 5xx and 429 are warnings, not findings. Redirects are followed by hand, at most 10, and never into private, local or loopback addresses. Exit codes: 0 no findings (warnings included) · 1 findings or a failed counter-check · 2 cannot run.

## Workflow

Order of the deploy steps: `genba-preflight`, `npm run build`, `genba-precheck`, `genba-stamp`, the upload, `genba-smoke`. Settings beyond `GENBA_URL` of `genba-smoke`:

| Target | Settings |
|---|---|
| Preview | `GENBA_USER`, `GENBA_PASSWORD`, `GENBA_EXPECT_PROTECTED: 1`, `GENBA_NOT_READY: skip`, `GENBA_HTTPS_REDIRECT: warn`, `GENBA_SITEMAP_ORIGIN` (the live URL, if the preview's site URL is production). `GENBA_WITHOUT_CREDENTIALS: skip` only where an unverified preview deploy is acceptable. |
| Live | `genba-precheck --forbid-markers`: a placeholder in the content stops the deploy before the upload. `GENBA_FOREIGN_SITEMAP: fail`, set explicitly: a foreign sitemap entry on the live site means a wrong site URL. Never `GENBA_WITHOUT_CREDENTIALS: skip`. |

## Tests

`composer test` runs `tests/run.sh`, which runs every `tests/*Test.php` as a plain PHP script. None needs PHPUnit or network access. `KirbyTest.php` needs `vendor/`.

| Test | Covers |
|---|---|
| `I18nTest.php` | English and German files have the same keys and placeholders |
| `StampTest.php` | Stamp reading, writing and `genba-stamp` |
| `PreflightTest.php` | Hook lookup, exit codes and `genba-preflight` |
| `KirbyTest.php` | The Kirby part with a real Kirby instance |
| `PrecheckTest.php` | Fixture project with one finding per rule, self-tests, `genba-precheck`, markers in the content with and without `--forbid-markers` |
| `SmokeTest.php` | `genba-smoke` against PHP's built-in server with injected faults |
| `HomepageTest.php` | The homepage reader's own rules |
| `HtmlCorpusTest.php` | One homepage and one template in many HTML spellings |
| `LinksTest.php` | Reference classification, redirects, counter-check, `genba-links` |
| `MbstringTest.php` | Every tool exits 2 without mbstring |
| `MessagesTest.php` | Every message literal of the tools stands in [messages.md](messages.md); the `GENBA_MAX_TIME` row above names both bounds |
