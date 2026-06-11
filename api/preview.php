<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$sessionId = trim($_GET['session_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
    http_response_code(404);
    exit;
}

$path = xlog_config('data_dir') . '/previews/' . $sessionId . '.html';
$html = is_file($path) ? file_get_contents($path) : preview_waiting_html();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header("Content-Security-Policy: default-src 'none'; img-src data: https://xlog.ink; style-src 'unsafe-inline'; font-src data:; script-src 'none'; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
echo $html;

function preview_waiting_html() {
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0c10;color:#b6ffe7;font:14px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;padding:24px}
  </style>
</head>
<body>等待 HTML 流进入预览通道...</body>
</html>
HTML;
}
