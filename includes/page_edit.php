<?php
// Shared helpers for safe page edit sessions.

require_once __DIR__ . '/db.php';

function page_public_url($slug) {
    return 'https://' . $slug . '.xlog.ink/';
}

function clean_generated_html_for_edit($html) {
    $html = (string)$html;
    $html = preg_replace('/<div style="position:fixed;right:12px;bottom:12px;.*?Made with xlog\.ink.*?<\/div>\s*/is', '', $html);
    $html = preg_replace('/<style>\s*\.adult-gate--locked.*?<\/style>\s*/is', '', $html);
    $html = preg_replace('/<script>\s*\(function\(\)\{.*?adult-gate.*?<\/script>\s*/is', '', $html);
    $html = preg_replace('/<div class="adult-gate".*?<\/div>\s*<\/div>\s*/is', '', $html);
    $html = preg_replace('/\sclass="([^"]*)adult-gate--enabled adult-gate--locked([^"]*)"/i', ' class="$1$2"', $html);
    $html = preg_replace('/\sdata-adult-key="[^"]*"/i', '', $html);
    return trim($html);
}

function page_edit_seed_messages(array $page) {
    $html = '';
    if (!empty($page['html_path']) && is_file($page['html_path'])) {
        $html = file_get_contents($page['html_path']);
    }
    $cleanHtml = clean_generated_html_for_edit($html);
    if (mb_strlen($cleanHtml, 'UTF-8') > 18000) {
        $cleanHtml = mb_substr($cleanHtml, 0, 18000, 'UTF-8') . "\n<!-- 当前 HTML 已截断，保留了开头结构与主要内容 -->";
    }
    $title = $page['title'] ?: $page['slug'];
    return [
        [
            'role' => 'assistant',
            'content' => '这是你的「' . $title . '」页面。告诉我你想修改哪里，我会基于当前页面重新生成并覆盖原地址。',
            'ts' => now_iso(),
        ],
        [
            'role' => 'user',
            'content' => "[当前页面信息]\nURL: " . page_public_url($page['slug']) . "\n标题: " . $title . "\n类型: " . ($page['type'] ?: 'page') . "\n\n[当前页面 HTML]\n" . $cleanHtml,
            'ts' => now_iso(),
        ],
    ];
}

function create_page_edit_session(array $page, $mode) {
    if (!in_array($mode, ['edit_owner', 'edit_token'], true)) {
        throw new InvalidArgumentException('Invalid edit mode');
    }
    return create_session($page['slug'], page_edit_seed_messages($page), $mode);
}

function current_user_can_edit_page(array $page) {
    $userId = current_user_id();
    return $userId && !empty($page['owner_user_id']) && (int)$page['owner_user_id'] === (int)$userId;
}
