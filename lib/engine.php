<?php
// Monitoring-Engine: Baum/Status berechnen, Ergebnisse speichern, Statuswechsel, Benachrichtigungen.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sensors.php';
require_once __DIR__ . '/notify.php';

/** Effektiv pausierte Gruppen-IDs (Pausierung erbt nach unten). */
function paused_group_ids(): array
{
    $rows = db()->query('SELECT id, parent_id, paused FROM groups')->fetchAll();
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['id']] = $r;
    }
    $res = [];
    foreach ($by as $id => $r) {
        $cur = $r;
        $guard = 0;
        while ($cur && $guard++ < 100) {
            if ((int) $cur['paused']) {
                $res[$id] = true;
                break;
            }
            $cur = $cur['parent_id'] ? ($by[(int) $cur['parent_id']] ?? null) : null;
        }
    }
    return $res;
}

/** Status für Anzeige: berücksichtigt Pause und Bestätigung. */
function effective_status(array $s, array $pausedGroups = []): string
{
    if ((int) $s['paused'] || (int) ($s['device_paused'] ?? 0) || isset($pausedGroups[(int) ($s['group_id'] ?? 0)])) {
        return 'paused';
    }
    if ($s['status'] === 'down' && (int) $s['acked']) {
        return 'acked';
    }
    return $s['status'];
}

const PUSH_TYPES = ['push', 'agent'];

const SENSOR_SELECT = 'SELECT s.*, d.host, d.name AS device_name, d.paused AS device_paused, d.group_id, d.depends_on, d.creds
    FROM sensors s JOIN devices d ON d.id = s.device_id';

