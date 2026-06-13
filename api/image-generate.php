<?php
require_once __DIR__ . '/../includes/imageproc.php';
require_once __DIR__ . '/../includes/quota.php';

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$sessionId = trim($data['session_id'] ?? '');
$prompt = trim((string)($data['prompt'] ?? ''));
$slot = normalize_generated_image_slot($data['slot'] ?? 'hero');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($prompt === '') api_error('bad_prompt', t('api', 'imagePromptRequired', $locale));
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenUploadSession', $locale), 403);

$charge = consume_quota('image_generate');
if (!$charge['ok']) api_error('image_quota_exceeded', t('api', 'imageGenerateExceeded', $locale), 429);

try {
    $result = image_create_generated_placeholder($sessionId, $prompt, $slot);
    append_session_message($sessionId, 'user', '[图片已生成: ' . $result['url'] . '] 说明: ' . $prompt . ' 版位: ' . $slot);
    api_json($result);
} catch (Throwable $e) {
    refund_quota('image_generate', $charge);
    api_error('image_generate_failed', $e->getMessage(), 400);
}

function normalize_generated_image_slot($slot) {
    $slot = strtolower(trim((string)$slot));
    return in_array($slot, ['hero', 'avatar', 'product', 'gallery'], true) ? $slot : 'hero';
}
