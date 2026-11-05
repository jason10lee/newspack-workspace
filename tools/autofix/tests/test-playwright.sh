#!/bin/bash
# playwright-env.sh tells a browser drive which Playwright to load and, when the
# Chromium build that Playwright expects is not installed, which installed one
# to launch instead. A fake workspace and browser cache stand in for the real
# ones.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
H="$(cd ../bin/lib && pwd)/playwright-env.sh"

W="$(cd "$(mktemp -d)" && pwd -P)"; B="$(cd "$(mktemp -d)" && pwd -P)"
pw() { # version revision
  local d="$W/node_modules/.pnpm"
  mkdir -p "$d/playwright@$1/node_modules/playwright" "$d/playwright-core@$1/node_modules/playwright-core"
  printf '{"browsers":[{"name":"chromium","revision":"%s"},{"name":"chromium-headless-shell","revision":"%s"}]}\n' "$2" "$2" \
    > "$d/playwright-core@$1/node_modules/playwright-core/browsers.json"
}
shell() { # revision
  local d="$B/chromium_headless_shell-$1/chrome-headless-shell-mac-arm64"
  mkdir -p "$d"; : > "$d/chrome-headless-shell"; chmod +x "$d/chrome-headless-shell"
}
run() { AUTOFIX_WORKSPACE_ROOT="$W" PLAYWRIGHT_BROWSERS_PATH="$B" bash "$H"; }

pw 1.59.1 1217
shell 1223; shell 1248
out="$(run 2>"$W/err")"; rc=$?
assert_eq 0 "$rc" "a missing expected build falls back"
eval "$out"
assert_eq "$W/node_modules/.pnpm/playwright@1.59.1/node_modules/playwright" "$PW_PATH" "to the workspace's Playwright"
assert_eq "$B/chromium_headless_shell-1248/chrome-headless-shell-mac-arm64/chrome-headless-shell" "$CHROME_PATH" "and the newest installed headless shell"
assert_contains "$(cat "$W/err")" "1217" "and says which build it expected"

shell 1217
out="$(run 2>"$W/err")"; eval "$out"
assert_eq "" "$CHROME_PATH" "with the expected build installed, Playwright launches its own"

pw 1.60.0 1300; shell 1300
out="$(run 2>/dev/null)"; eval "$out"
assert_contains "$PW_PATH" "playwright@1.60.0" "the newest workspace Playwright wins"

E="$(cd "$(mktemp -d)" && pwd -P)"
out="$(AUTOFIX_WORKSPACE_ROOT="$W" PLAYWRIGHT_BROWSERS_PATH="$E" bash "$H" 2>&1)"; rc=$?
assert_eq 2 "$rc" "no headless shell at all is refused"
assert_contains "$out" "playwright install" "and names the install command"

out="$(AUTOFIX_WORKSPACE_ROOT="$E" PLAYWRIGHT_BROWSERS_PATH="$B" bash "$H" 2>&1)"; rc=$?
assert_eq 2 "$rc" "a workspace without Playwright is refused"

finish
