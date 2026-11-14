<?php
// Agenten: Registrierungs-Token (Selbstanmeldung), aktuelle Versionen, Download-/Update-Links.
declare(strict_types=1);

require_once __DIR__ . '/apitokens.php';

/** Aktuelle Agent-Version laut Vorlage (für Selbst-Update). */
function agent_latest_version(string $platform): ?string
{
    static $cache = [];
    if (isset($cache[$platform])) {
        return $cache[$platform];
    }
    $file = $platform === 'linux' ? '/../agent/signalwarte-agent.sh' : '/../agent/SignalwarteAgent.ps1';
    $src = @file_get_contents(__DIR__ . $file, false, null, 0, 4096) ?: '';
    $re = $platform === 'linux' ? '/^AGENT_VERSION="([\d.]+)"/m' : "/^\\\$AgentVersion = '([\\d.]+)'/m";
    return $cache[$platform] = preg_match($re, $src, $m) ? $m[1] : null;
}

/** Download-Link für einen Agenten mit Sensor-Token. */
function agent_download_url(string $token, string $platform): string
{
    return public_base_url() . 'agent.php?token=' . $token . ($platform === 'linux' ? '&os=linux' : '');
}

/** Registrierungs-Schlüssel (wird bei Bedarf erzeugt). */
function enroll_key(): string
{
    $k = (string) setting('enroll_key', '');
    if ($k === '') {
        $k = 'swe_' . bin2hex(random_bytes(16));
        setting_set('enroll_key', $k);
    }
    return $k;
}

function enroll_key_regenerate(): string
{
    setting_set('enroll_key', 'swe_' . bin2hex(random_bytes(16)));
    return enroll_key();
}

function enroll_enabled(): bool
{
    return setting('enroll_enabled', '1') === '1';
}

function enroll_key_valid(string $k): bool
{
    return enroll_enabled() && $k !== '' && hash_equals(enroll_key(), $k);
}

/**
 * Selbstanmeldung eines Agenten: Gerät suchen (IP/Hostname) oder anlegen, System-Agent-Sensor sicherstellen.
 * → ['device_id', 'sensor_id', 'url' (Push-URL), 'created' (bool)]
 */
function agent_enroll(array $in): array
{
    $db = db();
    $hostname = mb_substr(preg_replace('/[^A-Za-z0-9._\- ]/', '', (string) ($in['hostname'] ?? '')), 0, 100);
    $ip = (string) ($in['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '';
    }
    $platform = ($in['platform'] ?? '') === 'linux' ? 'linux' : 'windows';
    if ($hostname === '' && $ip === '') {
        throw new InvalidArgumentException('hostname oder ip fehlt');
    }
    // vorhandenes Gerät: gleiche IP, sonst gleicher Name/Host
    $dev = null;
    foreach ([[$ip, 'host'], [$hostname, 'name'], [$hostname, 'host']] as [$v, $col]) {
        if ($v === '' || $dev) {
            continue;
        }
        $st = $db->prepare("SELECT id, name FROM devices WHERE lower({$col}) = lower(?) ORDER BY id LIMIT 1");
        $st->execute([$v]);
        $dev = $st->fetch() ?: null;
    }
    $created = false;
    if (!$dev) {
        $gid = (int) setting('enroll_group', '0');
        $chk = $db->prepare('SELECT id FROM groups WHERE id = ?');
        $chk->execute([$gid]);
        if (!$chk->fetchColumn()) {
            $gid = (int) $db->query("SELECT id FROM groups WHERE kind = 'probe' ORDER BY id LIMIT 1")->fetchColumn();
        }
        $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes) VALUES(?, ?, ?, ?, ?)')
            ->execute([$gid, $hostname ?: $ip, $ip ?: $hostname, $platform === 'linux' ? 'linux' : 'windows',
                'Selbst angemeldet per Agent am ' . date('d.m.Y H:i') . (!empty($in['os']) ? ' · ' . mb_substr((string) $in['os'], 0, 80) : '')]);
        $dev = ['id' => (int) $db->lastInsertId(), 'name' => $hostname ?: $ip];
        $created = true;
        if ($ip !== '') {
            create_sensor((int) $dev['id'], 'ping', 'Ping', []);
        }
    }
    $st = $db->prepare("SELECT id, push_token FROM sensors WHERE device_id = ? AND type = 'agent' ORDER BY id LIMIT 1");
    $st->execute([$dev['id']]);
    $s = $st->fetch();
    if (!$s) {
        $sid = create_sensor((int) $dev['id'], 'agent', 'System-Agent', ['services' => (string) setting('enroll_services_' . $platform, '')]);
        $s = sensor_row($sid);
    }
    log_event('user', ($created ? 'Neues Gerät per Agent angemeldet: ' : 'Agent angemeldet an vorhandenem Gerät: ') . "{$dev['name']} ({$ip}, {$platform})", null, (int) $dev['id']);
    return ['device_id' => (int) $dev['id'], 'sensor_id' => (int) $s['id'], 'url' => public_base_url() . 'push.php?token=' . $s['push_token'], 'created' => $created];
}
