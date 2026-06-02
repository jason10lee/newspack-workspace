#!/bin/bash
#
# Unit tests for bin/migrate-standalone-repos.sh decision logic + actions.
# Pure shell + synthetic git fixtures under a temp dir; no Docker.
#
# Usage: tests/migrate-standalone-repos.test.sh   (exits non-zero on failure)
set -u

BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"
trap 'rm -rf "$FIX"' EXIT

pass=0; fail=0
ok() {
    if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass + 1));
    else echo "  FAIL  $1"; echo "        expected: [$3]"; echo "        got:      [$2]"; fail=$((fail + 1)); fi
}

# Source the script with a fixture root; main() is guarded so this is safe.
NABSPATH="$FIX" source "$BIN/migrate-standalone-repos.sh"

echo "== msr_detect_kind =="
mkdir -p "$FIX/k-theme-css"; printf 'Theme Name: Acme\n' > "$FIX/k-theme-css/style.css"
ok "style.css with Theme Name header -> theme" "$(msr_detect_kind "$FIX/k-theme-css")" "theme"
mkdir -p "$FIX/k-theme-json"; printf '{}\n' > "$FIX/k-theme-json/theme.json"
ok "root theme.json -> theme" "$(msr_detect_kind "$FIX/k-theme-json")" "theme"
mkdir -p "$FIX/k-plugin"; printf '<?php /* Plugin Name: Acme */\n' > "$FIX/k-plugin/acme.php"
ok "plugin php header -> plugin" "$(msr_detect_kind "$FIX/k-plugin")" "plugin"
mkdir -p "$FIX/k-plugin-csslib"; printf '.btn{}\n' > "$FIX/k-plugin-csslib/style.css"
ok "style.css w/o Theme Name header -> plugin" "$(msr_detect_kind "$FIX/k-plugin-csslib")" "plugin"
# F8 corroboration: theme.json AND a root Plugin Name php -> plugin, not theme.
mkdir -p "$FIX/k-plug-themejson"
printf '{}\n' > "$FIX/k-plug-themejson/theme.json"
# Canonical WP plugin header (own comment line) — matches WP's own
# ^[ \t/*#@]*Plugin Name: detection; the compact one-line form is not a
# recognized header.
printf '<?php\n/**\n * Plugin Name: Blockz\n */\n' > "$FIX/k-plug-themejson/blockz.php"
ok "theme.json + plugin header -> plugin" "$(msr_detect_kind "$FIX/k-plug-themejson")" "plugin"

echo "== msr_is_monorepo_tracked =="
mkdir -p "$FIX/plugins/tracked-plug"; printf 'x' > "$FIX/plugins/tracked-plug/f.php"
mkdir -p "$FIX/themes/tracked-theme"; printf 'x' > "$FIX/themes/tracked-theme/style.css"
mkdir -p "$FIX/plugins/empty-stub"   # empty migration stub — must NOT count
if msr_is_monorepo_tracked tracked-plug;  then ok "tracked plugin -> yes" yes yes; else ok "tracked plugin -> yes" no yes; fi
if msr_is_monorepo_tracked tracked-theme; then ok "tracked theme -> yes"  yes yes; else ok "tracked theme -> yes"  no yes; fi
if msr_is_monorepo_tracked empty-stub;    then ok "empty stub -> no" yes no;  else ok "empty stub -> no" no no; fi
if msr_is_monorepo_tracked nope;          then ok "absent -> no"     yes no;  else ok "absent -> no"     no no; fi

