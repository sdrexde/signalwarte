<?php
// Sensortypen: Definition (für UI/Validierung) + eigentliche Prüfung.
declare(strict_types=1);

/**
 * Jeder Typ: label, icon, unit, desc, fields (Konfiguration), defaults für Grenzwerte.
 * Feldtypen: text | number | bool | select
 */
function sensor_types(): array
{
    return [
        'ping' => [
            'label' => 'Ping', 'icon' => '📶', 'unit' => 'ms', 'interval' => 60,
            'desc'  => 'ICMP-Echo: Antwortzeit und Paketverlust.',
            'fields' => [
                ['key' => 'count', 'label' => 'Anzahl Pakete', 'type' => 'number', 'default' => 3],
                ['key' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'default' => 2],
            ],
            'limits' => ['warn_max' => 150, 'err_max' => null],
        ],
        'http' => [
            'label' => 'HTTP(S)', 'icon' => '🌐', 'unit' => 'ms', 'interval' => 60,
            'desc'  => 'Ruft eine URL ab, prüft Statuscode, optional ein Schlüsselwort.',
            'fields' => [
                ['key' => 'url', 'label' => 'URL (leer = http://<Host>/)', 'type' => 'text', 'default' => ''],
                ['key' => 'method', 'label' => 'Methode', 'type' => 'select', 'options' => ['GET', 'HEAD', 'POST'], 'default' => 'GET'],
                ['key' => 'expect', 'label' => 'Erwartete Statuscodes (z. B. 200-399)', 'type' => 'text', 'default' => '200-399'],
                ['key' => 'keyword', 'label' => 'Muss enthalten (Schlüsselwort)', 'type' => 'text', 'default' => ''],
                ['key' => 'keyword_absent', 'label' => 'Darf NICHT enthalten', 'type' => 'text', 'default' => ''],
                ['key' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'default' => 10],
                ['key' => 'verify_ssl', 'label' => 'SSL-Zertifikat prüfen', 'type' => 'bool', 'default' => true],
            ],
            'limits' => ['warn_max' => 2000, 'err_max' => null],
        ],
        'port' => [
            'label' => 'TCP-Port', 'icon' => '🔌', 'unit' => 'ms', 'interval' => 60,
            'desc'  => 'Baut eine TCP-Verbindung zu einem Port auf.',
            'fields' => [
                ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'default' => 443],
                ['key' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'default' => 5],
            ],
            'limits' => ['warn_max' => 500, 'err_max' => null],
        ],
        'dns' => [
            'label' => 'DNS', 'icon' => '🧭', 'unit' => 'ms', 'interval' => 300,
            'desc'  => 'Löst einen Namen auf; optional muss eine bestimmte IP enthalten sein.',
            'fields' => [
                ['key' => 'query', 'label' => 'Name (leer = Host)', 'type' => 'text', 'default' => ''],
                ['key' => 'rtype', 'label' => 'Typ', 'type' => 'select', 'options' => ['A', 'AAAA', 'MX', 'TXT', 'CNAME', 'NS'], 'default' => 'A'],
                ['key' => 'expect', 'label' => 'Muss enthalten (z. B. IP)', 'type' => 'text', 'default' => ''],
            ],
            'limits' => ['warn_max' => 500, 'err_max' => null],
        ],
        'ssl' => [
            'label' => 'SSL-Zertifikat', 'icon' => '🔒', 'unit' => 'Tage', 'interval' => 3600,
            'desc'  => 'Restlaufzeit des TLS-Zertifikats in Tagen.',
            'fields' => [
                ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'default' => 443],
                ['key' => 'sni', 'label' => 'SNI-Name (leer = Host)', 'type' => 'text', 'default' => ''],
            ],
            'limits' => ['warn_min' => 21, 'err_min' => 7],
        ],
        'http_json' => [
            'label' => 'HTTP-JSON-Wert', 'icon' => '🧩', 'unit' => '', 'interval' => 60,
            'desc'  => 'Liest einen Zahlenwert per JSON-Pfad aus einer API (z. B. data.temperature).',
            'fields' => [
                ['key' => 'url', 'label' => 'URL', 'type' => 'text', 'default' => ''],
                ['key' => 'path', 'label' => 'JSON-Pfad (Punkt-getrennt, z. B. stats.0.value)', 'type' => 'text', 'default' => ''],
                ['key' => 'unit', 'label' => 'Einheit', 'type' => 'text', 'default' => ''],
                ['key' => 'header', 'label' => 'Zusatz-Header (z. B. Authorization: Bearer …)', 'type' => 'text', 'default' => ''],
                ['key' => 'timeout', 'label' => 'Timeout (s)', 'type' => 'number', 'default' => 10],
            ],
            'limits' => [],
        ],
        'push' => [
            'label' => 'HTTP-Push', 'icon' => '📥', 'unit' => '', 'interval' => 300,
            'desc'  => 'Gerät/Skript meldet sich selbst per URL. Fehler, wenn innerhalb des Intervalls nichts kommt.',
            'fields' => [
                ['key' => 'unit', 'label' => 'Einheit des Werts', 'type' => 'text', 'default' => ''],
            ],
            'limits' => [],
        ],
        'snmp_system' => [
            'label' => 'SNMP System', 'icon' => '🖥️', 'unit' => '%', 'interval' => 60,
            'desc'  => 'CPU, Arbeitsspeicher und Laufzeit per SNMP (pfSense, Switches, Linux, NAS, Windows mit SNMP-Dienst).',
            'fields' => [
                ['key' => 'community', 'label' => 'Community (leer = vom Gerät)', 'type' => 'text', 'default' => ''],
            ],
            'limits' => ['warn_max' => 80, 'err_max' => 95],
        ],
        'snmp_traffic' => [
            'label' => 'SNMP Traffic', 'icon' => '📊', 'unit' => 'Mbit/s', 'interval' => 60,
            'desc'  => 'Datenrate eines Interfaces (ein/aus) per SNMP, inkl. Auslastung und Link-Status.',
            'fields' => [
                ['key' => 'interface', 'label' => 'Interface (Index oder Name, z. B. igb0 / WAN)', 'type' => 'text', 'default' => ''],
                ['key' => 'community', 'label' => 'Community (leer = vom Gerät)', 'type' => 'text', 'default' => ''],
                ['key' => 'down_is_error', 'label' => 'Link down = Fehler', 'type' => 'bool', 'default' => true],
            ],
            'limits' => [],
        ],
        'snmp_custom' => [
            'label' => 'SNMP Wert (OID)', 'icon' => '🔢', 'unit' => '', 'interval' => 60,
            'desc'  => 'Beliebige OID abfragen – als Wert oder als Rate pro Sekunde (z. B. pfSense-Firewall-States).',
            'fields' => [
                ['key' => 'oid', 'label' => 'OID', 'type' => 'text', 'default' => ''],
                ['key' => 'mode', 'label' => 'Auswertung', 'type' => 'select', 'options' => ['Wert', 'Rate pro Sekunde'], 'default' => 'Wert'],
                ['key' => 'factor', 'label' => 'Faktor (z. B. 0.01)', 'type' => 'number', 'default' => 1],
                ['key' => 'unit', 'label' => 'Einheit', 'type' => 'text', 'default' => ''],
                ['key' => 'community', 'label' => 'Community (leer = vom Gerät)', 'type' => 'text', 'default' => ''],
            ],
            'limits' => [],
        ],
        'fritzbox' => [
            'label' => 'FRITZ!Box', 'icon' => '📡', 'unit' => 'Mbit/s', 'interval' => 60,
            'desc'  => 'Internet-Verbindung, externe IP, aktuelle Up-/Download-Rate, Sync-Raten, Laufzeit, Modell/Firmware, verbundene Geräte (TR-064).',
            'fields' => [
                ['key' => 'check_wan', 'label' => 'Internetverbindung prüfen (aus bei Repeater/IP-Client)', 'type' => 'bool', 'default' => true],
                ['key' => 'port', 'label' => 'TR-064-Port', 'type' => 'number', 'default' => 49000],
            ],
            'limits' => [],
        ],
        'agent' => [
            'label' => 'System-Agent (Windows/Linux)', 'icon' => '🖥️', 'unit' => '%', 'interval' => 120,
            'desc'  => 'Skript auf dem Server (Windows: PowerShell, Linux: Bash + systemd) meldet CPU, RAM, Laufwerke, Dienste, Updates, Neustart nötig, Fehler, Netzwerk und Systeminformationen (Hersteller, Modell, Seriennummer, CPU, IPs …).',
            'fields' => [
                ['key' => 'primary', 'label' => 'Hauptwert (wird in Kachel und Graph angezeigt)', 'type' => 'channel', 'default' => 'CPU %'],
                ['key' => 'services', 'label' => 'Zu überwachende Dienste (Komma-getrennt; Windows z. B. DNS,NTDS · Linux z. B. nginx,docker,ssh)', 'type' => 'text', 'default' => ''],
                ['key' => 'ignore_failed', 'label' => 'Fehlgeschlagene systemd-Units ignorieren (Linux, Komma-getrennt, * erlaubt, z. B. qwenduline-sync.service)', 'type' => 'text', 'default' => ''],
                ['key' => 'ignore_stopped', 'label' => 'Gestoppte Auto-Dienste ignorieren (Komma-getrennt, * erlaubt, z. B. Avira*,edgeupdate)', 'type' => 'text', 'default' => ''],
                ['key' => 'disk_warn', 'label' => 'Laufwerk frei Warnung unter %', 'type' => 'number', 'default' => 15],
                ['key' => 'disk_err', 'label' => 'Laufwerk frei Fehler unter %', 'type' => 'number', 'default' => 5],
            ],
            'limits' => ['warn_max' => 90, 'err_max' => null],
        ],
        'agent_channel' => [
            'label' => 'Agent-Wert (einzelner Wert)', 'icon' => '📈', 'unit' => '', 'interval' => 60,
            'desc'  => 'Zeigt einen einzelnen Wert des System-Agenten als eigenen Sensor – z. B. nur RAM, nur Festplatte C: oder nur Sicherheitsupdates – mit eigenen Grenzwerten, Alarmen und Verlauf. Benötigt einen System-Agent am selben Gerät.',
            'fields' => [
                ['key' => 'channel', 'label' => 'Wert vom System-Agent', 'type' => 'channel', 'default' => 'RAM %'],
            ],
            'limits' => [],
        ],
        'cpu' => [
            'label' => 'CPU-Last (Probe)', 'icon' => '🧠', 'unit' => '%', 'interval' => 60,
            'desc'  => 'CPU-Auslastung des Servers, auf dem der Monitor läuft (lokale Probe).',
            'fields' => [],
            'limits' => ['warn_max' => 80, 'err_max' => 95],
        ],
        'memory' => [
            'label' => 'Arbeitsspeicher (Probe)', 'icon' => '💾', 'unit' => '%', 'interval' => 60,
            'desc'  => 'RAM-Belegung des Probe-Servers in Prozent.',
            'fields' => [],
            'limits' => ['warn_max' => 85, 'err_max' => 95],
        ],
        'disk' => [
            'label' => 'Speicherplatz (Probe)', 'icon' => '🗄️', 'unit' => '%', 'interval' => 300,
            'desc'  => 'Belegter Speicher eines Pfads auf dem Probe-Server.',
            'fields' => [
                ['key' => 'path', 'label' => 'Pfad', 'type' => 'text', 'default' => '/'],
            ],
            'limits' => ['warn_max' => 85, 'err_max' => 95],
        ],
    ];
}

