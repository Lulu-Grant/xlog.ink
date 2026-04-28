<?php
// creat-article.php — 文章模式（標題 + Markdown），EasyMDE + 預設色塊選色
// 依赖：/assets/css/base.css, /assets/js/i18n.js, /assets/js/app.js
require_once __DIR__ . '/includes/turnstile.php';
$turnstileSiteKey = h(turnstile_site_key());
$createPageCsp = h(build_csp('create-page'));
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title data-i18n="seoTitle_article">文章模式 - XLOG</title>
  <meta name="description" data-i18n="seoDesc_article" content="使用 Markdown 撰寫文章並生成你的個人頁面，支援多語與主題。">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="https://xlog.ink/creat-article.php">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f1115">
  <meta property="og:site_name" content="XLOG">
  <meta property="og:type" content="website">
  <meta property="og:title" content="文章模式 - XLOG">
  <meta property="og:description" content="使用 Markdown 撰寫文章並生成你的個人頁面，支援多語與主題。">
  <meta property="og:url" content="https://xlog.ink/creat-article.php">
  <meta property="og:image" content="/assets/og/cover.jpg">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="文章模式 - XLOG">
  <meta name="twitter:description" content="使用 Markdown 撰寫文章並生成你的個人頁面，支援多語與主題。">
  <meta name="twitter:image" content="/assets/og/cover.jpg">
  <meta http-equiv="Content-Security-Policy" content="<?php echo $createPageCsp; ?>">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="stylesheet" href="/assets/vendor/font-awesome/font-awesome.min.css">
  <link rel="icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico" sizes="180x180">
  <link rel="manifest" href="/site.webmanifest">
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

  <!-- EasyMDE -->
  <link rel="stylesheet" href="/assets/vendor/easymde/easymde.min.css">
  <script src="/assets/vendor/easymde/easymde.min.js"></script>

</head>
<body class="theme-dark page-create mode-article">
<div class="container">
  <header class="page-header" aria-label="Site header">
    <div class="page-header__title"><a href="/" class="logo-link"><img src="/assets/brand/xlog-mark.svg" alt="" class="logo-mark" aria-hidden="true"><span class="logo-wordmark">XLOG</span></a></div>
    <div class="page-header__controls">
      <label><span data-i18n="themeLabel">主題</span>
        <select id="theme">
          <option value="dark" data-i18n="dark">暗色</option>
          <option value="light" data-i18n="light">明亮</option>
        </select>
      </label>
      <label><span data-i18n="langLabel">語言</span>
        <select id="lang">
          <option value="zh-TW">繁體中文</option>
          <option value="zh-CN">简体中文</option>
          <option value="en">English</option>
        </select>
      </label>
    </div>
  </header>
  <main class="site-main">

  <section class="page-intro">
    <div class="hero-kicker" data-i18n="articleIntroKicker">Markdown Article · Fast Publish</div>
    <h1 data-i18n="articleIntroTitle">把文章整理成一個乾淨的靜態頁</h1>
    <p data-i18n="articleIntroDesc">使用 Markdown 編寫內容、預覽排版，再一鍵生成穩定可分享的文章頁，適合長文與公告。</p>
    <div class="meta-strip">
      <span class="ui-pill" data-i18n="articleMeta1">Markdown 編輯</span>
      <span class="ui-pill" data-i18n="articleMeta2">16 色文字工具</span>
      <span class="ui-pill" data-i18n="articleMeta3">生成後永久保留</span>
    </div>
  </section>

  <section class="page-section create-shell">
    <div class="page-main-wide">
      <div class="create-modebar">
        <div class="mode-tabs" role="tablist" aria-label="Create mode">
          <a id="tab-links" class="button button--ghost" data-i18n="tabLinks" role="tab" aria-selected="false">連結模式</a>
          <a id="tab-article" class="button button--accent" data-i18n="tabArticle" role="tab" aria-selected="true">文章模式（Markdown）</a>
        </div>
      </div>

      <form class="editor-frame form-stack create-panel create-panel--article" action="/generate-article.php" method="post" novalidate>
        <input type="hidden" name="theme" id="hidden_theme" value="dark">
        <input type="hidden" name="ui_lang" id="hidden_ui_lang" value="zh-TW">

        <div class="text-help form-intro-note" data-i18n="tipLimits_article">標題≤30字；正文建議≤5000字；提交後不可修改或刪除。</div>

        <div class="form-block">
          <h2 data-i18n="titleLabel">標題（必填，≤30字）</h2>
          <input id="title" name="title" type="text" maxlength="30" required aria-required="true"
                 aria-describedby="titleHelp" placeholder="">
          <div id="titleHelp" class="text-help" data-i18n="titleHelp">請輸入頁面標題，不超過 30 個字元。</div>
        </div>

        <div class="form-block">
          <h2 data-i18n="mdLabel">正文（支援 Markdown）</h2>
          <textarea id="markdown_body" name="markdown_body" rows="14" aria-describedby="mdHelp"></textarea>
          <div id="mdHelp" class="text-help" data-i18n="mdHelp">支援標題/粗斜體/連結/列表/程式碼/引用/分隔線等格式，右上角可切換預覽。</div>
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
          <div class="form-block form-block--verification">
            <h2 data-i18n="verificationTitle">安全驗證</h2>
            <div class="text-help" data-i18n="verificationHelp">提交前請完成人機驗證，避免濫用與批量生成。</div>
            <div class="turnstile-wrap">
              <div class="cf-turnstile"
                   data-sitekey="<?php echo $turnstileSiteKey; ?>"
                   data-theme="auto"
                   data-language="auto"></div>
            </div>
          </div>

          <div class="action-group action-group--submit">
            <button class="button button--accent" type="submit" data-i18n="generateBtn">生成文章頁面</button>
            <a class="button button--ghost js-manual" id="btn-manual" href="/manual.html" target="_blank" data-i18n="manual">使用手冊</a>
          </div>
        </div>
      </form>
    </div>
  </section>
  </main>
  <div id="footer-slot" class="footer-slot"></div>
