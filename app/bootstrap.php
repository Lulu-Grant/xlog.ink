<?php

if (defined('APP_BOOTSTRAPPED')) {
    return;
}

define('APP_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));

$site_config = [
    'default_lang' => 'zh',
    'language_registry' => [
        'zh' => [
            'native_label' => '中文繁體',
            'hreflang' => 'zh-Hant',
            'og_locale' => 'zh_TW',
        ],
        'zh-cn' => [
            'native_label' => '简体中文',
            'hreflang' => 'zh-Hans',
            'og_locale' => 'zh_CN',
        ],
        'en' => [
            'native_label' => 'English',
            'hreflang' => 'en',
            'og_locale' => 'en_US',
        ],
        'fr' => [
            'native_label' => 'Français',
            'hreflang' => 'fr',
            'og_locale' => 'fr_FR',
        ],
        'ko' => [
            'native_label' => '한국어',
            'hreflang' => 'ko',
            'og_locale' => 'ko_KR',
        ],
        'ja' => [
            'native_label' => '日本語',
            'hreflang' => 'ja',
            'og_locale' => 'ja_JP',
        ],
    ],
    'site_name_fallback' => '猫柠咔百宝箱',
    'downloads_ttl' => 6 * 60 * 60,
    'max_upload_size' => 10 * 1024 * 1024,
    'max_upload_count' => 12,
];

$tool_registry = include APP_ROOT . '/app/tools.php';

function app_config(?string $key = null)
{
    global $site_config;

    if ($key === null) {
        return $site_config;
    }

    return $site_config[$key] ?? null;
}

function supported_langs(): array
{
    return array_keys(language_registry());
}

function language_registry(): array
{
    return app_config('language_registry') ?? [];
}

function language_meta(string $langCode): array
{
    $registry = language_registry();

    return $registry[$langCode] ?? [];
}

function language_switcher_options(): array
{
    $options = [];

    foreach (supported_langs() as $langCode) {
        $options[] = [
            'code' => $langCode,
            'label' => language_meta($langCode)['native_label'] ?? $langCode,
        ];
    }

    return $options;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_lang_code(string $rawLang): ?string
{
    $lang = strtolower(trim($rawLang));

    if ($lang === '') {
        return null;
    }

    $lang = str_replace('_', '-', explode(';', $lang, 2)[0]);

    if (in_array($lang, supported_langs(), true)) {
        return $lang;
    }

    $primary = explode('-', $lang, 2)[0];

    if ($primary === 'zh') {
        if (str_contains($lang, 'hans') || str_contains($lang, 'cn') || str_contains($lang, 'sg')) {
            return 'zh-cn';
        }

        return 'zh';
    }

    if (in_array($primary, supported_langs(), true)) {
        return $primary;
    }

    return null;
}

function detect_lang(): string
{
    if (isset($_GET['lang'])) {
        $requestedLang = normalize_lang_code((string) $_GET['lang']);

        if ($requestedLang !== null) {
            setcookie('lang', $requestedLang, time() + 3600 * 24 * 30, '/');
            return $requestedLang;
        }
    }

    if (isset($_COOKIE['lang'])) {
        $cookieLang = normalize_lang_code((string) $_COOKIE['lang']);

        if ($cookieLang !== null) {
            return $cookieLang;
        }
    }

    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $browserLang) {
            $normalisedLang = normalize_lang_code((string) $browserLang);

            if ($normalisedLang !== null) {
                return $normalisedLang;
            }
        }
    }

    return app_config('default_lang');
}

function load_lang(string $langCode): array
{
    $langFile = APP_ROOT . '/lang/' . $langCode . '.php';
    $fallbackFile = APP_ROOT . '/lang/' . app_config('default_lang') . '.php';

    if (is_file($langFile)) {
        return include $langFile;
    }

    return include $fallbackFile;
}

$lang_code = detect_lang();
$lang = load_lang($lang_code);

