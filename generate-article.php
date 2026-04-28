<?php
// generate-article.php — 文章模式：Markdown 生成为静态页（前端 marked.js 渲染）
// 依赖：includes/*、/partials/footer.html

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/ratelimit.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/response.php';
require_once __DIR__ . '/includes/turnstile.php';

/* -------------------- 仅 POST -------------------- */
ensure_post();

/* -------------------- 读取表单 -------------------- */
$title    = trim($_POST['title'] ?? '');
$md       = trim($_POST['markdown_body'] ?? '');
$themeRaw = validate_theme($_POST['theme'] ?? 'light');
$uiLang   = validate_lang($_POST['ui_lang'] ?? 'zh-CN');
$isAdult  = !empty($_POST['is_adult']);

if ($title === '') { http_response_code(400); exit('Title required'); }
$title = clamp_len_u($title, 30);
if (mb_strlen($md, 'UTF-8') > 20000) $md = mb_substr($md, 0, 20000, 'UTF-8');

/* -------------------- 限流 -------------------- */
$rl = check_rate_limit();
if ($rl['exceeded']) {
    render_rate_limit_page($uiLang, '/creat-article.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw));
}
ensure_turnstile_or_render($uiLang, '/creat-article.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw));

/* -------------------- 生成文件 -------------------- */
$outDir = __DIR__ . '/site';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);

$info    = generate_slug($outDir);
$slug    = $info['slug'];
$path    = $info['path'];
$subUrl  = 'https://' . $slug . '.xlog.ink/';
$desc    = markdown_excerpt($md, 120);
if ($desc === '') $desc = $title;
$nowIso  = gmdate('c');

$pageLabels = generated_page_labels($uiLang);
$themeCls  = theme_class($themeRaw);
$titleH    = h($title);
$nowIsoH   = h($nowIso);
$genLabel  = h($pageLabels['generatedAt']);
$articleHtml = markdown_to_html($md);

/* -------------------- Footer（直接内嵌） -------------------- */
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
    'og_type' => 'article',
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
    </div>
    <article class="ui-card min-h-120" id="article-body">{$articleHtml}</article>
    <div class="ui-card">
      <div class="text-help">{$genLabel}: <span class="text-code" title="UTC ISO8601">{$nowIsoH}</span></div>
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
record_page_index($slug, $title, $nowIso, $uiLang, 'article', $isAdult);

/* -------------------- 成功页 -------------------- */
$backUrl = '/creat-article.php?lang=' . urlencode($uiLang) . '&theme=' . urlencode($themeRaw);
render_success_page($uiLang, $subUrl, $backUrl);
