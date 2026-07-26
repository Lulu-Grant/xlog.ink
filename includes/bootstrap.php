<?php
// V2 bootstrap: config, sessions, API helpers.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';

if (!defined('XLOG_ROOT')) {
    define('XLOG_ROOT', dirname(__DIR__));
}

function xlog_default_config() {
    return [
        'base_url' => 'https://xlog.ink',
        'data_dir' => XLOG_ROOT . '/data',
        'site_dir' => XLOG_ROOT . '/site',
        'asset_dir' => XLOG_ROOT . '/site-assets',
        'asset_url' => 'https://xlog.ink/site-assets',
        'turnstile' => [
            'enabled' => false,
            'site_key' => getenv('TURNSTILE_SITE_KEY') ?: '',
            'secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
        ],
        'ai' => [
            'base_url' => getenv('XLOG_AI_BASE_URL') ?: 'https://api.3s3.org',
            'chat' => [
                'base_url' => getenv('XLOG_CHAT_BASE_URL') ?: 'https://api.3s3.org',
                'model' => getenv('XLOG_CHAT_MODEL') ?: 'gpt-5.4-mini',
                'format' => getenv('XLOG_CHAT_FORMAT') ?: 'openai',
                'key' => getenv('XLOG_CHAT_API_KEY') ?: '',
                'max_tokens' => 1024,
                'fallbacks' => [
                    [
                        'base_url' => getenv('XLOG_CHAT_FALLBACK_BASE_URL') ?: 'https://api.3s3.org',
                        'model' => getenv('XLOG_CHAT_FALLBACK_MODEL') ?: 'grok-4.5',
                        'format' => getenv('XLOG_CHAT_FALLBACK_FORMAT') ?: 'openai',
                        'key' => getenv('XLOG_CHAT_FALLBACK_API_KEY') ?: '',
                        'max_tokens' => 1024,
                    ],
                ],
            ],
            'gen' => [
                'base_url' => getenv('XLOG_GEN_BASE_URL') ?: 'https://api.3s3.org',
                'model' => getenv('XLOG_GEN_MODEL') ?: 'grok-4.5',
                'format' => getenv('XLOG_GEN_FORMAT') ?: 'openai',
                'key' => getenv('XLOG_GEN_API_KEY') ?: '',
                'max_tokens' => 16384,
                'stream' => true,
                'timeout' => 180,
                'low_speed_time' => 35,
                'fallbacks' => [
                    [
                        'base_url' => getenv('XLOG_GEN_FALLBACK_BASE_URL') ?: 'https://api.3s3.org',
                        'model' => getenv('XLOG_GEN_FALLBACK_MODEL') ?: 'gpt-5.6',
                        'format' => getenv('XLOG_GEN_FALLBACK_FORMAT') ?: 'openai',
                        'key' => getenv('XLOG_GEN_FALLBACK_API_KEY') ?: '',
                        'max_tokens' => 16384,
                        'stream' => true,
                        'timeout' => 240,
                        'low_speed_time' => 45,
                    ],
                ],
            ],
            'image' => [
                'base_url' => getenv('XLOG_IMAGE_BASE_URL') ?: 'https://api.tu-zi.com',
                'model' => getenv('XLOG_IMAGE_MODEL') ?: 'gpt-image-2',
                'format' => getenv('XLOG_IMAGE_FORMAT') ?: 'openai_image',
                'key' => getenv('XLOG_IMAGE_API_KEY') ?: '',
                'size' => getenv('XLOG_IMAGE_SIZE') ?: '1024x1024',
                'quality' => getenv('XLOG_IMAGE_QUALITY') ?: 'low',
                'output_format' => getenv('XLOG_IMAGE_OUTPUT_FORMAT') ?: 'webp',
                'download_hosts' => [],
                'download_max_bytes' => 20 * 1024 * 1024,
                'max_tokens' => 0,
                'fallbacks' => [
                    [
                        'base_url' => getenv('XLOG_IMAGE_FALLBACK_BASE_URL') ?: 'https://api.3s3.org',
                        'model' => getenv('XLOG_IMAGE_FALLBACK_MODEL') ?: 'gpt-image-2',
                        'format' => getenv('XLOG_IMAGE_FALLBACK_FORMAT') ?: 'openai_image',
                        'key' => getenv('XLOG_IMAGE_FALLBACK_API_KEY') ?: '',
                        'size' => getenv('XLOG_IMAGE_FALLBACK_SIZE') ?: '1024x1024',
                        'quality' => getenv('XLOG_IMAGE_FALLBACK_QUALITY') ?: 'low',
                        'output_format' => getenv('XLOG_IMAGE_FALLBACK_OUTPUT_FORMAT') ?: 'webp',
                        'max_tokens' => 0,
                    ],
                ],
            ],
            'moderation' => [
                'base_url' => getenv('XLOG_MODERATION_BASE_URL') ?: 'https://api.openai.com',
                'model' => getenv('XLOG_MODERATION_MODEL') ?: 'omni-moderation-latest',
                'format' => getenv('XLOG_MODERATION_FORMAT') ?: 'openai_moderation',
                'key' => getenv('XLOG_MODERATION_API_KEY') ?: '',
                'max_tokens' => 512,
            ],
        ],
        'screenshot' => [
            'enabled' => true,
            'node' => getenv('XLOG_NODE_BIN') ?: 'node',
        ],
        'smtp' => [
            'host' => getenv('XLOG_SMTP_HOST') ?: '',
            'port' => (int)(getenv('XLOG_SMTP_PORT') ?: 465),
            'secure' => getenv('XLOG_SMTP_SECURE') ?: 'ssl',
            'user' => getenv('XLOG_SMTP_USER') ?: '',
            'pass' => getenv('XLOG_SMTP_PASS') ?: '',
            'from' => getenv('XLOG_SMTP_FROM') ?: 'no-reply@xlog.ink',
            'from_name' => getenv('XLOG_SMTP_FROM_NAME') ?: 'xlog.ink',
        ],
        'billing' => [
            'credit_mode' => false,
            'generate_credit_cost' => 1,
            // Guest free daily page generations (quota counter, not wallet credits).
            'guest_generate_quota' => 5,
            // Free wallet credits granted once on first registration.
            'signup_credits' => 10,
            // Legacy users.daily_quota seed; ignored for generate when credit_mode is true.
            'signup_daily_quota' => 10,
            // When wallet credits < cost, allow this many free generations/day (G1 A1). 0 = off.
            'user_fallback_daily_generate' => 2,
            // amount_cents is RMB fen; min platform amount is 100 (= ¥1.00)
            'packages' => [
                ['id' => 'c10', 'credits' => 10, 'amount_cents' => 1000],
                ['id' => 'c30', 'credits' => 30, 'amount_cents' => 2800],
                ['id' => 'c100', 'credits' => 100, 'amount_cents' => 8800],
                ['id' => 'c500', 'credits' => 500, 'amount_cents' => 39800],
            ],
        ],
        'pay' => [
            'enabled' => true,
            'api_base' => getenv('XLOG_PAY_API_BASE') ?: 'https://api.xuanfanpay.top',
            'pid' => getenv('XLOG_PAY_PID') ?: '',
            'merchant_private_key' => getenv('XLOG_PAY_MERCHANT_PRIVATE_KEY') ?: '',
            'platform_public_key' => getenv('XLOG_PAY_PLATFORM_PUBLIC_KEY') ?: '',
            // V1 MD5 key kept for diagnostics only; V2 uses RSA.
            'md5_key' => getenv('XLOG_PAY_MD5_KEY') ?: '',
            'default_type' => getenv('XLOG_PAY_TYPE') ?: 'alipay',
            'method' => getenv('XLOG_PAY_METHOD') ?: 'jump',
            'notify_url' => getenv('XLOG_PAY_NOTIFY_URL') ?: '',
            'return_url' => getenv('XLOG_PAY_RETURN_URL') ?: '',
        ],
        'admin' => [
            'token' => getenv('XLOG_ADMIN_TOKEN') ?: '',
            'allow_credit_grant' => false,
            'max_attempts' => (int)(getenv('XLOG_ADMIN_MAX_ATTEMPTS') ?: 8),
            'lock_seconds' => (int)(getenv('XLOG_ADMIN_LOCK_SECONDS') ?: 900),
        ],
        'analytics' => [
            'salt' => getenv('XLOG_ANALYTICS_SALT') ?: '',
            'visit_ip_minute_limit' => (int)(getenv('XLOG_VISIT_IP_MINUTE_LIMIT') ?: 120),
            'visit_retention_days' => (int)(getenv('XLOG_VISIT_RETENTION_DAYS') ?: 90),
        ],
    ];
}

