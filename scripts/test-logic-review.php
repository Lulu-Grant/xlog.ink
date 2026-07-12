<?php
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/xlog-logic-review-' . bin2hex(random_bytes(5));
$dataDir = $tmp . '/data';
$siteDir = $tmp . '/site';
$assetDir = $tmp . '/site-assets';
foreach ([$tmp, $dataDir, $siteDir, $assetDir] as $dir) mkdir($dir, 0755, true);
$configPath = $tmp . '/config.php';
$config = [
    'base_url' => 'https://xlog.test',
    'data_dir' => $dataDir,
    'site_dir' => $siteDir,
    'asset_dir' => $assetDir,
    'smtp' => [
        'host' => '127.0.0.1',
        'port' => 1,
        'secure' => '',
        'user' => 'test',
        'pass' => 'test',
        'from' => 'test@xlog.test',
        'from_name' => 'xlog test',
    ],
    'ai' => ['moderation' => ['key' => '', 'mock' => true]],
];
file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
putenv('XLOG_CONFIG_PATH=' . $configPath);

require_once $root . '/includes/page_edit.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/ai.php';
require_once $root . '/includes/content_tools.php';

function logic_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo '[OK] ' . $message . PHP_EOL;
}

function logic_remove_tree($path) {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $item = $path . '/' . $name;
        if (is_dir($item)) logic_remove_tree($item); else @unlink($item);
    }
    @rmdir($path);
}

try {
    $htmlPath = $siteDir . '/editdemo.html';
    $html = '<!DOCTYPE html><html><head><title>Original</title></head><body><main>START'
        . str_repeat(' retained content ', 2500) . 'TAIL</main></body></html>';
    file_put_contents($htmlPath, $html);
    $editContext = build_edit_page_generation_context([
        'slug' => 'editdemo',
        'title' => 'Original',
        'type' => 'article',
        'lang' => 'zh-CN',
        'html_path' => $htmlPath,
    ]);
    logic_assert(strpos($editContext['current_html'], '<title>Original</title>') !== false, 'edit context keeps head');
    logic_assert(strpos($editContext['current_html'], 'START') !== false, 'edit context keeps body start');
    logic_assert(strpos($editContext['current_html'], 'TAIL') !== false, 'edit context keeps body tail');
    logic_assert(mb_strlen($editContext['current_html'], 'UTF-8') <= 24200, 'edit context respects size budget');

    $moderation = ai_openai_moderation_result([
        'flagged' => true,
        'categories' => ['sexual' => false, 'sexual/minors' => true],
        'category_scores' => ['sexual' => 0.2, 'sexual/minors' => 0.91],
    ]);
    logic_assert(!empty($moderation['must_block']), 'sexual/minors moderation requires hard block');
    logic_assert(abs($moderation['adult_score'] - 0.2) < 0.0001, 'adult score remains separate from minors score');

    $oldHash = hash('sha256', 'old-token');
    $now = now_iso();
    db_exec(
        'INSERT INTO pages (slug, title, type, lang, created_at, updated_at, email, editable, token_hash, status, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['maildemo', 'Mail demo', 'page', 'zh-CN', $now, $now, 'old@example.com', 1, $oldHash, 'live', $htmlPath]
    );
    $page = db_one('SELECT * FROM pages WHERE slug = ?', ['maildemo']);
    try {
        send_page_edit_link($page, 'new@example.com', 'zh-CN', 'new@example.com:maildemo');
        throw new RuntimeException('SMTP failure was not raised');
    } catch (Throwable $e) {
        if ($e->getMessage() === 'SMTP failure was not raised') throw $e;
    }
    $restored = db_one('SELECT email, editable, token_hash FROM pages WHERE slug = ?', ['maildemo']);
    logic_assert($restored['email'] === 'old@example.com', 'SMTP failure restores previous email');
    logic_assert((int)$restored['editable'] === 1, 'SMTP failure restores previous editable state');
    logic_assert(hash_equals($oldHash, (string)$restored['token_hash']), 'SMTP failure preserves previous edit token');

    foreach (['https://127.0.0.1/a.png', 'https://169.254.169.254/latest/meta-data', 'http://1.1.1.1/a.png'] as $unsafeUrl) {
        $rejected = false;
        try { ai_validate_public_image_url($unsafeUrl); } catch (Throwable $e) { $rejected = true; }
        logic_assert($rejected, 'unsafe image URL rejected: ' . $unsafeUrl);
    }

    $metaHtml = '<!doctype html><html><head><title>Test</title><meta name="description" content="A concise public summary"></head><body><h1>Visible heading</h1></body></html>';
    logic_assert(generated_page_description($metaHtml, 'Test') === 'A concise public summary', 'SEO description keeps generated public summary');
    $fallbackDescription = generated_page_description('<html><head><title>Test</title><style>body{color:red}</style></head><body><h1>Visible heading</h1><p>Visible copy</p></body></html>', 'Test');
    logic_assert(strpos($fallbackDescription, 'Visible heading') !== false, 'SEO fallback uses visible page content');
    logic_assert(strpos($fallbackDescription, '[{"role"') === false, 'SEO fallback never serializes conversation JSON');

    echo "Logic review regression passed.\n";
} finally {
    logic_remove_tree($tmp);
}
