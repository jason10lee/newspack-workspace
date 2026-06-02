# SSL-trust smoke (manual; needs macOS + Docker)

1. **mkcert absent:** `brew uninstall mkcert` (if present); `n env create ssltest --up`
   → expect the "host mkcert not found" warning; env comes up (untrusted).
2. **CA untrusted:** install mkcert but remove trust: `brew install mkcert && mkcert -uninstall`;
   `n env up ssltest` → expect the "CA is not trusted" warning.
3. **Provision (idempotent):** `./bin/setup-networking.sh` → mkcert installed + CA trusted;
   re-run → no error, reports already-trusted (not a file-presence guess).
4. **Stale-cert regen:** with the env still on its container cert, `n env up ssltest`
   → cert is regenerated; verify it chains to the host CA:
   `openssl verify -CAfile "$(mkcert -CAROOT)/rootCA.pem" envs/ssltest/certs/<domain>.pem` → "OK",
   and a browser loads `https://<domain>/` with no cert error.
5. **Cleanup:** `n env destroy ssltest`.
