#!/usr/bin/env bash
# Lanceur Linux/macOS d'Atelier : vérifie PHP, initialise la base si besoin, démarre le serveur intégré.
set -euo pipefail
cd "$(dirname "$0")"

PHP=""
for candidate in "./_tools/php84/php" "./_tools/php/php" "php8.4" "php"; do
    if command -v "$candidate" >/dev/null 2>&1 || [ -x "$candidate" ]; then
        PHP="$candidate"
        break
    fi
done
if [ -z "$PHP" ]; then
    echo "[Atelier] PHP introuvable. Installez PHP 8.4 (ex. apt install php8.4-cli php8.4-sqlite3 php8.4-mbstring)." >&2
    exit 1
fi

if ! "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'; then
    echo "[Atelier] PHP 8.4 ou supérieur est requis ($("$PHP" -r 'echo PHP_VERSION;'))." >&2
    exit 1
fi
if ! "$PHP" -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("mbstring") ? 0 : 1);'; then
    echo "[Atelier] Extensions PHP requises manquantes : pdo_sqlite, mbstring." >&2
    exit 1
fi

if [ ! -f var/data/atelier.sqlite ]; then
    echo "[Atelier] Première utilisation : création de la base et des données de démonstration…"
    "$PHP" tools/console.php db:migrate
    "$PHP" tools/console.php db:seed
else
    "$PHP" tools/console.php db:migrate >/dev/null
fi

HOST="127.0.0.1"
PORT="${1:-8000}"
echo "[Atelier] Serveur de développement : http://$HOST:$PORT  (Ctrl+C pour arrêter)"
if command -v xdg-open >/dev/null 2>&1; then xdg-open "http://$HOST:$PORT/" >/dev/null 2>&1 || true
elif command -v open >/dev/null 2>&1; then open "http://$HOST:$PORT/" || true; fi
exec "$PHP" -S "$HOST:$PORT" -t public tools/dev-router.php
