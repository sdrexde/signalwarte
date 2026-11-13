#!/usr/bin/env bash
# Konsistentes Backup der Reload-Monitor-Datenbank (im laufenden Betrieb, per SQLite "VACUUM INTO").
#   bash tools/backup.sh [/ziel/datei.sqlite]
# Wiederherstellen / Umzug auf neuen Server:  bash install/install.sh --import /ziel/datei.sqlite
set -euo pipefail
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-/tmp/reload-monitor-$(date +%Y%m%d-%H%M%S).sqlite}"
[ -f "$APP_DIR/data/monitor.sqlite" ] || { echo "Keine Datenbank unter $APP_DIR/data gefunden."; exit 1; }
[ -e "$OUT" ] && { echo "$OUT existiert bereits."; exit 1; }
TMP="$(mktemp -d)"
chmod 777 "$TMP"
# als www-data, damit WAL-/SHM-Dateien der Live-Datenbank nicht root gehören
su -s /bin/sh www-data -c "php -r '\$p = new PDO(\"sqlite:$APP_DIR/data/monitor.sqlite\"); \$p->exec(\"PRAGMA busy_timeout=8000\"); \$p->exec(\"VACUUM INTO \" . \$p->quote(\"$TMP/backup.sqlite\"));'"
mv "$TMP/backup.sqlite" "$OUT"
rmdir "$TMP"
chmod 600 "$OUT"
echo "Backup: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Enthält Zugangsdaten (SNMP-Community, Passwörter, Tokens) – vertraulich behandeln."
