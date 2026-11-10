#!/usr/bin/env bash
#
# test-test-js-deps.sh
#
# Self-proving spec for bin/test-js-deps.sh, the helpers test-js.sh uses to decide
# whether the shared install can serve a test run without installing again.
#
# The currency check is run against a real pnpm on a scratch workspace, because
# what it relies on is pnpm's own behavior: `install --frozen-lockfile
# --lockfile-only` refuses when a manifest's specifiers differ from those the
# lockfile recorded, and replaces the lockfile by rename when it passes. The second
# is why the check must never run in place; the "real tree untouched" case pins it.
# Those cases are skipped, with a notice, on a host without pnpm.
#
# Run: bash bin/tests/test-test-js-deps.sh

set -uo pipefail # not -e: several cases assert a non-zero status.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

WORK=$(mktemp -d -t test-js-deps-XXXXXX)
trap 'chmod -R u+rwx "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT

# shellcheck source=../test-js-deps.sh
source "$BIN_DIR/test-js-deps.sh"

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

# The check's scratch directories land here, so the cleanup cases can see them.
export TMPDIR="$WORK/tmp"
mkdir -p "$TMPDIR"
# Node keeps a compile cache in TMPDIR; it is not the check's to clean.
scratch_left() { ls -A "$TMPDIR" | grep -v "^node-compile-cache$"; }

# --- Currency check, against a real pnpm -------------------------------------

if command -v pnpm >/dev/null 2>&1; then
	WS="$WORK/ws"
	mkdir -p "$WS/packages/a" "$WS/patches"
	cat > "$WS/package.json" <<'JSON'
{ "name": "ws", "private": true, "packageManager": "pnpm@10.33.0" }
JSON
	printf "packages:\n  - 'packages/*'\n" > "$WS/pnpm-workspace.yaml"
	cat > "$WS/packages/a/package.json" <<'JSON'
{ "name": "a", "version": "1.0.0", "dependencies": { "is-number": "7.0.0" } }
JSON
	cp "$WS/packages/a/package.json" "$WORK/a.pristine"
	(cd "$WS" && pnpm install > "$WORK/install.log" 2>&1) || { echo "FAIL - scratch install"; cat "$WORK/install.log"; exit 1; }
	LOCK="$WS/node_modules/.pnpm/lock.yaml"

	run_check() { # <installed-lock>
		out=$(deps_check "$WS" "$1" pnpm)
		status=$?
	}
	reset_manifest() { cp "$WORK/a.pristine" "$WS/packages/a/package.json"; }
	set_dep() { # <json fragment for the dependencies object>
		printf '{ "name": "a", "version": "1.0.0", "dependencies": { %s } }\n' "$1" > "$WS/packages/a/package.json"
	}

	lock_inode=$(ls -i "$WS/pnpm-lock.yaml" | awk '{print $1}')
	touch "$WORK/before-checks"
	sleep 1

	run_check "$LOCK"
	check "check: current is exit 0" "0" "$status"
	scratch_left > "$WORK/tmp-after-0"
	check "check: scratch dir removed after exit 0" "" "$(cat "$WORK/tmp-after-0")"

	set_dep '"is-number": "7.0.0", "is-odd": "3.0.1"'
	run_check "$LOCK"
	check "check: new dependency is exit 1" "1" "$status"
	check "check: new dependency names pnpm's error" "yes" \
		"$(grep -q ERR_PNPM_OUTDATED_LOCKFILE <<<"$out" && echo yes || echo no)"

	set_dep '"is-number": "6.0.0"'
	run_check "$LOCK"
	check "check: re-pinned version is exit 1" "1" "$status"

	set_dep '"is-number": "^7.0.0"'
	run_check "$LOCK"
	check "check: reworded range is exit 1" "1" "$status"
	reset_manifest

	run_check "$WORK/no-such-lock.yaml"
	check "check: missing installed lockfile is exit 2" "2" "$status"
	check "check: scratch dir removed after exit 2" "" "$(scratch_left)"

	cp "$WS/pnpm-workspace.yaml" "$WORK/ws-yaml.pristine"
	printf "packages:\n  - 'packages/**'\n" > "$WS/pnpm-workspace.yaml"
	run_check "$LOCK"
	check "check: unsupported workspace pattern is exit 2" "2" "$status"
	cp "$WORK/ws-yaml.pristine" "$WS/pnpm-workspace.yaml"

	chmod 000 "$WS/packages/a/package.json"
	run_check "$LOCK"
	check "check: copy failure is exit 2" "2" "$status"
	chmod 644 "$WS/packages/a/package.json"
	check "check: scratch dir removed after a copy failure" "" "$(scratch_left)"

	# Restore the manifests' bytes, then confirm nothing else in the real tree
	# moved. The edits above touched only packages/a/package.json and the workspace
	# yaml, so those two are excluded from the newer-than list.
	written=$(find "$WS" -newer "$WORK/before-checks" -type f \
		! -path "$WS/packages/a/package.json" ! -path "$WS/pnpm-workspace.yaml" | sort)
	check "check: real tree untouched" "" "$written"
	check "check: lockfile not replaced" "$lock_inode" "$(ls -i "$WS/pnpm-lock.yaml" | awk '{print $1}')"

	# Lockfile updated by hand, never installed: the installed copy is now older.
	set_dep '"is-number": "7.0.0", "is-odd": "3.0.1"'
	(cd "$WS" && pnpm install --lockfile-only > "$WORK/lo.log" 2>&1)
	run_check "$LOCK"
	check "check: lockfile updated but not installed is exit 1" "1" "$status"
	reset_manifest