/** Konfiguration gegen Typ-Definition normalisieren. */
function sensor_normalize_config(string $type, array $in): array
{
    $def = sensor_types()[$type] ?? null;
    if (!$def) {
        throw new InvalidArgumentException('Unbekannter Sensortyp');
    }
    $out = [];
    foreach ($def['fields'] as $f) {
        $v = $in[$f['key']] ?? $f['default'];
        $out[$f['key']] = match ($f['type']) {
            'number' => is_numeric($v) ? $v + 0 : $f['default'],
            'bool'   => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'select' => in_array($v, $f['options'], true) ? $v : $f['default'],
            default  => trim((string) $v),
        };
    }
    return $out;
}

/* =============================================================== Prüfungen */

function r_ok(?float $value, string $unit, string $msg, array $extra = []): array
{
    return ['status' => 'up', 'value' => $value, 'unit' => $unit, 'message' => $msg, 'extra' => $extra];
}

function r_fail(string $msg, ?float $value = null, string $unit = '', array $extra = []): array
{
    return ['status' => 'down', 'value' => $value, 'unit' => $unit, 'message' => $msg, 'extra' => $extra];
}

/** Führt die Prüfung eines Sensors aus. $sensor = DB-Zeile inkl. host. */
function sensor_check(array $sensor): array
{
    $cfg  = json_decode($sensor['config'] ?: '{}', true) ?: [];
    $host = trim((string) ($sensor['host'] ?? ''));
    try {
        return match ($sensor['type']) {
            'ping'      => check_ping($host, $cfg),
            'http'      => check_http($host, $cfg),
            'port'      => check_port($host, $cfg),
            'dns'       => check_dns($host, $cfg),
            'ssl'       => check_ssl($host, $cfg),
            'http_json' => check_http_json($cfg),
            'snmp_system' => check_snmp_system($host, $cfg, $sensor),
            'snmp_traffic' => check_snmp_traffic($host, $cfg, $sensor),
            'snmp_custom' => check_snmp_custom($host, $cfg, $sensor),
            'fritzbox'  => check_fritzbox($host, $cfg, $sensor),
            'agent_channel' => check_agent_channel($cfg, $sensor),
            'cpu'       => check_cpu(),
            'memory'    => check_memory(),
            'disk'      => check_disk($cfg),
            default     => r_fail('Sensortyp nicht prüfbar: ' . $sensor['type']),
        };
    } catch (Throwable $e) {
        return r_fail('Interner Fehler: ' . $e->getMessage());
    }
}

