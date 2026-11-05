#!/bin/bash
# playwright-env.sh — print PW_PATH and CHROME_PATH as shell assignments, for a
# browser drive to `eval` before it starts:
#
#   eval "$(bash tools/autofix/bin/lib/playwright-env.sh)"
#   const { chromium } = require( process.env.PW_PATH );
#   chromium.launch( { executablePath: process.env.CHROME_PATH || undefined } );
#
# PW_PATH is the workspace's own Playwright, the newest when several are
# installed. Each Playwright release expects one Chromium build, and the browser
# cache this machine shares with other tools often holds only newer ones, so
# `chromium.launch()` fails looking for a build that is not there. When the
# expected headless shell is installed, CHROME_PATH is empty and Playwright
# launches its own; otherwise it names the newest installed headless shell, and
# a note on stderr says which build was expected. Nothing is downloaded.
set -uo pipefail
root="${AUTOFIX_WORKSPACE_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)}"
case "$(uname -s)" in
  Darwin) default_cache="$HOME/Library/Caches/ms-playwright" ;;
  *) default_cache="$HOME/.cache/ms-playwright" ;;
esac
cache="${PLAYWRIGHT_BROWSERS_PATH:-$default_cache}"

pw="$(find "$root/node_modules/.pnpm" -maxdepth 1 -type d -name 'playwright@*' 2>/dev/null | sort -V | tail -1)"
[ -n "$pw" ] && [ -d "$pw/node_modules/playwright" ] \
  || { echo "playwright-env: no Playwright installed in $root/node_modules; run pnpm install there" >&2; exit 2; }
version="${pw##*/playwright@}"
want="$(jq -r '.browsers[] | select(.name == "chromium-headless-shell") | .revision' \
  "$root/node_modules/.pnpm/playwright-core@$version/node_modules/playwright-core/browsers.json" 2>/dev/null)"

chrome=""
if [ -z "$want" ] || ! [ -d "$cache/chromium_headless_shell-$want" ]; then
  newest="$(find "$cache" -maxdepth 1 -type d -name 'chromium_headless_shell-*' 2>/dev/null | sort -V | tail -1)"
  [ -n "$newest" ] && chrome="$(find "$newest" -maxdepth 3 -type f -name 'chrome-headless-shell' 2>/dev/null | head -1)"
  [ -n "$chrome" ] || { echo "playwright-env: no Chromium headless shell in $cache; run \`npx playwright install chromium-headless-shell\` from $root" >&2; exit 2; }
  echo "playwright-env: Playwright $version expects headless shell ${want:-unknown}, which is not installed; using ${newest##*-}" >&2
fi

printf 'PW_PATH=%q\nCHROME_PATH=%q\nexport PW_PATH CHROME_PATH\n' "$pw/node_modules/playwright" "$chrome"
