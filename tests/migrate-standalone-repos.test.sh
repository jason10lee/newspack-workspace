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

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
