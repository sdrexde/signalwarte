<?php
// Erst-Einrichtung: Admin-Konto anlegen + Beispielobjekte. Danach gesperrt.
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/authpage.php';

session_boot();
if (!needs_setup()) {
    header('Location: ./');
    exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string) ($_POST['user'] ?? ''));
    $p = (string) ($_POST['pass'] ?? '');
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'Sitzung abgelaufen.';
    } elseif ($u === '' || strlen($p) < 8) {
        $err = 'Benutzername angeben, Passwort mindestens 8 Zeichen.';
    } elseif ($p !== ($_POST['pass2'] ?? '')) {
        $err = 'Passwörter stimmen nicht überein.';
    } else {
        $db = db();
        $db->beginTransaction();
        $db->prepare("INSERT INTO users(username, password_hash, role, created_at) VALUES(?, ?, 'admin', ?)")
            ->execute([$u, password_hash($p, PASSWORD_DEFAULT), time()]);
        if (!empty($_POST['demo'])) {
            seed_demo($db);
        }
        $db->commit();
        login_attempt($u, $p);
        header('Location: ./');
        exit;
    }
}

function seed_demo(PDO $db): void
{
    $now = time();
    $g = function (string $name, int $parent) use ($db): int {
        $db->prepare("INSERT INTO groups(parent_id, name, kind) VALUES(?, ?, 'group')")->execute([$parent, $name]);
        return (int) $db->lastInsertId();
    };
    $d = function (int $group, string $name, string $host, string $icon) use ($db): int {
        $db->prepare('INSERT INTO devices(group_id, name, host, icon) VALUES(?, ?, ?, ?)')->execute([$group, $name, $host, $icon]);
        return (int) $db->lastInsertId();
    };
    $s = function (int $dev, string $name, string $type, array $cfg, int $iv, ?float $wmax = null, ?float $emax = null, ?float $wmin = null, ?float $emin = null) use ($db, $now): int {
        $db->prepare('INSERT INTO sensors(device_id, name, type, config, interval_s, warn_max, err_max, warn_min, err_min, status_since) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$dev, $name, $type, json_encode($cfg), $iv, $wmax, $emax, $wmin, $emin, $now]);
        return (int) $db->lastInsertId();
    };
    $probe = $d(2, 'Probe-Server (lokal)', '127.0.0.1', 'server');
    $cpu = $s($probe, 'CPU-Last', 'cpu', [], 60, 80, 95);
    $mem = $s($probe, 'Arbeitsspeicher', 'memory', [], 60, 85, 95);
    $s($probe, 'Speicherplatz /', 'disk', ['path' => '/'], 300, 85, 95);

    $inet = $g('Internet', 2);
    $gw = $d($inet, 'Cloudflare DNS', '1.1.1.1', 'cloud');
    $ping = $s($gw, 'Ping', 'ping', ['count' => 3, 'timeout' => 2], 60, 150);
    $s($gw, 'DNS-Auflösung', 'dns', ['query' => 'example.com', 'rtype' => 'A', 'expect' => ''], 300, 500);
    $db->prepare('UPDATE devices SET depends_on = ? WHERE id = ?')->execute([$ping, $gw]);
    $g2 = $d($inet, 'Google DNS', '8.8.8.8', 'cloud');
    $s($g2, 'Ping', 'ping', ['count' => 3, 'timeout' => 2], 60, 150);

    $web = $g('Webseiten', 2);
    $w = $d($web, 'example.com', 'example.com', 'web');
    $https = $s($w, 'HTTPS', 'http', ['url' => 'https://example.com/', 'method' => 'GET', 'expect' => '200-399', 'keyword' => 'Example', 'keyword_absent' => '', 'timeout' => 10, 'verify_ssl' => true], 60, 2000);
    $s($w, 'SSL-Zertifikat', 'ssl', ['port' => 443, 'sni' => ''], 3600, null, null, 21, 7);
    $s($w, 'Port 443', 'port', ['port' => 443, 'timeout' => 5], 60, 500);

    // Beispiel-Map
    $icon = fn($id, $icon, $x, $y, $label, $bind = null) => array_filter(['id' => $id, 'kind' => 'icon', 'shape' => 'rect', 'icon' => $icon,
        'x' => $x, 'y' => $y, 'w' => 64, 'h' => 64, 'label' => $label, 'bind' => $bind,
        'style' => ['fill' => '#ffffff', 'stroke' => '#334155', 'strokeWidth' => 0, 'fontSize' => 12, 'fontColor' => '#1f2937', 'bold' => true, 'labelPos' => 'bottom', 'iconColor' => '#1e5fa8']], fn($v) => $v !== null);
    $zone = fn($id, $x, $y, $w, $h, $label, $color) => ['id' => $id, 'kind' => 'zone', 'shape' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'label' => $label,
        'style' => ['fill' => $color, 'fillOpacity' => 0.06, 'stroke' => $color, 'strokeWidth' => 1.5, 'dash' => true, 'fontSize' => 14, 'fontColor' => $color, 'bold' => true, 'radius' => 12]];
    $stat = fn($id, $x, $y, $type, $bid, $w = 190) => ['id' => $id, 'kind' => 'status', 'shape' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => 54, 'label' => '',
        'bind' => ['type' => $type, 'id' => $bid], 'style' => ['fill' => '#ffffff', 'stroke' => '#cbd5e1', 'strokeWidth' => 1, 'fontSize' => 12, 'fontColor' => '#1f2937', 'radius' => 8]];
    $edge = fn($id, $a, $b, $extra = []) => array_merge(['id' => $id, 'from' => ['node' => $a, 'port' => null], 'to' => ['node' => $b, 'port' => null],
        'style' => ['stroke' => '#64748b', 'width' => 2, 'dash' => false, 'routing' => 'orthogonal', 'arrowEnd' => false, 'arrowStart' => false, 'animated' => false, 'autoStatus' => true, 'fontSize' => 11]], $extra);
    $nodes = [
        $zone('z1', 40, 60, 560, 190, 'Internet / Upstream', '#7c3aed'),
        $zone('z2', 40, 290, 560, 360, 'Standort – Rechenzentrum', '#0f6fc5'),
        ['id' => 't1', 'kind' => 'text', 'shape' => 'rect', 'x' => 40, 'y' => 10, 'w' => 360, 'h' => 36, 'label' => 'Netzwerkplan (Beispiel)',
            'style' => ['fill' => 'none', 'stroke' => 'none', 'strokeWidth' => 0, 'fontSize' => 22, 'fontColor' => '#0f172a', 'bold' => true, 'labelPos' => 'center']],
        $icon('n_inet', 'internet', 90, 120, 'Internet'),
        $icon('n_cf', 'cloud', 290, 120, 'Cloudflare DNS', ['type' => 'device', 'id' => $gw]),
        $icon('n_gg', 'cloud', 470, 120, 'Google DNS', ['type' => 'device', 'id' => $g2]),
        $icon('n_fw', 'firewall', 290, 320, 'Firewall'),
        $icon('n_sw', 'switch', 290, 470, 'Core-Switch'),
        $icon('n_srv', 'server', 110, 560, 'Probe-Server', ['type' => 'device', 'id' => $probe]),
        $icon('n_web', 'web', 470, 560, 'example.com', ['type' => 'device', 'id' => $w]),
        ['id' => 'h1', 'kind' => 'text', 'shape' => 'rect', 'x' => 650, 'y' => 60, 'w' => 220, 'h' => 30, 'label' => 'Live-Werte',
            'style' => ['fill' => 'none', 'stroke' => 'none', 'strokeWidth' => 0, 'fontSize' => 15, 'fontColor' => '#334155', 'bold' => true, 'labelPos' => 'center']],
        $stat('s1', 650, 100, 'group', $web, 220),
        $stat('s2', 650, 170, 'group', $inet, 220),
        $stat('s3', 650, 260, 'sensor', $cpu, 220),
        $stat('s4', 650, 330, 'sensor', $mem, 220),
        $stat('s5', 650, 400, 'sensor', $https, 220),
        $stat('s6', 650, 470, 'sensor', $ping, 220),
    ];
    $edges = [
        $edge('e1', 'n_inet', 'n_cf', ['style' => ['stroke' => '#64748b', 'width' => 2, 'routing' => 'straight', 'dash' => true, 'autoStatus' => true]]),
        $edge('e2', 'n_cf', 'n_fw', ['label' => 'WAN 1']),
        $edge('e3', 'n_gg', 'n_fw', ['label' => 'WAN 2']),
        $edge('e4', 'n_fw', 'n_sw', ['label' => '10 Gbit/s', 'style' => ['stroke' => '#0f6fc5', 'width' => 3, 'routing' => 'straight', 'animated' => true, 'autoStatus' => true]]),
        $edge('e5', 'n_sw', 'n_srv'),
        $edge('e6', 'n_sw', 'n_web', ['bind' => ['type' => 'sensor', 'id' => $https]]),
    ];
    $db->prepare('INSERT INTO maps(name, data, refresh_s, updated_at) VALUES(?, ?, 30, ?)')
        ->execute(['Netzwerkplan (Beispiel)', json_encode(['nodes' => $nodes, 'edges' => $edges, 'settings' => ['grid' => true, 'snap' => true, 'gridSize' => 10, 'background' => '#ffffff', 'guides' => true]], JSON_UNESCAPED_UNICODE), $now]);
}

ob_start(); ?>
<p class="muted">Willkommen! Lege das erste Administrator-Konto an.</p>
<form method="post" class="auth-form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
  <label>Benutzername<input name="user" required autofocus value="<?= h($_POST['user'] ?? 'admin') ?>"></label>
  <label>Passwort (min. 8 Zeichen)<input name="pass" type="password" minlength="8" required autocomplete="new-password"></label>
  <label>Passwort wiederholen<input name="pass2" type="password" minlength="8" required autocomplete="new-password"></label>
  <label class="check"><input type="checkbox" name="demo" value="1" checked> Beispiel-Geräte und -Sensoren anlegen</label>
  <button class="btn primary">Einrichten</button>
</form>
<?php auth_page('Ersteinrichtung', ob_get_clean());
