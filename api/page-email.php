<?php
require_once __DIR__ . '/../includes/mailer.php';

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$sessionId = trim($data['session_id'] ?? '');
$email = normalize_email($data['email'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($email === '') api_error('bad_email', 'Invalid email');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session || empty($session['page_slug'])) api_error('page_not_found', 'No published page for this session', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenEmailSession', $locale), 403);

$slug = $session['page_slug'];
$page = db_one('SELECT * FROM pages WHERE slug = ?', [$slug]);
if (!$page) api_error('page_not_found', 'No published page for this session', 404);
$mailLocale = normalize_locale($page['lang'] ?? '') ?: $locale;
$limitKey = $email . ':' . $slug;
if (mail_recently_sent('edit-link', $limitKey, 600)) {
    api_error('too_frequent', t('api', 'editMailTooFrequent', $locale), 429);
}
try {
    send_page_edit_link($page, $email, $mailLocale, $limitKey);
} catch (Throwable $e) {
    error_log('edit link send failed: ' . $e->getMessage());
    api_error('mail_send_failed', t('api', 'editMailFailed', $locale), 502);
}
append_session_message($sessionId, 'system', '[系统事件] 页面修改链接已发送到 ' . $email . '。');
api_json(['ok' => true]);
