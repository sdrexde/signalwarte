#!/usr/bin/env bash
# Signalwarte – Installation auf einem frischen Debian/Ubuntu-LXC (oder VM)
#
#   git clone git@github.com:sdrexde/signalwarte.git /var/www/signalwarte
#   bash /var/www/signalwarte/install/install.sh [--import /pfad/backup.sqlite] [--name monitor.example.de]
#
# Richtet ein: Apache + PHP (pdo_sqlite, curl, pcntl), vHost auf / , Cron-Poller (jede Minute als www-data),
# Rechte, ping für www-data, optional Datenübernahme aus einem Backup (tools/backup.sh).
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMPORT=""
SERVER_NAME="_default_"

while [ $# -gt 0 ]; do
  case "$1" in
    --import) IMPORT="${2:-}"; shift 2 ;;
    --name) SERVER_NAME="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    *) echo "Unbekannte Option: $1"; exit 1 ;;
  esac
done

[ "$(id -u)" -eq 0 ] || { echo "Bitte als root ausführen."; exit 1; }
[ -f "$APP_DIR/index.php" ] && [ -f "$APP_DIR/lib/engine.php" ] || { echo "Skript muss im geklonten Repository liegen ($APP_DIR)."; exit 1; }
if [ -n "$IMPORT" ] && [ ! -f "$IMPORT" ]; then echo "Backup $IMPORT nicht gefunden."; exit 1; fi

echo "==> Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-sqlite3 php-curl php-mbstring php-intl \
  iputils-ping cron git curl ca-certificates libcap2-bin >/dev/null

php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);' || { echo "PHP-Erweiterungen fehlen (pdo_sqlite/curl)."; exit 1; }
php -r 'exit(function_exists("pcntl_fork") ? 0 : 1);' || echo "Hinweis: pcntl fehlt im PHP-CLI – Sensoren werden dann nacheinander statt parallel geprüft."

echo "==> Apache einrichten ($APP_DIR)"
a2enmod -q rewrite headers remoteip >/dev/null
cat > /etc/apache2/sites-available/signalwarte.conf <<EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    DocumentRoot ${APP_DIR}

    # Echte Client-IP hinter einem Reverse-Proxy (HAProxy, nginx, pfSense …)
    RemoteIPHeader X-Forwarded-For
    RemoteIPInternalProxy 127.0.0.1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16

    <Directory ${APP_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <DirectoryMatch "^${APP_DIR}/(\.git|data|lib|cron|agent|install|tools)">
        Require all denied
    </DirectoryMatch>

    ErrorLog \${APACHE_LOG_DIR}/signalwarte-error.log
    CustomLog \${APACHE_LOG_DIR}/signalwarte-access.log combined
</VirtualHost>
EOF
a2ensite -q signalwarte >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true

echo "==> Daten & Rechte"
mkdir -p "$APP_DIR/data"
if [ -n "$IMPORT" ]; then
  if [ -f "$APP_DIR/data/monitor.sqlite" ]; then
    mv "$APP_DIR/data/monitor.sqlite" "$APP_DIR/data/monitor.sqlite.vor-import.$(date +%s)"
  fi
  rm -f "$APP_DIR/data/monitor.sqlite-wal" "$APP_DIR/data/monitor.sqlite-shm"
  cp "$IMPORT" "$APP_DIR/data/monitor.sqlite"
  echo "    Backup übernommen: $IMPORT"
fi
chown -R www-data:www-data "$APP_DIR"
chmod 770 "$APP_DIR/data"
git config --system --add safe.directory "$APP_DIR" 2>/dev/null || true

echo "==> Ping für www-data prüfen"
if ! su -s /bin/sh www-data -c "ping -c1 -W1 127.0.0.1" >/dev/null 2>&1; then
  setcap cap_net_raw+ep "$(command -v ping)" || true
  su -s /bin/sh www-data -c "ping -c1 -W1 127.0.0.1" >/dev/null 2>&1 \
    && echo "    ping per Capability freigeschaltet" \
    || echo "    WARNUNG: ping funktioniert für www-data nicht (LXC: ggf. 'sysctl net.ipv4.ping_group_range=\"0 2147483647\"')"
fi

echo "==> Datenbank initialisieren / migrieren"
su -s /bin/sh www-data -c "php -r 'require \"$APP_DIR/lib/engine.php\"; echo \"    Schema v\", db()->query(\"PRAGMA user_version\")->fetchColumn(), PHP_EOL;'"

echo "==> Poller (Cron) einrichten"
echo "* * * * * www-data php ${APP_DIR}/cron/poller.php >/dev/null 2>&1" > /etc/cron.d/signalwarte
chmod 644 /etc/cron.d/signalwarte
systemctl enable --now cron >/dev/null 2>&1 || service cron start >/dev/null 2>&1 || true

systemctl restart apache2 2>/dev/null || service apache2 restart
su -s /bin/sh www-data -c "php $APP_DIR/cron/poller.php --once" >/dev/null 2>&1 || true

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "Fertig. Signalwarte läuft unter:  http://${IP:-<IP>}/"
if [ -z "$IMPORT" ]; then
  echo "Beim ersten Aufruf das Admin-Konto anlegen (Ersteinrichtung)."
else
  echo "Anmelden mit den bisherigen Zugangsdaten."
fi
echo "Update später:  cd $APP_DIR && git pull && chown -R www-data:www-data ."
echo "Firewall: Diesem Server ICMP, TCP (Web/Ports/49000) und UDP 161 in alle zu überwachenden Netze erlauben."
