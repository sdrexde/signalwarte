# PRTG Network Monitor – Recherche (Aufbau, Konzepte, Maps)

Grundlage für **Reload Monitor**. Zusammengestellt aus dem offiziellen PRTG-Handbuch von Paessler
(Stand November 2026). Die Screenshots unten sind **nicht** in diesem Repository gespeichert, sondern
werden direkt aus dem öffentlichen Paessler-Handbuch eingebunden (Urheberrecht: Paessler GmbH).
Eigene Screenshots von Reload Monitor liegen unter [`docs/screenshots/`](screenshots/).

Quellen:
- Objekt-Hierarchie: <https://www.paessler.com/manuals/prtg/object_hierarchy>
- Sensor-Status: <https://www.paessler.com/manuals/prtg/sensor_states>
- Maps: <https://www.paessler.com/manuals/prtg/maps>
- Map Designer: <https://www.paessler.com/manuals/prtg/map_designer>
- Benachrichtigungen: <https://www.paessler.com/manuals/prtg/notifications>

---

## 1. Was ist PRTG?

PRTG (Paessler Router Traffic Grapher) ist ein agentenloses Monitoring-Tool für Netzwerke, Server,
Dienste und Anwendungen. Es besteht aus:

| Komponente | Aufgabe |
|---|---|
| **Core-Server** | Zentrale: Konfiguration, Datenhaltung, Weboberfläche, Benachrichtigungen, Berichte |
| **Probe(s)** | Führen die eigentlichen Prüfungen aus. Eine *lokale Probe* läuft auf dem Core-Server, *Remote-Probes* in anderen Netzen/Standorten melden an den Core |
| **Weboberfläche / Apps** | Gerätebaum, Alarme, Maps (Dashboards), Berichte, Protokoll |

Lizenziert wird nach Anzahl der **Sensoren** (ein Sensor = ein überwachter Messpunkt).

## 2. Objekt-Hierarchie

PRTG ordnet alles in einem Baum. Einstellungen (Intervalle, Zugangsdaten, Benachrichtigungen)
werden von oben nach unten **vererbt**.

```
Root-Gruppe
└── Probe (lokal / remote)
    └── Gruppe (beliebig verschachtelbar, z. B. Standort, Abteilung)
        └── Gerät (eine IP-Adresse / ein DNS-Name: Server, Switch, Router, Drucker …)
            └── Sensor (ein Aspekt: Ping, HTTP, CPU, Traffic, Speicherplatz …)
                └── Kanal (Datenreihe eines Sensors: z. B. Antwortzeit, Paketverlust, Min/Max)
```

