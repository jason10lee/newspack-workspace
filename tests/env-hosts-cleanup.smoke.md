# Isolated-env /etc/hosts cleanup smoke (manual; needs macOS + Docker + setup-networking run)

Pre: `./bin/setup-networking.sh` has installed the current `newspack-manage-host`.

1. **Marker on add:** `n env create hosttest --domain hosttest.test --up`
   → `grep hosttest.test /etc/hosts` shows a line ending `# newspack-env:hosttest`.
2. **Reliable destroy:** `n env destroy hosttest`
   → the line is gone; the command prints "Removed /etc/hosts entries for env 'hosttest'".
   To exercise the honest-failure path, temporarily rename the wrapper
   (`sudo mv /usr/local/bin/newspack-manage-host{,.off}`) and destroy another env: it should
   print the "may remain" warning instead of a false "Removed", then restore the wrapper.
3. **Same-domain shadow regression:** create `shadowtest` (domain `shadowtest.test`); simulate a
   stale destroy by `rm docker-compose.env-shadowtest.yml` WITHOUT removing the hosts line; recreate
   `n env create shadowtest --domain shadowtest.test`; run `n env cleanup` and let it sweep
   → the stale marked line is auto-removed (marked-orphan) and only the current (authoritative)
   line remains, so the recreated env is no longer shadowed.
4. **Legacy + user safety:** manually add `127.0.0.99 legacy-demo.test` (no marker) and
   `127.0.0.98 mything.local` (your own) to `/etc/hosts`; run `n env cleanup`
   → both are listed as legacy candidates and removed ONLY after you confirm `y`; answering `N`
   (or running with `--yes`, or non-interactively) leaves them. Neither is ever auto-removed.
5. **Cleanup:** `n env cleanup --all --yes` (removes envs), then manually delete any leftover demo lines.
