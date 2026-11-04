#!/bin/bash
# jest-related.js runs Jest with newspack-scripts' own configuration, limited to
# tests related to the files it is given. A fake newspack-scripts and a fake
# wp-scripts stand in for the real ones, so no Jest runs here: the fake
# wp-scripts records the arguments it was started with.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
J="$(cd ../bin/lib && pwd)/jest-related.js"

P="$(cd "$(mktemp -d)" && pwd -P)"; LOG="$P/argv.log"; export JEST_FAKE_LOG="$LOG"
mkdir -p "$P/node_modules/newspack-scripts/scripts" "$P/node_modules/cross-spawn" "$P/bin"
cat > "$P/bin/wp-scripts.js" <<'EOF'
#!/usr/bin/env node
require( 'fs' ).appendFileSync( process.env.JEST_FAKE_LOG, JSON.stringify( { argv: process.argv.slice( 2 ), env: process.env.BABEL_ENV } ) + '\n' );
process.exit( Number( process.env.JEST_FAKE_STATUS || 0 ) );
EOF
# The real cross-spawn would start the full suite; the helper must replace it.
cat > "$P/node_modules/cross-spawn/index.js" <<'EOF'
module.exports = { sync() { require( 'fs' ).appendFileSync( process.env.JEST_FAKE_LOG, 'REAL-CROSS-SPAWN\n' ); return { status: 0 }; } };
EOF
printf '{"name":"cross-spawn","main":"index.js"}\n' > "$P/node_modules/cross-spawn/package.json"
printf '{"name":"newspack-scripts"}\n' > "$P/node_modules/newspack-scripts/package.json"
# Shaped like the real scripts/test.js: user args first, then the subcommand and config.
cat > "$P/node_modules/newspack-scripts/scripts/test.js" <<EOF
'use strict';
process.env.BABEL_ENV = 'test';
const spawn = require( 'cross-spawn' );
const args = process.argv.slice( 2 );
args.push( 'test-unit-js' );
args.push( '--config', JSON.stringify( { rootDir: process.cwd() } ) );
const result = spawn.sync( '$P/bin/wp-scripts.js', args, { stdio: 'inherit' } );
process.exit( result.status );
EOF

run() { (cd "$P" && node "$J" "$@"); }

: > "$LOG"; run src/a.js src/b.test.js >/dev/null 2>&1; rc=$?
assert_eq 0 "$rc" "a passing run exits 0"
line="$(head -1 "$LOG")"
assert_eq 0 "$(grep -c REAL-CROSS-SPAWN "$LOG")" "newspack-scripts' own spawn never runs"
assert_eq test-unit-js "$(jq -r '.argv[0]' <<<"$line")" "the subcommand comes first, so wp-scripts reads it"
assert_eq --config "$(jq -r '.argv[1]' <<<"$line")" "then newspack-scripts' config"
assert_eq "$P" "$(jq -r '.argv[2] | fromjson | .rootDir' <<<"$line")" "unchanged"
assert_contains "$(jq -r '.argv | join(" ")' <<<"$line")" "--maxWorkers=1" "with one worker"
assert_contains "$(jq -r '.argv | join(" ")' <<<"$line")" "--findRelatedTests src/a.js src/b.test.js" "limited to tests related to the given files"
assert_eq test "$(jq -r '.env' <<<"$line")" "and the environment newspack-scripts sets"

: > "$LOG"; JEST_FAKE_STATUS=1 run src/a.js >/dev/null 2>&1; rc=$?
assert_eq 1 "$rc" "a failing run exits non-zero"

: > "$LOG"; out="$(run 2>&1)"; rc=$?
assert_eq 2 "$rc" "no files is refused"
assert_contains "$out" "whole suite" "and says why"
assert_eq "" "$(cat "$LOG")" "and nothing runs"

E="$(mktemp -d)"; out="$( (cd "$E" && node "$J" src/a.js) 2>&1)"; rc=$?
assert_eq 2 "$rc" "a package without newspack-scripts is refused"
assert_contains "$out" "newspack-scripts" "and names what is missing"

finish
