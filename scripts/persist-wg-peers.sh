#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
PEERS_FILE="${2:-}"

if [[ ! "$INTERFACE" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "Invalid WireGuard interface." >&2
    exit 1
fi

CONF="/etc/wireguard/${INTERFACE}.conf"
LOCK_FILE="/var/lock/wg-conf-${INTERFACE}.lock"

if [[ ! -f "$CONF" ]]; then
    echo "WireGuard config not found: ${CONF}" >&2
    exit 1
fi

if [[ -n "$PEERS_FILE" ]]; then
    if [[ "$PEERS_FILE" != /* ]] || [[ "$PEERS_FILE" == *..* ]] || [[ ! -f "$PEERS_FILE" ]]; then
        echo "Invalid peers file." >&2
        exit 1
    fi
fi

(
    flock -x 200

    TMP="$(mktemp "${CONF}.XXXXXX")"
    trap 'rm -f "$TMP"' EXIT

    awk '
        BEGIN { skip = 0 }
        {
            gsub(/\r/, "")
        }
        /^\[Peer\]/ { skip = 1; next }
        skip && /^\[/ && !/^\[Peer\]/ { skip = 0 }
        skip { next }
        { print }
    ' "$CONF" > "$TMP"

    if [[ -n "$PEERS_FILE" && -s "$PEERS_FILE" ]]; then
        printf '\n' >> "$TMP"
        cat "$PEERS_FILE" >> "$TMP"
    fi

    if [[ ! -s "$TMP" ]]; then
        echo "Refusing to replace empty WireGuard config." >&2
        exit 1
    fi

    if ! grep -q '^\[Interface\]' "$TMP"; then
        echo "Rewritten config is missing [Interface]." >&2
        exit 1
    fi

    if ! grep -qE '^PrivateKey[[:space:]]*=' "$TMP"; then
        echo "Rewritten config is missing PrivateKey." >&2
        exit 1
    fi

    chmod 600 "$TMP"
    mv "$TMP" "$CONF"
    chmod 600 "$CONF"
    trap - EXIT
) 200>"$LOCK_FILE"

echo "Persisted peers to ${CONF}"