![PRTG Objekt-Hierarchie](https://manuals.paessler.com/g_object_hierarchy.png)
![PRTG Gerätebaum](https://manuals.paessler.com/mo_object_hierarchy_device_tree.png)

## 3. Sensor-Status

Der Status eines Objekts ist immer der „schlimmste“ Status seiner Kinder (Priorität von oben nach unten):

| Status | Farbe | Bedeutung |
|---|---|---|
| **Down** (Fehler) | Rot | Gerät nicht erreichbar oder Fehlergrenze überschritten |
| **Down (bestätigt)** | Rosa | Ein Benutzer hat den Alarm quittiert, es gehen keine weiteren Benachrichtigungen raus |
| **Warning** (Warnung) | Gelb | Warngrenze überschritten **oder** erster Fehlschlag, PRTG versucht es sofort erneut |
| **Unusual** | Orange | Wert ungewöhnlich für Wochentag/Uhrzeit (historischer Vergleich) |
| **Up** (OK) | Grün | Letzte Prüfung erfolgreich |
| **Paused** | Blau | Manuell, zeitgesteuert oder durch eine Abhängigkeit pausiert |
| **Unknown** | Grau | Noch keine Daten bzw. Kommunikationsproblem der Probe |

Wichtige Logik:
- **Retry-Verhalten:** Erster fehlgeschlagener Request → *Warnung* + sofortiger Neuversuch; schlägt auch der fehl → *Down*.
- **Grenzwerte** (Limits) pro Kanal: obere/untere Warn- und Fehlergrenze.
- **Abhängigkeiten:** Fällt ein „Master-Sensor“ (z. B. Ping des Routers) aus, werden abhängige Objekte pausiert statt Alarmflut.

## 4. Benachrichtigungen

Auslöser (Trigger): Statuswechsel, Grenzwert über Zeit, Geschwindigkeit, Volumen, Wertänderung.
Wege u. a.: E-Mail, SMS, HTTP-Aktion/Webhook, Programm ausführen, Syslog, SNMP-Trap, Push, Teams, Slack, MQTT.
Paessler empfiehlt **mindestens zwei Wege** pro Trigger (z. B. E-Mail + Push).

## 5. Maps (das, was an PRTG besonders gefällt)

Maps sind frei gestaltbare HTML-Dashboards mit Live-Status. Sie bestehen aus **Map-Elementen**
(Geräte-Icons, Status-Icons, Graphen, Tabellen, Linien/Verbindungen, Geo-Karten, eigenes HTML) auf
einer Zeichenfläche, optional mit Hintergrundbild (z. B. Netzplan oder Grundriss). Maps können
**öffentlich** (ohne Login, z. B. auf einem NOC-Monitor) freigegeben und **rotiert** werden.

![PRTG Map Beispiel](https://manuals.paessler.com/map_example.png)

### Map Designer (PRTG)

Dreiteiliger Aufbau:

| Bereich | Inhalt |
|---|---|
| **Links: Gerätebaum** | Objekt (Probe/Gruppe/Gerät/Sensor) auswählen, per Drag & Drop auf die Fläche ziehen; Undo/Redo, Snap-to-Grid |
| **Mitte: Design-Fläche** | Feste Pixelgröße, Elemente positionieren, Pfeiltasten verschieben (Shift = 10 px) |
| **Rechts: Eigenschaften** | Darstellungsart wählen (Icons A/B/C, Status-Icons, Graphen, Tabellen, Donuts, Geo-Map, Formen, Top-10, HTML) + Position/Größe/Ebene/Link |

![PRTG Map Designer](https://manuals.paessler.com/map_designer.png)

**Verbindungslinien:** Am grauen Griff links an einem Element ziehen und auf ein anderes Element
fallen lassen. Die Linie färbt sich nach dem Status der verbundenen Objekte – bei unterschiedlichem
Status **zweifarbig** (z. B. halb rot, halb gelb).

![PRTG farbige Verbindungslinien](https://manuals.paessler.com/map_colored_lines.png)

### Schwächen des PRTG-Designers (Anlass für Reload Monitor)

- Bedienung eher „Formular-basiert“, nicht wie ein modernes Diagramm-Werkzeug.
- Keine freien Formen/Zonen mit Text wie in draw.io, kein Zoom/Pan, keine Wegpunkte bei Linien,
  keine Ausrichtungshilfen, kein Mehrfach-Auswählen/Kopieren.
- Linien nur gerade, keine orthogonale Führung.

---

## 6. Umsetzung in Reload Monitor (Mapping PRTG → Reload Monitor)

| PRTG | Reload Monitor | Status |
|---|---|---|
| Root → Probe → Gruppen → Geräte → Sensoren → Kanäle | identisch (lokale Probe, beliebig tiefe Gruppen, Kanäle als Zusatzwerte) | ✅ |
| Status-Farben & Priorität (Down/Warning/Up/Paused/Unknown/Bestätigt) | identisch, inkl. „schlimmster Status vererbt sich nach oben“ | ✅ |
| Retry: 1. Fehler = Warnung, 2. Fehler = Down | identisch (Neuversuch nach 10–20 s) | ✅ |
| Warn-/Fehlergrenzen (oben/unten) | pro Sensor auf den Hauptwert | ✅ |
| Abhängigkeiten (Master-Sensor) | pro Gerät wählbar | ✅ |
| Alarm bestätigen (Acknowledge) | mit Kommentar | ✅ |
| Pausieren (Gruppe/Gerät/Sensor, vererbt) | ✅ | ✅ |
| Auto-Discovery | Ping + 20 gängige Ports, schlägt Sensoren vor | ✅ (vereinfacht) |
| Sensoren: Ping, HTTP(S), Port, DNS, SSL, HTTP-Push, JSON-API, CPU/RAM/Disk | ✅ | ✅ |
| SNMP, WMI, NetFlow, Traffic | – | 🔜 Roadmap |
| Remote-Probes | – | 🔜 Roadmap (Push-Sensor als Zwischenlösung) |
| Benachrichtigungen: E-Mail, Webhook, Telegram, ntfy (Push) | ✅ | ✅ |
| Graphen/Historie (1 h / 24 h / 7 T / 30 T) | Chart.js mit Grenzwert-Linien und Fehler-Einfärbung | ✅ |
| Maps: Gerätebaum links, Fläche mitte, Eigenschaften rechts | ✅ – **Bedienung wie draw.io** | ✅ |
| Maps: Linien nach Status gefärbt, zweifarbig | ✅ + an Sensor bindbar (z. B. Uplink) | ✅ |
| Maps: öffentlich teilen (NOC-Wand) | Token-Link, nur Objekte der Map sichtbar | ✅ |
| Maps: Hintergrundbild | Element „Bild (URL)“ | ✅ |
| Maps: Rotation mehrerer Maps | – | 🔜 Roadmap |
| Berichte (PDF) | – | 🔜 Roadmap |

### draw.io-Bedienung im Map-Editor

- Unendliche Fläche mit Raster, **Strg+Mausrad** zoomen, Mausrad/Leertaste+Ziehen/mittlere Maustaste verschieben, „Alles anzeigen“
- Formen-Palette (Rechteck, abgerundet, Ellipse, Raute, Sechseck, Zylinder, Wolke, Zone, Text, Bild, Link) + 20 Netzwerk-Icons
- **Verbinden über die blauen ×-Punkte** am Element (wie draw.io); ins Leere ziehen erzeugt ein neues verbundenes Element
- Linien gerade / **orthogonal** / kurvig, **Wegpunkte** (orange Rauten ziehen, weiße Punkte erzeugen neue), Pfeile, gestrichelt, animierter Fluss, Beschriftung
- Mehrfachauswahl (Rahmen ziehen, Shift-Klick), **Hilfslinien** beim Verschieben, Einrasten am Raster
- Größe ändern über 8 Anfasser (Shift = Seitenverhältnis), Doppelklick = Text direkt bearbeiten
- Ausrichten & Verteilen, Nach vorne/hinten, Kopieren/Einfügen/Duplizieren, **Undo/Redo**, Rechtsklick-Menü
- Zonen nehmen beim Verschieben ihren Inhalt mit
- Export **PNG / SVG / JSON**, Import JSON
- Bearbeiten/Ansicht-Umschalter; in der Ansicht Klick auf Objekt → Detailansicht, Tooltip mit Status
