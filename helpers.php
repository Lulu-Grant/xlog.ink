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
    return ($theme === 'dark') ? 'theme-dark' : '';
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
