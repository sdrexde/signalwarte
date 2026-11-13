<?php
// Benachrichtigungen: E-Mail, Telegram, Webhook, ntfy.
declare(strict_types=1);

function notification_types(): array
{
    return [
        'email' => ['label' => 'E-Mail', 'fields' => [
            ['key' => 'to', 'label' => 'Empfänger (Komma-getrennt)', 'type' => 'text'],
            ['key' => 'from', 'label' => 'Absender', 'type' => 'text'],
        ]],
        'telegram' => ['label' => 'Telegram', 'fields' => [
            ['key' => 'bot_token', 'label' => 'Bot-Token', 'type' => 'text'],
            ['key' => 'chat_id', 'label' => 'Chat-ID', 'type' => 'text'],
        ]],
        'webhook' => ['label' => 'Webhook (JSON-POST)', 'fields' => [
            ['key' => 'url', 'label' => 'URL', 'type' => 'text'],
            ['key' => 'header', 'label' => 'Zusatz-Header (optional)', 'type' => 'text'],
        ]],
        'ntfy' => ['label' => 'ntfy.sh Push', 'fields' => [
            ['key' => 'url', 'label' => 'Topic-URL (z. B. https://ntfy.sh/mein-topic)', 'type' => 'text'],
            ['key' => 'token', 'label' => 'Access-Token (optional)', 'type' => 'text'],
        ]],
    ];
}

function notify_status_change(array $s, string $old, string $new, string $message): void
{
    $col = match ($new) {
        'down'    => 'on_down',
        'warning' => 'on_warning',
        'up'      => in_array($old, ['down', 'warning'], true) ? 'on_up' : null,
        default   => null,
    };
    if (!$col) {
        return;
    }
    $rows = db()->query("SELECT * FROM notifications WHERE enabled = 1 AND {$col} = 1")->fetchAll();
    if (!$rows) {
        return;
    }
    $emoji = ['down' => '🔴', 'warning' => '🟡', 'up' => '🟢'][$new] ?? '⚪';
    $title = sprintf('%s %s › %s: %s', $emoji, $s['device_name'], $s['name'], STATUS_LABEL[$new] ?? $new);
    $payload = [
        'event' => 'status_change', 'sensor_id' => (int) $s['id'], 'sensor' => $s['name'],
        'device' => $s['device_name'], 'host' => $s['host'] ?? '', 'old_status' => $old, 'status' => $new,
        'message' => $message, 'time' => date('c'),
    ];
    foreach ($rows as $n) {
        try {
            $ok = notify_send($n, $title, $message, $payload);
            log_event('notify', ($ok ? 'Gesendet' : 'FEHLER') . " via {$n['name']}: {$title}", (int) $s['id'], (int) $s['device_id']);
        } catch (Throwable $e) {
            log_event('notify', "FEHLER via {$n['name']}: " . $e->getMessage(), (int) $s['id'], (int) $s['device_id']);
        }
    }
}

function notify_send(array $n, string $title, string $body, array $payload): bool
{
    $c = json_decode($n['config'] ?: '{}', true) ?: [];
    switch ($n['type']) {
        case 'email':
            $to = trim((string) ($c['to'] ?? ''));
            if ($to === '') {
                return false;
            }
            $headers = 'Content-Type: text/plain; charset=UTF-8';
            if (!empty($c['from'])) {
                $headers .= "\r\nFrom: " . str_replace(["\r", "\n"], '', $c['from']);
            }
            return mail($to, '=?UTF-8?B?' . base64_encode($title) . '?=', $body . "\n\n" . date('d.m.Y H:i:s'), $headers);
        case 'telegram':
            if (empty($c['bot_token']) || empty($c['chat_id'])) {
                return false;
            }
            return http_post_json(
                'https://api.telegram.org/bot' . rawurlencode($c['bot_token']) . '/sendMessage',
                ['chat_id' => $c['chat_id'], 'text' => $title . "\n" . $body]
            );
        case 'webhook':
            if (empty($c['url'])) {
                return false;
            }
            return http_post_json($c['url'], $payload + ['title' => $title], $c['header'] ?? '');
        case 'ntfy':
            if (empty($c['url'])) {
                return false;
            }
            $ch = curl_init($c['url']);
            $h = ['Title: ' . preg_replace('/[^\x20-\x7E]/', '', $title) ?: 'Monitor'];
            if (!empty($c['token'])) {
                $h[] = 'Authorization: Bearer ' . $c['token'];
            }
            $h[] = 'Priority: ' . ($payload['status'] === 'down' ? 'high' : 'default');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $title . "\n" . $body,
                CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
    }
    return false;
}

function http_post_json(string $url, array $data, string $extraHeader = ''): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $h = ['Content-Type: application/json'];
    if ($extraHeader !== '' && str_contains($extraHeader, ':')) {
        $h[] = $extraHeader;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
