<?php
require_once __DIR__ . '/../includes/imageproc.php';
require_once __DIR__ . '/../includes/quota.php';

require_method('POST');
$locale = resolve_locale($_POST['locale'] ?? null);
set_locale_cookie($locale);
$sessionId = trim($_POST['session_id'] ?? '');
$caption = trim((string)($_POST['caption'] ?? ''));
if (mb_strlen($caption, 'UTF-8') > 200) {
    $caption = mb_substr($caption, 0, 200, 'UTF-8');
}
$slot = normalize_image_slot($_POST['slot'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenUploadSession', $locale), 403);
if (empty($_FILES['file'])) api_error('missing_file', 'No file uploaded');

$charge = consume_quota('upload_image');
if (!$charge['ok']) api_error('upload_quota_exceeded', t('api', 'uploadExceeded', $locale), 429);

try {
    $result = image_process_upload($sessionId, $_FILES['file'], $caption, $slot);
    $slotText = $slot !== '' ? ' 版位: ' . $slot : '';
    $msg = '[图片已上传: ' . $result['url'] . '] 说明: ' . ($caption !== '' ? $caption : '无') . $slotText;
    append_session_message($sessionId, 'user', $msg);
    api_json($result);
} catch (Throwable $e) {
    refund_quota('upload_image', $charge);
    api_error('upload_failed', $e->getMessage(), 400);
}

function normalize_image_slot($slot) {
    $slot = strtolower(trim((string)$slot));
    return in_array($slot, ['hero', 'avatar', 'product', 'gallery'], true) ? $slot : '';
}
