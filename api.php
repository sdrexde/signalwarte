<?php
// JSON-API für die Weboberfläche (Session + CSRF).
declare(strict_types=1);

require_once __DIR__ . '/lib/engine.php';

session_boot();
$user = current_user();
if (!$user) {
    json_err('Nicht angemeldet', 401);
}
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$in = [];
if ($method === 'POST') {
    if (!csrf_ok($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_err('CSRF-Token ungültig – Seite neu laden', 403);
    }
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $readOnlyAllowed = ['sensor_ack', 'sensor_check', 'password'];
    if ($user['role'] !== 'admin' && !in_array($action, $readOnlyAllowed, true)) {
        json_err('Nur für Administratoren', 403);
    }
}

function in_int(array $in, string $k): ?int
{
    return isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? (int) $in[$k] : null;
}

function in_num(array $in, string $k): ?float
{
    return isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null && is_numeric($in[$k]) ? (float) $in[$k] : null;
}

function in_str(array $in, string $k, int $max = 200): string
{
    return mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
}

function require_name(string $n): string
{
    if ($n === '') {
        json_err('Name darf nicht leer sein');
    }
    return $n;
}

function group_is_descendant(int $id, int $ancestor): bool
{
    $st = db()->prepare('SELECT parent_id FROM groups WHERE id = ?');
    $guard = 0;
    while ($id && $guard++ < 100) {
        if ($id === $ancestor) {
            return true;
        }
        $st->execute([$id]);
        $id = (int) $st->fetchColumn();
    }
    return false;
}

$db = db();

try {
    switch ($action) {
        /* ------------------------------------------------------------- Lesen */
        case 'bootstrap':
            json_out([
                'ok' => true, 'user' => $user, 'types' => sensor_types(),
                'notification_types' => notification_types(),
                'heartbeat' => (int) setting('poller_heartbeat', '0'), 'now' => time(),
                'version' => APP_VERSION,
            ]);

        case 'tree':
            $t = build_tree();
            json_out(['ok' => true, 'tree' => $t['root'], 'counts' => $t['counts'],
                'heartbeat' => (int) setting('poller_heartbeat', '0'), 'now' => time()]);

        case 'status':
            $t = build_tree();
            json_out(['ok' => true, 'index' => status_index(), 'counts' => $t['counts'], 'now' => time()]);

        case 'sensor':
            $s = sensor_row((int) ($_GET['id'] ?? 0)) ?? json_err('Sensor nicht gefunden', 404);
            $s['config'] = json_decode($s['config'], true);
            $s['effective'] = effective_status($s, paused_group_ids());
            $last = $db->prepare('SELECT extra FROM results WHERE sensor_id = ? ORDER BY ts DESC LIMIT 1');
            $last->execute([$s['id']]);
            $s['channels'] = json_decode((string) $last->fetchColumn(), true) ?: [];
            $ev = $db->prepare("SELECT * FROM events WHERE sensor_id = ? ORDER BY id DESC LIMIT 30");
            $ev->execute([$s['id']]);
            $s['events'] = $ev->fetchAll();
            $up = $db->prepare("SELECT COUNT(*) AS n, SUM(status IN ('up','warning')) AS ok, AVG(value) AS avg, MIN(value) AS min, MAX(value) AS max FROM results WHERE sensor_id = ? AND ts > ?");
            $up->execute([$s['id'], time() - 86400]);
            $s['stats24'] = $up->fetch();
            if ($s['type'] === 'push') {
                $s['push_url'] = $s['push_token'];
            }
            json_out(['ok' => true, 'sensor' => $s]);

        case 'history':
            $id = (int) ($_GET['id'] ?? 0);
            $range = max(600, min(400 * 86400, (int) ($_GET['range'] ?? 86400)));
            $from = time() - $range;
            $points = 300;
            $step = max(1, intdiv($range, $points));
            $st = $db->prepare("SELECT (ts / :step) * :step AS t, AVG(value) AS v, MIN(value) AS mn, MAX(value) AS mx,
                    MAX(CASE status WHEN 'down' THEN 3 WHEN 'warning' THEN 2 ELSE 1 END) AS s
                FROM results WHERE sensor_id = :id AND ts >= :from GROUP BY t ORDER BY t");
            $st->execute([':step' => $step, ':id' => $id, ':from' => $from]);
            $rows = array_map(fn($r) => [(int) $r['t'], $r['v'] !== null ? round((float) $r['v'], 3) : null,
                $r['mn'] !== null ? (float) $r['mn'] : null, $r['mx'] !== null ? (float) $r['mx'] : null, (int) $r['s']], $st->fetchAll());
            json_out(['ok' => true, 'points' => $rows, 'step' => $step]);

        case 'events':
            $lim = max(10, min(1000, (int) ($_GET['limit'] ?? 200)));
            $where = '1=1';
            $args = [];
            if (!empty($_GET['type'])) {
                $where .= ' AND e.type = ?';
                $args[] = $_GET['type'];
            }
            if (!empty($_GET['q'])) {
                $where .= ' AND e.message LIKE ?';
                $args[] = '%' . $_GET['q'] . '%';
            }
            $st = $db->prepare("SELECT e.* FROM events e WHERE {$where} ORDER BY e.id DESC LIMIT {$lim}");
            $st->execute($args);
            json_out(['ok' => true, 'events' => $st->fetchAll()]);

        case 'maps':
            $rows = $db->query('SELECT id, name, public_token, refresh_s, updated_at, length(data) AS size FROM maps ORDER BY name')->fetchAll();
            json_out(['ok' => true, 'maps' => $rows]);

        case 'map':
            $st = $db->prepare('SELECT * FROM maps WHERE id = ?');
            $st->execute([(int) ($_GET['id'] ?? 0)]);
            $m = $st->fetch() ?: json_err('Map nicht gefunden', 404);
            $m['data'] = json_decode($m['data'], true);
            json_out(['ok' => true, 'map' => $m]);

        case 'notifications':
            $rows = $db->query('SELECT * FROM notifications ORDER BY name')->fetchAll();
            foreach ($rows as &$r) {
                $r['config'] = json_decode($r['config'], true);
            }
            json_out(['ok' => true, 'notifications' => $rows]);

        case 'users':
            json_out(['ok' => true, 'users' => $db->query('SELECT id, username, role, created_at FROM users ORDER BY username')->fetchAll()]);

        case 'settings':
            json_out(['ok' => true, 'settings' => [
                'retention_days' => (int) setting('retention_days', '30'),
                'poller_workers' => (int) setting('poller_workers', '8'),
            ]]);

        /* ------------------------------------------------------- Schreiben */
        case 'group_save':
            $id = in_int($in, 'id');
            $name = require_name(in_str($in, 'name'));
            $parent = in_int($in, 'parent_id') ?? 2;
            if ($id) {
                if ($id === 1) {
                    json_err('Root kann nicht verändert werden');
                }
                if ($id !== 1 && group_is_descendant($parent, $id)) {
                    json_err('Gruppe kann nicht in sich selbst verschoben werden');
                }
                $db->prepare('UPDATE groups SET name = ?, parent_id = ? WHERE id = ?')->execute([$name, $parent, $id]);
            } else {
                $db->prepare("INSERT INTO groups(parent_id, name, kind) VALUES(?, ?, 'group')")->execute([$parent, $name]);
                $id = (int) $db->lastInsertId();
            }
            json_out(['ok' => true, 'id' => $id]);

        case 'group_delete':
            $id = (int) ($in['id'] ?? 0);
            if ($id <= 2) {
                json_err('Root und lokale Probe können nicht gelöscht werden');
            }
            $db->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);

        case 'group_toggle':
            $db->prepare('UPDATE groups SET collapsed = ? WHERE id = ?')->execute([(int) !empty($in['collapsed']), (int) $in['id']]);
            json_out(['ok' => true]);

        case 'device_save':
            $id = in_int($in, 'id');
            $name = require_name(in_str($in, 'name'));
            $host = in_str($in, 'host', 255);
            if ($host !== '' && !valid_host($host)) {
                json_err('Ungültiger Host / IP');
            }
            $fields = [$in_group = in_int($in, 'group_id') ?? 2, $name, $host, in_str($in, 'icon', 40) ?: 'server', in_str($in, 'notes', 4000), in_int($in, 'depends_on')];
            if ($id) {
                $db->prepare('UPDATE devices SET group_id = ?, name = ?, host = ?, icon = ?, notes = ?, depends_on = ? WHERE id = ?')->execute([...$fields, $id]);
            } else {
                $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes, depends_on) VALUES(?, ?, ?, ?, ?, ?)')->execute($fields);
                $id = (int) $db->lastInsertId();
                log_event('user', "Gerät angelegt: {$name} ({$host})", null, $id);
            }
            // Optional direkt Sensoren anlegen (Auto-Discovery / Vorlagen)
            foreach ((array) ($in['add_sensors'] ?? []) as $ns) {
                $type = (string) ($ns['type'] ?? '');
                if (!isset(sensor_types()[$type])) {
                    continue;
                }
                create_sensor($id, $type, (string) ($ns['name'] ?? ''), (array) ($ns['config'] ?? []));
            }
            json_out(['ok' => true, 'id' => $id]);

        case 'device_delete':
            $db->prepare('DELETE FROM devices WHERE id = ?')->execute([(int) $in['id']]);
            json_out(['ok' => true]);

        case 'device_move':
            $db->prepare('UPDATE devices SET group_id = ? WHERE id = ?')->execute([(int) $in['group_id'], (int) $in['id']]);
            json_out(['ok' => true]);

        case 'device_clone':
            $st = $db->prepare('SELECT * FROM devices WHERE id = ?');
            $st->execute([(int) $in['id']]);
            $d = $st->fetch() ?: json_err('Gerät nicht gefunden', 404);
            $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes) VALUES(?, ?, ?, ?, ?)')
                ->execute([$d['group_id'], $d['name'] . ' (Kopie)', $in['host'] ?? $d['host'], $d['icon'], $d['notes']]);
            $nid = (int) $db->lastInsertId();
            $ss = $db->prepare('SELECT * FROM sensors WHERE device_id = ?');
            $ss->execute([$d['id']]);
            foreach ($ss->fetchAll() as $s) {
                $db->prepare('INSERT INTO sensors(device_id, name, type, config, interval_s, warn_max, err_max, warn_min, err_min, push_token) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$nid, $s['name'], $s['type'], $s['config'], $s['interval_s'], $s['warn_max'], $s['err_max'], $s['warn_min'], $s['err_min'],
                        $s['type'] === 'push' ? random_token() : null]);
            }
            json_out(['ok' => true, 'id' => $nid]);

        case 'sensor_save':
            $id = in_int($in, 'id');
            $type = (string) ($in['type'] ?? '');
            $types = sensor_types();
            if (!$id && !isset($types[$type])) {
                json_err('Unbekannter Sensortyp');
            }
            if ($id) {
                $cur = sensor_row($id) ?? json_err('Sensor nicht gefunden', 404);
                $type = $cur['type'];
                $cfg = sensor_normalize_config($type, (array) ($in['config'] ?? []));
                $db->prepare('UPDATE sensors SET name = ?, config = ?, interval_s = ?, warn_max = ?, err_max = ?, warn_min = ?, err_min = ?, next_check = 0 WHERE id = ?')
                    ->execute([require_name(in_str($in, 'name')), json_encode($cfg, JSON_UNESCAPED_UNICODE),
                        max(10, (int) ($in['interval_s'] ?? 60)), in_num($in, 'warn_max'), in_num($in, 'err_max'), in_num($in, 'warn_min'), in_num($in, 'err_min'), $id]);
            } else {
                $id = create_sensor((int) $in['device_id'], $type, in_str($in, 'name'), (array) ($in['config'] ?? []), $in);
            }
            json_out(['ok' => true, 'id' => $id]);

        case 'sensor_delete':
            $db->prepare('DELETE FROM sensors WHERE id = ?')->execute([(int) $in['id']]);
            json_out(['ok' => true]);

        case 'pause':
            $obj = (string) ($in['object'] ?? '');
            $table = ['group' => 'groups', 'device' => 'devices', 'sensor' => 'sensors'][$obj] ?? json_err('Objekttyp ungültig');
            $p = (int) !empty($in['paused']);
            $db->prepare("UPDATE {$table} SET paused = ? WHERE id = ?")->execute([$p, (int) $in['id']]);
            if ($obj === 'sensor' && !$p) {
                $db->prepare("UPDATE sensors SET next_check = 0, status = 'unknown', status_since = ? WHERE id = ?")->execute([time(), (int) $in['id']]);
            }
            log_event('user', ($p ? 'Pausiert' : 'Fortgesetzt') . ": {$obj} #{$in['id']} von {$user['username']}");
            json_out(['ok' => true]);

        case 'sensor_check':
            $s = sensor_row((int) $in['id']) ?? json_err('Sensor nicht gefunden', 404);
            $r = sensor_run($s);
            json_out(['ok' => true, 'result' => $r]);

        case 'sensor_ack':
            $msg = in_str($in, 'message', 300) ?: 'bestätigt';
            $db->prepare("UPDATE sensors SET acked = 1, ack_msg = ? WHERE id = ? AND status = 'down'")
                ->execute(["{$user['username']}: {$msg}", (int) $in['id']]);
            log_event('user', "Alarm bestätigt von {$user['username']}: {$msg}", (int) $in['id']);
            json_out(['ok' => true]);

        case 'discover':
            $host = in_str($in, 'host', 255);
            if (!valid_host($host)) {
                json_err('Ungültiger Host');
            }
            json_out(['ok' => true, 'found' => discover_host($host)]);

        case 'map_save':
            $id = in_int($in, 'id');
            $name = require_name(in_str($in, 'name'));
            $data = json_encode($in['data'] ?? ['nodes' => [], 'edges' => []], JSON_UNESCAPED_UNICODE);
            if (strlen($data) > 4_000_000) {
                json_err('Map zu groß (max. 4 MB)');
            }
            $refresh = max(5, min(3600, (int) ($in['refresh_s'] ?? 30)));
            if ($id) {
                $db->prepare('UPDATE maps SET name = ?, data = ?, refresh_s = ?, updated_at = ? WHERE id = ?')->execute([$name, $data, $refresh, time(), $id]);
            } else {
                $db->prepare('INSERT INTO maps(name, data, refresh_s, updated_at) VALUES(?, ?, ?, ?)')->execute([$name, $data, $refresh, time()]);
                $id = (int) $db->lastInsertId();
            }
            json_out(['ok' => true, 'id' => $id]);

        case 'map_delete':
            $db->prepare('DELETE FROM maps WHERE id = ?')->execute([(int) $in['id']]);
            json_out(['ok' => true]);

        case 'map_share':
            $tok = !empty($in['enable']) ? random_token(24) : null;
            $db->prepare('UPDATE maps SET public_token = ? WHERE id = ?')->execute([$tok, (int) $in['id']]);
            json_out(['ok' => true, 'token' => $tok]);

        case 'notification_save':
            $type = (string) ($in['type'] ?? '');
            $nt = notification_types()[$type] ?? json_err('Unbekannter Typ');
            $cfg = [];
            foreach ($nt['fields'] as $f) {
                $cfg[$f['key']] = in_str((array) ($in['config'] ?? []), $f['key'], 500);
            }
            $vals = [require_name(in_str($in, 'name')), $type, json_encode($cfg), (int) !empty($in['on_down']), (int) !empty($in['on_warning']), (int) !empty($in['on_up']), (int) !empty($in['enabled'])];
            if ($id = in_int($in, 'id')) {
                $db->prepare('UPDATE notifications SET name=?, type=?, config=?, on_down=?, on_warning=?, on_up=?, enabled=? WHERE id=?')->execute([...$vals, $id]);
            } else {
                $db->prepare('INSERT INTO notifications(name, type, config, on_down, on_warning, on_up, enabled) VALUES(?,?,?,?,?,?,?)')->execute($vals);
            }
            json_out(['ok' => true]);

        case 'notification_delete':
            $db->prepare('DELETE FROM notifications WHERE id = ?')->execute([(int) $in['id']]);
            json_out(['ok' => true]);

        case 'notification_test':
            $st = $db->prepare('SELECT * FROM notifications WHERE id = ?');
            $st->execute([(int) $in['id']]);
            $n = $st->fetch() ?: json_err('Nicht gefunden', 404);
            $ok = notify_send($n, '🧪 Testnachricht von ' . APP_NAME, 'Wenn du das liest, funktioniert die Benachrichtigung.',
                ['event' => 'test', 'status' => 'up', 'message' => 'Test', 'time' => date('c')]);
            json_out(['ok' => $ok, 'error' => $ok ? null : 'Versand fehlgeschlagen – Konfiguration prüfen']);

        case 'user_save':
            $username = require_name(in_str($in, 'username', 60));
            $role = ($in['role'] ?? '') === 'viewer' ? 'viewer' : 'admin';
            $pw = (string) ($in['password'] ?? '');
            if ($id = in_int($in, 'id')) {
                $db->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?')->execute([$username, $role, $id]);
                if ($pw !== '') {
                    if (strlen($pw) < 8) {
                        json_err('Passwort: mindestens 8 Zeichen');
                    }
                    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                }
            } else {
                if (strlen($pw) < 8) {
                    json_err('Passwort: mindestens 8 Zeichen');
                }
                $db->prepare('INSERT INTO users(username, password_hash, role, created_at) VALUES(?,?,?,?)')
                    ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role, time()]);
            }
            json_out(['ok' => true]);

        case 'user_delete':
            if ((int) $in['id'] === (int) $user['id']) {
                json_err('Du kannst dich nicht selbst löschen');
            }
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $in['id']]);
            json_out(['ok' => true]);

        case 'password':
            $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $st->execute([$user['id']]);
            if (!password_verify((string) ($in['old'] ?? ''), (string) $st->fetchColumn())) {
                json_err('Altes Passwort falsch');
            }
            if (strlen((string) ($in['new'] ?? '')) < 8) {
                json_err('Neues Passwort: mindestens 8 Zeichen');
            }
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $in['new'], PASSWORD_DEFAULT), $user['id']]);
            json_out(['ok' => true]);

        case 'settings_save':
            setting_set('retention_days', (string) max(1, min(400, (int) ($in['retention_days'] ?? 30))));
            setting_set('poller_workers', (string) max(1, min(32, (int) ($in['poller_workers'] ?? 8))));
            json_out(['ok' => true]);

        default:
            json_err('Unbekannte Aktion', 404);
    }
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        json_err('Name bereits vergeben');
    }
    json_err('Datenbankfehler: ' . $e->getMessage(), 500);
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
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
            $type === 'push' ? random_token() : null,
            $type === 'push' ? time() + $iv : 0]);
    return (int) db()->lastInsertId();
}

