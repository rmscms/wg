#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
PUBLIC_KEY="${2:?public key required}"
CLIENT_IP="${3:-}"
SPEED_KBPS="${4:-0}"

if ! wg set "$INTERFACE" peer "$PUBLIC_KEY" remove; then
    echo "Failed to remove peer from runtime: ${PUBLIC_KEY}" >&2
    exit 1
fi

CONF="/etc/wireguard/${INTERFACE}.conf"
LOCK_FILE="/var/lock/wg-conf-${INTERFACE}.lock"

if [[ -f "$CONF" && -n "$PUBLIC_KEY" ]]; then
    # Acquire exclusive lock to prevent race conditions when multiple
    # remove-peer calls run simultaneously (e.g. bulk API deletes).
    (
        flock -x 200

        awk -v key="$PUBLIC_KEY" '
            BEGIN { in_peer=0; skip=0; block="" }

            /^\[Peer\]/ {
                if (in_peer && !skip) printf "%s", block
                in_peer=1; skip=0; block=$0 "\n"; next
            }

            in_peer {
                if (/^\[/ && !/^\[Peer\]/) {
                    if (!skip) printf "%s", block
                    in_peer=0; skip=0; block=""
                    print; next
                }
                if ($0 == "PublicKey = " key) skip=1
                block = block $0 "\n"
                next
            }

            { print }

            END { if (in_peer && !skip) printf "%s", block }
        ' "$CONF" > "${CONF}.tmp"

        # Only replace if output is non-empty (guards against awk failure)
        if [[ -s "${CONF}.tmp" ]]; then
            mv "${CONF}.tmp" "$CONF"
        else
            rm -f "${CONF}.tmp"
        fi

    ) 200>"$LOCK_FILE"
fi

if [[ -n "$CLIENT_IP" ]]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    /bin/bash "${SCRIPT_DIR}/tc-client-lib.sh" "$INTERFACE" "$CLIENT_IP"
fi

echo "Peer ${PUBLIC_KEY} removed from ${INTERFACE}"
