<?php
// HTTP-Push-Endpunkt
//  a) Push-Sensor:  GET/POST push.php?token=XYZ&value=42&status=up|warning|down&msg=Text
//  b) Agent-Sensor: POST push.php?token=XYZ  mit JSON {"channels":{...},"services":{...},"msg":"…","info":{...}}
//     Antwort enthält die zu überwachenden Dienste, damit der Agent sie beim nächsten Mal mitschickt.
declare(strict_types=1);

require_once __DIR__ . '/lib/engine.php';

$tok = (string) ($_REQUEST['token'] ?? '');
if ($tok === '' || strlen($tok) > 64) {
    json_err('token fehlt', 400);
}
$st = db()->prepare(SENSOR_SELECT . " WHERE s.push_token = ? AND s.type IN ('push', 'agent')");
$st->execute([$tok]);
$s = $st->fetch() ?: json_err('Unbekanntes Token', 404);
$cfg = json_decode($s['config'], true) ?: [];
$watch = array_values(array_filter(array_map('trim', explode(',', (string) ($cfg['services'] ?? '')))));

if ((int) $s['paused'] || (int) $s['device_paused']) {
    json_out(['ok' => true, 'ignored' => 'pausiert', 'services' => $watch]);
}

$json = null;
if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
    $raw = file_get_contents('php://input', false, null, 0, 256 * 1024);
    $json = json_decode($raw ?: '', true);
    if (!is_array($json) && is_string($raw)) {
        // ältere Linux-Agenten unter deutschem Gebietsschema schicken "0,5" statt 0.5 – reparieren,
        // damit sie wieder melden und sich selbst aktualisieren können
        $fixed = preg_replace('/(":\s*-?\d+),(\d+)(?=\s*[,}\]])/', '$1.$2', $raw);
        $json = json_decode((string) $fixed, true);
    }
    if (!is_array($json)) {
        json_err('Ungültiges JSON');
    }
}

