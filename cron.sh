#!/usr/bin/env bash
# Tâches de fond Atelier (Linux/macOS) : à planifier toutes les 5 à 15 minutes, par exemple dans crontab -e :
#   */10 * * * * /chemin/vers/atelier/cron.sh >> /chemin/vers/atelier/var/logs/cron.log 2>&1
cd "$(dirname "$0")"
PHP="php"
for candidate in "./_tools/php84/php" "./_tools/php/php" "php8.4"; do
    if [ -x "$candidate" ] || command -v "$candidate" >/dev/null 2>&1; then PHP="$candidate"; break; fi
done
exec "$PHP" tools/console.php cron:run "$@"
