#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
PUBLIC_KEY="${2:?public key required}"
ALLOWED_IPS="${3:?allowed ips required}"
SPEED_KBPS="${4:-0}"

# Live tunnel only. wg0.conf is rewritten from the database by sync-wg.php (cron).
wg set "$INTERFACE" peer "$PUBLIC_KEY" allowed-ips "$ALLOWED_IPS"

CLIENT_IP="${ALLOWED_IPS%%/*}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$SPEED_KBPS" -gt 0 ]]; then
    IP_HASH=$(printf '%s' "$CLIENT_IP" | cksum | cut -d' ' -f1)
    CLASS_MINOR=$(( (IP_HASH % 65000) + 2 ))
    CLASS_HANDLE="1:$(printf '%x' "$CLASS_MINOR")"
    FILTER_PRIO_DST=$(( ((CLASS_MINOR % 32767) * 2) + 1 ))
    FILTER_PRIO_SRC=$(( FILTER_PRIO_DST + 1 ))

    if ! tc qdisc show dev "$INTERFACE" 2>/dev/null | grep -q "htb"; then
        tc qdisc add dev "$INTERFACE" root handle 1: htb default 999
        tc class add dev "$INTERFACE" parent 1: classid 1:999 htb rate 1000mbit ceil 1000mbit
    fi

    /bin/bash "${SCRIPT_DIR}/tc-client-lib.sh" "$INTERFACE" "$CLIENT_IP"

    tc class add dev "$INTERFACE" parent 1: classid "$CLASS_HANDLE" htb rate "${SPEED_KBPS}kbit" ceil "${SPEED_KBPS}kbit"
    tc filter add dev "$INTERFACE" protocol ip parent 1: prio "$FILTER_PRIO_DST" u32 match ip dst "$CLIENT_IP" flowid "$CLASS_HANDLE"
    tc filter add dev "$INTERFACE" protocol ip parent 1: prio "$FILTER_PRIO_SRC" u32 match ip src "$CLIENT_IP" flowid "$CLASS_HANDLE"
else
    /bin/bash "${SCRIPT_DIR}/tc-client-lib.sh" "$INTERFACE" "$CLIENT_IP"
fi

echo "Peer ${PUBLIC_KEY} applied on ${INTERFACE} (${CLIENT_IP}, speed=${SPEED_KBPS}kbps)"
