<?php
// API-Schlüssel für MCP/Automatisierung. Gespeichert wird nur der SHA-256-Hash; Klartext (sw_…) nur bei Erzeugung sichtbar.
declare(strict_types=1);

const API_ROLES = ['read' => 'Lesen', 'operate' => 'Lesen + Bedienen (prüfen, bestätigen, pausieren)', 'admin' => 'Vollzugriff (anlegen, ändern, löschen, scannen)'];

function api_token_create(string $name, string $role, string $by): string
{
    if (!isset(API_ROLES[$role])) {
        throw new InvalidArgumentException('Rolle ungültig');
    }
    $plain = 'sw_' . bin2hex(random_bytes(20));
    db()->prepare('INSERT INTO api_tokens(name, token_hash, prefix, role, created_at, created_by) VALUES(?, ?, ?, ?, ?, ?)')
        ->execute([mb_substr($name, 0, 80), hash('sha256', $plain), substr($plain, 0, 9), $role, time(), $by]);
    log_event('user', "API-Schlüssel „{$name}“ ({$role}) erstellt von {$by}");
    return $plain;
}

function api_token_from_request(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) {
        return $m[1];
    }
    $x = $_SERVER['HTTP_X_API_KEY'] ?? '';
    return $x !== '' ? $x : null;
}

/** → ['id', 'name', 'role'] oder null */
function api_token_verify(string $plain): ?array
{
    if (!str_starts_with($plain, 'sw_') || strlen($plain) > 100) {
        return null;
    }
    $st = db()->prepare('SELECT id, name, role, last_used FROM api_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $plain)]);
    $t = $st->fetch();
    if (!$t) {
        usleep(300000);
        return null;
    }
    if ((int) $t['last_used'] < time() - 60) {
        db()->prepare('UPDATE api_tokens SET last_used = ? WHERE id = ?')->execute([time(), $t['id']]);
    }
    return ['id' => (int) $t['id'], 'name' => $t['name'], 'role' => $t['role']];
}

/** Öffentliche Basis-URL der App (für Links in Agent-/MCP-Antworten). */
function public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host . base_path();
}
