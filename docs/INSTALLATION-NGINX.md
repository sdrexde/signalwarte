# Signalwarte – Installation mit Nginx (Alternative zu Apache)

Für Debian 13 LXC mit **Nginx** statt Apache.

## 1. Voraussetzungen

```bash
apt-get update
apt-get install -y php8.4 php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-curl php8.4-mbstring sqlite3 nginx git curl ca-certificates libcap2-bin cron
```

## 2. Code holen

```bash
git clone https://github.com/sdrexde/signalwarte.git /opt/signalwarte
cd /opt/signalwarte
```

## 3. Nginx-Config

`/etc/nginx/sites-available/signalwarte`:

```nginx
server {
    listen 80;
    server_name _;
    root /opt/signalwarte;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /data/  { deny all; return 403; }
    location ~ /lib/   { deny all; return 403; }
    location ~ /cron/  { deny all; return 403; }
    location ~ /agent/ { deny all; return 403; }
    location ~ /tools/ { deny all; return 403; }
    location ~ /install/ { deny all; return 403; }
}
```

Aktivieren:

```bash
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/signalwarte /etc/nginx/sites-enabled/signalwarte
systemctl enable nginx php8.4-fpm
systemctl restart nginx php8.4-fpm
```

## 4. Rechte

```bash
mkdir -p /opt/signalwarte/data
chown -R www-data:www-data /opt/signalwarte/data
chmod 770 /opt/signalwarte/data
```

## 5. Ping für www-data freischalten (WICHTIG!)

In unprivilegierten LXC muss das `ping`-Binary die Capability `cap_net_raw` haben, sonst können Ping-Sensoren nicht funktionieren (der Poller läuft als `www-data`, nicht root):

```bash
setcap cap_net_raw+ep /usr/bin/ping
```

**Prüfen:**
```bash
su -s /bin/sh www-data -c "ping -c1 -W1 127.0.0.1"
# erwartet: 0% packet loss
```

## 6. Cron (Poller)

```bash
echo '* * * * * www-data php /opt/signalwarte/cron/poller.php >/dev/null 2>&1' > /etc/cron.d/signalwarte
chmod 644 /etc/cron.d/signalwarte
```

**Prüfen:**
```bash
systemctl status cron
su -s /bin/sh www-data -c "php /opt/signalwarte/cron/poller.php --once --verbose"
```

## 7. Erst-Einrichtung

Im Browser `http://<LXC-IP>/` öffnen → Weiterleitung zu `setup.php` → Admin-Konto anlegen.

---

## Bekannte Probleme

### Poller Status "läuft nicht" trotz Cron

**Ursache:** Wenn der Poller manuell als `root` getestet wird (`php cron/poller.php --once`), legt er die Datei `data/poller.lock` mit `root:root` an. Der Cron läuft aber als `www-data` und kann diese Lock-Datei weder lesen noch überschreiben → jeder Cron-Lauf bricht ab.

**Fix:**
```bash
rm -f /opt/signalwarte/data/poller.lock
chown -R www-data:www-data /opt/signalwarte/data
```

Danach läuft der Poller wieder sauber.

### Alle Ping-Sensoren "Fehler" (rot), TCP-Sensoren grün

**Ursache:** `www-data` darf keine ICMP-Pakete senden, weil `/usr/bin/ping` die Capability `cap_net_raw` fehlt.

**Fix:**
```bash
setcap cap_net_raw+ep /usr/bin/ping
```

Danach Ping-Sensoren wieder grün.
