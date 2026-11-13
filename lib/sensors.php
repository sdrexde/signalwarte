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
                $r['message'] = sprintf('%s %s (%s %s) – %s', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'), $r['unit'], $txt, $s[$key], $r['message']);
            }
            break;
        }
    }
    return $r;
}
