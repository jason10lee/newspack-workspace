# Smoke: `n test-js` never installs from an isolated env

What this proves on a live env: `n test-js` in an isolated env leaves the shared
root untouched, refuses a branch whose manifests the shared install does not match
with `(cause: deps-stale)`, and finds a unit's branch volume across Docker
Desktop's bind mounts. The unit tests (`bin/tests/test-test-js-deps.sh`,
`bin/tests/test-test-js-install-policy.sh`) cover the decisions with stubs; this
covers the mounts, the container's pnpm and the real shared install.

Run it from the root checkout. Until the branch is merged, `n` runs the root's
`bin/`, so copy the branch's `test-js.sh`, `test-js-deps.sh`, `repos.sh`,
`resolve-project-path.sh` and `test-js-summary.sh` to `/tmp/tjs-bin/` in the env
and run `bash /tmp/tjs-bin/test-js.sh <unit>` there. After the merge, use
`n test-js <unit>` from the branch's worktree.

1. **Snapshot the root.** `stat` the mtimes of `pnpm-lock.yaml`,
   `node_modules/.pnpm/lock.yaml`, `node_modules/.modules.yaml`,
   `packages/components/dist` and `.husky/_`.
2. **Create the env.** Two branches from local `main`: `a` unchanged, `b` with one
   specifier reworded in `plugins/newspack-plugin/package.json` (`^2.5.1` →
   `~2.5.1` on `classnames`; no lockfile change, no network).
   `n env create tjs2 --worktree newspack-popups:tooling/tjs2-a --worktree newspack-plugin:tooling/tjs2-b`,
   then `n env up tjs2 --build-worktrees`.
3. **Branch volumes match by `-ef`.** In the env,
   `source test-js-deps.sh; branch_root_for /newspack-plugins/<unit>` prints
   `/newspack-worktrees/tooling-tjs2-a` for popups and `.../tooling-tjs2-b` for the
   plugin, and nothing for a unit no branch carries. If it prints nothing for a
   branch unit, the bind mounts break `-ef` and the branch path never runs.
4. **Run each branch's tests, in both output modes.** Expected result depends on
   the shared install: when it does not match a branch's manifests, the run exits 1
   with `(cause: deps-stale)` and pnpm's first error line, and installs nothing.
   When it does match, the tests run from `/newspack-worktrees/<branch>`.
5. **The root is unchanged.** Repeat step 1's `stat`; every mtime matches.
6. **Control.** In the env, run `deps_check /newspack-worktrees/<branch>
   /tmp/main-lock.yaml "$(root_pnpm_cmd /newspack-monorepo)"` with `main`'s
   committed lockfile copied in. `a` exits 0 and `b` exits 1 on its reworded range.
   This proves the check can pass from a branch root, and that it catches a
   manifest change the shared install was not made from.
7. **Clean up** on the operator's word: tag both branches, then
   `n env destroy tjs2`.

## Run 2026-11-09

The shared install followed upstream manifests (`@wordpress/ui ^0.26`; local
`main` asks for `^0.23`), so step 4 refused both branches, as expected, naming
`plugins/newspack-plugin/package.json`. Everything else matched: step 3 matched
both units and left `newspack-blocks` empty; step 5 found every mtime unchanged;
step 6 gave `a` exit 0 and `b` exit 1; no `/tmp/test-js-deps.*` directory was left
in the env.