function valid_host(string $host): bool
{
    return $host !== '' && (bool) preg_match('/^[A-Za-z0-9.\-:_\[\]]+$/', $host);
}

function check_ping(string $host, array $cfg): array
{
    if (!valid_host($host)) {
        return r_fail('Kein gültiger Host am Gerät hinterlegt');
    }
    $count   = max(1, min(10, (int) ($cfg['count'] ?? 3)));
    $timeout = max(1, min(10, (int) ($cfg['timeout'] ?? 2)));
    $bin = str_contains($host, ':') ? 'ping -6' : 'ping';
    $cmd = sprintf('%s -n -q -c %d -i 0.2 -W %d %s 2>&1', $bin, $count, $timeout, escapeshellarg($host));
    $out = shell_exec($cmd) ?? '';
    $loss = preg_match('/([\d.]+)% packet loss/', $out, $m) ? (float) $m[1] : 100.0;
    if (preg_match('#= ([\d.]+)/([\d.]+)/([\d.]+)#', $out, $m)) {
        $extra = ['Minimum' => (float) $m[1], 'Maximum' => (float) $m[3], 'Paketverlust' => $loss];
        $avg = (float) $m[2];
        $r = r_ok($avg, 'ms', sprintf('%.1f ms, %s%% Verlust', $avg, $loss + 0), $extra);
        if ($loss > 0) {
            $r['status'] = 'warning';
            $r['message'] = "Paketverlust {$loss}%";
        }
        return $r;
    }
    if (preg_match('/unknown host|Name or service not known|Temporary failure/i', $out)) {
        return r_fail('Host nicht auflösbar');
    }
    return r_fail('Keine Antwort (100% Paketverlust)', null, 'ms', ['Paketverlust' => 100]);
}

function http_request(string $url, array $opt): array
{
    $ch = curl_init($url);
    $headers = [];
    if (!empty($opt['header']) && str_contains($opt['header'], ':')) {
        $headers[] = $opt['header'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => max(1, min(60, (int) ($opt['timeout'] ?? 10))),
        CURLOPT_CONNECTTIMEOUT => max(1, min(30, (int) ($opt['timeout'] ?? 10))),
        CURLOPT_USERAGENT      => APP_NAME . '/' . APP_VERSION,
        CURLOPT_SSL_VERIFYPEER => $opt['verify_ssl'] ?? true,
        CURLOPT_SSL_VERIFYHOST => ($opt['verify_ssl'] ?? true) ? 2 : 0,
        CURLOPT_NOBODY         => ($opt['method'] ?? 'GET') === 'HEAD',
        CURLOPT_CUSTOMREQUEST  => $opt['method'] ?? 'GET',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [$body === false ? null : (string) $body, $err, $info];
}

function status_matches(int $code, string $expr): bool
{
    $expr = trim($expr) ?: '200-399';
    foreach (preg_split('/\s*,\s*/', $expr) as $part) {
        if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $m)) {
            if ($code >= (int) $m[1] && $code <= (int) $m[2]) {
                return true;
            }
        } elseif (ctype_digit($part) && (int) $part === $code) {
            return true;
        }
    }
    return false;
}

function check_http(string $host, array $cfg): array
{
    $url = trim((string) ($cfg['url'] ?? ''));
    if ($url === '') {
        if (!valid_host($host)) {
            return r_fail('Keine URL und kein Host hinterlegt');
        }
        $url = 'http://' . $host . '/';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }
    [$body, $err, $info] = http_request($url, $cfg);
    $ms = round(($info['total_time'] ?? 0) * 1000, 1);
    if ($body === null) {
        return r_fail('Verbindungsfehler: ' . $err);
    }
    $code  = (int) $info['http_code'];
    $extra = ['HTTP-Status' => $code, 'Größe (KB)' => round(strlen($body) / 1024, 1)];
    if (!status_matches($code, (string) ($cfg['expect'] ?? ''))) {
        return r_fail("HTTP {$code} (erwartet {$cfg['expect']})", $ms, 'ms', $extra);
    }
    $kw = (string) ($cfg['keyword'] ?? '');
    if ($kw !== '' && !str_contains($body, $kw)) {
        return r_fail("Schlüsselwort „{$kw}“ nicht gefunden", $ms, 'ms', $extra);
    }
    $nkw = (string) ($cfg['keyword_absent'] ?? '');
    if ($nkw !== '' && str_contains($body, $nkw)) {
        return r_fail("Verbotenes Wort „{$nkw}“ gefunden", $ms, 'ms', $extra);
    }
    return r_ok($ms, 'ms', "HTTP {$code} in {$ms} ms", $extra);
}

