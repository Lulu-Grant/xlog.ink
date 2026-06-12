<?php
require_once __DIR__ . '/../includes/mailer.php';

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$email = normalize_email($data['email'] ?? '');
$slug = trim($data['slug'] ?? '');
if ($email === '' || !preg_match('/^[a-z0-9]{10}$/', $slug)) api_error('bad_request', 'Email and slug required');
$page = db_one('SELECT * FROM pages WHERE slug = ? AND email = ? AND editable = 1', [$slug, $email]);
if (!$page) api_error('not_found', 'No editable page found', 404);
$limitKey = $email . ':' . $slug;
if (mail_recently_sent('edit-link', $limitKey, 600)) {
    api_error('too_frequent', t('api', 'editMailResendTooFrequent', $locale), 429);
}
$token = bin2hex(random_bytes(32));
$hash = hash('sha256', $token);
db_exec('UPDATE pages SET token_hash = ?, updated_at = ? WHERE slug = ?', [$hash, now_iso(), $slug]);
send_mail_template($email, 'edit-link', ['url' => 'https://' . $slug . '.xlog.ink/', 'edit_url' => rtrim(xlog_config('base_url'), '/') . '/edit.php?t=' . $token], normalize_locale($page['lang'] ?? '') ?: $locale);
record_mail_event('edit-link', $limitKey);
api_json(['ok' => true]);
