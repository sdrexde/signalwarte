<?php
// Export / Import der Konfiguration (Gruppen, Geräte, Sensoren, Maps, Benachrichtigungen, Icons, Einstellungen).
declare(strict_types=1);

const EXPORT_SETTINGS = ['retention_days', 'poller_workers', 'scan_exclude', 'require_2fa', 'session_idle_min', 'fail_threshold', 'retry_delay'];

function export_all(bool $withSecrets): array
{
    $db = db();
    $devices = $db->query('SELECT id, group_id, name, host, icon, notes, sort, paused, depends_on, creds FROM devices ORDER BY id')->fetchAll();
    foreach ($devices as &$d) {
        $c = json_decode((string) $d['creds'], true) ?: [];
        if (!$withSecrets) {
            unset($c['password'], $c['snmp_community']);
        }
        $d['creds'] = $c;
    }
    unset($d);
    $sensors = $db->query('SELECT id, device_id, name, type, config, interval_s, sort, paused, warn_max, err_max, warn_min, err_min, retries, push_token FROM sensors ORDER BY id')->fetchAll();
    foreach ($sensors as &$s) {
        $s['config'] = json_decode((string) $s['config'], true) ?: [];
        if (!$withSecrets) {
            unset($s['push_token']);
        }
    }
    unset($s);
    $notifs = $db->query('SELECT name, type, config, on_down, on_warning, on_up, enabled FROM notifications ORDER BY id')->fetchAll();
    foreach ($notifs as &$n) {
        $n['config'] = json_decode((string) $n['config'], true) ?: [];
        if (!$withSecrets) {
            unset($n['config']['bot_token'], $n['config']['token']);
        }
    }
    unset($n);
    $maps = $db->query('SELECT name, data, refresh_s FROM maps ORDER BY id')->fetchAll();
    foreach ($maps as &$m) {
        $m['data'] = json_decode((string) $m['data'], true);
    }
    unset($m);
    $settings = [];
    foreach (EXPORT_SETTINGS as $k) {
        if (($v = setting($k)) !== null) {
            $settings[$k] = $v;
        }
    }
    return [
        'type' => 'reload-monitor-export', 'version' => 1, 'app_version' => APP_VERSION,
        'exported_at' => date('c'), 'with_secrets' => $withSecrets,
        'groups' => $db->query('SELECT id, parent_id, name, kind, sort, paused FROM groups ORDER BY id')->fetchAll(),
        'devices' => $devices, 'sensors' => $sensors, 'notifications' => $notifs, 'maps' => $maps,
        'icons' => $db->query('SELECT id, name, data FROM icons ORDER BY id')->fetchAll(),
        'settings' => $settings,
    ];
}

/**
 * Import. mode = merge (ergänzen, vorhandene Geräte mit gleichem Host+Name werden übersprungen)
 *        | replace (bestehende Gruppen/Geräte/Sensoren/Maps/Benachrichtigungen/Icons werden ersetzt).
 * IDs werden neu vergeben; Verknüpfungen in Maps und Abhängigkeiten werden umgeschrieben.
 */
