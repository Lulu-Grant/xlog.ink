<?php
require_once __DIR__ . '/../../includes/pay.php';

require_method('POST');
xlog_start_session();
$locale = resolve_locale((json_input()['locale'] ?? null));
set_locale_cookie($locale);

if (!pay_enabled()) {
    api_error('pay_disabled', t('api', 'payDisabled', $locale), 503);
}

$userId = current_user_id();
if (!$userId) {
    api_error('login_required', t('api', 'payLoginRequired', $locale), 401);
}

$data = json_input();
$packageId = trim((string)($data['package_id'] ?? ''));
$channelId = trim((string)($data['channel_id'] ?? ''));
$payType = trim((string)($data['channel'] ?? $data['pay_type'] ?? ''));

$channel = null;
if ($channelId !== '') {
    $channel = pay_channel_by_id($channelId);
    if (!$channel || !(int)$channel['enabled'] || !pay_channel_is_configured($channel)) {
        api_error('bad_channel', t('api', 'payBadChannel', $locale));
    }
} else {
    if ($payType === '') $payType = 'alipay';
    if (!isset(pay_channel_pay_types()[$payType])) {
        api_error('bad_channel', t('api', 'payBadChannel', $locale));
    }
    $channel = pay_channel_for_pay_type($payType);
    if (!$channel) {
        api_error('bad_channel', t('api', 'payBadChannel', $locale));
    }
}

$package = pay_package_by_id($packageId);
if (!$package) {
    api_error('bad_package', t('api', 'payBadPackage', $locale));
}

$day = utc_date();
$createdToday = db_one(
    "SELECT COUNT(*) AS c FROM orders WHERE user_id = ? AND substr(created_at, 1, 10) = ?",
    [$userId, $day]
);
if ($createdToday && (int)$createdToday['c'] >= 20) {
    api_error('order_rate_limited', t('api', 'payRateLimited', $locale), 429);
}

$order = null;
try {
    $payType = (string)$channel['pay_type'];
    $order = pay_create_local_order($userId, $package, $payType, (string)$channel['id']);
    $gateway = pay_create_gateway_order($order + [
        'name' => 'xlog积分' . (int)$package['credits'],
        'channel_id' => $channel['id'],
        'pay_channel' => $payType,
    ], $channel);
    if ($gateway['pay_url'] === '' || !pay_is_safe_pay_url($gateway['pay_url'])) {
        db_exec('UPDATE orders SET status = ? WHERE id = ?', ['failed', $order['id']]);
        if ($gateway['pay_url'] !== '' && !pay_is_safe_pay_url($gateway['pay_url'])) {
            error_log('pay_create unsafe pay_url for order ' . $order['id']);
        }
        api_error('pay_create_failed', t('api', 'payCreateFailed', $locale), 502);
    }
    pay_mark_gateway_info($order['id'], $gateway['trade_no'], $gateway['pay_url'], $channel['id']);
    $order = db_one('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    api_json([
        'order' => pay_public_order($order),
        'pay_url' => $gateway['pay_url'],
        'pay_type' => $gateway['pay_type'],
        'channel' => [
            'id' => $channel['id'],
            'name' => $channel['name'],
            'pay_type' => $channel['pay_type'],
        ],
    ]);
} catch (Throwable $e) {
    if (!empty($order['id'])) {
        try {
            db_exec('UPDATE orders SET status = ? WHERE id = ? AND status = ?', ['failed', $order['id'], 'pending']);
        } catch (Throwable $ignored) {
        }
    }
    error_log('pay_create_failed: ' . $e->getMessage());
    api_error('pay_create_failed', t('api', 'payCreateFailed', $locale), 502);
}
