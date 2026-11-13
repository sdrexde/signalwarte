# Signalwarte – Installationsanleitung

Schritt-für-Schritt-Anleitung für eine Installation auf einem **eigenen Proxmox-LXC** (oder einer VM) mit
**Debian 13 (trixie)**. Sie ist so geschrieben, dass sie von einem Menschen **oder einem KI-Agenten** (z. B. Maik/OpenClaw,
Tom/Hermes) linear abgearbeitet werden kann: Jeder Schritt hat einen **Prüfbefehl** mit der **erwarteten Ausgabe**.
Weiter erst, wenn die Prüfung passt.

> Getestet: `install/install.sh` auf einem frischen Debian 13, inkl. Datenübernahme aus einer bestehenden Instanz.

---

## Inhalt

1. [Überblick & Voraussetzungen](#1-überblick--voraussetzungen)
2. [LXC anlegen (Proxmox)](#2-lxc-anlegen-proxmox)
3. [Code von GitHub holen](#3-code-von-github-holen)
4. [Installieren](#4-installieren)
5. [Daten von einer bestehenden Instanz übernehmen (Umzug)](#5-daten-von-einer-bestehenden-instanz-übernehmen-umzug)
6. [Prüfen, ob alles läuft](#6-prüfen-ob-alles-läuft)
7. [Reverse-Proxy / HTTPS (HAProxy auf pfSense)](#7-reverse-proxy--https-haproxy-auf-pfsense)
8. [Firewall-Regeln (pfSense)](#8-firewall-regeln-pfsense)
9. [Erste Schritte in der Oberfläche](#9-erste-schritte-in-der-oberfläche)
10. [Alte Instanz abschalten (nach Umzug)](#10-alte-instanz-abschalten-nach-umzug)
11. [Updates](#11-updates)
12. [Backup & Wiederherstellung](#12-backup--wiederherstellung)
13. [Fehlersuche](#13-fehlersuche)
14. [Deinstallation](#14-deinstallation)
15. [Kurzfassung für Agenten](#15-kurzfassung-für-agenten)

---

## 1. Überblick & Voraussetzungen

| Komponente | Wert |
|---|---|
| Betriebssystem | Debian 13 (empfohlen) oder Debian 12 / Ubuntu 22.04+ |
| Ressourcen | 1 vCPU, 512 MB–1 GB RAM, 4 GB Disk (Messwerte: ca. 1 MB je Sensor und Monat) |
| Webserver | Apache 2 + PHP ≥ 8.1 (`pdo_sqlite`, `curl`, `mbstring`, `intl`, `pcntl` im CLI) |
| Datenbank | SQLite-Datei unter `<App>/data/monitor.sqlite` – kein Datenbankserver nötig |
| Hintergrunddienst | Cron startet jede Minute `cron/poller.php` als `www-data` |
| Installationspfad | `/var/www/signalwarte` (Webroot = dieser Ordner, App unter `/`) |
| Netzwerk | Der LXC muss **alle zu überwachenden Netze erreichen** (siehe [Abschnitt 8](#8-firewall-regeln-pfsense)) |

Was das Installationsskript `install/install.sh` erledigt:

- Pakete installieren: `apache2 libapache2-mod-php php-cli php-sqlite3 php-curl php-mbstring php-intl iputils-ping cron git curl ca-certificates libcap2-bin`
- Apache-vHost `/etc/apache2/sites-available/signalwarte.conf` anlegen (DocumentRoot = App-Ordner, `mod_remoteip` für die echte Client-IP hinter dem Proxy, interne Ordner gesperrt), `000-default` deaktivieren
- Rechte setzen (`www-data`, `data/` = 770)
- `ping` für `www-data` freischalten (Capability `cap_net_raw`, nötig in unprivilegierten LXC)
- Datenbank anlegen bzw. migrieren
- Cron-Datei `/etc/cron.d/signalwarte` anlegen und den Poller einmal starten
- optional: ein Backup importieren (`--import`)

---

## 2. LXC anlegen (Proxmox)

### 2a. Über die Proxmox-Oberfläche

1. *Create CT*: Hostname z. B. `monitor`, **Unprivileged container: ja**, Passwort/SSH-Key setzen
2. Template: **debian-13-standard**
3. Disk: 4–8 GB · CPU: 1 · RAM: 1024 MB, Swap 512 MB
4. Netzwerk: Bridge in das Netz, in dem der Monitor stehen soll (z. B. Management- oder LAN-Bridge), **statische IPv4** (z. B. `192.168.11.30/24`, Gateway = pfSense), DNS = interner DNS
5. *Start after created*

### 2b. Per Kommandozeile auf dem Proxmox-Host

```bash
pveam update
pveam available --section system | grep debian-13          # exakten Template-Namen ablesen
pveam download local debian-13-standard_<VERSION>_amd64.tar.zst

pct create 140 local:vztmpl/debian-13-standard_<VERSION>_amd64.tar.zst \
  --hostname monitor --unprivileged 1 --features nesting=1 \
  --cores 1 --memory 1024 --swap 512 --rootfs local-lvm:8 \
  --net0 name=eth0,bridge=vmbr0,ip=192.168.11.30/24,gw=192.168.11.250 \
  --nameserver 192.168.11.200 --onboot 1 --start 1
pct enter 140
```

IDs, Bridge, IP, Gateway und DNS an das eigene Netz anpassen.

**Prüfen** (im LXC):

```bash
cat /etc/debian_version          # erwartet: 13.x
ping -c1 -W2 deb.debian.org      # erwartet: 1 received
```

---

## 3. Code von GitHub holen

Das Repository `sdrexde/signalwarte` ist privat → Zugriff per **Deploy-Key** (nur Lesen).

```bash
apt-get update && apt-get install -y git openssh-client
ssh-keygen -t ed25519 -N "" -C "signalwarte@$(hostname)" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Den ausgegebenen Schlüssel auf GitHub eintragen: **Repository → Settings → Deploy keys → Add deploy key**,
Titel z. B. `monitor-lxc`, **„Allow write access“ NICHT anhaken** (nur Lesen genügt).

Falls ausgehend Port 22 gesperrt ist (häufig), SSH über Port 443 leiten:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  HostName ssh.github.com
  Port 443
  User git
  IdentityFile ~/.ssh/id_ed25519
EOF
chmod 600 ~/.ssh/config
```

**Prüfen:**

```bash
ssh -o StrictHostKeyChecking=accept-new -T git@github.com
# erwartet: "Hi sdrexde/signalwarte! You've successfully authenticated, but GitHub does not provide shell access."
```

Klonen:

```bash
git clone git@github.com:sdrexde/signalwarte.git /var/www/signalwarte
ls /var/www/signalwarte/install/install.sh      # erwartet: Pfad wird angezeigt
```

---

## 4. Installieren

**Neuinstallation (leer):**

```bash
bash /var/www/signalwarte/install/install.sh
```

**Mit Datenübernahme** (Backup aus [Abschnitt 5](#5-daten-von-einer-bestehenden-instanz-übernehmen-umzug)):

```bash
bash /var/www/signalwarte/install/install.sh --import /root/signalwarte.sqlite
```

Optional `--name monitor.example.de` für den `ServerName` im vHost.

Erwartete letzte Zeilen der Ausgabe:

```
==> Datenbank initialisieren / migrieren
    Schema v5            (oder höher)
==> Poller (Cron) einrichten
Fertig. Signalwarte läuft unter:  http://<IP>/
```

Das Skript kann gefahrlos **erneut ausgeführt** werden (z. B. nach einer Netzwerkänderung). Mit `--import` wird eine vorhandene
Datenbank vorher als `data/monitor.sqlite.vor-import.<Zeitstempel>` gesichert.

---

## 5. Daten von einer bestehenden Instanz übernehmen (Umzug)

Auf dem **alten** Server (Beispiel: bisherige Instanz unter `/var/www/html/maik/monitor` auf nexus/webclaude):

```bash
bash /var/www/html/maik/monitor/tools/backup.sh /root/signalwarte.sqlite
# erwartet: "Backup: /root/signalwarte.sqlite (… K)"
scp /root/signalwarte.sqlite root@<LXC-IP>:/root/
```

Das Backup wird im laufenden Betrieb konsistent erstellt (SQLite `VACUUM INTO`) und enthält **alles**: Geräte, Sensoren,
Messwerte, Maps, Benutzer inkl. 2FA, Benachrichtigungen, Icons, Zugangsdaten. → **Vertraulich behandeln**, nach dem Import löschen.

Dann auf dem LXC [Schritt 4](#4-installieren) mit `--import /root/signalwarte.sqlite` ausführen und anschließend:

```bash
shred -u /root/signalwarte.sqlite   # Backup-Kopie entfernen
```

Alternative ohne SSH zwischen den Servern: In der alten Instanz **Einstellungen › Datensicherung › Datenbank herunterladen (.sqlite)**,
Datei per Browser/WinSCP auf den LXC kopieren.

---

## 6. Prüfen, ob alles läuft

Alle Befehle im LXC ausführen:

```bash
APP=/var/www/signalwarte

# 1) Weboberfläche antwortet (302 = Weiterleitung zu Login/Ersteinrichtung)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/              # erwartet: 302
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/login.php     # erwartet: 200 (bzw. 302 → setup.php bei leerer Installation)

# 2) Interne Ordner sind gesperrt
for p in data/monitor.sqlite lib/db.php cron/poller.php agent/SignalwarteAgent.ps1 tools/backup.sh install/install.sh; do
  printf '%s %s\n' "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/$p)" "$p"
done                                                                     # erwartet: überall 403

# 3) Datenbank-Schema
su -s /bin/sh www-data -c "php -r 'require \"$APP/lib/engine.php\"; echo db()->query(\"PRAGMA user_version\")->fetchColumn(), PHP_EOL;'"
                                                                         # erwartet: 5 (oder höher)

# 4) Poller läuft per Cron
cat /etc/cron.d/signalwarte                                           # erwartet: * * * * * www-data php …/cron/poller.php …
su -s /bin/sh www-data -c "php $APP/cron/poller.php --once --verbose"    # erwartet: Liste geprüfter Sensoren (oder keine Ausgabe, wenn nichts fällig)

# 5) ping funktioniert für www-data
su -s /bin/sh www-data -c "ping -c1 -W1 127.0.0.1" | tail -1             # erwartet: rtt min/avg/max …

# 6) Rechte
stat -c '%U:%G %a %n' $APP/data $APP/data/monitor.sqlite                 # erwartet: www-data:www-data 770 … / www-data:www-data …
```

In der Oberfläche: **Einstellungen › System** zeigt beim Poller „läuft“ und „letzter Lauf vor < 60 s“.

---

## 7. Reverse-Proxy / HTTPS (HAProxy auf pfSense)

Der Monitor selbst spricht nur HTTP auf Port 80. HTTPS wird am vorgelagerten Proxy terminiert.

**pfSense → Services → HAProxy:**

1. **Backend** `signalwarte`: Server `<LXC-IP>` Port `80`, Health-Check `HTTP` auf `/login.php`
2. **Frontend** (bestehendes HTTPS-Frontend): ACL `host matches monitor.example.de` → Backend `signalwarte`
3. Im Frontend „**Use forwardfor option**“ aktivieren (setzt `X-Forwarded-For`)
4. Zusätzlich (Advanced / Backend pass thru): `http-request set-header X-Forwarded-Proto https`

> **Wichtig:** Ohne `X-Forwarded-Proto: https` erzeugt der Monitor `http://`-Links – betroffen sind vor allem die
> Download-Links der Windows-Agenten und Push-URLs. Ohne `X-Forwarded-For` sieht der Monitor nur die Proxy-IP; die
> Anmeldesperre (5 Fehlversuche) würde dann alle Benutzer gemeinsam treffen.

**Prüfen** (von außen):

```bash
curl -sI https://monitor.example.de/login.php | grep -i set-cookie
# erwartet: …SIGNALWARTESESS=…; path=/; secure; HttpOnly; SameSite=Lax   ← "secure" = HTTPS wird erkannt
```

Andere Proxys (nginx, Caddy, Traefik) funktionieren genauso: auf `http://<LXC-IP>:80` weiterleiten und `X-Forwarded-For`
sowie `X-Forwarded-Proto` setzen. Die erlaubten Proxy-Netze (`RemoteIPInternalProxy`) stehen in
`/etc/apache2/sites-available/signalwarte.conf` (Standard: 127.0.0.1, 10/8, 172.16/12, 192.168/16).

---

## 8. Firewall-Regeln (pfSense)

Alias **`Monitor`** = IP des LXC. Regeln auf **jedem Interface**, dessen Netz überwacht werden soll
(bzw. auf dem Interface des Monitors mit Ziel = überwachte Netze):

| Quelle | Ziel | Protokoll / Port | Zweck |
|---|---|---|---|
| Monitor | überwachte Netze | ICMP Echo Request | Ping-Sensoren, Netzwerkscan |
| Monitor | überwachte Netze | TCP (alle oder Port-Alias) | Port-/HTTP(S)-/SSL-Sensoren, Scan, FRITZ!Box TR-064 (49000) |
| Monitor | überwachte Netze + *This Firewall* | UDP 161 | SNMP (pfSense, Switches, NAS …) |
| Monitor | *This Firewall* | TCP 4444 (Web-GUI-Port) | pfSense-Weboberfläche + Zertifikat |
| Windows-Server | Monitor-URL (Proxy) | TCP 443 | Windows-Agent meldet sich |
| Proxy (HAProxy) | Monitor | TCP 80 | Weboberfläche |

pfSense-SNMP: **Services → SNMP** aktivieren, Community setzen, Module *MibII*, *Host Resources*, *pf* anhaken,
an das Interface binden, auf dem der Monitor anfragt.

FRITZ!Box: **Heimnetz → Netzwerk → Netzwerkeinstellungen**: „Zugriff für Anwendungen zulassen“ und
„Statusinformationen über UPnP übertragen“ aktivieren.

**Prüfen** (im LXC, Beispiel-IPs anpassen):

```bash
ping -c1 -W2 192.168.11.250                       # pfSense erreichbar
timeout 3 bash -c 'echo > /dev/tcp/192.168.11.250/4444' && echo "4444 offen"
timeout 3 bash -c 'echo > /dev/tcp/192.168.101.254/49000' && echo "TR-064 offen"
```

---

## 9. Erste Schritte in der Oberfläche

1. `https://monitor.example.de/` öffnen
   - **Neuinstallation:** Ersteinrichtung → Admin-Konto anlegen (optional mit Beispieldaten)
   - **Umzug:** mit den bisherigen Zugangsdaten anmelden (inkl. 2FA)
2. **Einstellungen › Mein Konto → 2FA einrichten** (dringend empfohlen), Wiederherstellungscodes sicher ablegen
3. **Einstellungen › System › Sicherheit**: ggf. 2FA für alle vorschreiben
4. **Geräte → Netzwerk scannen**: Netze eintragen, DHCP-Bereiche als Ausschluss hinterlegen, Geräte übernehmen
5. **Einstellungen › Benachrichtigungen**: mindestens zwei Wege (z. B. Telegram + E-Mail)
6. Windows-Server: Sensor „Windows-Agent“ anlegen → in den Sensor-Details `SignalwarteAgent.ps1` herunterladen und auf dem Server ausführen

---

## 10. Alte Instanz abschalten (nach Umzug)

Erst wenn die neue Instanz läuft und geprüft ist – auf dem **alten** Server:

```bash
rm -f /etc/cron.d/signalwarte /etc/cron.d/reload-monitor   # (alter Name) – sonst prüfen beide Instanzen und senden doppelte Benachrichtigungen
```

Optional die alte Weboberfläche entfernen bzw. per Proxy auf die neue umleiten.

**Windows-Agenten:** Ändert sich die Adresse (z. B. `nexus.sdrex.de/monitor` → `monitor.sdrex.de`), melden die installierten
Agenten weiter an die alte URL. Dann je Server den Agenten aus der **neuen** Oberfläche (Sensor-Details) neu herunterladen
und ausführen – er überschreibt die Installation. Bleibt die Adresse gleich (Proxy zeigt nur auf den neuen LXC), ist nichts zu tun.

---

## 11. Updates

```bash
cd /var/www/signalwarte
git pull --ff-only
chown -R www-data:www-data .
```

Datenbank-Migrationen laufen beim nächsten Aufruf automatisch. Danach im Browser einmal neu laden (Strg+F5).
Prüfen: Version steht unter **Einstellungen › System** unten.

---

## 12. Backup & Wiederherstellung

| Was | Wie |
|---|---|
| Komplett (empfohlen, regelmäßig) | `bash /var/www/signalwarte/tools/backup.sh /root/backup/monitor-$(date +%F).sqlite` |
| Komplett per Browser | Einstellungen › Datensicherung › Datenbank herunterladen |
| Nur Konfiguration (JSON) | Einstellungen › Datensicherung › Export (optional mit Zugangsdaten) |
| Wiederherstellen (komplett) | `bash install/install.sh --import <datei.sqlite>` |
| Wiederherstellen (JSON) | Einstellungen › Datensicherung › Import (Ergänzen oder Ersetzen) |

Beispiel für ein nächtliches Backup mit 14 Tagen Aufbewahrung:

```bash
mkdir -p /root/backup
cat > /etc/cron.d/signalwarte-backup <<'EOF'
15 3 * * * root bash /var/www/signalwarte/tools/backup.sh /root/backup/monitor-$(date +\%F).sqlite >/dev/null 2>&1; find /root/backup -name 'monitor-*.sqlite' -mtime +14 -delete
EOF
```

Zusätzlich sichert natürlich auch ein Proxmox-Backup des LXC alles mit.

---

## 13. Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| Gelbe Leiste „Poller läuft nicht“ | `systemctl status cron`; `cat /etc/cron.d/signalwarte`; manuell: `su -s /bin/sh www-data -c "php /var/www/signalwarte/cron/poller.php --once --verbose"` |
| Alle Ping-Sensoren „Fehler“ | `su -s /bin/sh www-data -c "ping -c1 127.0.0.1"` – wenn das scheitert: `setcap cap_net_raw+ep $(command -v ping)` oder `sysctl -w net.ipv4.ping_group_range="0 2147483647"` |
| Sensoren in anderen Netzen „Fehler“, lokal OK | Firewall-Regeln ([Abschnitt 8](#8-firewall-regeln-pfsense)); Test mit `ping` / `/dev/tcp` aus dem LXC |
| SNMP „Zeitüberschreitung“ | Community falsch, SNMP-Dienst aus, UDP 161 blockiert oder an falsches Interface gebunden |
| FRITZ!Box „Keine TR-064/UPnP-Daten“ | Optionen aus [Abschnitt 8](#8-firewall-regeln-pfsense) in der FRITZ!Box aktivieren; Port 49000 erreichbar? |
| „Datenbankfehler … readonly“ / Fehler 500 | Rechte: `chown -R www-data:www-data /var/www/signalwarte && chmod 770 /var/www/signalwarte/data` – **nie als root gegen die Live-DB arbeiten** (WAL-Dateien gehören dann root) |
| Windows-Agent meldet nicht | Auf dem Server: `C:\ProgramData\SignalwarteAgent\SignalwarteAgent.ps1 -Status` bzw. `-Test`; Proxy setzt `X-Forwarded-Proto`? Server erreicht Monitor-URL per HTTPS? |
| Anmeldung gesperrt | `su -s /bin/sh www-data -c "php /var/www/signalwarte/tools/user-admin.php unlock"` |
| 2FA verloren (Handy + Codes) | `su -s /bin/sh www-data -c "php /var/www/signalwarte/tools/user-admin.php reset-2fa <benutzer>"` |
| Passwort vergessen | `su -s /bin/sh www-data -c "php /var/www/signalwarte/tools/user-admin.php set-password <benutzer> '<neu>'"` |

Logs: `/var/log/apache2/signalwarte-error.log`, in der Oberfläche **Protokoll** (Statuswechsel, Anmeldungen, Benachrichtigungen).

---

## 14. Deinstallation

```bash
rm -f /etc/cron.d/signalwarte /etc/cron.d/signalwarte-backup
a2dissite signalwarte && systemctl reload apache2
rm -f /etc/apache2/sites-available/signalwarte.conf
rm -rf /var/www/signalwarte            # löscht auch die Datenbank – vorher Backup!
```

Den Deploy-Key auf GitHub entfernen (Settings → Deploy keys).

---

## 15. Kurzfassung für Agenten

Für automatisierte Ausführung (z. B. Maik/OpenClaw per SSH auf dem neuen LXC). **Annahmen:** Debian 13 LXC läuft,
Agent ist root, Deploy-Key ist auf GitHub eingetragen (Schritt 3 erfordert einmalig einen Menschen oder GitHub-Zugriff).
Jeder Schritt muss mit Exit-Code 0 enden, sonst abbrechen und melden.

```bash
set -euo pipefail
APP=/var/www/signalwarte

# 1. Voraussetzungen
apt-get update -qq && apt-get install -y -qq git openssh-client curl
test -f ~/.ssh/id_ed25519 || { echo "FEHLT: Deploy-Key (Abschnitt 3)"; exit 1; }
{ ssh -o StrictHostKeyChecking=accept-new -T git@github.com 2>&1 || true; } | grep -q "successfully authenticated"

# 2. Code
test -d $APP/.git || git clone git@github.com:sdrexde/signalwarte.git $APP
git -C $APP pull --ff-only

# 3. Installation (mit Import, falls Backup vorhanden)
if [ -f /root/signalwarte.sqlite ]; then
  bash $APP/install/install.sh --import /root/signalwarte.sqlite
else
  bash $APP/install/install.sh
fi

# 4. Abnahme – alle Prüfungen müssen passen
test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/)" = "302"
test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/data/monitor.sqlite)" = "403"
test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/lib/db.php)" = "403"
su -s /bin/sh www-data -c "php $APP/cron/poller.php --once"
test -f /etc/cron.d/signalwarte
su -s /bin/sh www-data -c "ping -c1 -W1 127.0.0.1" >/dev/null
echo "OK: Signalwarte installiert unter http://$(hostname -I | awk '{print $1}')/"
```

Danach an den Menschen melden: LXC-IP, ob mit Import installiert wurde, und die **offenen manuellen Schritte**:
Reverse-Proxy (Abschnitt 7), Firewall-Regeln (Abschnitt 8), alte Instanz abschalten (Abschnitt 10),
2FA-Einrichtung (Abschnitt 9). **Keine** Passwörter, Tokens oder Backup-Dateien in Chats oder Tickets posten.
