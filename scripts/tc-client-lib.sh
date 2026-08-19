#!/usr/bin/env bash
# Shared tc cleanup for a client IP (speed limit removal).
# Usage: tc-client-lib.sh <interface> <client_ip>

remove_tc_for_client() {
    local INTERFACE="${1:?interface required}"
    local CLIENT_IP="${2:?client ip required}"

    local IP_HASH CLASS_MINOR CLASS_HANDLE FILTER_PRIO_DST FILTER_PRIO_SRC
    local LEGACY_FILTER_PRIO BROKEN_FILTER_PRIO_DST BROKEN_FILTER_PRIO_SRC prio

    IP_HASH=$(printf '%s' "$CLIENT_IP" | cksum | cut -d' ' -f1)
    CLASS_MINOR=$(( (IP_HASH % 65000) + 2 ))
    CLASS_HANDLE="1:$(printf '%x' "$CLASS_MINOR")"
    FILTER_PRIO_DST=$(( ((CLASS_MINOR % 32767) * 2) + 1 ))
    FILTER_PRIO_SRC=$(( FILTER_PRIO_DST + 1 ))
    LEGACY_FILTER_PRIO=$(( (IP_HASH % 20000) + 1 ))
    BROKEN_FILTER_PRIO_DST=$(( CLASS_MINOR * 2 ))
    BROKEN_FILTER_PRIO_SRC=$(( CLASS_MINOR * 2 + 1 ))

    for prio in \
        "$LEGACY_FILTER_PRIO" \
        "$((LEGACY_FILTER_PRIO + 256))" \
        "$FILTER_PRIO_DST" \
        "$FILTER_PRIO_SRC" \
        "$BROKEN_FILTER_PRIO_DST" \
        "$BROKEN_FILTER_PRIO_SRC"
    do
        if (( prio >= 1 && prio <= 65535 )); then
            tc filter del dev "$INTERFACE" protocol ip parent 1: prio "$prio" 2>/dev/null || true
        fi
    done

    tc class del dev "$INTERFACE" classid "$CLASS_HANDLE" 2>/dev/null || true
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    set -euo pipefail
    remove_tc_for_client "${1:?interface required}" "${2:?client ip required}"
fi
