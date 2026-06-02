#!/bin/bash
# Sourceable mkcert/SSL-trust helpers shared by setup-networking.sh (provisioning)
# and env.sh (per-env cert generation). macOS-focused; Linux paths are best-effort
# stubs (see setup-networking.sh for the manual Linux note).

# True if the mkcert binary is on PATH.
ssl_host_mkcert_present() { command -v mkcert >/dev/null 2>&1; }

# True if the mkcert root CA appears in the host's system trust store.
# macOS: the CA is added (by `mkcert -install`) to the System keychain; its CN
# contains "mkcert". On Linux we can't cheaply verify trust, so treat a present
# CAROOT as "trusted" (the user ran `mkcert -install` per the manual note).
ssl_host_ca_trusted() {
    ssl_host_mkcert_present || return 1
    case "$(uname)" in
        Darwin)
            security find-certificate -a -c "mkcert" /Library/Keychains/System.keychain >/dev/null 2>&1
            ;;
        *)
            [ -f "$(mkcert -CAROOT 2>/dev/null)/rootCA.pem" ]
            ;;
    esac
}

# True if $1 (a cert path) chains to the CURRENT host mkcert root CA.
# Used to detect a stale/container-generated cert that should be regenerated.
# Returns 0 (trusted) or 1 (untrusted/error) — normalises openssl verify's
# non-zero exit codes (1 = cert error, 2 = untrusted issuer) to a single 1.
ssl_cert_is_host_trusted() {
    local cert="$1" caroot
    ssl_host_mkcert_present || return 1
    [ -f "$cert" ] || return 1
    caroot="$(mkcert -CAROOT 2>/dev/null)"
    [ -f "$caroot/rootCA.pem" ] || return 1
    openssl verify -CAfile "$caroot/rootCA.pem" "$cert" >/dev/null 2>&1 || return 1
    return 0
}
