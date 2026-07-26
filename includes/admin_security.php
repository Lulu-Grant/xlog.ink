<?php
// Shared admin auth helpers (cookie ticket + CSRF). Used by admin.php and tests.

function admin_cookie_ticket($token) {
    return hash_hmac('sha256', 'xlog-admin-v1', (string)$token . '|' . XLOG_ROOT);
}

function admin_request_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return false;
}

function admin_self_url() {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/admin.php');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/admin.php';
    $query = parse_url($uri, PHP_URL_QUERY);
    return $query ? ($path . '?' . $query) : $path;
}

/**
 * Issue admin session cookie and mirror into $_COOKIE so same-request CSRF matches.
 * PHP setcookie() does not update $_COOKIE for the current request.
 */
function admin_issue_session_cookie($configuredToken) {
    $ticket = admin_cookie_ticket($configuredToken);
    if (!headers_sent()) {
        setcookie('xlog_admin', $ticket, [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => admin_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    // Always mirror into $_COOKIE so same-request CSRF matches the ticket the browser will store.
    $_COOKIE['xlog_admin'] = $ticket;
    return $ticket;
}

function admin_csrf_token() {
    $secret = (string)xlog_config('admin.token', '');
    if ($secret === '') {
        $secret = XLOG_ROOT . '|local-admin';
    }
    $ticket = (string)($_COOKIE['xlog_admin'] ?? 'no-ticket');
    return hash_hmac('sha256', 'xlog-admin-pay-channel-v1|' . $ticket, $secret);
}

function admin_csrf_ok($posted) {
    $posted = (string)$posted;
    if ($posted === '') return false;
    return hash_equals(admin_csrf_token(), $posted);
}
