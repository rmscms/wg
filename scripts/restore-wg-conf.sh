#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

SRC="${1:?source required}"
INTERFACE="${2:?interface required}"

if [[ ! "$INTERFACE" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "Invalid WireGuard interface." >&2
    exit 1
fi

if [[ "$SRC" != /* ]] || [[ "$SRC" == *..* ]] || [[ ! -f "$SRC" ]]; then
    echo "Invalid source WireGuard config." >&2
    exit 1
fi

if ! grep -q '^\[Interface\]' "$SRC"; then
    echo "Source is not a WireGuard config." >&2
    exit 1
fi

DEST="/etc/wireguard/${INTERFACE}.conf"
cp "$SRC" "$DEST"
chmod 600 "$DEST"
