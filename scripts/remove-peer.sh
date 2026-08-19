#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
PUBLIC_KEY="${2:?public key required}"
CLIENT_IP="${3:-}"
SPEED_KBPS="${4:-0}"

# Live tunnel only. wg0.conf is rewritten from the database by sync-wg.php (cron).
if ! wg set "$INTERFACE" peer "$PUBLIC_KEY" remove; then
    echo "Failed to remove peer from runtime: ${PUBLIC_KEY}" >&2
    exit 1
fi

if [[ -n "$CLIENT_IP" ]]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    /bin/bash "${SCRIPT_DIR}/tc-client-lib.sh" "$INTERFACE" "$CLIENT_IP"
fi

echo "Peer ${PUBLIC_KEY} removed from ${INTERFACE}"
