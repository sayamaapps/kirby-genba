# Genba: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Nothing towards any third party. The Kirby part sets no cookies, uses no
`localStorage`, `sessionStorage` or IndexedDB, and loads no script, font, image
or embed from another server. The page contains a commit hash of the site's own
code, as plain HTML: `<meta name="build" content="…">`, and, if the site uses the
optional build line, the same hash in a `<p>` element with `genba-*` classes.

The plugin records nothing about the visitor. It does not read the visitor's IP
address, user agent or device clock.

## What the Kirby part computes

For each page request the Kirby part reads one file from the site's own
directory, `site/config/build.txt` by default, and prints the hash in it when the
file holds a valid hash. No visitor data is involved. Nothing is cached between
requests inside the plugin.

## What the tools do, and where

The command-line tools run in the continuous integration of the site, not in a
visitor's browser:

- **genba-stamp** writes the commit hash of the checkout to the stamp file. Local
  operation only.
- **genba-preflight** runs the site's own generation step. Local operation only.
- **genba-precheck** scans the site's own files for rules of the Content Security
  Policy. Local operation only.
- **genba-smoke** fetches the site's own pages as a client, the homepage, the
  sitemap, the robots file, the stylesheets, the images, and the Panel's status
  endpoint. Credentials for a protected preview are used for these requests only.
- **genba-links** contacts YouTube (oEmbed), Digistore24 and the shop hosts named
  in the site's own content. It sends only those public URLs and its user agent
  string, `Mozilla/5.0 (compatible; Genba link check; +https://github.com/sayamaapps/kirby-genba)`.
  No visitor data exists at that point.

Staging credentials for a protected preview pass to `curl` through a
configuration file, created with restrictive permissions, never as a command-line
argument, never over plain http, and never to another origin.

## What is stored, and where

The package stores nothing itself: no database, no key, no account. Its input is
the site's own content and configuration. The stamp file is an ordinary file in
the site's configuration directory. It is gitignored by the site and deployed
with the code. The tool output goes to the CI log, which the site's operator can
read.

## What is shown

- The commit hash of the site's code, in the page's `<head>`, and optionally in a
  visible build line.
- Nothing else. The package prints no address, telephone number, cookie or
  personal data.

## What the operator should check

- The web server's access logs are outside the package. They are covered by the
  site's general privacy policy.
- The CI log of the tools contains the URLs the link check and the smoke test
  requested. These are public URLs of the site and of the services named in its
  content. Secrets are never printed.
- Any service that the site itself embeds (analytics, maps, forms) is outside
  the package and needs its own description.
