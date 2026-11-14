# Signalwarte als MCP-Server

Signalwarte bringt einen eingebauten MCP-Server (Model Context Protocol) mit. KI-Agenten wie **Tom (Hermes)**,
**Maik (OpenClaw)**, Claude und Claude Code können darüber den Zustand des Netzes abfragen und Signalwarte **verwalten
und pflegen**: Geräte und Sensoren anlegen, ändern, löschen, Netze scannen, Alarme bestätigen, System-Agenten einrichten.

| Transport | Adresse | Authentifizierung |
|---|---|---|
| Streamable HTTP (JSON-Antworten) | `https://<signalwarte>/mcp.php` | `Authorization: Bearer sw_…` (alternativ `X-API-Key: sw_…`) |

Unterstützte Protokollversionen: 2024-11-05, 2025-03-26, 2025-06-18, 2025-11-25 (initialize-Handshake) und 2026-07-28
(zustandslos, `server/discover`). Getestet mit dem offiziellen MCP-Python-SDK (2.x). Der Server ist zustandslos – keine
Sitzungen, kein SSE nötig; Batches werden unterstützt.

---

## 1. API-Schlüssel anlegen

**Einstellungen › API & MCP › Neuer Schlüssel.** Jeder Agent bekommt am besten einen eigenen Schlüssel – im Protokoll
(Typ `mcp`) steht dann, wer was geändert hat. Der Schlüssel (`sw_…`) wird **nur einmal angezeigt**; gespeichert wird nur
ein Hash. Widerrufen jederzeit in derselben Ansicht.

| Rolle | Darf |
|---|---|
| `read` | alles lesen: Status, Alarme, Geräte, Sensoren, Verläufe, Protokoll, Maps |
| `operate` | zusätzlich: Sensor sofort prüfen, Alarm bestätigen, pausieren/fortsetzen |
| `admin` | zusätzlich: Gruppen/Geräte/Sensoren anlegen, ändern, löschen, Netz scannen, Host erkennen, DHCP-Ausschlüsse setzen, System-Agent einrichten |

Empfehlung: Claude (aus dem Internet) nur `read`, Hausagenten (Maik/Tom) `operate` oder `admin`.

## 2. Tom (Hermes)

`~/.hermes/.env`:
```
SIGNALWARTE_API_KEY=sw_...
```
`~/.hermes/config.yaml`:
```yaml
mcp_servers:
  signalwarte:
    url: "https://nexus.sdrex.de/monitor/mcp.php"
    headers:
      Authorization: "Bearer ${SIGNALWARTE_API_KEY}"
    timeout: 180
    # Optional nur lesend:
    # tools:
    #   include: [status_overview, list_alarms, list_devices, get_device, get_sensor, sensor_history, list_events]
```
Danach in Hermes `/reload-mcp`. Die Werkzeuge erscheinen als `mcp_signalwarte_status_overview` usw.

## 3. Maik (OpenClaw)

```bash
openclaw mcp set signalwarte '{"url":"https://nexus.sdrex.de/monitor/mcp.php","transport":"streamable-http","headers":{"Authorization":"Bearer ${SIGNALWARTE_API_KEY}"}}'
openclaw mcp probe signalwarte
```
Oder in `~/.openclaw/openclaw.json`:
```json5
{
  mcp: {
    servers: {
      signalwarte: {
        url: "https://nexus.sdrex.de/monitor/mcp.php",
        transport: "streamable-http",
        headers: { Authorization: "Bearer ${SIGNALWARTE_API_KEY}" },
        requestTimeoutMs: 180000
      }
    }
  }
}
```
`SIGNALWARTE_API_KEY` muss in der Umgebung des Gateways gesetzt sein; danach `openclaw gateway restart` bzw. `openclaw mcp reload`.

## 4. Claude Code / Claude

```bash
claude mcp add --transport http signalwarte https://nexus.sdrex.de/monitor/mcp.php --header "Authorization: Bearer sw_..."
```
Claude (claude.ai/Desktop) verbindet sich aus der Anthropic-Cloud – Signalwarte muss dafür per HTTPS aus dem Internet
erreichbar sein. **Einstellungen → Connectors → Benutzerdefinierter Connector**, URL wie oben, Header `Authorization`.
Dafür einen `read`-Schlüssel verwenden.

> Nach dem Umzug auf einen eigenen LXC (z. B. `https://signalwarte.sdrex.de/`) lautet die Adresse `https://signalwarte.sdrex.de/mcp.php`.

---

## 5. Werkzeuge

