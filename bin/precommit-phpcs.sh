#!/bin/sh
# Pre-commit PHP lint helper (invoked by lint-staged for staged *.php files).
#
# Runs PHP_CodeSniffer against the staged PHP files using the shared root
# ruleset (phpcs.xml). If the WP coding standards aren't installed — i.e. root
# `composer install` was never run — it fails with an actionable hint instead
# of the raw "Referenced sniff ... does not exist" / "No such file" output the
# bare `composer phpcs` would otherwise emit.
set -e

ROOT="$(git rev-parse --show-toplevel)"
PHPCS="$ROOT/vendor/bin/phpcs"

if [ ! -x "$PHPCS" ]; then
	echo "" >&2
	echo "✖ Pre-commit PHP lint can't run: PHP_CodeSniffer + the WP coding standards" >&2
	echo "  aren't installed at the workspace root." >&2
	echo "  Fix:    composer install   (run once at $ROOT)" >&2
	echo "  Bypass: git commit --no-verify" >&2
	echo "" >&2
	exit 1
fi

# No files passed (e.g. a manual no-arg invocation) — exit cleanly instead of
# falling through to phpcs, which would read stdin and appear to hang.
[ "$#" -eq 0 ] && exit 0

exec "$PHPCS" --standard="$ROOT/phpcs.xml" "$@"
