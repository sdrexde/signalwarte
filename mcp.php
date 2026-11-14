<?php
// Signalwarte MCP-Server (Model Context Protocol) – Streamable HTTP unter /mcp.php
// Authentifizierung: Authorization: Bearer sw_…  (API-Schlüssel unter Einstellungen › API & MCP)
// Rollen: read (lesen) · operate (+ prüfen, bestätigen, pausieren) · admin (+ anlegen, ändern, löschen, scannen)
declare(strict_types=1);

require_once __DIR__ . '/lib/ops.php';
require_once __DIR__ . '/lib/apitokens.php';
require_once __DIR__ . '/lib/agents.php';

const MCP_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'];
const MCP_SERVER = ['name' => 'signalwarte', 'title' => 'Signalwarte', 'version' => APP_VERSION];
const ROLE_RANK = ['read' => 1, 'operate' => 2, 'admin' => 3];

const MCP_INSTRUCTIONS = <<<TXT
Signalwarte ist ein Netzwerk-Monitoring (ähnlich PRTG). Aufbau: Gruppen → Geräte (Host/IP) → Sensoren (Ping, HTTP, Port,
DNS, SSL, SNMP, FRITZ!Box, System-Agent …). Status: down (Fehler) > warning > acked (bestätigt) > unknown > up > paused.
Ein Sensor geht erst nach mehreren Fehlprüfungen in Folge in den Alarm (Standard 3).
Vorgehen: Für einen Überblick status_overview bzw. list_alarms. Geräte mit list_devices (Filter search/status/group) finden,
Details mit get_device / get_sensor, Verläufe mit sensor_history. Neue Geräte möglichst mit Vorlage (list_templates) oder
discover=true anlegen; vorher mit list_devices prüfen, ob Host/IP schon existiert. Löschen erfordert confirm=true und sollte
nur nach ausdrücklicher Freigabe durch den Menschen erfolgen. Zugangsdaten (Passwörter, Tokens) niemals in Antworten wiederholen.
TXT;

/* ===================================================================== Transport */
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    header('Allow: POST, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}
if ($method === 'DELETE') {          // Sitzungsende – zustandslos, nichts zu tun
    http_response_code(200);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, DELETE, OPTIONS');
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Nur POST (Streamable HTTP, JSON-Antworten)']);
    exit;
}

$tok = api_token_from_request();
$auth = $tok ? api_token_verify($tok) : null;
if (!$auth) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="signalwarte"');
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'API-Schlüssel fehlt oder ungültig (Authorization: Bearer sw_…)']]);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 2_000_000) ?: '';
$msg = json_decode($raw, true);
if (!is_array($msg)) {
    mcp_send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Ungültiges JSON']]);
}
$batch = array_is_list($msg) && $msg !== [];
$out = [];
foreach ($batch ? $msg : [$msg] as $m) {
    $r = mcp_handle($m, $auth);
    if ($r !== null) {
        $out[] = $r;
    }
}
if (!$out) {
    http_response_code(202);
    exit;
}
mcp_send($batch ? $out : $out[0]);

