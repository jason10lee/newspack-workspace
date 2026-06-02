# ensure-vendor robustness smoke (manual; needs a worktree-backed env + Docker)

1. **Runtime-only provisioning:** on a fresh env whose worktree plugin has no `vendor/`
   (e.g. `n env create evtest --worktree newspack-newsletters:<branch> --up`), watch
   `n env up` run `[ensure-vendor] installing composer deps for newspack-newsletters`.
   After it completes: `vendor/autoload.php` is present, the plugin activates with no
   missing-autoload fatal, and `ls .../newspack-newsletters/vendor/sebastian 2>/dev/null`
   shows **nothing** (dev deps not fetched).
2. **Dev deps still available on demand:** `n build newspack-newsletters` (or `n ci-build`)
   then re-check — `vendor/sebastian/` now present (build path installs dev deps).
3. **No partial vendor on failure (optional):** temporarily point a throwaway plugin's
   `composer.json` at an unresolvable package and run `bin/ensure-vendor.sh` in the
   container → it reports the failure with the `n ci-build all` hint, and that plugin's
   `vendor/` directory is **absent** afterward (not a half-written tree).
4. `bash -n bin/ensure-vendor.sh bin/composer-recovery.sh` clean;
   `bash tests/composer-recovery.test.sh` → `RESULT: 16 passed, 0 failed`.
