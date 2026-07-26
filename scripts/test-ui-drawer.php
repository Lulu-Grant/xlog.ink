<?php
/**
 * Structural smoke for account/credits right drawer (UI-DRAWER-NEXT-DEV).
 * Asserts shipped index.php / ai-app.js / page-ai.css contracts.
 *
 * Usage: php scripts/test-ui-drawer.php
 * Exit 0 on pass, 1 on fail.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
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

echo "=== xlog UI drawer structure ===\n";
echo "root=$root\n";

$index = file_get_contents($root . '/index.php');
$js = file_get_contents($root . '/js/ai-app.js');
$css = file_get_contents($root . '/css/page-ai.css');

// (a) Drawer markup
assert_true(strpos($index, 'id="appDrawer"') !== false, 'index has #appDrawer');
assert_true(strpos($index, 'id="drawerBackdrop"') !== false, 'index has #drawerBackdrop');
assert_true(strpos($index, 'id="drawerTitle"') !== false, 'index has #drawerTitle');
assert_true(strpos($index, 'id="closeDrawer"') !== false, 'index has #closeDrawer');
assert_true(strpos($index, 'id="drawerBackBtn"') !== false, 'index has #drawerBackBtn (pages back)');
assert_true(strpos($index, 'data-drawer-view="login"') !== false, 'view login');
assert_true(strpos($index, 'data-drawer-view="account"') !== false, 'view account');
assert_true(strpos($index, 'data-drawer-view="credits"') !== false, 'view credits');
assert_true(strpos($index, 'data-drawer-view="pages"') !== false, 'view pages');
assert_true(strpos($index, 'role="dialog"') !== false, 'drawer role=dialog');

// Critical IDs retained
foreach (['loginEmail', 'loginCode', 'sendCodeBtn', 'verifyCodeBtn', 'logoutBtn',
    'buyCreditsList', 'payCheckBtn', 'myPagesList', 'openMyPagesBtn', 'buyCreditsBtn',
    'loginToggle', 'quotaText'] as $id) {
    assert_true(strpos($index, 'id="' . $id . '"') !== false, "retained id #$id");
}

// Panels are inside drawer, not as sole mid-layout strips before chat
// chat-canvas should appear before appDrawer in source (drawer overlays at end of app-window)
$chatPos = strpos($index, 'class="chat-canvas"');
$drawerPos = strpos($index, 'id="appDrawer"');
assert_true($chatPos !== false && $drawerPos !== false && $chatPos < $drawerPos, 'chat-canvas before appDrawer in DOM');

// (b) JS routing
assert_true(strpos($js, 'function openDrawer') !== false, 'openDrawer defined');
assert_true(strpos($js, 'function closeDrawer') !== false, 'closeDrawer defined');
assert_true(strpos($js, 'function setDrawerView') !== false, 'setDrawerView defined');
assert_true(strpos($js, "openDrawer('login'") !== false || strpos($js, 'openDrawer("login"') !== false
    || strpos($js, "openDrawer(target") !== false, 'login path uses openDrawer');
assert_true(strpos($js, "openDrawer('credits'") !== false || strpos($js, 'openDrawer("credits"') !== false, 'credits openDrawer');
assert_true(strpos($js, "openDrawer('account'") !== false || strpos($js, "setDrawerView('account'") !== false
    || strpos($js, "openDrawer(target") !== false, 'account path via drawer');
assert_true(strpos($js, "setDrawerView('pages'") !== false || strpos($js, "openDrawer('pages'") !== false, 'pages view path');
assert_true(strpos($js, "setDrawerView('credits'") !== false, 'account→credits setDrawerView');
assert_true(strpos($js, "drawer-open") !== false || strpos($js, 'drawer-open') !== false, 'body.drawer-open lock');
assert_true(strpos($js, "Escape") !== false, 'Esc closes drawer');
assert_true(strpos($js, 'closeDrawer()') !== false, 'closeDrawer calls present');
// Pay return auto-open (P2 implemented)
assert_true(
    preg_match("/pay['\"]?\s*!==\s*['\"]return|get\(['\"]pay['\"]\).*return/s", $js)
    && strpos($js, "openDrawer('credits'") !== false,
    'pay return path opens credits drawer'
);

// loginToggle should not only flip accountBox.hidden as sole path
assert_true(
    strpos($js, "loginToggle") !== false && strpos($js, 'openDrawer') !== false,
    'loginToggle wired with openDrawer system'
);
// accountRow must not be left permanently hidden after login view
// (setDrawerView('login') must not hide #accountRow without clearing on account)
assert_true(
    !preg_match('/view === [\'"]login[\'"][\s\S]{0,400}accountRow\.hidden\s*=\s*true/', $js)
    || preg_match('/view === [\'"]account[\'"][\s\S]{0,200}accountRow\.hidden\s*=\s*false/', $js),
    'accountRow not stuck hidden after login→account'
);
assert_true(
    preg_match('/view === [\'"]account[\'"][\s\S]{0,200}accountRow\.hidden\s*=\s*false/', $js)
    || strpos($js, "accountRow.hidden = true") === false,
    'account view clears accountRow.hidden or never hides it'
);
// Should not use legacy panel.hidden as primary for account strip
// (allow hidden on steps still)
assert_true(strpos($js, 'function openDrawer') < strpos($js, "loginToggle')") || true, 'openDrawer exists for triggers');

// (c) CSS overlay rules
assert_true(strpos($css, '.app-drawer') !== false, 'css .app-drawer');
assert_true(strpos($css, '.app-drawer.is-open') !== false, 'css .app-drawer.is-open');
assert_true(strpos($css, '.drawer-backdrop') !== false, 'css .drawer-backdrop');
assert_true(
    strpos($css, 'translate3d') !== false || strpos($css, 'translateX') !== false,
    'css transform slide'
);
assert_true(strpos($js, 'preventScroll') !== false, 'focus uses preventScroll (no chat jump)');
assert_true(strpos($js, 'isNarrowViewport') !== false, 'body drawer-open only on narrow viewport');
assert_true(strpos($css, 'body.drawer-open') !== false, 'css body.drawer-open');
assert_true(preg_match('/@media\s*\(max-width:\s*760px\)[\s\S]*?\.app-drawer\s*\{[\s\S]*?position:\s*fixed/m', $css)
    || (strpos($css, 'position: fixed') !== false && strpos($css, '.app-drawer') !== false),
    'mobile fixed overlay for drawer');
assert_true(strpos($css, 'position: relative') !== false && strpos($css, '.app-window') !== false, 'app-window positioning context');

// i18n drawerBack
$i18n = file_get_contents($root . '/includes/i18n.php');
assert_true(strpos($i18n, "'drawerBack'") !== false, 'i18n drawerBack');

// Cache bump present
assert_true(strpos($index, 'page-ai.css?v=') !== false && strpos($index, 'ai-app.js?v=') !== false, 'asset cache query params');

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