function mcp_send(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===================================================================== Protokoll */
final class McpError extends Exception
{
    public function __construct(public int $rpcCode, string $message, public mixed $data = null)
    {
        parent::__construct($message);
    }
}
final class ToolError extends Exception
{
}

function mcp_handle(mixed $m, array $auth): ?array
{
    if (!is_array($m) || ($m['jsonrpc'] ?? '') !== '2.0' || !isset($m['method'])) {
        if (is_array($m) && (isset($m['result']) || isset($m['error']))) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $m['id'] ?? null, 'error' => ['code' => -32600, 'message' => 'Ungültige JSON-RPC-Anfrage']];
    }
    $isNote = !array_key_exists('id', $m);
    try {
        $res = mcp_dispatch((string) $m['method'], (array) ($m['params'] ?? []), $auth);
    } catch (McpError $e) {
        return $isNote ? null : ['jsonrpc' => '2.0', 'id' => $m['id'], 'error' => array_filter(['code' => $e->rpcCode, 'message' => $e->getMessage(), 'data' => $e->data], fn($v) => $v !== null)];
    } catch (Throwable $e) {
        return $isNote ? null : ['jsonrpc' => '2.0', 'id' => $m['id'], 'error' => ['code' => -32603, 'message' => 'Interner Fehler: ' . $e->getMessage()]];
    }
    return $isNote ? null : ['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $res === [] ? new stdClass() : $res];
}

function mcp_dispatch(string $method, array $params, array $auth): array
{
    $pv = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
    if ($pv && !in_array($pv, MCP_VERSIONS, true)) {
        throw new McpError(-32022, 'Nicht unterstützte Protokollversion', ['supported' => MCP_VERSIONS, 'requested' => $pv]);
    }
    $stateless = $pv === '2026-07-28';
    $done = function (array $r) use ($stateless): array {
        if ($stateless) {
            $r['resultType'] ??= 'complete';
            $r['_meta']['io.modelcontextprotocol/serverInfo'] = MCP_SERVER;
        }
        return $r;
    };
    $caps = ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false, 'subscribe' => false], 'prompts' => ['listChanged' => false]];

    switch ($method) {
        case 'initialize':
            $req = (string) ($params['protocolVersion'] ?? '2025-03-26');
            $ver = in_array($req, MCP_VERSIONS, true) && $req !== '2026-07-28' ? $req : '2025-11-25';
            return ['protocolVersion' => $ver, 'capabilities' => $caps, 'serverInfo' => MCP_SERVER, 'instructions' => MCP_INSTRUCTIONS];
        case 'server/discover':
            return $done(['supportedVersions' => MCP_VERSIONS, 'capabilities' => $caps, 'serverInfo' => MCP_SERVER, 'instructions' => MCP_INSTRUCTIONS]);
        case 'ping':
        case 'logging/setLevel':
            return [];
        case 'tools/list':
            $tools = [];
            foreach (mcp_tools() as $t) {
                if (ROLE_RANK[$auth['role']] >= ROLE_RANK[$t['role']]) {
                    $tools[] = ['name' => $t['name'], 'title' => $t['title'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema'], 'annotations' => $t['annotations']];
                }
            }
            return $done(['tools' => $tools] + ($stateless ? ['ttlMs' => 300000, 'cacheScope' => 'private'] : []));
        case 'tools/call':
            return $done(mcp_call_tool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $auth));
        case 'resources/list':
            return $done(['resources' => [
                ['uri' => 'signalwarte://sensor-types', 'name' => 'Sensortypen', 'mimeType' => 'application/json', 'description' => 'Alle Sensortypen mit Konfigurationsfeldern'],
                ['uri' => 'signalwarte://templates', 'name' => 'Gerätevorlagen', 'mimeType' => 'application/json', 'description' => 'Vorlagen mit Sensor-Sets'],
                ['uri' => 'signalwarte://status', 'name' => 'Gesamtstatus', 'mimeType' => 'application/json', 'description' => 'Zähler und offene Alarme'],
            ]]);
        case 'resources/templates/list':
            return $done(['resourceTemplates' => []]);
        case 'resources/read':
            $uri = (string) ($params['uri'] ?? '');
            $data = match ($uri) {
                'signalwarte://sensor-types' => t_sensor_types([]),
                'signalwarte://templates' => t_templates([]),
                'signalwarte://status' => t_status([]),
                default => throw new McpError(-32602, "Ressource nicht gefunden: {$uri}"),
            };
            return $done(['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]]);
        case 'prompts/list':
            return $done(['prompts' => [
                ['name' => 'lagebericht', 'title' => 'Lagebericht', 'description' => 'Kurzer Statusbericht über das gesamte Netz'],
                ['name' => 'geraet_pruefen', 'title' => 'Gerät prüfen', 'description' => 'Ein Gerät mit allen Sensoren untersuchen',
                    'arguments' => [['name' => 'geraet', 'description' => 'Name, Host oder IP', 'required' => true]]],
                ['name' => 'netz_aufnehmen', 'title' => 'Netz aufnehmen', 'description' => 'Ein Netz scannen und sinnvolle Geräte anlegen',
                    'arguments' => [['name' => 'netz', 'description' => 'z. B. 192.168.1.0/24', 'required' => true]]],
            ]]);
        case 'prompts/get':
            return $done(mcp_prompt((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])));
        case 'completion/complete':
            return $done(['completion' => ['values' => [], 'hasMore' => false]]);
    }
    if (str_starts_with($method, 'notifications/')) {
        return [];
    }
    throw new McpError(-32601, "Methode nicht gefunden: {$method}");
}

function mcp_prompt(string $name, array $a): array
{
    $text = match ($name) {
        'lagebericht' => 'Erstelle einen kurzen Lagebericht aus Signalwarte: 1) status_overview, 2) list_alarms – je Alarm Gerät, Sensor, '
            . 'Meldung und Dauer, 3) bei Fehlern mit get_sensor/sensor_history prüfen, seit wann und ob es Muster gibt, 4) list_events '
            . '(type=status, letzte 24 h) für Auffälligkeiten. Ergebnis: Ampel, wichtigste Probleme mit Zahlen, empfohlene nächste Schritte.',
        'geraet_pruefen' => ($a['geraet'] ?? '') === '' ? throw new McpError(-32602, 'Argument geraet fehlt')
            : "Untersuche das Gerät „{$a['geraet']}“ in Signalwarte: get_device (Host/Name), dann für jeden auffälligen Sensor get_sensor und "
            . 'sensor_history (24h, ggf. 7d und einzelne Kanäle). Prüfe Systeminformationen (sysinfo), falls ein System-Agent läuft. '
            . 'Nenne Ursachenhypothesen und konkrete Maßnahmen.',
        'netz_aufnehmen' => ($a['netz'] ?? '') === '' ? throw new McpError(-32602, 'Argument netz fehlt')
            : "Nimm das Netz {$a['netz']} in Signalwarte auf: scan_network, dann je erkanntem Gerät (nicht exists) mit passender Vorlage "
            . 'create_device anlegen (sinnvolle Namen aus Hostname/Typ, passende Gruppe; ggf. vorher create_group). Unbekannte/dynamische '
            . 'Geräte (DHCP) weglassen. Vorher die Liste dem Menschen zeigen und Freigabe abwarten.',
        default => throw new McpError(-32602, "Unbekannter Prompt: {$name}"),
    };
    return ['description' => $name, 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];
}

/* ===================================================================== Werkzeuge */
function p(string $type, string $desc, array $extra = []): array
{
    return ['type' => $type, 'description' => $desc] + $extra;
}

function mcp_tool(string $name, string $title, string $desc, string $role, array $props, array $required, callable $fn,
    bool $readOnly = true, bool $destructive = false, bool $idempotent = true): array
{
    return [
        'name' => $name, 'title' => $title, 'description' => $desc, 'role' => $role, 'fn' => $fn,
        'inputSchema' => ['type' => 'object', 'properties' => (object) $props, 'required' => $required, 'additionalProperties' => false],
        'annotations' => ['title' => $title, 'readOnlyHint' => $readOnly, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => false],
    ];
}

function mcp_tools(): array
{
    static $tools = null;
    if ($tools !== null) {
        return $tools;
    }
    $status = p('string', 'Status-Filter', ['enum' => ['down', 'warning', 'acked', 'unknown', 'up', 'paused']]);
    $limits = [
        'warn_max' => p('number', 'Warnung, wenn Wert darüber'), 'err_max' => p('number', 'Fehler, wenn Wert darüber'),
        'warn_min' => p('number', 'Warnung, wenn Wert darunter'), 'err_min' => p('number', 'Fehler, wenn Wert darunter'),
        'retries' => p('integer', 'Fehlprüfungen in Folge bis Alarm (1–10, leer = Standard)'),
    ];
    $tools = [
        // ---------------------------------------------------------------- lesen
        mcp_tool('status_overview', 'Gesamtstatus', 'Zähler je Status, Anzahl Geräte, Poller-Zustand und die wichtigsten offenen Alarme.', 'read', [], [], 't_status'),
        mcp_tool('list_alarms', 'Offene Alarme', 'Alle Sensoren mit Fehler, Warnung, bestätigtem Fehler oder unbekanntem Status (schlimmste zuerst).', 'read',
            ['include_unknown' => p('boolean', 'Auch Status „unbekannt“ (Standard: true)')], [], 't_alarms'),
        mcp_tool('list_groups', 'Gruppen', 'Alle Gruppen mit Pfad, Status und Zählern.', 'read', [], [], 't_groups'),
        mcp_tool('list_devices', 'Geräte suchen', 'Geräte auflisten, nach Text (Name/Host/IP), Status oder Gruppe gefiltert, nach IP sortiert.', 'read',
            ['search' => p('string', 'Teil von Name, Host oder IP'), 'status' => $status, 'group' => p('string', 'Gruppen-ID oder -Name'), 'limit' => p('integer', 'max. Anzahl (Standard 200)')], [], 't_devices'),
        mcp_tool('get_device', 'Gerät anzeigen', 'Ein Gerät mit allen Sensoren, Status, Notizen und – falls ein System-Agent läuft – Systeminformationen. Suche über id, host oder name.', 'read',
            ['id' => p('integer', 'Geräte-ID'), 'host' => p('string', 'Host/IP'), 'name' => p('string', 'Gerätename (exakt oder eindeutig enthalten)')], [], 't_get_device'),
        mcp_tool('get_sensor', 'Sensor anzeigen', 'Details eines Sensors: Konfiguration, Grenzwerte, letzte Werte und Kanäle, Verfügbarkeit 24 h, letzte Ereignisse.', 'read',
            ['id' => p('integer', 'Sensor-ID')], ['id'], 't_get_sensor'),
        mcp_tool('sensor_history', 'Messverlauf', 'Verlauf eines Sensors (Hauptwert oder Kanal) als bis zu 120 Punkte mit Ø/Min/Max und Zusammenfassung.', 'read',
            ['id' => p('integer', 'Sensor-ID'), 'range' => p('string', 'Zeitraum', ['enum' => ['1h', '6h', '24h', '7d', '30d']]), 'channel' => p('string', 'Kanalname, z. B. „RAM %“ (leer = Hauptwert)')], ['id'], 't_history'),
        mcp_tool('list_sensor_types', 'Sensortypen', 'Verfügbare Sensortypen mit Beschreibung, Einheit, Standard-Intervall und Konfigurationsfeldern.', 'read', [], [], 't_sensor_types'),
        mcp_tool('list_templates', 'Gerätevorlagen', 'Vorlagen (z. B. windows, linux, pfsense, fritzbox, proxmox, nas, printer, web) mit ihren Sensoren.', 'read', [], [], 't_templates'),
        mcp_tool('list_events', 'Protokoll', 'Ereignisprotokoll (Statuswechsel, Benachrichtigungen, Benutzer-/MCP-Aktionen), neueste zuerst.', 'read',
            ['type' => p('string', 'Typ', ['enum' => ['status', 'notify', 'user', 'mcp']]), 'search' => p('string', 'Textsuche'), 'hours' => p('integer', 'nur die letzten N Stunden'), 'limit' => p('integer', 'max. Anzahl (Standard 50)')], [], 't_events'),
        mcp_tool('list_maps', 'Maps', 'Vorhandene Netzwerk-Maps mit Name, Anzahl Elemente und Freigabe-Status.', 'read', [], [], 't_maps'),
        mcp_tool('list_agents', 'System-Agenten', 'Alle Server mit System-Agent: Plattform, Agent-Version (veraltet?), CPU, RAM, freier Platz je Laufwerk, ausstehende (Sicherheits-)Updates, Neustart nötig, letzte Meldung.', 'read',
            ['only_problems' => p('boolean', 'nur Server mit Updates, Neustart, wenig Platz, hoher Last oder Fehlerstatus')], [], 't_agents'),
        mcp_tool('get_scan_exclusions', 'DHCP-/Ausschlussbereiche', 'Bereiche, die beim Netzwerkscan übersprungen werden.', 'read', [], [], 't_get_excl'),
        // ---------------------------------------------------------------- bedienen
        mcp_tool('check_sensor', 'Sensor jetzt prüfen', 'Führt die Prüfung eines Sensors sofort aus und liefert das Ergebnis.', 'operate',
            ['id' => p('integer', 'Sensor-ID')], ['id'], 't_check', false, false, false),
        mcp_tool('acknowledge_alarm', 'Alarm bestätigen', 'Bestätigt einen Fehler-Alarm mit Kommentar (keine weiteren Benachrichtigungen bis zum nächsten Statuswechsel).', 'operate',
            ['sensor_id' => p('integer', 'Sensor-ID'), 'message' => p('string', 'Kommentar, z. B. „Wartung bis 18 Uhr“')], ['sensor_id', 'message'], 't_ack', false),
        mcp_tool('set_paused', 'Pausieren / Fortsetzen', 'Pausiert oder setzt eine Gruppe, ein Gerät oder einen Sensor fort.', 'operate',
            ['object' => p('string', 'Objekttyp', ['enum' => ['group', 'device', 'sensor']]), 'id' => p('integer', 'ID'), 'paused' => p('boolean', 'true = pausieren, false = fortsetzen')], ['object', 'id', 'paused'], 't_pause', false),
        // ---------------------------------------------------------------- verwalten
        mcp_tool('create_group', 'Gruppe anlegen', 'Legt eine Gruppe an (oder liefert die vorhandene mit gleichem Namen unter demselben Eltern).', 'admin',
            ['name' => p('string', 'Name'), 'parent' => p('string', 'Eltern-Gruppe (ID oder Name), Standard: lokale Probe')], ['name'], 't_create_group', false),
        mcp_tool('create_device', 'Gerät anlegen', 'Legt ein Gerät an. Sensoren über template (siehe list_templates), eine eigene sensors-Liste oder discover=true (Ports/Dienste erkennen). Ohne Angabe nur Ping. Prüft vorher auf Dubletten (gleicher Host).', 'admin',
            ['name' => p('string', 'Gerätename'), 'host' => p('string', 'Host oder IP'), 'group' => p('string', 'Gruppe (ID oder Name, wird bei Bedarf angelegt)'),
                'template' => p('string', 'Vorlagen-Schlüssel, z. B. windows, linux, pfsense, fritzbox'), 'template_param' => p('string', 'Vorlagen-Parameter, z. B. Web-Port bei pfsense'),
                'sensors' => p('array', 'Eigene Sensoren', ['items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'name' => ['type' => 'string'], 'config' => ['type' => 'object']], 'required' => ['type']]]),
                'discover' => p('boolean', 'Dienste automatisch erkennen und passende Sensoren anlegen'), 'icon' => p('string', 'Icon, z. B. server, windows, linux, firewall, switch, printer'),
                'notes' => p('string', 'Notizen'), 'web_url' => p('string', 'Adresse der Weboberfläche'), 'snmp_community' => p('string', 'SNMP-Community'), 'allow_duplicate' => p('boolean', 'Trotz vorhandenem Host anlegen')],
            ['name', 'host'], 't_create_device', false, false, false),
        mcp_tool('update_device', 'Gerät ändern', 'Ändert Name, Host, Gruppe, Icon, Notizen, Weboberfläche oder SNMP-Community eines Geräts.', 'admin',
            ['id' => p('integer', 'Geräte-ID'), 'name' => p('string', 'Name'), 'host' => p('string', 'Host/IP'), 'group' => p('string', 'Gruppe (ID oder Name)'),
                'icon' => p('string', 'Icon'), 'notes' => p('string', 'Notizen'), 'web_url' => p('string', 'Weboberfläche'), 'snmp_community' => p('string', 'SNMP-Community')], ['id'], 't_update_device', false),
        mcp_tool('delete_device', 'Gerät löschen', 'Löscht ein Gerät inkl. aller Sensoren und Messwerte. Nur nach ausdrücklicher Freigabe durch den Menschen.', 'admin',
            ['id' => p('integer', 'Geräte-ID'), 'confirm' => p('boolean', 'Muss true sein')], ['id', 'confirm'], 't_delete_device', false, true),
        mcp_tool('create_sensor', 'Sensor anlegen', 'Legt einen Sensor an einem Gerät an (Typen und Felder: list_sensor_types).', 'admin',
            ['device_id' => p('integer', 'Geräte-ID'), 'type' => p('string', 'Sensortyp'), 'name' => p('string', 'Name (optional)'), 'config' => p('object', 'Konfiguration laut Sensortyp'),
                'interval_s' => p('integer', 'Prüfintervall in Sekunden')] + $limits, ['device_id', 'type'], 't_create_sensor', false, false, false),
        mcp_tool('update_sensor', 'Sensor ändern', 'Ändert Name, Konfiguration, Intervall, Grenzwerte oder Fehlprüfungen eines Sensors (nur übergebene Felder).', 'admin',
            ['id' => p('integer', 'Sensor-ID'), 'name' => p('string', 'Name'), 'config' => p('object', 'Konfiguration (wird mit der bestehenden zusammengeführt)'),
                'interval_s' => p('integer', 'Prüfintervall in Sekunden')] + $limits, ['id'], 't_update_sensor', false),
        mcp_tool('delete_sensor', 'Sensor löschen', 'Löscht einen Sensor inkl. Messwerten. Nur nach ausdrücklicher Freigabe.', 'admin',
            ['id' => p('integer', 'Sensor-ID'), 'confirm' => p('boolean', 'Muss true sein')], ['id', 'confirm'], 't_delete_sensor', false, true),
        mcp_tool('scan_network', 'Netzwerk scannen', 'Scannt Netze (z. B. „192.168.1.0/24, 10.0.0.1-50“): Ping, Ports, Hostname, MAC/Hersteller, Gerätetyp, Vorschlag für Vorlage. Legt nichts an. DHCP-Ausschlüsse werden übersprungen.', 'admin',
            ['targets' => p('string', 'Netze/Bereiche/IPs'), 'deep' => p('boolean', 'Auch Geräte ohne Ping-Antwort (langsamer)')], ['targets'], 't_scan'),
        mcp_tool('discover_host', 'Host erkennen', 'Erkennt Gerätetyp und Dienste eines einzelnen Hosts und schlägt Sensoren vor.', 'admin',
            ['host' => p('string', 'Host oder IP')], ['host'], 't_discover'),
        mcp_tool('set_scan_exclusions', 'DHCP-/Ausschlussbereiche setzen', 'Ersetzt die Ausschlussbereiche (eine Zeile pro Bereich, z. B. 192.168.11.50-192.168.11.84).', 'admin',
            ['ranges' => p('string', 'Bereiche, zeilen- oder kommagetrennt')], ['ranges'], 't_set_excl', false),
        mcp_tool('agent_enroll_command', 'Agent-Einzeiler (Registrierung)', 'Liefert die Installationsbefehle mit Registrierungs-Token für beliebige neue Server (Windows/Linux). Der Agent legt sein Gerät selbst an.', 'admin',
            [], [], 't_enroll_cmd'),
        mcp_tool('agent_install', 'System-Agent einrichten', 'Sorgt dafür, dass ein Gerät einen System-Agent-Sensor hat, und liefert Download-Links und Installationsbefehle für Windows und Linux.', 'admin',
            ['device_id' => p('integer', 'Geräte-ID'), 'services' => p('string', 'Zu überwachende Dienste, Komma-getrennt (optional)')], ['device_id'], 't_agent', false, false, false),
    ];
    return $tools;
}

function mcp_call_tool(string $name, array $args, array $auth): array
{
    $t = null;
    foreach (mcp_tools() as $x) {
        if ($x['name'] === $name) {
            $t = $x;
        }
    }
    if (!$t) {
        throw new McpError(-32602, "Unbekanntes Werkzeug: {$name}");
    }
    if (ROLE_RANK[$auth['role']] < ROLE_RANK[$t['role']]) {
        return tool_err("Keine Berechtigung für {$name} (benötigt Rolle „{$t['role']}“, Schlüssel hat „{$auth['role']}“)");
    }
    foreach ($t['inputSchema']['required'] as $k) {
        if (!array_key_exists($k, $args) || $args[$k] === '' || $args[$k] === null) {
            return tool_err("Fehlender Parameter: {$k}");
        }
    }
    $GLOBALS['mcp_auth'] = $auth;
    try {
        $out = ($t['fn'])($args);
    } catch (ToolError | InvalidArgumentException | RuntimeException $e) {
        return tool_err($e->getMessage());
    } catch (PDOException $e) {
        return tool_err('Datenbankfehler: ' . $e->getMessage());
    }
    if (!$t['annotations']['readOnlyHint']) {
        $short = json_encode(array_diff_key($args, ['snmp_community' => 1, 'sensors' => 1, 'config' => 1]), JSON_UNESCAPED_UNICODE);
        log_event('mcp', "MCP {$auth['name']}: {$name} " . mb_substr((string) $short, 0, 300));
    }
    $text = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => array_is_list($out) ? ['items' => $out] : $out, 'isError' => false];
}

function tool_err(string $m): array
{
    return ['content' => [['type' => 'text', 'text' => $m]], 'isError' => true];
}

/* ===================================================================== Implementierungen */
function iso(?int $ts): ?string
{
    return $ts ? date('c', $ts) : null;
}

function group_paths(): array
{
    $rows = db()->query('SELECT id, parent_id, name, kind FROM groups')->fetchAll();
    $by = array_column($rows, null, 'id');
    $paths = [];
    foreach ($by as $id => $g) {
        $parts = [];
        $cur = $g;
        $guard = 0;
        while ($cur && $guard++ < 50) {
            if ($cur['kind'] !== 'root') {
                array_unshift($parts, $cur['name']);
            }
            $cur = $cur['parent_id'] ? ($by[$cur['parent_id']] ?? null) : null;
        }
        $paths[(int) $id] = implode(' › ', $parts);
    }
    return $paths;
}

function resolve_group(?string $g, bool $create = false): int
{
    $default = (int) db()->query("SELECT id FROM groups WHERE kind = 'probe' ORDER BY id LIMIT 1")->fetchColumn();
    if ($g === null || trim($g) === '') {
        return $default;
    }
    if (ctype_digit($g)) {
        $st = db()->prepare('SELECT id FROM groups WHERE id = ?');
        $st->execute([(int) $g]);
        if ($id = $st->fetchColumn()) {
            return (int) $id;
        }
        throw new ToolError("Gruppe #{$g} nicht gefunden");
    }
    $st = db()->prepare("SELECT id FROM groups WHERE lower(name) = lower(?) AND kind != 'root'");
    $st->execute([trim($g)]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        return (int) $ids[0];
    }
    if (count($ids) > 1) {
        throw new ToolError("Gruppenname „{$g}“ ist mehrdeutig – bitte ID angeben (list_groups)");
    }
    if (!$create) {
        throw new ToolError("Gruppe „{$g}“ nicht gefunden");
    }
    db()->prepare("INSERT INTO groups(parent_id, name, kind) VALUES(?, ?, 'group')")->execute([$default, mb_substr(trim($g), 0, 200)]);
    return (int) db()->lastInsertId();
}

function flat_devices(): array
{
    $t = build_tree();
    $paths = group_paths();
    $out = [];
    $walk = function ($g) use (&$walk, &$out, $paths) {
        foreach ($g['devices'] as $d) {
            $d['group_id'] = $g['id'];
            $d['group'] = $paths[$g['id']] ?? '';
            $out[] = $d;
        }
        foreach ($g['groups'] as $c) {
            $walk($c);
        }
    };
    if ($t['root']) {
        $walk($t['root']);
    }
    usort($out, fn($a, $b) => device_cmp($a, $b));
    return $out;
}

function sensor_brief(array $s): array
{
    return ['id' => $s['id'], 'name' => $s['name'], 'type' => $s['type'], 'status' => $s['status'], 'value' => $s['value'], 'unit' => $s['unit'],
        'message' => $s['message'], 'last_check' => iso($s['last_check']), 'status_since' => iso($s['since'])];
}

function t_status(array $a): array
{
    $t = build_tree();
    $hb = (int) setting('poller_heartbeat', '0');
    $alarms = array_slice(t_alarms(['include_unknown' => false]), 0, 10);
    $devs = (int) db()->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    return ['version' => APP_VERSION, 'time' => date('c'), 'sensors_by_status' => $t['counts'], 'devices' => $devs,
        'poller' => ['last_run' => iso($hb ?: null), 'running' => $hb > 0 && time() - $hb < 180],
        'alarm_threshold' => (int) setting('fail_threshold', '3') . ' Fehlprüfungen in Folge', 'top_alarms' => $alarms];
}

function t_alarms(array $a): array
{
    $want = ['down', 'warning', 'acked'];
    if ($a['include_unknown'] ?? true) {
        $want[] = 'unknown';
    }
    $out = [];
    foreach (flat_devices() as $d) {
        foreach ($d['sensors'] as $s) {
            if (in_array($s['status'], $want, true)) {
                $out[] = ['device_id' => $d['id'], 'device' => $d['name'], 'host' => $d['host'], 'group' => $d['group']] + sensor_brief($s)
                    + ['duration_min' => $s['since'] ? intdiv(time() - $s['since'], 60) : null];
            }
        }
    }
    usort($out, fn($x, $y) => (STATUS_PRIO[$y['status']] <=> STATUS_PRIO[$x['status']]) ?: (($y['duration_min'] ?? 0) <=> ($x['duration_min'] ?? 0)));
    return $out;
}

function t_groups(array $a): array
{
    $paths = group_paths();
    $out = [];
    $walk = function ($g, $parent) use (&$walk, &$out, $paths) {
        if ($g['kind'] !== 'root') {
            $out[] = ['id' => $g['id'], 'name' => $g['name'], 'path' => $paths[$g['id']] ?? $g['name'], 'kind' => $g['kind'], 'parent_id' => $parent,
                'status' => $g['status'], 'counts' => array_filter($g['counts']), 'devices' => count($g['devices']), 'paused' => $g['paused']];
        }
        foreach ($g['groups'] as $c) {
            $walk($c, $g['id']);
        }
    };
    $t = build_tree();
    if ($t['root']) {
        $walk($t['root'], null);
    }
    return $out;
}

function t_devices(array $a): array
{
    $q = mb_strtolower(trim((string) ($a['search'] ?? '')));
    $gid = isset($a['group']) && $a['group'] !== '' ? resolve_group((string) $a['group']) : null;
    $out = [];
    foreach (flat_devices() as $d) {
        if ($q !== '' && !str_contains(mb_strtolower($d['name'] . ' ' . $d['host']), $q)) {
            continue;
        }
        if (!empty($a['status']) && $d['status'] !== $a['status']) {
            continue;
        }
        if ($gid && $d['group_id'] !== $gid) {
            continue;
        }
        $out[] = ['id' => $d['id'], 'name' => $d['name'], 'host' => $d['host'], 'group' => $d['group'], 'status' => $d['status'],
            'counts' => array_filter($d['counts']), 'sensors' => count($d['sensors']), 'icon' => $d['icon'], 'paused' => $d['paused'],
            'web_url' => $d['creds']['web_url'] ?? null];
        if (count($out) >= max(1, min(1000, (int) ($a['limit'] ?? 200)))) {
            break;
        }
    }
    return $out;
}

function find_device(array $a): array
{
    $all = flat_devices();
    if (!empty($a['id'])) {
        foreach ($all as $d) {
            if ($d['id'] === (int) $a['id']) {
                return $d;
            }
        }
        throw new ToolError("Gerät #{$a['id']} nicht gefunden");
    }
    if (!empty($a['host'])) {
        $m = array_values(array_filter($all, fn($d) => strcasecmp($d['host'], (string) $a['host']) === 0));
        if ($m) {
            return $m[0];
        }
        throw new ToolError("Kein Gerät mit Host „{$a['host']}“");
    }
    if (!empty($a['name'])) {
        $n = mb_strtolower((string) $a['name']);
        $exact = array_values(array_filter($all, fn($d) => mb_strtolower($d['name']) === $n));
        if ($exact) {
            return $exact[0];
        }
        $part = array_values(array_filter($all, fn($d) => str_contains(mb_strtolower($d['name']), $n)));
        if (count($part) === 1) {
            return $part[0];
        }
        throw new ToolError(count($part) ? 'Name mehrdeutig: ' . implode(', ', array_map(fn($d) => "#{$d['id']} {$d['name']}", array_slice($part, 0, 10))) : "Kein Gerät „{$a['name']}“");
    }
    throw new ToolError('id, host oder name angeben');
}

function t_get_device(array $a): array
{
    $d = find_device($a);
    $st = db()->prepare('SELECT notes, sysinfo, sysinfo_at, depends_on FROM devices WHERE id = ?');
    $st->execute([$d['id']]);
    $x = $st->fetch();
    return ['id' => $d['id'], 'name' => $d['name'], 'host' => $d['host'], 'group' => $d['group'], 'group_id' => $d['group_id'], 'status' => $d['status'],
        'icon' => $d['icon'], 'paused' => $d['paused'], 'notes' => $x['notes'], 'web_url' => $d['creds']['web_url'] ?? null,
        'snmp_configured' => !empty($d['creds']['snmp_community']), 'depends_on_sensor' => $x['depends_on'] ? (int) $x['depends_on'] : null,
        'sensors' => array_map(fn($s) => sensor_brief($s) + ['channels' => $s['channels'], 'url' => $s['url']], $d['sensors']),
        'sysinfo' => $x['sysinfo'] ? json_decode($x['sysinfo'], true) : null, 'sysinfo_updated' => iso($x['sysinfo_at'] ? (int) $x['sysinfo_at'] : null)];
}

function t_get_sensor(array $a): array
{
    $s = sensor_row((int) $a['id']) ?? throw new ToolError("Sensor #{$a['id']} nicht gefunden");
    $up = db()->prepare("SELECT COUNT(*) n, SUM(status IN ('up','warning')) ok, AVG(value) avg, MIN(value) mn, MAX(value) mx FROM results WHERE sensor_id = ? AND ts > ?");
    $up->execute([$s['id'], time() - 86400]);
    $st = $up->fetch();
    $ev = db()->prepare('SELECT ts, status, message FROM events WHERE sensor_id = ? ORDER BY id DESC LIMIT 20');
    $ev->execute([$s['id']]);
    $out = ['id' => (int) $s['id'], 'name' => $s['name'], 'type' => $s['type'], 'device_id' => (int) $s['device_id'], 'device' => $s['device_name'], 'host' => $s['host'],
        'status' => effective_status($s, paused_group_ids()), 'message' => $s['last_message'], 'value' => $s['last_value'] !== null ? (float) $s['last_value'] : null, 'unit' => $s['last_unit'],
        'channels' => json_decode((string) $s['last_channels'], true) ?: [], 'last_check' => iso($s['last_check'] ? (int) $s['last_check'] : null),
        'status_since' => iso($s['status_since'] ? (int) $s['status_since'] : null), 'interval_s' => (int) $s['interval_s'], 'paused' => (bool) $s['paused'],
        'acknowledged' => (bool) $s['acked'] ? $s['ack_msg'] : null, 'fail_count' => (int) $s['fail_count'],
        'alarm_after' => sensor_fail_threshold($s) . ' Fehlprüfungen in Folge',
        'limits' => array_filter(['warn_max' => $s['warn_max'], 'err_max' => $s['err_max'], 'warn_min' => $s['warn_min'], 'err_min' => $s['err_min']], fn($v) => $v !== null),
        'config' => json_decode($s['config'], true) ?: [],
        'stats_24h' => ['checks' => (int) $st['n'], 'availability_pct' => $st['n'] ? round($st['ok'] / $st['n'] * 100, 2) : null,
            'avg' => $st['avg'] !== null ? round((float) $st['avg'], 3) : null, 'min' => $st['mn'] !== null ? (float) $st['mn'] : null, 'max' => $st['mx'] !== null ? (float) $st['mx'] : null],
        'events' => array_map(fn($e) => ['time' => iso((int) $e['ts']), 'status' => $e['status'], 'message' => $e['message']], $ev->fetchAll())];
    if (in_array($s['type'], PUSH_TYPES, true) && ROLE_RANK[$GLOBALS['mcp_auth']['role']] >= ROLE_RANK['admin']) {
        $out['push_url'] = public_base_url() . 'push.php?token=' . $s['push_token'];
    }
    return $out;
}

function t_history(array $a): array
{
    $ranges = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];
    $range = $ranges[$a['range'] ?? '24h'] ?? 86400;
    $s = sensor_row((int) $a['id']) ?? throw new ToolError("Sensor #{$a['id']} nicht gefunden");
    $step = max(1, intdiv($range, 120));
    $ch = trim((string) ($a['channel'] ?? ''));
    $col = $ch === '' ? 'value' : "json_extract(extra, '$.\"' || :ch || '\"')";
    $q = db()->prepare("SELECT (ts / :step) * :step t, AVG({$col}) v, MIN({$col}) mn, MAX({$col}) mx,
        MAX(CASE status WHEN 'down' THEN 3 WHEN 'warning' THEN 2 ELSE 1 END) s FROM results WHERE sensor_id = :id AND ts >= :from GROUP BY t ORDER BY t");
    $args = [':step' => $step, ':id' => $s['id'], ':from' => time() - $range];
    if ($ch !== '') {
        $args[':ch'] = str_replace('"', '', $ch);
    }
    $q->execute($args);
    $pts = [];
    $vals = [];
    $bad = 0;
    foreach ($q->fetchAll() as $r) {
        $pts[] = [date('c', (int) $r['t']), $r['v'] !== null ? round((float) $r['v'], 3) : null, $r['mn'] !== null ? (float) $r['mn'] : null, $r['mx'] !== null ? (float) $r['mx'] : null, ['', 'ok', 'warning', 'down'][(int) $r['s']]];
        if ($r['v'] !== null) {
            $vals[] = (float) $r['v'];
        }
        $bad += (int) $r['s'] > 1 ? 1 : 0;
    }
    return ['sensor' => $s['name'], 'device' => $s['device_name'], 'channel' => $ch ?: 'Hauptwert', 'unit' => $ch === '' ? $s['last_unit'] : null,
        'range' => $a['range'] ?? '24h', 'bucket_seconds' => $step, 'columns' => ['zeit', 'avg', 'min', 'max', 'status'],
        'summary' => $vals ? ['avg' => round(array_sum($vals) / count($vals), 3), 'min' => min($vals), 'max' => max($vals), 'buckets_with_problems' => $bad] : null,
        'points' => $pts];
}

function t_sensor_types(array $a): array
{
    $out = [];
    foreach (sensor_types() as $k => $t) {
        $out[] = ['type' => $k, 'label' => $t['label'], 'description' => $t['desc'], 'unit' => $t['unit'], 'default_interval_s' => $t['interval'],
            'fields' => array_map(fn($f) => array_intersect_key($f, array_flip(['key', 'label', 'type', 'options', 'default'])), $t['fields']),
            'default_limits' => $t['limits']];
    }
    return $out;
}

function t_templates(array $a): array
{
    $out = [];
    foreach (device_templates() as $k => $t) {
        if ($k === '') {
            continue;
        }
        $out[] = ['key' => $k, 'label' => $t['label'], 'icon' => $t['icon'] ?? 'server', 'parameter' => $t['param'] ?? null,
            'sensors' => array_map(fn($s) => $s['type'] . ': ' . $s['name'] . (!empty($s['optional']) ? ' (optional)' : ''), $t['sensors'])];
    }
    return $out;
}

function t_events(array $a): array
{
    $where = '1=1';
    $args = [];
    if (!empty($a['type'])) {
        $where .= ' AND type = ?';
        $args[] = $a['type'];
    }
    if (!empty($a['search'])) {
        $where .= ' AND message LIKE ?';
        $args[] = '%' . $a['search'] . '%';
    }
    if (!empty($a['hours'])) {
        $where .= ' AND ts > ?';
        $args[] = time() - (int) $a['hours'] * 3600;
    }
    $lim = max(1, min(500, (int) ($a['limit'] ?? 50)));
    $st = db()->prepare("SELECT ts, type, status, sensor_id, device_id, message FROM events WHERE {$where} ORDER BY id DESC LIMIT {$lim}");
    $st->execute($args);
    return array_map(fn($e) => ['time' => iso((int) $e['ts'])] + array_diff_key($e, ['ts' => 1]), $st->fetchAll());
}

function t_maps(array $a): array
{
    $out = [];
    foreach (db()->query('SELECT id, name, data, public_token, updated_at FROM maps ORDER BY name') as $m) {
        $d = json_decode($m['data'], true) ?: [];
        $out[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'elements' => count($d['nodes'] ?? []), 'connections' => count($d['edges'] ?? []),
            'public' => (bool) $m['public_token'], 'updated' => iso((int) $m['updated_at']), 'url' => public_base_url() . '#/maps/' . $m['id']];
    }
    return $out;
}

function t_get_excl(array $a): array
{
    return ['ranges' => (string) setting('scan_exclude', '')];
}

function t_check(array $a): array
{
    $s = sensor_row((int) $a['id']) ?? throw new ToolError("Sensor #{$a['id']} nicht gefunden");
    $r = sensor_run($s);
    $n = sensor_row((int) $a['id']);
    return ['sensor' => $s['name'], 'device' => $s['device_name'], 'result' => $r['status'] ?? null, 'message' => $r['message'] ?? null,
        'value' => $r['value'] ?? null, 'unit' => $r['unit'] ?? null, 'sensor_status_now' => $n['status'], 'fail_count' => (int) $n['fail_count']];
}

function t_ack(array $a): array
{
    $st = db()->prepare("UPDATE sensors SET acked = 1, ack_msg = ? WHERE id = ? AND status = 'down'");
    $st->execute(['MCP ' . $GLOBALS['mcp_auth']['name'] . ': ' . mb_substr((string) $a['message'], 0, 250), (int) $a['sensor_id']]);
    if (!$st->rowCount()) {
        throw new ToolError('Sensor ist nicht im Status „Fehler“ (nur Fehler können bestätigt werden)');
    }
    log_event('user', "Alarm bestätigt (MCP {$GLOBALS['mcp_auth']['name']}): {$a['message']}", (int) $a['sensor_id']);
    return ['ok' => true, 'sensor_id' => (int) $a['sensor_id']];
}

function t_pause(array $a): array
{
    $table = ['group' => 'groups', 'device' => 'devices', 'sensor' => 'sensors'][$a['object']] ?? throw new ToolError('object: group, device oder sensor');
    $p = (int) (bool) $a['paused'];
    $st = db()->prepare("UPDATE {$table} SET paused = ? WHERE id = ?");
    $st->execute([$p, (int) $a['id']]);
    if (!$st->rowCount()) {
        throw new ToolError("{$a['object']} #{$a['id']} nicht gefunden");
    }
    if ($a['object'] === 'sensor' && !$p) {
        db()->prepare("UPDATE sensors SET next_check = 0, status = 'unknown', status_since = ? WHERE id = ?")->execute([time(), (int) $a['id']]);
    }
    return ['ok' => true, 'object' => $a['object'], 'id' => (int) $a['id'], 'paused' => (bool) $p];
}

function t_create_group(array $a): array
{
    $parent = isset($a['parent']) && $a['parent'] !== '' ? resolve_group((string) $a['parent']) : resolve_group(null);
    $st = db()->prepare('SELECT id FROM groups WHERE parent_id = ? AND lower(name) = lower(?)');
    $st->execute([$parent, trim((string) $a['name'])]);
    if ($id = $st->fetchColumn()) {
        return ['id' => (int) $id, 'created' => false];
    }
    db()->prepare("INSERT INTO groups(parent_id, name, kind) VALUES(?, ?, 'group')")->execute([$parent, mb_substr(trim((string) $a['name']), 0, 200)]);
    return ['id' => (int) db()->lastInsertId(), 'created' => true];
}

function t_create_device(array $a): array
{
    $host = trim((string) $a['host']);
    if (!valid_host($host)) {
        throw new ToolError('Ungültiger Host/IP');
    }
    if (empty($a['allow_duplicate'])) {
        $st = db()->prepare('SELECT id, name FROM devices WHERE lower(host) = lower(?)');
        $st->execute([$host]);
        if ($ex = $st->fetch()) {
            throw new ToolError("Host {$host} existiert bereits als Gerät #{$ex['id']} „{$ex['name']}“ (allow_duplicate=true zum Erzwingen)");
        }
    }
    $group = resolve_group(isset($a['group']) ? (string) $a['group'] : null, true);
    $sensors = [];
    $icon = (string) ($a['icon'] ?? '');
    $web = (string) ($a['web_url'] ?? '');
    if (!empty($a['template'])) {
        $sensors = template_sensors((string) $a['template'], $host, $a['template_param'] ?? null);
        $tpl = device_templates()[$a['template']];
        $icon = $icon ?: ($tpl['icon'] ?? 'server');
        if (!$web && !empty($tpl['web'])) {
            $web = str_replace(['{HOST}', '{PORT}'], [$host, (string) ($a['template_param'] ?? ($tpl['param']['def'] ?? ''))], $tpl['web']);
        }
    }
    if (!empty($a['discover'])) {
        $disc = discover_host($host);
        foreach ($disc['found'] as $f) {
            if (!$f['optional']) {
                $sensors[] = ['type' => $f['type'], 'name' => $f['name'], 'config' => $f['config']];
            }
        }
        if ($disc['meta']) {
            $icon = $icon ?: $disc['meta']['icon'];
            $web = $web ?: (string) $disc['meta']['web_url'];
        }
    }
    foreach ((array) ($a['sensors'] ?? []) as $s) {
        if (!isset(sensor_types()[$s['type'] ?? ''])) {
            throw new ToolError('Unbekannter Sensortyp: ' . ($s['type'] ?? '?'));
        }
        $sensors[] = ['type' => $s['type'], 'name' => (string) ($s['name'] ?? ''), 'config' => (array) ($s['config'] ?? [])];
    }
    if (!$sensors) {
        $sensors[] = ['type' => 'ping', 'name' => 'Ping', 'config' => []];
    }
    // doppelte Sensoren (gleicher Typ + Name) vermeiden
    $uniq = [];
    foreach ($sensors as $s) {
        $uniq[$s['type'] . '|' . mb_strtolower($s['name'])] = $s;
    }
    $creds = ['snmp_community' => (string) ($a['snmp_community'] ?? ''), 'snmp_version' => 2, 'snmp_port' => 161, 'username' => '', 'password' => '', 'web_url' => $web];
    db()->prepare('INSERT INTO devices(group_id, name, host, icon, notes, creds) VALUES(?, ?, ?, ?, ?, ?)')
        ->execute([$group, mb_substr(trim((string) $a['name']), 0, 200), $host, $icon ?: 'server', (string) ($a['notes'] ?? ''), json_encode($creds, JSON_UNESCAPED_UNICODE)]);
    $id = (int) db()->lastInsertId();
    $created = [];
    foreach ($uniq as $s) {
        $sid = create_sensor($id, $s['type'], $s['name'], $s['config']);
        $created[] = ['id' => $sid, 'type' => $s['type'], 'name' => sensor_row($sid)['name']];
    }
    log_event('user', "Gerät angelegt (MCP {$GLOBALS['mcp_auth']['name']}): {$a['name']} ({$host})", null, $id);
    return ['id' => $id, 'name' => $a['name'], 'host' => $host, 'group_id' => $group, 'icon' => $icon ?: 'server', 'web_url' => $web ?: null,
        'sensors' => $created, 'hint' => 'Sensoren werden innerhalb einer Minute geprüft (oder sofort mit check_sensor).'];
}

function t_update_device(array $a): array
{
    $st = db()->prepare('SELECT * FROM devices WHERE id = ?');
    $st->execute([(int) $a['id']]);
    $d = $st->fetch() ?: throw new ToolError("Gerät #{$a['id']} nicht gefunden");
    $creds = json_decode((string) $d['creds'], true) ?: [];
    $set = [];
    $args = [];
    foreach (['name', 'host', 'icon', 'notes'] as $k) {
        if (array_key_exists($k, $a)) {
            if ($k === 'host' && !valid_host((string) $a['host'])) {
                throw new ToolError('Ungültiger Host/IP');
            }
            $set[] = "{$k} = ?";
            $args[] = (string) $a[$k];
        }
    }
    if (array_key_exists('group', $a)) {
        $set[] = 'group_id = ?';
        $args[] = resolve_group((string) $a['group'], true);
    }
    if (array_key_exists('web_url', $a)) {
        $creds['web_url'] = (string) $a['web_url'];
    }
    if (array_key_exists('snmp_community', $a)) {
        $creds['snmp_community'] = (string) $a['snmp_community'];
    }
    $set[] = 'creds = ?';
    $args[] = json_encode($creds, JSON_UNESCAPED_UNICODE);
    $args[] = (int) $a['id'];
    db()->prepare('UPDATE devices SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return ['ok' => true, 'id' => (int) $a['id'], 'changed' => array_values(array_intersect(array_keys($a), ['name', 'host', 'icon', 'notes', 'group', 'web_url', 'snmp_community']))];
}

function t_delete_device(array $a): array
{
    if (($a['confirm'] ?? false) !== true) {
        throw new ToolError('confirm=true erforderlich');
    }
    $st = db()->prepare('SELECT name, host FROM devices WHERE id = ?');
    $st->execute([(int) $a['id']]);
    $d = $st->fetch() ?: throw new ToolError("Gerät #{$a['id']} nicht gefunden");
    db()->prepare('DELETE FROM devices WHERE id = ?')->execute([(int) $a['id']]);
    log_event('user', "Gerät gelöscht (MCP {$GLOBALS['mcp_auth']['name']}): {$d['name']} ({$d['host']})");
    return ['ok' => true, 'deleted' => "#{$a['id']} {$d['name']} ({$d['host']})"];
}

function t_create_sensor(array $a): array
{
    $st = db()->prepare('SELECT id FROM devices WHERE id = ?');
    $st->execute([(int) $a['device_id']]);
    if (!$st->fetchColumn()) {
        throw new ToolError("Gerät #{$a['device_id']} nicht gefunden");
    }
    if (!isset(sensor_types()[$a['type']])) {
        throw new ToolError("Unbekannter Sensortyp „{$a['type']}“ (list_sensor_types)");
    }
    $in = array_intersect_key($a, array_flip(['interval_s', 'warn_max', 'err_max', 'warn_min', 'err_min']));
    $id = create_sensor((int) $a['device_id'], (string) $a['type'], (string) ($a['name'] ?? ''), (array) ($a['config'] ?? []), $in);
    if (isset($a['retries']) && $a['retries'] !== '') {
        db()->prepare('UPDATE sensors SET retries = ? WHERE id = ?')->execute([max(1, min(10, (int) $a['retries'])), $id]);
    }
    $s = sensor_row($id);
    return ['id' => $id, 'name' => $s['name'], 'type' => $s['type'], 'config' => json_decode($s['config'], true), 'interval_s' => (int) $s['interval_s']];
}

function t_update_sensor(array $a): array
{
    $s = sensor_row((int) $a['id']) ?? throw new ToolError("Sensor #{$a['id']} nicht gefunden");
    $set = [];
    $args = [];
    if (array_key_exists('name', $a)) {
        $set[] = 'name = ?';
        $args[] = mb_substr((string) $a['name'], 0, 200);
    }
    if (array_key_exists('config', $a)) {
        $merged = array_merge(json_decode($s['config'], true) ?: [], (array) $a['config']);
        $set[] = 'config = ?';
        $args[] = json_encode(sensor_normalize_config($s['type'], $merged), JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('interval_s', $a)) {
        $set[] = 'interval_s = ?';
        $args[] = max(10, (int) $a['interval_s']);
    }
    foreach (['warn_max', 'err_max', 'warn_min', 'err_min'] as $k) {
        if (array_key_exists($k, $a)) {
            $set[] = "{$k} = ?";
            $args[] = is_numeric($a[$k]) ? (float) $a[$k] : null;
        }
    }
    if (array_key_exists('retries', $a)) {
        $set[] = 'retries = ?';
        $args[] = is_numeric($a['retries']) ? max(1, min(10, (int) $a['retries'])) : null;
    }
    if (!$set) {
        throw new ToolError('Keine Änderung angegeben');
    }
    $set[] = 'next_check = 0';
    $args[] = (int) $a['id'];
    db()->prepare('UPDATE sensors SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return t_get_sensor(['id' => (int) $a['id']]);
}

function t_delete_sensor(array $a): array
{
    if (($a['confirm'] ?? false) !== true) {
        throw new ToolError('confirm=true erforderlich');
    }
    $s = sensor_row((int) $a['id']) ?? throw new ToolError("Sensor #{$a['id']} nicht gefunden");
    db()->prepare('DELETE FROM sensors WHERE id = ?')->execute([(int) $a['id']]);
    return ['ok' => true, 'deleted' => "#{$a['id']} {$s['device_name']} › {$s['name']}"];
}

function t_scan(array $a): array
{
    require_once __DIR__ . '/lib/scanner.php';
    @set_time_limit(300);
    $targets = scan_parse_targets((string) $a['targets']);
    $ranges = scan_parse_ranges((string) setting('scan_exclude', ''));
    $before = count($targets);
    $targets = array_values(array_filter($targets, fn($ip) => !ip_in_ranges($ip, $ranges)));
    $existing = array_column(db()->query("SELECT host, name FROM devices WHERE host != ''")->fetchAll(), 'name', 'host');
    $res = $targets ? scan_network($targets, [], !empty($a['deep'])) : [];
    return ['scanned' => count($targets), 'skipped_excluded' => $before - count($targets), 'found' => count($res),
        'hosts' => array_map(fn($h) => ['ip' => $h['ip'], 'hostname' => $h['hostname'], 'suggested_name' => $h['name'], 'type' => $h['type'],
            'template' => $h['template'] === 'generic' ? null : $h['template'], 'icon' => $h['icon'], 'vendor' => $h['vendor'], 'mac' => $h['mac'],
            'services' => $h['port_names'], 'title' => $h['title'], 'web_url' => $h['web_url'], 'exists_as' => $existing[$h['ip']] ?? null], $res)];
}

function t_discover(array $a): array
{
    $r = discover_host((string) $a['host']);
    return ['host' => $a['host'], 'detected' => $r['meta'], 'suggested_sensors' => $r['found']];
}

function t_set_excl(array $a): array
{
    require_once __DIR__ . '/lib/scanner.php';
    $ranges = str_replace(',', "\n", (string) $a['ranges']);
    $parsed = scan_parse_ranges($ranges);
    setting_set('scan_exclude', mb_substr($ranges, 0, 4000));
    return ['ok' => true, 'ranges' => count($parsed)];
}

function t_agent(array $a): array
{
    $st = db()->prepare('SELECT id, name FROM devices WHERE id = ?');
    $st->execute([(int) $a['device_id']]);
    $d = $st->fetch() ?: throw new ToolError("Gerät #{$a['device_id']} nicht gefunden");
    $s = db()->prepare("SELECT id FROM sensors WHERE device_id = ? AND type = 'agent' LIMIT 1");
    $s->execute([$d['id']]);
    $sid = $s->fetchColumn();
    $created = false;
    if (!$sid) {
        $sid = create_sensor((int) $d['id'], 'agent', 'System-Agent', ['services' => (string) ($a['services'] ?? '')]);
        $created = true;
    } elseif (!empty($a['services'])) {
        $row = sensor_row((int) $sid);
        $cfg = array_merge(json_decode($row['config'], true) ?: [], ['services' => (string) $a['services']]);
        db()->prepare('UPDATE sensors SET config = ? WHERE id = ?')->execute([json_encode(sensor_normalize_config('agent', $cfg), JSON_UNESCAPED_UNICODE), $sid]);
    }
    $tok = sensor_row((int) $sid)['push_token'];
    $base = public_base_url();
    $win = $base . 'agent.php?token=' . $tok;
    $lin = $win . '&os=linux';
    return ['device' => $d['name'], 'sensor_id' => (int) $sid, 'sensor_created' => $created,
        'windows' => ['download' => $win, 'install_powershell_admin' => "iwr -UseBasicParsing '{$win}' -OutFile \"\$env:TEMP\\SignalwarteAgent.ps1\"; powershell -ExecutionPolicy Bypass -File \"\$env:TEMP\\SignalwarteAgent.ps1\""],
        'linux' => ['download' => $lin, 'install_shell_root' => "curl -fsSL '{$lin}' -o /tmp/signalwarte-agent.sh && sudo bash /tmp/signalwarte-agent.sh install"],
        'hint' => 'Links enthalten den geheimen Agent-Schlüssel – nur an den jeweiligen Server weitergeben, nicht in Chats/Tickets posten.'];
}

function t_agents(array $a): array
{
    $rows = db()->query("SELECT s.id, s.status, s.acked, s.last_check, s.last_channels, d.id did, d.name, d.host, d.sysinfo FROM sensors s JOIN devices d ON d.id = s.device_id WHERE s.type = 'agent'")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $si = json_decode((string) $r['sysinfo'], true) ?: [];
        $ch = json_decode((string) $r['last_channels'], true) ?: [];
        $disks = [];
        foreach ($ch as $k => $v) {
            if (preg_match('/^Frei (.+) %$/u', $k, $m)) {
                $disks[$m[1]] = $v;
            }
        }
        $plat = $si['platform'] ?? null;
        $latest = $plat ? agent_latest_version($plat) : null;
        $x = ['device_id' => (int) $r['did'], 'device' => $r['name'], 'host' => $r['host'], 'sensor_id' => (int) $r['id'], 'status' => $r['status'] === 'down' && $r['acked'] ? 'acked' : $r['status'],
            'platform' => $plat, 'os' => $si['os'] ?? null, 'agent_version' => $si['agent'] ?? null, 'agent_latest' => $latest,
            'agent_outdated' => $latest && !empty($si['agent']) && version_compare($si['agent'], $latest, '<'),
            'cpu_pct' => $ch['CPU %'] ?? null, 'ram_pct' => $ch['RAM %'] ?? null, 'disk_free_pct' => $disks,
            'updates_pending' => $ch['Updates ausstehend'] ?? null, 'security_updates' => $ch['Sicherheitsupdates'] ?? null,
            'reboot_pending' => isset($ch['Neustart ausstehend']) ? (bool) $ch['Neustart ausstehend'] : null, 'uptime_days' => $ch['Laufzeit (Tage)'] ?? null,
            'last_report' => iso($r['last_check'] ? (int) $r['last_check'] : null)];
        if (!empty($a['only_problems'])) {
            $prob = in_array($x['status'], ['down', 'warning', 'unknown'], true) || ($x['security_updates'] ?? 0) > 0 || $x['reboot_pending']
                || ($x['cpu_pct'] ?? 0) > 85 || ($x['ram_pct'] ?? 0) > 90 || ($disks && min($disks) < 15);
            if (!$prob) {
                continue;
            }
        }
        $out[] = $x;
    }
    usort($out, fn($a, $b) => device_cmp(['host' => $a['host'], 'name' => $a['device']], ['host' => $b['host'], 'name' => $b['device']]));
    return $out;
}

function t_enroll_cmd(array $a): array
{
    if (!enroll_enabled()) {
        throw new ToolError('Selbstanmeldung ist deaktiviert (Agenten › Einstellungen)');
    }
    $base = public_base_url();
    $key = enroll_key();
    $w = $base . 'agent.php?enroll=' . $key;
    return ['windows_powershell_admin' => "iwr -UseBasicParsing '{$w}' -OutFile \"\$env:TEMP\\SignalwarteAgent.ps1\"; powershell -ExecutionPolicy Bypass -File \"\$env:TEMP\\SignalwarteAgent.ps1\"",
        'linux_root' => "curl -fsSL '{$w}&os=linux' -o /tmp/signalwarte-agent.sh && sudo bash /tmp/signalwarte-agent.sh install",
        'hint' => 'Enthält den Registrierungs-Token – nur auf eigenen Servern ausführen, nicht in Chats/Tickets posten.'];
}
