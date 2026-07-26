<?php
/**
 * Smoke checks for pay packages, free-tier quotas, fen money compare,
 * atomic credit consume, fulfill idempotency, return/CSRF/reconcile structure.
 *
 * Uses real project includes (not a reimplementation).
 *
 * Usage: php scripts/test-pay-quota.php
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
    'ai' => ['mock' => true],
]);

// CLI session for consume_quota(current_user_id) — before any output
if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_cookies', '0');
    @session_save_path($__testCtx['data_dir'] . '/php-sessions');
    @mkdir($__testCtx['data_dir'] . '/php-sessions', 0700, true);
    @session_start();
}

require_once $root . '/includes/pay.php';
require_once $root . '/includes/admin_security.php';
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

echo "=== xlog pay/quota smoke ===\n";
echo "root=$root\n";
// 1) Free-tier + billing config
$guestLimit = quota_limit_for('generate', null);
assert_true($guestLimit === 5, "guest generate limit is 5 (got $guestLimit)");

$signup = (int)xlog_config('billing.signup_credits', -1);
assert_true($signup === 10, "signup_credits is 10 (got $signup)");

$creditMode = (bool)xlog_config('billing.credit_mode', false);
assert_true($creditMode === true, 'billing.credit_mode is true');

// 2) Packages include c500 @ 39800
$packages = pay_packages();
$byId = [];
foreach ($packages as $p) {
    $byId[$p['id']] = $p;
    echo "  package {$p['id']}: credits={$p['credits']} amount_cents={$p['amount_cents']}\n";
}
assert_true(isset($byId['c10']), 'package c10 present');
assert_true(isset($byId['c30']), 'package c30 present');
assert_true(isset($byId['c100']), 'package c100 present');
assert_true(isset($byId['c500']), 'package c500 present');
$c500 = $byId['c500'] ?? null;
assert_true($c500 && (int)$c500['amount_cents'] === 39800, 'c500 amount_cents is 39800');
assert_true($c500 && (int)$c500['credits'] === 500, 'c500 credits is 500');

// 2b) fen money helpers
assert_true(pay_parse_money_to_cents('10') === 1000, "parse 10 => 1000");
assert_true(pay_parse_money_to_cents('10.0') === 1000, "parse 10.0 => 1000");
assert_true(pay_parse_money_to_cents('10.00') === 1000, "parse 10.00 => 1000");
assert_true(pay_parse_money_to_cents('9.99') === 999, "parse 9.99 => 999");
assert_true(pay_parse_money_to_cents('10.001') === null, "parse 10.001 invalid");
assert_true(pay_parse_money_to_cents('abc') === null, "parse abc invalid");
assert_true(pay_money_equal('10', 1000), "equal 10 vs 1000 fen");
assert_true(pay_money_equal('10.0', 1000), "equal 10.0 vs 1000 fen");
assert_true(pay_money_equal('10.00', 1000), "equal 10.00 vs 1000 fen");
assert_true(!pay_money_equal('9.99', 1000), "not equal 9.99 vs 1000 fen");
assert_true(!pay_money_equal('nope', 1000), "not equal invalid money");
assert_true(!pay_money_equal('', 1000), "empty money never equal (P1-3)");
assert_true(!pay_money_equal(null, 1000), "null money never equal (P1-3)");

// 3) Fulfill idempotency + fen money variants
$email = 'smoke-pay-' . bin2hex(random_bytes(4)) . '@example.com';
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)',
    [$email, now_iso(), 10, 0, 'active']
);
$user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
assert_true($user && (int)$user['id'] > 0, 'smoke user created');
$userId = (int)$user['id'];

$pkg = pay_package_by_id('c10');
assert_true($pkg !== null, 'c10 package resolvable');

// 3a) fulfill with money "10" (not "10.00")
$orderA = pay_create_local_order($userId, $pkg, 'alipay');
$rA = pay_fulfill_order($orderA['id'], ['trade_no' => 'T10', 'money' => '10']);
assert_true(!empty($rA['ok']) && empty($rA['already']), 'fulfill with money=10 ok');
$bal = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($bal === 10, "balance after money=10 fulfill is 10 (got $bal)");

// 3b) new order with money 10.0
$orderB = pay_create_local_order($userId, $pkg, 'alipay');
$rB = pay_fulfill_order($orderB['id'], ['trade_no' => 'T100', 'money' => '10.0']);
assert_true(!empty($rB['ok']), 'fulfill with money=10.0 ok');

// 3c) new order with money 10.00 + idempotent second
$orderC = pay_create_local_order($userId, $pkg, 'alipay');
$rC1 = pay_fulfill_order($orderC['id'], ['trade_no' => 'T1000', 'money' => '10.00']);
$rC2 = pay_fulfill_order($orderC['id'], ['trade_no' => 'T1000', 'money' => '10.00']);
assert_true(!empty($rC1['ok']) && empty($rC1['already']), 'fulfill 10.00 first ok');
assert_true(!empty($rC2['ok']) && !empty($rC2['already']), 'fulfill 10.00 second already');
$txC = db_one(
    'SELECT COUNT(*) AS c FROM credit_transactions WHERE user_id = ? AND ref = ? AND reason = ?',
    [$userId, $orderC['id'], 'recharge']
);
assert_true($txC && (int)$txC['c'] === 1, 'exactly one recharge tx for 10.00 order');

// 3d) mismatch 9.99
$orderD = pay_create_local_order($userId, $pkg, 'alipay');
$rD = pay_fulfill_order($orderD['id'], ['trade_no' => 'TBAD', 'money' => '9.99']);
assert_true(empty($rD['ok']) && ($rD['error'] ?? '') === 'money_mismatch', 'money 9.99 mismatch');
assert_true((db_one('SELECT status FROM orders WHERE id = ?', [$orderD['id']])['status'] ?? '') === 'pending', 'mismatch stays pending');

// 3e) invalid money
$orderE = pay_create_local_order($userId, $pkg, 'alipay');
$rE = pay_fulfill_order($orderE['id'], ['trade_no' => 'TINV', 'money' => '10.001']);
assert_true(empty($rE['ok']) && in_array($rE['error'] ?? '', ['money_mismatch', 'money_missing'], true), 'money 10.001 rejected');

// 3f) missing money
$orderF = pay_create_local_order($userId, $pkg, 'alipay');
$rF = pay_fulfill_order($orderF['id'], ['trade_no' => 'TEMPTY', 'money' => '']);
assert_true(empty($rF['ok']) && ($rF['error'] ?? '') === 'money_missing', 'empty money fulfill rejected');

// 4) Atomic credit consume: start with 1 credit
// Drive real consume_quota() with a synthetic session user_id (CLI-safe).
// With G1 free-daily fallback, second consume may succeed via free_daily when credits hit 0.
// Exhaust fallback first by setting generate_free counters to the limit, then verify pure credit deny.
db_exec('UPDATE users SET credits = 1 WHERE id = ?', [$userId]);
$_SESSION['user_id'] = $userId;
$fallbackLim = user_fallback_daily_limit();
if ($fallbackLim > 0) {
    // Pre-fill free-daily counter so this test isolates credit_mode atomic path.
    $fkey = 'user:' . $userId;
    $fkind = quota_free_daily_kind();
    db_exec(
        'INSERT INTO quota_counters (key, date, kind, count) VALUES (?, ?, ?, ?)
         ON CONFLICT(key, date, kind) DO UPDATE SET count = excluded.count',
        [$fkey, utc_date(), $fkind, $fallbackLim]
    );
}
$c1 = consume_quota('generate');
$c2 = consume_quota('generate');
assert_true(!empty($c1['ok']) && !empty($c1['credit_mode']), 'first atomic consume ok');
assert_true(empty($c2['ok']) && ($c2['reason'] ?? '') === 'credits_exhausted', 'second consume credits_exhausted (fallback pre-exhausted)');
$finalBal = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($finalBal === 0, "final balance 0 after double consume (got $finalBal)");
unset($_SESSION['user_id']);

// 5) notify failure logging is durable
$logBefore = is_file(xlog_config('data_dir') . '/pay-notify.log')
    ? filesize(xlog_config('data_dir') . '/pay-notify.log')
    : 0;
pay_notify_log('fail', 'smoke_bad_sign', [
    'order_id' => 'XLOGSMOKELOG',
    'out_trade_no' => 'XLOGSMOKELOG',
    'trade_no' => 'T0',
]);
$logPath = rtrim((string)xlog_config('data_dir'), '/') . '/pay-notify.log';
assert_true(is_file($logPath), 'pay-notify.log exists after fail log');
$logAfter = filesize($logPath);
assert_true($logAfter > $logBefore, 'pay-notify.log grew after fail log');

// 6) Structural: return.php no direct fulfill from signature
$returnSrc = file_get_contents($root . '/api/pay/return.php');
assert_true(strpos($returnSrc, 'pay_sync_order_from_gateway') !== false, 'return.php uses pay_sync_order_from_gateway');
assert_true(strpos($returnSrc, 'pay_fulfill_order') === false, 'return.php does not call pay_fulfill_order');
assert_true(strpos($returnSrc, 'canTrust') === false, 'return.php has no canTrust fulfill branch');

// 7) Structural: MD5 query prefers POST api/pay/query and POST api.php before GET key=
$paySrc = file_get_contents($root . '/includes/pay.php');
assert_true(strpos($paySrc, "pay_http_post_raw(\$base . '/api/pay/query'") !== false
    || strpos($paySrc, 'pay_http_post_raw($base . \'/api/pay/query\'') !== false
    || preg_match("/api\\/pay\\/query/", $paySrc), 'pay.php has /api/pay/query path');
assert_true(strpos($paySrc, "pay_http_post_raw(\$base . '/api.php'") !== false
    || strpos($paySrc, "'/api.php'") !== false, 'pay.php POSTs legacy /api.php');
// GET fallback still exists but primary is POST
$posPost = strpos($paySrc, "pay_http_post_raw(\$base . '/api.php'");
if ($posPost === false) $posPost = strpos($paySrc, "'/api.php'");
$posGetKey = strpos($paySrc, '&key=');
assert_true($posPost !== false && $posGetKey !== false && $posPost < $posGetKey, 'POST api.php appears before GET key= fallback');

// 8) Admin CSRF (real shipped helpers in includes/admin_security.php)
require_once $root . '/includes/admin_security.php';
$adminSrc = file_get_contents($root . '/admin.php');
$channelsPartial = is_file($root . '/partials/admin/channels.php')
    ? file_get_contents($root . '/partials/admin/channels.php')
    : '';
assert_true(strpos($adminSrc, 'admin_csrf_ok') !== false, 'admin.php has admin_csrf_ok');
assert_true(strpos($adminSrc, 'admin_issue_session_cookie') !== false, 'admin.php issues cookie via helper');
assert_true(
    strpos($adminSrc, 'name="csrf"') !== false || strpos($channelsPartial, 'name="csrf"') !== false,
    'admin forms include csrf field'
);
assert_true(strpos($adminSrc, 'CSRF 校验失败') !== false, 'admin rejects missing CSRF');
assert_true(strpos($adminSrc, '$_COOKIE[\'xlog_admin\']') !== false || strpos($adminSrc, 'admin_issue_session_cookie') !== false, 'admin syncs cookie into $_COOKIE');

// Real path: admin_issue_session_cookie mirrors ticket into $_COOKIE (setcookie alone would not).
unset($_COOKIE['xlog_admin']);
$adminToken = (string)xlog_config('admin.token', '');
// Without cookie, CSRF binds to literal "no-ticket"
$csrfBeforeLogin = admin_csrf_token();
assert_true(strpos($csrfBeforeLogin, '') === 0, 'csrf function callable before cookie');
// After issue: $_COOKIE has ticket; form CSRF must validate against same ticket
$issued = admin_issue_session_cookie($adminToken);
assert_true(isset($_COOKIE['xlog_admin']) && $_COOKIE['xlog_admin'] === $issued, 'issue_session_cookie sets $_COOKIE');
$formCsrf = admin_csrf_token();
assert_true($formCsrf !== $csrfBeforeLogin, 'CSRF changes after cookie issued (not stuck on no-ticket)');
assert_true(admin_csrf_ok($formCsrf), 'admin_csrf_ok accepts token after cookie sync');
assert_true(!admin_csrf_ok(''), 'admin_csrf_ok rejects empty');
assert_true(!admin_csrf_ok($csrfBeforeLogin), 'stale pre-login CSRF rejected after ticket issued');
// Next request: browser resends same ticket
$_COOKIE['xlog_admin'] = $issued;
assert_true(admin_csrf_ok($formCsrf), 'CSRF still valid on next request with same ticket');// 9) Reconcile script exists and dry-run works
$reconcilePath = $root . '/scripts/pay-reconcile.php';
assert_true(is_file($reconcilePath), 'pay-reconcile.php exists');
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($reconcilePath) . ' --dry-run --limit=5 2>&1', $out, $code);
$joined = implode("\n", $out);
assert_true($code === 0, 'pay-reconcile --dry-run exit 0');
assert_true(strpos($joined, 'reconcile_start') !== false, 'reconcile prints reconcile_start');
assert_true(strpos($joined, 'reconcile_done') !== false, 'reconcile prints reconcile_done');
assert_true(is_file($root . '/docs/PAY-RUNBOOK.md'), 'PAY-RUNBOOK.md exists');

// 10) notify still success plain
$notifySrc = file_get_contents($root . '/api/pay/notify.php');
assert_true(strpos($notifySrc, "echo 'success'") !== false || strpos($notifySrc, 'echo "success"') !== false, 'notify success plain text');

// cleanup isolated tmp (no project data/)
if (!empty($__testCtx['tmp'])) {
    xlog_test_remove_tree($__testCtx['tmp']);
}

echo "=== summary ===\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . " check(s)\n";
    foreach ($failures as $f) echo " - $f\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