function check_port(string $host, array $cfg): array
{
    if (!valid_host($host)) {
        return r_fail('Kein gültiger Host am Gerät hinterlegt');
    }
    $port = (int) ($cfg['port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        return r_fail('Ungültiger Port');
    }
    $t0 = microtime(true);
    $target = str_contains($host, ':') && $host[0] !== '[' ? "[{$host}]" : $host;
    $fp = @stream_socket_client("tcp://{$target}:{$port}", $errno, $errstr, (float) ($cfg['timeout'] ?? 5));
    $ms = round((microtime(true) - $t0) * 1000, 1);
    if (!$fp) {
        return r_fail("Port {$port} nicht erreichbar: " . ($errstr ?: 'Timeout'));
    }
    fclose($fp);
    return r_ok($ms, 'ms', "Port {$port} offen ({$ms} ms)");
}

function check_dns(string $host, array $cfg): array
{
    $q = trim((string) ($cfg['query'] ?? '')) ?: $host;
    if (!valid_host($q)) {
        return r_fail('Kein Name zum Auflösen');
    }
    $map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'CNAME' => DNS_CNAME, 'NS' => DNS_NS];
    $type = $cfg['rtype'] ?? 'A';
    $t0 = microtime(true);
    $recs = @dns_get_record($q, $map[$type] ?? DNS_A);
    $ms = round((microtime(true) - $t0) * 1000, 1);
    if (!$recs) {
        return r_fail("Keine {$type}-Einträge für {$q}", $ms, 'ms');
    }
    $vals = array_map(fn($r) => $r['ip'] ?? $r['ipv6'] ?? $r['target'] ?? $r['txt'] ?? '', $recs);
    $list = implode(', ', array_filter($vals));
    $exp  = trim((string) ($cfg['expect'] ?? ''));
    if ($exp !== '' && !str_contains($list, $exp)) {
        return r_fail("„{$exp}“ nicht in Antwort ({$list})", $ms, 'ms');
    }
    return r_ok($ms, 'ms', "{$type}: " . mb_substr($list, 0, 200), ['Einträge' => count($recs)]);
}

function check_ssl(string $host, array $cfg): array
{
    if (!valid_host($host)) {
        return r_fail('Kein gültiger Host am Gerät hinterlegt');
    }
    $port = (int) ($cfg['port'] ?? 443) ?: 443;
    $sni  = trim((string) ($cfg['sni'] ?? '')) ?: $host;
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
        'SNI_enabled' => true, 'peer_name' => $sni,
    ]]);
    $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return r_fail('TLS-Verbindung fehlgeschlagen: ' . $errstr);
    }
    $params = stream_context_get_params($fp);
    fclose($fp);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? '');
    if (!$cert) {
        return r_fail('Zertifikat nicht lesbar');
    }
    $days = round(($cert['validTo_time_t'] - time()) / 86400, 1);
    $cn = $cert['subject']['CN'] ?? '?';
    $issuer = $cert['issuer']['O'] ?? ($cert['issuer']['CN'] ?? '?');
    $msg = sprintf('%s, gültig bis %s (%s Tage), Aussteller %s', $cn, date('d.m.Y', $cert['validTo_time_t']), $days, $issuer);
    if ($days < 0) {
        return r_fail('Zertifikat abgelaufen: ' . $msg, $days, 'Tage');
    }
    return r_ok($days, 'Tage', $msg);
}

function json_path_get($data, string $path)
{
    if ($path === '') {
        return $data;
    }
    foreach (explode('.', $path) as $k) {
        if (is_array($data) && array_key_exists($k, $data)) {
            $data = $data[$k];
        } elseif (is_array($data) && ctype_digit($k) && array_key_exists((int) $k, $data)) {
            $data = $data[(int) $k];
        } else {
            return null;
        }
    }
    return $data;
}

function check_http_json(array $cfg): array
{
    $url = trim((string) ($cfg['url'] ?? ''));
    if (!preg_match('#^https?://#i', $url)) {
        return r_fail('Keine gültige URL');
    }
    [$body, $err, $info] = http_request($url, $cfg + ['method' => 'GET']);
    if ($body === null) {
        return r_fail('Verbindungsfehler: ' . $err);
    }
    if ((int) $info['http_code'] >= 400) {
        return r_fail('HTTP ' . $info['http_code']);
    }
    $json = json_decode($body, true);
    if ($json === null) {
        return r_fail('Antwort ist kein JSON');
    }
    $v = json_path_get($json, (string) ($cfg['path'] ?? ''));
    if (is_bool($v)) {
        $v = $v ? 1 : 0;
    }
    if (!is_numeric($v)) {
        return r_fail('Pfad liefert keinen Zahlenwert: ' . mb_substr(json_encode($v), 0, 80));
    }
    $unit = (string) ($cfg['unit'] ?? '');
    return r_ok((float) $v, $unit, 'Wert: ' . $v . ($unit ? " {$unit}" : ''));
}

function cpu_sample(): ?array
{
    $l = @file('/proc/stat');
    if (!$l) {
        return null;
    }
    $p = preg_split('/\s+/', trim($l[0]));
    array_shift($p);
    $p = array_map('intval', $p);
    $idle = $p[3] + ($p[4] ?? 0);
    return [array_sum($p), $idle];
}