else
	echo "skip - currency-check cases: no pnpm on this host"
fi

# --- Env detection -----------------------------------------------------------

env_status() { (WP_CACHE_KEY_SALT="$1" in_isolated_env); echo $?; }
check "env: env_x_ is isolated" "0" "$(env_status env_x_)"
check "env: unset is not" "1" "$(unset WP_CACHE_KEY_SALT; in_isolated_env; echo $?)"
check "env: other prefix is not" "1" "$(env_status prod_)"

# --- Branch root lookup ------------------------------------------------------

export WORKTREES_PATH="$WORK/worktrees"
for b in b1 b2; do
	mkdir -p "$WORKTREES_PATH/$b/plugins/u" "$WORKTREES_PATH/$b/node_modules/.pnpm"
	: > "$WORKTREES_PATH/$b/node_modules/.pnpm/lock.yaml"
done
mkdir -p "$WORK/mounts/plugins"
ln -s "$WORKTREES_PATH/b2/plugins/u" "$WORK/mounts/plugins/u"
check "branch: matches the -ef volume" "$WORKTREES_PATH/b2" "$(branch_root_for "$WORK/mounts/plugins/u")"

rm "$WORKTREES_PATH/b2/node_modules/.pnpm/lock.yaml"
check "branch: no lockfile, no branch root" "" "$(branch_root_for "$WORK/mounts/plugins/u")"

check "branch: no worktrees dir is empty" "" \
	"$(WORKTREES_PATH="$WORK/none" branch_root_for "$WORK/mounts/plugins/u")"

# --- Pinned pnpm -------------------------------------------------------------

mkdir -p "$WORK/root-pnpm" "$WORK/root-yarn"
echo '{ "packageManager": "pnpm@10.33.0" }' > "$WORK/root-pnpm/package.json"
echo '{ "packageManager": "yarn@4.0.0" }' > "$WORK/root-yarn/package.json"
check "pnpm cmd: reads packageManager" "corepack pnpm@10.33.0" "$(root_pnpm_cmd "$WORK/root-pnpm")"
check "pnpm cmd: yarn is exit 1" "1" "$(root_pnpm_cmd "$WORK/root-yarn" >/dev/null; echo $?)"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