| Werkzeug | Rolle | Zweck |
|---|---|---|
| `status_overview` | read | Zähler je Status, Geräte, Poller-Zustand, wichtigste Alarme |
| `list_alarms` | read | offene Alarme (Fehler, Warnung, bestätigt, unbekannt), schlimmste zuerst |
| `list_groups` | read | Gruppen mit Pfad, Status, Zählern |
| `list_devices` | read | Geräte suchen (Text/IP, Status, Gruppe), nach IP sortiert |
| `get_device` | read | Gerät mit allen Sensoren, Notizen, **Systeminformationen** (vom Agenten) – per id, host oder name |
| `get_sensor` | read | Sensor-Details: Konfiguration, Grenzwerte, Kanäle, Verfügbarkeit 24 h, Ereignisse |
| `sensor_history` | read | Verlauf (1h/6h/24h/7d/30d), Hauptwert oder Kanal, max. 120 Punkte + Zusammenfassung |
| `list_sensor_types` | read | Sensortypen mit Feldern und Standard-Grenzwerten |
| `list_templates` | read | Gerätevorlagen (windows, dc, linux, pfsense, fritzbox, fritzrep, switch, proxmox, nas, printer, web) |
| `list_events` | read | Protokoll (status, notify, user, mcp), Textsuche, Zeitraum |
| `list_maps` | read | Maps mit Elementanzahl und Link |
| `get_scan_exclusions` | read | DHCP-/Ausschlussbereiche |
| `check_sensor` | operate | Sensor sofort prüfen |
| `acknowledge_alarm` | operate | Fehler bestätigen (mit Kommentar) |
| `set_paused` | operate | Gruppe/Gerät/Sensor pausieren oder fortsetzen |
| `create_group` | admin | Gruppe anlegen (idempotent) |
| `create_device` | admin | Gerät anlegen – mit `template`, eigener `sensors`-Liste oder `discover=true`; prüft auf Dubletten |
| `update_device` | admin | Name, Host, Gruppe, Icon, Notizen, Weboberfläche, SNMP-Community ändern |
| `delete_device` | admin | Gerät löschen (`confirm=true` nötig) |
| `create_sensor` / `update_sensor` | admin | Sensor anlegen/ändern (Konfiguration, Intervall, Grenzwerte, Fehlprüfungen) |
| `delete_sensor` | admin | Sensor löschen (`confirm=true` nötig) |
| `scan_network` | admin | Netze scannen (legt nichts an), mit Gerätetyp und Vorlagen-Vorschlag; DHCP-Ausschlüsse werden übersprungen |
| `discover_host` | admin | einzelnen Host erkennen, Sensoren vorschlagen |
| `set_scan_exclusions` | admin | DHCP-/Ausschlussbereiche setzen |
| `agent_install` | admin | System-Agent-Sensor sicherstellen, Download-Links und Installationsbefehle für Windows und Linux liefern |

Lesende Werkzeuge sind als `readOnlyHint`, Löschen als `destructiveHint` markiert – Clients können so vor gefährlichen
Aktionen nachfragen. Fehler kommen als `isError: true` mit verständlicher Meldung zurück.

**Prompts:** `lagebericht`, `geraet_pruefen` (Argument `geraet`), `netz_aufnehmen` (Argument `netz`).
**Ressourcen:** `signalwarte://status`, `signalwarte://sensor-types`, `signalwarte://templates`.

## 6. Beispiele (was ein Agent tun kann)

- „Wie ist die Lage?“ → `status_overview`, `list_alarms`
- „Warum ist der Drucker rot?“ → `get_device {name:"Drucker"}` → `get_sensor` → `sensor_history`
- „Nimm 192.168.11.0/24 auf, aber ohne DHCP“ → `get_scan_exclusions` → `scan_network` → Liste zeigen → `create_device` je Gerät mit `template`
- „Richte auf dem neuen Linux-Server den Agenten ein und überwache nginx“ → `agent_install {device_id, services:"nginx"}` → Befehl auf dem Server ausführen
- „Wartung am NAS bis 18 Uhr“ → `set_paused {object:"device", id, paused:true}` bzw. `acknowledge_alarm`

## 7. Test mit curl

```bash
KEY=sw_...
URL=https://nexus.sdrex.de/monitor/mcp.php
curl -s -H "Authorization: Bearer $KEY" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' $URL
curl -s -H "Authorization: Bearer $KEY" -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"status_overview","arguments":{}}}' $URL
```

## 8. Sicherheit

- Schlüssel wie Passwörter behandeln (Vaultwarden), nie in Chats/Tickets/Repos.
- Pro Agent eigener Schlüssel und minimal nötige Rolle; nicht mehr benötigte Schlüssel widerrufen.
- `agent_install` und `get_sensor` (admin) liefern Push-/Agent-Links mit geheimem Token – nur an den betroffenen Server weitergeben.
- Wird `/mcp.php` ins Internet freigegeben (für Claude), am Proxy nur diesen Pfad durchlassen.
- Alle ändernden MCP-Aufrufe stehen im Protokoll (**Protokoll › Typ mcp**).