function check_cpu(): array
{
    $a = cpu_sample();
    usleep(500000);
    $b = cpu_sample();
    if (!$a || !$b || $b[0] === $a[0]) {
        $load = sys_getloadavg();
        return r_ok(null, '%', 'Load: ' . implode(' / ', $load));
    }
    $pct = round(100 * (1 - ($b[1] - $a[1]) / ($b[0] - $a[0])), 1);
    $load = array_map(fn($v) => round($v, 2), sys_getloadavg() ?: [0, 0, 0]);
    return r_ok($pct, '%', "CPU {$pct}% · Load {$load[0]} / {$load[1]} / {$load[2]}", ['Load 1m' => $load[0], 'Load 5m' => $load[1], 'Load 15m' => $load[2]]);
}

function check_memory(): array
{
    $info = @file_get_contents('/proc/meminfo');
    if (!$info || !preg_match('/MemTotal:\s+(\d+)/', $info, $t) || !preg_match('/MemAvailable:\s+(\d+)/', $info, $a)) {
        return r_fail('/proc/meminfo nicht lesbar');
    }
    $total = (int) $t[1];
    $used  = $total - (int) $a[1];
    $pct = round($used / $total * 100, 1);
    return r_ok($pct, '%', sprintf('%.1f von %.1f GB belegt', $used / 1048576, $total / 1048576), ['Frei (MB)' => round((int) $a[1] / 1024)]);
}

function check_disk(array $cfg): array
{
    $path = (string) ($cfg['path'] ?? '/') ?: '/';
    $total = @disk_total_space($path);
    $free  = @disk_free_space($path);
    if (!$total) {
        return r_fail("Pfad {$path} nicht lesbar");
    }
    $pct = round(($total - $free) / $total * 100, 1);
    return r_ok($pct, '%', sprintf('%s: %.1f GB frei von %.1f GB', $path, $free / 1e9, $total / 1e9), ['Frei (GB)' => round($free / 1e9, 1)]);
}

/* ==================================================================== SNMP */

function sensor_creds(array $sensor): array
{
    $c = json_decode((string) ($sensor['creds'] ?? '{}'), true);
    return is_array($c) ? $c : [];
}

function snmp_for(string $host, array $cfg, array $sensor): SnmpClient
{
    require_once __DIR__ . '/snmp.php';
    if (!valid_host($host)) {
        throw new RuntimeException('Kein gültiger Host am Gerät hinterlegt');
    }
    $cr = sensor_creds($sensor);
    $community = trim((string) ($cfg['community'] ?? '')) ?: (trim((string) ($cr['snmp_community'] ?? '')) ?: 'public');
    $ver = (int) ($cr['snmp_version'] ?? 2) === 1 ? 1 : 2;
    $port = (int) ($cr['snmp_port'] ?? 161) ?: 161;
    return new SnmpClient($host, $community, $ver, $port, 2.0, 1);
}

function sensor_state(array $sensor): array
{
    $st = json_decode((string) ($sensor['state'] ?? ''), true);
    return is_array($st) ? $st : [];
}

function fmt_uptime(float $sec): string
{
    $d = floor($sec / 86400);
    $h = floor(fmod($sec, 86400) / 3600);
    $m = floor(fmod($sec, 3600) / 60);
    return ($d ? "{$d} T " : '') . "{$h} h {$m} min";
}

function check_snmp_system(string $host, array $cfg, array $sensor): array
{
    require_once __DIR__ . '/snmp.php';
    $c = snmp_for($host, $cfg, $sensor);
    $base = $c->get([OID_SYS_DESCR, OID_SYS_UPTIME, OID_SYS_NAME]);
    if ($base[OID_SYS_UPTIME] === null && $base[OID_SYS_DESCR] === null) {
        return r_fail('SNMP antwortet ohne Systemdaten');
    }
    $extra = [];
    $up = (float) ($base[OID_SYS_UPTIME] ?? 0) / 100;
    $extra['Laufzeit (Tage)'] = round($up / 86400, 2);
    $cpu = null;
    try {
        $loads = array_values($c->walk(OID_HR_CPU, 256));
        if ($loads) {
            $cpu = round(array_sum($loads) / count($loads), 1);
            $extra['CPU-Kerne'] = count($loads);
        }
    } catch (Throwable) {
    }
    if ($cpu === null) {
        try {
            $l = $c->getOne(OID_UCD_LOAD1);
            if ($l !== null) {
                $extra['Load 1m'] = (float) $l;
                $memMsg = " · Load {$l}";
            }
        } catch (Throwable) {
        }
    }
    $memMsg = '';
    try {
        $types = $c->walk(OID_HR_STO_TYPE, 200);
        foreach ($types as $oid => $t) {
            $i = substr($oid, strrpos($oid, '.') + 1);
            $isRam = $t === '1.3.6.1.2.1.25.2.1.2';
            $isDisk = $t === '1.3.6.1.2.1.25.2.1.4';
            if (!$isRam && !$isDisk) {
                continue;
            }
            $v = $c->get([OID_HR_STO_DESC . ".$i", OID_HR_STO_UNIT . ".$i", OID_HR_STO_SIZE . ".$i", OID_HR_STO_USED . ".$i"]);
            $size = (float) $v[OID_HR_STO_SIZE . ".$i"];
            if ($size <= 0) {
                continue;
            }
            $pct = round((float) $v[OID_HR_STO_USED . ".$i"] / $size * 100, 1);
            if ($isRam) {
                $extra['RAM %'] = $pct;
                $memMsg = sprintf(' · RAM %s%% von %.1f GB', $pct, $size * (float) $v[OID_HR_STO_UNIT . ".$i"] / 1073741824);
            } elseif (count($extra) < 20) {
                $name = mb_substr(trim((string) $v[OID_HR_STO_DESC . ".$i"]), 0, 24);
                if (preg_match('#^/(dev|proc|run|sys)(/|$)#', $name) || $size * (float) $v[OID_HR_STO_UNIT . ".$i"] < 1e9) {
                    continue;
                }
                $extra["Belegt {$name} %"] = $pct;
            }
        }
    } catch (Throwable) {
    }
    try {
        $states = $c->getOne(OID_PF_STATES);
        if (is_numeric($states)) {
            $extra['Firewall-States'] = (int) $states;
        }
    } catch (Throwable) {
    }
    $name = trim((string) ($base[OID_SYS_NAME] ?? ''));
    $descr = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($base[OID_SYS_DESCR] ?? ''))), 0, 90);
    $msg = ($name ? "{$name}: " : '') . ($cpu !== null ? "CPU {$cpu}%" : 'CPU n/v') . $memMsg . ' · Laufzeit ' . fmt_uptime($up) . ($descr ? " · {$descr}" : '');
    return r_ok($cpu, '%', $msg, $extra);
}

