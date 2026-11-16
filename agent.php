<?php
// Liefert den Windows-Agenten mit eingesetzter Push-URL + Token aus (wie bei Spurwerk).
//   agent.php?token=XYZ            -> SignalwarteAgent.ps1 (Windows)
//   agent.php?token=XYZ&os=linux   -> signalwarte-agent.sh (Linux)
// Ohne Login nutzbar, weil das Token selbst der Schlüssel ist (nur Admins sehen es in der Oberfläche).
declare(strict_types=1);

require_once __DIR__ . '/lib/engine.php';

require_once __DIR__ . '/lib/agents.php';

$tok = (string) ($_GET['token'] ?? '');
$enroll = (string) ($_GET['enroll'] ?? '');
if ($enroll !== '') {
    // Download mit Registrierungs-Token: der Agent meldet sich bei der Installation selbst an
    if (!preg_match('/^swe_[a-f0-9]{32}$/', $enroll) || !enroll_key_valid($enroll)) {
        http_response_code(403);
        exit('Registrierungs-Token ungültig oder Selbstanmeldung deaktiviert');
    }
} elseif ($tok === '' || strlen($tok) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $tok)) {
    http_response_code(400);
    exit('token fehlt');
}
$s = ['id' => 0, 'device' => ''];
if ($enroll === '') {
    $st = db()->prepare("SELECT s.id, s.name, d.name AS device FROM sensors s JOIN devices d ON d.id = s.device_id WHERE s.push_token = ? AND s.type = 'agent'");
    $st->execute([$tok]);
    $s = $st->fetch();
    if (!$s) {
        http_response_code(404);
        exit('Unbekanntes Token');
    }
}
require_once __DIR__ . '/lib/apitokens.php';
$https = request_is_https();
$host = request_host($https);   // inkl. Port, z. B. 192.168.18.110:8080
if ($host === null) {   // kommt in Skripte – nur saubere Hostnamen
    http_response_code(400);
    exit('Ungültiger Host');
}
$base = ($https ? 'https' : 'http') . '://' . $host . base_path();
$cfg = json_encode($enroll !== ''
    ? ['enroll_url' => $base . 'enroll.php', 'enroll_key' => $enroll]
    : ['url' => $base . 'push.php?token=' . $tok, 'sensor' => (int) $s['id'], 'device' => $s['device']],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
// nur ASCII in die PS1 (Windows PowerShell 5.1 ohne BOM)
$cfg = preg_replace_callback('/[^\x00-\x7F]/u', fn($m) => sprintf('\\u%04x', mb_ord($m[0])), $cfg);

if (($_GET['os'] ?? '') === 'linux') {
    // Linux: Bash-Skript, URL wird als Shell-String eingesetzt (Token enthält nur [A-Za-z0-9_-])
    $tpl = file_get_contents(__DIR__ . '/agent/signalwarte-agent.sh');
    $out = $enroll !== ''
        ? str_replace(['__SIGNALWARTE_URL__', '__SIGNALWARTE_ENROLL_URL__', '__SIGNALWARTE_ENROLL_KEY__'], ['', $base . 'enroll.php', $enroll], $tpl)
        : str_replace('__SIGNALWARTE_URL__', $base . 'push.php?token=' . $tok, $tpl);
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Content-Disposition: attachment; filename="signalwarte-agent.sh"');
    header('Cache-Control: no-store');
    echo $out;
    exit;
}

$tpl = file_get_contents(__DIR__ . '/agent/SignalwarteAgent.ps1');
$out = str_replace('__SIGNALWARTE_CONFIG__', $cfg, $tpl);
$out = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $out));
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="SignalwarteAgent.ps1"');
header('Cache-Control: no-store');
echo $out;
