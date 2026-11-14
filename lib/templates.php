<?php
// Gerätevorlagen (von Oberfläche und MCP gemeinsam genutzt). {HOST} und {PORT} werden ersetzt.
declare(strict_types=1);

function device_templates(): array
{
    return [
        '' => ['label' => '— keine Vorlage —', 'sensors' => [['type' => 'ping', 'name' => 'Ping']]],
        'windows' => ['label' => 'Windows-Server', 'icon' => 'windows', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'agent', 'name' => 'System-Agent'],
            ['type' => 'port', 'name' => 'RDP (3389)', 'config' => ['port' => 3389]], ['type' => 'port', 'name' => 'SMB (445)', 'config' => ['port' => 445]]]],
        'dc' => ['label' => 'Windows-Domänencontroller', 'icon' => 'windows', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'agent', 'name' => 'System-Agent', 'config' => ['services' => 'NTDS,DNS,Netlogon,KDC,W32Time']],
            ['type' => 'port', 'name' => 'LDAP (389)', 'config' => ['port' => 389]], ['type' => 'port', 'name' => 'Kerberos (88)', 'config' => ['port' => 88]],
            ['type' => 'dns', 'name' => 'DNS-Dienst', 'config' => ['query' => '{HOST}']]]],
        'linux' => ['label' => 'Linux-Server', 'icon' => 'linux', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'agent', 'name' => 'System-Agent'],
            ['type' => 'port', 'name' => 'SSH (22)', 'config' => ['port' => 22]]]],
        'pfsense' => ['label' => 'pfSense / OPNsense', 'icon' => 'firewall', 'param' => ['key' => 'PORT', 'label' => 'Web-Port', 'def' => 4444], 'web' => 'https://{HOST}:{PORT}/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'],
            ['type' => 'http', 'name' => 'Weboberfläche :{PORT}', 'config' => ['url' => 'https://{HOST}:{PORT}/', 'verify_ssl' => false, 'expect' => '200-499']],
            ['type' => 'ssl', 'name' => 'SSL-Zertifikat :{PORT}', 'config' => ['port' => '{PORT}']],
            ['type' => 'snmp_system', 'name' => 'SNMP System (CPU/RAM/States)'],
            ['type' => 'snmp_custom', 'name' => 'Firewall-States', 'config' => ['oid' => '1.3.6.1.4.1.12325.1.200.1.3.1.0', 'unit' => 'States']]]],
        'fritzbox' => ['label' => 'FRITZ!Box (Internet-Router)', 'icon' => 'fritzbox', 'web' => 'http://{HOST}/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'fritzbox', 'name' => 'FRITZ!Box Status', 'config' => ['check_wan' => true]],
            ['type' => 'http', 'name' => 'Weboberfläche', 'config' => ['url' => 'http://{HOST}/', 'expect' => '200-499']]]],
        'fritzrep' => ['label' => 'FRITZ!Repeater / FRITZ!Box als IP-Client', 'icon' => 'wifi', 'web' => 'http://{HOST}/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'fritzbox', 'name' => 'FRITZ! Status', 'config' => ['check_wan' => false]]]],
        'switch' => ['label' => 'Switch (SNMP)', 'icon' => 'switch', 'sensors' => [['type' => 'ping', 'name' => 'Ping'], ['type' => 'snmp_system', 'name' => 'SNMP System']]],
        'proxmox' => ['label' => 'Proxmox VE', 'icon' => 'hypervisor', 'web' => 'https://{HOST}:8006/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'http', 'name' => 'Proxmox-Weboberfläche', 'config' => ['url' => 'https://{HOST}:8006/', 'verify_ssl' => false]],
            ['type' => 'ssl', 'name' => 'SSL-Zertifikat :8006', 'config' => ['port' => 8006]], ['type' => 'agent', 'name' => 'System-Agent', 'optional' => true]]],
        'nas' => ['label' => 'NAS (Synology/QNAP)', 'icon' => 'nas', 'web' => 'https://{HOST}:5001/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'http', 'name' => 'DSM / Weboberfläche', 'config' => ['url' => 'https://{HOST}:5001/', 'verify_ssl' => false, 'expect' => '200-499']],
            ['type' => 'port', 'name' => 'SMB (445)', 'config' => ['port' => 445]], ['type' => 'snmp_system', 'name' => 'SNMP System', 'optional' => true]]],
        'printer' => ['label' => 'Drucker', 'icon' => 'printer', 'web' => 'http://{HOST}/', 'sensors' => [
            ['type' => 'ping', 'name' => 'Ping'], ['type' => 'port', 'name' => 'Druckport (9100)', 'config' => ['port' => 9100]],
            ['type' => 'http', 'name' => 'Weboberfläche', 'config' => ['url' => 'http://{HOST}/', 'expect' => '200-499']]]],
        'web' => ['label' => 'Webseite / Webanwendung', 'icon' => 'web', 'web' => 'https://{HOST}/', 'sensors' => [
            ['type' => 'http', 'name' => 'HTTPS', 'config' => ['url' => 'https://{HOST}/']], ['type' => 'ssl', 'name' => 'SSL-Zertifikat', 'config' => ['port' => 443]]]],
    ];
}

/** Sensorliste einer Vorlage mit eingesetztem Host/Port. */
function template_sensors(string $key, string $host, $param = null, bool $withOptional = false): array
{
    $t = device_templates()[$key] ?? null;
    if ($t === null) {
        throw new InvalidArgumentException("Unbekannte Vorlage „{$key}“. Verfügbar: " . implode(', ', array_filter(array_keys(device_templates()))));
    }
    $param ??= $t['param']['def'] ?? '';
    $fill = fn($v) => is_string($v) ? str_replace(['{HOST}', '{PORT}'], [$host, (string) $param], $v) : $v;
    $out = [];
    foreach ($t['sensors'] as $s) {
        if (!empty($s['optional']) && !$withOptional) {
            continue;
        }
        $cfg = [];
        foreach ((array) ($s['config'] ?? []) as $k => $v) {
            $cfg[$k] = $v === '{PORT}' ? (int) $param : $fill($v);
        }
        $out[] = ['type' => $s['type'], 'name' => $fill($s['name']), 'config' => $cfg];
    }
    return $out;
}
