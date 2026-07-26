<?php
// Async payment notify (multi-channel epay V1/V2).
// Must return plain text "success" and never redirect.

require_once __DIR__ . '/../../includes/pay.php';

@ini_set('display_errors', '0');

function pay_notify_fail($msg = 'fail', array $ctx = []) {
    $orderId = (string)($ctx['out_trade_no'] ?? $ctx['order_id'] ?? '');
    pay_notify_log('fail', $msg, array_merge($ctx, [
        'order_id' => $orderId,
        'response' => $msg,
    ]));
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

function pay_notify_ok(array $ctx = []) {
    pay_notify_log('ok', 'success', $ctx);
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'success';
    exit;
}

try {
    $params = pay_notify_params();
    if (!$params) pay_notify_fail('empty');

    $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
    $tradeNo = trim((string)($params['trade_no'] ?? ''));
    $ctx = [
        'out_trade_no' => $outTradeNo,
        'order_id' => $outTradeNo,
        'trade_no' => $tradeNo,
        'money' => (string)($params['money'] ?? ''),
        'pid' => (string)($params['pid'] ?? ''),
    ];

    $channelId = null;
    if ($outTradeNo !== '' && preg_match('/^XLOG[A-Z0-9]+$/', $outTradeNo)) {
        $order = db_one('SELECT channel_id FROM orders WHERE id = ?', [$outTradeNo]);
        if ($order && !empty($order['channel_id'])) {
            $channelId = (string)$order['channel_id'];
        }
    }

    if (!pay_verify($params, $channelId)) {
        pay_notify_fail('bad sign', $ctx);
    }

    if (!pay_notify_is_paid($params)) {
        pay_notify_fail('not paid', $ctx);
    }

    if ($outTradeNo === '' || !preg_match('/^XLOG[A-Z0-9]+$/', $outTradeNo)) {
        pay_notify_fail('bad order', $ctx);
    }

    // pid must match some configured channel
    $pid = (string)($params['pid'] ?? '');
    if ($pid !== '') {
        $match = false;
        foreach (pay_channels_all() as $ch) {
            if ((string)$ch['pid'] === $pid) { $match = true; break; }
        }
        $legacyPid = (string)xlog_config('pay.pid', '');
        if (!$match && $legacyPid !== '' && $pid === $legacyPid) $match = true;
        if (!$match) pay_notify_fail('bad pid', $ctx);
    }

    $result = pay_fulfill_order($outTradeNo, [
        'trade_no' => $tradeNo,
        'money' => (string)($params['money'] ?? ''),
        'notify_raw' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    if (empty($result['ok'])) {
        pay_notify_fail($result['error'] ?? 'fail', array_merge($ctx, [
            'fulfill_error' => $result['error'] ?? 'fail',
            'already' => !empty($result['already']),
        ]));
    }

    pay_notify_ok(array_merge($ctx, [
        'already' => !empty($result['already']),
        'credits' => (int)($result['credits'] ?? 0),
        'channel_id' => $channelId,
    ]));
} catch (Throwable $e) {
    pay_notify_fail('error', [
        'exception' => $e->getMessage(),
    ]);
}