if ($s['type'] === 'agent') {
    if (!$json) {
        json_err('Agent erwartet JSON (Content-Type: application/json)');
    }
    $channels = [];
    foreach ((array) ($json['channels'] ?? []) as $k => $v) {
        if (is_numeric($v) && count($channels) < 60) {
            $channels[mb_substr((string) $k, 0, 40)] = round((float) $v, 3);
        }
    }
    $status = 'up';
    $issues = [];
    $bump = function (string $to, string $why) use (&$status, &$issues) {
        if (STATUS_PRIO[$to] > STATUS_PRIO[$status]) {
            $status = $to;
        }
        $issues[] = $why;
    };
    $dw = (float) ($cfg['disk_warn'] ?? 15);
    $de = (float) ($cfg['disk_err'] ?? 5);
    foreach ($channels as $k => $v) {
        if (preg_match('/^Frei (.+) %$/u', $k, $m)) {
            if ($v < $de) {
                $bump('down', "Laufwerk {$m[1]} nur {$v}% frei");
            } elseif ($v < $dw) {
                $bump('warning', "Laufwerk {$m[1]} nur {$v}% frei");
            }
        }
    }
    if (($channels['Neustart ausstehend'] ?? 0) > 0) {
        $bump('warning', 'Neustart ausstehend');
    }
    // fehlgeschlagene systemd-Units: Ausnahmen (Sensor-Einstellung + globale Liste) herausrechnen
    if (isset($channels['Dienste fehlgeschlagen']) && is_array($json['failed'] ?? null)) {
        $ignf = array_filter(array_map('trim', explode(',', (string) ($cfg['ignore_failed'] ?? '') . ',' . (string) setting('agent_ignore_failed', ''))));
        $json['failed'] = array_values(array_filter((array) $json['failed'], function ($n) use ($ignf) {
            foreach ($ignf as $p) {
                if (fnmatch($p, (string) $n, FNM_CASEFOLD)) {
                    return false;
                }
            }
            return true;
        }));
        $channels['Dienste fehlgeschlagen'] = count($json['failed']);
    }
    if (($channels['Dienste fehlgeschlagen'] ?? 0) > 0) {
        $bump('warning', $channels['Dienste fehlgeschlagen'] . ' systemd-Dienst(e) fehlgeschlagen' . (!empty($json['failed']) ? ' (' . mb_substr(implode(', ', (array) $json['failed']), 0, 150) . ')' : ''));
    }
    // gestoppte Auto-Dienste: Ausnahmen (Sensor-Einstellung + globale Standardliste) herausrechnen
    if (isset($channels['Auto-Dienste gestoppt']) && is_array($json['stopped'] ?? null)) {
        $ign = array_filter(array_map('trim', explode(',', (string) ($cfg['ignore_stopped'] ?? '') . ',' . (string) setting('agent_ignore_stopped', 'Avira*Updater*,AviraFallback*,GoogleUpdate*,MozillaMaintenance,BITS,gupdate*,edgeupdate*,sppsvc,RemoteRegistry'))));
        $json['stopped'] = array_values(array_filter((array) $json['stopped'], function ($n) use ($ign) {
            foreach ($ign as $p) {
                if (fnmatch($p, (string) $n, FNM_CASEFOLD)) {
                    return false;
                }
            }
            return true;
        }));
        $channels['Auto-Dienste gestoppt'] = count($json['stopped']);
    }
    if (($channels['Auto-Dienste gestoppt'] ?? 0) > 0) {
        $bump('warning', ($channels['Auto-Dienste gestoppt']) . ' automatische Dienste gestoppt' . (!empty($json['stopped']) ? ' (' . mb_substr(implode(', ', (array) $json['stopped']), 0, 150) . ')' : ''));
    }
    if (isset($channels['Defender aktiv']) && $channels['Defender aktiv'] < 1) {
        $bump('warning', 'Defender-Echtzeitschutz aus');
    }
    $svc = (array) ($json['services'] ?? []);
    foreach ($watch as $name) {
        $state = null;
        foreach ($svc as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                $state = (string) $v;
            }
        }
        if ($state === null) {
            $bump('warning', "Dienst {$name}: keine Daten (kommt mit nächster Meldung)");
        } elseif (!in_array(strtolower($state), ['running', 'active'], true)) {
            $bump('down', "Dienst {$name}: {$state}");
        }
        $channels["Dienst {$name}"] = $state !== null && in_array(strtolower($state), ['running', 'active'], true) ? 1 : 0;
    }
    $agentStatus = $json['status'] ?? null;
    if (in_array($agentStatus, ['warning', 'down'], true)) {
        $bump($agentStatus, (string) ($json['msg'] ?? 'Agent meldet Problem'));
    }
    // Systeminformationen am Gerät ablegen (Hersteller, Modell, CPU, RAM, Netzwerk, Laufwerke …)
    if (is_array($json['info'] ?? null)) {
        $info = $json['info'];
        $enc = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($enc !== false && strlen($enc) <= 65536) {
            db()->prepare('UPDATE devices SET sysinfo = ?, sysinfo_at = ? WHERE id = ?')->execute([$enc, time(), $s['device_id']]);
        }
    }
    $primary = (string) ($cfg['primary'] ?? 'CPU %');
    $info = array_filter([
        is_string($json['info']['os'] ?? null) ? $json['info']['os'] : null,
        isset($channels['CPU %']) ? 'CPU ' . $channels['CPU %'] . '%' : null,
        isset($channels['RAM %']) ? 'RAM ' . $channels['RAM %'] . '%' : null,
        isset($channels['Laufzeit (Tage)']) ? 'Laufzeit ' . $channels['Laufzeit (Tage)'] . ' T' : null,
    ]);
    $msg = $issues ? implode(' · ', $issues) : implode(' · ', $info);
    $r = ['status' => $status, 'value' => $channels[$primary] ?? null, 'unit' => str_contains($primary, '%') ? '%' : '',
        'message' => mb_substr($msg, 0, 480), 'extra' => $channels];
    sensor_store_result($s, $r, true);
    // Selbst-Update: neuere Agent-Version verfügbar?
    require_once __DIR__ . '/lib/agents.php';
    $resp = ['ok' => true, 'status' => $status, 'services' => $watch, 'interval' => (int) $s['interval_s']];
    $plat = ($json['info']['platform'] ?? '') === 'linux' ? 'linux' : 'windows';
    $have = (string) ($json['info']['agent'] ?? '');
    $latest = agent_latest_version($plat);
    if ($latest && $have !== '' && version_compare($have, $latest, '<') && setting('agent_autoupdate', '1') === '1') {
        $resp['update'] = ['version' => $latest, 'url' => agent_download_url((string) $s['push_token'], $plat)];
    }
    json_out($resp);
}

// einfacher Push
$src = $json ?? $_REQUEST;
$status = in_array($src['status'] ?? 'up', ['up', 'warning', 'down'], true) ? ($src['status'] ?? 'up') : 'up';
$value = isset($src['value']) && is_numeric($src['value']) ? (float) $src['value'] : null;
$msg = mb_substr(trim((string) ($src['msg'] ?? '')), 0, 300) ?: 'Push empfangen';
$r = ['status' => $status, 'value' => $value, 'unit' => (string) ($cfg['unit'] ?? ''), 'message' => $msg, 'extra' => []];
// Gemeldete Fehler sind bewusst -> kein Retry (Push-Typen), Grenzwerte trotzdem anwenden.
sensor_store_result($s, $r, true);
json_out(['ok' => true]);
