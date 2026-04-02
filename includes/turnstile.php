<?php
// includes/turnstile.php — Cloudflare Turnstile verification

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/response.php';

function turnstile_site_key() {
    $value = getenv('TURNSTILE_SITE_KEY');
    if ($value !== false && trim($value) !== '') return trim($value);
    return '0x4AAAAAACyz8Bnvh3fYSsPr';
}

function turnstile_secret_key() {
    $value = getenv('TURNSTILE_SECRET_KEY');
    if ($value !== false && trim($value) !== '') return trim($value);
    return '0x4AAAAAACyz8GvsgJ1qDLou9v-3h79zawg';
}

function turnstile_verify($token, $remoteIp) {
    $token = trim((string)$token);
    if ($token === '') {
        return ['ok' => false, 'reason' => 'missing-input-response'];
    }

    $payload = http_build_query([
        'secret'   => turnstile_secret_key(),
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Content-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 8,
        ],
    ]);

    $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    if ($raw === false) {
        return ['ok' => false, 'reason' => 'verification-request-failed'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'reason' => 'invalid-json'];
    }

    return [
        'ok' => !empty($data['success']),
        'reason' => isset($data['error-codes']) && is_array($data['error-codes'])
            ? implode(',', $data['error-codes'])
            : '',
    ];
}

function ensure_turnstile_or_render($uiLang, $backUrl) {
    $result = turnstile_verify($_POST['cf-turnstile-response'] ?? '', client_ip());
    if ($result['ok']) return;

    render_turnstile_error_page($uiLang, $backUrl);
}
