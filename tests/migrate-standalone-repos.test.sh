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

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
