# Genba

Operations checks for a deployed [Kirby CMS](https://getkirby.com) site: the build stamp in the HTML, pre-deploy checks, a post-deploy smoke test and a check of external links.

Genba checks one deployed site from the inside, on every deploy. A status code alone proves little, so the smoke test measures the content: an old build or a half upload does not pass. The tools are command-line programs for CI; the Kirby part is a small plugin. No styling.

## Requirements

| | Supported |
|---|---|
| Kirby | 5 (the plugin part; the command-line tools need no Kirby) |
| PHP | 8.2 to 8.5 with `curl`, `dom`, `json` and `mbstring` (declared by the package) |
| `genba-smoke` | PHP 8.4 or newer on the machine that runs it, normally the CI runner |
| Other | bash 3.2 or newer, `curl` and `git` for the commit hash |

## Features

- Build stamp: the deployed commit hash in `<meta name="build">`, so a check can see that the new code is live.
- Pre-deploy checks: Content Security Policy (no inline styles, handlers or foreign hosts) and required built files; for the live deploy, no placeholder such as `[TODO]` in the content.
- Post-deploy smoke test: build, content, delivery and Panel state of a live site or a preview.
- Link check: where YouTube, Digistore24 and purchase links really lead.
- No silent passes: a check that cannot run exits 2 or prints a visible `SKIPPED` line.
- Off by default in Kirby, no cookies and no third-party requests; texts in English and German.

## Installation

Composer (recommended):

```sh
composer require sayamaapps/kirby-genba
```

Composer installs the plugin into `site/plugins/genba/` (through `getkirby/composer-installer`, a dependency of this package) and links the five tools into `vendor/bin/`. Add `/site/plugins/genba` to `.gitignore` and deploy it like `kirby/` and `vendor/`.

ZIP: download the release archive from the GitHub release page and unpack it to `site/plugins/genba/`. The archive holds the plugin and the tools, nothing else.

Git submodule:

```sh
git submodule add https://github.com/sayamaapps/kirby-genba.git site/plugins/genba
```

With the submodule the tools are in `site/plugins/genba/bin/`, run them from there.

## Usage

Switch on the Kirby part and print the stamp in `<head>` of the layout:

```php
// site/config/config.php
return [
    'genba.active' => true,
];
```

```php
<?php snippet('genba/stamp') ?>
```

Add `/site/config/build.txt` to `.gitignore`: the deploy writes it, and a committed copy would always hold an old hash.

The tools:

| Tool | Checks | When | Exit codes |
|---|---|---|---|
| `genba-preflight` | The project's generation step ran, or had nothing to run | Before `npm run build` | 0 passed or skipped · 1 hook failed · 2 cannot run |
| `genba-precheck` | The built site obeys the Content Security Policy; required files exist; with `--forbid-markers`, no placeholder in the content | After the build, before the upload | 0 passed · 1 findings · 2 cannot run |
| `genba-stamp` | Writes the deployed commit to the stamp file | Before the upload | 0 written · 1 failed · 2 usage error |
| `genba-smoke` | The live site or a preview serves the new build, with the expected content, delivery and Panel | After the upload | 0 passed or skipped · 1 findings · 2 cannot run |
| `genba-links` | Every external reference leads where the source says | Daily, on its own schedule | 0 no findings · 1 findings · 2 cannot run |

A tool never returns 0 for a check that did not happen. Exit 0 covers a visible `SKIPPED` line, exit 1 a finding, exit 2 a check that could not run.

A preview deploy, as one step in a workflow:

```yaml
- run: vendor/bin/genba-smoke
  env:
    GENBA_URL: ${{ vars.PREVIEW_URL }}
    GENBA_USER: ${{ secrets.PREVIEW_USERNAME }}
    GENBA_PASSWORD: ${{ secrets.PREVIEW_PASSWORD }}
    GENBA_EXPECT_PROTECTED: 1   # without credentials the preview must answer 401
    GENBA_NOT_READY: skip       # DNS or certificate still pending: SKIPPED, not red
```

In the live deploy, run `vendor/bin/genba-precheck --forbid-markers`: a placeholder such as `[TODO]` or `[TBD: date]` in a content file then stops the deploy before the upload. A preview leaves the switch out and may show them. Further markers of your own: `GENBA_MARKERS`, one regular expression per line.

Run `genba-links` on its own schedule rather than in the deploy, so a slow YouTube does not block a release.

## Options

The Kirby part reads these options from `site/config/config.php`. Flat (`'genba.active' => true`) and nested spellings both work; `null` means "not set".

| Option | Default | Meaning |
|---|---|---|
| `genba.active` | `false` | Master switch. Only a real `true` enables the snippets and site methods. The tools do not read it. |
| `genba.stamp.file` | `'site/config/build.txt'` | Stamp file, absolute or relative to the project root. |
| `genba.stamp.meta` | `'build'` | `name` of the `<meta>` element. Only `[A-Za-z0-9_-]+` is accepted. |
| `genba.language` | the site language, else `en` | `en` or `de`. A German single-language site without a `locale` must set `'de'`. |

A broken value is logged once; with Kirby's `debug` option on, it throws an `InvalidArgumentException` instead. The tools' settings, checks and rules are in [docs/reference.md](docs/reference.md).

The plugin's texts come from Genba's own tables (English and German), which the command-line tools share; they do not depend on Kirby's translation files. The keys `genba.*` are also registered with Kirby for blueprint labels.

## Deprecations

Deprecated in 1.x, removed in the next major version. No runtime notice is raised: each deprecation is marked `@deprecated` in the code and listed here.

| Deprecated | Replacement |
|---|---|
| `genba_option($key)` | `Sayamaapps\Genba\Options::get($kirby, $key)` |
| Classes `Genba\Stamp`, `Genba\Precheck`, `Genba\Preflight`, `Genba\Links`, `Genba\Homepage`, `Genba\Options`, `Genba\I18n`, `Genba\Log`, `Genba\Text`, `Genba\Tree` | `Sayamaapps\Genba\…` (same names; the old names stay as class aliases) |
| `Genba\Log::forget()` (test helper, `@internal`) | none |

## Planned for 2.0

Not in 1.x, because each one changes behaviour:

- Options are read as `sayamaapps.genba.*` (the same keys as `genba.*`). `genba.*` stays readable as a fallback, with a deprecation notice in debug mode.
- Runtime deprecation notices for `genba_option()`, the `Genba\` class names and the `genba.*` options.
- Broken options throw Kirby's own `Kirby\Exception\InvalidArgumentException` in debug mode. It does not extend PHP's `InvalidArgumentException`, so code that catches the PHP class would miss it.
- Removal of `genba_option()`, the `Genba\` class names and `Log::forget()`.

## Privacy

Genba processes no visitor data. It sets no cookies, uses no browser storage and makes no request to a third party from a visitor's browser. The stamp file holds only a commit hash.

## Documentation

- [docs/reference.md](docs/reference.md): every setting of the tools, the checks of `genba-smoke`, the rules of `genba-precheck`, the link kinds and the test list.
- [docs/messages.md](docs/messages.md): every line the tools print.

## Maintenance

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's private vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
