#!/bin/bash
#
# Ensure migrated monorepo plugins have their Composer vendor/ installed.
#
# A fresh env links the monorepo plugins from /newspack-plugins/, whose composer
# vendor/ is never installed by `n env up` on its own — so activating a plugin
# that requires its autoloader (e.g. newspack-plugin, newspack-popups) fatals on
# a missing vendor/autoload.php. The composer-install step already exists in
# build-repos.sh's `all` path; this runs the same PHP-dependency step at env-up
# time, WITHOUT the slow JS build, so a fresh env is never vendorless.
#
# Idempotent: skips any plugin whose vendor/autoload.php is already present.
# Standalone repos (repos/<name>/) manage their own deps and are skipped.

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

failed=()
installed=0

while IFS= read -r dir; do
	[ -d "$dir" ] || continue
	[ -f "$dir/composer.json" ] || continue
	name=$(basename "$dir")
	# Standalone repos own their dependency lifecycle; don't touch them.
	is_standalone_repo "$name" && continue
	# Already provisioned — nothing to do (keeps warm starts instant).
	[ -f "$dir/vendor/autoload.php" ] && continue
	echo "[ensure-vendor] installing composer deps for $name"
	if composer install --working-dir "$dir" --no-interaction; then
		installed=$((installed + 1))
	else
		failed+=("$name")
	fi
done < <(get_all_project_dirs)

if [ "${#failed[@]}" -gt 0 ]; then
	echo "[ensure-vendor] ERROR: composer install failed for: ${failed[*]}" >&2
	echo "[ensure-vendor] those plugins will fatal on activation; fix the cause and re-run 'n ci-build all'." >&2
	exit 1
fi

if [ "$installed" -eq 0 ]; then
	echo "[ensure-vendor] all monorepo plugin vendor/ already present"
else
	echo "[ensure-vendor] provisioned vendor/ for $installed plugin(s)"
fi
