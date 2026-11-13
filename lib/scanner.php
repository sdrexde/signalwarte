<?php
// Netzwerkscanner: Ping-Sweep (parallel), Port-Check, Reverse-DNS, MAC (ARP), HTTP-Titel,
// Geräte-Erkennung (FRITZ!Box, pfSense, Windows, Linux, Proxmox, Drucker, NAS …) inkl. Sensor-Vorschlägen.
declare(strict_types=1);

const SCAN_PORTS = [
    21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 88 => 'Kerberos', 110 => 'POP3',
    135 => 'RPC', 139 => 'NetBIOS', 143 => 'IMAP', 389 => 'LDAP', 443 => 'HTTPS', 445 => 'SMB', 515 => 'LPD',
    631 => 'IPP', 993 => 'IMAPS', 1433 => 'MS SQL', 1883 => 'MQTT', 3306 => 'MySQL', 3389 => 'RDP', 4444 => 'HTTPS (4444)',
    5000 => 'HTTP 5000', 5001 => 'HTTPS 5001', 5432 => 'PostgreSQL', 5900 => 'VNC', 5985 => 'WinRM', 8006 => 'Proxmox',
    8080 => 'HTTP-Alt', 8123 => 'Home Assistant', 8443 => 'HTTPS-Alt', 9100 => 'Drucker (RAW)', 49000 => 'TR-064 (FRITZ!Box)',
];
const SCAN_HTTP_PORTS = [80 => 'http', 443 => 'https', 4444 => 'https', 5000 => 'http', 5001 => 'https', 8006 => 'https', 8080 => 'http', 8123 => 'http', 8443 => 'https'];

/** "192.168.1.0/24, 10.0.0.5, 10.0.1.10-10.0.1.50" → Liste von IPv4-Adressen (max. 4096) */
function scan_parse_targets(string $in): array
{
    $ips = [];
    foreach (preg_split('/[\s,;]+/', trim($in)) as $t) {
        if ($t === '') {
            continue;
        }
        if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d{1,2})$#', $t, $m)) {
            $bits = (int) $m[2];
            if ($bits < 20 || $bits > 32 || ip2long($m[1]) === false) {
                throw new InvalidArgumentException("Netz {$t}: nur /20 bis /32 erlaubt");
            }
            $net = ip2long($m[1]) & (-1 << (32 - $bits));
            $size = 1 << (32 - $bits);
            for ($i = ($size > 2 ? 1 : 0); $i < ($size > 2 ? $size - 1 : $size); $i++) {
                $ips[] = long2ip($net + $i);
            }
        } elseif (preg_match('#^(\d+\.\d+\.\d+\.\d+)\s*-\s*(\d+\.\d+\.\d+\.\d+|\d+)$#', $t, $m)) {
            $a = ip2long($m[1]);
            $b = str_contains($m[2], '.') ? ip2long($m[2]) : (($a & ~0xff) | (int) $m[2]);
            if ($a === false || $b === false || $b < $a) {
                throw new InvalidArgumentException("Bereich {$t} ungültig");
            }
            for ($x = $a; $x <= $b; $x++) {
                $ips[] = long2ip($x);
            }
        } elseif (filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[] = $t;
        } else {
            throw new InvalidArgumentException("Ziel „{$t}“ nicht erkannt (Beispiele: 192.168.1.0/24, 10.0.0.1-50, 10.0.0.7)");
        }
        if (count($ips) > 4096) {
            throw new InvalidArgumentException('Maximal 4096 Adressen pro Scan');
        }
    }
    if (!$ips) {
        throw new InvalidArgumentException('Bitte ein Netz eingeben, z. B. 192.168.1.0/24');
    }
    return array_values(array_unique($ips));
}

