<?php
require_once __DIR__ . '/includes/bootstrap.php';
$editSession = preg_match('/^[a-f0-9]{32}$/', $_GET['edit_session'] ?? '') ? $_GET['edit_session'] : '';
$turnstileEnabled = (bool)xlog_config('turnstile.enabled', false);
$turnstileSiteKey = xlog_config('turnstile.site_key', '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>xlog.ink - AI 个人页面生成</title>
  <meta name="description" content="通过 AI 对话创建个人名片、宣传海报、文章页、活动页，并发布到专属二级域名。">
  <link rel="canonical" href="https://xlog.ink/">
  <meta property="og:title" content="xlog.ink - AI 个人页面生成">
  <meta property="og:description" content="聊天、上传图片、生成自由 HTML 页面，并自动分发到二级域名。">
  <meta property="og:image" content="/assets/og/cover.jpg">
  <link rel="stylesheet" href="/css/page-ai.css?v=20260611v9">
  <link rel="icon" href="/favicon.ico">
  <?php if ($turnstileEnabled && $turnstileSiteKey !== ''): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="ai-page"
      data-edit-session="<?php echo h($editSession); ?>"
      data-turnstile-enabled="<?php echo $turnstileEnabled ? '1' : '0'; ?>"
      data-turnstile-sitekey="<?php echo h($turnstileSiteKey); ?>">
  <main class="desktop-canvas">
    <section class="app-window" aria-label="xlog AI page creator">
      <div class="window-chrome" aria-hidden="true">
        <span></span><span></span><span></span>
        <div>xlog.ink</div>
      </div>

      <header class="topbar">
        <a class="brand" id="brandLink" href="/">xlog.ink</a>
        <nav class="top-actions" aria-label="Account and status">
          <button type="button" class="icon-btn" title="语言">文</button>
          <button type="button" class="icon-btn" title="主题">◐</button>
          <span id="quotaText" class="quota-pill">读取额度...</span>
          <button id="myPagesToggle" type="button" class="login-btn secondary" hidden>我的页面</button>
          <button id="loginToggle" type="button" class="login-btn">登录</button>
        </nav>
      </header>

      <div id="accountBox" class="login-panel" hidden>
        <input id="loginEmail" type="email" placeholder="邮箱验证码登录">
        <button id="sendCodeBtn" type="button">发送验证码</button>
        <input id="loginCode" type="text" inputmode="numeric" maxlength="6" placeholder="6 位验证码">
        <button id="verifyCodeBtn" type="button">登录 / 注册</button>
      </div>

      <div id="myPagesPanel" class="my-pages-panel" hidden>
        <div class="panel-head">
          <strong>我的页面</strong>
          <button id="closeMyPages" type="button" aria-label="关闭">×</button>
        </div>
        <div id="myPagesList" class="my-pages-list">
          <span>登录后可以查看并修改你名下的页面。</span>
        </div>
      </div>

      <section class="chat-canvas">
        <div id="messages" class="messages" aria-live="polite"></div>

        <div class="preset-row" id="presetList" aria-label="Page type presets">
          <button data-prompt="我想创建一个个人名片页面，请引导我补充必要信息。">名片</button>
          <button data-prompt="我想创建一个产品或服务宣传海报页面，请帮我整理需求。">宣传海报</button>
          <button data-prompt="我想创建一篇文章页面，请问我需要哪些内容。">文章页面</button>
          <button data-prompt="我想创建一个活动页面，请引导我提供活动名称、时间、地址和联系方式。">活动页面</button>
          <button data-prompt="我想自由创建一个页面，我会直接描述。">自由描述</button>
        </div>

        <div class="generate-strip">
          <button id="publishBtn" class="publish-btn" type="button">生成页面</button>
        </div>
      </section>

      <section id="deliveryBox" class="delivery-slot" aria-live="polite">
        <span>生成后会出现页面 URL、复制按钮、二维码下载和邮箱修改入口。</span>
      </section>

      <section id="publishOptions" class="publish-options">
        <label class="adult-toggle">
          <input id="isAdultPage" type="checkbox">
          <span>此页面包含 18+ 成人内容，发布后先显示确认页</span>
        </label>
        <?php if ($turnstileEnabled && $turnstileSiteKey !== ''): ?>
        <div id="turnstileBox" class="turnstile-box">
          <div class="cf-turnstile" data-sitekey="<?php echo h($turnstileSiteKey); ?>" data-theme="light"></div>
        </div>
        <?php endif; ?>
      </section>

      <form id="composer" class="composer">
        <textarea id="messageInput" rows="1" placeholder="继续描述你的需求，或直接点击生成..."></textarea>
        <button type="submit" aria-label="发送">➤</button>
      </form>
    </section>

    <section class="mobile-notes" aria-label="mobile layout note">
      <span>移动端会保留同一聊天流，顶部状态压缩，交付卡片直接出现在对话下方。</span>
    </section>
  </main>
  <script src="/js/qrcode.min.js?v=1.4.4"></script>
  <script src="/js/ai-app.js?v=20260611v9"></script>
</body>
</html>
