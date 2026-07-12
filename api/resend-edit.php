<?php
require_once __DIR__ . '/../includes/mailer.php';

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$email = normalize_email($data['email'] ?? '');
$slug = trim($data['slug'] ?? '');
if ($email === '' || !preg_match('/^[a-z0-9]{3,10}$/', $slug)) api_error('bad_request', 'Email and slug required');
$page = db_one('SELECT * FROM pages WHERE slug = ? AND email = ? AND editable = 1', [$slug, $email]);
if (!$page) api_error('not_found', 'No editable page found', 404);
$limitKey = $email . ':' . $slug;
if (mail_recently_sent('edit-link', $limitKey, 600)) {
    api_error('too_frequent', t('api', 'editMailResendTooFrequent', $locale), 429);
}
try {
    send_page_edit_link($page, $email, normalize_locale($page['lang'] ?? '') ?: $locale, $limitKey);
} catch (Throwable $e) {
    error_log('edit link resend failed: ' . $e->getMessage());
    api_error('mail_send_failed', t('api', 'editMailFailed', $locale), 502);
}
api_json(['ok' => true]);