/** Viele Kommandos parallel ausführen → [key => stdout] */
function scan_parallel(array $cmds, int $parallel = 96, int $timeout = 8): array
{
    $out = [];
    $queue = $cmds;
    $running = [];
    $deadline = time() + 240;
    while (($queue || $running) && time() < $deadline) {
        while ($queue && count($running) < $parallel) {
            $key = array_key_first($queue);
            $cmd = $queue[$key];
            unset($queue[$key]);
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                continue;
            }
            stream_set_blocking($pipes[1], false);
            $running[$key] = ['p' => $p, 'pipes' => $pipes, 'buf' => '', 'start' => time()];
        }
        foreach ($running as $key => &$r) {
            $r['buf'] .= (string) stream_get_contents($r['pipes'][1]);
            $st = proc_get_status($r['p']);
            if (!$st['running'] || time() - $r['start'] > $timeout) {
                $r['buf'] .= (string) stream_get_contents($r['pipes'][1]);
                fclose($r['pipes'][1]);
                fclose($r['pipes'][2]);
                if ($st['running']) {
                    proc_terminate($r['p']);
                }
                proc_close($r['p']);
                $out[$key] = $r['buf'];
                unset($running[$key]);
            }
        }
        unset($r);
        usleep(20000);
    }
    return $out;
}

