<?php
require_once __DIR__ . '/../../includes/mailer.php';

require_method('POST');
$data = json_input();
$email = normalize_email($data['email'] ?? '');
if ($email === '') api_error('bad_email', 'Invalid email');

$recent = db_one('SELECT created_at FROM login_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1', [$email]);
if ($recent && strtotime($recent['created_at']) > time() - 60) {
    api_error('too_frequent', 'Please wait before requesting another code', 429);
}
$todayCount = db_one('SELECT COUNT(*) AS c FROM login_codes WHERE email = ? AND substr(created_at, 1, 10) = ?', [$email, utc_date()]);
if ($todayCount && (int)$todayCount['c'] >= 10) {
    api_error('daily_limit', '今日验证码发送次数已达上限', 429);
}

$code = (string)random_int(100000, 999999);
$hash = password_hash($code, PASSWORD_DEFAULT);
db_exec('INSERT INTO login_codes (email, code_hash, expires_at, attempts, created_at) VALUES (?, ?, ?, 0, ?)', [$email, $hash, gmdate('c', time() + 300), now_iso()]);
send_mail_template($email, 'login-code', ['code' => $code]);
api_json(['ok' => true]);
