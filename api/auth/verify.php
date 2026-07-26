<?php
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/page_edit.php';

require_method('POST');
xlog_start_session();
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
$email = normalize_email($data['email'] ?? '');
$code = trim($data['code'] ?? '');
if ($email === '' || !preg_match('/^\d{6}$/', $code)) api_error('bad_request', t('api', 'badLoginRequest', $locale));

$row = db_one('SELECT rowid, * FROM login_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1', [$email]);
if (!$row || strtotime($row['expires_at']) < time() || (int)$row['attempts'] >= 5) {
    api_error('invalid_code', t('api', 'invalidCode', $locale), 400);
}
if (!password_verify($code, $row['code_hash'])) {
    db_exec('UPDATE login_codes SET attempts = attempts + 1 WHERE rowid = ?', [$row['rowid']]);
    api_error('invalid_code', t('api', 'wrongCode', $locale), 400);
}
db_exec('DELETE FROM login_codes WHERE email = ?', [$email]);

$user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
if (!$user) {
    $signupCredits = max(0, (int)xlog_config('billing.signup_credits', 10));
    $dailyQuota = max(0, (int)xlog_config('billing.signup_daily_quota', 10));
    db_exec(
        'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)',
        [$email, now_iso(), $dailyQuota, $signupCredits, 'active']
    );
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user && $signupCredits > 0) {
        db_exec(
            'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
            [(int)$user['id'], $signupCredits, 'signup_bonus', null, now_iso()]
        );
    }
}
// AUDIT-8 P2-1: disabled/suspended users must not obtain a session.
if (!$user || (string)($user['status'] ?? '') !== 'active') {
    api_error('account_disabled', t('api', 'accountDisabled', $locale) ?: 'Account disabled', 403);
}
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];

// G2/G8/G10: bind active session and claim eligible guest pages.
$sessionId = trim((string)($data['session_id'] ?? ''));
$claims = claim_pages_after_login((int)$user['id'], $sessionId !== '' ? $sessionId : null, $email);

api_json([
    'user' => [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'daily_quota' => (int)$user['daily_quota'],
        'credits' => (int)$user['credits'],
    ],
    'claims' => $claims,
]);
