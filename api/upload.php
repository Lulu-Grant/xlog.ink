<?php
require_once __DIR__ . '/../includes/imageproc.php';

require_method('POST');
$sessionId = trim($_POST['session_id'] ?? '');
$caption = trim($_POST['caption'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if (!db_one('SELECT id FROM sessions WHERE id = ?', [$sessionId])) api_error('session_not_found', 'Session not found', 404);
if (empty($_FILES['file'])) api_error('missing_file', 'No file uploaded');

try {
    $result = image_process_upload($sessionId, $_FILES['file'], $caption);
    $msg = '[图片已上传: ' . $result['url'] . '] 说明: ' . ($caption !== '' ? $caption : '无');
    append_session_message($sessionId, 'user', $msg);
    api_json($result);
} catch (Throwable $e) {
    api_error('upload_failed', $e->getMessage(), 400);
}
