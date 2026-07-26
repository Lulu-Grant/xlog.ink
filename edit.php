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

// G10: if viewer is logged in with matching email, silently claim orphan page.
$userId = current_user_id();
if ($userId) {
    claim_page_for_user((string)$page['slug'], (int)$userId, ['email_match' => true]);
    // Reload page row after possible claim.
    $page = db_one('SELECT * FROM pages WHERE slug = ?', [$page['slug']]) ?: $page;
}

$sessionId = create_page_edit_session($page, 'edit_token');
// Bind edit session to logged-in user when possible.
if ($userId) {
    bind_session_to_user($sessionId, (int)$userId);
}
header('Location: /index.php?edit_session=' . urlencode($sessionId));
exit;
