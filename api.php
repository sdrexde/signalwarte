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
    $readOnlyAllowed = ['sensor_ack', 'sensor_check', 'password', 'totp_setup', 'totp_enable', 'totp_disable', 'totp_recovery_new'];
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

if (in_array($action, ['export', 'db_download'], true) && $user['role'] !== 'admin') {
    json_err('Nur für Administratoren', 403);
}

// 2FA-Pflicht: bis zur Einrichtung nur Konto-Aktionen erlauben
if (!empty($user['must_setup_2fa']) && !in_array($action, ['bootstrap', 'totp_setup', 'totp_enable', 'password'], true)) {
    json_err('Bitte zuerst die Zwei-Faktor-Anmeldung einrichten (Mein Konto).', 403);
}

/** Aktuelles Passwort des angemeldeten Benutzers prüfen. */
function confirm_password(array $user, string $pw): void
{
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    if (!password_verify($pw, (string) $st->fetchColumn())) {
        usleep(400000);
        json_err('Passwort falsch');
    }
}

try {
    switch ($action) {
        /* ------------------------------------------------------------- Lesen */
        case 'bootstrap':
            json_out([
                'ok' => true, 'user' => $user, 'types' => sensor_types(),
                'notification_types' => notification_types(),
                'heartbeat' => (int) setting('poller_heartbeat', '0'), 'now' => time(),
                'version' => APP_VERSION,
                'icons' => $db->query('SELECT id, name, data FROM icons ORDER BY name')->fetchAll(),
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
            unset($s['state'], $s['creds']);
            $s['channels'] = json_decode((string) $s['last_channels'], true) ?: $s['channels'];
            if (!is_admin()) {
                unset($s['push_token']);
            }
            json_out(['ok' => true, 'sensor' => $s]);

        case 'history':
            $id = (int) ($_GET['id'] ?? 0);
            $range = max(600, min(400 * 86400, (int) ($_GET['range'] ?? 86400)));
            $from = time() - $range;
            $points = 300;
            $step = max(1, intdiv($range, $points));
            $ch = (string) ($_GET['channel'] ?? '');
            $col = 'value';
            if ($ch !== '') {
                $col = "json_extract(extra, '$.\"' || :ch || '\"')";
            }
            $st = $db->prepare("SELECT (ts / :step) * :step AS t, AVG({$col}) AS v, MIN({$col}) AS mn, MAX({$col}) AS mx,
                    MAX(CASE status WHEN 'down' THEN 3 WHEN 'warning' THEN 2 ELSE 1 END) AS s
                FROM results WHERE sensor_id = :id AND ts >= :from GROUP BY t ORDER BY t");
            $args = [':step' => $step, ':id' => $id, ':from' => $from];
            if ($ch !== '') {
                $args[':ch'] = str_replace('"', '', $ch);
            }
            $st->execute($args);
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
            json_out(['ok' => true, 'users' => $db->query('SELECT id, username, role, created_at, totp_enabled, last_login FROM users ORDER BY username')->fetchAll()]);

        case 'settings':
            json_out(['ok' => true, 'settings' => [
                'retention_days' => (int) setting('retention_days', '30'),
                'poller_workers' => (int) setting('poller_workers', '8'),
                'require_2fa' => setting('require_2fa', '0') === '1',
                'scan_exclude' => (string) setting('scan_exclude', ''),
                'session_idle_min' => (int) setting('session_idle_min', '480'),
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
            $old = [];
            if ($id) {
                $q = $db->prepare('SELECT creds FROM devices WHERE id = ?');
                $q->execute([$id]);
                $old = json_decode((string) $q->fetchColumn(), true) ?: [];
            }
            $ci = (array) ($in['creds'] ?? []);
            $creds = [
                'snmp_community' => in_str($ci, 'snmp_community', 100),
                'snmp_version'   => (int) ($ci['snmp_version'] ?? 2) === 1 ? 1 : 2,
                'snmp_port'      => max(1, min(65535, (int) ($ci['snmp_port'] ?? 161) ?: 161)),
                'username'       => in_str($ci, 'username', 100),
                'password'       => ($ci['password'] ?? '') !== '' ? (string) $ci['password'] : (string) ($old['password'] ?? ''),
                'web_url'        => in_str($ci, 'web_url', 300),
            ];
            if (!empty($ci['clear_password'])) {
                $creds['password'] = '';
            }
            if ($creds['web_url'] !== '' && !preg_match('#^https?://#i', $creds['web_url'])) {
                json_err('Weboberfläche: URL muss mit http:// oder https:// beginnen');
            }
            $fields = [in_int($in, 'group_id') ?? 2, $name, $host, in_str($in, 'icon', 40) ?: 'server', in_str($in, 'notes', 4000), in_int($in, 'depends_on'), json_encode($creds, JSON_UNESCAPED_UNICODE)];
            if ($id) {
                $db->prepare('UPDATE devices SET group_id = ?, name = ?, host = ?, icon = ?, notes = ?, depends_on = ?, creds = ? WHERE id = ?')->execute([...$fields, $id]);
            } else {
                $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes, depends_on, creds) VALUES(?, ?, ?, ?, ?, ?, ?)')->execute($fields);
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
            $db->prepare('INSERT INTO devices(group_id, name, host, icon, notes, creds) VALUES(?, ?, ?, ?, ?, ?)')
                ->execute([$d['group_id'], $d['name'] . ' (Kopie)', $in['host'] ?? $d['host'], $d['icon'], $d['notes'], $d['creds']]);
            $nid = (int) $db->lastInsertId();
            $ss = $db->prepare('SELECT * FROM sensors WHERE device_id = ?');
            $ss->execute([$d['id']]);
            foreach ($ss->fetchAll() as $s) {
                $db->prepare('INSERT INTO sensors(device_id, name, type, config, interval_s, warn_max, err_max, warn_min, err_min, push_token) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$nid, $s['name'], $s['type'], $s['config'], $s['interval_s'], $s['warn_max'], $s['err_max'], $s['warn_min'], $s['err_min'],
                        in_array($s['type'], PUSH_TYPES, true) ? random_token() : null]);
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
            json_out(['ok' => true] + discover_host($host));

        case 'map_save':
            $id = in_int($in, 'id');
            $name = require_name(in_str($in, 'name'));
            $data = json_encode($in['data'] ?? ['nodes' => [], 'edges' => []], JSON_UNESCAPED_UNICODE);
            if (strlen($data) > 12_000_000) {
                json_err('Map zu groß (max. 12 MB inkl. Hintergrundbild)');
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
            if (isset($in['session_idle_min'])) {
                setting_set('session_idle_min', (string) max(5, min(10080, (int) $in['session_idle_min'])));
            }
            if (isset($in['scan_exclude'])) {
                require_once __DIR__ . '/lib/scanner.php';
                $ex = trim((string) $in['scan_exclude']);
                scan_parse_ranges($ex);   // wirft bei Fehlern
                setting_set('scan_exclude', mb_substr($ex, 0, 4000));
            }
            if (isset($in['require_2fa'])) {
                $req = filter_var($in['require_2fa'], FILTER_VALIDATE_BOOLEAN);
                if ($req && !$user['totp_enabled']) {
                    json_err('Richte zuerst für dein eigenes Konto 2FA ein, bevor du sie für alle vorschreibst.');
                }
                setting_set('require_2fa', $req ? '1' : '0');
                log_event('user', '2FA-Pflicht ' . ($req ? 'aktiviert' : 'deaktiviert') . " von {$user['username']}");
            }
            json_out(['ok' => true]);

        case 'snmp_interfaces':
            require_once __DIR__ . '/lib/snmp.php';
            $st = $db->prepare('SELECT host, creds FROM devices WHERE id = ?');
            $st->execute([(int) ($in['device_id'] ?? 0)]);
            $d = $st->fetch() ?: json_err('Gerät nicht gefunden', 404);
            try {
                $c = snmp_for($d['host'], ['community' => (string) ($in['community'] ?? '')], ['creds' => $d['creds']]);
                json_out(['ok' => true, 'interfaces' => array_values(snmp_interfaces($c))]);
            } catch (Throwable $e) {
                json_err($e->getMessage());
            }

        case 'scan':
            require_once __DIR__ . '/lib/scanner.php';
            @set_time_limit(300);
            session_write_close();
            $targets = scan_parse_targets((string) ($in['targets'] ?? ''));
            $skipped = 0;
            if (empty($in['include_excluded'])) {
                $ranges = scan_parse_ranges((string) setting('scan_exclude', ''));
                $before = count($targets);
                $targets = array_values(array_filter($targets, fn($ip) => !ip_in_ranges($ip, $ranges)));
                $skipped = $before - count($targets);
            }
            if (!$targets) {
                json_out(['ok' => true, 'hosts' => [], 'scanned' => 0, 'skipped' => $skipped]);
            }
            $existing = array_column($db->query("SELECT host, name FROM devices WHERE host != ''")->fetchAll(), 'name', 'host');
            $res = scan_network($targets, (array) ($in['creds'] ?? []), !empty($in['deep']));
            foreach ($res as &$r) {
                $r['exists'] = $existing[$r['ip']] ?? ($r['hostname'] !== '' ? ($existing[$r['hostname']] ?? null) : null);
            }
            json_out(['ok' => true, 'hosts' => $res, 'scanned' => count($targets), 'skipped' => $skipped]);

        case 'icon_save':
            $data = (string) ($in['data'] ?? '');
            if (!preg_match('#^data:image/(svg\+xml|png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $data)) {
                json_err('Nur SVG, PNG, JPG, WebP oder GIF');
            }
            if (strlen($data) > 400_000) {
                json_err('Icon zu groß (max. ca. 300 KB)');
            }
            if (str_starts_with($data, 'data:image/svg')) {
                $svg = base64_decode(substr($data, strpos($data, ',') + 1));
                if (preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject/i', $svg)) {
                    json_err('SVG enthält Skripte – bitte bereinigte Datei verwenden');
                }
            }
            $db->prepare('INSERT INTO icons(name, data, created_at) VALUES(?, ?, ?)')->execute([require_name(in_str($in, 'name', 60)), $data, time()]);
            json_out(['ok' => true, 'id' => (int) $db->lastInsertId()]);

        case 'icon_delete':
            $db->prepare('DELETE FROM icons WHERE id = ?')->execute([(int) $in['id']]);
            $db->prepare("UPDATE devices SET icon = 'server' WHERE icon = ?")->execute(['c' . (int) $in['id']]);
            json_out(['ok' => true]);

        case 'totp_setup':
            require_once __DIR__ . '/lib/totp.php';
            if ($user['totp_enabled']) {
                json_err('2FA ist bereits aktiv');
            }
            $_SESSION['totp_pending'] = totp_new_secret();
            $issuer = APP_NAME . ' (' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'local') . ')';
            json_out(['ok' => true, 'secret' => $_SESSION['totp_pending'], 'uri' => totp_uri($_SESSION['totp_pending'], $user['username'], $issuer)]);

        case 'totp_enable':
            require_once __DIR__ . '/lib/totp.php';
            $secret = (string) ($_SESSION['totp_pending'] ?? '');
            if ($secret === '') {
                json_err('Einrichtung abgelaufen – bitte neu starten');
            }
            $step = totp_verify($secret, (string) ($in['code'] ?? ''));
            if ($step === null) {
                json_err('Code stimmt nicht. Uhrzeit von Handy/Server prüfen und den aktuellen Code eingeben.');
            }
            [$plain, $hashes] = recovery_codes_new();
            $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last = ?, recovery_codes = ? WHERE id = ?')
                ->execute([$secret, $step, json_encode($hashes), $user['id']]);
            unset($_SESSION['totp_pending']);
            log_event('user', "2FA aktiviert: {$user['username']}");
            json_out(['ok' => true, 'recovery_codes' => $plain]);

        case 'totp_disable':
            if (setting('require_2fa', '0') === '1') {
                json_err('2FA ist für alle Benutzer vorgeschrieben und kann nicht abgeschaltet werden.');
            }
            confirm_password($user, (string) ($in['password'] ?? ''));
            $st = $db->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$user['id']]);
            if (!verify_second_factor($st->fetch(), (string) ($in['code'] ?? ''))) {
                json_err('2FA-Code ungültig');
            }
            $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last = NULL, recovery_codes = NULL WHERE id = ?')->execute([$user['id']]);
            log_event('user', "2FA deaktiviert: {$user['username']}");
            json_out(['ok' => true]);

        case 'totp_recovery_new':
            confirm_password($user, (string) ($in['password'] ?? ''));
            if (!$user['totp_enabled']) {
                json_err('2FA ist nicht aktiv');
            }
            require_once __DIR__ . '/lib/totp.php';
            [$plain, $hashes] = recovery_codes_new();
            $db->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([json_encode($hashes), $user['id']]);
            log_event('user', "Neue Wiederherstellungscodes: {$user['username']}");
            json_out(['ok' => true, 'recovery_codes' => $plain]);

        case 'user_2fa_reset':
            $id = (int) ($in['id'] ?? 0);
            if ($id === (int) $user['id']) {
                json_err('Eigene 2FA bitte unter „Mein Konto“ verwalten');
            }
            $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last = NULL, recovery_codes = NULL WHERE id = ?')->execute([$id]);
            log_event('user', "2FA zurückgesetzt für Benutzer #{$id} von {$user['username']}");
            json_out(['ok' => true]);

        case 'excluded_devices':
            require_once __DIR__ . '/lib/scanner.php';
            $ranges = scan_parse_ranges((string) setting('scan_exclude', ''));
            $out = [];
            foreach ($db->query('SELECT id, name, host FROM devices') as $d) {
                if (filter_var($d['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ip_in_ranges($d['host'], $ranges)) {
                    $out[] = $d;
                }
            }
            json_out(['ok' => true, 'devices' => $out]);

        case 'devices_delete':
            $ids = array_map('intval', (array) ($in['ids'] ?? []));
            $st = $db->prepare('DELETE FROM devices WHERE id = ?');
            foreach ($ids as $i) {
                $st->execute([$i]);
            }
            log_event('user', count($ids) . " Geräte gelöscht von {$user['username']}");
            json_out(['ok' => true, 'deleted' => count($ids)]);

        case 'export':
            require_once __DIR__ . '/lib/transfer.php';
            $secrets = !empty($_GET['secrets']);
            log_event('user', 'Export ' . ($secrets ? 'mit' : 'ohne') . " Zugangsdaten von {$user['username']}");
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="reload-monitor-export-' . date('Ymd-His') . '.json"');
            header('Cache-Control: no-store');
            echo json_encode(export_all($secrets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;

        case 'import':
            require_once __DIR__ . '/lib/transfer.php';
            if (!is_array($in['data'] ?? null)) {
                json_err('Keine Daten');
            }
            $mode = ($in['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
            if ($mode === 'replace' && ($in['confirm'] ?? '') !== 'ERSETZEN') {
                json_err('Zum Ersetzen bitte ERSETZEN bestätigen');
            }
            $stats = import_all($in['data'], $mode);
            log_event('user', "Import ({$mode}) von {$user['username']}: " . json_encode($stats));
            json_out(['ok' => true, 'stats' => $stats]);

        case 'db_download':
            // vollständige Datenbank inkl. Messwerte und Benutzer (konsistent per VACUUM INTO)
            $tmp = tempnam(sys_get_temp_dir(), 'rmdb');
            unlink($tmp);
            $db->exec('VACUUM INTO ' . $db->quote($tmp));
            log_event('user', "Datenbank-Download von {$user['username']}");
            header('Content-Type: application/vnd.sqlite3');
            header('Content-Disposition: attachment; filename="reload-monitor-' . date('Ymd-His') . '.sqlite"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store');
            readfile($tmp);
            unlink($tmp);
            exit;

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
            in_array($type, PUSH_TYPES, true) ? random_token() : null,
            in_array($type, PUSH_TYPES, true) ? time() + $iv : 0]);
    return (int) db()->lastInsertId();
}

/** Auto-Discovery für ein Gerät: nutzt den Netzwerkscanner (Ports, HTTP-Titel, Gerätetyp). */
function discover_host(string $host): array
{
    require_once __DIR__ . '/lib/scanner.php';
    $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        json_err('Host nicht auflösbar (IPv4)');
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
