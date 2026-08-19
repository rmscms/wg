#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

INTERFACE="${1:?interface required}"
PUBLIC_KEY="${2:?public key required}"
ALLOWED_IPS="${3:?allowed ips required}"
SPEED_KBPS="${4:-0}"

# Add or update WireGuard peer
wg set "$INTERFACE" peer "$PUBLIC_KEY" allowed-ips "$ALLOWED_IPS"

# Persist to wg config if wg-quick format exists
CONF="/etc/wireguard/${INTERFACE}.conf"
LOCK_FILE="/var/lock/wg-conf-${INTERFACE}.lock"

if [[ -f "$CONF" ]]; then
    (
        flock -x 200

        if grep -qF "$PUBLIC_KEY" "$CONF" 2>/dev/null; then
            awk -v key="$PUBLIC_KEY" -v ips="$ALLOWED_IPS" '
                BEGIN { in_peer = 0; match_peer = 0 }

                /^\[Peer\]/ {
                    in_peer = 1
                    match_peer = 0
                    print
                    next
                }

                in_peer && /^\[/ && !/^\[Peer\]/ {
                    in_peer = 0
                    match_peer = 0
                    print
                    next
                }

                in_peer {
                    if ($0 == "PublicKey = " key) {
                        match_peer = 1
                    }
                    if (match_peer && $0 ~ /^AllowedIPs = /) {
                        print "AllowedIPs = " ips
                        next
                    }
                    print
                    next
                }

                { print }
            ' "$CONF" > "${CONF}.tmp"

            if [[ -s "${CONF}.tmp" ]]; then
                mv "${CONF}.tmp" "$CONF"
            else
                rm -f "${CONF}.tmp"
            fi
        else
            cat >> "$CONF" <<EOF

[Peer]
PublicKey = ${PUBLIC_KEY}
AllowedIPs = ${ALLOWED_IPS}
EOF
        fi

    ) 200>"$LOCK_FILE"
fi

# Extract client IP (without CIDR)
CLIENT_IP="${ALLOWED_IPS%%/*}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$SPEED_KBPS" -gt 0 ]]; then
    IP_HASH=$(printf '%s' "$CLIENT_IP" | cksum | cut -d' ' -f1)
    CLASS_MINOR=$(( (IP_HASH % 65000) + 2 ))
    CLASS_HANDLE="1:$(printf '%x' "$CLASS_MINOR")"
    # tc prio must be 1..65535; keep stable unique prios per IP
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