echo "== msr_unique_git_state =="
git init -q --bare "$FIX/origin-clean.git"
git clone -q "$FIX/origin-clean.git" "$FIX/clean" 2>/dev/null
( cd "$FIX/clean" && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init && git push -q origin HEAD:main && git branch -q --set-upstream-to=origin/main ) 2>/dev/null
ok "clean+pushed -> ''" "$(msr_unique_git_state "$FIX/clean")" ""
cp -R "$FIX/clean" "$FIX/dirty"; printf 'x' > "$FIX/dirty/uncommitted.txt"
ok "dirty tree -> dirty-working-tree" "$(msr_unique_git_state "$FIX/dirty")" "dirty-working-tree"
cp -R "$FIX/clean" "$FIX/unpushed"
( cd "$FIX/unpushed" && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m local-only ) 2>/dev/null
ok "unpushed commit -> unpushed-commits" "$(msr_unique_git_state "$FIX/unpushed")" "unpushed-commits"
mkdir -p "$FIX/notgit"
ok "non-git dir -> not-a-git-repo" "$(msr_unique_git_state "$FIX/notgit")" "not-a-git-repo"
# F7: a stash entry counts as unique state.
cp -R "$FIX/clean" "$FIX/stashed"
( cd "$FIX/stashed" && printf 'wip' > wip.txt && git add wip.txt && git stash -q ) 2>/dev/null
ok "stash entry -> stash-entries" "$(msr_unique_git_state "$FIX/stashed")" "stash-entries"

echo "== msr_content_matches_monorepo =="
mkdir -p "$FIX/plugins/dup"; printf 'same\n' > "$FIX/plugins/dup/main.php"
mkdir -p "$FIX/repos/dup/.git"; printf 'same\n' > "$FIX/repos/dup/main.php"
if msr_content_matches_monorepo dup; then ok "identical content -> match" yes yes; else ok "identical content -> match" no yes; fi
printf 'different\n' > "$FIX/repos/dup/main.php"
if msr_content_matches_monorepo dup; then ok "divergent content -> no match" yes no; else ok "divergent content -> no match" no no; fi

echo "== msr_classify =="
mkdir -p "$FIX/repos/standalone-plug/.git"; printf '<?php\n/* Plugin Name: X */' > "$FIX/repos/standalone-plug/x.php"
ok "genuine standalone plugin" "$(msr_classify standalone-plug)" "genuine-standalone:plugin"
mkdir -p "$FIX/repos/standalone-thm/.git"; printf 'Theme Name: T\n' > "$FIX/repos/standalone-thm/style.css"
ok "genuine standalone theme" "$(msr_classify standalone-thm)" "genuine-standalone:theme"
mkdir -p "$FIX/repos/plugins/already/.git"
ok "already typed" "$(msr_classify already)" "already-typed"
ok "absent" "$(msr_classify ghost)" "absent"
git init -q --bare "$FIX/origin-dup.git"; git clone -q "$FIX/origin-dup.git" "$FIX/repos/dupclean" 2>/dev/null
mkdir -p "$FIX/plugins/dupclean"; printf 'A\n' > "$FIX/plugins/dupclean/a.php"; printf 'A\n' > "$FIX/repos/dupclean/a.php"
( cd "$FIX/repos/dupclean" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m a && git push -q origin HEAD:main && git branch -q --set-upstream-to=origin/main ) 2>/dev/null
ok "duplicate clean" "$(msr_classify dupclean)" "duplicate-clean"
cp -R "$FIX/repos/dupclean" "$FIX/repos/dupdirty"; mkdir -p "$FIX/plugins/dupdirty"; printf 'A\n' > "$FIX/plugins/dupdirty/a.php"
printf 'edit' >> "$FIX/repos/dupdirty/a.php"
ok "unsafe dirty" "$(msr_classify dupdirty)" "unsafe:dirty-working-tree"
git init -q --bare "$FIX/origin-fork.git"; git clone -q "$FIX/origin-fork.git" "$FIX/repos/dupfork" 2>/dev/null
mkdir -p "$FIX/plugins/dupfork"; printf 'MONO\n' > "$FIX/plugins/dupfork/a.php"; printf 'FORK\n' > "$FIX/repos/dupfork/a.php"
( cd "$FIX/repos/dupfork" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m fork && git push -q origin HEAD:main && git branch -q --set-upstream-to=origin/main ) 2>/dev/null
ok "unsafe divergent content" "$(msr_classify dupfork)" "unsafe:divergent-content"
# F5: both typed AND bare exist -> stale-bare-alongside-typed
mkdir -p "$FIX/repos/plugins/bothy/.git" "$FIX/repos/bothy/.git"
ok "stale bare alongside typed" "$(msr_classify bothy)" "stale-bare-alongside-typed"

