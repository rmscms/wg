#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FONT_DIR="${ROOT}/public/assets/fonts"
BASE="https://raw.githubusercontent.com/rastikerdar/vazirmatn/master/fonts/webfonts"

mkdir -p "$FONT_DIR"

declare -A FILES=(
    ["Vazirmatn-Regular.woff2"]="${BASE}/Vazirmatn-Regular.woff2"
    ["Vazirmatn-Medium.woff2"]="${BASE}/Vazirmatn-Medium.woff2"
    ["Vazirmatn-Bold.woff2"]="${BASE}/Vazirmatn-Bold.woff2"
    ["Vazirmatn-Variable.woff2"]="${BASE}/Vazirmatn%5Bwght%5D.woff2"
)

for name in "${!FILES[@]}"; do
    echo "Downloading ${name}..."
    curl -fsSL "${FILES[$name]}" -o "${FONT_DIR}/${name}"
done

echo "Fonts saved to ${FONT_DIR}"