function xlog_array_merge_deep(array $base, array $override) {
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = xlog_array_merge_deep($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

function xlog_config($key = null, $default = null) {
    static $config = null;
    if ($config === null) {
        $config = xlog_default_config();
        $configPaths = [
            XLOG_ROOT . '/config.php',
            '/etc/xlog/config.php',
        ];
        $envPath = getenv('XLOG_CONFIG_PATH');
        if ($envPath) {
            $configPaths[] = $envPath;
        }
        foreach ($configPaths as $external) {
            if (is_file($external)) {
                $loaded = require $external;
                if (is_array($loaded)) {
                    $config = xlog_array_merge_deep($config, $loaded);
                }
            }
        }
    }
    if ($key === null) return $config;
    $cursor = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$part];
    }
    return $cursor;
}

function xlog_ensure_dirs() {
    foreach (['data_dir', 'site_dir', 'asset_dir'] as $key) {
        $dir = xlog_config($key);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }
}

/**
 * HTTPS detection for cookies/base URL. Trusts X-Forwarded-Proto only when
 * REMOTE_ADDR is in trusted proxy list (AUDIT-7 P2-9).
 */
function request_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '0') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '' && function_exists('request_comes_from_trusted_proxy') && request_comes_from_trusted_proxy($remote)) {
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') return true;
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    }
    return false;
}

