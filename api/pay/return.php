<?php
// Browser return after payment.
// Do NOT fulfill from return signature — only server-side gateway query (or async notify).
require_once __DIR__ . '/../../includes/pay.php';

xlog_start_session();
$params = pay_notify_params();
$orderId = trim((string)($params['out_trade_no'] ?? ''));
$status = 'unknown';

if ($orderId !== '' && preg_match('/^XLOG[A-Z0-9]+$/', $orderId)) {
    $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order) {
        if (($order['status'] ?? '') === 'pending') {
            try {
                pay_sync_order_from_gateway($order);
                $order = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
            } catch (Throwable $ignored) {
            }
        }
        $status = (string)($order['status'] ?? 'unknown');
    } else {
        $status = 'missing';
    }
}

$base = rtrim((string)xlog_config('base_url', 'https://xlog.ink'), '/');
$query = http_build_query([
    'pay' => 'return',
    'order_id' => $orderId,
    'status' => $status,
]);
header('Location: ' . $base . '/?' . $query, true, 302);
exit;