/** Offene TCP-Ports für viele Hosts gleichzeitig prüfen → [ip => [ports]] */
function scan_ports(array $ips, array $ports, float $timeout = 1.5): array
{
    $open = array_fill_keys($ips, []);
    foreach (array_chunk($ips, 24) as $chunk) {
        $socks = [];
        foreach ($chunk as $ip) {
            foreach ($ports as $port) {
                $s = @stream_socket_client("tcp://{$ip}:{$port}", $e, $es, $timeout, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
                if ($s) {
                    $socks["{$ip}|{$port}"] = $s;
                }
            }
        }
        $deadline = microtime(true) + $timeout;
        while ($socks && microtime(true) < $deadline) {
            $w = $socks;
            $r = $ex = null;
            if (@stream_select($r, $w, $ex, 0, 150000) > 0) {
                foreach ($w as $s) {
                    $k = array_search($s, $socks, true);
                    [$ip, $port] = explode('|', $k);
                    if (@stream_socket_get_name($s, true) !== false) {
                        $open[$ip][] = (int) $port;
                    }
                    fclose($s);
                    unset($socks[$k]);
                }
            }
        }
        foreach ($socks as $s) {
            fclose($s);
        }
    }
    foreach ($open as &$p) {
        sort($p);
    }
    return $open;
}

/** HTTP-Titel/Server-Header parallel holen → [ip => ['title'=>…, 'server'=>…, 'url'=>…]] */
function scan_http_titles(array $targets): array
{
    $mh = curl_multi_init();
    $hs = [];
    foreach ($targets as $ip => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RANGE => '0-65535', CURLOPT_USERAGENT => 'Mozilla/5.0 (Reload Monitor Scanner)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$ip] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.5);
        }
    } while ($active && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $ip => $ch) {
        $raw = (string) curl_multi_getcontent($ch);
        $title = preg_match('#<title[^>]*>\s*(.*?)\s*</title>#is', $raw, $m) ? html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8') : '';
        $server = preg_match('/^Server:\s*(.+)$/mi', $raw, $m) ? trim($m[1]) : '';
        $out[$ip] = ['title' => mb_substr(trim(preg_replace('/\s+/', ' ', $title)), 0, 80), 'server' => mb_substr($server, 0, 60),
            'body' => mb_substr($raw, 0, 20000), 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function scan_arp(): array
{
    $map = [];
    foreach (@file('/proc/net/arp') ?: [] as $i => $l) {
        if ($i === 0) {
            continue;
        }
        $p = preg_split('/\s+/', trim($l));
        if (isset($p[3]) && $p[3] !== '00:00:00:00:00:00') {
            $map[$p[0]] = strtoupper($p[3]);
        }
    }
    return $map;
}

/** Hersteller anhand weniger bekannter MAC-Präfixe (OUI) – grobe Hilfe, keine vollständige Liste. */
function scan_vendor(string $mac): string
{
    static $oui = [
        '3C:A6:2F' => 'AVM', '2C:91:AB' => 'AVM', '38:10:D5' => 'AVM', 'C8:0E:14' => 'AVM', '44:4E:6D' => 'AVM', 'E0:28:6D' => 'AVM',
        '5C:49:79' => 'AVM', '98:9B:CB' => 'AVM', 'DC:39:6F' => 'AVM', '7C:FF:4D' => 'AVM', 'B0:F2:08' => 'AVM', '04:B4:FE' => 'AVM',
        '00:08:A2' => 'Netgate', '90:EC:77' => 'Netgate', '00:0C:29' => 'VMware', '00:50:56' => 'VMware', 'BC:24:11' => 'Proxmox-VM',
        '52:54:00' => 'QEMU/KVM', '00:15:5D' => 'Hyper-V', '00:11:32' => 'Synology', '90:09:D0' => 'Synology', '24:5E:BE' => 'QNAP',
        'B8:27:EB' => 'Raspberry Pi', 'DC:A6:32' => 'Raspberry Pi', 'E4:5F:01' => 'Raspberry Pi', '28:CD:C1' => 'Raspberry Pi',
        '00:1B:21' => 'Intel', '3C:FD:FE' => 'Intel', 'F4:F2:6D' => 'TP-Link', '50:C7:BF' => 'TP-Link', '18:E8:29' => 'Ubiquiti',
        '24:A4:3C' => 'Ubiquiti', '74:83:C2' => 'Ubiquiti', 'F0:9F:C2' => 'Ubiquiti', '00:17:88' => 'Philips Hue', 'EC:FA:BC' => 'Espressif',
        '24:0A:C4' => 'Espressif', '30:AE:A4' => 'Espressif', '00:1E:0B' => 'HP', '3C:D9:2B' => 'HP', '00:80:77' => 'Brother',
        '00:1B:A9' => 'Brother', '00:00:85' => 'Canon', '00:26:AB' => 'Epson', '00:04:F2' => 'Polycom', '80:5E:C0' => 'Yealink',
        '00:15:65' => 'Yealink', 'D4:CA:6D' => 'MikroTik', '48:8F:5A' => 'MikroTik', '00:1D:AA' => 'DrayTek', '00:50:7F' => 'DrayTek',
        'AC:1F:6B' => 'Supermicro', '00:25:90' => 'Supermicro', 'F8:BC:12' => 'Dell', '18:66:DA' => 'Dell', '00:1C:C4' => 'HP',
        '94:57:A5' => 'HP', '70:10:6F' => 'HPE', '00:0D:B9' => 'PC Engines', 'A0:36:9F' => 'Intel',
    ];
    return $oui[substr($mac, 0, 8)] ?? '';
}

/** Gerätetyp + Icon + Sensorvorschläge aus Ports/Titel ableiten. */
function scan_classify(string $ip, array $ports, array $http, string $vendor, string $hostname): array
{
    $titles = strtolower(implode(' ', array_map(fn($h) => $h['title'] . ' ' . $h['server'], $http)));
    $bodies = strtolower(implode(' ', array_map(fn($h) => substr($h['body'], 0, 6000), $http)));
    $has = fn(int ...$p) => (bool) array_intersect($p, $ports);
    $type = 'Unbekannt';
    $icon = 'server';
    $tpl = 'generic';
    if ($has(49000) || str_contains($titles, 'fritz!') || str_contains($bodies, 'fritz!box') || $vendor === 'AVM') {
        [$type, $icon, $tpl] = [str_contains($titles, 'repeater') ? 'FRITZ!Repeater' : 'FRITZ!Box', 'fritzbox', 'fritzbox'];
    } elseif (str_contains($titles, 'pfsense') || str_contains($bodies, 'pfsense') || $vendor === 'Netgate') {
        [$type, $icon, $tpl] = ['pfSense Firewall', 'firewall', 'pfsense'];
    } elseif (str_contains($titles, 'opnsense')) {
        [$type, $icon, $tpl] = ['OPNsense Firewall', 'firewall', 'pfsense'];
    } elseif ($has(8006) || str_contains($titles, 'proxmox')) {
        [$type, $icon, $tpl] = ['Proxmox VE', 'hypervisor', 'proxmox'];
    } elseif (str_contains($titles, 'synology') || str_contains($titles, 'diskstation') || str_contains($titles, 'qnap') || in_array($vendor, ['Synology', 'QNAP'], true)) {
        [$type, $icon, $tpl] = ['NAS', 'nas', 'nas'];
    } elseif (str_contains($titles, 'home assistant') || $has(8123)) {
        [$type, $icon, $tpl] = ['Home Assistant', 'smarthome', 'web'];
    } elseif ($has(3389) && $has(445, 135)) {
        [$type, $icon, $tpl] = [$has(88, 389) ? 'Windows Domänencontroller' : 'Windows', $has(88, 389) ? 'windows' : 'windows', 'windows'];
    } elseif ($has(9100, 515, 631) || preg_match('/printer|drucker|laserjet|officejet|brother|epson|canon|kyocera|ricoh/', $titles) || in_array($vendor, ['Brother', 'Canon', 'Epson'], true)) {
        [$type, $icon, $tpl] = ['Drucker', 'printer', 'printer'];
    } elseif (preg_match('/unifi|ubiquiti|omada|access point/', $titles) || $vendor === 'Ubiquiti') {
        [$type, $icon, $tpl] = ['Access Point / Netzwerk', 'wifi', 'generic'];
    } elseif (preg_match('/switch|netgear|procurve|aruba|cisco|mikrotik|routeros/', $titles) || $vendor === 'MikroTik') {
        [$type, $icon, $tpl] = ['Switch / Router', 'switch', 'switch'];
    } elseif (in_array($vendor, ['Yealink', 'Polycom'], true)) {
        [$type, $icon, $tpl] = ['VoIP-Telefon', 'voip', 'generic'];
    } elseif ($has(22) && !$has(3389)) {
        [$type, $icon, $tpl] = ['Linux / Unix', 'linux', 'linux'];
    } elseif ($has(80, 443, 8080, 8443)) {
        [$type, $icon, $tpl] = ['Web-Gerät', 'web', 'web'];
    } elseif ($vendor === 'Espressif') {
        [$type, $icon, $tpl] = ['IoT (ESP)', 'iot', 'generic'];
    }

    $sensors = [['type' => 'ping', 'name' => 'Ping', 'config' => []]];
    $webUrl = '';
    foreach (SCAN_HTTP_PORTS as $p => $proto) {
        if (!in_array($p, $ports, true)) {
            continue;
        }
        $url = $proto . '://' . $ip . (($proto === 'http' && $p === 80) || ($proto === 'https' && $p === 443) ? '' : ':' . $p) . '/';
        if ($webUrl === '' || in_array($p, [443, 4444, 8006, 5001], true)) {
            $webUrl = $url;
        }
        $sensors[] = ['type' => 'http', 'name' => strtoupper($proto) . ($p === 80 || $p === 443 ? '' : " :{$p}"),
            'config' => ['url' => $url, 'verify_ssl' => false, 'expect' => '200-499']];
        if ($proto === 'https') {
            $sensors[] = ['type' => 'ssl', 'name' => "SSL-Zertifikat :{$p}", 'config' => ['port' => $p]];
        }
    }
    foreach ($ports as $p) {
        if (!isset(SCAN_HTTP_PORTS[$p]) && in_array($p, [22, 25, 53, 389, 445, 1433, 3306, 3389, 5432, 5985, 9100], true)) {
            $sensors[] = ['type' => 'port', 'name' => 'Port ' . $p . ' (' . SCAN_PORTS[$p] . ')', 'config' => ['port' => $p]];
        }
    }
    if ($tpl === 'fritzbox') {
        $sensors[] = ['type' => 'fritzbox', 'name' => 'FRITZ!Box Status', 'config' => ['check_wan' => $type === 'FRITZ!Box']];
    }
    if (in_array($tpl, ['pfsense', 'switch', 'nas', 'proxmox', 'linux'], true)) {
        $sensors[] = ['type' => 'snmp_system', 'name' => 'SNMP System', 'config' => [], 'optional' => true];
    }
    if ($tpl === 'pfsense') {
        $sensors[] = ['type' => 'snmp_custom', 'name' => 'Firewall-States', 'config' => ['oid' => OID_PF_STATES_STR, 'unit' => 'States'], 'optional' => true];
    }
    if ($tpl === 'windows') {
        $sensors[] = ['type' => 'agent', 'name' => 'Windows-Agent', 'config' => [], 'optional' => true];
    }
    return ['type' => $type, 'icon' => $icon, 'template' => $tpl, 'sensors' => $sensors, 'web_url' => $webUrl];
}

const OID_PF_STATES_STR = '1.3.6.1.4.1.12325.1.200.1.3.1.0';

/** Kompletter Scan. */
function scan_network(array $ips, array $creds = [], bool $deep = false): array
{
    // 1) Ping-Sweep parallel
    $cmds = [];
    foreach ($ips as $ip) {
        $cmds[$ip] = 'ping -n -c 1 -W 1 ' . escapeshellarg($ip) . ' 2>/dev/null';
    }
    $pings = scan_parallel($cmds, 128, 4);
    $alive = [];
    foreach ($pings as $ip => $o) {
        if (preg_match('/time=([\d.]+)/', $o, $m)) {
            $alive[$ip] = (float) $m[1];
        }
    }
    // 2) Ports: alle lebenden Hosts; bei „gründlich“ auch Hosts ohne Ping-Antwort (Windows-Firewall blockt ICMP)
    $portTargets = $deep ? $ips : array_keys($alive);
    $ports = scan_ports($portTargets, array_keys(SCAN_PORTS), $deep ? 1.0 : 1.5);
    $hosts = array_keys(array_filter($ports, fn($p, $ip) => isset($alive[$ip]) || $p, ARRAY_FILTER_USE_BOTH));
    // 3) Reverse-DNS parallel
    $dns = scan_parallel(array_combine($hosts, array_map(fn($ip) => 'getent hosts ' . escapeshellarg($ip), $hosts)), 64, 3);
    // 4) HTTP-Titel
    $httpTargets = [];
    foreach ($hosts as $ip) {
        foreach (SCAN_HTTP_PORTS as $p => $proto) {
            if (in_array($p, $ports[$ip], true)) {
                $httpTargets["{$ip}|{$p}"] = "{$proto}://{$ip}:{$p}/";
            }
        }
    }
    $titles = $httpTargets ? scan_http_titles($httpTargets) : [];
    $arp = scan_arp();

    $out = [];
    foreach ($hosts as $ip) {
        $hn = '';
        if (preg_match('/^\S+\s+(\S+)/', trim($dns[$ip] ?? ''), $m)) {
            $hn = $m[1];
        }
        $http = [];
        foreach ($titles as $k => $t) {
            if (str_starts_with($k, $ip . '|')) {
                $http[(int) substr($k, strlen($ip) + 1)] = $t;
            }
        }
        $mac = $arp[$ip] ?? '';
        $vendor = $mac ? scan_vendor($mac) : '';
        $cls = scan_classify($ip, $ports[$ip], $http, $vendor, $hn);
        $title = '';
        foreach ($http as $t) {
            if ($t['title'] !== '' && !preg_match('/^(\d{3}\b|login|anmeld|sign in|default|apache|nginx|welcome|willkommen|index of|it works|redirect)/i', $t['title'])) {
                $title = $t['title'];
                break;
            }
        }
        $name = $hn !== '' ? preg_replace('/\..*$/', '', $hn) : ($title !== '' && mb_strlen($title) < 40 ? $title : ($cls['type'] !== 'Unbekannt' ? $cls['type'] . ' ' . $ip : $ip));
        $out[] = [
            'ip' => $ip, 'hostname' => $hn, 'mac' => $mac, 'vendor' => $vendor,
            'ping' => $alive[$ip] ?? null, 'ports' => $ports[$ip],
            'port_names' => array_map(fn($p) => SCAN_PORTS[$p] ?? (string) $p, $ports[$ip]),
            'title' => $title, 'name' => $name,
        ] + $cls;
    }
    usort($out, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    return $out;
}
