#!/bin/bash

NABSPATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

validate_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._/-]+$ ]] || [[ "$1" == *..* ]] || [[ "$1" == /* ]]; then
        echo "Error: invalid $2 '$1' (only alphanumeric, dots, hyphens, underscores, slashes allowed; no '..' or leading '/')"
        exit 1
    fi
}

# Stricter validation for env names — no slashes (Docker rejects them in container/service names)
validate_env_name() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9._-]+$ ]]; then
        echo "Error: invalid environment name '$1' (only alphanumeric, dots, hyphens, underscores allowed)"
        exit 1
    fi
}

validate_domain() {
    if [[ ! "$1" =~ ^[a-zA-Z0-9.-]+$ ]] || [[ ${#1} -gt 253 ]]; then
        echo "Error: invalid domain '$1'"
        exit 1
    fi
}

validate_port() {
    if [[ ! "$1" =~ ^[0-9]+$ ]] || [[ "$1" -lt 1 || "$1" -gt 65535 ]]; then
        echo "Error: invalid port '$1' (must be a number between 1 and 65535)"
        exit 1
    fi
}

# Logging helpers — mirror the colored output used by bin/site-setup.sh.
NP_RED='\033[0;31m'
NP_GREEN='\033[0;32m'
NP_YELLOW='\033[1;33m'
NP_BLUE='\033[0;34m'
NP_NC='\033[0m'

log_info() { echo -e "${NP_BLUE}[INFO]${NP_NC} ${1}"; }
log_success() { echo -e "${NP_GREEN}[SUCCESS]${NP_NC} ${1}"; }
log_warning() { echo -e "${NP_YELLOW}[WARNING]${NP_NC} ${1}"; }
log_error() { echo -e "${NP_RED}[ERROR]${NP_NC} ${1}"; }

# Get the isolated-db sidecar service name (db_lowercase_<safe>) for an env's
# compose file. Returns empty if the env uses the shared db. Detection is by
# the 2-space-indented service-key line written by `n env create --isolated-db`.
# If the compose file's indentation changes, this regex must change in lockstep.
sidecar_service_for_env() {
    grep -oE '^  db_lowercase_[a-zA-Z0-9_]+:' "$1" 2>/dev/null | head -1 | tr -d ' :'
}

# Normalize an env name to a docker-safe form for service / container / data-dir
# names: fold dashes AND dots to underscores. Mirrors the equivalence enforced
# by the create-time collision check; the dot fold is what makes
# `n env create foo.bar --isolated-db` work (validate_env_name permits dots,
# but the detection regex above intentionally excludes them).
env_safe_name() {
    echo "$1" | tr -- '-.' '_'
}
