# Messages

Every line the tools print that a log or a check relies on. `<…>` is a value. Read by `tests/MessagesTest.php`.

## genba-stamp

```text
Genba stamp: wrote <hash> to <file>
Genba stamp: PASSED
Genba stamp: FAILED — not a full commit hash
Genba stamp: FAILED — git rev-parse HEAD failed
Genba stamp: FAILED — cannot write the stamp file
Genba stamp: FAILED — the stamp file does not verify
Genba stamp: FAILED — no valid build stamp in <file>
Genba stamp: FAILED — cannot run
::error::Not a full commit hash: <value>
::error::git not found
::error::git rev-parse HEAD failed: <first line of git's error>
::error::git rev-parse HEAD exited with <n>, git rev-parse HEAD printed no full commit hash, Directory not found: <dir>
::error::Cannot write <file>: directory does not exist, it is a symbolic link, refusing to write through it, write failed
::error::<file> does not contain the hash just written
::error::No valid build stamp in <file>
::error::Unknown argument: <argument>
::error::--sha needs a value
::error::--file needs a value
::error::PHP extension mbstring is missing (<function> is not available)
```

## genba-preflight

```text
Genba preflight: running 'npm run praeflug' (from package.json)
  ok   preflight hook finished
Genba preflight: PASSED
Genba preflight: SKIPPED — nothing to run
Genba preflight: FAILED — hook exit <n>
Genba preflight: FAILED — cannot run
::error::Preflight hook failed with exit <n>
::error::Cannot start the preflight hook (sh is not available)
::error::--hook needs a value (GENBA_PREFLIGHT_HOOK=none switches the step off)
::error::Unknown argument: <argument>
::warning::Cannot read <dir>/package.json (not valid JSON); no preflight hook taken from it
::warning::<dir>/package.json: "scripts" is not an object; no preflight hook taken from it
  --   no preflight hook configured (no GENBA_PREFLIGHT_HOOK, no package.json script "preflight" or "praeflug")
  --   no preflight hook configured (--hook=none)
  --   no preflight hook configured (GENBA_PREFLIGHT_HOOK=none)
::error::PHP extension mbstring is missing (<function> is not available)
```

## genba-precheck

```text
  ok   self-test: 5 rules fire on their samples and stay silent on their negative samples
  ok   self-test: 1 marker pattern fires on its samples and stays silent on its negative samples
  ok   self-test: <n> marker patterns fire on their samples and stay silent on their negative samples
::error::<file>:<line>: inline style — the CSP forbids it: <line>
::error::<file>:<line>: inline event handler — the CSP forbids it: <line>
::error::<file>:<line>: inline event handler with a name built at runtime — the CSP forbids it: <line>
::error::<file>:<line>: embedded <script> — the CSP forbids it: <line>
::error::<file>:<line>: foreign host — brings back the consent requirement: <line>
::error::Self-test failed: rule <name> did not fire on its sample — pattern broken
::error::Self-test failed: rule <name> did not fire on its positive sample '<line>' — pattern broken
::error::Self-test failed: rule foreign-host did not fire on its positive sample '<line>' with the default allow list — pattern broken
::error::Self-test failed: rule <name> fired on its negative sample '<line>' — pattern broken
::error::Self-test failed: rule <name>: pattern does not compile — pattern broken
::error::Self-test failed: rule <name> has no pattern or sample — pattern broken
::error::Self-test failed: rule <name>: positives is not a list — pattern broken
::error::Self-test failed: rule <name>: negatives is not a list — pattern broken
::error::Self-test failed: rule <name>: names is not a list of lower-case names — pattern broken
::error::Self-test failed: rule <name>: names needs a pattern with a (?<name>…) group and no each — pattern broken
Genba precheck: FAILED — cannot run
::error::Directory not found: <dir>
::error::Cannot read file: <file>
::error::Cannot read directory: <dir>
::error::--require lists no file: ',' (use none to switch the file check off)
::error::GENBA_REQUIRE_FILES lists no file: ' , ' (use none to switch the file check off)
::error::No files found under <roots>
::error::Required file missing or empty: <file>
  ok   <file> (<n> bytes)
  --   no required files configured (GENBA_REQUIRE_FILES=none)
  --   no required files configured (--require=none)
  --   not checked: <dir> (cannot be read (permission denied))
  --   not checked: <dir> (broken symbolic link)
  --   not checked: <dir> (symbolic link into <place>)
  --   not checked: <dir> (inside <place>, reached through a symbolic link)
  --   not checked: <dir> (symbolic link to a file outside the project)
  --   not checked: <dir> (symbolic link to a directory outside the project)
  --   not checked: <dir> (symbolic link to a directory already scanned)
  --   not checked: <dir> (cannot be resolved)
  --   not checked: site/plugins/genba (Genba's own package, its rule samples would match every rule)
  --   not checked: <n> Markdown files in site/plugins (package documentation)
  --   not counted: <file>:<line>: 'once' is not an event handler Genba knows: <line>
```

