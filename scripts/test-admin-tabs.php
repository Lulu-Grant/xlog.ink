<?php
/**
 * Admin tab shell, lazy data, orders/users helpers, CSRF/grant guards.
 * Drives shipped includes/admin_data.php + source structure of admin.php / partials.
 *
 * Usage: php scripts/test-admin-tabs.php
 * Exit 0 on pass, 1 on fail.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/test_bootstrap.php';
$__testCtx = xlog_test_bootstrap([
    'billing' => [
        'credit_mode' => true,
        'signup_credits' => 10,
        'guest_generate_quota' => 5,
        'user_fallback_daily_generate' => 2,
    ],
    'pay' => ['enabled' => true, 'allow_http_api' => true],
    'app' => ['env' => 'test'],
    'admin' => ['token' => 'test-admin-token', 'allow_credit_grant' => false],
    'ai' => ['mock' => true],
]);

if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_cookies', '0');
    @session_save_path($__testCtx['data_dir'] . '/php-sessions');
    @mkdir($__testCtx['data_dir'] . '/php-sessions', 0700, true);
    @session_start();
}

require_once $root . '/includes/admin_security.php';
require_once $root . '/includes/admin_data.php';
xlog_test_assert_not_default_data_dir();

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

echo "=== xlog admin tabs ===\n";
echo "root=$root\n";

// (a) tab whitelist / unknown → overview
assert_true(admin_resolve_tab('overview') === 'overview', 'resolve overview');
assert_true(admin_resolve_tab('pages') === 'pages', 'resolve pages');
assert_true(admin_resolve_tab('channels') === 'channels', 'resolve channels');
assert_true(admin_resolve_tab('orders') === 'orders', 'resolve orders');
assert_true(admin_resolve_tab('users') === 'users', 'resolve users');
assert_true(admin_resolve_tab('nope') === 'overview', 'unknown tab → overview');
assert_true(admin_resolve_tab('../evil') === 'overview', 'pathy tab sanitized → overview');
assert_true(admin_resolve_tab('') === 'overview', 'empty tab → overview');

$url = admin_tab_url('pages', ['q' => 'cafe', 'limit' => 20]);
assert_true(strpos($url, 'tab=pages') !== false && strpos($url, 'q=cafe') !== false, 'admin_tab_url includes query');

// (b) overview path does not issue heavy pages+visits list
// Structural: admin.php only calls admin_list_pages when tab===pages
$adminSrc = file_get_contents($root . '/admin.php');
assert_true(strpos($adminSrc, "admin_list_pages") !== false, 'admin.php uses admin_list_pages');
// Ensure list is gated
assert_true(
    preg_match('/tab === [\'"]pages[\'"].*admin_list_pages|admin_list_pages.*tab === [\'"]pages[\'"]/s', $adminSrc)
    || (strpos($adminSrc, "elseif (\$tab === 'pages')") !== false && strpos($adminSrc, 'admin_list_pages') !== false),
    'admin_list_pages only under pages branch'
);
// overview partial must not contain channel secret fields or pages list table headers for ops
$overviewSrc = file_get_contents($root . '/partials/admin/overview.php');
$pagesSrc = file_get_contents($root . '/partials/admin/pages.php');
$channelsSrc = file_get_contents($root . '/partials/admin/channels.php');
assert_true(strpos($overviewSrc, 'name="md5_key"') === false, 'overview has no md5_key field');
assert_true(strpos($overviewSrc, 'merchant_private_key') === false, 'overview has no merchant_private_key');
assert_true(strpos($pagesSrc, 'name="md5_key"') === false, 'pages partial has no channel secret input');
assert_true(strpos($pagesSrc, 'merchant_private_key') === false, 'pages partial has no RSA textarea name');
assert_true(strpos($pagesSrc, admin_pages_list_sql_marker()) === false, 'pages view is template only (SQL in helper)');
// Real call: overview kpis works without needing list
$kpis = admin_overview_kpis();
assert_true(isset($kpis['total_pages'], $kpis['pending_orders'], $kpis['today']), 'overview kpis shape');
assert_true(!isset($kpis['pages_rows']), 'overview kpis has no pages list payload');

// (c) channel write path CSRF
assert_true(strpos($adminSrc, 'admin_csrf_ok') !== false, 'admin.php uses admin_csrf_ok');
assert_true(strpos($channelsSrc, 'name="csrf"') !== false, 'channels form has csrf field');
assert_true(strpos($adminSrc, "admin_tab_url('channels')") !== false || strpos($adminSrc, 'tab=channels') !== false, 'channel POST PRG targets channels');
// Real CSRF helpers still work
unset($_COOKIE['xlog_admin']);
$token = (string)xlog_config('admin.token', 'test-token-for-csrf');
if ($token === '') {
    // local may have empty token; still exercise hash
    $token = 'test-token-for-csrf';
}
// Force cookie via issue if config has token
$cfgToken = trim((string)xlog_config('admin.token', ''));
if ($cfgToken !== '') {
    admin_issue_session_cookie($cfgToken);
    $good = admin_csrf_token();
    assert_true(admin_csrf_ok($good), 'admin_csrf_ok accepts valid token');
    assert_true(!admin_csrf_ok('bad'), 'admin_csrf_ok refuses bad token');
    assert_true(!admin_csrf_ok(''), 'admin_csrf_ok refuses empty');
} else {
    echo "SKIP  csrf live (admin.token empty in config)\n";
    // Still assert function rejects empty
    assert_true(!admin_csrf_ok(''), 'admin_csrf_ok refuses empty without token');
}

// (d) orders list filter
$now = now_iso();
$email = 'admin-tab-' . bin2hex(random_bytes(3)) . '@example.com';
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)',
    [$email, $now, 10, 7, 'active']
);
$userId = (int)db_one('SELECT id FROM users WHERE email = ?', [$email])['id'];
$oidPending = 'XLOGADM' . strtoupper(bin2hex(random_bytes(4)));
$oidPaid = 'XLOGADM' . strtoupper(bin2hex(random_bytes(4)));
db_exec(
    'INSERT INTO orders (id, user_id, amount_cents, credits, status, pay_channel, channel_id, package_id, client_ip, created_at, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$oidPending, $userId, 1000, 10, 'pending', 'alipay', 'alipay_main', 'c10', '127.0.0.1', $now, null]
);
db_exec(
    'INSERT INTO orders (id, user_id, amount_cents, credits, status, pay_channel, channel_id, package_id, client_ip, created_at, paid_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$oidPaid, $userId, 2800, 30, 'paid', 'wxpay', 'wxpay_xhmcn', 'c30', '127.0.0.1', $now, $now]
);

$pendingOnly = admin_list_orders('pending', 50, '');
$pendingIds = array_column($pendingOnly, 'id');
assert_true(in_array($oidPending, $pendingIds, true), 'pending filter includes pending order');
assert_true(!in_array($oidPaid, $pendingIds, true), 'pending filter excludes paid order');
foreach ($pendingOnly as $row) {
    if ($row['id'] === $oidPending) {
        assert_true(($row['status'] ?? '') === 'pending', 'seeded pending status');
        assert_true((int)$row['amount_cents'] === 1000, 'pending amount_cents');
        assert_true(($row['user_email'] ?? '') === $email, 'pending joins user email');
    }
}
$paidOnly = admin_list_orders('paid', 50, '');
$paidIds = array_column($paidOnly, 'id');
assert_true(in_array($oidPaid, $paidIds, true), 'paid filter includes paid order');
assert_true(!in_array($oidPending, $paidIds, true), 'paid filter excludes pending order');
// second call consistency
$paidOnly2 = admin_list_orders('paid', 50, '');
assert_true(in_array($oidPaid, array_column($paidOnly2, 'id'), true), 'paid filter stable on second call');

// orders UI: no fulfill control
$ordersSrc = file_get_contents($root . '/partials/admin/orders.php');
// No write/fulfill controls (note text may mention read-only).
assert_true(!preg_match('/<(button|input)[^>]*(fulfill|强制入账|入账)/i', $ordersSrc), 'orders UI no fulfill/入账 controls');
assert_true(strpos($ordersSrc, 'pay_fulfill') === false, 'orders UI no pay_fulfill');
assert_true(strpos($ordersSrc, 'method="post"') === false, 'orders partial has no POST forms');

// (e) users search + ledger
db_exec(
    'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
    [$userId, 10, 'signup_bonus', null, $now]
);
db_exec(
    'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
    [$userId, -1, 'generate', null, $now]
);
$found = admin_search_users(explode('@', $email)[0], 30);
$foundIds = array_map('intval', array_column($found, 'id'));
assert_true(in_array($userId, $foundIds, true), 'email search finds user');
$hit = null;
foreach ($found as $u) {
    if ((int)$u['id'] === $userId) {
        $hit = $u;
        break;
    }
}
assert_true($hit && (int)$hit['credits'] === 7, 'search returns credits');
assert_true(isset($hit['daily_quota'], $hit['status'], $hit['created_at']), 'user row shape');
$ledger = admin_user_credit_ledger($userId, 50);
$reasons = array_column($ledger, 'reason');
assert_true(in_array('signup_bonus', $reasons, true), 'ledger has signup_bonus');
assert_true(in_array('generate', $reasons, true), 'ledger has generate');
$ledger2 = admin_user_credit_ledger($userId, 50);
assert_true(count($ledger2) === count($ledger), 'ledger stable second call');

// (f) grant default off
assert_true(admin_credit_grant_allowed() === false, 'allow_credit_grant default false');
$denied = admin_grant_credits($userId, 5, 'should fail');
assert_true(empty($denied['ok']) && ($denied['error'] ?? '') === 'credit_grant_disabled', 'grant refused when disabled');
$bal = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($bal === 7, 'credits unchanged after denied grant');
$usersSrc = file_get_contents($root . '/partials/admin/users.php');
assert_true(strpos($usersSrc, 'admin_grant_action') !== false, 'users partial has grant form gated by $grantAllowed');
assert_true(strpos($usersSrc, 'grantAllowed') !== false, 'grant UI gated by grantAllowed flag');

// Nav structure (keys in $nav map + admin_tab_url($id) loop)
$layoutSrc = file_get_contents($root . '/partials/admin/layout.php');
assert_true(strpos($layoutSrc, "'overview'") !== false && strpos($layoutSrc, "'pages'") !== false, 'nav map has overview+pages');
assert_true(strpos($layoutSrc, "'channels'") !== false && strpos($layoutSrc, "'orders'") !== false && strpos($layoutSrc, "'users'") !== false, 'nav map has channels+orders+users');
assert_true(strpos($layoutSrc, 'admin_tab_url($id)') !== false || strpos($layoutSrc, 'admin_tab_url') !== false, 'nav uses admin_tab_url');
assert_true(strpos($layoutSrc, 'admin-nav') !== false, 'layout has admin-nav sidebar class');
assert_true(strpos($layoutSrc, 'aria-label="后台模块"') !== false, 'nav aria-label present');
// Labels present for operators
foreach (['概览', '页面', '支付渠道', '订单', '用户积分'] as $label) {
    assert_true(strpos($layoutSrc, $label) !== false, "nav label $label");
}

// channels secrets not echoed as values on edit form (empty value=)
assert_true(strpos($channelsSrc, 'name="md5_key"') !== false, 'channels has md5_key field');
assert_true(preg_match('/name="md5_key"[^>]*value=""/', $channelsSrc) || strpos($channelsSrc, 'type="password" value=""') !== false, 'md5_key value empty (no echo)');

if (!empty($__testCtx['tmp'])) {
    xlog_test_remove_tree($__testCtx['tmp']);
}

echo "\n=== summary ===\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . ":\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
