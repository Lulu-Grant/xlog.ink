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
    $locale = normalize_locale($page['lang'] ?? '') ?: resolve_locale();
    $html = '';
    if (!empty($page['html_path']) && is_file($page['html_path'])) {
        $html = file_get_contents($page['html_path']);
    }
    $cleanHtml = clean_generated_html_for_edit($html);
    if (mb_strlen($cleanHtml, 'UTF-8') > 18000) {
        $truncated = $locale === 'en'
            ? "\n<!-- Current HTML was truncated; the beginning structure and main content were kept. -->"
            : ($locale === 'zh-TW'
                ? "\n<!-- 目前 HTML 已截斷，保留了開頭結構與主要內容 -->"
                : "\n<!-- 当前 HTML 已截断，保留了开头结构与主要内容 -->");
        $cleanHtml = mb_substr($cleanHtml, 0, 18000, 'UTF-8') . $truncated;
    }
    $title = $page['title'] ?: $page['slug'];
    if ($locale === 'en') {
        $intro = 'This is your "' . $title . '" page. Tell me what you want to change, and I will regenerate it based on the current page while keeping the same URL.';
        $info = "[Current page info]\nURL: " . page_public_url($page['slug']) . "\nTitle: " . $title . "\nType: " . ($page['type'] ?: 'page') . "\n\n[Current page HTML]\n" . $cleanHtml;
    } elseif ($locale === 'zh-TW') {
        $intro = '這是你的「' . $title . '」頁面。告訴我你想修改哪裡，我會基於目前頁面重新生成並覆蓋原地址。';
        $info = "[目前頁面資訊]\nURL: " . page_public_url($page['slug']) . "\n標題: " . $title . "\n類型: " . ($page['type'] ?: 'page') . "\n\n[目前頁面 HTML]\n" . $cleanHtml;
    } else {
        $intro = '这是你的「' . $title . '」页面。告诉我你想修改哪里，我会基于当前页面重新生成并覆盖原地址。';
        $info = "[当前页面信息]\nURL: " . page_public_url($page['slug']) . "\n标题: " . $title . "\n类型: " . ($page['type'] ?: 'page') . "\n\n[当前页面 HTML]\n" . $cleanHtml;
    }
    return [
        [
            'role' => 'assistant',
            'content' => $intro,
            'ts' => now_iso(),
        ],
        [
            'role' => 'user',
            'content' => $info,
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
