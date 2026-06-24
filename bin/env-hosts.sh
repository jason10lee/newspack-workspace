#!/bin/bash
# Sourceable helpers for managing isolated-env /etc/hosts entries.
# Pure functions (env_hosts_classify, env_hosts_strip, env_hosts_sed_expr) take a
# hosts-file PATH and are unit-tested in tests/env-hosts.test.sh. env_hosts_remove
# is privileged (sudo) and covered by tests/env-hosts-cleanup.smoke.md.
#
# Managed lines carry a trailing marker:  <ip> <domain> # newspack-env:<name>

NEWSPACK_HOSTS_MARKER="# newspack-env:"

# Membership test against a space-separated list.
_env_hosts_in_list() {
    local needle="$1" hay=" $2 "
    case "$hay" in *" $needle "*) return 0 ;; *) return 1 ;; esac
}

# Emit a BRE sed delete-expression matching a domain's hosts line, tolerating an
# optional trailing marker. Dots in the domain are escaped so they are literal.
# Matches: <whitespace><domain> followed by either end-of-line or <whitespace>...
env_hosts_sed_expr() {
    local escaped="${1//./\\.}"
    printf '/[[:space:]]%s\\([[:space:]].*\\)\\{0,1\\}$/d' "$escaped"
}

# Remove every line for <domain> from an arbitrary file (no sudo). Pure.
# Test/utility helper: env.sh's removal path uses env_hosts_remove (privileged),
# which re-derives the same expression via env_hosts_sed_expr, so the two stay in
# sync. This non-sudo variant exists for unit testing the matching behavior.
env_hosts_strip() {
    local domain="${1:-}" file="${2:-}"
    [ -n "$domain" ] && [ -f "$file" ] || return 1
    local expr; expr="$(env_hosts_sed_expr "$domain")"
    # BSD form first (macOS), GNU fallback — mirrors the repo idiom.
    sed -i '' "$expr" "$file" 2>/dev/null || sed -i "$expr" "$file"
}

# Classify stale isolated-env lines in a hosts file. Pure.
#   $1 = hosts-file path
#   $2 = space-separated live env names (those with a compose file)
#   $3 = space-separated live env domains
# stdout (one per line):
#   marked-orphan <domain>      marker present, env name not live
#   legacy-candidate <domain>   no marker, 127.0.0.x + .test/.local, domain not live
# Lines that are marked-and-live, unmarked-but-live-domain, or non-loopback /
# non-.test/.local are never emitted.
env_hosts_classify() {
    local hosts_file="${1:-}" live_names="${2:-}" live_domains="${3:-}"
    [ -r "$hosts_file" ] || return 0
    local line ip domain rest marker_name
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in ''|'#'*) continue ;; esac
        read -r ip domain rest <<< "$line"
        [ -n "$domain" ] || continue
        [[ "$ip" =~ ^127\.0\.0\.[0-9]+$ ]] || continue
        case "$domain" in *.test|*.local) ;; *) continue ;; esac
        marker_name=""
        case "$line" in
            *"$NEWSPACK_HOSTS_MARKER"*)
                marker_name="${line##*"$NEWSPACK_HOSTS_MARKER"}"
                marker_name="${marker_name%%[[:space:]]*}"
                ;;
        esac
        if [ -n "$marker_name" ]; then
            _env_hosts_in_list "$marker_name" "$live_names" && continue
            printf 'marked-orphan %s\n' "$domain"
        else
            _env_hosts_in_list "$domain" "$live_domains" && continue
            printf 'legacy-candidate %s\n' "$domain"
        fi
    done < "$hosts_file"
}

# Remove all /etc/hosts lines for <domain> via the privileged path. Returns 0
# only if a privileged removal actually executed (so callers can report honestly).
env_hosts_remove() {
    local domain="${1:-}"
    [ -n "$domain" ] || return 1
    if command -v newspack-manage-host >/dev/null 2>&1 \
        && { [[ "$domain" == *.test ]] || [[ "$domain" == *.local ]]; }; then
        sudo newspack-manage-host host-remove "$domain" && return 0
        return 1
    fi
    local expr; expr="$(env_hosts_sed_expr "$domain")"
    if sudo sed -i '' "$expr" /etc/hosts 2>/dev/null || sudo sed -i "$expr" /etc/hosts 2>/dev/null; then
        return 0
    fi
    return 1
}
