# Signalwarte System-Agenten (Windows & Linux)

Kleine Client-Skripte, die auf einem Server laufen und **Messwerte** (CPU, RAM, Laufwerke, Dienste, Updates …) sowie
**Systeminformationen** (Hersteller, Modell, Seriennummer, CPU, IPs, MACs …) per HTTPS an Signalwarte melden.
Funktioniert in jedem Netz, aus dem der Server die Signalwarte-Adresse per HTTPS erreicht – keine eingehenden Ports nötig.

## Einrichten – der schnellste Weg (Registrierungs-Token)

**Menü „Agenten“ → „Server anbinden“** → Reiter *Windows* oder *Linux* → Befehl kopieren → auf dem Server ausführen. Fertig.

Der Befehl enthält den **Registrierungs-Token**. Bei der Installation meldet sich der Agent damit selbst an:
Signalwarte legt das **Gerät** (Hostname, IP, Icon, Ping-Sensor) und den **System-Agent-Sensor** automatisch an –
oder ordnet ihn einem vorhandenen Gerät mit gleicher IP bzw. gleichem Hostnamen zu. Derselbe Befehl funktioniert für
beliebig viele Server.

Unter „Einstellungen für die Selbstanmeldung“: Zielgruppe für neue Server, Standard-Dienste je Plattform,
Selbstanmeldung an/aus, Selbst-Update an/aus, Token neu erzeugen (alte Befehle werden ungültig, installierte Agenten laufen weiter).

Per MCP: `agent_enroll_command` liefert diese Befehle; `list_agents` zeigt alle Agenten mit CPU, RAM, Platten, Updates.

## Alternative: gerätegebunden

Gerät in Signalwarte anlegen, Sensor **„System-Agent (Windows/Linux)“** hinzufügen (Vorlagen *Windows-Server*,
*Domänencontroller*, *Linux-Server* bringen ihn mit), Sensor öffnen → Kasten *Agent installieren*. Oder in der
Agenten-Übersicht beim jeweiligen Server auf **„Installbefehl“** (z. B. zum Reparieren). Per MCP: `agent_install`.

## Selbst-Update

Bei jeder Meldung teilt Signalwarte dem Agenten mit, ob es eine neuere Version gibt (die Version steht in der
Skript-Vorlage auf dem Server, also nach `git pull` automatisch aktuell). Dann:

1. neue Version über den eigenen, tokengeschützten Link laden,
2. **Syntax prüfen** (Linux `bash -n`, Windows PowerShell-Parser) und Versionsnummer abgleichen – sonst verwerfen,
3. alte Version als `….bak` sichern, ersetzen,
4. neu starten (Linux: Dienst ersetzt sich selbst, Windows: Neustart über Mutex ohne Doppelinstanz).

In der Agenten-Übersicht zeigt ein gelbes Kennzeichen „→ 1.x.y“ Agenten, die beim nächsten Melden aktualisiert werden.
Abschaltbar unter *Agenten › Einstellungen für die Selbstanmeldung*. Getestet: Update im Cron- und im Dienstbetrieb,
fehlerhaftes Update wird verworfen und die laufende Version bleibt.

### Windows (Windows Server 2012 R2+ / Windows 10+, PowerShell 5.1+)

In einer **Administrator-PowerShell**:
```powershell
iwr -UseBasicParsing '<Download-Link>' -OutFile "$env:TEMP\SignalwarteAgent.ps1"; powershell -ExecutionPolicy Bypass -File "$env:TEMP\SignalwarteAgent.ps1"
```
oder `SignalwarteAgent.ps1` herunterladen → Rechtsklick → *Mit PowerShell ausführen* (fordert Adminrechte an).

- Installiert nach `C:\ProgramData\SignalwarteAgent` (nur SYSTEM/Administratoren haben Zugriff, enthält das Token)
- geplante Aufgabe `SignalwarteAgent` unter SYSTEM, startet mit Windows, Wächter alle 5 Minuten
- ersetzt automatisch einen älteren `ReloadAgent`

```powershell
C:\ProgramData\SignalwarteAgent\SignalwarteAgent.ps1 -Status      # Zustand, letzte Meldung, Log
C:\ProgramData\SignalwarteAgent\SignalwarteAgent.ps1 -Test        # Werte anzeigen und senden
C:\ProgramData\SignalwarteAgent\SignalwarteAgent.ps1 -Uninstall   # entfernen
```

### Linux (Debian/Ubuntu, RHEL/Alma/Rocky, Proxmox, LXC; bash + curl)

Als root:
```bash
curl -fsSL '<Download-Link>&os=linux' -o /tmp/signalwarte-agent.sh && sudo bash /tmp/signalwarte-agent.sh install
```