function xlog_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $sessionDir = rtrim(xlog_config('data_dir'), '/') . '/php-sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    @ini_set('session.save_handler', 'files');
    @ini_set('session.save_path', $sessionDir);
    $secure = request_is_https();
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('xlog_v2');
    session_start();
}

/**
 * Active logged-in user id, or null.
 * AUDIT-8 P2-1: non-active users cannot keep a valid identity.
 */
function current_user_id() {
    xlog_start_session();
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $id = (int)$_SESSION['user_id'];
    if ($id <= 0) {
        return null;
    }
    // Lazy status check (cached per request). db.php may not be loaded yet.
    static $cache = [];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    if (!function_exists('db_one')) {
        // Session present but DB helpers not loaded — trust session until db is available.
        return $id;
    }
    try {
        $row = db_one('SELECT id, status FROM users WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        // DB not ready — treat as logged out for safety on sensitive paths after load.
        $cache[$id] = null;
        return null;
    }
    if (!$row || (string)($row['status'] ?? '') !== 'active') {
        unset($_SESSION['user_id']);
        $cache[$id] = null;
        return null;
    }
    $cache[$id] = $id;
    return $id;
}

/**
 * Full user row for the active session user, or null.
 */
function current_user() {
    $id = current_user_id();
    if (!$id || !function_exists('db_one')) {
        return null;
    }
    return db_one('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active']);
}

function xlog_cookie_id() {
    $name = 'xlog_cid';
    if (!empty($_COOKIE[$name]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$name])) {
        return $_COOKIE[$name];
    }
    $cid = bin2hex(random_bytes(16));
    $secure = request_is_https();
    setcookie($name, $cid, [
        'expires' => time() + 86400 * 365,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $cid;
    return $cid;
}

function json_input() {
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function api_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error($code, $message, $status = 400, array $extra = []) {
    api_json(['error' => array_merge(['code' => $code, 'message' => $message], $extra)], $status);
}

function sse_start() {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);
}

function sse_event($event, array $data = []) {
    echo "event: " . $event . "\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

function now_iso() {
    return gmdate('c');
}

function utc_date() {
    return gmdate('Y-m-d');
}

function require_method($method) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        api_error('method_not_allowed', 'Method not allowed', 405);
    }
}

xlog_ensure_dirs();