/** Interface-Index anhand Index/Name/Beschreibung/Alias finden. */
function snmp_find_if(SnmpClient $c, string $want): ?int
{
    $want = trim($want);
    if ($want === '') {
        return null;
    }
    if (ctype_digit($want)) {
        return (int) $want;
    }
    foreach (snmp_interfaces($c) as $i => $if) {
        foreach (['name', 'descr', 'alias'] as $k) {
            if ($if[$k] !== '' && strcasecmp($if[$k], $want) === 0) {
                return $i;
            }
        }
    }
    return null;
}

function check_snmp_traffic(string $host, array $cfg, array $sensor): array
{
    require_once __DIR__ . '/snmp.php';
    $c = snmp_for($host, $cfg, $sensor);
    $st = sensor_state($sensor);
    $idx = isset($st['if']) && ($st['if_want'] ?? null) === ($cfg['interface'] ?? '') ? (int) $st['if'] : snmp_find_if($c, (string) ($cfg['interface'] ?? ''));
    if (!$idx) {
        return r_fail('Interface „' . ($cfg['interface'] ?? '') . '“ nicht gefunden – im Sensor „Interfaces laden“ nutzen');
    }
    $hc = $c->get([OID_IF_HC_IN . ".$idx", OID_IF_HC_OUT . ".$idx", OID_IF_HSPEED . ".$idx"]);
    $in = $hc[OID_IF_HC_IN . ".$idx"];
    $out = $hc[OID_IF_HC_OUT . ".$idx"];
    $wrap = 18446744073709551616.0;
    if ($in === null || $out === null) {
        $lo = $c->get([OID_IF_IN . ".$idx", OID_IF_OUT . ".$idx"]);
        $in = $lo[OID_IF_IN . ".$idx"];
        $out = $lo[OID_IF_OUT . ".$idx"];
        $wrap = 4294967296.0;
    }
    $meta = $c->get([OID_IF_OPER . ".$idx", OID_IF_DESCR . ".$idx"]);
    if ($in === null) {
        return r_fail("Interface {$idx} liefert keine Zähler");
    }
    $now = microtime(true);
    $state = ['if' => $idx, 'if_want' => $cfg['interface'] ?? '', 'in' => (float) $in, 'out' => (float) $out, 't' => $now];
    $speed = (float) ($hc[OID_IF_HSPEED . ".$idx"] ?? 0);
    $linkUp = (int) ($meta[OID_IF_OPER . ".$idx"] ?? 1) === 1;
    $name = (string) ($meta[OID_IF_DESCR . ".$idx"] ?? $idx);
    if (!$linkUp && ($cfg['down_is_error'] ?? true)) {
        $r = r_fail("Interface {$name}: Link down");
        $r['state'] = $state;
        return $r;
    }
    if (!isset($st['t']) || ($st['if'] ?? null) !== $idx || $now - (float) $st['t'] > 3600) {
        $r = r_ok(null, 'Mbit/s', "Interface {$name}: erste Messung, Rate folgt bei nächster Prüfung");
        $r['status'] = 'unknown';
        $r['state'] = $state;
        return $r;
    }
    $dt = max(1.0, $now - (float) $st['t']);
    $d = function (float $cur, float $prev) use ($wrap): float {
        $x = $cur - $prev;
        return $x < 0 ? $x + $wrap : $x;
    };
    $rin = round($d((float) $in, (float) $st['in']) * 8 / $dt / 1e6, 3);
    $rout = round($d((float) $out, (float) $st['out']) * 8 / $dt / 1e6, 3);
    $sum = round($rin + $rout, 3);
    $extra = ['Eingang Mbit/s' => $rin, 'Ausgang Mbit/s' => $rout];
    if ($speed > 0) {
        $extra['Auslastung %'] = round(max($rin, $rout) / $speed * 100, 1);
        $extra['Link Mbit/s'] = $speed;
    }
    $r = r_ok($sum, 'Mbit/s', sprintf('%s: ↓ %s Mbit/s · ↑ %s Mbit/s%s', $name, $rin, $rout, $speed ? " · Link {$speed} Mbit/s" : ''), $extra);
    $r['state'] = $state;
    return $r;
}

function check_snmp_custom(string $host, array $cfg, array $sensor): array
{
    require_once __DIR__ . '/snmp.php';
    $oid = trim((string) ($cfg['oid'] ?? ''), " .");
    if (!preg_match('/^\d+(\.\d+)+$/', $oid)) {
        return r_fail('Ungültige OID');
    }
    $c = snmp_for($host, $cfg, $sensor);
    $v = $c->getOne($oid);
    if ($v === null) {
        return r_fail("OID {$oid} nicht vorhanden");
    }
    $unit = (string) ($cfg['unit'] ?? '');
    $factor = (float) ($cfg['factor'] ?? 1) ?: 1.0;
    if (!is_numeric($v)) {
        return r_ok(null, $unit, 'Wert: ' . mb_substr((string) $v, 0, 200));
    }
    if (($cfg['mode'] ?? 'Wert') === 'Rate pro Sekunde') {
        $st = sensor_state($sensor);
        $now = microtime(true);
        $state = ['v' => (float) $v, 't' => $now];
        if (!isset($st['t'])) {
            $r = r_ok(null, $unit, 'Erste Messung, Rate folgt');
            $r['status'] = 'unknown';
            $r['state'] = $state;
            return $r;
        }
        $delta = (float) $v - (float) $st['v'];
        if ($delta < 0) {
            $delta += 4294967296.0;
        }
        $val = round($delta / max(1, $now - (float) $st['t']) * $factor, 3);
        $r = r_ok($val, $unit, "{$val} {$unit}/s");
        $r['state'] = $state;
        return $r;
    }
    $val = round((float) $v * $factor, 3);
    return r_ok($val, $unit, "{$val} {$unit}");
}