- Installiert nach `/opt/signalwarte-agent/`, Konfiguration `/etc/signalwarte-agent.conf` (nur root lesbar)
- systemd-Dienst `signalwarte-agent` (Neustart bei Absturz); ohne systemd ein Cron-Job alle 2 Minuten (Cron wird bei Bedarf installiert)
- Log: `/var/log/signalwarte-agent.log`, Zustand: `/var/lib/signalwarte-agent/`

```bash
sudo /opt/signalwarte-agent/signalwarte-agent.sh status      # Zustand, letzte Meldung, überwachte Dienste
sudo /opt/signalwarte-agent/signalwarte-agent.sh test        # Werte anzeigen und senden
sudo /opt/signalwarte-agent/signalwarte-agent.sh uninstall   # entfernen
bash signalwarte-agent.sh collect "nginx,ssh"                # nur sammeln und ausgeben (Diagnose, sendet nichts)
```

## Was gemeldet wird

| Kanal | Windows | Linux | Bewertung in Signalwarte |
|---|---|---|---|
| CPU % | ✔ | ✔ | Grenzwert (Standard: Warnung > 90 %) |
| Load 1m/5m/15m, CPU-Kerne | – | ✔ | – |
| RAM %, RAM frei/gesamt GB, Swap % | ✔ | ✔ | – |
| Frei `<Laufwerk>` % / GB | ✔ (C:, D: …) | ✔ (/, /var …) | Warnung < 15 %, Fehler < 5 % (im Sensor einstellbar) |
| Laufzeit (Tage), Prozesse | ✔ | ✔ | – |
| Angemeldete Benutzer | – | ✔ | – |
| Netz Eingang/Ausgang Mbit/s | ✔ | ✔ | – |
| CPU-Temperatur °C | – | ✔ (falls verfügbar) | – |
| Auto-Dienste gestoppt | ✔ | – | Warnung (mit Namen) |
| Dienste fehlgeschlagen (systemd) | – | ✔ | Warnung (mit Namen) |
| Dienst `<Name>` (überwacht) | ✔ | ✔ | **Fehler**, wenn nicht läuft |
| Neustart ausstehend | ✔ | ✔ | Warnung |
| Ereignis-Fehler 1h | ✔ | – | – |
| Tage seit letztem Update | ✔ | – | – |
| Updates ausstehend / Sicherheitsupdates | ✔ (Windows Update, alle 6 h, inkl. „Kritische Updates“) | ✔ (apt/dnf, stündlich) | – (in der Agenten-Übersicht rot markiert) |
| Defender aktiv / Signatur (Tage) | ✔ | – | Warnung, wenn Echtzeitschutz aus |

Alle Kanäle haben einen eigenen Verlauf (Sensor-Details → Kanal wählen) und lassen sich in Maps als Wert einer
Status-Kachel anzeigen.

**Systeminformationen** (Gerät → „Details & Systeminfo“, Sensor-Details, MCP `get_device`):
Betriebssystem, Kernel/Build, Architektur, Hersteller, Modell, Seriennummer, BIOS, Virtualisierung, CPU, Threads, RAM,
Hostname/FQDN, Domäne (Windows), IP-Adressen, MAC-Adressen, Gateway, DNS, letzter Start, Installationsdatum (Windows),
Zeitzone, Laufwerke mit Größe/frei, Agent-Version.

## Einzelne Werte als eigene Sensoren (z. B. nur RAM oder nur Festplatte C:)

Der System-Agent liefert alle Werte gesammelt. Für einen einzelnen Wert mit **eigenem Grenzwert, Alarm, Verlauf und
eigener Kachel** (auch in Maps):

- **Am schnellsten:** System-Agent-Sensor öffnen → hinter jedem Wert **„＋ Sensor“** klicken, oder oben
  **„＋ Standard-Sensoren anlegen“** (CPU, RAM, alle Festplatten in %, Sicherheitsupdates, kritische Updates, Neustart, fehlgeschlagene Dienste).
- **Manuell:** Gerät → „+ Sensor“ → Typ **„Agent-Wert (einzelner Wert)“** → Wert aus der **Auswahlliste** wählen (dort
  stehen alle Werte, die der Agent dieses Geräts meldet, mit aktuellem Stand). Name und Grenzwerte werden vorgeschlagen.

Vorgeschlagene Grenzwerte: CPU > 80/95 %, RAM > 85/95 %, Festplatte frei < 15/5 %, Swap > 50 %, Sicherheitsupdates/
kritische Updates/Neustart/fehlgeschlagene Dienste > 0 → Warnung, Tage seit letztem Update > 45, Defender aus → Fehler,
CPU-Temperatur > 80/90 °C. Meldet der Agent nicht mehr, gehen auch die Einzelsensoren auf Fehler.

Auch der **Hauptwert** des System-Agent-Sensors (was Kachel und Graph zeigen) wird jetzt aus dieser Liste gewählt.

## Linux: fehlgeschlagene Units in Containern