echo "== dry-run plan (no fs changes) =="
# Root guard needs plugins/ + themes/ to exist (created by earlier fixtures).
mkdir -p "$FIX/repos/dryplug/.git"; printf '<?php' > "$FIX/repos/dryplug/x.php"
out=$(NABSPATH="$FIX" "$BIN/migrate-standalone-repos.sh" dryplug 2>&1)
ok "dry-run names the move target" "$(echo "$out" | grep -c 'dryplug.*repos/plugins/dryplug')" "1"
ok "dry-run is non-destructive (bare still present)" "$([ -d "$FIX/repos/dryplug" ] && echo yes)" "yes"
ok "dry-run did NOT create the typed path" "$([ -d "$FIX/repos/plugins/dryplug" ] && echo yes || echo no)" "no"
ok "dry-run prints the apply hint" "$(echo "$out" | grep -c -- '--apply')" "1"
# Root guard: a root lacking plugins/+themes/ must exit 2.
RGUARD="$(mktemp -d)"; mkdir -p "$RGUARD/repos/x"
NABSPATH="$RGUARD" "$BIN/migrate-standalone-repos.sh" x >/dev/null 2>&1
ok "root guard exits 2 when not a workspace" "$?" "2"
rm -rf "$RGUARD"

echo "== apply: move genuine standalone + repair worktree =="
git init -q "$FIX/repos/movable" 2>/dev/null; ( cd "$FIX/repos/movable" && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init ) 2>/dev/null
printf '<?php' > "$FIX/repos/movable/m.php"
( cd "$FIX/repos/movable" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m add ) 2>/dev/null
mkdir -p "$FIX/worktrees/movable"
( cd "$FIX/repos/movable" && git worktree add -q "$FIX/worktrees/movable/feat" -b feat ) 2>/dev/null
out=$(NABSPATH="$FIX" "$BIN/migrate-standalone-repos.sh" movable --apply 2>&1)
ok "moved to typed path" "$([ -d "$FIX/repos/plugins/movable/.git" ] && echo yes || echo no)" "yes"
ok "bare path gone" "$([ -e "$FIX/repos/movable" ] && echo yes || echo no)" "no"
ok "linked worktree resolves after repair" "$(git -C "$FIX/worktrees/movable/feat" rev-parse --is-inside-work-tree 2>/dev/null)" "true"
ok "worktree list points at new primary" "$(git -C "$FIX/repos/plugins/movable" worktree list 2>/dev/null | grep -c "$FIX/worktrees/movable/feat")" "1"

echo "== apply: remove clean duplicate to trash =="
git init -q --bare "$FIX/origin-rm.git"; git clone -q "$FIX/origin-rm.git" "$FIX/repos/rmdup" 2>/dev/null
mkdir -p "$FIX/plugins/rmdup"; printf 'A\n' > "$FIX/plugins/rmdup/a.php"; printf 'A\n' > "$FIX/repos/rmdup/a.php"
( cd "$FIX/repos/rmdup" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m a && git push -q origin HEAD:main && git branch -q --set-upstream-to=origin/main ) 2>/dev/null
out=$(NABSPATH="$FIX" "$BIN/migrate-standalone-repos.sh" rmdup --apply 2>&1)
ok "bare duplicate removed" "$([ -e "$FIX/repos/rmdup" ] && echo yes || echo no)" "no"
ok "backed up to trash" "$(ls -d "$FIX"/repos/.migration-trash/*/rmdup 2>/dev/null | wc -l | tr -d ' ')" "1"
ok "monorepo copy untouched" "$([ -f "$FIX/plugins/rmdup/a.php" ] && echo yes || echo no)" "yes"

echo "== apply: refuses unsafe, leaves it in place =="
git init -q --bare "$FIX/origin-un.git"; git clone -q "$FIX/origin-un.git" "$FIX/repos/unsafe1" 2>/dev/null
mkdir -p "$FIX/plugins/unsafe1"; printf 'A\n' > "$FIX/plugins/unsafe1/a.php"; printf 'A\n' > "$FIX/repos/unsafe1/a.php"
( cd "$FIX/repos/unsafe1" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m a && git push -q origin HEAD:main && git branch -q --set-upstream-to=origin/main ) 2>/dev/null
printf 'uncommitted' > "$FIX/repos/unsafe1/dirty.txt"   # make it unsafe (dirty)
out=$(NABSPATH="$FIX" "$BIN/migrate-standalone-repos.sh" unsafe1 --apply 2>&1)
ok "unsafe left in place" "$([ -d "$FIX/repos/unsafe1" ] && echo yes || echo no)" "yes"
ok "apply reports REFUSED" "$(echo "$out" | grep -c 'REFUSED  *unsafe1')" "1"

