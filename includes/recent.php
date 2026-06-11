<?php
// Build recent.html from SQLite.

require_once __DIR__ . '/db.php';

function build_recent_html_file() {
    $rows = db_all(
        'SELECT slug, title, created_at, updated_at, type, is_adult FROM pages WHERE status = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 100',
        ['live']
    );

    $items = '';
    foreach ($rows as $row) {
        $url = 'https://' . $row['slug'] . '.xlog.ink/';
        $badge = !empty($row['is_adult']) ? ' <span class="recent-badge">18+</span>' : '';
        $type = h($row['type'] ?: 'page');
        $date = h(substr(($row['updated_at'] ?: $row['created_at']) ?: '', 0, 10));
        $items .= '<li><a href="' . h($url) . '" target="_blank" rel="noopener">' . h($row['title']) . '</a>'
            . $badge . '<span class="recent-meta">' . $type . ' · ' . $date . '</span></li>' . "\n";
    }

    if ($items === '') {
        $items = '<li><span class="recent-meta">暂无公开页面</span></li>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>近期生成页面 - xlog.ink</title>
  <meta name="description" content="浏览最近公开生成的 xlog.ink 页面。">
  <link rel="canonical" href="https://xlog.ink/recent.html">
  <style>
    body{margin:0;background:#f7f6f0;color:#27241f;font-family:"Avenir Next","Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
    .recent-shell{max-width:860px;margin:0 auto;padding:48px 18px}
    .recent-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:28px}
    .recent-head a{color:inherit;text-decoration:none;font-weight:900}
    h1{font-size:clamp(34px,7vw,72px);line-height:.95;margin:0 0 26px}
    .recent-list{display:grid;gap:10px;list-style:none;margin:0;padding:0}
    .recent-list li{display:grid;gap:6px;border:1px solid #e5e0d7;background:#fffefa;border-radius:8px;padding:16px}
    .recent-list a{color:#27241f;font-weight:800;text-decoration:none}
    .recent-meta{color:#777168;font-size:13px}
    .recent-badge{display:inline-flex;margin-left:8px;padding:2px 7px;border-radius:999px;background:#111;color:#fff;font-size:12px;font-weight:800}
  </style>
</head>
<body>
  <main class="recent-shell">
    <header class="recent-head">
      <a href="/">xlog.ink</a>
      <a href="/index.php">创建页面</a>
    </header>
    <h1>近期生成页面</h1>
    <ol class="recent-list">
{$items}
    </ol>
  </main>
</body>
</html>
HTML;

    file_put_contents(XLOG_ROOT . '/recent.html', $html, LOCK_EX);
    return count($rows);
}
