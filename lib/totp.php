<?php
// TOTP (RFC 6238, SHA-1, 6 Stellen, 30 s) – kompatibel mit Google/Microsoft Authenticator, Aegis, Bitwarden, 1Password …
declare(strict_types=1);

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;

function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));   // 160 Bit
}

function totp_code(string $secret, int $step, int $digits = TOTP_DIGITS): string
{
    $key = base32_decode($secret);
    $msg = pack('N2', 0, $step);
    $h = hash_hmac('sha1', $msg, $key, true);
    $o = ord($h[19]) & 0x0f;
    $num = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($num % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Prüft einen Code (±1 Zeitfenster). Gibt den verwendeten Zeitschritt zurück oder null.
 * $lastStep verhindert, dass derselbe Code zweimal benutzt wird.
 */
function totp_verify(string $secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== TOTP_DIGITS) {
        return null;
    }
    $step = intdiv($now ?? time(), TOTP_PERIOD);
    foreach ([0, -1, 1] as $d) {
        $s = $step + $d;
        if ($lastStep !== null && $s <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_code($secret, $s), $code)) {
            return $s;
        }
    }
    return null;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** 10 Wiederherstellungscodes → [klartext[], hashes[]] */
function recovery_codes_new(): array
{
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = strtolower(substr(base32_encode(random_bytes(8)), 0, 10));
        $c = substr($c, 0, 5) . '-' . substr($c, 5, 5);
        $plain[] = $c;
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    return [$plain, $hashes];
}