In LXC- und Docker-Containern schlagen einige Kernel-Mounts systembedingt fehl, z. B. `sys-kernel-config.mount`, und
lösen dann die Warnung „systemd-Dienst(e) fehlgeschlagen“ aus. Ab Version 1.1.1 erkennt der Agent einen Container
(`systemd-detect-virt -c`) und maskiert nur diese fest hinterlegten Units, wenn sie im Zustand *failed* sind. Danach setzt er
ihren Fehlerstatus zurück. Das entspricht `systemctl mask …` und `systemctl reset-failed …`:

`sys-kernel-config.mount`, `sys-kernel-debug.mount`, `sys-kernel-tracing.mount`, `sys-fs-fuse-connections.mount`,
`dev-hugepages.mount`, `dev-mqueue.mount`, `systemd-journald-audit.socket`

- Von Hand auslösen: `sudo /opt/signalwarte-agent/signalwarte-agent.sh fix-units`
- Abschalten: `FIX_CONTAINER_UNITS=0` in `/etc/signalwarte-agent.conf` eintragen
- Rückgängig machen: `systemctl unmask <unit>`
- Auf echter Hardware und in VMs ändert der Agent nichts.

Andere fehlgeschlagene Units, die bewusst ignoriert werden sollen (z. B. `qwenduline-sync.service`), trägst du am Sensor unter
**Fehlgeschlagene systemd-Units ignorieren** ein (Komma-getrennt, `*` erlaubt). Eine globale Liste gibt es über die
Einstellung `agent_ignore_failed`.

## Funktionsweise

- Der Agent sendet alle *Intervall* Sekunden (Sensor-Einstellung, Standard 120 s) ein JSON an `push.php?token=…`.
- Die Antwort enthält die Liste der zu überwachenden Dienste und das Intervall – Änderungen im Sensor wirken also ohne
  Neuinstallation beim nächsten Melden.
- Meldet sich ein Agent länger als das Intervall nicht, geht der Sensor auf **Fehler** („Agent meldet sich nicht“).
- Das Token im Download ist der Schlüssel – bei Verdacht Sensor löschen und neu anlegen (neues Token), Agent neu installieren.

## Fehlersuche

| Problem | Prüfen |
|---|---|
| Sensor „Agent meldet sich nicht“ | Server erreicht die Signalwarte-URL per HTTPS? (`curl -I https://…/login.php` bzw. `iwr`) · Agent-Status/Log |
| Download-Link ist `http://` statt `https://` | Reverse-Proxy setzt `X-Forwarded-Proto: https` nicht (siehe INSTALLATION.md, Abschnitt 7) |
| Dienst „keine Daten“ | kommt mit der nächsten Meldung (der Agent erfährt die Dienstliste aus der Antwort) |
| Windows: Skript startet nicht | PowerShell als Administrator, `-ExecutionPolicy Bypass`; Log `C:\ProgramData\SignalwarteAgent\agent.log` |
| Linux: „Senden fehlgeschlagen … error: 400“ | Agent älter als 1.1.1 auf einem System mit deutschem Gebietsschema (Zahlen mit Komma → ungültiges JSON). Signalwarte repariert solche Meldungen inzwischen selbst, der Agent aktualisiert sich dann automatisch; sonst Agent neu installieren |
| Updates neu prüfen, ohne zu warten | Am Sensor **Jetzt prüfen** klicken: Der Agent zählt bei seiner nächsten Meldung (spätestens nach dem Intervall) Updates bzw. Windows-Updates und Hotfixes ohne Zwischenspeicher neu und meldet sofort. Direkt auf dem Server: Linux `sudo /opt/signalwarte-agent/signalwarte-agent.sh refresh` |
| Linux: „Updates/Sicherheitsupdates“ bleibt nach `apt upgrade` gelb | Ab 1.1.2 zählt der Agent neu, sobald sich die Paketdatenbank ändert (vorher bis zu 1 h Zwischenspeicher). Ältere Agenten aktualisieren sich selbst |
| Gerät hat mehrere IP-Adressen | Der Agent meldet bei der Anmeldung alle IPs. Signalwarte nimmt das Gerät mit einer dieser IPs (bevorzugt das mit vorhandenem System-Agent-Sensor), sonst den Hostnamen |
| Installation mit Registrierungs-Link nutzt alte Konfiguration | Ab 1.1.1 meldet sich der Agent bei jedem Installationslauf mit Registrierungs-Token neu an |
| Linux/LXC: „systemd-Dienst(e) fehlgeschlagen (sys-kernel-config.mount)“ | Im Container nicht nutzbare Kernel-Mounts maskiert der Agent selbst (siehe unten). Andere Units: Sensor-Einstellung „Fehlgeschlagene systemd-Units ignorieren“ |
| Linux: „Weder systemd noch Cron“ | `apt install cron` bzw. `dnf install cronie`, dann erneut installieren |