</div>

<!-- 色塊面板 -->
<div id="xlog-color-panel" class="color-panel" role="dialog" aria-hidden="true">
  <div class="cap" data-i18n="colorTitle">文字顏色</div>
  <div class="color-grid" id="xlog-color-grid"></div>
</div>

<!-- 统一 i18n + 公共 UI -->
<script src="/assets/js/i18n.js"></script>
<script src="/assets/js/app.js"></script>
<script>
(function(){
  var I18N = window.XLOG_I18N || {};

  XLOG.initState();
  XLOG.applyLang(XLOG.curLang);
  XLOG.applySEO('seoTitle_article', 'seoDesc_article');
  XLOG.bindControls({
    onLangChange: function(lang) {
      XLOG.applySEO('seoTitle_article', 'seoDesc_article');
    }
  });
  XLOG.bindTabs('article');
  XLOG.loadFooter();

  /* ======================= EasyMDE + 色塊面板 ======================= */
  var t = I18N[XLOG.curLang] || I18N['zh-TW'] || {};

  var easyMDE = new EasyMDE({
    element: document.getElementById('markdown_body'),
    autoDownloadFontAwesome: false,
    spellChecker: false,
    autofocus: true,
    minHeight: "220px",
    status: false,
    toolbar: [
      "bold","italic","strikethrough","heading","|",
      "quote","unordered-list","ordered-list","|",
      "link","code","table","horizontal-rule","|",
      {
        name: "text-color",
        className: "fa fa-eyedropper",
        title: t.colorTitle || 'Text Color',
        action: function(){
          var btn = document.querySelector('.editor-toolbar .fa-eyedropper');
          if (btn) btn = btn.parentElement;
          var panel = document.getElementById('xlog-color-panel');
          if(!btn || !panel) return;
          var rect = btn.getBoundingClientRect();
          panel.style.left = (rect.left + window.scrollX) + 'px';
          panel.style.top  = (rect.bottom + window.scrollY + 8) + 'px';
          panel.style.display = 'block';
          panel.setAttribute('aria-hidden', 'false');
          window._xlog_cur_editor = easyMDE;
        }
      },"|",
      "preview",
      {
        name: "guide",
        action: function(){ window.open('https://www.markdownguide.org/basic-syntax/','_blank'); },
        className: "fa fa-question-circle",
        title: "Markdown Guide"
      }
    ]
  });

  // 16 色塊
  var presetColors = [
    '#e53935','#fb8c00','#fdd835','#43a047',
    '#1e88e5','#3949ab','#8e24aa','#ec407a',
    '#d81b60','#ffb300','#7cb342','#039be5',
    '#5c6bc0','#00acc1','#00897b','#8d6e63'
  ];
  var grid = document.getElementById('xlog-color-grid');
  presetColors.forEach(function(c){
    var sw = document.createElement('div');
    sw.className = 'color-swatch';
    sw.style.background = c;
    sw.dataset.color = c;
    grid.appendChild(sw);
  });

  // 色塊点击
  document.getElementById('xlog-color-panel').addEventListener('click', function(e){
    var sw = e.target.closest('.color-swatch');
    if(!sw) return;
    var color = sw.dataset.color;
    var ct = I18N[XLOG.curLang] || {};
    var cm = window._xlog_cur_editor.codemirror;
    var sel = cm.getSelection() || ct.colorSample || 'text';
    cm.replaceSelection('<span style="color:' + color + '">' + sel + '</span>');
    cm.focus();
    hidePanel();
  });

  // 关闭面板
  document.addEventListener('click', function(e){
    var panel = document.getElementById('xlog-color-panel');
    var isBtn = e.target.classList.contains('fa-eyedropper') || (e.target.closest && e.target.closest('.fa-eyedropper'));
    if(!panel) return;
    if(panel.style.display === 'block' && !panel.contains(e.target) && !isBtn) hidePanel();
  });
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') hidePanel();
  });

  function hidePanel(){
    var panel = document.getElementById('xlog-color-panel');
    if(!panel) return;
    panel.style.display = 'none';
    panel.setAttribute('aria-hidden','true');
  }
})();
</script>
</body>
</html>
