#!/usr/bin/env bash
#
# test-test-js-install-policy.sh
#
# Self-proving spec for when bin/test-js.sh installs. The root's node_modules is
# shared by the main container and every isolated env, and an install from an env
# resolves that env's branch manifests, so it rewrites the shared root to follow one
# branch. test-js.sh used to install on every run, from anywhere.
#
# Now: the main container installs only when the check says the root is stale (or
# the check cannot run); an isolated env never installs and refuses a stale run with
# (cause: deps-stale); and an env with a branch volume runs its tests from that
# branch's own worktree. The package managers are stubs; the check's own behavior is
# pinned in test-test-js-deps.sh.
#
# Run: bash bin/tests/test-test-js-install-policy.sh

set -uo pipefail # not -e: the refusal cases assert a non-zero exit.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

WORK=$(mktemp -d -t test-js-policy-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

ROOT="$WORK/root"
make_workspace() { # <dir>
	mkdir -p "$1/plugins/u" "$1/node_modules/.pnpm"
	echo '{ "name": "ws", "private": true, "packageManager": "pnpm@10.33.0" }' > "$1/package.json"
	printf "packages:\n  - 'plugins/*'\n" > "$1/pnpm-workspace.yaml"
	echo '{ "name": "u", "scripts": { "test": "jest" } }' > "$1/plugins/u/package.json"
	: > "$1/node_modules/.pnpm/lock.yaml"
}
make_workspace "$ROOT"
make_workspace "$WORK/worktrees/b"
mkdir -p "$WORK/mounts"
ln -s "$WORK/worktrees/b/plugins/u" "$WORK/mounts/u"

# Stubs log every call with its cwd and NODE_PATH. The check is the one call that
# carries --frozen-lockfile; its result comes from STUB_CHECK_RC (1 prints pnpm's
# stale error, anything else non-zero prints an unrelated one).
mkdir -p "$WORK/stub"
for pm in pnpm corepack; do
	cat > "$WORK/stub/$pm" <<STUB
#!/usr/bin/env bash
case " \$* " in
	*" --frozen-lockfile "*)
		echo "check" >> "$WORK/calls.log"
		rc=\${STUB_CHECK_RC:-0}
		[ "\$rc" = 1 ] && echo " ERR_PNPM_OUTDATED_LOCKFILE  Cannot install with \"frozen-lockfile\" because pnpm-lock.yaml is not up to date"
		[ "\$rc" != 0 ] && [ "\$rc" != 1 ] && echo " ENOENT  something else went wrong"
		exit \$rc ;;
esac
echo "$pm cwd=\$PWD NODE_PATH=\${NODE_PATH:-} args=\$*" >> "$WORK/calls.log"
echo "stub test output"
exit 0
STUB
	chmod +x "$WORK/stub/$pm"
done
export PATH="$WORK/stub:$PATH"
export MONOREPO_ROOT="$ROOT" WORKTREES_PATH="$WORK/worktrees"
export THEMES_PATH="$WORK/themes" REPOS_PATH="$WORK/repos"

failures=0
check() {
	local desc="$1" want="$2" got="$3"
	if [[ "$want" == "$got" ]]; then
		echo "ok   - $desc"
	else
		echo "FAIL - $desc: wanted [$want], got [$got]"
		failures=$((failures + 1))
	fi
}
has() { grep -qE -- "$1" "$2" && echo yes || echo no; }

# run_case <plugins-path> <salt> <check-rc> [compact]
run_case() {
	: > "$WORK/calls.log"
	rm -rf "$ROOT/logs"
	(
		export PLUGINS_PATH="$1" STUB_CHECK_RC="$3"
		if [ -n "$2" ]; then export WP_CACHE_KEY_SALT="$2"; else unset WP_CACHE_KEY_SALT; fi
		if [ "${4:-}" = compact ]; then export NEWSPACK_TEST_OUTPUT=compact; else unset NEWSPACK_TEST_OUTPUT; fi
		bash "$BIN_DIR/test-js.sh" u
	) > "$WORK/out" 2>&1
	status=$?
}
installs() { has '^pnpm .*args=install$' "$WORK/calls.log"; }
tests_ran() { has 'args=--filter u run test' "$WORK/calls.log"; }

