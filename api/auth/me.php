<?php
require_once __DIR__ . '/../../includes/pay.php';
require_once __DIR__ . '/../../includes/page_edit.php';

xlog_start_session();
$userId = current_user_id();
$data = json_input();
$payload = [
    'user' => null,
    'quota' => quota_status('generate'),
    'billing' => [
        'credit_mode' => (bool)xlog_config('billing.credit_mode', false),
        'pay_enabled' => pay_enabled(),
        'credit_cost' => max(1, (int)xlog_config('billing.generate_credit_cost', 1)),
        // Ops note: when credit_mode is on, users.daily_quota is ignored for generate
        // except free-daily fallback counter (billing.user_fallback_daily_generate).
        'user_fallback_daily_generate' => user_fallback_daily_limit(),
    ],
];
if (!$userId) api_json($payload);
$user = db_one('SELECT id, email, daily_quota, credits FROM users WHERE id = ?', [$userId]);
$payload['user'] = $user;

// Re-run claim on me() so resuming a tab after login still attaches guest pages.
$sessionId = trim((string)($data['session_id'] ?? ''));
if ($user) {
    $payload['claims'] = claim_pages_after_login(
        (int)$user['id'],
        $sessionId !== '' ? $sessionId : null,
        $user['email'] ?? null
    );
    // Refresh credits after any side-effects (none currently change credits).
    $payload['quota'] = quota_status('generate');
}

api_json($payload);
