<?php
// includes/helpers.php — 公共工具函数

function h($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clamp_len_u($s, $max) {
    $arr = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if (count($arr) > $max) {
        $arr = array_slice($arr, 0, $max);
    }
    return implode('', $arr);
}

function random_name($len = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $o = '';
    for ($i = 0; $i < $len; $i++) {
        $o .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $o;
}

function client_ip() {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        $remoteAddr = '0.0.0.0';
    }

    if (!request_comes_from_trusted_proxy($remoteAddr)) {
        return $remoteAddr;
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $p) {
            $ip = trim($p);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }

    return $remoteAddr;
}

function base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function validate_lang($lang) {
    $allowed = ['zh-CN', 'zh-TW', 'en'];
    return in_array($lang, $allowed, true) ? $lang : 'zh-CN';
}

function validate_theme($theme) {
    return ($theme === 'dark') ? 'dark' : 'light';
}

function theme_class($theme) {
    return ($theme === 'dark') ? 'theme-dark' : 'theme-light';
}

function trusted_proxy_list() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $raw = getenv('XLOG_TRUSTED_PROXIES');
    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $items = array_map('trim', explode(',', $raw));
    $cache = array_values(array_filter($items, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
    return $cache;
}

function request_comes_from_trusted_proxy($remoteAddr) {
    return in_array($remoteAddr, trusted_proxy_list(), true);
}

function build_create_page_csp() {
    return implode('; ', [
        "default-src 'self'",
        "img-src 'self' https: data:",
        "style-src 'self' 'unsafe-inline'",
        "font-src 'self' data:",
        "connect-src 'self' https://challenges.cloudflare.com https://mc.yandex.ru",
        "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://mc.yandex.ru",
        "frame-src https://challenges.cloudflare.com",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        'upgrade-insecure-requests',
    ]);
}

function build_generated_page_csp() {
    return implode('; ', [
        "default-src 'self'",
        "img-src 'self' https: data:",
        "style-src 'self' 'unsafe-inline'",
        "font-src 'self' data:",
        "connect-src 'self' https://mc.yandex.ru",
        "script-src 'self' 'unsafe-inline' https://mc.yandex.ru",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        'upgrade-insecure-requests',
    ]);
}

function build_response_page_csp() {
    return implode('; ', [
        "default-src 'self'",
        "img-src 'self' data:",
        "style-src 'self' 'unsafe-inline'",
        "font-src 'self' data:",
        "connect-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        'upgrade-insecure-requests',
    ]);
}

function build_csp($profile = 'response-page') {
    switch ($profile) {
        case 'create-page':
            return build_create_page_csp();
        case 'generated-page':
            return build_generated_page_csp();
        case 'response-page':
        default:
            return build_response_page_csp();
    }
}

function send_security_headers($profile = 'response-page') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: ' . build_csp($profile));
}

function ensure_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Method Not Allowed";
        exit;
    }
}

function generate_slug($outDir) {
    $tries = 0;
    do {
        $slug = random_name(10);
        $file = $slug . '.html';
        $path = $outDir . '/' . $file;
        $tries++;
    } while (file_exists($path) && $tries < 10);

    if (file_exists($path)) {
        http_response_code(500);
        exit('Name conflict');
    }
    return ['slug' => $slug, 'file' => $file, 'path' => $path];
}

function record_page_index($slug, $title, $nowIso, $uiLang, $type, $isAdult = false) {
    $indexFile = __DIR__ . '/../data/pages.jsonl';
    $entry = json_encode([
        'slug'  => $slug,
        'title' => $title,
        'time'  => $nowIso,
        'lang'  => $uiLang,
        'type'  => $type,
        'adult' => (bool)$isAdult,
    ], JSON_UNESCAPED_UNICODE);
    @file_put_contents($indexFile, $entry . "\n", FILE_APPEND | LOCK_EX);
}

function excerpt_plain_text($text, $max = 120) {
    $text = preg_replace('/\s+/u', ' ', trim((string)$text));
    if ($text === '') return '';
    return mb_substr($text, 0, $max, 'UTF-8');
}

function markdown_excerpt($markdown, $max = 120) {
    $plain = preg_replace('/```.*?```/su', ' ', (string)$markdown);
    $plain = preg_replace('/`([^`]*)`/u', '$1', $plain);
    $plain = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', ' ', $plain);
    $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $plain);
    $plain = preg_replace('/^[>#*\-\+\d\.\s]+/mu', '', $plain);
    $plain = str_replace(['#', '*', '_'], ' ', $plain);
    return excerpt_plain_text($plain, $max);
}

function generated_page_labels($uiLang) {
    $i18nPage = get_i18n()['page'];
    return $i18nPage[$uiLang] ?? $i18nPage['zh-CN'];
}

function localized_copy($group, $uiLang) {
    $all = get_i18n();
    $set = $all[$group] ?? [];
    return $set[$uiLang] ?? ($set['zh-CN'] ?? []);
}

function build_response_head_html($uiLang, $title) {
    $lang = h(validate_lang($uiLang));
    $title = h($title);

    return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>{$title}</title>
  <link rel="stylesheet" href="/assets/css/base.css">
  <meta name="robots" content="noindex,nofollow">
</head>
HTML;
}

