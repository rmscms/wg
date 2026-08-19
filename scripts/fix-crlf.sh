#!/usr/bin/env bash
# Fix Windows CRLF line endings in shell scripts (run on Linux server)
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

for file in "$ROOT/install.sh" "$ROOT/uninstall.sh" "$ROOT"/scripts/*.sh; do
    if [[ -f "$file" ]]; then
        sed -i 's/\r$//' "$file"
        echo "Fixed: $file"
    fi
done

echo "Done. Run: sudo bash uninstall.sh"
