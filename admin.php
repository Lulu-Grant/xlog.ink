<?php
require_once __DIR__ . '/includes/db.php';

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
    }
    setcookie('xlog_admin', admin_cookie_ticket($configuredToken), [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => admin_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} elseif (!$isLocal) {
    error_log('SECURITY WARNING: xlog admin.token is not configured; refusing non-local admin.php access. Configure /etc/xlog/config.php admin.token before production use.');
    http_response_code(403);
    echo 'Admin token is not configured.';
    exit;
} else {
    error_log('SECURITY WARNING: xlog admin.token is not configured; admin.php is using localhost-only fallback.');
}

$limit = (int)($_GET['limit'] ?? 50);
$limit = max(10, min(200, $limit));
$q = trim((string)($_GET['q'] ?? ''));
$today = utc_date();

$where = "p.status = 'live'";
$params = [];
if ($q !== '') {
    $where .= " AND (p.slug LIKE ? OR p.title LIKE ? OR p.type LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

$totalPages = db_one("SELECT COUNT(*) AS c FROM pages p WHERE $where", $params)['c'] ?? 0;
$todayPages = db_one("SELECT COUNT(*) AS c FROM pages WHERE status = 'live' AND substr(created_at, 1, 10) = ?", [$today])['c'] ?? 0;
$totalVisits = db_one('SELECT COUNT(*) AS c FROM page_visits')['c'] ?? 0;
$todayVisits = db_one('SELECT COUNT(*) AS c FROM page_visits WHERE date = ?', [$today])['c'] ?? 0;
$todayVisitors = db_one('SELECT COUNT(DISTINCT visitor_hash) AS c FROM page_visits WHERE date = ?', [$today])['c'] ?? 0;

$pages = db_all(
    "SELECT
        p.slug, p.title, p.type, p.lang, p.created_at, p.updated_at, p.is_adult, p.editable, p.slug_source,
        COALESCE(v.total_visits, 0) AS total_visits,
        COALESCE(v.today_visits, 0) AS today_visits,
        COALESCE(v.today_visitors, 0) AS today_visitors,
        v.last_visit
    FROM pages p
    LEFT JOIN (
        SELECT slug,
            COUNT(*) AS total_visits,
            SUM(CASE WHEN date = ? THEN 1 ELSE 0 END) AS today_visits,
            COUNT(DISTINCT CASE WHEN date = ? THEN visitor_hash ELSE NULL END) AS today_visitors,
            MAX(created_at) AS last_visit
        FROM page_visits
        GROUP BY slug
    ) v ON v.slug = p.slug
    WHERE $where
    ORDER BY COALESCE(p.updated_at, p.created_at) DESC
    LIMIT $limit",
    array_merge([$today, $today], $params)
);

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

function admin_cookie_ticket($token) {
    return hash_hmac('sha256', 'xlog-admin-v1', (string)$token . '|' . XLOG_ROOT);
}

function admin_request_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return false;
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
    if ($count < $maxAttempts) return 0;
    $firstTs = strtotime((string)($row['first_at'] ?? '')) ?: time();
    return max(1, $lockSeconds - (time() - $firstTs));
}

function fmt_dt($value) {
    if (!$value) return '-';
    return h(str_replace('T', ' ', substr((string)$value, 0, 19)));
}

?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>xlog.ink admin</title>
<style>
:root{--bg:#f4f1ea;--ink:#24211f;--muted:#8b8379;--line:#d8d0c4;--strong:#24211f;--accent:#df7658}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.wrap{width:min(1180px,100%);margin:0 auto;padding:24px}
header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;padding-bottom:16px;border-bottom:2px solid var(--strong)}
h1{margin:0;font-size:22px;letter-spacing:.02em}.muted{color:var(--muted)}
.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:18px 0}
.stat{border:1.5px solid var(--strong);padding:14px;background:transparent}.stat b{display:block;font-size:24px;line-height:1.1}.stat span{color:var(--muted);font-size:12px}
form.search{display:flex;gap:8px;margin:14px 0 18px}input,button,select{font:inherit;border:1.5px solid var(--line);background:transparent;color:var(--ink);height:36px;padding:0 10px}button{border-color:var(--strong);font-weight:700}
table{width:100%;border-collapse:collapse;border:1.5px solid var(--strong);background:transparent}th,td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top;text-align:left}th{font-size:12px;color:var(--muted);font-weight:700}tr:hover{background:rgba(223,118,88,.06)}
.title{max-width:340px;font-weight:700}.slug a{color:var(--accent);font-weight:700;text-decoration:none}.badge{display:inline-flex;border:1px solid var(--line);padding:1px 6px;margin-right:4px}
.num{font-weight:800}.actions a{color:var(--ink);text-decoration:none;border:1px solid var(--strong);padding:4px 7px;display:inline-flex;margin:0 4px 4px 0}
@media(max-width:820px){.wrap{padding:14px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}table{display:block;overflow:auto;white-space:nowrap}header{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">
<header>
    <div>
        <h1>■ xlog.ink admin</h1>
        <div class="muted">近期页面、访问事件和今日去重访客</div>
    </div>
    <div class="muted">UTC 日期：<?= h($today) ?></div>
</header>

<section class="stats">
    <div class="stat"><b><?= (int)$totalPages ?></b><span>页面总数</span></div>
    <div class="stat"><b><?= (int)$todayPages ?></b><span>今日生成</span></div>
    <div class="stat"><b><?= (int)$totalVisits ?></b><span>访问事件</span></div>
    <div class="stat"><b><?= (int)$todayVisits ?></b><span>今日访问</span></div>
    <div class="stat"><b><?= (int)$todayVisitors ?></b><span>今日访客</span></div>
</section>

<form class="search" method="get">
    <input name="q" value="<?= h($q) ?>" placeholder="搜索 slug / 标题 / 类型">
    <select name="limit">
        <?php foreach ([20, 50, 100, 200] as $n): ?><option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
    </select>
    <button>筛选</button>
</form>

<table>
    <thead>
        <tr>
            <th>页面</th>
            <th>类型</th>
            <th>访问</th>
            <th>今日</th>
            <th>最近访问</th>
            <th>创建/更新</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pages as $p): $url = 'https://' . $p['slug'] . '.xlog.ink/'; ?>
        <tr>
            <td>
                <div class="slug"><a href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($p['slug']) ?></a></div>
                <div class="title"><?= h($p['title']) ?></div>
            </td>
            <td><?= h($p['type']) ?><br><span class="muted"><?= h($p['lang']) ?></span></td>
            <td><span class="num"><?= (int)$p['total_visits'] ?></span></td>
            <td><span class="num"><?= (int)$p['today_visits'] ?></span><br><span class="muted"><?= (int)$p['today_visitors'] ?> 人</span></td>
            <td><?= fmt_dt($p['last_visit']) ?></td>
            <td><?= fmt_dt($p['created_at']) ?><br><span class="muted"><?= fmt_dt($p['updated_at']) ?></span></td>
            <td>
                <?php if (!empty($p['is_adult'])): ?><span class="badge">18+</span><?php endif; ?>
                <?php if (!empty($p['editable'])): ?><span class="badge">editable</span><?php endif; ?>
                <?php if (!empty($p['slug_source'])): ?><span class="badge"><?= h($p['slug_source']) ?></span><?php endif; ?>
            </td>
            <td class="actions">
                <a href="<?= h($url) ?>" target="_blank" rel="noopener">打开</a>
                <a href="/site/<?= h($p['slug']) ?>.html" target="_blank" rel="noopener">静态</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$pages): ?>
        <tr><td colspan="8" class="muted">没有匹配页面。</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</body>
</html>
