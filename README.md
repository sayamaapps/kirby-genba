# Genba

Operations checks for a deployed [Kirby CMS](https://getkirby.com) site: the build stamp in the HTML, pre-deploy checks, a post-deploy smoke test and a check of external links.

Genba checks one deployed site from the inside, on every deploy. A status code alone proves little, so the smoke test measures the content: an old build or a half upload does not pass. The tools are command-line programs for CI; the Kirby part is a small plugin. No styling.

## Features

- Build stamp: the deployed commit hash in `<meta name="build">`, so a check can see that the new code is live.
- Pre-deploy checks: Content Security Policy (no inline styles, handlers or foreign hosts) and required built files.
- Post-deploy smoke test: build, content, delivery and Panel state of a live site or a preview.
- Link check: where YouTube, Digistore24 and purchase links really lead.
- No silent passes: a check that cannot run exits 2 or prints a visible `SKIPPED` line.
- Off by default in Kirby, no cookies and no third-party requests; texts in English and German.

## Requirements

- PHP 8.2 or newer with `curl`, `json`, `mbstring` and `dom` (declared by the package).
- `genba-smoke` needs PHP 8.4 or newer on the machine that runs it, normally the CI runner.
- bash 3.2 or newer, `curl` and `git` for the commit hash.
- Kirby 5, for the plugin part only.

## Installation

```sh
composer require sayamaapps/kirby-genba
```

Until the package is on Packagist, add the repository to `composer.json` first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sayamaapps/kirby-genba" }]
```

Composer installs the plugin into `site/plugins/genba/` (through `getkirby/composer-installer`, a dependency of this package) and links the five tools into `vendor/bin/`. Add `/site/plugins/genba` to `.gitignore` and deploy it like `kirby/` and `vendor/`.

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
| `genba-precheck` | The built site obeys the Content Security Policy; required files exist | After the build, before the upload | 0 passed · 1 findings · 2 cannot run |
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

Run `genba-links` on its own schedule rather than in the deploy, so a slow YouTube does not block a release.

## Options

The Kirby part reads these options from `site/config/config.php`. Flat (`'genba.active' => true`) and nested spellings both work; `null` means "not set".

| Option | Default | Meaning |
|---|---|---|
| `genba.active` | `false` | Master switch. Only a real `true` enables the snippets and site methods. The tools do not read it. |
| `genba.stamp.file` | `'site/config/build.txt'` | Stamp file, absolute or relative to the project root. |
| `genba.stamp.meta` | `'build'` | `name` of the `<meta>` element. Only `[A-Za-z0-9_-]+` is accepted. |
| `genba.language` | the site language, else `en` | `en` or `de`. A German single-language site without a `locale` must set `'de'`. |

A broken value is logged once; with Kirby's `debug` option on, it throws instead. The tools' settings, checks and rules are in [docs/reference.md](docs/reference.md).

## Privacy

Genba processes no visitor data. It sets no cookies, uses no browser storage and makes no request to a third party from a visitor's browser. The stamp file holds only a commit hash.

## Documentation

- [docs/reference.md](docs/reference.md): every setting of the tools, the checks of `genba-smoke`, the rules of `genba-precheck`, the link kinds and the test list.
- [docs/messages.md](docs/messages.md): every line the tools print.

## Maintenance

Tests: `composer test`. Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's private vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## License

MIT, see [LICENSE](LICENSE).
