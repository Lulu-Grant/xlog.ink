<?php
/**
 * AUDIT-8 P1-1: non-edit publish never blind-UPDATEs; slug reserve via INSERT.
 * Isolated SQLite only.
 *
 * Usage: php scripts/test-publish-slug.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/test_bootstrap.php';

$failures = [];
function assert_true($cond, $label) {
    global $failures;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    echo "FAIL  $label\n";
    $failures[] = $label;
}

echo "=== publish slug reserve (isolated) ===\n";

$ctx = xlog_test_bootstrap([
    'app' => ['env' => 'test'],
    'ai' => ['mock' => true],
]);

require_once $root . '/includes/content_tools.php';
require_once $root . '/includes/db.php';

xlog_test_assert_not_default_data_dir();

$now = now_iso();
$ownerA = null;
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?,?,?,?,?)',
    ['slug-a-' . bin2hex(random_bytes(3)) . '@example.com', $now, 10, 10, 'active']
);
$ownerA = (int)db_one('SELECT id FROM users ORDER BY id DESC LIMIT 1')['id'];
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?,?,?,?,?)',
    ['slug-b-' . bin2hex(random_bytes(3)) . '@example.com', $now, 10, 10, 'active']
);
$ownerB = (int)db_one('SELECT id FROM users ORDER BY id DESC LIMIT 1')['id'];

// Existing live page owned by A
$slug = 'coffee';
$pathA = rtrim($ctx['site_dir'], '/') . '/' . $slug . '.html';
file_put_contents($pathA, '<html><body>ownerA</body></html>');
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, html_path, slug_source)
     VALUES (?,?,?,?,?,?,?,?,?)',
    [$slug, 'A coffee', 'page', 'zh-CN', $now, $ownerA, 'live', $pathA, 'custom']
);

// Create path must not UPDATE A's row when desired slug collides
$reserved = publish_insert_new_page([
    'title' => 'B coffee',
    'type' => 'page',
    'lang' => 'zh-CN',
    'created_at' => $now,
    'owner_user_id' => $ownerB,
    'status' => 'live',
    'cost_tokens' => 1,
    'session_id' => null,
    'is_adult' => 0,
    'adult_score' => 0,
    'adult_reason' => '',
    'og_image_path' => '',
    'screenshot_path' => '',
], [['role' => 'user', 'content' => 'coffee shop']], 'B coffee', 'coffee');

assert_true($reserved['slug'] !== $slug, 'create reserve uses different slug on collision');
assert_true(strpos($reserved['slug'], 'coffee') === 0 || strlen($reserved['slug']) >= 6, 'reserved slug is sensible');

$rowA = db_one('SELECT owner_user_id, title FROM pages WHERE slug = ?', [$slug]);
assert_true((int)$rowA['owner_user_id'] === $ownerA, 'owner A row not stolen');
assert_true($rowA['title'] === 'A coffee', 'owner A title not overwritten');

$rowB = db_one('SELECT owner_user_id, title FROM pages WHERE slug = ?', [$reserved['slug']]);
assert_true($rowB && (int)$rowB['owner_user_id'] === $ownerB, 'owner B owns reserved slug');
assert_true($rowB['title'] === 'B coffee', 'owner B title stored');

// Atomic write does not leave half-written final path on failure of rename source
$writePath = rtrim($ctx['site_dir'], '/') . '/' . $reserved['slug'] . '.html';
write_site_html_atomic($writePath, '<!DOCTYPE html><html><body>B</body></html>');
assert_true(is_file($writePath) && strpos(file_get_contents($writePath), '>B<') !== false, 'atomic write creates final file');

// Edit update only touches target slug
$n = publish_update_edit_page($slug, [
    'title' => 'A coffee edited',
    'type' => 'page',
    'lang' => 'zh-CN',
    'updated_at' => $now,
    'cost_tokens' => 2,
    'session_id' => null,
    'html_path' => $pathA,
    'is_adult' => 0,
    'adult_score' => 0,
    'adult_reason' => '',
    'og_image_path' => '',
    'screenshot_path' => '',
    'slug_source' => 'edit',
]);
assert_true($n >= 1, 'edit update affects existing slug');
$rowA2 = db_one('SELECT title, owner_user_id FROM pages WHERE slug = ?', [$slug]);
assert_true($rowA2['title'] === 'A coffee edited', 'edit update changed title');
assert_true((int)$rowA2['owner_user_id'] === $ownerA, 'edit update kept owner A');

// Missing slug edit returns 0 (fail closed)
$nMiss = publish_update_edit_page('nosuchslugzz', [
    'title' => 'x',
    'type' => 'page',
    'lang' => 'zh-CN',
    'updated_at' => $now,
    'cost_tokens' => 0,
    'session_id' => null,
    'html_path' => '',
    'is_adult' => 0,
    'adult_score' => 0,
    'adult_reason' => '',
    'og_image_path' => '',
    'screenshot_path' => '',
    'slug_source' => 'edit',
]);
assert_true($nMiss === 0, 'edit update missing slug affects 0 rows');

// Simulated TOCTOU: two INSERTs for same desired — second gets different slug
$r1 = publish_insert_new_page([
    'title' => 'T1', 'type' => 'page', 'lang' => 'zh-CN', 'created_at' => $now,
    'owner_user_id' => $ownerA, 'status' => 'live', 'cost_tokens' => 0, 'session_id' => null,
], [], 'Tea', 'teashop');
$r2 = publish_insert_new_page([
    'title' => 'T2', 'type' => 'page', 'lang' => 'zh-CN', 'created_at' => $now,
    'owner_user_id' => $ownerB, 'status' => 'live', 'cost_tokens' => 0, 'session_id' => null,
], [], 'Tea', 'teashop');
assert_true($r1['slug'] !== $r2['slug'], 'two creates with same desired get distinct slugs');
assert_true((int)db_one('SELECT owner_user_id FROM pages WHERE slug=?', [$r1['slug']])['owner_user_id'] === $ownerA, 'first create owner A');
assert_true((int)db_one('SELECT owner_user_id FROM pages WHERE slug=?', [$r2['slug']])['owner_user_id'] === $ownerB, 'second create owner B');

// schema_migrations baseline applied
$mig = db_one("SELECT version FROM schema_migrations WHERE version = '001_baseline'");
assert_true($mig !== null, 'schema_migrations has 001_baseline');

xlog_test_remove_tree($ctx['tmp']);

if ($failures) {
    echo "FAILED: " . count($failures) . "\n";
    exit(1);
}
echo "OK all publish-slug checks passed\n";
exit(0);
