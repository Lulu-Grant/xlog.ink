<?php
// Pure admin data helpers (tab routing, lists). Callable from admin.php and CLI tests.

require_once __DIR__ . '/db.php';
// pay_enabled() used by overview KPIs
require_once __DIR__ . '/pay.php';

function admin_allowed_tabs() {
    return ['overview', 'pages', 'channels', 'orders', 'users'];
}

/**
 * Normalize tab query; unknown → overview.
 */
function admin_resolve_tab($tab) {
    $tab = strtolower(preg_replace('/[^a-z_]/', '', (string)$tab));
    if ($tab === '') {
        return 'overview';
    }
    return in_array($tab, admin_allowed_tabs(), true) ? $tab : 'overview';
}

/**
 * Build /admin.php URL for a module tab.
 */
function admin_tab_url($tab, array $extra = []) {
    $tab = admin_resolve_tab($tab);
    $query = array_merge(['tab' => $tab], $extra);
    // Drop empty values for cleaner URLs.
    $query = array_filter($query, static function ($v) {
        return $v !== null && $v !== '';
    });
    return '/admin.php?' . http_build_query($query);
}

function admin_credit_grant_allowed() {
    return (bool)xlog_config('admin.allow_credit_grant', false);
}

/**
 * Overview KPI counts only — never the pages+visits list join.
 */
function admin_overview_kpis() {
    $today = utc_date();
    return [
        'total_pages' => (int)(db_one("SELECT COUNT(*) AS c FROM pages WHERE status = 'live'")['c'] ?? 0),
        'today_pages' => (int)(db_one(
            "SELECT COUNT(*) AS c FROM pages WHERE status = 'live' AND substr(created_at, 1, 10) = ?",
            [$today]
        )['c'] ?? 0),
        'total_visits' => (int)(db_one('SELECT COUNT(*) AS c FROM page_visits')['c'] ?? 0),
        'today_visits' => (int)(db_one('SELECT COUNT(*) AS c FROM page_visits WHERE date = ?', [$today])['c'] ?? 0),
        'today_visitors' => (int)(db_one(
            'SELECT COUNT(DISTINCT visitor_hash) AS c FROM page_visits WHERE date = ?',
            [$today]
        )['c'] ?? 0),
        'pending_orders' => (int)(db_one("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'")['c'] ?? 0),
        'paid_orders' => (int)(db_one("SELECT COUNT(*) AS c FROM orders WHERE status = 'paid'")['c'] ?? 0),
        'channel_count' => (int)(db_one('SELECT COUNT(*) AS c FROM pay_channels')['c'] ?? 0),
        'pay_enabled' => pay_enabled(),
        'credit_mode' => (bool)xlog_config('billing.credit_mode', false),
        'user_fallback_daily_generate' => max(0, (int)xlog_config('billing.user_fallback_daily_generate', 2)),
        'guest_generate_quota' => max(0, (int)xlog_config('billing.guest_generate_quota', 5)),
        'signup_credits' => max(0, (int)xlog_config('billing.signup_credits', 10)),
        'today' => $today,
    ];
}

/**
 * Pages list with visit aggregates (heavy query — pages tab only).
 */
function admin_list_pages($q = '', $limit = 50) {
    $limit = max(10, min(200, (int)$limit));
    $q = trim((string)$q);
    $today = utc_date();
    $where = "p.status = 'live'";
    $params = [];
    if ($q !== '') {
        $where .= ' AND (p.slug LIKE ? OR p.title LIKE ? OR p.type LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }
    $rows = db_all(
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
    return is_array($rows) ? $rows : [];
}

/**
 * SQL fragment marker used only by admin_list_pages (for structural tests).
 */
function admin_pages_list_sql_marker() {
    return 'FROM page_visits';
}

/**
 * Orders list. status: all|pending|paid|other exact status.
 */
function admin_list_orders($status = 'all', $limit = 50, $q = '') {
    $limit = max(10, min(200, (int)$limit));
    $status = strtolower(trim((string)$status));
    if ($status === '') {
        $status = 'all';
    }
    $q = trim((string)$q);

    $where = ['1=1'];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $where[] = '(o.id LIKE ? OR IFNULL(u.email, \'\') LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = 'SELECT o.id, o.user_id, o.amount_cents, o.credits, o.status, o.pay_channel, o.channel_id,
                   o.package_id, o.created_at, o.paid_at, u.email AS user_email
            FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.created_at DESC
            LIMIT ' . $limit;
    $rows = db_all($sql, $params);
    return is_array($rows) ? $rows : [];
}

/**
 * Search users by email substring (case-insensitive).
 */
function admin_search_users($q = '', $limit = 30) {
    $limit = max(1, min(100, (int)$limit));
    $q = trim((string)$q);
    if ($q === '') {
        $rows = db_all(
            'SELECT id, email, credits, daily_quota, status, created_at FROM users ORDER BY id DESC LIMIT ' . $limit
        );
        return is_array($rows) ? $rows : [];
    }
    $like = '%' . strtolower($q) . '%';
    $rows = db_all(
        'SELECT id, email, credits, daily_quota, status, created_at
         FROM users
         WHERE lower(email) LIKE ?
         ORDER BY id DESC
         LIMIT ' . $limit,
        [$like]
    );
    return is_array($rows) ? $rows : [];
}

function admin_user_credit_ledger($userId, $limit = 50) {
    $userId = (int)$userId;
    $limit = max(1, min(200, (int)$limit));
    if ($userId <= 0) {
        return [];
    }
    $rows = db_all(
        'SELECT id, user_id, delta, reason, ref, created_at
         FROM credit_transactions
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT ' . $limit,
        [$userId]
    );
    return is_array($rows) ? $rows : [];
}

/**
 * Optional admin grant. Refuses when allow_credit_grant is false.
 *
 * @return array{ok:bool,error?:string,credits?:int}
 */
function admin_grant_credits($userId, $credits, $note = '') {
    if (!admin_credit_grant_allowed()) {
        return ['ok' => false, 'error' => 'credit_grant_disabled'];
    }
    $userId = (int)$userId;
    $credits = (int)$credits;
    $note = trim((string)$note);
    if ($userId <= 0 || $credits <= 0) {
        return ['ok' => false, 'error' => 'bad_request'];
    }
    if ($credits > 100000) {
        return ['ok' => false, 'error' => 'credits_too_large'];
    }
    $user = db_one('SELECT id, credits FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
    if (!$user) {
        return ['ok' => false, 'error' => 'user_not_found'];
    }
    $ref = $note !== '' ? mb_substr($note, 0, 120, 'UTF-8') : 'admin_grant';
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$credits, $userId]);
        $pdo->prepare(
            'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $credits, 'admin_grant', $ref, now_iso()]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        return ['ok' => false, 'error' => 'db_error'];
    }
    $fresh = db_one('SELECT credits FROM users WHERE id = ?', [$userId]);
    return ['ok' => true, 'credits' => (int)($fresh['credits'] ?? 0)];
}

/**
 * Format fen amount for display.
 */
function admin_format_yuan_from_cents($cents) {
    return number_format(((int)$cents) / 100, 2, '.', '');
}
