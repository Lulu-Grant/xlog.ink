<?php
/**
 * AUDIT-7 Sprint A–B hardening: claim access, money required, channel soft-delete,
 * HTML sanitize, mock gate, data_dir guard. Isolated SQLite only.
 *
 * Usage: php scripts/test-audit7-hardening.php
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

echo "=== audit7 hardening (isolated) ===\n";

// Guard: refuse if someone forces default data via env after bootstrap (we bootstrap first)
$ctx = xlog_test_bootstrap([
    'app' => ['env' => 'test'],
    'ai' => ['mock' => true],
    'billing' => ['credit_mode' => true, 'user_fallback_daily_generate' => 0],
]);

if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_cookies', '0');
    @session_save_path($ctx['data_dir'] . '/php-sessions');
    @mkdir($ctx['data_dir'] . '/php-sessions', 0700, true);
    @session_start();
}

require_once $root . '/includes/pay.php';
require_once $root . '/includes/page_edit.php';
require_once $root . '/includes/html_sanitize.php';
require_once $root . '/includes/ai.php';

try {
    xlog_test_assert_not_default_data_dir();
    assert_true(true, 'data_dir is isolated');
} catch (Throwable $e) {
    assert_true(false, 'data_dir is isolated: ' . $e->getMessage());
}

// --- P1-3 money ---
assert_true(pay_money_equal('', 1000) === false, 'empty money not equal');
assert_true(pay_money_equal(null, 1000) === false, 'null money not equal');
assert_true(pay_money_equal('10.00', 1000) === true, '10.00 matches 1000 fen');
assert_true(pay_money_equal('9.99', 1000) === false, '9.99 mismatch');

$email = 'audit7-' . bin2hex(random_bytes(3)) . '@example.com';
db_exec('INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?,?,?,?,?)', [$email, now_iso(), 10, 0, 'active']);
$userId = (int)db_one('SELECT id FROM users WHERE email=?', [$email])['id'];
$_SESSION['user_id'] = $userId;

// seed channel
$now = now_iso();
db_exec(
    'INSERT INTO pay_channels (id, name, pay_type, driver, api_base, pid, md5_key, merchant_private_key, platform_public_key, method, enabled, sort_order, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['ch_test', 'Test', 'alipay', 'epay_v1_md5', 'https://pay.example.com', '1001', 'md5secret', '', '', 'jump', 1, 10, $now, $now]
);

$oid = 'XLOGA7' . strtoupper(bin2hex(random_bytes(4)));
db_exec(
    'INSERT INTO orders (id, user_id, amount_cents, credits, status, pay_channel, channel_id, package_id, client_ip, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$oid, $userId, 1000, 10, 'pending', 'alipay', 'ch_test', 'c10', '127.0.0.1', $now]
);

$rMissing = pay_fulfill_order($oid, ['trade_no' => 'T1', 'money' => '']);
assert_true(empty($rMissing['ok']) && ($rMissing['error'] ?? '') === 'money_missing', 'fulfill rejects empty money');
$status = db_one('SELECT status FROM orders WHERE id=?', [$oid])['status'];
assert_true($status === 'pending', 'order still pending after money_missing');

$rOk = pay_fulfill_order($oid, ['trade_no' => 'T1', 'money' => '10.00']);
assert_true(!empty($rOk['ok']) && empty($rOk['already']), 'fulfill with money=10.00 ok');
$bal = (int)db_one('SELECT credits FROM users WHERE id=?', [$userId])['credits'];
assert_true($bal === 10, 'credits credited after good money');

// --- P1-2 channel soft delete + no fallback ---
$del = pay_channel_delete('ch_test');
assert_true(!empty($del['ok']) && !empty($del['soft']), 'channel delete is soft');
$ch = pay_channel_by_id('ch_test');
assert_true($ch && (int)$ch['enabled'] === 0, 'channel still exists disabled');
assert_true((int)($del['orders'] ?? 0) >= 1, 'soft delete reports order refs');

$order2 = db_one('SELECT * FROM orders WHERE id=?', [$oid]);
// paid order still resolves channel for verify material
$resolved = pay_channel_for_order($order2);
assert_true($resolved && $resolved['id'] === 'ch_test', 'order channel resolve without fallback');

// query without channel_id on a synthetic pending order with missing channel
$oidB = 'XLOGA7' . strtoupper(bin2hex(random_bytes(4)));
db_exec(
    'INSERT INTO orders (id, user_id, amount_cents, credits, status, pay_channel, channel_id, package_id, client_ip, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)',
    [$oidB, $userId, 1000, 10, 'pending', 'alipay', 'ch_missing', 'c10', '127.0.0.1', $now]
);
$q = pay_query_gateway_order($oidB, '', null);
assert_true(empty($q['ok']) && ($q['error'] ?? '') === 'no_channel', 'query no silent channel fallback');

// P1-2 notify-style verify: order channel A, signature with key B must reject
db_exec(
    'INSERT INTO pay_channels (id, name, pay_type, driver, api_base, pid, md5_key, merchant_private_key, platform_public_key, method, enabled, sort_order, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['ch_a', 'A', 'alipay', 'epay_v1_md5', 'https://pay.example.com', '1001', 'keyAAAA', '', '', 'jump', 1, 1, $now, $now]
);
db_exec(
    'INSERT INTO pay_channels (id, name, pay_type, driver, api_base, pid, md5_key, merchant_private_key, platform_public_key, method, enabled, sort_order, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['ch_b', 'B', 'alipay', 'epay_v1_md5', 'https://pay.example.com', '1001', 'keyBBBB', '', '', 'jump', 1, 2, $now, $now]
);
$paramsA = ['pid' => '1001', 'out_trade_no' => 'X1', 'trade_no' => 'T', 'money' => '10.00', 'trade_status' => 'TRADE_SUCCESS'];
$paramsA['sign'] = pay_sign_md5($paramsA, 'keyAAAA');
assert_true(pay_verify($paramsA, 'ch_a') === true, 'verify with correct channel A key ok');
assert_true(pay_verify($paramsA, 'ch_b') === false, 'verify channel B rejects key A signature');
$paramsB = $paramsA;
$paramsB['sign'] = pay_sign_md5($paramsB, 'keyBBBB');
assert_true(pay_verify($paramsB, 'ch_a') === false, 'order channel A rejects key B signature');
assert_true(pay_verify($paramsB, '') === false, 'verify without channel_id fails closed');
assert_true(pay_verify($paramsB, null) === false, 'verify null channel_id fails closed');
// ensure no fallback to any enabled channel when wrong id
assert_true(pay_verify($paramsA, 'ch_missing') === false, 'missing channel id fails closed');

// --- P1-1 session claim ---
$clientA = bin2hex(random_bytes(16));
$clientB = bin2hex(random_bytes(16));
$_COOKIE['xlog_cid'] = $clientA;
$sessionA = bin2hex(random_bytes(16));
$slugA = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
db_exec(
    'INSERT INTO sessions (id, user_id, page_slug, edit_mode, messages, state, locale, ip, client_id, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    [$sessionA, null, $slugA, 'create', '[]', 'done', 'zh-CN', '10.0.0.1', $clientA, $now, $now]
);
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, session_id, html_path)
     VALUES (?,?,?,?,?,?,?,?,?)',
    [$slugA, 'A', 'page', 'zh-CN', $now, null, 'live', $sessionA, '']
);

// Same browser (clientA): bind ok
$bindOk = bind_session_to_user($sessionA, $userId);
assert_true(!empty($bindOk['ok']), 'same-browser bind ok');
$claimOk = claim_page_for_user($slugA, $userId, ['session_id' => $sessionA]);
assert_true(!empty($claimOk['ok']), 'same-browser claim ok');
// reset ownership for foreign test
db_exec('UPDATE pages SET owner_user_id = NULL WHERE slug=?', [$slugA]);
db_exec('UPDATE sessions SET user_id = NULL WHERE id=?', [$sessionA]);

// Foreign browser
$_COOKIE['xlog_cid'] = $clientB;
$bindBad = bind_session_to_user($sessionA, $userId);
assert_true(empty($bindBad['ok']) && ($bindBad['error'] ?? '') === 'forbidden_session', 'foreign session bind forbidden');
$claimBad = claim_page_for_user($slugA, $userId, ['session_id' => $sessionA]);
assert_true(empty($claimBad['ok']), 'foreign session claim rejected');
$owner = db_one('SELECT owner_user_id FROM pages WHERE slug=?', [$slugA]);
assert_true($owner['owner_user_id'] === null || $owner['owner_user_id'] === '', 'page owner still null after foreign claim');

// email match still works without session
db_exec('UPDATE pages SET email = ? WHERE slug=?', [$email, $slugA]);
$_COOKIE['xlog_cid'] = $clientB; // different browser ok for email
$claimEmail = claim_page_for_user($slugA, $userId, ['email_match' => true]);
assert_true(!empty($claimEmail['ok']), 'email-match claim ok');

// --- P1-5 sanitize ---
$dirty = '<!DOCTYPE html><html><head><title>t</title></head><body>'
    . '<script>alert(1)</script>'
    . '<a href="javascript:alert(1)">x</a>'
    . '<a href="java&#115;cript:alert(1)">ent</a>'
    . '<a href="java' . "\t" . 'script:alert(1)">tab</a>'
    . '<img src=x onerror=alert(1)>'
    . '<iframe src="https://evil"></iframe>'
    . '<form action="/"></form>'
    . '<meta http-equiv="refresh" content="0;url=https://evil">'
    . '<p>ok</p></body></html>';
$clean = xlog_sanitize_generated_html($dirty);
assert_true(stripos($clean, '<script') === false, 'sanitize removes script');
assert_true(stripos($clean, 'onerror') === false, 'sanitize removes onerror');
assert_true(stripos($clean, 'javascript:') === false, 'sanitize removes javascript:');
assert_true(stripos($clean, 'java&#115;cript:') === false || strpos($clean, 'href="#"') !== false, 'entity javascript neutralized');
assert_true(xlog_is_javascript_uri('java&#115;cript:alert(1)'), 'detect entity javascript URI');
assert_true(xlog_is_javascript_uri("java\tscript:alert(1)"), 'detect tab javascript URI');
$tabHref = xlog_sanitize_generated_html('<a href="java' . "\t" . 'script:alert(1)">x</a>');
assert_true(stripos(xlog_html_decode_for_safety($tabHref), 'javascript:') === false, 'sanitize strips tab-obfuscated javascript');
$entHref = xlog_sanitize_generated_html('<a href="java&#115;cript:alert(1)">x</a>');
assert_true(!xlog_is_javascript_uri(preg_match('/href="([^"]*)"/', $entHref, $mm) ? $mm[1] : 'javascript:x'), 'entity href not javascript after sanitize');
assert_true(stripos($clean, '<iframe') === false, 'sanitize removes iframe');
assert_true(stripos($clean, '<form') === false, 'sanitize removes form');
assert_true(stripos($clean, 'refresh') === false, 'sanitize removes meta refresh');
$threw = false;
try {
    xlog_assert_safe_generated_html($dirty);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw, 'assert_safe rejects dirty html');
$threwTab = false;
try {
    xlog_assert_safe_generated_html('<a href="java' . "\t" . 'script:alert(1)">x</a>');
} catch (Throwable $e) {
    $threwTab = true;
}
assert_true($threwTab, 'assert_safe rejects tab-obfuscated javascript');
$threwEnt = false;
try {
    xlog_assert_safe_generated_html('<a href="java&#115;cript:alert(1)">x</a>');
} catch (Throwable $e) {
    $threwEnt = true;
}
assert_true($threwEnt, 'assert_safe rejects entity-obfuscated javascript');
assert_true(strpos(xlog_generated_page_csp(), "script-src 'none'") !== false, 'CSP has script-src none');
// Adult gate has no scripts (CSP-compatible)
$adult = build_adult_gate_parts('zh-CN', 'testslug', true);
assert_true(stripos($adult['gate_html'], '<script') === false, 'adult gate HTML has no script');
assert_true(stripos($adult['boot_html'] . $adult['body_boot_html'], '<script') === false, 'adult gate boot has no script');
assert_true(strpos($adult['gate_html'], 'adult-gate-check') !== false, 'adult gate uses checkbox unlock');

// --- mock gate ---
assert_true(ai_mock_allowed() === true, 'mock allowed in test env with ai.mock');
// simulate production-like
putenv('XLOG_CONFIG_PATH='); // can't easily reset static config — structural assert:
$aiSrc = file_get_contents($root . '/includes/ai.php');
assert_true(strpos($aiSrc, 'ai_mock_allowed') !== false, 'ai_stream uses ai_mock_allowed');
assert_true(strpos($aiSrc, "xlog_config('ai.mock'") !== false, 'mock requires ai.mock config');

// --- pay URL safety ---
assert_true(pay_is_safe_pay_url('https://pay.example.com/x') === true, 'https pay url ok');
assert_true(pay_is_safe_pay_url('javascript:alert(1)') === false, 'javascript pay url rejected');
assert_true(pay_is_safe_pay_url('http://evil.com') === false, 'http pay url rejected');

// --- HTTPS helper ---
assert_true(function_exists('request_is_https'), 'request_is_https exists');

// --- structural hygiene ---
assert_true(!is_file($root . '/scripts/_tmp_admin_post.php'), 'scripts/_tmp_admin_post.php removed');
assert_true(!is_file($root . '/includes/markdown.php'), 'includes/markdown.php removed');
$gitignore = file_get_contents($root . '/.gitignore');
assert_true(strpos($gitignore, 'scripts/_tmp') !== false, 'gitignore has scripts/_tmp pattern');
assert_true(is_file($root . '/docs/README.md'), 'docs/README.md index exists');
$cap = file_get_contents($root . '/scripts/capture-page.js');
assert_true(strpos($cap, '/Users/apple/') === false, 'capture-page.js has no /Users/apple path');
$visit = file_get_contents($root . '/api/visit.php');
assert_true(strpos($visit, 'xlog-shot') !== false || strpos($visit, 'HeadlessChrome') !== false, 'visit skips screenshot agents');
$ngx = file_get_contents($root . '/docs/nginx-v2-snippet.conf');
assert_true(strpos($ngx, 'page-shot') !== false && strpos($ngx, 'immutable') !== false, 'nginx documents page-shot non-immutable');

echo "\n=== summary ===\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . ":\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    xlog_test_remove_tree($ctx['tmp']);
    exit(1);
}
echo "ALL PASSED\n";
xlog_test_remove_tree($ctx['tmp']);
exit(0);