function trans(string $key, ?string $fallback = null): string
{
    global $lang;

    if (array_key_exists($key, $lang)) {
        return (string) $lang[$key];
    }

    return $fallback ?? $key;
}

function tool_registry(): array
{
    global $tool_registry;

    return $tool_registry;
}

function get_tool(string $toolKey): array
{
    $tools = tool_registry();

    if (!isset($tools[$toolKey])) {
        throw new InvalidArgumentException('Unknown tool: ' . $toolKey);
    }

    return $tools[$toolKey] + ['key' => $toolKey];
}

function tool_context(string $toolKey): array
{
    $tool = get_tool($toolKey);

    return [
        'tool' => $tool,
        'tool_key' => $toolKey,
        'tool_title' => trans($tool['title_key']),
        'tool_description' => trans($tool['description_key'], ''),
        'tool_keywords' => trans($tool['keywords_key'], ''),
        'pageTitle' => build_page_title($toolKey),
    ];
}

function build_page_title(?string $toolKey = null): string
{
    $siteTitle = trans('title_full', (string) app_config('site_name_fallback'));

    if ($toolKey === null) {
        return $siteTitle;
    }

    $tool = get_tool($toolKey);
    return trans($tool['title_key']) . ' - ' . $siteTitle;
}

function tool_url($tool): string
{
    if (is_string($tool)) {
        $tool = get_tool($tool);
    }

    return '/' . ltrim($tool['slug'], '/');
}

function current_request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = strtok($uri, '?');

    return $path === false || $path === '' ? '/' : $path;
}

function current_scheme(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }

    return 'http';
}

function build_absolute_url(string $path = '/', array $query = []): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'tool.gls.lat';
    $normalisedPath = $path === '' ? '/' : $path;
    $url = current_scheme() . '://' . $host . $normalisedPath;

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function current_lang(): string
{
    global $lang_code;

    return $lang_code;
}

function query_for_lang(string $langCode, bool $includeDefault = false): array
{
    if ($langCode === app_config('default_lang') && !$includeDefault) {
        return [];
    }

    return ['lang' => $langCode];
}

function build_canonical_url(): string
{
    return build_absolute_url(current_request_path(), query_for_lang(current_lang()));
}

function build_hreflang_links(): array
{
    $path = current_request_path();
    $links = [];

    foreach (supported_langs() as $code) {
        $links[] = [
            'hreflang' => language_meta($code)['hreflang'] ?? $code,
            'href' => build_absolute_url($path, query_for_lang($code)),
        ];
    }

    $links[] = [
        'hreflang' => 'x-default',
        'href' => build_absolute_url($path),
    ];

    return $links;
}

function locale_map(): array
{
    $map = [];

    foreach (language_registry() as $langCode => $meta) {
        if (isset($meta['og_locale'])) {
            $map[$langCode] = $meta['og_locale'];
        }
    }

    return $map;
}

function current_og_locale(): string
{
    $locales = locale_map();
    $lang = current_lang();

    return $locales[$lang] ?? 'en_US';
}

function alternate_og_locales(): array
{
    $locales = locale_map();
    $current = current_lang();
    $result = [];

    foreach (supported_langs() as $lang) {
        if ($lang === $current || !isset($locales[$lang])) {
            continue;
        }

        $result[] = $locales[$lang];
    }

    return $result;
}

function current_page_type(): string
{
    return current_request_path() === '/' ? 'WebSite' : 'WebPage';
}

