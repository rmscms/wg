#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
chmod +x "${ROOT}/scripts/"*.sh
echo "Executable: ${ROOT}/scripts/*.sh"
