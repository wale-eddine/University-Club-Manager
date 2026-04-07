#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if command -v php >/dev/null 2>&1; then
  php "${SCRIPT_DIR}/reset_owner_db.php" "$@"
  exit 0
fi

if [ -x "/c/xampp/php/php.exe" ]; then
  "/c/xampp/php/php.exe" "${SCRIPT_DIR}/reset_owner_db.php" "$@"
  exit 0
fi

echo "Could not find PHP in PATH or /c/xampp/php/php.exe" >&2
echo "Install PHP or adjust scripts/reset_owner_db.sh" >&2
exit 1
