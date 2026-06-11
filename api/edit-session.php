<?php
require_once __DIR__ . '/../includes/page_edit.php';

require_method('POST');
$data = json_input();
$slug = trim($data['slug'] ?? '');
if (!preg_match('/^[a-z0-9]{10}$/', $slug)) api_error('bad_slug', 'Invalid page slug');

$page = db_one('SELECT * FROM pages WHERE slug = ? AND status = ?', [$slug, 'live']);
if (!$page) api_error('not_found', 'Page not found', 404);
if (!current_user_can_edit_page($page)) api_error('forbidden', '你没有权限修改这个页面。', 403);

$sessionId = create_page_edit_session($page);
api_json(['session_id' => $sessionId]);
