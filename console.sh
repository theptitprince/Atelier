#!/usr/bin/env bash
# Console d'administration Atelier (Linux/macOS) : ./console.sh help
cd "$(dirname "$0")"
PHP="php"
for candidate in "./_tools/php84/php" "./_tools/php/php" "php8.4"; do
    if [ -x "$candidate" ] || command -v "$candidate" >/dev/null 2>&1; then PHP="$candidate"; break; fi
done
exec "$PHP" tools/console.php "$@"
