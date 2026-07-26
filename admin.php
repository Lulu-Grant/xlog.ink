<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pay.php';
require_once __DIR__ . '/includes/admin_security.php';
require_once __DIR__ . '/includes/admin_data.php';

$configuredToken = trim((string)xlog_config('admin.token', ''));
$isLocal = in_array(client_ip(), ['127.0.0.1', '::1'], true);

if ($configuredToken !== '') {
    $cookieTicket = trim((string)($_COOKIE['xlog_admin'] ?? ''));
    $hasValidCookie = $cookieTicket !== '' && hash_equals(admin_cookie_ticket($configuredToken), $cookieTicket);
    if (!$hasValidCookie) {
        $lockedSeconds = admin_login_locked_seconds();
        if ($lockedSeconds > 0) {
            admin_login_form(false, $lockedSeconds);
        }

        $postedToken = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            ? trim((string)($_POST['token'] ?? ''))
            : '';
        if ($postedToken === '') {
            admin_login_form(false);
        }
        if (!hash_equals($configuredToken, $postedToken)) {
            admin_record_login_attempt(false);
            admin_login_form(true, admin_login_locked_seconds());
        }

        admin_record_login_attempt(true);
        admin_clear_failed_logins();
        // Fresh login: sync cookie into $_COOKIE for CSRF, then PRG so dashboard
        // forms are never rendered on the bare token POST (avoids ticket mismatch).
        admin_issue_session_cookie($configuredToken);
        $isChannelPost = isset($_POST['pay_channel_action']);
        $isGrantPost = isset($_POST['admin_grant_action']);
        if (!$isChannelPost && !$isGrantPost) {
            header('Location: ' . admin_tab_url('overview'), true, 303);
            exit;
        }
    }
    // Refresh ticket each authenticated request; always mirror into $_COOKIE.
    admin_issue_session_cookie($configuredToken);
} elseif (!$isLocal) {
    error_log('SECURITY WARNING: xlog admin.token is not configured; refusing non-local admin.php access. Configure /etc/xlog/config.php admin.token before production use.');
    http_response_code(403);
    echo 'Admin token is not configured.';
    exit;
} else {
    error_log('SECURITY WARNING: xlog admin.token is not configured; admin.php is using localhost-only fallback.');
}

// Flash via PHP session (PRG).
xlog_start_session();
$adminFlash = (string)($_SESSION['admin_flash'] ?? '');
$adminFlashError = (string)($_SESSION['admin_flash_error'] ?? '');
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_error']);

// --- POST: payment channel CRUD → PRG to channels ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pay_channel_action'])) {
    $action = (string)$_POST['pay_channel_action'];
    try {
        if (!admin_csrf_ok($_POST['csrf'] ?? '')) {
            throw new InvalidArgumentException('CSRF 校验失败，请刷新后台后重试');
        }
        if ($action === 'save') {
            $isNew = !empty($_POST['is_new']);
            $input = [
                'id' => $_POST['id'] ?? '',
                'name' => $_POST['name'] ?? '',
                'pay_type' => $_POST['pay_type'] ?? 'alipay',
                'driver' => $_POST['driver'] ?? 'epay_v1_md5',
                'api_base' => $_POST['api_base'] ?? '',
                'pid' => $_POST['pid'] ?? '',
                'md5_key' => $_POST['md5_key'] ?? '',
                'merchant_private_key' => $_POST['merchant_private_key'] ?? '',
                'platform_public_key' => $_POST['platform_public_key'] ?? '',
                'method' => $_POST['method'] ?? 'jump',
                'enabled' => !empty($_POST['enabled']),
                'sort_order' => (int)($_POST['sort_order'] ?? 100),
                'keep_secrets' => true,
            ];
            pay_channel_save($input, $isNew);
            $_SESSION['admin_flash'] = '支付渠道已保存：' . $input['id'];
        } elseif ($action === 'delete') {
            $id = trim((string)($_POST['id'] ?? ''));
            $del = pay_channel_delete($id);
            if (empty($del['ok'])) {
                throw new InvalidArgumentException('无法停用渠道：' . ($del['error'] ?? 'error'));
            }
            $n = (int)($del['orders'] ?? 0);
            $_SESSION['admin_flash'] = $n > 0
                ? ('渠道已停用（保留密钥供 ' . $n . ' 笔历史订单对账）：' . $id)
                : ('渠道已停用：' . $id);
        } elseif ($action === 'toggle') {
            $id = trim((string)($_POST['id'] ?? ''));
            $ch = pay_channel_by_id($id);
            if (!$ch) {
                throw new InvalidArgumentException('渠道不存在');
            }
            $want = isset($_POST['enabled_to']) ? (int)$_POST['enabled_to'] : ((int)$ch['enabled'] ? 0 : 1);
            if ($want === 1 && !pay_channel_is_configured($ch)) {
                throw new InvalidArgumentException('渠道密钥不完整，无法启用');
            }
            db_exec('UPDATE pay_channels SET enabled = ?, updated_at = ? WHERE id = ?', [$want, now_iso(), $id]);
            $_SESSION['admin_flash'] = '渠道 ' . $id . ' 已' . ($want ? '启用' : '停用');
        } else {
            throw new InvalidArgumentException('未知操作');
        }
    } catch (Throwable $e) {
        $_SESSION['admin_flash_error'] = $e->getMessage();
    }
    header('Location: ' . admin_tab_url('channels'), true, 303);
    exit;
}