run_case "$ROOT/plugins" "" 0
check "main current: exit 0" "0" "$status"
check "main current: no install" "no" "$(installs)"
check "main current: test runs" "yes" "$(tests_ran)"

run_case "$ROOT/plugins" "" 1
check "main stale: announces" "yes" "$(has 'Root dependencies are stale; installing' "$WORK/out")"
check "main stale: installs" "yes" "$(installs)"
check "main stale: test runs" "yes" "$(tests_ran)"

run_case "$ROOT/plugins" "" 254
check "main check error: announces" "yes" "$(has 'Could not check root dependencies' "$WORK/out")"
check "main check error: installs" "yes" "$(installs)"
check "main check error: test runs" "yes" "$(tests_ran)"

run_case "$ROOT/plugins" "env_x_" 1
check "env stale: exit 1" "1" "$status"
check "env stale: cause tag printed" "yes" "$(has '\(cause: deps-stale\)' "$WORK/out")"
check "env stale: names pnpm's error" "yes" "$(has 'ERR_PNPM_OUTDATED_LOCKFILE' "$WORK/out")"
check "env stale: no install" "no" "$(installs)"
check "env stale: no test" "no" "$(tests_ran)"

run_case "$ROOT/plugins" "env_x_" 254
check "env check error: exit 1" "1" "$status"
check "env check error: cause tag printed" "yes" "$(has '\(cause: deps-stale\)' "$WORK/out")"
check "env check error: no install" "no" "$(installs)"

run_case "$ROOT/plugins" "env_x_" 1 compact
check "env stale compact: exit 1" "1" "$status"
check "env stale compact: refusal on the terminal" "yes" "$(has '\(cause: deps-stale\)' "$WORK/out")"
log=$(ls "$ROOT"/logs/test-js/u-*/output.log 2>/dev/null | head -1)
check "env stale compact: log holds the refusal" "yes" "$([ -n "$log" ] && has '\(cause: deps-stale\)' "$log" || echo no)"

run_case "$ROOT/plugins" "env_x_" 0
check "env current, no branch volume: no install" "no" "$(installs)"
check "env current, no branch volume: test from MONOREPO_ROOT" "yes" \
	"$(has "^pnpm cwd=$ROOT NODE_PATH= args=--filter u run test$" "$WORK/calls.log")"

run_case "$WORK/mounts" "env_x_" 0
B="$WORK/worktrees/b"
check "env current, branch volume: exit 0" "0" "$status"
check "env current, branch volume: no install" "no" "$(installs)"
check "env current, branch volume: test from the branch root with its NODE_PATH and pinned pnpm" "yes" \
	"$(has "^corepack cwd=$B NODE_PATH=$B/node_modules/.pnpm/node_modules args=pnpm@10.33.0 --filter u run test$" "$WORK/calls.log")"

run_case "$ROOT/plugins" "" 0
log=$(ls "$ROOT"/logs/test-js/u-*/output.log 2>/dev/null | head -1)
check "full mode logs the check and the test exit" "yes,yes" \
	"$([ -n "$log" ] && has "^test-js: check current" "$log" || echo no),$([ -n "$log" ] && has "^test-js: test exit 0" "$log" || echo no)"

run_case "$ROOT/plugins" "" 1
log=$(ls "$ROOT"/logs/test-js/u-*/output.log 2>/dev/null | head -1)
check "full mode logs the install" "yes" "$([ -n "$log" ] && has "^test-js: install ran \\(exit 0\\)" "$log" || echo no)"
check "full mode still prints the test output" "yes" "$(has 'stub test output' "$WORK/out")"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
