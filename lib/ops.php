<?php
// Gemeinsame Operationen für Weboberfläche (api.php) und MCP-Server (mcp.php).
declare(strict_types=1);

require_once __DIR__ . '/engine.php';

if (!function_exists('in_num')) {
    function in_num(array $in, string $k): ?float
    {
        return isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null && is_numeric($in[$k]) ? (float) $in[$k] : null;
    }
}

function create_sensor(int $deviceId, string $type, string $name, array $config, array $in = []): int
{
    $types = sensor_types();
    $def = $types[$type];
    $cfg = sensor_normalize_config($type, $config);
    $lim = $def['limits'];
    $pick = fn($k) => array_key_exists($k, $in) ? in_num($in, $k) : ($lim[$k] ?? null);
    if ($name === '') {
        $name = $def['label'];
        if ($type === 'port') {
            $name = 'Port ' . $cfg['port'];
        } elseif ($type === 'http' && $cfg['url']) {
            $name = 'HTTP ' . (parse_url($cfg['url'], PHP_URL_HOST) ?: $cfg['url']);
        }
    }
    db()->prepare('INSERT INTO sensors(device_id, name, type, config, interval_s, warn_max, err_max, warn_min, err_min, status_since, push_token, next_check) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$deviceId, $name, $type, json_encode($cfg, JSON_UNESCAPED_UNICODE),
            $iv = max(10, (int) ($in['interval_s'] ?? $def['interval'])),
            $pick('warn_max'), $pick('err_max'), $pick('warn_min'), $pick('err_min'), time(),
            in_array($type, PUSH_TYPES, true) ? random_token() : null,
            in_array($type, PUSH_TYPES, true) ? time() + $iv : 0]);
    return (int) db()->lastInsertId();
}

/** Auto-Discovery für ein Gerät: nutzt den Netzwerkscanner (Ports, HTTP-Titel, Gerätetyp). */
function discover_host(string $host): array
{
    require_once __DIR__ . '/scanner.php';
    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('Host nicht auflösbar (IPv4)');
    }
    $res = scan_network([$ip], [], true);
    if (!$res) {
        return ['found' => [], 'meta' => null];
    }
    $h = $res[0];
    $found = [];
    foreach ($h['sensors'] as $s) {
        $cfg = $s['config'];
        // Hostname statt IP in URLs verwenden, wenn so eingegeben
        if ($ip !== $host && isset($cfg['url'])) {
            $cfg['url'] = str_replace('://' . $ip, '://' . $host, $cfg['url']);
        }
        $found[] = ['type' => $s['type'], 'name' => $s['name'], 'config' => $cfg, 'optional' => !empty($s['optional']),
            'info' => $s['type'] === 'ping' ? ($h['ping'] !== null ? $h['ping'] . ' ms' : 'keine Antwort') : (!empty($s['optional']) ? 'benötigt SNMP/Agent' : 'erkannt')];
    }
    $meta = ['type' => $h['type'], 'icon' => $h['icon'], 'template' => $h['template'], 'web_url' => $ip !== $host ? str_replace('://' . $ip, '://' . $host, $h['web_url']) : $h['web_url'],
        'name' => $h['hostname'] ? preg_replace('/\..*$/', '', $h['hostname']) : $h['name'], 'ports' => $h['port_names'], 'vendor' => $h['vendor'], 'mac' => $h['mac']];
    return ['found' => $found, 'meta' => $meta];
}
