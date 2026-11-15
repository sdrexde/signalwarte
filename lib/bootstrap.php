<?php
// Zentrale Initialisierung: Konfiguration, Datenbank, Session/Auth, Helfer.
declare(strict_types=1);

const APP_NAME    = 'Signalwarte';
const APP_VERSION = '1.7.0';
define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', getenv('RM_DATA_DIR') ?: APP_ROOT . '/data');

date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/db.php';

/* ---------------------------------------------------------------- Status */

/** Status-Priorität (höher = schlimmer) – wie im PRTG-Modell. */
const STATUS_PRIO = [
    'down'    => 6,
    'warning' => 5,
    'acked'   => 4,   // Down (bestätigt)
    'unknown' => 3,
    'up'      => 2,
    'paused'  => 1,
    'none'    => 0,
];

const STATUS_LABEL = [
    'down'    => 'Fehler',
    'warning' => 'Warnung',
    'acked'   => 'Fehler (bestätigt)',
    'unknown' => 'Unbekannt',
    'up'      => 'OK',
    'paused'  => 'Pausiert',
    'none'    => 'Keine Sensoren',
];

function worst_status(array $states): string
{
    $best = 'none';
    foreach ($states as $s) {
        if ((STATUS_PRIO[$s] ?? 0) > STATUS_PRIO[$best]) {
            $best = $s;
        }
    }
    return $best;
}

/* --------------------------------------------------------------- Session */

function is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function base_path(): string
{
    // Pfad der App relativ zur Domain, z. B. "/monitor/" – damit läuft sie in jedem Unterordner.
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

function session_boot(): void
{
    if (is_cli() || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('SIGNALWARTESESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path(),
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function needs_setup(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (empty($_SESSION['uid'])) {
        return $cache = null;
    }
    // Sitzung nach Inaktivität beenden
    $idle = max(5, (int) setting('session_idle_min', '480')) * 60;
    if (!empty($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $idle) {
        $_SESSION = [];
        return $cache = null;
    }
    // automatische Aktualisierungen (Status-Polling) zählen nicht als Aktivität
    if (empty($_SERVER['HTTP_X_AUTO_REFRESH'])) {
        $_SESSION['last_activity'] = time();
    }
    $st = db()->prepare('SELECT id, username, role, totp_enabled FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;
    if ($u) {
        $u['totp_enabled'] = (bool) $u['totp_enabled'];
        $u['must_setup_2fa'] = !$u['totp_enabled'] && setting('require_2fa', '0') === '1';
    }
    return $cache = $u;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login_page(): array
{
    session_boot();
    if (needs_setup()) {
        header('Location: setup.php');
        exit;
    }
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

function csrf_ok(?string $t): bool
{
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function client_ip(): string
{
    // REMOTE_ADDR ist hinter dem Proxy dank mod_remoteip bereits die echte Client-IP
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

const LOGIN_MAX_FAILS = 5;       // Fehlversuche …
const LOGIN_WINDOW    = 900;     // … innerhalb von 15 Minuten → Sperre

/** Sekunden bis zur nächsten erlaubten Anmeldung (0 = frei). Zählt pro IP und pro Benutzername. */
function login_blocked_for(string $user): int
{
    $since = time() - LOGIN_WINDOW;
    $st = db()->prepare('SELECT COUNT(*), MAX(ts) FROM login_attempts WHERE ok = 0 AND ts > ? AND (ip = ? OR username = ?)');
    $st->execute([$since, client_ip(), mb_strtolower($user)]);
    [$n, $last] = $st->fetch(PDO::FETCH_NUM);
    if ((int) $n < LOGIN_MAX_FAILS) {
        return 0;
    }
    return max(0, (int) $last + LOGIN_WINDOW - time());
}

function login_log(string $user, bool $ok): void
{
    db()->prepare('INSERT INTO login_attempts(ts, ip, username, ok) VALUES(?, ?, ?, ?)')->execute([time(), client_ip(), mb_strtolower($user), (int) $ok]);
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 86400 * 30]);
    }
    if (!$ok) {
        log_event('user', "Fehlgeschlagene Anmeldung: {$user} von " . client_ip());
    }
}

/** Prüft Benutzer + Passwort → Benutzerzeile oder null (ohne Sitzung zu starten). */
function check_password(string $user, string $pass): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$user]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        usleep(400000);
        return null;
    }
    return $u;
}

function complete_login(int $uid, string $username): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
    $_SESSION['uid'] = $uid;
    $_SESSION['last_activity'] = time();
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $uid]);
    login_log($username, true);
    log_event('user', "Anmeldung: {$username} von " . client_ip());
}

/** Zweiter Faktor: TOTP-Code oder Wiederherstellungscode. */
function verify_second_factor(array $u, string $code): bool
{
    require_once __DIR__ . '/totp.php';
    $code = trim($code);
    if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code))) {
        $step = totp_verify((string) $u['totp_secret'], $code, $u['totp_last'] !== null ? (int) $u['totp_last'] : null);
        if ($step !== null) {
            db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $u['id']]);
            return true;
        }
        return false;
    }
    $codes = json_decode((string) $u['recovery_codes'], true) ?: [];
    foreach ($codes as $i => $h) {
        if (password_verify(strtolower($code), $h)) {
            unset($codes[$i]);
            db()->prepare('UPDATE users SET recovery_codes = ? WHERE id = ?')->execute([json_encode(array_values($codes)), $u['id']]);
            log_event('user', "Wiederherstellungscode benutzt: {$u['username']} (noch " . count($codes) . ')');
            return true;
        }
    }
    return false;
}

/* ---------------------------------------------------------------- Helfer */

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function setting_set(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function random_token(int $bytes = 18): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function log_event(string $type, string $msg, ?int $sensorId = null, ?int $deviceId = null, ?string $status = null): void
{
    db()->prepare('INSERT INTO events(ts, type, sensor_id, device_id, status, message) VALUES(?, ?, ?, ?, ?, ?)')
        ->execute([time(), $type, $sensorId, $deviceId, $status, mb_substr($msg, 0, 1000)]);
}
