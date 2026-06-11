<?php
require_once __DIR__ . '/includes/page_edit.php';

$token = trim($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    echo 'Invalid edit link';
    exit;
}
$page = db_one('SELECT * FROM pages WHERE token_hash = ? AND editable = 1 AND status = ?', [hash('sha256', $token), 'live']);
if (!$page) {
    http_response_code(404);
    echo 'Edit link not found';
    exit;
}
$sessionId = create_page_edit_session($page);
header('Location: /index.php?edit_session=' . urlencode($sessionId));
exit;
