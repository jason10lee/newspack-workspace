#!/bin/bash
#
# Ensure migrated monorepo plugins have their Composer vendor/ installed.
#
# A fresh env links the monorepo plugins from /newspack-plugins/, whose composer
# vendor/ is never installed by `n env up` on its own — so activating a plugin
# that requires its autoloader (e.g. newspack-plugin, newspack-popups) fatals on
# a missing vendor/autoload.php. This runs a runtime-only Composer install
# (--no-dev -- all plugin activation needs) at env-up time, without dev
# dependencies (PHPUnit etc.) or the slow JS build, so a fresh env is never
# vendorless. Dev deps for running a plugin's tests come from `n build`/`n ci-build`.
#
# Idempotent: skips any plugin whose vendor/autoload.php is already present.
# get_all_project_dirs is monorepo-only, so standalone repos/ checkouts aren't
# touched here -- they install their own deps on demand via `n build <name>`.

source /var/scripts/resolve-project-path.sh

failed=()
installed=0

while IFS= read -r dir; do
	[ -d "$dir" ] || continue
	[ -f "$dir/composer.json" ] || continue
	name=$(basename "$dir")
	# Already provisioned — nothing to do (keeps warm starts instant).
	[ -f "$dir/vendor/autoload.php" ] && continue
	echo "[ensure-vendor] installing composer deps for $name"
	if composer install --working-dir "$dir" --no-interaction --no-dev; then
		installed=$((installed + 1))
	else
		failed+=("$name")
	fi
done < <(get_all_project_dirs)

if [ "${#failed[@]}" -gt 0 ]; then
	echo "[ensure-vendor] ERROR: composer install failed for: ${failed[*]}" >&2
	echo "[ensure-vendor] those plugins will fatal on activation; fix the cause and re-run 'n ci-build all'." >&2
	[ "$installed" -gt 0 ] && echo "[ensure-vendor] (provisioned vendor/ for $installed other plugin(s) before this)" >&2
	exit 1
fi

if [ "$installed" -eq 0 ]; then
	echo "[ensure-vendor] all monorepo plugin vendor/ already present"
else
	echo "[ensure-vendor] provisioned vendor/ for $installed plugin(s)"
fi
