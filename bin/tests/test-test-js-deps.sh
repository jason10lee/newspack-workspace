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

	# A manifest the copy never reaches drops out of pnpm's comparison: the frozen
	# check passes when a lockfile importer has no manifest. So every way of missing
	# one has to read as "could not check", never as current. Each case below leaves
	# plugins/u stale, so a silent skip would show up as exit 0.
	WS2="$WORK/ws2"
	mkdir -p "$WS2/packages/a" "$WS2/plugins/u"
	cp "$WS/package.json" "$WS2/"
	printf "packages:\n  - 'packages/*'\n  - 'plugins/*'\n" > "$WS2/pnpm-workspace.yaml"
	cp "$WORK/a.pristine" "$WS2/packages/a/package.json"
	echo '{ "name": "u", "version": "1.0.0", "dependencies": { "is-number": "7.0.0" } }' > "$WS2/plugins/u/package.json"
	(cd "$WS2" && pnpm install > "$WORK/install2.log" 2>&1) || { echo "FAIL - second scratch install"; cat "$WORK/install2.log"; exit 1; }
	cp "$WS2/pnpm-workspace.yaml" "$WORK/ws2-yaml.pristine"
	echo '{ "name": "u", "version": "1.0.0", "dependencies": { "is-number": "6.0.0" } }' > "$WS2/plugins/u/package.json"
	run_check2() { out=$(deps_check "$WS2" "$WS2/node_modules/.pnpm/lock.yaml" pnpm); status=$?; }

	run_check2
	check "check: stale second unit is exit 1 (control)" "1" "$status"

	printf "packages:\n  - 'packages/*'\n# units below\n  - 'plugins/*'\n" > "$WS2/pnpm-workspace.yaml"
	run_check2
	check "check: comment inside packages is not current" "2" "$status"

	printf "packages: ['packages/*', 'plugins/*']\n" > "$WS2/pnpm-workspace.yaml"
	run_check2
	check "check: flow-style packages is not current" "2" "$status"
	cp "$WORK/ws2-yaml.pristine" "$WS2/pnpm-workspace.yaml"

	chmod 000 "$WS2/plugins/u"
	run_check2
	check "check: unreadable unit directory is not current" "2" "$status"
	chmod 755 "$WS2/plugins/u"

	mv "$WS2/plugins/u/package.json" "$WORK/u.off"
	run_check2
	check "check: unit without package.json is not current" "2" "$status"
	mv "$WORK/u.off" "$WS2/plugins/u/package.json"
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

# --- Branch packages ---------------------------------------------------------
#
# A branch-path run tests against the branch's own packages/*, so a package whose
# source changed after its last build has to be rebuilt first, or the run tests
# the old build. The build is a stub here: what matters is when it runs and where.

BR="$WORK/branch"
MR="$WORK/monorepo"
mkdir -p "$BR/packages/built/src" "$BR/packages/built/dist" "$BR/packages/plain" \
	"$MR/packages/built/node_modules" "$MR/packages/plain/node_modules"
echo '{ "name": "built", "scripts": { "build": "x" } }' > "$BR/packages/built/package.json"
echo '{ "name": "plain" }' > "$BR/packages/plain/package.json"
: > "$MR/packages/built/node_modules/from-root"
: > "$MR/packages/plain/node_modules/from-root"
touch -t 202601010000 "$BR/packages/built/src/index.js"
touch -t 202601020000 "$BR/packages/built/dist/index.js"

cat > "$WORK/stub-pnpm" <<STUB
#!/usr/bin/env bash
echo "cwd=\$PWD NODE_PATH=\${NODE_PATH:-} args=\$*" >> "$WORK/build.log"
exit \${STUB_BUILD_RC:-0}
STUB
chmod +x "$WORK/stub-pnpm"

stale_status() { branch_packages_stale "$BR"; echo $?; }

check "stale: dist newer than src" "1" "$(stale_status)"
touch -t 202601030000 "$BR/packages/built/src/index.js"
check "stale: src newer than dist" "0" "$(stale_status)"
touch -t 202601010000 "$BR/packages/built/src/index.js"
mv "$BR/packages/built/dist" "$BR/packages/built/dist.off"
check "stale: dist missing" "0" "$(stale_status)"
mv "$BR/packages/built/dist.off" "$BR/packages/built/dist"
# packages/plain has no build script and no dist/; it must not count.
check "stale: package without build script ignored" "1" "$(stale_status)"

mkdir -p "$BR/packages/plain/node_modules"
: > "$BR/packages/plain/node_modules/branch-own"
: > "$WORK/build.log"
prepare_branch_packages "$BR" "$MR" "$WORK/stub-pnpm"
check "prepare: copies missing node_modules" "yes" \
	"$([ -e "$BR/packages/built/node_modules/from-root" ] && echo yes || echo no)"
check "prepare: leaves existing node_modules untouched" "branch-own" \
	"$(ls -A "$BR/packages/plain/node_modules" | tr "\n" " " | sed "s/ \$//")"
check "prepare: no build when fresh" "" "$(cat "$WORK/build.log")"

touch -t 202601030000 "$BR/packages/built/src/index.js"
prepare_branch_packages "$BR" "$MR" "$WORK/stub-pnpm"
check "prepare: builds when stale, from the branch root, with NODE_PATH" \
	"cwd=$BR NODE_PATH=$BR/node_modules/.pnpm/node_modules args=--filter ./packages/* run --if-present build" \
	"$(cat "$WORK/build.log")"

check "prepare: build failure is non-zero" "1" \
	"$(STUB_BUILD_RC=3 prepare_branch_packages "$BR" "$MR" "$WORK/stub-pnpm" >/dev/null 2>&1; [ $? -ne 0 ] && echo 1 || echo 0)"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "All cases passed."
