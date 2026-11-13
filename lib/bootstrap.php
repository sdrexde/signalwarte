<?php
// Zentrale Initialisierung: Konfiguration, Datenbank, Session/Auth, Helfer.
declare(strict_types=1);

const APP_NAME    = 'Reload Monitor';
const APP_VERSION = '1.1.0';
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
    session_name('RELOADMONSESS');
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
    $st = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $cache = ($st->fetch() ?: null);
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

function login_attempt(string $user, string $pass): bool
{
    $st = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $st->execute([$user]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        usleep(400000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return true;
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