function import_all(array $x, string $mode): array
{
    if (($x['type'] ?? '') !== 'reload-monitor-export') {
        throw new InvalidArgumentException('Keine Reload-Monitor-Exportdatei');
    }
    $db = db();
    $stats = ['groups' => 0, 'devices' => 0, 'sensors' => 0, 'maps' => 0, 'notifications' => 0, 'icons' => 0, 'skipped' => 0];
    $db->beginTransaction();
    try {
        if ($mode === 'replace') {
            $db->exec('DELETE FROM results');
            $db->exec('DELETE FROM sensors');
            $db->exec('DELETE FROM devices');
            $db->exec("DELETE FROM groups WHERE kind NOT IN ('root','probe')");
            $db->exec('DELETE FROM maps');
            $db->exec('DELETE FROM notifications');
            $db->exec('DELETE FROM icons');
        }
        // Icons
        $iconMap = [];
        $existingIcons = [];
        foreach ($db->query('SELECT id, name, data FROM icons') as $i) {
            $existingIcons[md5($i['name'] . $i['data'])] = (int) $i['id'];
        }
        foreach ((array) ($x['icons'] ?? []) as $i) {
            $k = md5(($i['name'] ?? '') . ($i['data'] ?? ''));
            if (isset($existingIcons[$k])) {
                $iconMap['c' . $i['id']] = 'c' . $existingIcons[$k];
                continue;
            }
            if (!preg_match('#^data:image/#', (string) ($i['data'] ?? ''))) {
                continue;
            }
            $db->prepare('INSERT INTO icons(name, data, created_at) VALUES(?, ?, ?)')->execute([mb_substr((string) $i['name'], 0, 60), $i['data'], time()]);
            $iconMap['c' . $i['id']] = 'c' . $db->lastInsertId();
            $stats['icons']++;
        }
        $icon = fn(string $ic) => $iconMap[$ic] ?? $ic;

        // Gruppen (Root/Probe werden auf die vorhandenen abgebildet; Gruppen gleichen Namens unter gleichem Eltern werden zusammengeführt)
        $root = (int) $db->query("SELECT id FROM groups WHERE kind = 'root' LIMIT 1")->fetchColumn();
        $probe = (int) $db->query("SELECT id FROM groups WHERE kind = 'probe' ORDER BY id LIMIT 1")->fetchColumn();
        $gMap = [];
        $groups = (array) ($x['groups'] ?? []);
        $pending = $groups;
        for ($pass = 0; $pending && $pass < 50; $pass++) {
            $next = [];
            foreach ($pending as $g) {
                if (($g['kind'] ?? '') === 'root') {
                    $gMap[$g['id']] = $root;
                    continue;
                }
                if (($g['kind'] ?? '') === 'probe') {
                    $gMap[$g['id']] = $probe;
                    continue;
                }
                if (!isset($gMap[$g['parent_id']])) {
                    $next[] = $g;
                    continue;
                }
                $parent = $gMap[$g['parent_id']];
                $st = $db->prepare('SELECT id FROM groups WHERE parent_id = ? AND name = ?');
                $st->execute([$parent, $g['name']]);
                $found = $st->fetchColumn();
                if ($found) {
                    $gMap[$g['id']] = (int) $found;
                } else {
                    $db->prepare("INSERT INTO groups(parent_id, name, kind, sort, paused) VALUES(?, ?, 'group', ?, ?)")
                        ->execute([$parent, mb_substr((string) $g['name'], 0, 200), (int) ($g['sort'] ?? 0), (int) ($g['paused'] ?? 0)]);
                    $gMap[$g['id']] = (int) $db->lastInsertId();
                    $stats['groups']++;
                }
            }
            $pending = $next;
        }

        // Geräte
        $dMap = [];
        $existing = [];
        foreach ($db->query('SELECT id, name, host FROM devices') as $d) {
            $existing[mb_strtolower($d['name'] . '|' . $d['host'])] = (int) $d['id'];
        }
        $depends = [];
        foreach ((array) ($x['devices'] ?? []) as $d) {
            $key = mb_strtolower(($d['name'] ?? '') . '|' . ($d['host'] ?? ''));
            if (isset($existing[$key])) {
                $dMap[$d['id']] = null;   // übersprungen → Sensoren auch
                $stats['skipped']++;
                continue;
            }
            $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes, sort, paused, creds) VALUES(?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$gMap[$d['group_id']] ?? $probe, mb_substr((string) $d['name'], 0, 200), (string) ($d['host'] ?? ''),
                    $icon((string) ($d['icon'] ?? 'server')), (string) ($d['notes'] ?? ''), (int) ($d['sort'] ?? 0), (int) ($d['paused'] ?? 0),
                    json_encode((array) ($d['creds'] ?? []), JSON_UNESCAPED_UNICODE)]);
            $dMap[$d['id']] = (int) $db->lastInsertId();
            if (!empty($d['depends_on'])) {
                $depends[$dMap[$d['id']]] = (int) $d['depends_on'];
            }
            $stats['devices']++;
        }

        // Sensoren
        $sMap = [];
        $types = sensor_types();
        foreach ((array) ($x['sensors'] ?? []) as $s) {
            $dev = $dMap[$s['device_id']] ?? null;
            if (!$dev || !isset($types[$s['type'] ?? ''])) {
                continue;
            }
            $tok = in_array($s['type'], PUSH_TYPES, true) ? ((string) ($s['push_token'] ?? '') ?: random_token()) : null;
            if ($tok) {
                $chk = $db->prepare('SELECT 1 FROM sensors WHERE push_token = ?');
                $chk->execute([$tok]);
                if ($chk->fetchColumn()) {
                    $tok = random_token();
                }
            }
            $db->prepare('INSERT INTO sensors(device_id, name, type, config, interval_s, sort, paused, warn_max, err_max, warn_min, err_min, push_token, status_since, next_check) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$dev, mb_substr((string) $s['name'], 0, 200), $s['type'], json_encode(sensor_normalize_config($s['type'], (array) ($s['config'] ?? [])), JSON_UNESCAPED_UNICODE),
                    max(10, (int) ($s['interval_s'] ?? 60)), (int) ($s['sort'] ?? 0), (int) ($s['paused'] ?? 0),
                    $s['warn_max'] ?? null, $s['err_max'] ?? null, $s['warn_min'] ?? null, $s['err_min'] ?? null, $tok, time(),
                    $tok ? time() + max(10, (int) ($s['interval_s'] ?? 60)) : 0]);
            $sMap[$s['id']] = (int) $db->lastInsertId();
            if (isset($s['retries']) && $s['retries'] !== null) {
                $db->prepare('UPDATE sensors SET retries = ? WHERE id = ?')->execute([max(1, min(10, (int) $s['retries'])), $sMap[$s['id']]]);
            }
            $stats['sensors']++;
        }
        foreach ($depends as $dev => $oldSensor) {
            if (isset($sMap[$oldSensor])) {
                $db->prepare('UPDATE devices SET depends_on = ? WHERE id = ?')->execute([$sMap[$oldSensor], $dev]);
            }
        }

        // Maps (Verknüpfungen umschreiben)
        $remap = function (?array $b) use ($gMap, $dMap, $sMap): ?array {
            if (!$b || !isset($b['type'], $b['id'])) {
                return $b;
            }
            $m = ['group' => $gMap, 'device' => $dMap, 'sensor' => $sMap][$b['type']] ?? [];
            $new = $m[$b['id']] ?? null;
            return $new ? ['type' => $b['type'], 'id' => $new] : null;
        };
        foreach ((array) ($x['maps'] ?? []) as $m) {
            $data = (array) ($m['data'] ?? []);
            foreach (['nodes', 'edges'] as $k) {
                foreach ((array) ($data[$k] ?? []) as $i => $o) {
                    if (isset($o['bind'])) {
                        $nb = $remap($o['bind']);
                        if ($nb) {
                            $data[$k][$i]['bind'] = $nb;
                        } else {
                            unset($data[$k][$i]['bind']);
                        }
                    }
                    if (isset($o['icon'])) {
                        $data[$k][$i]['icon'] = $icon((string) $o['icon']);
                    }
                }
            }
            $db->prepare('INSERT INTO maps(name, data, refresh_s, updated_at) VALUES(?, ?, ?, ?)')
                ->execute([mb_substr((string) ($m['name'] ?? 'Map'), 0, 200), json_encode($data, JSON_UNESCAPED_UNICODE), (int) ($m['refresh_s'] ?? 30), time()]);
            $stats['maps']++;
        }

        foreach ((array) ($x['notifications'] ?? []) as $n) {
            if (!isset(notification_types()[$n['type'] ?? ''])) {
                continue;
            }
            $db->prepare('INSERT INTO notifications(name, type, config, on_down, on_warning, on_up, enabled) VALUES(?,?,?,?,?,?,?)')
                ->execute([$n['name'], $n['type'], json_encode((array) ($n['config'] ?? [])), (int) ($n['on_down'] ?? 1), (int) ($n['on_warning'] ?? 0), (int) ($n['on_up'] ?? 1), (int) ($n['enabled'] ?? 1)]);
            $stats['notifications']++;
        }
        foreach ((array) ($x['settings'] ?? []) as $k => $v) {
            if (in_array($k, EXPORT_SETTINGS, true) && $k !== 'require_2fa') {
                setting_set($k, (string) $v);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $stats;
}
