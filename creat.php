<?php
// creat.php — 链接模式生成器表单（多语言 + 主题切换 + 前端校验）
// 依赖：/assets/css/base.css, /assets/js/i18n.js, /assets/js/app.js, /generate.php
require_once __DIR__ . '/includes/turnstile.php';
$turnstileSiteKey = h(turnstile_site_key());
$createPageCsp = h(build_csp('create-page'));
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>页面生成 - XLOG</title>
  <meta name="description" content="在 XLOG 一鍵生成你的個人主頁：輸入標題、簡介與連結，選擇主題與語言，即可獲得免費二級網域的個人頁面。頁面生成後永久不刪除。">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://xlog.ink/creat.php">

  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f1115">

  <meta property="og:site_name" content="XLOG">
  <meta property="og:type" content="website">
  <meta property="og:title" content="页面生成 - XLOG">
  <meta property="og:description" content="在 XLOG 一鍵生成你的個人主頁。頁面生成後永久不刪除。">
  <meta property="og:url" content="https://xlog.ink/creat.php">
  <meta property="og:image" content="/assets/og/cover.jpg">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="页面生成 - XLOG">
  <meta name="twitter:description" content="在 XLOG 一鍵生成你的個人主頁。頁面生成後永久不刪除。">
  <meta name="twitter:image" content="/assets/og/cover.jpg">
  <meta http-equiv="Content-Security-Policy" content="<?php echo $createPageCsp; ?>">
  <meta name="referrer" content="strict-origin-when-cross-origin">

  <link rel="preload" href="/assets/css/base.css" as="style">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico" sizes="180x180">
  <link rel="manifest" href="/site.webmanifest">
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

  <style>
    [data-i18n]:empty::after { content: attr(data-i18n); opacity: 0; }
    html, body { overflow-x: hidden; }
    .ui-card h2 { display: flex; align-items: baseline; gap: 8px; }
    input, textarea { -webkit-tap-highlight-color: transparent; }
  </style>

  <script type="application/ld+json">
  {
    "@context":"https://schema.org",
    "@type":"WebApplication",
    "name":"XLOG — 個人主頁生成器",
    "applicationCategory":"BusinessApplication",
    "operatingSystem":"Web",
    "url":"https://xlog.ink/creat.php",
    "inLanguage":["zh-TW","zh-CN","en"],
    "offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},
    "featureList":["免費二級網域","行動優先設計","暗/明主題","三語介面（繁體/簡體/英文）","頁面生成後永久不刪除"],
    "publisher":{"@type":"Organization","name":"XLOG","url":"https://xlog.ink/"}
  }
  </script>
