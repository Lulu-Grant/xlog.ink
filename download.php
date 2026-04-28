<?php

require_once __DIR__ . '/config.php';

maybe_cleanup_runtime_storage(app_config('downloads_ttl'));

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
$payload = $token === '' ? null : get_download_payload($token);

if ($payload === null || empty($payload['path']) || !is_file($payload['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File not found or expired.';
    exit;
}

$filePath = $payload['path'];
$downloadName = $payload['name'] ?? basename($filePath);
$mimeType = $payload['mime'] ?? 'application/octet-stream';
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'download.bin';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');

readfile($filePath);
