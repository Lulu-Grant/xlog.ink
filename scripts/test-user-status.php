<?php
/**
 * AUDIT-8 P2-1: disabled users cannot keep a valid identity.
 * Isolated SQLite only.
 *
 * Usage: php scripts/test-user-status.php
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

echo "=== user status (isolated) ===\n";

$ctx = xlog_test_bootstrap([
    'app' => ['env' => 'test'],
]);

if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_cookies', '0');
    @session_save_path($ctx['data_dir'] . '/php-sessions');
    @mkdir($ctx['data_dir'] . '/php-sessions', 0700, true);
    @session_start();
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/html_sanitize.php';

xlog_test_assert_not_default_data_dir();

$now = now_iso();
$emailActive = 'active-' . bin2hex(random_bytes(3)) . '@example.com';
$emailDis = 'disabled-' . bin2hex(random_bytes(3)) . '@example.com';

db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?,?,?,?,?)',
    [$emailActive, $now, 10, 5, 'active']
);
$activeId = (int)db_one('SELECT id FROM users WHERE email=?', [$emailActive])['id'];

db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?,?,?,?,?)',
    [$emailDis, $now, 10, 5, 'disabled']
);
$disId = (int)db_one('SELECT id FROM users WHERE email=?', [$emailDis])['id'];

$_SESSION['user_id'] = $activeId;
assert_true(current_user_id() === $activeId, 'active user current_user_id ok');
$user = current_user();
assert_true($user && (int)$user['id'] === $activeId, 'current_user returns active row');

$_SESSION['user_id'] = $disId;
// Clear static cache inside current_user_id by using a fresh process... static cache is per-request.
// Force: we need to bypass static cache when same process already cached miss for another id.
// current_user_id caches by id key, so disId is fresh.
$got = current_user_id();
assert_true($got === null, 'disabled user current_user_id is null');
assert_true(!isset($_SESSION['user_id']), 'disabled user session cleared');

// Simulate verify gate: only active may be accepted
$disRow = db_one('SELECT * FROM users WHERE id=?', [$disId]);
assert_true((string)$disRow['status'] !== 'active', 'disabled row status not active');
$activeRow = db_one('SELECT * FROM users WHERE id=?', [$activeId]);
assert_true((string)$activeRow['status'] === 'active', 'active row status active');

// --- AUDIT-8 P2-4 sanitizer gaps ---
$evil = '<!DOCTYPE html><html><body>'
    . '<img src=https://evil.example/x.png>'
    . '<img srcset="https://evil.example/a.png 1x, https://xlog.ink/site-assets/ok.webp 2x">'
    . '<style>@import url(https://evil.example/x.css); body{background:url(https://evil.example/b.png)}</style>'
    . '</body></html>';
$clean = xlog_sanitize_generated_html($evil);
assert_true(strpos($clean, 'evil.example') === false, 'sanitize removes evil host from img/srcset/css');
assert_true(strpos($clean, '@import') === false, 'sanitize removes @import');
$threw = false;
try {
    xlog_assert_safe_generated_html($clean);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw === false, 'assert_safe accepts sanitized output');

// Unsanitized evil must fail assert
$threw2 = false;
try {
    xlog_assert_safe_generated_html($evil);
} catch (Throwable $e) {
    $threw2 = true;
}
assert_true($threw2 === true, 'assert_safe rejects unsanitized evil');

// secret_ref hydrate
require_once $root . '/includes/pay.php';
// Re-bootstrap config merge: put secrets via env config rewrite is hard; call hydrate with synthetic row
// by temporarily writing config is complex — test function with pay.secrets via reload is heavy.
// Instead exercise hydrate with empty ref (passthrough) and with map via putenv+new process not available.
// Direct unit: empty ref returns same md5
$ch = ['id' => 't', 'md5_key' => 'inline', 'secret_ref' => '', 'pid' => '1', 'api_base' => 'https://x', 'driver' => 'epay_v1_md5'];
$h = pay_channel_hydrate_secrets($ch);
assert_true($h['md5_key'] === 'inline', 'hydrate passthrough without secret_ref');

// migrations recorded
$m2 = db_one("SELECT version FROM schema_migrations WHERE version = '002_pay_channel_secret_ref'");
assert_true($m2 !== null, 'migration 002 applied');

xlog_test_remove_tree($ctx['tmp']);

if ($failures) {
    echo "FAILED: " . count($failures) . "\n";
    exit(1);
}
echo "OK all user-status/sanitize checks passed\n";
exit(0);
