#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
DEST="${2:?destination required}"

if [[ ! "$INTERFACE" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "Invalid WireGuard interface." >&2
    exit 1
fi

SRC="/etc/wireguard/${INTERFACE}.conf"

if [[ ! -f "$SRC" ]]; then
    echo "WireGuard config not found: ${SRC}" >&2
    exit 1
fi

if [[ "$DEST" != /* ]] || [[ "$DEST" == *..* ]]; then
    echo "Invalid destination path." >&2
    exit 1
fi

mkdir -p "$(dirname "$DEST")"
cp "$SRC" "$DEST"
chown www-data:www-data "$DEST" 2>/dev/null || true
chmod 600 "$DEST"