echo "== msr_affected_envs =="
# Real convention: root-level docker-compose.env-<name>.yml (not envs/<name>/).
printf 'services:\n  x:\n    volumes:\n      - ./repos:/newspack-repos\n' > "$FIX/docker-compose.env-alpha.yml"
printf 'services:\n  y:\n    volumes:\n      - ./html:/var/www/html\n' > "$FIX/docker-compose.env-beta.yml"
ok "lists envs that bind-mount ./repos" "$(msr_affected_envs | tr '\n' ',')" "alpha,"

echo "== pre-flight container gate =="
# Override the docker mounts seam with fixtures (no docker needed).
msr_container_mounts() { printf 'whole\t%s\n' "$MSR_ROOT/repos"; }
ok "whole ./repos mount blocks any name" "$(msr_blocking_containers anything | tr '\n' ',')" "whole,"
msr_container_mounts() { printf 'sub\t%s\n' "$MSR_ROOT/repos/other"; }
ok "repos/<name> mount blocks that name" "$(msr_blocking_containers other | tr '\n' ',')" "sub,"
ok "repos/<name> mount does not block a different name" "$(msr_blocking_containers movable2 | tr '\n' ',')" ""
# A move is refused (before any mv) when a blocker is present.
msr_container_mounts() { printf 'pin\t%s\n' "$MSR_ROOT/repos"; }
mkdir -p "$FIX/repos/pinned/.git"; printf '<?php' > "$FIX/repos/pinned/x.php"
gate_out=$(msr_do_move pinned plugin 2>&1); gate_rc=$?
ok "move refused when pinned (rc=1)" "$gate_rc" "1"
ok "refuse prints docker stop" "$(echo "$gate_out" | grep -c 'docker stop')" "1"
ok "pinned NOT moved while blocked" "$([ -d "$FIX/repos/pinned" ] && echo yes || echo no)" "yes"
# With no blockers, the move proceeds.
msr_container_mounts() { return 0; }
msr_do_move pinned plugin >/dev/null 2>&1
ok "move proceeds when unpinned" "$([ -d "$FIX/repos/plugins/pinned/.git" ] && echo yes || echo no)" "yes"

echo "== cwd gate =="
mkdir -p "$FIX/repos/cwdtest/.git"; printf '<?php' > "$FIX/repos/cwdtest/x.php"
# (1) Shell inside the target -> refuse (subprocess; $FIX won't match real containers).
cwd_out=$( cd "$FIX/repos/cwdtest" && NABSPATH="$FIX" "$BIN/migrate-standalone-repos.sh" cwdtest --apply 2>&1 ); cwd_rc=$?
ok "refuses when shell is inside target (rc=1)" "$cwd_rc" "1"
ok "names cd out" "$(echo "$cwd_out" | grep -c 'cd out')" "1"
ok "cwdtest NOT moved" "$([ -d "$FIX/repos/cwdtest" ] && echo yes || echo no)" "yes"
# (2) Another process cwd-rooted (override the lsof seam) -> refuse, in-process.
msr_container_mounts() { return 0; }
msr_cwd_rooted_procs() { echo "node(123)"; }
rooted_out=$(msr_do_move cwdtest plugin 2>&1); rooted_rc=$?
ok "refuses when a process is cwd-rooted (rc=1)" "$rooted_rc" "1"
ok "names the rooted proc" "$(echo "$rooted_out" | grep -c 'node(123)')" "1"
# (3) Nothing rooted -> proceeds.
msr_cwd_rooted_procs() { return 0; }
msr_do_move cwdtest plugin >/dev/null 2>&1
ok "proceeds when nothing rooted" "$([ -d "$FIX/repos/plugins/cwdtest/.git" ] && echo yes || echo no)" "yes"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
