<?php
// Selbstanmeldung von Agenten mit dem Registrierungs-Token (Einstellungen › Agenten).
//   POST enroll.php  JSON {"key":"swe_…","hostname":"srv01","ip":"192.168.1.10","platform":"linux|windows","os":"…"}
//   → {"ok":true,"url":"https://…/push.php?token=…","device_id":…,"sensor_id":…,"created":true|false}
declare(strict_types=1);

require_once __DIR__ . '/lib/ops.php';
require_once __DIR__ . '/lib/agents.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Nur POST', 405);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 65536) ?: '', true);
if (!is_array($in)) {
    json_err('Ungültiges JSON');
}
// einfacher Schutz gegen Raten: nach 10 Fehlversuchen/15 min pro IP sperren
$ip = client_ip();
$st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ok = 0 AND username = 'enroll' AND ip = ? AND ts > ?");
$st->execute([$ip, time() - 900]);
if ((int) $st->fetchColumn() >= 10) {
    json_err('Zu viele Fehlversuche', 429);
}
if (!enroll_key_valid((string) ($in['key'] ?? ''))) {
    db()->prepare("INSERT INTO login_attempts(ts, ip, username, ok) VALUES(?, ?, 'enroll', 0)")->execute([time(), $ip]);
    usleep(500000);
    json_err(enroll_enabled() ? 'Registrierungs-Token ungültig' : 'Selbstanmeldung ist deaktiviert', 403);
}
try {
    json_out(['ok' => true] + agent_enroll($in));
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
}