function build_response_shell_start_html($uiLang, $title, $bodyClass = 'theme-dark screen-center') {
    $head = build_response_head_html($uiLang, $title);
    $bodyClass = h(trim($bodyClass));

    return $head . <<<HTML
<body class="{$bodyClass}">
  <div class="page-bg-orb" aria-hidden="true"></div>
  <div class="page-bg-grid" aria-hidden="true"></div>
HTML;
}

function build_generated_head_html(array $opts) {
    $title     = h($opts['title'] ?? '');
    $desc      = h($opts['description'] ?? '');
    $canonical = h($opts['canonical'] ?? '');
    $lang      = h($opts['lang'] ?? 'zh-CN');
    $ogType    = h($opts['og_type'] ?? 'website');
    $metaCsp   = h(build_csp('generated-page'));
    $ogImage   = 'https://xlog.ink/assets/og/cover.jpg';

    return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{$title}</title>
  <meta name="description" content="{$desc}">
  <link rel="canonical" href="{$canonical}">
  <meta name="robots" content="index,follow">
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f1115">
  <meta property="og:site_name" content="XLOG">
  <meta property="og:title" content="{$title}">
  <meta property="og:description" content="{$desc}">
  <meta property="og:type" content="{$ogType}">
  <meta property="og:url" content="{$canonical}">
  <meta property="og:image" content="{$ogImage}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{$title}">
  <meta name="twitter:description" content="{$desc}">
  <meta name="twitter:image" content="{$ogImage}">
  <meta http-equiv="Content-Security-Policy" content="{$metaCsp}">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <link rel="stylesheet" href="/assets/css/base.css">
  <link rel="icon" href="/favicon.ico">
  <link rel="apple-touch-icon" href="/favicon.ico" sizes="180x180">
  <link rel="manifest" href="/site.webmanifest">
HTML;
}

function build_adult_gate_parts($uiLang, $slug, $isAdult) {
    $adultKey = 'xlog_adult_ok_' . $slug;

    if (!$isAdult) {
        return [
            'adult_key' => $adultKey,
            'body_class_suffix' => '',
            'boot_html' => '',
            'gate_html' => '',
        ];
    }

    $i18nAdult = get_i18n()['adultGate'];
    $adult = $i18nAdult[$uiLang] ?? $i18nAdult['zh-TW'];
    $adultKeyJs = json_encode($adultKey, JSON_UNESCAPED_UNICODE);
    $adultBadge   = h($adult['badge']);
    $adultTitle   = h($adult['title']);
    $adultMsg     = h($adult['msg']);
    $adultConfirm = h($adult['confirm']);
    $adultLeave   = h($adult['leave']);

    $bootHtml = <<<HTML
  <script>
  (function(){
    window.__xlogAdultGateApproved = false;
    try {
      if (localStorage.getItem({$adultKeyJs}) === '1') {
        window.__xlogAdultGateApproved = true;
      }
    } catch (e) {}
  })();
  </script>
HTML;

    $bodyBootHtml = <<<HTML
  <script>
  (function(){
    if (!window.__xlogAdultGateApproved) return;
    var body = document.body;
    if (!body) return;
    body.classList.remove('adult-gate--locked');
    body.classList.add('adult-gate--approved');
  })();
  </script>
HTML;

    $gateHtml = <<<HTML
  <div class="adult-gate" aria-modal="true" role="dialog" aria-labelledby="adult-gate-title">
    <div class="adult-gate-card">
      <div class="adult-gate-badge">{$adultBadge}</div>
      <h1 id="adult-gate-title">{$adultTitle}</h1>
      <p>{$adultMsg}</p>
      <div class="adult-gate-actions">
        <button type="button" class="button button--accent" id="adult-confirm">{$adultConfirm}</button>
        <a class="button button--ghost" href="https://xlog.ink/" id="adult-leave">{$adultLeave}</a>
      </div>
    </div>
  </div>
HTML;

    return [
        'adult_key' => $adultKey,
        'body_class_suffix' => ' adult-gate--enabled adult-gate--locked',
        'boot_html' => $bootHtml,
        'body_boot_html' => $bodyBootHtml,
        'gate_html' => $gateHtml,
    ];
}

function build_generated_page_runtime_html() {
    return <<<HTML
  <script>
  (function(){
    var body = document.body;
    if (!body.classList.contains('adult-gate--enabled')) return;
    var key = body.getAttribute('data-adult-key');
    if (body.classList.contains('adult-gate--approved')) {
      body.classList.remove('adult-gate--locked');
      body.classList.add('adult-gate--approved');
      return;
    }
    var confirmBtn = document.getElementById('adult-confirm');
    if (!confirmBtn) return;
    confirmBtn.addEventListener('click', function(){
      try { localStorage.setItem(key, '1'); } catch (e) {}
      window.__xlogAdultGateApproved = true;
      body.classList.remove('adult-gate--locked');
      body.classList.add('adult-gate--approved');
    });
  })();
  </script>
HTML;
}