</head>
<body class="theme-dark page-create mode-link">
<div class="container">
  <header class="page-header" aria-label="Site header">
    <div class="page-header__title">
      <a href="https://xlog.ink" class="logo-link" aria-label="返回首頁">
        <span data-i18n="appTitle">XLOG</span>
      </a>
    </div>
    <div class="page-header__controls" role="group" aria-label="偏好設置">
      <label>
        <span class="text-help" data-i18n="themeLabel">主題</span>
        <select id="theme" aria-label="Theme">
          <option value="light" data-i18n="light">明亮</option>
          <option value="dark" data-i18n="dark" selected>暗色</option>
        </select>
      </label>
      <label>
        <span class="text-help" data-i18n="langLabel">語言</span>
        <select id="lang" aria-label="Language">
          <option value="zh-TW" selected>繁體中文</option>
          <option value="zh-CN">简体中文</option>
          <option value="en">English</option>
        </select>
      </label>
    </div>
  </header>
  <main class="site-main">

  <section class="page-intro">
    <div class="hero-kicker" data-i18n="linkIntroKicker">Link Page · Fast Publish</div>
    <h1 data-i18n="linkIntroTitle">建立一個乾淨、可分享的連結頁</h1>
    <p data-i18n="linkIntroDesc">填好標題、簡介與連結，立即生成一個穩定的個人頁面，適合社交入口、履歷和活動頁。</p>
    <div class="meta-strip">
      <span class="ui-pill" data-i18n="linkMeta1">免費二級網域</span>
      <span class="ui-pill" data-i18n="linkMeta2">提交後永久保留</span>
      <span class="ui-pill" data-i18n="linkMeta3">支援三語界面</span>
    </div>
  </section>

  <!-- 模式切换 -->
  <div class="page-section ui-card ui-card--switcher page-section--tight-top">
    <div class="mode-tabs" role="tablist" aria-label="Create mode">
      <a id="tab-links" role="tab" class="button button--ghost" data-i18n="tabLinks">連結模式</a>
      <a id="tab-article" role="tab" class="button button--ghost" data-i18n="tabArticle">文章模式（Markdown）</a>
    </div>
  </div>

  <noscript>
    <div class="page-section ui-card ui-card--warning">
      ⚠️ 需要開啟 JavaScript 才能正常使用。
    </div>
  </noscript>

  <!-- 表单 -->
  <form id="form" class="page-section ui-card editor-frame form-stack" action="/generate.php" method="post" novalidate>
    <input type="hidden" name="theme" id="hidden_theme" value="dark">
    <input type="hidden" name="ui_lang" id="hidden_ui_lang" value="zh-TW">

    <div class="text-help form-intro-note" data-i18n="tipLimits">標題≤30字；正文≤500字；最多 20 條連結；URL 需以 http/https 開頭。</div>

    <div class="form-block">
      <h2 data-i18n="title">標題（必填，≤30字）</h2>
      <input id="title" name="title" type="text" maxlength="30" required aria-required="true"
             aria-describedby="titleHelp" placeholder="">
      <div id="titleHelp" class="text-help" data-i18n="titleHelp">請輸入頁面標題，不超過 30 個字元。</div>
    </div>

    <div class="form-block">
      <h2 data-i18n="body">文字模塊（≤500字，可留空）</h2>
      <textarea id="body" name="body" rows="6" maxlength="500" placeholder=""
                aria-describedby="bodyHelp"></textarea>
      <div id="bodyHelp" class="text-help" data-i18n="bodyHelp">可補充個人介紹、活動說明或頁面摘要。</div>
    </div>

    <div class="form-block">
      <h2 data-i18n="links">連結模塊（1–20 條）</h2>
      <div id="links" class="form-row" aria-live="polite"></div>
      <div class="action-group">
        <button type="button" id="add-link" class="button button--ghost" data-i18n="addLink">新增連結</button>
      </div>
    </div>

    <div class="form-block adult-check">
      <label class="adult-check-label" for="is_adult">
        <input id="is_adult" name="is_adult" type="checkbox" value="1">
        <span>
          <strong data-i18n="adultContentLabel">此頁面包含 18+ 成人內容</strong>
          <small class="text-help" data-i18n="adultContentHelp">勾選後，訪客首次打開該頁面時會先看到 18+ 確認提示。</small>
        </span>
      </label>
    </div>

    <div class="form-actions-shell">
      <div class="form-block">
      <h2 data-i18n="verificationTitle">安全驗證</h2>
      <div class="text-help" data-i18n="verificationHelp">提交前請完成人機驗證，避免濫用與批量生成。</div>
      <div class="turnstile-wrap">
        <div class="cf-turnstile"
             data-sitekey="<?php echo $turnstileSiteKey; ?>"
             data-theme="auto"
             data-language="auto"></div>
      </div>
      </div>

      <div class="action-group">
        <button class="button button--accent" type="submit" data-i18n="generate">免費生成個人主頁</button>
      </div>
    </div>

  </form>
  </main>
  <div id="footer-slot" class="footer-slot"></div>
</div>

<!-- 统一 i18n + 公共 UI -->
<script src="/assets/js/i18n.js"></script>
<script src="/assets/js/app.js"></script>
<script>
(function () {
  XLOG.initState();
  XLOG.applyLang(XLOG.curLang);
  XLOG.applySEO('seoTitle_links', 'seoDesc_links');
  XLOG.bindControls({
    onLangChange: function (lang) {
      XLOG.applySEO('seoTitle_links', 'seoDesc_links');
      XLOG.refreshLinkI18N(lang);
    }
  });
  XLOG.bindTabs('link');
  XLOG.loadFooter();
  XLOG.initLinkMode();

  // 移动端长 URL 换行保护
  document.addEventListener('input', function (e) {
    if (e.target && e.target.name === 'link_url[]') {
      e.target.style.wordBreak = 'break-all';
      e.target.style.overflowWrap = 'anywhere';
    }
  });
})();
</script>
</body>
</html>
