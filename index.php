<?php
require_once __DIR__ . '/includes/bootstrap.php';
$editSession = preg_match('/^[a-f0-9]{32}$/', $_GET['edit_session'] ?? '') ? $_GET['edit_session'] : '';
$turnstileEnabled = (bool)xlog_config('turnstile.enabled', false);
$turnstileSiteKey = xlog_config('turnstile.site_key', '');
$locale = resolve_locale($_GET['locale'] ?? null);
set_locale_cookie($locale);
$appCopy = localized_copy('app', $locale);
$allAppCopy = get_i18n()['app'];
?>
<!DOCTYPE html>
<html lang="<?php echo h($locale); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo h($appCopy['pageTitle']); ?></title>
  <meta name="description" content="<?php echo h($appCopy['pageDescription']); ?>">
  <link rel="canonical" href="https://xlog.ink/">
  <meta property="og:title" content="<?php echo h($appCopy['pageTitle']); ?>">
  <meta property="og:description" content="<?php echo h($appCopy['ogDescription']); ?>">
  <meta property="og:image" content="/assets/og/cover.jpg">
  <link rel="stylesheet" href="/css/page-ai.css?v=20260612v22">
  <link rel="icon" href="/favicon.ico">
  <?php if ($turnstileEnabled && $turnstileSiteKey !== ''): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="ai-page"
      data-locale="<?php echo h($locale); ?>"
      data-edit-session="<?php echo h($editSession); ?>"
      data-turnstile-enabled="<?php echo $turnstileEnabled ? '1' : '0'; ?>"
      data-turnstile-sitekey="<?php echo h($turnstileSiteKey); ?>">
  <main class="desktop-canvas">
    <section class="app-window" aria-label="xlog AI page creator">
      <header class="topbar">
        <a class="brand" id="brandLink" href="/">xlog.ink</a>
        <nav class="top-actions" aria-label="Account and status">
          <div id="localeSwitch" class="locale-switch" aria-label="Language">
            <button type="button" data-locale-choice="zh-CN" <?php echo $locale === 'zh-CN' ? 'aria-current="true"' : ''; ?>>简</button>
            <button type="button" data-locale-choice="zh-TW" <?php echo $locale === 'zh-TW' ? 'aria-current="true"' : ''; ?>>繁</button>
            <button type="button" data-locale-choice="en" <?php echo $locale === 'en' ? 'aria-current="true"' : ''; ?>>EN</button>
          </div>
          <span id="quotaText" class="quota-pill"><?php echo h($appCopy['quotaLoading']); ?></span>
          <button id="myPagesToggle" type="button" class="login-btn secondary" hidden><?php echo h($appCopy['myPages']); ?></button>
          <button id="loginToggle" type="button" class="login-btn"><?php echo h($appCopy['login']); ?></button>
        </nav>
      </header>

      <div id="accountBox" class="login-panel" hidden>
        <div id="loginStepEmail" class="login-step">
          <input id="loginEmail" type="email" placeholder="<?php echo h($appCopy['loginWithEmail']); ?>" autocomplete="email">
          <button id="sendCodeBtn" type="button"><?php echo h($appCopy['sendCode']); ?></button>
        </div>
        <div id="loginStepCode" class="login-step" hidden>
          <input id="loginCode" type="text" inputmode="numeric" maxlength="6" placeholder="<?php echo h($appCopy['codePlaceholder']); ?>" autocomplete="one-time-code">
          <button id="verifyCodeBtn" type="button"><?php echo h($appCopy['loginRegister']); ?></button>
        </div>
        <div id="accountRow" class="login-step" hidden>
          <span id="accountEmail" class="account-email"></span>
          <button id="logoutBtn" type="button"><?php echo h($appCopy['logout']); ?></button>
        </div>
        <p id="loginHint" class="login-hint" hidden></p>
      </div>

      <div id="myPagesPanel" class="my-pages-panel" hidden>
        <div class="panel-head">
          <strong><?php echo h($appCopy['myPages']); ?></strong>
          <button id="closeMyPages" type="button" aria-label="<?php echo h($appCopy['close']); ?>">×</button>
        </div>
        <div id="myPagesList" class="my-pages-list">
          <span><?php echo h($appCopy['myPagesHint']); ?></span>
        </div>
      </div>

      <section class="chat-canvas">
        <div id="messages" class="messages" role="log"></div>
      </section>

      <form id="composer" class="composer">
        <textarea id="messageInput" rows="1" placeholder="<?php echo h($appCopy['composerPlaceholder']); ?>"></textarea>
        <button type="submit" aria-label="<?php echo h($appCopy['send']); ?>">↵</button>
      </form>
    </section>

  </main>
  <script>
  window.XLOG_LOCALE = <?php echo json_encode($locale, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  window.XLOG_I18N = <?php echo json_encode($allAppCopy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  </script>
  <script src="/js/qrcode.min.js?v=1.4.4"></script>
  <script src="/js/ai-app.js?v=20260612v29"></script>
</body>
</html>
