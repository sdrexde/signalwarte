<?php
// HTTP-Push-Endpunkt: Geräte/Skripte melden Werte selbst.
//   GET/POST push.php?token=XYZ&value=42&status=up|warning|down&msg=Text
declare(strict_types=1);

require_once __DIR__ . '/lib/engine.php';

$tok = (string) ($_REQUEST['token'] ?? '');
if ($tok === '' || strlen($tok) > 64) {
    json_err('token fehlt', 400);
}
$st = db()->prepare(SENSOR_SELECT . " WHERE s.push_token = ? AND s.type = 'push'");
$st->execute([$tok]);
$s = $st->fetch() ?: json_err('Unbekanntes Token', 404);
if ((int) $s['paused'] || (int) $s['device_paused']) {
    json_out(['ok' => true, 'ignored' => 'pausiert']);
}
$status = in_array($_REQUEST['status'] ?? 'up', ['up', 'warning', 'down'], true) ? ($_REQUEST['status'] ?? 'up') : 'up';
$value = isset($_REQUEST['value']) && is_numeric($_REQUEST['value']) ? (float) $_REQUEST['value'] : null;
$msg = mb_substr(trim((string) ($_REQUEST['msg'] ?? '')), 0, 300) ?: 'Push empfangen';
$cfg = json_decode($s['config'], true) ?: [];
$r = ['status' => $status, 'value' => $value, 'unit' => (string) ($cfg['unit'] ?? ''), 'message' => $msg, 'extra' => []];
// Push-Fehlermeldungen sind bewusst gemeldet -> kein Retry, Grenzwerte trotzdem anwenden.
$s['fail_count'] = $status === 'down' ? 1 : 0;
sensor_store_result($s, $r, true);
json_out(['ok' => true]);
