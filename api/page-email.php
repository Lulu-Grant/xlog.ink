<?php
require_once __DIR__ . '/../includes/mailer.php';

require_method('POST');
$data = json_input();
$sessionId = trim($data['session_id'] ?? '');
$email = normalize_email($data['email'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($email === '') api_error('bad_email', 'Invalid email');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session || empty($session['page_slug'])) api_error('page_not_found', 'No published page for this session', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', '你不能绑定这个会话的邮箱。', 403);

$slug = $session['page_slug'];
$limitKey = $email . ':' . $slug;
if (mail_recently_sent('edit-link', $limitKey, 600)) {
    api_error('too_frequent', '同一页面的修改邮件 10 分钟内只能发送一次。', 429);
}
$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
db_exec('UPDATE pages SET email = ?, editable = 1, token_hash = ?, updated_at = ? WHERE slug = ?', [$email, $hash, now_iso(), $slug]);
$url = 'https://' . $slug . '.xlog.ink/';
$editUrl = rtrim(xlog_config('base_url'), '/') . '/edit.php?t=' . $token;
send_mail_template($email, 'edit-link', ['url' => $url, 'edit_url' => $editUrl]);
record_mail_event('edit-link', $limitKey);
append_session_message($sessionId, 'system', '[系统事件] 页面修改链接已发送到 ' . $email . '。');
api_json(['ok' => true]);