// --- POST: optional credit grant → PRG to users ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['admin_grant_action'])) {
    $uid = (int)($_POST['user_id'] ?? 0);
    try {
        if (!admin_csrf_ok($_POST['csrf'] ?? '')) {
            throw new InvalidArgumentException('CSRF 校验失败，请刷新后台后重试');
        }
        $result = admin_grant_credits($uid, (int)($_POST['credits'] ?? 0), (string)($_POST['note'] ?? ''));
        if (empty($result['ok'])) {
            $err = (string)($result['error'] ?? 'grant_failed');
            if ($err === 'credit_grant_disabled') {
                throw new InvalidArgumentException('积分补发未开启（admin.allow_credit_grant）');
            }
            throw new InvalidArgumentException('补发失败：' . $err);
        }
        $_SESSION['admin_flash'] = '已补发积分，当前余额 ' . (int)$result['credits'];
    } catch (Throwable $e) {
        $_SESSION['admin_flash_error'] = $e->getMessage();
    }
    header('Location: ' . admin_tab_url('users', [
        'user_id' => $uid > 0 ? $uid : null,
        'q' => trim((string)($_GET['q'] ?? $_POST['q'] ?? '')),
    ]), true, 303);
    exit;
}

// --- GET tab routing (lazy data) ---
$tab = admin_resolve_tab($_GET['tab'] ?? 'overview');
$today = utc_date();
$limit = (int)($_GET['limit'] ?? 50);
$limit = max(10, min(200, $limit));
$q = trim((string)($_GET['q'] ?? ''));

// Defaults for partials
$kpis = null;
$pages = null;
$payChannels = null;
$editChannel = null;
$orders = null;
$orderStatus = 'all';
$users = null;
$ledger = [];
$focusUserId = null;
$grantAllowed = admin_credit_grant_allowed();

if ($tab === 'overview') {
    $kpis = admin_overview_kpis();
} elseif ($tab === 'pages') {
    $pages = admin_list_pages($q, $limit);
} elseif ($tab === 'channels') {
    $editChannelId = trim((string)($_GET['edit_channel'] ?? ''));
    $editChannel = $editChannelId !== '' ? pay_channel_by_id($editChannelId) : null;
    $payChannels = pay_channels_all();
} elseif ($tab === 'orders') {
    $orderStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
    if ($orderStatus === '') {
        $orderStatus = 'all';
    }
    $orders = admin_list_orders($orderStatus, $limit, $q);
} elseif ($tab === 'users') {
    $userLimit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    $limit = $userLimit;
    $users = admin_search_users($q, $userLimit);
    $focusUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    if ($focusUserId > 0) {
        $ledger = admin_user_credit_ledger($focusUserId, 50);
    }
}

function admin_login_form($failed = false, $lockedSeconds = 0) {
    http_response_code($lockedSeconds > 0 ? 429 : ($failed ? 403 : 200));
    echo '<!doctype html><meta charset="utf-8"><title>xlog admin</title><style>body{font:16px ui-monospace,monospace;background:#f4f1ea;color:#23211f;padding:40px}input,button{font:inherit;padding:10px;border:1px solid #23211f;background:transparent}button{background:#df7658;color:#fff}</style>';
    echo '<form method="post"><h1>xlog admin</h1>';
    if ($lockedSeconds > 0) {
        echo '<p>尝试次数过多，请 ' . h((string)ceil($lockedSeconds / 60)) . ' 分钟后再试。</p>';
    } elseif ($failed) {
        echo '<p>Token 不正确。</p>';
    }
    echo '<input name="token" type="password" placeholder="admin token" autofocus ' . ($lockedSeconds > 0 ? 'disabled' : '') . '> <button ' . ($lockedSeconds > 0 ? 'disabled' : '') . '>进入</button></form>';
    exit;
}

function admin_login_ip_hash() {
    $salt = (string)xlog_config('analytics.salt', '');
    if ($salt === '') {
        $salt = hash('sha256', XLOG_ROOT . '|' . xlog_config('admin.token', ''));
    }
    return hash('sha256', $salt . '|admin-login|' . client_ip());
}

function admin_record_login_attempt($success) {
    $ipHash = admin_login_ip_hash();
    db_exec('INSERT INTO admin_login_attempts (ip_hash, success, created_at) VALUES (?, ?, ?)', [$ipHash, $success ? 1 : 0, now_iso()]);
    db_exec('DELETE FROM admin_login_attempts WHERE created_at < ?', [gmdate('c', time() - 86400 * 7)]);
}

function admin_clear_failed_logins() {
    db_exec('DELETE FROM admin_login_attempts WHERE ip_hash = ? AND success = 0', [admin_login_ip_hash()]);
}

function admin_login_locked_seconds() {
    $maxAttempts = max(3, (int)xlog_config('admin.max_attempts', 8));
    $lockSeconds = max(60, (int)xlog_config('admin.lock_seconds', 900));
    $cutoff = gmdate('c', time() - $lockSeconds);
    $row = db_one(
        'SELECT COUNT(*) AS c, MIN(created_at) AS first_at FROM admin_login_attempts WHERE ip_hash = ? AND success = 0 AND created_at >= ?',
        [admin_login_ip_hash(), $cutoff]
    );
    $count = (int)($row['c'] ?? 0);
    if ($count < $maxAttempts) {
        return 0;
    }
    $firstTs = strtotime((string)($row['first_at'] ?? '')) ?: time();
    return max(1, $lockSeconds - (time() - $firstTs));
}

function fmt_dt($value) {
    if (!$value) {
        return '-';
    }
    return h(str_replace('T', ' ', substr((string)$value, 0, 19)));
}

require __DIR__ . '/partials/admin/layout.php';
