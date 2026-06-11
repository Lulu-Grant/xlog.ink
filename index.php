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
  <link rel="stylesheet" href="/css/page-ai.css?v=20260611v14">
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
      <header class="topbar">
        <a class="brand" id="brandLink" href="/">xlog.ink</a>
        <nav class="top-actions" aria-label="Account and status">
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
      </section>

      <form id="composer" class="composer">
        <textarea id="messageInput" rows="1" placeholder="继续描述你的页面..."></textarea>
        <button type="submit" aria-label="发送">➤</button>
      </form>
    </section>

  </main>
  <script src="/js/qrcode.min.js?v=1.4.4"></script>
  <script src="/js/ai-app.js?v=20260611v15"></script>
</body>
</html>
