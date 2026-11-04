#!/bin/bash
# The suite guard denies a run's Bash call that would start a whole JS test
# suite, and leaves every other command alone.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh
HOOK=../hooks/test-suite-guard.sh
bashcall() { jq -n --arg c "$1" '{tool_name: "Bash", tool_input: {command: $c}}' | bash "$HOOK"; }
decision() { [ -n "$1" ] || { echo allow; return; }; jq -r '.hookSpecificOutput.permissionDecision // "allow"' <<<"$1"; }
denies() { assert_eq deny "$(decision "$(bashcall "$1")")" "denies: $1"; }
allows() { assert_eq allow "$(decision "$(bashcall "$1")")" "allows: $1"; }

denies 'n test-js'
denies 'n test-js newspack-plugin'
denies 'cd plugins/newspack-plugin && n test-js'
denies 'npx newspack-scripts test src/other-scripts/recaptcha-checkout-block 2>&1 | tail -18'
denies 'newspack-scripts test'
denies './node_modules/.bin/wp-scripts test-unit-js --config x'
denies 'npx jest src/a.test.js'
denies 'node_modules/.bin/jest'
denies 'jest --runInBand'
denies 'npm test'
denies 'npm run test'
denies 'pnpm test'
denies 'pnpm --filter newspack-plugin test'
denies 'pnpm -F newspack-plugin run test'
denies 'pnpm -r test'
denies 'yarn test'
denies 'FOO=1 timeout 600 npm test'
denies 'W=x; cd $W; n test-php --filter A; npx newspack-scripts test src/x'
denies 'echo start && (cd p && pnpm test)'
# the workspace's root script that runs every package's whole suite
denies 'pnpm test:js'
denies 'pnpm run test:js'
denies 'npm run test:js'
# npm's aliases for test
denies 'npm t'
denies 'npm tst'
# pnpm's -w takes no value, so the word after it is the script
denies 'pnpm -w test'
denies 'pnpm -w run test:js'
# pnpm and yarn run a package binary without exec
denies 'pnpm jest src/a.test.js'
denies 'pnpm newspack-scripts test'
denies 'yarn jest'
denies 'pnpm exec jest'
# node starting a test runner's entry point
denies 'node node_modules/jest/bin/jest.js'
denies 'node ./node_modules/.bin/jest'
denies 'node node_modules/newspack-scripts/scripts/test.js'
denies 'node --max-old-space-size=4096 node_modules/@wordpress/scripts/bin/wp-scripts.js test-unit-js'

allows 'n test-php --filter Checkout_Protection'
allows 'bash tools/autofix/bin/verify.sh js forge-x src/a.js'
allows 'bash tools/autofix/bin/verify.sh suite forge-x'
allows 'node tools/autofix/bin/lib/jest-related.js src/a.js'
allows 'grep -rn jest package.json'
allows 'npm run test:php'
allows 'npm run build'
allows 'pnpm install'
allows 'cat test.js'
allows 'git commit -m "test: cover the guard"'
allows 'n test'
allows 'ls node_modules/.bin | grep jest'
allows 'npm -w newspack-plugin run build'
allows 'pnpm --filter newspack-plugin run build'
allows 'pnpm lint'
allows 'node -e "console.log(1)"'
allows 'node scripts/build.js'
allows 'yarn install'

out="$(bashcall 'npm test')"
assert_contains "$out" "verify.sh js" "the deny names the scoped helper"
assert_contains "$out" "$(cd ../../autofix/bin && pwd)/verify.sh" "at its path in this tooling"
assert_eq "" "$(jq -n '{tool_name: "Read", tool_input: {file_path: "npm test"}}' | bash "$HOOK")" "other tools pass untouched"
assert_eq "" "$(printf 'not json' | bash "$HOOK")" "unreadable input is let through: this guard is a tripwire, not a gate"

finish
