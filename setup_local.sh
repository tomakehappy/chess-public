#!/usr/bin/env bash
# Lokale Testumgebung einrichten (Linux/macOS):  ./setup_local.sh
# Prueft Node.js und PHP, legt die Datenverzeichnisse an und startet server.js.
set -euo pipefail
cd "$(dirname "$0")"

command -v node >/dev/null || { echo "Node.js fehlt: https://nodejs.org/ (LTS)"; exit 1; }
if ! command -v php >/dev/null; then
  echo "PHP fehlt. Installieren mit:"
  echo "  Ubuntu/Debian:  sudo apt-get install -y php-cli"
  echo "  macOS:          brew install php"
  exit 1
fi

echo "==> Node.js $(node -v), $(php -v | head -n1)"
php -l chess_online.php
php -l chess_auth.php
mkdir -p online_games auth_data

exec node server.js "$@"
