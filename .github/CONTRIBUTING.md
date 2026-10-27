# Contributing

Bug reports, feature requests and pull requests are welcome. Support is provided on a best-effort basis.

## Before you start

Open an issue first for anything beyond a small fix, so the change can be discussed before work starts.

## Setup

```sh
git clone https://github.com/sayamaapps/kirby-genba.git
cd kirby-genba
composer install
```

To try the plugin on a site, link or copy the folder to `site/plugins/genba/`.

## Checks

Run these before you open a pull request. CI runs the same checks on PHP 8.2 to 8.5.

```sh
composer test                     # PHPUnit
composer analyze:psalm            # Psalm
composer analyze:phpmd            # PHP Mess Detector
composer analyze:unused           # unused Composer packages
composer analyze:phpcpd           # duplicated code (needs phpcpd 6.0.3 on the PATH, see CI)
composer analyze:require-checker  # undeclared dependencies
composer normalize:check          # composer.json layout
composer check-platform-reqs       # PHP and extensions of the platform
```

Code style is checked with PHP-CS-Fixer and the Kirby rule set (`getkirby/php-cs`). The package is installed globally, because it needs PHP 8.3 or newer and this package supports PHP 8.2:

```sh
composer global require getkirby/php-cs:0.2.1
kirby-cs fix --dry-run --diff     # the global bin directory must be in PATH
```

## Conventions

- Tabs for indentation, LF line endings. `.editorconfig` lists the rest.
- Classes live in `src/`, one class per file. The class map in `autoload.php` lists them, and `tests/AutoloadTest.php` checks it.
- Keep public names stable. A rename gets a deprecation wrapper first, see the README section Deprecations.
- Every class throws the PHP exception classes it threw in 1.x (`\InvalidArgumentException`, `\RuntimeException` …), also the ones bound to Kirby. Kirby's own exception classes do not extend them, so a change needs a major version.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `style:`, `chore:`).

## Pull requests

Describe what changes and why. Add or update tests for a bug fix or a feature. Use the pull request template.
