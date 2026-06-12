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
    $locale = resolve_locale();
    $title = h(t('app', 'previewWaitingTitle', $locale));
    $body = h(t('app', 'previewWaitingBody', $locale));
    $aria = h(t('app', 'previewAria', $locale));
    return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 50% 38%,rgba(124,92,255,.18),transparent 34%),linear-gradient(rgba(57,255,182,.06) 1px,transparent 1px),#0b0c10;background-size:auto,100% 14px,auto;color:#ecfdf8;font:14px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;padding:24px}.wrap{display:grid;place-items:center;gap:10px;text-align:center}.copy{display:grid;gap:4px;max-width:360px}.copy span{color:rgba(236,253,248,.66);font-size:12px}.orbit{width:min(82vw,420px);height:auto;overflow:visible}.grid{fill:rgba(255,255,255,.035);stroke:rgba(236,253,248,.16)}.wire{fill:none;stroke:url(#g);stroke-width:3;stroke-linecap:round;stroke-dasharray:10 18;animation:flow 1.1s linear infinite}.b{opacity:.7;animation-duration:1.7s;animation-direction:reverse}.core circle{fill:rgba(124,92,255,.16);stroke:url(#g);stroke-width:2;transform-origin:260px 130px;animation:pulse 1.8s ease-in-out infinite}.core path,.node path{fill:none;stroke:#ecfdf8;stroke-width:3;stroke-linecap:round}.node circle{fill:#111218;stroke:#39ffb6;stroke-width:2}.node{transform-box:fill-box;transform-origin:center;animation:node 1.6s ease-in-out infinite}.n2{animation-delay:.35s}.n3{animation-delay:.7s}.scan{fill:rgba(57,255,182,.09);stroke:rgba(57,255,182,.18);animation:scan 2.2s ease-in-out infinite}@keyframes flow{to{stroke-dashoffset:-56}}@keyframes pulse{50%{transform:scale(1.08)}}@keyframes node{50%{transform:scale(1.22)}}@keyframes scan{0%,100%{transform:translateY(0);opacity:.35}50%{transform:translateY(154px);opacity:.9}}
  </style>
</head>
<body>
  <div class="wrap">
    <svg class="orbit" viewBox="0 0 520 260" role="img" aria-label="{$aria}">
      <defs>
        <linearGradient id="g" x1="0%" x2="100%" y1="0%" y2="100%">
          <stop offset="0%" stop-color="#39ffb6"/>
          <stop offset="50%" stop-color="#7c5cff"/>
          <stop offset="100%" stop-color="#ffca6a"/>
        </linearGradient>
      </defs>
      <rect class="grid" x="22" y="18" width="476" height="224" rx="24"/>
      <path class="wire" d="M82 178 C134 70 246 78 286 128 S396 196 438 82"/>
      <path class="wire b" d="M72 92 C142 152 204 164 260 112 S370 56 452 138"/>
      <g class="core"><circle cx="260" cy="130" r="42"/><path d="M240 132h40M260 112v40M246 118l28 28M274 118l-28 28"/></g>
      <g class="node"><circle cx="86" cy="178" r="12"/><path d="M80 178h12"/></g>
      <g class="node n2"><circle cx="438" cy="82" r="12"/><path d="M432 82h12"/></g>
      <g class="node n3"><circle cx="452" cy="138" r="10"/><path d="M447 138h10"/></g>
      <rect class="scan" x="42" y="34" width="436" height="36" rx="18"/>
    </svg>
    <div class="copy">
      <strong>{$title}</strong>
      <span>{$body}</span>
    </div>
  </div>
</body>
</html>
HTML;
}