/* ============================================================ Agent-Wert */

/** Einheit aus dem Kanalnamen ableiten („RAM %“ → %, „Frei C: GB“ → GB …). */
function channel_unit(string $ch): string
{
    foreach (['%' => '/ %$/u', 'GB' => '/ GB$/u', 'MB' => '/ MB$/u', 'Mbit/s' => '/Mbit\/s$/u', 'Tage' => '/\(Tage\)$/u', '°C' => '/°C$/u'] as $u => $re) {
        if (preg_match($re, $ch)) {
            return $u;
        }
    }
    return '';
}

/** Empfohlene Grenzwerte und Anzeigename für einen Agent-Kanal. */
function channel_defaults(string $ch): array
{
    $rules = [
        ['/^CPU %$/u', 'CPU-Last', ['warn_max' => 80, 'err_max' => 95]],
        ['/^RAM %$/u', 'Arbeitsspeicher', ['warn_max' => 85, 'err_max' => 95]],
        ['/^Swap %$/u', 'Swap', ['warn_max' => 50]],
        ['/^Frei (.+) %$/u', 'Festplatte $1 frei', ['warn_min' => 15, 'err_min' => 5]],
        ['/^Frei (.+) GB$/u', 'Festplatte $1 frei (GB)', []],
        ['/^Sicherheitsupdates$/u', 'Sicherheitsupdates', ['warn_max' => 0]],
        ['/^Kritische Updates$/u', 'Kritische Updates', ['warn_max' => 0]],
        ['/^Updates ausstehend$/u', 'Updates ausstehend', []],
        ['/^Neustart ausstehend$/u', 'Neustart ausstehend', ['warn_max' => 0]],
        ['/^Auto-Dienste gestoppt$/u', 'Gestoppte Auto-Dienste', ['warn_max' => 0]],
        ['/^Dienste fehlgeschlagen$/u', 'Fehlgeschlagene Dienste', ['warn_max' => 0]],
        ['/^Tage seit letztem Update$/u', 'Tage seit letztem Update', ['warn_max' => 45]],
        ['/^Defender aktiv$/u', 'Defender Echtzeitschutz', ['err_min' => 1]],
        ['/^Defender Signatur \(Tage\)$/u', 'Defender-Signatur (Alter)', ['warn_max' => 3]],
        ['/^CPU-Temperatur/u', 'CPU-Temperatur', ['warn_max' => 80, 'err_max' => 90]],
    ];
    foreach ($rules as [$re, $name, $lim]) {
        if (preg_match($re, $ch, $m)) {
            return ['name' => preg_replace('/\$1/', $m[1] ?? '', $name), 'limits' => $lim];
        }
    }
    return ['name' => $ch, 'limits' => []];
}

/** Wert aus der letzten Meldung des System-Agenten am selben Gerät. */
function check_agent_channel(array $cfg, array $sensor): array
{
    $ch = trim((string) ($cfg['channel'] ?? ''));
    if ($ch === '') {
        return r_fail('Kein Wert ausgewählt');
    }
    $st = db()->prepare("SELECT status, last_check, last_channels, interval_s FROM sensors WHERE device_id = ? AND type = 'agent' AND paused = 0 ORDER BY last_check DESC LIMIT 1");
    $st->execute([$sensor['device_id']]);
    $a = $st->fetch();
    if (!$a) {
        return r_fail('Kein System-Agent an diesem Gerät');
    }
    if (!$a['last_check']) {
        $r = r_ok(null, channel_unit($ch), 'Warte auf erste Meldung des System-Agenten');
        $r['status'] = 'unknown';
        return $r;
    }
    $age = time() - (int) $a['last_check'];
    if ($age > (int) (max(10, (int) $a['interval_s']) * 1.5) + 60) {
        return r_fail('System-Agent meldet sich nicht (letzte Meldung vor ' . round($age / 60) . ' min)');
    }
    $chs = json_decode((string) $a['last_channels'], true) ?: [];
    if (!array_key_exists($ch, $chs)) {
        return r_fail("Wert „{$ch}“ wird vom Agenten nicht gemeldet");
    }
    $v = (float) $chs[$ch];
    $u = channel_unit($ch);
    return r_ok($v, $u, $ch . ': ' . rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . ($u ? " {$u}" : ''));
}

/* =============================================================== FRITZ!Box */

