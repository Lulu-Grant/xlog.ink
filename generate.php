<?php
// generate.php — 链接模式：生成静态 HTML 到 /site/XXXXXXXXXX.html
// 依赖：includes/*、/partials/footer.html

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/ratelimit.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/turnstile.php';

/* -------------------- 仅 POST -------------------- */
ensure_post();

/* -------------------- 读取表单 -------------------- */
$title   = trim($_POST['title'] ?? '');
$body    = trim($_POST['body'] ?? '');
$themeRaw = validate_theme($_POST['theme'] ?? 'light');
$uiLang  = validate_lang($_POST['ui_lang'] ?? 'zh-CN');
$names   = (array)($_POST['link_name'] ?? []);
$urls    = (array)($_POST['link_url'] ?? []);
$isAdult = !empty($_POST['is_adult']);

if ($title === '') { http_response_code(400); exit('Title required'); }
$title = clamp_len_u($title, 30);
if (mb_strlen($body, 'UTF-8') > 500) $body = mb_substr($body, 0, 500, 'UTF-8');

// 清洗链接
$links = [];
for ($i = 0; $i < count($names); $i++) {
    $n = trim($names[$i] ?? '');
    $u = trim($urls[$i] ?? '');
    if ($n === '' && $u === '') continue;
    if ($n === '' || mb_strlen($n, 'UTF-8') > 30) continue;
    if (!preg_match('/^https?:\\/\\//i', $u)) continue;
    $links[] = ['name' => $n, 'url' => $u];
}
if (count($links) < 1) { http_response_code(400); exit('At least 1 link'); }
if (count($links) > 20) $links = array_slice($links, 0, 20);

/* -------------------- 限流 -------------------- */
$rl = check_rate_limit();
if ($rl['exceeded']) {
    render_rate_limit_page($uiLang, '/creat.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw));
}
ensure_turnstile_or_render($uiLang, '/creat.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw));

/* -------------------- 生成文件 -------------------- */
$outDir = __DIR__ . '/site';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);

$info   = generate_slug($outDir);
$slug   = $info['slug'];
$path   = $info['path'];
$subUrl = 'https://' . $slug . '.xlog.ink/';
$desc   = $body !== '' ? excerpt_plain_text($body, 120) : $title;
$nowIso = gmdate('c');

$pageLabels = generated_page_labels($uiLang);
$themeCls  = theme_class($themeRaw);
$titleH    = h($title);
$nowIsoH   = h($nowIso);
$genLabel  = h($pageLabels['generatedAt']);
$linksLabel = h($pageLabels['links']);

/* -------------------- 构建链接 HTML -------------------- */
$linksHtml = '';
foreach ($links as $lk) {
    $linksHtml .= '<a class="list-item list-item--link" href="' . h($lk['url']) . '" target="_blank" rel="nofollow noopener">'
                . '<strong>' . h($lk['name']) . '</strong>'
                . '<span>' . h($lk['url']) . '</span>'
                . '</a>';
}
$bodyHtml = $body !== '' ? nl2br(h($body)) : '';

/* -------------------- Footer（直接内嵌，不再运行时 fetch） -------------------- */
$footerHtml = get_footer_html();
$adultGate = build_adult_gate_parts($uiLang, $slug, $isAdult);
$adultKey = h($adultGate['adult_key']);
$adultGateBoot = $adultGate['boot_html'];
$adultGateBodyBoot = $adultGate['body_boot_html'] ?? '';
$adultGateHtml = $adultGate['gate_html'];
$bodyClass = trim($themeCls . $adultGate['body_class_suffix']);
$runtimeHtml = build_generated_page_runtime_html();

/* -------------------- 构建静态页 HTML -------------------- */
$doc = build_generated_head_html([
    'title' => $title,
    'description' => $desc,
    'canonical' => $subUrl,
    'lang' => $uiLang,
    'og_type' => 'website',
]) . <<<HTML

{$adultGateBoot}
</head>
<body class="page-generated generated-page {$bodyClass}" data-adult-key="{$adultKey}">
{$adultGateBodyBoot}
{$adultGateHtml}
  <div class="page-bg-grid" aria-hidden="true"></div>
  <div class="page-shell">
  <div class="container">
HTML;

$doc .= <<<HTML
    <div class="generated-heading">
      <h1>{$titleH}</h1>
HTML;

if ($bodyHtml !== '') {
    $doc .= '<div class="generated-summary">' . $bodyHtml . '</div>';
}

$doc .= <<<HTML
    </div>
    <div class="ui-card">
      <h2>{$linksLabel}</h2>
      <div class="links">{$linksHtml}</div>
      <div class="text-help meta-note">{$genLabel}: <span class="text-code" title="UTC ISO8601">{$nowIsoH}</span></div>
    </div>
    <div class="footer-slot">{$footerHtml}</div>
  </div>
  </div>
  {$runtimeHtml}
</body>
</html>
HTML;

if (file_put_contents($path, $doc) === false) {
    http_response_code(500);
    exit('Write failed');
}

/* -------------------- 记账 + 索引 -------------------- */
record_rate_event($rl['ipFile'], $rl['events'], $rl['now']);
record_page_index($slug, $title, $nowIso, $uiLang, 'link', $isAdult);

/* -------------------- 成功页 -------------------- */
$backUrl = '/creat.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw);
render_success_page($uiLang, $subUrl, $backUrl);
