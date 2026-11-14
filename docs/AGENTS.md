# Signalwarte System-Agenten (Windows & Linux)

Kleine Client-Skripte, die auf einem Server laufen und **Messwerte** (CPU, RAM, Laufwerke, Dienste, Updates …) sowie
**Systeminformationen** (Hersteller, Modell, Seriennummer, CPU, IPs, MACs …) per HTTPS an Signalwarte melden.
Funktioniert in jedem Netz, aus dem der Server die Signalwarte-Adresse per HTTPS erreicht – keine eingehenden Ports nötig.

## Einrichten

1. Gerät in Signalwarte anlegen (oder vorhandenes nehmen) und einen Sensor **„System-Agent (Windows/Linux)“** hinzufügen
   (die Vorlagen *Windows-Server*, *Domänencontroller* und *Linux-Server* bringen ihn mit).
   Optional im Sensor: **zu überwachende Dienste**, Grenzen für freien Speicher, Hauptwert.
2. Sensor öffnen → Kasten **Agent installieren** → Reiter **Windows** oder **Linux**.
3. Das Skript enthält Server-Adresse und Token bereits – herunterladen und ausführen bzw. den Einzeiler einfügen.

Per MCP: `agent_install {device_id, services}` liefert dieselben Links/Befehle.

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
| Updates ausstehend / Sicherheitsupdates | – | ✔ (apt/dnf, stündlich) | – |
| Defender aktiv / Signatur (Tage) | ✔ | – | Warnung, wenn Echtzeitschutz aus |

Alle Kanäle haben einen eigenen Verlauf (Sensor-Details → Kanal wählen) und lassen sich in Maps als Wert einer
Status-Kachel anzeigen.

**Systeminformationen** (Gerät → „Details & Systeminfo“, Sensor-Details, MCP `get_device`):
Betriebssystem, Kernel/Build, Architektur, Hersteller, Modell, Seriennummer, BIOS, Virtualisierung, CPU, Threads, RAM,
Hostname/FQDN, Domäne (Windows), IP-Adressen, MAC-Adressen, Gateway, DNS, letzter Start, Installationsdatum (Windows),
Zeitzone, Laufwerke mit Größe/frei, Agent-Version.

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
| Linux: „Weder systemd noch Cron“ | `apt install cron` bzw. `dnf install cronie`, dann erneut installieren |