With `--forbid-markers` (the live deploy):

```text
::error::<file>:<line>: placeholder '<marker>' — not for the live site: <line>
::error::<file>:<line>: marker '<marker>' — not for the live site: <line>
  ok   no markers in <n> content files (<m> patterns)
  --   not checked: content/blog/_drafts (Kirby drafts, not delivered)
  --   GENBA_MARKERS is set, but the content is not checked for markers (no --forbid-markers)
::error::--marker: pattern does not compile: <pattern>
::error::GENBA_MARKERS: pattern matches an empty line, so every line: <pattern>
::error::--marker leaves no marker pattern (none drops the default; add a pattern or leave none out)
::error::--marker needs --forbid-markers
::error::--content needs --forbid-markers
::error::No content files (*.txt) found under <dir>
::error::Not valid UTF-8, cannot be checked for markers: <file>
::error::Marker pattern failed on <file>:<line> (<reason>)
```

The last line of every run:

```text
Genba precheck: PASSED (<n> files scanned)
Genba precheck: FAILED — <n> findings, <m> required files missing, <k> markers in the content
Genba precheck: FAILED — cannot run
::error::PHP extension mbstring is missing (<function> is not available)
```

## genba-smoke

```text
Genba smoke against <url>, expected build <hash>
Genba smoke: PASSED.
Genba smoke: FAILED — see findings above.
Genba smoke: FAILED — cannot run
Genba smoke: SKIPPED — <host> does not resolve yet
Genba smoke: SKIPPED — https to <host> fails (certificate pending?)
Genba smoke: SKIPPED — build not verified, see the warning above.
::warning::Genba smoke: SKIPPED — <host> does not resolve yet (DNS entry pending). Nothing was checked; run again once the name resolves.
::warning::Genba smoke: SKIPPED — https to <host> answers HTTP <code>: TLS certificate not issued yet? Nothing was checked.
::warning::Genba smoke: SKIPPED — <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set. Whether the new build arrived is NOT verified.
  --   <url> requires credentials (HTTP 401) and GENBA_USER/GENBA_PASSWORD are not set — not SKIPPED: the run has findings already
  ok   Protected: <url>/ answers 401 without credentials
::error::<host> does not resolve (DNS entry missing?) — a preview whose DNS entry is still pending sets GENBA_NOT_READY=skip
::error::https to <host> fails, and without credentials <http url> returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
::error::Without credentials <url>/ returns HTTP <code> instead of 401 — the preview protection does not work, the site may be open to everyone
::error::Without credentials <url>/ gives no answer (HTTP 000) — <curl's message> — the preview protection is not verified
  ok   Homepage serves build <hash>
::error::Homepage: HTTP <code> instead of 200 — <curl's message>
::error::Homepage: HTTP 401 instead of 200 (protected: set GENBA_USER and GENBA_PASSWORD)
::error::Homepage larger than <n> bytes — not read: its build, its marker and its addresses are not checked
::error::Homepage without <meta name="build"> — stamp file not deployed or snippet('genba/stamp') missing in <head>
::error::Live build is <live>, expected <expected> — the upload did not (fully) arrive
::error::Homepage does not contain the marker '<marker>' — is this really our site?
  ok   Marker 'assets/css/tailwind.css' found
  --   marker not checked (GENBA_MARKER empty)
  ok   Unknown address returns 404
::error::Unknown address returns HTTP <code> instead of 404 — <curl's message>
  ok   GET /robots.txt: 200, text/plain
::error::GET /<file>: HTTP <code> instead of 200 — <curl's message>
::error::GET /<file>: larger than <n> bytes — not read
::error::GET /<file>: Content-Type '<type>', expected <type>
::error::/<file> does not contain what it should (pattern: <pattern>)
::error::/robots.txt does not contain what it should (pattern: ^Sitemap: )
::error::robots.txt disallows all crawling (Disallow: /) but the sitemap lists pages — the live site would be closed by mistake (GENBA_CLOSED_PREVIEW=accept accepts Disallow: / only with an empty sitemap)
  --   closed preview: robots.txt Disallow: / and an empty sitemap, so no sitemap pages were fetched (GENBA_CLOSED_PREVIEW=accept)
::error::Sitemap not available for page check
::error::Sitemap lists no pages
::error::Sitemap: <n> entries could not be parsed (<u> <url> entries, <l> <loc> addresses)
::error::Sitemap entry is empty (<loc> without an address)
::error::Sitemap entry is not an absolute URL: <loc> — the sitemap protocol requires scheme and host
::error::Sitemap entry longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Sitemap entry with a character reference that is not decoded: <loc> — not fetched
::error::Sitemap points to a foreign host: <loc> (neither <origin> nor GENBA_SITEMAP_ORIGIN <origin>)
::error::Sitemap points to a foreign host: <loc> (site URL misconfigured?)
::notice::Sitemap lists <n> pages on <foreign origin> instead of <origin> — rewritten to <origin> (path and query kept) and fetched there (GENBA_FOREIGN_SITEMAP=rewrite)
::warning::No sitemap page fetched: the entries point to a foreign host — the pages of <url> are NOT checked
::error::No sitemap page fetched: every entry is a finding (see above) — the pages of <url> are NOT checked
::error::Sitemap page <url>: HTTP <code> instead of 200 (listed as <loc>) — <curl's message>
  ok   <n> sitemap pages fetched
  ok   <n> of <m> sitemap pages fetched (GENBA_PAGES_MAX=<n>), the rest not checked
  --   stylesheets, images and fonts not checked: the homepage is larger than <n> bytes and was not read
  --   stylesheets, images and fonts not checked: the homepage gave no answer (HTTP 000)
  --   stylesheets, images and fonts not checked: the homepage answered HTTP <code>, not 200
  --   <base href="<href>"> on the homepage: the addresses after it are resolved against <url>
  --   <base href="<href>"> on the homepage is ignored, as the browser ignores a data: or javascript: base
::error::Homepage <base href="<href>"> is no http(s) address — the addresses after it are not checked
  ok   Stylesheet: 200, text/css
::error::Stylesheet <url>: HTTP <code>, '<type>', <bytes> bytes — expected 200, text/css, not empty — <curl's message>
::notice::The homepage and its stylesheets name <n> addresses on <origin> instead of <origin> — rewritten to <origin> (path and query kept) and checked there (GENBA_FOREIGN_SITEMAP=rewrite, GENBA_SITEMAP_ORIGIN)
  --   no stylesheet of <origin> found on the homepage, stylesheets not checked
::error::Homepage contains the stylesheet address '<marker>' (GENBA_MARKER), but no stylesheet of <origin> with that address was read from its <link> elements — the stylesheet is not checked (loaded by a script, from another origin, or read wrongly?)
  --   no .webp file found on the homepage, Content-Type not checked
  --   no .webp file of <origin> found on the homepage (<n> on other origins, not requested), Content-Type not checked
  --   <n> .webp files of <origin> on the homepage, the first 5 checked (the server configuration is tested, not every file)
::error::<asset>: HTTP <code> instead of 200 — <curl's message>
::error::<asset>: Content-Type '<type>', expected <type>
::error::Stylesheet address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
::error::Asset address longer than 2048 characters (<n>), not fetched: <first 60 characters>…
  ok   <asset>: <type>
  ok   Panel set up (account present, installer locked)
  --   Panel not checked (GENBA_PANEL_ACCOUNT=off)
::error::/api/system: HTTP <code> without isInstalled/isInstallable — is this Kirby's Panel API?
::error::Panel can be installed from outside (panel.install on?) — any visitor could become admin
::error::Panel without account: /panel shows the installer. Create the account on the server first
  ok   Panel: /panel answers 200, its <n> files arrive
  --   /panel sends on to <target> — checked as <path> on <origin> (Kirby's url option names another origin)
  --   <path> sends on to itself (Kirby links the Panel files on the first call after an update) — asked once more
::error::/panel names no JS or CSS under /media/panel — an empty Panel page?
::error::Panel file <path>: HTTP <code> instead of 200 — <curl's message>
::error::Panel file <path>: HTTP 200, '<type>', <bytes> bytes — expected text/javascript or application/javascript or application/x-javascript, not empty
::error::/panel: HTTP <code> instead of 200 — <curl's message>
::error::/panel: HTTP <code> to '<location>', not to a page under /panel
  ok   <n> private paths not reachable (none answers 2xx)
  --   private paths not checked (GENBA_PRIVATE_PATHS=none)
  --   private paths not checked: the homepage gave no answer (HTTP 000)
  --   private paths not checked: the homepage answered HTTP <code>, not 200
::error::Private path /<path> answers HTTP <code> — reachable from the web (document root not on public/?)
::error::Private path /<path>: no answer (HTTP 000) — <curl's message> — not verified
  --   response headers not checked (GENBA_HEADERS not set)
  ok   homepage <name>: exactly '<value>' (HTTP 200)
::error::homepage <name>: sent '<value>', expected exactly '<value>' (HTTP 200)
::error::homepage <name>: not sent, expected present (HTTP 200)
::error::homepage <name>: HTTP <code> instead of 2xx or 3xx — the header was not checked (path wrong?)
::error::homepage <name>: no answer (HTTP 000) — <curl's message>
  --   http→https not checked (GENBA_HTTPS_REDIRECT=off)
  --   http→https not checked: URL is not https
  ok   http redirects with <code> to <target>
::error::http→https missing: <http url> returns '<code>' with target '<target>', expected 301/308 to https://<host> — <curl's message>
::warning::Panel without account: /panel shows the installer. Create the account on the server first   (GENBA_PANEL_ACCOUNT=warn)
::error::Panel without account: /panel shows the installer. Create the account on the server first
::error::GENBA_URL missing (base address of the site)
::error::GENBA_URL must start with http:// or https://
::error::GENBA_URL must not contain credentials (user:password@host) — use GENBA_USER and GENBA_PASSWORD
::error::URL argument is empty (set GENBA_URL or pass the address)
::error::Only one URL argument is allowed
::error::Unknown argument: <argument>
::error::GENBA_EXPECTED_BUILD is not a commit hash: <value>
::error::GENBA_EXPECTED_BUILD not set and git not found
::error::GENBA_EXPECTED_BUILD not set and the working directory is not a git checkout
::error::GENBA_META must match [A-Za-z0-9_-]+ (the name of the <meta> element): <value>
::error::GENBA_PANEL_PATH must be a path below the site root: <value>
::error::GENBA_API_PATH must be a path below the site root: <value>
::error::GENBA_HEADERS: path '<path>' must be / followed by letters, digits, . _ ~ / -
::error::GENBA_HEADERS: a line needs 'Name: rule' (got: <line>)
::error::GENBA_HEADERS: '<name>' is not a header name
::error::GENBA_HEADERS: the rule for <name> is empty
::error::GENBA_HEADERS: the rule for <name> is '~' without text
::error::GENBA_PRIVATE_PATHS: not a path below the site root: <entry>
::error::GENBA_PAGES_MAX must be a whole number above 0: <value>
::error::GENBA_RETRIES must be a whole number above 0: <value>
::error::GENBA_PAUSE must be a whole number of seconds: <value>
::error::GENBA_MAX_TIME must be a whole number of seconds above 0: <value>
::error::GENBA_SITEMAP_ORIGIN must be an origin without path (https://host or https://host:port): <value>
::error::GENBA_USER and GENBA_PASSWORD must be set together
::error::GENBA_USER/GENBA_PASSWORD must not contain a line break (a secret set from a file keeps the file's line breaks)
::error::GENBA_USER/GENBA_PASSWORD only over https: GENBA_URL is not https:// (plain http only to 127.0.0.1, localhost or [::1])
::error::curl cannot read its credentials file (curl exit 26) — GENBA_USER/GENBA_PASSWORD were not sent; check them for characters curl's -K file cannot hold
::error::curl not found
::error::php not found (genba-smoke reads the homepage with PHP: bin/genba-smoke-read)
::error::The homepage reader cannot run: genba-smoke needs PHP 8.4 or later on the machine that runs it (it reads the homepage with Dom\HTMLDocument), this is PHP <version>
::error::The homepage reader cannot run: PHP extension mbstring is missing (<function> is not available)
::error::The homepage reader cannot run: PHP extension dom is missing (genba-smoke reads the homepage with Dom\HTMLDocument)
::error::The homepage cannot be read: <reason>
::error::The homepage cannot be read: the reader did not finish within <n> seconds (GENBA_MAX_TIME)
genba-smoke-read: unknown argument
genba-smoke-read: --meta=NAME and one file are required
genba-smoke-read: cannot read the saved homepage
```

