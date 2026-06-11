<?php
require_once __DIR__ . '/../includes/page_edit.php';

require_method('POST');
$userId = current_user_id();
if (!$userId) api_error('not_logged_in', '请先登录后查看你的页面。', 401);

$rows = db_all(
    'SELECT slug, title, type, created_at, updated_at, editable, is_adult, status FROM pages WHERE owner_user_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 100',
    [$userId]
);

$pages = array_map(function ($row) {
    return [
        'slug' => $row['slug'],
        'title' => $row['title'],
        'type' => $row['type'],
        'url' => page_public_url($row['slug']),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'editable' => (bool)$row['editable'],
        'is_adult' => (bool)$row['is_adult'],
        'status' => $row['status'],
    ];
}, $rows);

api_json(['pages' => $pages]);