/** Mini-Auto-Discovery: Ping + gängige Ports prüfen und passende Sensoren vorschlagen. */
function discover_host(string $host): array
{
    $found = [];
    $p = check_ping($host, ['count' => 2, 'timeout' => 1]);
    if ($p['status'] !== 'down') {
        $found[] = ['type' => 'ping', 'name' => 'Ping', 'config' => [], 'info' => $p['message']];
    }
    $ports = [21 => 'FTP', 22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP', 443 => 'HTTPS',
        445 => 'SMB', 587 => 'SMTP Submission', 993 => 'IMAPS', 1433 => 'MS SQL', 3306 => 'MySQL', 3389 => 'RDP',
        5432 => 'PostgreSQL', 5900 => 'VNC', 8006 => 'Proxmox', 8080 => 'HTTP-Alt', 8443 => 'HTTPS-Alt', 9100 => 'Drucker (RAW)'];
    $target = str_contains($host, ':') ? "[{$host}]" : $host;
    $socks = [];
    foreach ($ports as $port => $label) {
        $s = @stream_socket_client("tcp://{$target}:{$port}", $e, $es, 1.5, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
        if ($s) {
            $socks[$port] = $s;
        }
    }
    $deadline = microtime(true) + 1.6;
    $open = [];
    while ($socks && microtime(true) < $deadline) {
        $w = $socks;
        $r = $ex = null;
        if (@stream_select($r, $w, $ex, 0, 200000) > 0) {
            foreach ($w as $s) {
                $port = array_search($s, $socks, true);
                if (@stream_socket_get_name($s, true) !== false) {
                    $open[] = $port;
                }
                fclose($s);
                unset($socks[$port]);
            }
        }
    }
    foreach ($socks as $s) {
        fclose($s);
    }
    sort($open);
    foreach ($open as $port) {
        $found[] = ['type' => 'port', 'name' => "Port {$port} ({$ports[$port]})", 'config' => ['port' => $port], 'info' => 'offen'];
        if (in_array($port, [80, 8080], true)) {
            $found[] = ['type' => 'http', 'name' => "HTTP :{$port}", 'config' => ['url' => "http://{$host}:{$port}/"], 'info' => 'Webserver'];
        }
        if (in_array($port, [443, 8443, 8006], true)) {
            $found[] = ['type' => 'http', 'name' => "HTTPS :{$port}", 'config' => ['url' => "https://{$host}:{$port}/", 'verify_ssl' => false], 'info' => 'Webserver (TLS)'];
            $found[] = ['type' => 'ssl', 'name' => "SSL-Zertifikat :{$port}", 'config' => ['port' => $port], 'info' => 'Zertifikatslaufzeit'];
        }
    }
    return $found;
}