function build_json_ld_payload(string $pageTitle, string $description, string $canonicalUrl): array
{
    $brandName = trans('brand_name', '猫柠咔');
    $siteTitle = trans('title_full', (string) app_config('site_name_fallback'));

    $payload = [
        '@context' => 'https://schema.org',
        '@type' => current_page_type(),
        'name' => $pageTitle,
        'headline' => $pageTitle,
        'description' => $description,
        'url' => $canonicalUrl,
        'inLanguage' => current_lang(),
        'publisher' => [
            '@type' => 'Organization',
            'name' => $brandName,
        ],
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => $siteTitle,
            'url' => build_absolute_url('/'),
            'inLanguage' => current_lang(),
        ],
    ];

    if (current_request_path() === '/') {
        $payload['potentialAction'] = [
            '@type' => 'ViewAction',
            'target' => build_absolute_url('/'),
        ];
    }

    return $payload;
}

function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function ensure_directory(string $path): string
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

function app_runtime_root(): string
{
    return ensure_directory(sys_get_temp_dir() . '/toolsgualaoshi');
}

function app_runtime_path(string $relativePath = ''): string
{
    $root = app_runtime_root();

    if ($relativePath === '') {
        return $root;
    }

    return $root . '/' . ltrim($relativePath, '/');
}

function cleanup_expired_files(string $path, int $ttlSeconds): void
{
    if (!is_dir($path)) {
        return;
    }

    $threshold = time() - $ttlSeconds;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->getMTime() > $threshold) {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }
}

function maybe_cleanup_runtime_storage(int $ttlSeconds, int $minIntervalSeconds = 900): void
{
    $root = app_runtime_root();
    $stampPath = app_runtime_path('.cleanup-stamp');
    $lastRun = is_file($stampPath) ? (int) filemtime($stampPath) : 0;

    if ($lastRun > time() - $minIntervalSeconds) {
        return;
    }

    $handle = @fopen($stampPath, 'c+');
    if ($handle === false) {
        return;
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return;
    }

    clearstatcache(true, $stampPath);
    $lastRun = is_file($stampPath) ? (int) filemtime($stampPath) : 0;
    if ($lastRun <= time() - $minIntervalSeconds) {
        cleanup_expired_files($root, $ttlSeconds);
        @touch($stampPath);
    }

    flock($handle, LOCK_UN);
    fclose($handle);
}

function sanitize_filename_stem(string $filename): string
{
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    $safeStem = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $stem);

    return trim((string) $safeStem, '_') ?: 'file';
}

function normalise_hex_color(string $hexColor, string $default = '#ffffff'): string
{
    $value = trim($hexColor);

    if ($value === '') {
        return $default;
    }

    if ($value[0] !== '#') {
        $value = '#' . $value;
    }

    if (preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value) !== 1) {
        return $default;
    }

    return strtolower($value);
}

function image_extension_allowed(string $extension): bool
{
    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
}

function image_output_mime(string $format): string
{
    $map = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
    ];

    return $map[$format] ?? 'application/octet-stream';
}

function register_download(string $absolutePath, string $downloadName, ?string $mimeType = null): string
{
    app_start_session();
    cleanup_expired_session_downloads();

    $token = bin2hex(random_bytes(16));
    $_SESSION['downloads'][$token] = [
        'path' => $absolutePath,
        'name' => $downloadName,
        'mime' => $mimeType ?: 'application/octet-stream',
        'expires_at' => time() + app_config('downloads_ttl'),
    ];

    return $token;
}

function cleanup_expired_session_downloads(): void
{
    app_start_session();

    if (empty($_SESSION['downloads']) || !is_array($_SESSION['downloads'])) {
        $_SESSION['downloads'] = [];
        return;
    }

    $now = time();

    foreach ($_SESSION['downloads'] as $token => $payload) {
        if (($payload['expires_at'] ?? 0) < $now || empty($payload['path']) || !is_file($payload['path'])) {
            unset($_SESSION['downloads'][$token]);
        }
    }
}

function get_download_payload(string $token): ?array
{
    app_start_session();
    cleanup_expired_session_downloads();

    if (!isset($_SESSION['downloads'][$token])) {
        return null;
    }

    return $_SESSION['downloads'][$token];
}

function download_url(string $token): string
{
    return '/download.php?token=' . rawurlencode($token);
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