## genba-links

```text
<n> external references found, <m> distinct targets.
Genba links: <n> references listed
Genba links: <ok> ok, <findings> findings, <disruptions> disruptions, <notices> notices.
Genba links: FAILED — cannot run
Genba links: FAILED — counter-check failed, no reference was checked
  ok   <url>: <text> — in <places>
::error::<url>: <text> — in <places>
::error::<url>: Purchase link: redirect to <host> refused (private or local address <address>) — not followed (<chain>) — in <places>
::error::<url>: Purchase link: address <host> refused (private or local address <address>) — not requested (<chain>) — in <places>
::warning::Disruption, not assessed: <url>: <text> — in <places>
::notice::<url>: <text> — in <places>
::notice::No external references found under <paths>
::error::Counter-check: <failure>
The link check cannot currently tell what exists from what does not. The result would be worthless.
Counter-check passed: a deleted video and a missing product are recognised.
  --   counter-check skipped (--skip-counter-check)
  --   not checked: <dir> (<reason>)
  --   not checked: <dir> (cannot be read (permission denied))
  --   not checked: <dir> (broken symbolic link)
  --   not checked: <dir> (symbolic link into <place>)
  --   not checked: <dir> (inside <place>, reached through a symbolic link)
  --   not checked: <dir> (symbolic link to a file outside the project)
  --   not checked: <dir> (symbolic link to a directory outside the project)
  --   not checked: <dir> (symbolic link to a directory already scanned)
  --   not checked: <dir> (cannot be resolved)
  --   not checked: public (default path, does not exist)
  --   not checked: <dir> (Genba's own package: its tests and docs carry sample links)
::error::Cannot read directory: <dir>
::error::Cannot read file: <file>
::error::Path argument is empty (omit it for the default paths)
::error::None of the paths exist: <paths>
::error::Unknown argument: <argument>
::error::--pause must be a number of seconds, not: <value>
::error::--<option> needs a value
::error::PHP extension curl is missing
::error::PHP extension mbstring is missing (<function> is not available)
::warning::Path not found, skipped: <path>
  ok   youtube: "<title>"
::error::Video <id> does not exist (oEmbed <code>)
Video <id> is private (oEmbed 403)
Video <id> cannot be embedded (oEmbed 401)
Video <id>: URL refused (<reason>)
oEmbed answers 200 without a title
YouTube unreachable: no response (<curl error>) | HTTP <code>
Digistore product <id> does not exist (HTTP <code>)
Digistore product <id>: no response (<curl error>) | HTTP <code>
Digistore product <id>: <refusal>
Digistore product <id> shows no active product (page title "<title>")
Digistore product <id> is called "<name>", but the page is "<title>" / "<title>" — wrong ID?
Purchase link: <n> redirect(s), target <final>
Parameters lost on the way: aff=<value>, cam=<value> (<chain>)
Purchase link ends with HTTP <code> (<chain>)
Purchase link: no response (<curl error>) | HTTP <code> (<chain>)
Purchase link: <refusal> (<chain>)
URL refused (<reason>)
redirect to <host> refused (local host name) — not followed
redirect to <host> refused (private or local address <address>) — not followed
address <host> refused (private or local address <address>) — not requested
connected to <ip> instead of the checked address <ip> for <host> — result discarded
whitespace, control character, backslash or non-ASCII in the address
not an absolute http(s) address
user information in the address
address cannot be parsed unambiguously
invalid IPv6 address
numeric host in a non-canonical form
host name is not a plain DNS name
Could not resolve host: <host>
Existing video <id> not recognised: <text>
Missing video <id> not recognised as a finding: <status>, <text>
Missing Digistore product <id> not recognised as a finding: <status>, <text>
PHP extension mbstring is missing (<function> is not available)
```
