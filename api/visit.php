<?php
require_once __DIR__ . '/../includes/db.php';

try {
    // Internal Playwright screenshot must not inflate visit stats (AUDIT-7 P2-3).
    $internalShot = (string)($_GET['xlog_shot'] ?? $_POST['xlog_shot'] ?? '');
    $uaEarly = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($internalShot === '1' || stripos($uaEarly, 'xlog-shot') !== false || stripos($uaEarly, 'HeadlessChrome') !== false) {
        // fall through to 1x1 gif without DB write
    } else {
    $slug = strtolower(trim($_GET['slug'] ?? $_POST['slug'] ?? ''));
    if (preg_match('/^[a-z0-9]{3,20}$/', $slug)) {
        $page = db_one('SELECT slug FROM pages WHERE slug = ? AND status = ?', [$slug, 'live']);
        if ($page) {
            $ua = substr($uaEarly, 0, 500);
            $referer = substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
            $path = substr((string)($_GET['path'] ?? $_POST['path'] ?? ''), 0, 300);
            $ip = client_ip();
            $salt = (string)xlog_config('analytics.salt', '');
            if ($salt === '') {
                $salt = hash('sha256', XLOG_ROOT);
            }
            $today = utc_date();
            $visitorHash = hash('sha256', $salt . '|visitor|' . $today . '|' . $ip . '|' . $ua);
            $ipHash = hash('sha256', $salt . '|ip|' . $ip);
            $recentCutoff = gmdate('c', time() - 60);
            $recent = db_one(
                'SELECT id FROM page_visits WHERE slug = ? AND ip_hash = ? AND created_at >= ? LIMIT 1',
                [$slug, $ipHash, $recentCutoff]
            );
            if (!$recent && visit_ip_rate_allowed($ipHash)) {
                db_exec(
                    'INSERT INTO page_visits (slug, visitor_hash, ip_hash, user_agent, referer, path, date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$slug, $visitorHash, $ipHash, $ua, $referer, $path, $today, now_iso()]
                );
            }
        }
    }
    } // end not-internal-shot
} catch (Throwable $e) {
    error_log('visit tracking failed: ' . $e->getMessage());
}

function visit_ip_rate_allowed($ipHash) {
    $limit = max(1, (int)xlog_config('analytics.visit_ip_minute_limit', 120));
    $cutoff = gmdate('c', time() - 60);
    $row = db_one('SELECT COUNT(*) AS c FROM page_visits WHERE ip_hash = ? AND created_at >= ?', [$ipHash, $cutoff]);
    return (int)($row['c'] ?? 0) < $limit;
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