function tr064_call(string $host, int $port, string $path, string $service, string $action, ?array $auth = null): ?array
{
    $body = '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
        . "<s:Body><u:{$action} xmlns:u=\"{$service}\"></u:{$action}></s:Body></s:Envelope>";
    $target = str_contains($host, ':') ? "[{$host}]" : $host;
    $ch = curl_init("http://{$target}:{$port}{$path}");
    $opt = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset="utf-8"', "SOAPAction: \"{$service}#{$action}\""],
    ];
    if ($auth && (($auth['user'] ?? '') !== '' || ($auth['pass'] ?? '') !== '')) {
        $opt[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
        $opt[CURLOPT_USERPWD] = ($auth['user'] ?? '') . ':' . ($auth['pass'] ?? '');
    }
    curl_setopt_array($ch, $opt);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('TR-064 nicht erreichbar: ' . $err);
    }
    if ($code === 401) {
        throw new RuntimeException('TR-064: Anmeldung fehlgeschlagen (Benutzer/Passwort am Gerät prüfen)');
    }
    if ($code !== 200) {
        return null;
    }
    if (!preg_match_all('#<(New[A-Za-z0-9_\-]+)>([^<]*)</\1>#', $res, $m, PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    foreach ($m as $x) {
        $out[$x[1]] = html_entity_decode($x[2], ENT_QUOTES | ENT_XML1);
    }
    return $out;
}

function check_fritzbox(string $host, array $cfg, array $sensor): array
{
    if (!valid_host($host)) {
        return r_fail('Kein gültiger Host am Gerät hinterlegt');
    }
    $port = (int) ($cfg['port'] ?? 49000) ?: 49000;
    $cr = sensor_creds($sensor);
    $auth = ['user' => (string) ($cr['username'] ?? ''), 'pass' => (string) ($cr['password'] ?? '')];
    $hasAuth = $auth['user'] !== '' || $auth['pass'] !== '';
    $extra = [];
    $parts = [];
    $value = null;
    $status = 'up';

    // Geräteinfo (benötigt Login)
    if ($hasAuth) {
        $di = tr064_call($host, $port, '/upnp/control/deviceinfo', 'urn:dslforum-org:service:DeviceInfo:1', 'GetInfo', $auth);
        if ($di) {
            $parts[] = trim(($di['NewModelName'] ?? 'FRITZ!Box') . ' · FRITZ!OS ' . ($di['NewSoftwareVersion'] ?? '?'));
            if (isset($di['NewUpTime'])) {
                $extra['Laufzeit (Tage)'] = round((int) $di['NewUpTime'] / 86400, 2);
            }
        }
        $hn = tr064_call($host, $port, '/upnp/control/hosts', 'urn:dslforum-org:service:Hosts:1', 'GetHostNumberOfEntries', $auth);
        if ($hn && isset($hn['NewHostNumberOfEntries'])) {
            $extra['Bekannte Geräte'] = (int) $hn['NewHostNumberOfEntries'];
        }
    }

    if ($cfg['check_wan'] ?? true) {
        // UPnP-IGD-Schnittstelle (ohne Login, „Statusinformationen über UPnP übertragen“ muss aktiv sein)
        $base = ['/igdupnp/control/WANCommonIFC1', 'urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1'];
        $addon = tr064_call($host, $port, $base[0], $base[1], 'GetAddonInfos');
        $link = tr064_call($host, $port, $base[0], $base[1], 'GetCommonLinkProperties');
        $ip = tr064_call($host, $port, '/igdupnp/control/WANIPConn1', 'urn:schemas-upnp-org:service:WANIPConnection:1', 'GetStatusInfo');
        $ext = tr064_call($host, $port, '/igdupnp/control/WANIPConn1', 'urn:schemas-upnp-org:service:WANIPConnection:1', 'GetExternalIPAddress');
        if ($addon === null && $ip === null) {
            if (!$parts) {
                return r_fail('Keine TR-064/UPnP-Daten. In der FRITZ!Box „Heimnetz › Netzwerk › Netzwerkeinstellungen › Zugriff für Anwendungen zulassen“ und „Statusinformationen über UPnP übertragen“ aktivieren.');
            }
            $parts[] = 'WAN-Status nicht abrufbar (UPnP-Statusinformationen deaktiviert?)';
            $status = 'warning';
        } else {
            $conn = $ip['NewConnectionStatus'] ?? '?';
            $extra['Online'] = $conn === 'Connected' ? 1 : 0;
            if (isset($ip['NewUptime'])) {
                $extra['Verbindung seit (h)'] = round((int) $ip['NewUptime'] / 3600, 1);
            }
            if ($addon) {
                $down = round((float) ($addon['NewByteReceiveRate'] ?? 0) * 8 / 1e6, 3);
                $up = round((float) ($addon['NewByteSendRate'] ?? 0) * 8 / 1e6, 3);
                $extra['Download Mbit/s'] = $down;
                $extra['Upload Mbit/s'] = $up;
                $value = $down;
            }
            if ($link) {
                $extra['Sync Download Mbit/s'] = round((float) ($link['NewLayer1DownstreamMaxBitRate'] ?? 0) / 1e6, 1);
                $extra['Sync Upload Mbit/s'] = round((float) ($link['NewLayer1UpstreamMaxBitRate'] ?? 0) / 1e6, 1);
                if (!empty($link['NewWANAccessType'])) {
                    $parts[] = $link['NewWANAccessType'];
                }
            }
            $parts[] = 'Internet: ' . ($conn === 'Connected' ? 'verbunden' : $conn) . (!empty($ext['NewExternalIPAddress']) ? ' · IP ' . $ext['NewExternalIPAddress'] : '');
            if (isset($extra['Download Mbit/s'])) {
                $parts[] = "↓ {$extra['Download Mbit/s']} / ↑ {$extra['Upload Mbit/s']} Mbit/s";
            }
            if ($conn !== 'Connected') {
                $status = 'down';
            }
        }
    }
    if (!$parts && !$extra) {
        return r_fail('FRITZ!Box antwortet nicht auf TR-064');
    }
    $r = r_ok($value, 'Mbit/s', implode(' · ', $parts), $extra);
    $r['status'] = $status;
    return $r;
}

/* ======================================================= Grenzwerte/Status */

/** Wendet Grenzwerte auf ein Prüfergebnis an. */
function apply_limits(array $r, array $s): array
{
    if ($r['status'] === 'down' && $r['value'] === null) {
        return $r;
    }
    $v = $r['value'];
    if ($v === null) {
        return $r;
    }
    $chk = [
        ['err_max', fn($l) => $v > $l, 'down', 'über Fehlergrenze'],
        ['err_min', fn($l) => $v < $l, 'down', 'unter Fehlergrenze'],
        ['warn_max', fn($l) => $v > $l, 'warning', 'über Warngrenze'],
        ['warn_min', fn($l) => $v < $l, 'warning', 'unter Warngrenze'],
    ];
    foreach ($chk as [$key, $test, $st, $txt]) {
        if ($s[$key] !== null && $s[$key] !== '' && $test((float) $s[$key])) {
            if (STATUS_PRIO[$st] > STATUS_PRIO[$r['status']]) {
                $r['status'] = $st;
                $r['message'] = sprintf('%s%s (%s %s) – %s', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'), $r['unit'] !== '' ? ' ' . $r['unit'] : '', $txt, $s[$key] + 0, $r['message']);
            }
            break;
        }
    }
    return $r;
}
