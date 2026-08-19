#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then
    exec /bin/bash "$0" "$@"
fi
exec php "$(dirname "$0")/check-limits.php" "$@"
