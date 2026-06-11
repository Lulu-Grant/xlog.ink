<?php
require_once __DIR__ . '/../../includes/mailer.php';

require_method('POST');
xlog_start_session();
$data = json_input();
$email = normalize_email($data['email'] ?? '');
$code = trim($data['code'] ?? '');
if ($email === '' || !preg_match('/^\d{6}$/', $code)) api_error('bad_request', 'Email and 6-digit code required');

$row = db_one('SELECT rowid, * FROM login_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1', [$email]);
if (!$row || strtotime($row['expires_at']) < time() || (int)$row['attempts'] >= 5) {
    api_error('invalid_code', 'Code expired or invalid', 400);
}
if (!password_verify($code, $row['code_hash'])) {
    db_exec('UPDATE login_codes SET attempts = attempts + 1 WHERE rowid = ?', [$row['rowid']]);
    api_error('invalid_code', 'Invalid code', 400);
}
db_exec('DELETE FROM login_codes WHERE email = ?', [$email]);

$user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
if (!$user) {
    db_exec('INSERT INTO users (email, created_at) VALUES (?, ?)', [$email, now_iso()]);
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
}
$_SESSION['user_id'] = (int)$user['id'];
api_json(['user' => ['id' => (int)$user['id'], 'email' => $user['email'], 'daily_quota' => (int)$user['daily_quota'], 'credits' => (int)$user['credits']]]);
