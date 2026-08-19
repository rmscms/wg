#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SWAGGER_DIR="${ROOT}/public/assets/swagger"
VERSION="5.18.2"
BASE="https://unpkg.com/swagger-ui-dist@${VERSION}"

mkdir -p "$SWAGGER_DIR"

download() {
    local name="$1"
    echo "Downloading ${name}..."
    curl -fsSL "${BASE}/${name}" -o "${SWAGGER_DIR}/${name}"
}

download swagger-ui.css
download swagger-ui-bundle.js

echo "Swagger UI ${VERSION} saved to ${SWAGGER_DIR}"
