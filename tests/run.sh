#!/bin/sh
# Runs all checks of the package: `composer test` or `sh tests/run.sh`.
# Every tests/*Test.php is a standalone script (exit code 0 = pass), not
# PHPUnit — so they also run on a hoster PHP without a test framework. All
# files run even when one fails; the exit code is 1 if any failed.
#
# The smoke test (bin/genba-smoke, bash) is exercised by tests/SmokeTest.php,
# which starts PHP's built-in server as the fake site and runs the real
# script against it — so bash and curl must be installed, as on every runner.
cd "$(dirname "$0")/.." || exit 1
echo "PHP $(php -r 'echo PHP_VERSION;')"
failed=0
for f in tests/*Test.php; do
    [ -e "$f" ] || continue
    echo "→ $f"
    php "$f" || failed=1
done
exit "$failed"
