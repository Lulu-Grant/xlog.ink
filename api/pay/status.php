<?php
require_once __DIR__ . '/../../includes/pay.php';

require_method('POST');
xlog_start_session();
$locale = resolve_locale((json_input()['locale'] ?? null));
set_locale_cookie($locale);

$userId = current_user_id();
if (!$userId) {
    api_error('login_required', t('api', 'payLoginRequired', $locale), 401);
}

$data = json_input();
$orderId = trim((string)($data['order_id'] ?? ''));
if ($orderId === '' || !preg_match('/^XLOG[A-Z0-9]+$/', $orderId)) {
    api_error('bad_order', t('api', 'payBadOrder', $locale));
}

$order = db_one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $userId]);
if (!$order) {
    api_error('order_not_found', t('api', 'payOrderNotFound', $locale), 404);
}

if (($order['status'] ?? '') === 'pending') {
    try {
        pay_sync_order_from_gateway($order);
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    } catch (Throwable $e) {
        // keep pending; client may retry
    }
}

$user = db_one('SELECT id, email, daily_quota, credits FROM users WHERE id = ?', [$userId]);
api_json([
    'order' => pay_public_order($order),
    'user' => $user,
    'quota' => quota_status('generate'),
]);
