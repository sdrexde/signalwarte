<?php
// Notfall-Werkzeug für die Kommandozeile (nur auf dem Server, als www-data ausführen):
//   su -s /bin/sh www-data -c "php tools/user-admin.php list"
//   su -s /bin/sh www-data -c "php tools/user-admin.php reset-2fa <benutzer>"
//   su -s /bin/sh www-data -c "php tools/user-admin.php set-password <benutzer> <neues-passwort>"
//   su -s /bin/sh www-data -c "php tools/user-admin.php unlock"          # Anmeldesperren aufheben
//   su -s /bin/sh www-data -c "php tools/user-admin.php require-2fa off" # 2FA-Pflicht ausschalten
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$cmd, $a, $b] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
$db = db();
$find = function (string $u) use ($db): array {
    $st = $db->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$u]);
    $r = $st->fetch();
    if (!$r) {
        fwrite(STDERR, "Benutzer '{$u}' nicht gefunden.\n");
        exit(1);
    }
    return $r;
};

switch ($cmd) {
    case 'list':
        foreach ($db->query('SELECT username, role, totp_enabled, last_login FROM users ORDER BY username') as $u) {
            printf("%-20s %-7s 2FA:%-4s letzte Anmeldung: %s\n", $u['username'], $u['role'], $u['totp_enabled'] ? 'an' : 'aus', $u['last_login'] ? date('d.m.Y H:i', (int) $u['last_login']) : '–');
        }
        break;
    case 'reset-2fa':
        $u = $find($a);
        $db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last = NULL, recovery_codes = NULL WHERE id = ?')->execute([$u['id']]);
        log_event('user', "2FA per Kommandozeile zurückgesetzt: {$a}");
        echo "2FA für {$a} zurückgesetzt.\n";
        break;
    case 'set-password':
        $u = $find($a);
        if (strlen($b) < 8) {
            fwrite(STDERR, "Passwort: mindestens 8 Zeichen.\n");
            exit(1);
        }
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($b, PASSWORD_DEFAULT), $u['id']]);
        log_event('user', "Passwort per Kommandozeile gesetzt: {$a}");
        echo "Passwort für {$a} gesetzt.\n";
        break;
    case 'unlock':
        $n = $db->exec('DELETE FROM login_attempts WHERE ok = 0');
        echo "{$n} Fehlversuche gelöscht – Sperren aufgehoben.\n";
        break;
    case 'require-2fa':
        setting_set('require_2fa', $a === 'on' ? '1' : '0');
        echo '2FA-Pflicht ' . ($a === 'on' ? 'an' : 'aus') . ".\n";
        break;
    default:
        echo "Befehle: list | reset-2fa <user> | set-password <user> <pw> | unlock | require-2fa on|off\n";
}
