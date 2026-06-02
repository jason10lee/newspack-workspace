#!/bin/bash
# One-time host setup for isolated envs (macOS): passwordless networking + trusted SSL.
#
# Installs the newspack-manage-host wrapper to /usr/local/bin and creates
# a sudoers drop-in that allows the current user to run it without a password.
# After this, `n start` and `n env create` can set up loopback aliases and
# /etc/hosts entries without interactive sudo prompts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WRAPPER_SRC="$SCRIPT_DIR/newspack-manage-host"
WRAPPER_DEST="/usr/local/bin/newspack-manage-host"
SUDOERS_FILE="/etc/sudoers.d/newspack-manage-host"

source "$SCRIPT_DIR/ssl-trust.sh"

provision_ssl() {
    echo ""
    echo "== SSL (trusted certs for isolated-env HTTPS) =="
    case "$(uname)" in
        Darwin)
            if ! ssl_host_mkcert_present; then
                if command -v brew >/dev/null 2>&1; then
                    echo "Installing mkcert via Homebrew..."
                    if ! brew install mkcert; then
                        echo "Warning: 'brew install mkcert' failed; install mkcert manually, then re-run this script." >&2
                        return 0
                    fi
                else
                    echo "mkcert is not installed and Homebrew was not found."
                    echo "Install mkcert (https://github.com/FiloSottile/mkcert#installation), then re-run this script."
                    return 0
                fi
            fi
            # `mkcert -install` is idempotent: a no-op when already trusted, and the
            # only thing that actually proves the CA is in the trust store. Always run it.
            echo "Ensuring the mkcert root CA is trusted (may prompt for your password)..."
            if mkcert -install; then
                ssl_host_ca_trusted && echo "SSL: mkcert CA trusted." || echo "SSL: mkcert -install ran but the CA was not detected as trusted; check keychain access."
            else
                echo "Warning: 'mkcert -install' failed; isolated-env HTTPS will be untrusted until it succeeds." >&2
            fi
            ;;
        *)
            echo "Linux: install mkcert (distro package or the FiloSottile binary), then run 'mkcert -install'."
            echo "(Automated Linux provisioning is not yet implemented.)"
            ;;
    esac
}

if [[ "$(uname)" != "Darwin" ]]; then
    # Networking (loopback aliases + /etc/hosts) is macOS-only; SSL still applies.
    provision_ssl
    echo "Networking setup is macOS-only (Linux routes all 127.x.x.x by default)."
    exit 0
fi

if [[ ! -f "$WRAPPER_SRC" ]]; then
    echo "Error: wrapper script not found at $WRAPPER_SRC" >&2
    exit 1
fi

echo "This will:"
echo "  1. Copy newspack-manage-host to $WRAPPER_DEST (owned by root)"
echo "  2. Create $SUDOERS_FILE so '$(whoami)' can run it without a password"
echo "  3. Install mkcert + trust its CA so isolated-env HTTPS is trusted by your browser"
echo ""
echo "After this, 'n start' and 'n env create' will manage networking automatically."
echo ""
read -p "Continue? (Y/n): " choice
choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
if [[ "$choice" == "n" ]]; then
    echo "Aborted."
    exit 0
fi

echo "Installing wrapper script (requires sudo)..."
sudo cp "$WRAPPER_SRC" "$WRAPPER_DEST"
sudo chown root:wheel "$WRAPPER_DEST"
sudo chmod 755 "$WRAPPER_DEST"

echo "Creating sudoers rule..."
CURRENT_USER="$(whoami)"
sudo tee "$SUDOERS_FILE" > /dev/null <<EOF
# Allow $CURRENT_USER to manage loopback aliases and /etc/hosts for Newspack Docker.
$CURRENT_USER ALL=(root) NOPASSWD: $WRAPPER_DEST *
EOF
sudo chmod 440 "$SUDOERS_FILE"

# Validate the sudoers file.
if sudo visudo -cf "$SUDOERS_FILE"; then
    echo ""
    echo "Done! Networking setup will now work without password prompts."
    echo "To undo, run: sudo rm $SUDOERS_FILE $WRAPPER_DEST"
else
    echo "Error: sudoers file is invalid. Removing it." >&2
    sudo rm -f "$SUDOERS_FILE"
    exit 1
fi

provision_ssl
echo ""
echo "Host setup complete: networking + SSL."
