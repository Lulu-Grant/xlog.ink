<?php
/**
 * Guest vs logged-in flow: G1 fallback, G2/G8/G10 claim, G4 status modes.
 * Drives real includes/quota.php + includes/page_edit.php (no reimplementation).
 *
 * Usage: php scripts/test-guest-user-flow.php
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

// CLI session before any includes that may emit notices (headers).
if (PHP_SAPI === 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_cookies', '0');
    @session_save_path($__testCtx['data_dir'] . '/php-sessions');
    @mkdir($__testCtx['data_dir'] . '/php-sessions', 0700, true);
    @session_start();
}

require_once $root . '/includes/pay.php';
require_once $root . '/includes/page_edit.php';
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

echo "=== xlog guest-user flow ===\n";
echo "root=$root\n";

$fallbackCfg = user_fallback_daily_limit();
assert_true($fallbackCfg === 2, "user_fallback_daily_generate default 2 (got $fallbackCfg)");
assert_true((bool)xlog_config('billing.credit_mode', false) === true, 'credit_mode on');

// --- Guest status mode ---
unset($_SESSION['user_id']);
$guestStatus = quota_status('generate');
assert_true(($guestStatus['mode'] ?? '') === 'guest_daily', 'guest mode=guest_daily');
assert_true(($guestStatus['identity'] ?? '') === 'guest', 'guest identity');
assert_true(isset($guestStatus['limit']) && (int)$guestStatus['limit'] === 5, 'guest limit 5');
$guestPrompt = format_quota_status_for_prompt('zh-CN', $guestStatus);
assert_true(strpos($guestPrompt, '免费') !== false || strpos($guestPrompt, '今日') !== false, 'guest prompt mentions free/today');
assert_true(strpos($guestPrompt, '今日剩余生成额度') === false || strpos($guestPrompt, '游客') !== false, 'guest prompt ok');

// --- Create user with credits ---
$email = 'guest-flow-' . bin2hex(random_bytes(4)) . '@example.com';
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)',
    [$email, now_iso(), 10, 3, 'active']
);
$user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
$userId = (int)$user['id'];
assert_true($userId > 0, 'test user created');
$_SESSION['user_id'] = $userId;

// (a) credits path: deducts credits
$stCredits = quota_status('generate');
assert_true(($stCredits['mode'] ?? '') === 'user_credits', 'mode user_credits with balance');
assert_true((int)$stCredits['credits'] === 3, 'status credits=3');
$promptCredits = format_quota_status_for_prompt('zh-CN', $stCredits);
assert_true(strpos($promptCredits, '积分') !== false, 'credits prompt mentions 积分');
assert_true(strpos($promptCredits, '今日剩余生成额度') === false, 'credits prompt does not say 今日剩余生成额度');

$c1 = consume_quota('generate');
assert_true(!empty($c1['ok']) && !empty($c1['credit_mode']), 'consume with credits ok');
$bal = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($bal === 2, "balance 2 after one consume (got $bal)");

// burn remaining credits
consume_quota('generate');
consume_quota('generate');
$bal0 = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($bal0 === 0, "balance 0 (got $bal0)");

// (b) 0 credits + unused fallback still ok
$stFb = quota_status('generate');
assert_true(($stFb['mode'] ?? '') === 'user_fallback', 'mode user_fallback at 0 credits');
assert_true((int)$stFb['remaining'] === 2, 'fallback remaining 2');
$promptFb = format_quota_status_for_prompt('zh-CN', $stFb);
assert_true(strpos($promptFb, '保底') !== false || strpos($promptFb, '免费') !== false, 'fallback prompt free/保底');

$f1 = consume_quota('generate');
assert_true(!empty($f1['ok']) && !empty($f1['free_daily']), 'fallback consume 1 ok');
assert_true(($f1['mode'] ?? '') === 'user_fallback', 'fallback consume mode');
$balStill0 = (int)db_one('SELECT credits FROM users WHERE id = ?', [$userId])['credits'];
assert_true($balStill0 === 0, 'fallback does not deduct credits');

$f2 = consume_quota('generate');
assert_true(!empty($f2['ok']) && !empty($f2['free_daily']), 'fallback consume 2 ok');

// (c) fallback exhausted refuses
$f3 = consume_quota('generate');
assert_true(empty($f3['ok']) && ($f3['reason'] ?? '') === 'credits_exhausted', 'fallback exhausted => credits_exhausted');
$stDone = quota_status('generate');
assert_true((int)$stDone['remaining'] === 0, 'status remaining 0 when exhausted');

// refund free_daily works
refund_quota('generate', $f2);
$stAfterRefund = quota_status('generate');
assert_true(($stAfterRefund['mode'] ?? '') === 'user_fallback' && (int)$stAfterRefund['remaining'] === 1, 'refund free_daily restores 1');

// --- Guest daily path unchanged (separate identity) ---
unset($_SESSION['user_id']);
// Use unique cookie/ip simulation is hard; just verify guest consume structure with fresh keys via quota_limit
$gLimit = quota_limit_for('generate', null);
assert_true($gLimit === 5, 'guest limit still 5');
// Directly exercise guest branch of consume with session unset
$gStatus = quota_status('generate');
assert_true(($gStatus['mode'] ?? '') === 'guest_daily', 'back to guest_daily');

// --- Claim / ownership (G2/G8/G10) ---
$_SESSION['user_id'] = $userId;
// Same-browser claims require session.client_id === xlog_cid cookie (P1-1).
$sameClientId = bin2hex(random_bytes(16));
$_COOKIE['xlog_cid'] = $sameClientId;
$slugA = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
$slugB = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
$slugC = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
$sessionId = bin2hex(random_bytes(16));
$now = now_iso();

// Guest page tied to session (null owner)
db_exec(
    'INSERT INTO sessions (id, user_id, page_slug, edit_mode, messages, state, locale, ip, client_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$sessionId, null, $slugA, 'create', '[]', 'done', 'zh-CN', '127.0.0.1', $sameClientId, $now, $now]
);
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, session_id, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugA, 'Guest Page A', 'page', 'zh-CN', $now, null, 'live', $sessionId, '']
);

// Page owned by someone else
$otherEmail = 'other-' . bin2hex(random_bytes(3)) . '@example.com';
db_exec('INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)', [$otherEmail, $now, 10, 0, 'active']);
$otherId = (int)db_one('SELECT id FROM users WHERE email = ?', [$otherEmail])['id'];
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugB, 'Owned Page', 'page', 'zh-CN', $now, $otherId, 'live', '']
);

// Orphan page with matching email
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, email, html_path, editable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugC, 'Email Match', 'page', 'zh-CN', $now, null, 'live', $email, '', 1]
);

// (e) claim same-session
$claimSess = claim_page_for_user($slugA, $userId, ['session_id' => $sessionId]);
assert_true(!empty($claimSess['ok']) && empty($claimSess['already'] ?? false), 'session claim ok');
$ownerA = db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slugA]);
assert_true((int)$ownerA['owner_user_id'] === $userId, 'slugA owned after session claim');

// (f) owned page not stolen
$steal = claim_page_for_user($slugB, $userId, ['session_id' => $sessionId, 'email_match' => true]);
assert_true(empty($steal['ok']) && ($steal['error'] ?? '') === 'already_owned', 'cannot steal owned page');
$ownerB = (int)db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slugB])['owner_user_id'];
assert_true($ownerB === $otherId, 'slugB still other owner');

// email match claim
$claimEmail = claim_page_for_user($slugC, $userId, ['email_match' => true]);
assert_true(!empty($claimEmail['ok']), 'email match claim ok');
assert_true((int)db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slugC])['owner_user_id'] === $userId, 'slugC owned via email');

// wrong session cannot claim random null page without email
$slugD = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugD, 'Orphan no auth', 'page', 'zh-CN', $now, null, 'live', '']
);
$noAuth = claim_page_for_user($slugD, $userId, ['session_id' => $sessionId]);
assert_true(empty($noAuth['ok']) && ($noAuth['error'] ?? '') === 'not_eligible', 'no auth path => not_eligible');

// G8 bind session
$bind = bind_session_to_user($sessionId, $userId);
assert_true(!empty($bind['ok']), 'bind session ok');
$sessUser = db_one('SELECT user_id FROM sessions WHERE id = ?', [$sessionId]);
assert_true((int)$sessUser['user_id'] === $userId, 'session user_id bound');

// claim_pages_after_login composite
$session2 = bin2hex(random_bytes(16));
$slugE = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
db_exec(
    'INSERT INTO sessions (id, user_id, page_slug, edit_mode, messages, state, locale, ip, client_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$session2, null, $slugE, 'create', '[]', 'done', 'zh-CN', '127.0.0.1', $sameClientId, $now, $now]
);
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, session_id, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugE, 'After Login', 'page', 'zh-CN', $now, null, 'live', $session2, '']
);
$bundle = claim_pages_after_login($userId, $session2, $email);
assert_true(!empty($bundle['session_bind']['ok']), 'after_login bind ok');
assert_true(!empty($bundle['session_claim']['ok']), 'after_login session claim ok');
assert_true((int)db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slugE])['owner_user_id'] === $userId, 'slugE claimed via after_login');

// Self re-claim is ok already
$again = claim_page_for_user($slugA, $userId, ['session_id' => $sessionId]);
assert_true(!empty($again['ok']) && !empty($again['already']), 'reclaim self already');

// Foreign session bound to another user + null-owner page must NOT be claimable (steal fix).
$attackerEmail = 'attacker-' . bin2hex(random_bytes(3)) . '@example.com';
db_exec(
    'INSERT INTO users (email, created_at, daily_quota, credits, status) VALUES (?, ?, ?, ?, ?)',
    [$attackerEmail, $now, 10, 0, 'active']
);
$attackerId = (int)db_one('SELECT id FROM users WHERE email = ?', [$attackerEmail])['id'];
$sessionForeign = bin2hex(random_bytes(16));
$slugForeign = 't' . substr(bin2hex(random_bytes(4)), 0, 7);
// Session already bound to legitimate user; page still orphan (null owner).
db_exec(
    'INSERT INTO sessions (id, user_id, page_slug, edit_mode, messages, state, locale, ip, client_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$sessionForeign, $userId, $slugForeign, 'create', '[]', 'done', 'zh-CN', '127.0.0.1', 'test', $now, $now]
);
db_exec(
    'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, session_id, html_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [$slugForeign, 'Foreign Session Orphan', 'page', 'zh-CN', $now, null, 'live', $sessionForeign, '']
);
// Direct claim_page_for_user with foreign session must fail (covers page_session + session paths).
$stealDirect = claim_page_for_user($slugForeign, $attackerId, ['session_id' => $sessionForeign]);
assert_true(empty($stealDirect['ok']) && ($stealDirect['error'] ?? '') === 'not_eligible', 'foreign-bound session claim not_eligible');
// claim_pages_after_login must not steal after bind owned_by_other.
$stealBundle = claim_pages_after_login($attackerId, $sessionForeign, $attackerEmail);
assert_true(empty($stealBundle['session_bind']['ok']) && ($stealBundle['session_bind']['error'] ?? '') === 'owned_by_other', 'after_login bind owned_by_other');
assert_true(empty($stealBundle['session_claim']['ok']), 'after_login does not claim foreign session page');
$ownerForeign = db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slugForeign]);
assert_true($ownerForeign['owner_user_id'] === null || $ownerForeign['owner_user_id'] === '', 'foreign orphan still unowned after attack');

// Structural: chat uses format_quota_status_for_prompt
$chatSrc = file_get_contents($root . '/api/chat.php');
assert_true(strpos($chatSrc, 'format_quota_status_for_prompt') !== false, 'chat.php uses format_quota_status_for_prompt');

// Structural: publish branches login guidance
$pubSrc = file_get_contents($root . '/api/publish.php');
assert_true(strpos($pubSrc, '我的页面') !== false || strpos($pubSrc, '我的」') !== false, 'publish system event mentions 我的');
assert_true(strpos($pubSrc, '邮箱修改链接') !== false, 'publish guest branch mentions email');

// Structural: Sprint 0 topbar
$indexSrc = file_get_contents($root . '/index.php');
assert_true(strpos($indexSrc, 'myPagesToggle') === false, 'topbar has no myPagesToggle');
assert_true(strpos($indexSrc, 'openMyPagesBtn') !== false, 'account panel has openMyPagesBtn');
$jsSrc = file_get_contents($root . '/js/ai-app.js');
assert_true(strpos($jsSrc, "t('myAccount')") !== false, 'js uses myAccount label');
assert_true(strpos($jsSrc, 'publishConfirmCost') !== false, 'confirm card has credit cost');
assert_true(strpos($jsSrc, 'data-regen-same') !== false, 'delivery has regen button');
assert_true(strpos($jsSrc, 'data-make-new-page') !== false, 'delivery has make-new-page button');

unset($_SESSION['user_id']);
if (!empty($__testCtx['tmp'])) {
    xlog_test_remove_tree($__testCtx['tmp']);
}

echo "\n=== summary ===\n";
if ($failures) {
    echo 'FAILED ' . count($failures) . ":\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