function sensor_row(int $id): ?array
{
    $st = db()->prepare(SENSOR_SELECT . ' WHERE s.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Persistiert ein Prüfergebnis inkl. Retry-Logik (erster Fehlschlag => Warnung, zweiter => Fehler)
 * und löst bei Statuswechsel Ereignis + Benachrichtigung aus.
 */
function sensor_store_result(array $s, array $r, bool $fromLimits = true): void
{
    $now = time();
    $raw = $r['status'];
    if ($fromLimits) {
        $r = apply_limits($r, $s);
    }
    $fail = (int) $s['fail_count'];
    $next = $now + max(10, (int) $s['interval_s']);

    if ($raw === 'down') {
        $fail++;
        // Wie bei PRTG: erster Fehlschlag => Warnung + schneller Neuversuch.
        if ($fail === 1 && $s['status'] !== 'down' && !in_array($s['type'], PUSH_TYPES, true)) {
            $r['status'] = 'warning';
            $r['message'] = 'Neuer Versuch … ' . $r['message'];
            $next = $now + min(20, max(10, (int) $s['interval_s']));
        }
    } else {
        $fail = 0;
    }

    db()->prepare('INSERT INTO results(sensor_id, ts, status, value, extra) VALUES(?, ?, ?, ?, ?)')
        ->execute([$s['id'], $now, $r['status'], $r['value'], $r['extra'] ? json_encode($r['extra'], JSON_UNESCAPED_UNICODE) : null]);

    $changed = $r['status'] !== $s['status'];
    $sql = 'UPDATE sensors SET status = ?, fail_count = ?, last_value = ?, last_unit = ?, last_message = ?, last_check = ?, next_check = ?, last_channels = ?';
    $args = [$r['status'], $fail, $r['value'], $r['unit'], mb_substr((string) $r['message'], 0, 500), $now, $next,
        $r['extra'] ? json_encode($r['extra'], JSON_UNESCAPED_UNICODE) : null];
    if (array_key_exists('state', $r)) {
        $sql .= ', state = ?';
        $args[] = $r['state'] === null ? null : json_encode($r['state']);
    }
    if ($changed) {
        $sql .= ', status_since = ?, acked = 0, ack_msg = NULL';
        $args[] = $now;
    }
    $sql .= ' WHERE id = ?';
    $args[] = $s['id'];
    db()->prepare($sql)->execute($args);

    if ($changed) {
        $old = $s['status'];
        $label = STATUS_LABEL[$r['status']] ?? $r['status'];
        log_event('status', "{$s['device_name']} › {$s['name']}: {$label} – {$r['message']}", (int) $s['id'], (int) $s['device_id'], $r['status']);
        notify_status_change($s, $old, $r['status'], (string) $r['message']);
    }
}

/** Sensor sofort prüfen (aus Poller oder „Jetzt prüfen“). */
function sensor_run(array $s): array
{
    // Abhängigkeit: ist der Eltern-Sensor down, wird dieser Sensor pausiert statt alarmiert.
    if (!empty($s['depends_on'])) {
        $dep = db()->prepare('SELECT status FROM sensors WHERE id = ? AND id != ?');
        $dep->execute([$s['depends_on'], $s['id']]);
        $ds = $dep->fetchColumn();
        if ($ds === 'down') {
            db()->prepare("UPDATE sensors SET last_message = 'Pausiert durch Abhängigkeit', last_check = ?, next_check = ? WHERE id = ?")
                ->execute([time(), time() + (int) $s['interval_s'], $s['id']]);
            return ['status' => $s['status'], 'message' => 'Pausiert durch Abhängigkeit'];
        }
    }
    if (in_array($s['type'], PUSH_TYPES, true)) {
        $r = r_fail(($s['type'] === 'agent' ? 'Agent meldet sich nicht – letzte Meldung ' : 'Keine Push-Daten seit ') . ($s['last_check'] ? date('d.m. H:i:s', (int) $s['last_check']) : 'Anlage'));
        sensor_store_result($s, $r, false);
        return $r;
    }
    $r = sensor_check($s);
    sensor_store_result($s, $r);
    return $r;
}

/* ================================================================== Baum */

/** Liefert den kompletten Objektbaum inkl. aggregierter Stati. */
function build_tree(): array
{
    $pg = paused_group_ids();
    $groups = db()->query('SELECT * FROM groups')->fetchAll();
    usort($groups, fn($a, $b) => [(int) $a['sort'], 0] <=> [(int) $b['sort'], 0] ?: strnatcasecmp($a['name'], $b['name']));
    $devices = db()->query('SELECT * FROM devices')->fetchAll();
    usort($devices, 'device_cmp');
    $sensors = db()->query(SENSOR_SELECT . ' ORDER BY s.sort, s.id')->fetchAll();
    $types = sensor_types();

    $sensByDev = [];
    $counts = array_fill_keys(array_keys(STATUS_PRIO), 0);
    foreach ($sensors as $s) {
        $st = effective_status($s, $pg);
        $counts[$st]++;
        $sensByDev[(int) $s['device_id']][] = [
            'id' => (int) $s['id'], 'name' => $s['name'], 'type' => $s['type'],
            'icon' => $types[$s['type']]['icon'] ?? '•',
            'status' => $st, 'value' => $s['last_value'] !== null ? (float) $s['last_value'] : null,
            'unit' => $s['last_unit'], 'message' => $s['last_message'],
            'last_check' => $s['last_check'] ? (int) $s['last_check'] : null,
            'since' => $s['status_since'] ? (int) $s['status_since'] : null,
            'interval' => (int) $s['interval_s'],
            'channels' => $s['last_channels'] ? (json_decode($s['last_channels'], true) ?: []) : [],
            'url' => sensor_web_url($s),
        ];
    }
    $devByGroup = [];
    foreach ($devices as $d) {
        $ss = $sensByDev[(int) $d['id']] ?? [];
        $paused = (int) $d['paused'] || isset($pg[(int) $d['group_id']]);
        $devByGroup[(int) $d['group_id']][] = [
            'id' => (int) $d['id'], 'name' => $d['name'], 'host' => $d['host'], 'icon' => $d['icon'],
            'creds' => device_creds_public($d['creds'] ?? '{}'),
            'notes' => $d['notes'], 'paused' => (bool) $d['paused'],
            'depends_on' => $d['depends_on'] ? (int) $d['depends_on'] : null,
            'status' => $paused ? 'paused' : worst_status(array_column($ss, 'status')),
            'counts' => count_states($ss),
            'sensors' => $ss,
        ];
    }
    $byParent = [];
    foreach ($groups as $g) {
        $byParent[(int) ($g['parent_id'] ?? 0)][] = $g;
    }
    $mk = function (array $g) use (&$mk, $byParent, $devByGroup, $pg): array {
        $children = array_map($mk, $byParent[(int) $g['id']] ?? []);
        $devs = $devByGroup[(int) $g['id']] ?? [];
        $all = array_merge(array_column($children, 'status'), array_column($devs, 'status'));
        $counts = count_states([]);
        foreach ($children as $c) {
            foreach ($c['counts'] as $k => $v) {
                $counts[$k] += $v;
            }
        }
        foreach ($devs as $d) {
            foreach ($d['counts'] as $k => $v) {
                $counts[$k] += $v;
            }
        }
        return [
            'id' => (int) $g['id'], 'name' => $g['name'], 'kind' => $g['kind'],
            'paused' => (bool) $g['paused'], 'collapsed' => (bool) $g['collapsed'],
            'status' => isset($pg[(int) $g['id']]) ? 'paused' : worst_status($all),
            'counts' => $counts,
            'groups' => $children, 'devices' => $devs,
        ];
    };
    $root = $byParent[0][0] ?? null;
    return ['root' => $root ? $mk($root) : null, 'counts' => $counts];
}

/** Sortierung: IPv4 numerisch (1, 2, … 110, 254), danach Namen natürlich sortiert. */
function device_cmp(array $a, array $b): int
{
    $ia = ip2long((string) $a['host']);
    $ib = ip2long((string) $b['host']);
    if ($ia !== false && $ib !== false && $ia !== $ib) {
        return sprintf('%u', $ia) <=> sprintf('%u', $ib);
    }
    if (($ia === false) !== ($ib === false)) {
        return $ia === false ? 1 : -1;
    }
    return strnatcasecmp((string) $a['name'], (string) $b['name']);
}

/** Öffnenbare Web-Adresse eines Sensors (HTTP-Sensoren, Web-Ports). */
function sensor_web_url(array $s): ?string
{
    $cfg = json_decode((string) $s['config'], true) ?: [];
    $host = (string) ($s['host'] ?? '');
    if (in_array($s['type'], ['http', 'http_json'], true)) {
        $u = trim((string) ($cfg['url'] ?? ''));
        if ($u === '' && $s['type'] === 'http' && $host !== '') {
            $u = 'http://' . $host . '/';
        }
        return preg_match('#^https?://#i', $u) ? $u : null;
    }
    $web = [80 => 'http', 443 => 'https', 4444 => 'https', 5000 => 'http', 5001 => 'https', 8006 => 'https', 8080 => 'http', 8123 => 'http', 8443 => 'https'];
    $port = (int) ($cfg['port'] ?? 0);
    if (in_array($s['type'], ['port', 'ssl'], true) && isset($web[$port]) && $host !== '') {
        return $web[$port] . '://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ($port === 80 || $port === 443 ? '' : ":{$port}") . '/';
    }
    return null;
}

/** Zugangsdaten für die Oberfläche – Passwort wird nie ausgeliefert. */
function device_creds_public(string $json): array
{
    $c = json_decode($json, true) ?: [];
    if (!empty($c['password'])) {
        $c['password'] = '';
        $c['has_password'] = true;
    }
    return $c;
}

function count_states(array $sensors): array
{
    $c = ['down' => 0, 'warning' => 0, 'acked' => 0, 'unknown' => 0, 'up' => 0, 'paused' => 0];
    foreach ($sensors as $s) {
        if (isset($c[$s['status']])) {
            $c[$s['status']]++;
        }
    }
    return $c;
}

/** Kompakte Statusliste aller Objekte (für Maps-Liveansicht). */
function status_index(): array
{
    $t = build_tree();
    $out = ['group' => [], 'device' => [], 'sensor' => []];
    $walk = function ($g) use (&$walk, &$out) {
        $out['group'][$g['id']] = ['name' => $g['name'], 'status' => $g['status'], 'counts' => $g['counts']];
        foreach ($g['groups'] as $c) {
            $walk($c);
        }
        foreach ($g['devices'] as $d) {
            $out['device'][$d['id']] = ['name' => $d['name'], 'host' => $d['host'], 'status' => $d['status'], 'counts' => $d['counts']];
            foreach ($d['sensors'] as $s) {
                $out['sensor'][$s['id']] = [
                    'name' => $s['name'], 'device' => $d['name'], 'status' => $s['status'],
                    'value' => $s['value'], 'unit' => $s['unit'], 'message' => $s['message'],
                    'channels' => $s['channels'],
                ];
            }
        }
    };
    if ($t['root']) {
        $walk($t['root']);
    }
    return $out;
}

/** Aufräumen alter Messwerte/Ereignisse. */
function housekeeping(): void
{
    $days = max(1, (int) setting('retention_days', '30'));
    db()->prepare('DELETE FROM results WHERE ts < ?')->execute([time() - $days * 86400]);
    db()->prepare('DELETE FROM events WHERE ts < ?')->execute([time() - 180 * 86400]);
}
