<?php
// Multi-channel payment (epay V1 MD5 / V2 RSA) + credit order fulfillment.
// Channels live in SQLite `pay_channels` and can be managed from admin.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/quota.php';

function pay_enabled() {
    if (!xlog_config('billing.credit_mode', false)) return false;
    if (!(bool)xlog_config('pay.enabled', true)) return false;
    return count(pay_channels_enabled()) > 0;
}

function pay_packages() {
    $packages = xlog_config('billing.packages', []);
    if (!is_array($packages) || !$packages) {
        $packages = [
            ['id' => 'c10', 'credits' => 10, 'amount_cents' => 1000],
            ['id' => 'c30', 'credits' => 30, 'amount_cents' => 2800],
            ['id' => 'c100', 'credits' => 100, 'amount_cents' => 8800],
            ['id' => 'c500', 'credits' => 500, 'amount_cents' => 39800],
        ];
    }
    $out = [];
    foreach ($packages as $pkg) {
        if (!is_array($pkg)) continue;
        $id = trim((string)($pkg['id'] ?? ''));
        $credits = (int)($pkg['credits'] ?? 0);
        $cents = (int)($pkg['amount_cents'] ?? 0);
        if ($id === '' || $credits <= 0 || $cents < 100) continue;
        $out[] = [
            'id' => $id,
            'credits' => $credits,
            'amount_cents' => $cents,
            'amount_yuan' => number_format($cents / 100, 2, '.', ''),
            'label' => (string)($pkg['label'] ?? ''),
        ];
    }
    return $out;
}

function pay_package_by_id($id) {
    $id = trim((string)$id);
    foreach (pay_packages() as $pkg) {
        if ($pkg['id'] === $id) return $pkg;
    }
    return null;
}

function pay_notify_url() {
    $u = trim((string)xlog_config('pay.notify_url', ''));
    if ($u !== '') return $u;
    return rtrim((string)xlog_config('base_url', 'https://xlog.ink'), '/') . '/api/pay/notify.php';
}

function pay_return_url() {
    $u = trim((string)xlog_config('pay.return_url', ''));
    if ($u !== '') return $u;
    return rtrim((string)xlog_config('base_url', 'https://xlog.ink'), '/') . '/api/pay/return.php';
}

/* ---------- channels ---------- */

function pay_channel_drivers() {
    return [
        'epay_v1_md5' => '易支付 V1 (MD5)',
        'epay_v2_rsa' => '易支付 V2 (RSA)',
    ];
}

function pay_channel_pay_types() {
    return [
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
    ];
}

function pay_channels_all() {
    pay_channels_ensure_seeded();
    $rows = db_all('SELECT * FROM pay_channels ORDER BY sort_order ASC, id ASC');
    return array_map('pay_channel_hydrate_secrets', $rows);
}

function pay_channels_enabled() {
    pay_channels_ensure_seeded();
    $rows = db_all('SELECT * FROM pay_channels WHERE enabled = 1 ORDER BY sort_order ASC, id ASC');
    $out = [];
    foreach ($rows as $row) {
        $row = pay_channel_hydrate_secrets($row);
        if (pay_channel_is_configured($row)) $out[] = $row;
    }
    return $out;
}

function pay_channel_by_id($id) {
    $id = trim((string)$id);
    if ($id === '') return null;
    pay_channels_ensure_seeded();
    $row = db_one('SELECT * FROM pay_channels WHERE id = ?', [$id]);
    return $row ? pay_channel_hydrate_secrets($row) : null;
}

/**
 * AUDIT-8 P2-2: resolve secrets from external config when secret_ref is set.
 * Map: pay.secrets.<ref> = { md5_key?, merchant_private_key?, platform_public_key? }
 * Or file /etc/xlog/secrets.php returning the same map under 'pay_channels'.
 * Plaintext DB columns still work when secret_ref is empty (residual until full cutover).
 */
function pay_channel_hydrate_secrets(array $ch) {
    $ref = trim((string)($ch['secret_ref'] ?? ''));
    if ($ref === '') {
        return $ch;
    }
    $bundle = null;
    $map = xlog_config('pay.secrets', []);
    if (is_array($map) && isset($map[$ref]) && is_array($map[$ref])) {
        $bundle = $map[$ref];
    } else {
        $path = '/etc/xlog/secrets.php';
        if (is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $channels = $loaded['pay_channels'] ?? $loaded;
                if (is_array($channels) && isset($channels[$ref]) && is_array($channels[$ref])) {
                    $bundle = $channels[$ref];
                }
            }
        }
    }
    if (!is_array($bundle)) {
        return $ch;
    }
    foreach (['md5_key', 'merchant_private_key', 'platform_public_key'] as $k) {
        if (isset($bundle[$k]) && trim((string)$bundle[$k]) !== '') {
            $ch[$k] = (string)$bundle[$k];
        }
    }
    return $ch;
}

function pay_channel_for_pay_type($payType) {
    $payType = trim((string)$payType);
    if ($payType === '') $payType = 'alipay';
    foreach (pay_channels_enabled() as $ch) {
        if (($ch['pay_type'] ?? '') === $payType) return $ch;
    }
    return null;
}

function pay_public_channels() {
    $out = [];
    foreach (pay_channels_enabled() as $ch) {
        $out[] = [
            'id' => $ch['id'],
            'name' => $ch['name'],
            'pay_type' => $ch['pay_type'],
            'driver' => $ch['driver'],
        ];
    }
    return $out;
}

function pay_channel_is_configured(array $ch) {
    $pid = trim((string)($ch['pid'] ?? ''));
    $api = trim((string)($ch['api_base'] ?? ''));
    if ($pid === '' || $api === '') return false;
    $driver = (string)($ch['driver'] ?? 'epay_v1_md5');
    if ($driver === 'epay_v2_rsa') {
        return trim((string)($ch['merchant_private_key'] ?? '')) !== ''
            && trim((string)($ch['platform_public_key'] ?? '')) !== '';
    }
    return trim((string)($ch['md5_key'] ?? '')) !== '';
}

function pay_channels_ensure_seeded() {
    static $done = false;
    if ($done) return;
    $done = true;
    db(); // ensure schema

    $count = db_one('SELECT COUNT(*) AS c FROM pay_channels');
    if ($count && (int)$count['c'] > 0) return;

    $now = now_iso();
    $seeded = [];

    // Legacy single-channel config → alipay RSA channel
    $pid = trim((string)xlog_config('pay.pid', ''));
    $priv = trim((string)xlog_config('pay.merchant_private_key', ''));
    $pub = trim((string)xlog_config('pay.platform_public_key', ''));
    $md5 = trim((string)xlog_config('pay.md5_key', ''));
    $api = rtrim((string)xlog_config('pay.api_base', 'https://api.xuanfanpay.top'), '/');
    if ($pid !== '' && $priv !== '' && $pub !== '') {
        $seeded[] = [
            'id' => 'alipay_main',
            'name' => '支付宝',
            'pay_type' => 'alipay',
            'driver' => 'epay_v2_rsa',
            'api_base' => $api,
            'pid' => $pid,
            'md5_key' => $md5,
            'merchant_private_key' => $priv,
            'platform_public_key' => $pub,
            'method' => (string)xlog_config('pay.method', 'jump'),
            'enabled' => 1,
            'sort_order' => 10,
        ];
    } elseif ($pid !== '' && $md5 !== '') {
        $seeded[] = [
            'id' => 'alipay_main',
            'name' => '支付宝',
            'pay_type' => 'alipay',
            'driver' => 'epay_v1_md5',
            'api_base' => $api,
            'pid' => $pid,
            'md5_key' => $md5,
            'merchant_private_key' => '',
            'platform_public_key' => '',
            'method' => 'jump',
            'enabled' => 1,
            'sort_order' => 10,
        ];
    }

    // Optional config list pay.channels
    $cfgChannels = xlog_config('pay.channels', []);
    if (is_array($cfgChannels)) {
        $i = 0;
        foreach ($cfgChannels as $ch) {
            if (!is_array($ch)) continue;
            $id = trim((string)($ch['id'] ?? ''));
            if ($id === '') continue;
            $seeded[] = [
                'id' => $id,
                'name' => (string)($ch['name'] ?? $id),
                'pay_type' => (string)($ch['pay_type'] ?? 'alipay'),
                'driver' => (string)($ch['driver'] ?? 'epay_v1_md5'),
                'api_base' => rtrim((string)($ch['api_base'] ?? ''), '/'),
                'pid' => (string)($ch['pid'] ?? ''),
                'md5_key' => (string)($ch['md5_key'] ?? ''),
                'merchant_private_key' => (string)($ch['merchant_private_key'] ?? ''),
                'platform_public_key' => (string)($ch['platform_public_key'] ?? ''),
                'method' => (string)($ch['method'] ?? 'jump'),
                'enabled' => !empty($ch['enabled']) ? 1 : 0,
                'sort_order' => (int)($ch['sort_order'] ?? (20 + $i)),
            ];
            $i++;
        }
    }

    foreach ($seeded as $ch) {
        if (!pay_channel_is_configured($ch) && empty($ch['enabled'])) continue;
        db_exec(
            'INSERT OR IGNORE INTO pay_channels
            (id, name, pay_type, driver, api_base, pid, md5_key, merchant_private_key, platform_public_key, method, enabled, sort_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $ch['id'], $ch['name'], $ch['pay_type'], $ch['driver'], $ch['api_base'], $ch['pid'],
                $ch['md5_key'], $ch['merchant_private_key'], $ch['platform_public_key'], $ch['method'],
                (int)$ch['enabled'], (int)$ch['sort_order'], $now, $now,
            ]
        );
    }
}

function pay_channel_save(array $input, $isNew = false) {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)($input['id'] ?? '')));
    if ($id === '' || strlen($id) > 40) {
        throw new InvalidArgumentException('渠道 ID 需为 1-40 位字母数字下划线');
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('渠道名称不能为空');
    $payType = (string)($input['pay_type'] ?? 'alipay');
    if (!isset(pay_channel_pay_types()[$payType])) throw new InvalidArgumentException('支付方式无效');
    $driver = (string)($input['driver'] ?? 'epay_v1_md5');
    if (!isset(pay_channel_drivers()[$driver])) throw new InvalidArgumentException('驱动无效');
    $apiBase = rtrim(trim((string)($input['api_base'] ?? '')), '/');
    $allowHttp = (bool)xlog_config('pay.allow_http_api', false)
        || in_array(strtolower((string)xlog_config('app.env', '')), ['local', 'test', 'dev'], true);
    if ($apiBase === '' || !preg_match('#^https://#i', $apiBase)) {
        if (!($allowHttp && preg_match('#^https?://#i', $apiBase))) {
            throw new InvalidArgumentException('生产环境 API 地址必须以 https:// 开头');
        }
    }
    $pid = trim((string)($input['pid'] ?? ''));
    if ($pid === '') throw new InvalidArgumentException('商户 PID 不能为空');
    $md5 = trim((string)($input['md5_key'] ?? ''));
    $priv = trim((string)($input['merchant_private_key'] ?? ''));
    $pub = trim((string)($input['platform_public_key'] ?? ''));
    $method = trim((string)($input['method'] ?? 'jump'));
    if ($method === '') $method = 'jump';
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $sort = (int)($input['sort_order'] ?? 100);
    $now = now_iso();

    $existing = db_one('SELECT * FROM pay_channels WHERE id = ?', [$id]);
    if ($isNew && $existing) throw new InvalidArgumentException('渠道 ID 已存在');
    if (!$isNew && !$existing) throw new InvalidArgumentException('渠道不存在');

    // Keep secrets if form left blank on edit
    if (!$isNew && $existing) {
        if ($md5 === '') $md5 = (string)$existing['md5_key'];
        if ($priv === '') $priv = (string)$existing['merchant_private_key'];
        if ($pub === '') $pub = (string)$existing['platform_public_key'];
    }

    $row = [
        'id' => $id,
        'name' => $name,
        'pay_type' => $payType,
        'driver' => $driver,
        'api_base' => $apiBase,
        'pid' => $pid,
        'md5_key' => $md5,
        'merchant_private_key' => $priv,
        'platform_public_key' => $pub,
        'method' => $method,
        'enabled' => $enabled,
        'sort_order' => $sort,
    ];
    if ($enabled && !pay_channel_is_configured($row)) {
        throw new InvalidArgumentException('启用渠道前请补全对应密钥（V1 需 MD5，V2 需 RSA 公私钥）');
    }

    if ($existing) {
        db_exec(
            'UPDATE pay_channels SET name=?, pay_type=?, driver=?, api_base=?, pid=?, md5_key=?, merchant_private_key=?, platform_public_key=?, method=?, enabled=?, sort_order=?, updated_at=? WHERE id=?',
            [$name, $payType, $driver, $apiBase, $pid, $md5, $priv, $pub, $method, $enabled, $sort, $now, $id]
        );
    } else {
        db_exec(
            'INSERT INTO pay_channels (id, name, pay_type, driver, api_base, pid, md5_key, merchant_private_key, platform_public_key, method, enabled, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $name, $payType, $driver, $apiBase, $pid, $md5, $priv, $pub, $method, $enabled, $sort, $now, $now]
        );
    }
    return pay_channel_by_id($id);
}

/**
 * Soft-disable a channel (AUDIT-7 P1-2). Never hard-delete rows that may be needed
 * for delayed notify/query signatures. Physical delete only when no orders reference it.
 *
 * @return array{ok:bool,error?:string,soft?:bool}
 */
function pay_channel_delete($id) {
    $id = trim((string)$id);
    if ($id === '') {
        return ['ok' => false, 'error' => 'bad_id'];
    }
    $ch = pay_channel_by_id($id);
    if (!$ch) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $ref = db_one('SELECT COUNT(*) AS c FROM orders WHERE channel_id = ?', [$id]);
    $n = (int)($ref['c'] ?? 0);
    if ($n > 0) {
        // Soft-disable only — keep secrets for historical verify.
        db_exec('UPDATE pay_channels SET enabled = 0, updated_at = ? WHERE id = ?', [now_iso(), $id]);
        return ['ok' => true, 'soft' => true, 'orders' => $n];
    }
    // No order references: soft-disable still preferred over hard delete.
    db_exec('UPDATE pay_channels SET enabled = 0, updated_at = ? WHERE id = ?', [now_iso(), $id]);
    return ['ok' => true, 'soft' => true, 'orders' => 0];
}

/**
 * Resolve channel for an order: only order.channel_id (no silent fallback).
 */
function pay_channel_for_order(array $order) {
    $channelId = trim((string)($order['channel_id'] ?? ''));
    if ($channelId === '') {
        return null;
    }
    return pay_channel_by_id($channelId);
}

/* ---------- crypto / http ---------- */

function pay_normalize_pem($raw, $type = 'PRIVATE KEY') {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (strpos($raw, '-----BEGIN') !== false) return $raw;
    $raw = preg_replace('/\s+/', '', $raw);
    return "-----BEGIN {$type}-----\n" . chunk_split($raw, 64, "\n") . "-----END {$type}-----";
}

function pay_sign_string(array $params) {
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') continue;
        if ($v === null || $v === '') continue;
        if (is_bool($v)) $v = $v ? '1' : '0';
        if (is_array($v) || is_object($v)) continue;
        $parts[] = $k . '=' . $v;
    }
    return implode('&', $parts);
}

function pay_sign_md5(array $params, $md5Key) {
    return md5(pay_sign_string($params) . $md5Key);
}

function pay_sign_rsa(array $params, $privateKeyPem) {
    $pem = pay_normalize_pem($privateKeyPem, 'PRIVATE KEY');
    $key = openssl_pkey_get_private($pem);
    if ($key === false) throw new RuntimeException('Invalid merchant private key');
    $raw = '';
    if (!openssl_sign(pay_sign_string($params), $raw, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Payment RSA sign failed');
    }
    return base64_encode($raw);
}

function pay_verify_with_channel(array $params, array $channel) {
    $sign = (string)($params['sign'] ?? '');
    if ($sign === '') return false;
    $driver = (string)($channel['driver'] ?? 'epay_v1_md5');
    if ($driver === 'epay_v2_rsa') {
        $pem = pay_normalize_pem($channel['platform_public_key'] ?? '', 'PUBLIC KEY');
        $key = openssl_pkey_get_public($pem);
        if ($key === false) return false;
        $decoded = base64_decode($sign, true);
        if ($decoded === false) return false;
        return openssl_verify(pay_sign_string($params), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }
    $expect = pay_sign_md5($params, (string)($channel['md5_key'] ?? ''));
    return hash_equals(strtolower($expect), strtolower($sign));
}

/**
 * Verify notify/query signature for a specific channel only (AUDIT-7 P1-2).
 * No fallback across other channels or legacy config when channel_id is set/missing.
 */
function pay_verify(array $params, $channelId = null) {
    $channelId = trim((string)$channelId);
    if ($channelId === '') {
        return false;
    }
    $ch = pay_channel_by_id($channelId);
    if (!$ch || !pay_channel_is_configured($ch)) {
        return false;
    }
    return pay_verify_with_channel($params, $ch);
}

function pay_legacy_channel_from_config() {
    $pid = trim((string)xlog_config('pay.pid', ''));
    if ($pid === '') return null;
    $priv = trim((string)xlog_config('pay.merchant_private_key', ''));
    $pub = trim((string)xlog_config('pay.platform_public_key', ''));
    $md5 = trim((string)xlog_config('pay.md5_key', ''));
    $api = rtrim((string)xlog_config('pay.api_base', ''), '/');
    if ($priv !== '' && $pub !== '') {
        return [
            'id' => 'legacy', 'driver' => 'epay_v2_rsa', 'api_base' => $api, 'pid' => $pid,
            'md5_key' => $md5, 'merchant_private_key' => $priv, 'platform_public_key' => $pub, 'method' => 'jump',
        ];
    }
    if ($md5 !== '') {
        return [
            'id' => 'legacy', 'driver' => 'epay_v1_md5', 'api_base' => $api, 'pid' => $pid,
            'md5_key' => $md5, 'merchant_private_key' => '', 'platform_public_key' => '', 'method' => 'jump',
        ];
    }
    return null;
}

function pay_http_post_raw($url, array $params) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($errno) throw new RuntimeException('Payment gateway error: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException('Payment gateway HTTP ' . $status);
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('Payment gateway returned invalid JSON');
    return $data;
}

function pay_new_order_id() {
    return 'XLOG' . gmdate('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

function pay_money_from_cents($cents) {
    return number_format(((int)$cents) / 100, 2, '.', '');
}

/**
 * Parse gateway money string to integer fen (cents).
 * Accepts "10", "10.0", "10.00". Rejects empty invalid / >2 decimal places.
 * Returns null on invalid input.
 */
function pay_parse_money_to_cents($money) {
    $s = trim((string)$money);
    if ($s === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int)round(((float)$s) * 100);
}

/**
 * Compare gateway money with local order amount_cents (fen).
 * AUDIT-7 P1-3: empty/missing money is NOT a match (fail closed).
 */
function pay_money_equal($gatewayMoney, $amountCents) {
    if ($gatewayMoney === null || $gatewayMoney === '') {
        return false;
    }
    $gatewayCents = pay_parse_money_to_cents($gatewayMoney);
    if ($gatewayCents === null) {
        return false;
    }
    return $gatewayCents === (int)$amountCents;
}

/** Require non-empty money that matches order fen amount. */
function pay_money_required_match($gatewayMoney, $amountCents) {
    return pay_money_equal($gatewayMoney, $amountCents);
}

function pay_create_gateway_order(array $order, $channel = null) {
    if ($channel === null) {
        $channel = pay_channel_for_order($order);
        // Creating a new gateway order may still pick by pay_type when channel_id not yet set.
        if (!$channel) {
            $channel = pay_channel_for_pay_type($order['pay_channel'] ?? 'alipay');
        }
    }
    if (!$channel || !pay_channel_is_configured($channel)) {
        throw new RuntimeException('支付渠道不可用');
    }

    $driver = (string)($channel['driver'] ?? 'epay_v1_md5');
    $type = (string)($order['pay_channel'] ?: $channel['pay_type'] ?: 'alipay');
    $base = rtrim((string)$channel['api_base'], '/');
    $params = [
        'pid' => (string)$channel['pid'],
        'type' => $type,
        'out_trade_no' => (string)$order['id'],
        'notify_url' => pay_notify_url(),
        'return_url' => pay_return_url(),
        'name' => (string)($order['name'] ?? ('xlog credits ' . (int)$order['credits'])),
        'money' => pay_money_from_cents($order['amount_cents']),
        'clientip' => (string)($order['client_ip'] ?: client_ip()),
    ];
    if (!empty($order['package_id'])) {
        $params['param'] = (string)$order['package_id'];
    }

    if ($driver === 'epay_v2_rsa') {
        $params['method'] = (string)($channel['method'] ?: 'jump');
        $params['timestamp'] = (string)time();
        $params['sign_type'] = 'RSA';
        $params['sign'] = pay_sign_rsa($params, $channel['merchant_private_key']);
        $resp = pay_http_post_raw($base . '/api/pay/create', $params);
        if ((int)($resp['code'] ?? -1) !== 0) {
            throw new RuntimeException((string)($resp['msg'] ?? 'create failed'));
        }
        if (!empty($resp['sign']) && !pay_verify_with_channel($resp, $channel)) {
            throw new RuntimeException('Payment create response signature invalid');
        }
        $payUrl = (string)($resp['pay_info'] ?? $resp['payurl'] ?? $resp['qrcode'] ?? '');
        return [
            'trade_no' => (string)($resp['trade_no'] ?? ''),
            'pay_type' => (string)($resp['pay_type'] ?? ''),
            'pay_url' => $payUrl,
            'channel_id' => $channel['id'],
            'raw' => $resp,
        ];
    }

    // MD5 channels: prefer modern /api/pay/create (e.xhmcn.com doc), fallback legacy mapi.php
    return pay_create_gateway_order_md5($base, $channel, $params, $type);
}

/**
 * Create order with MD5 merchant key.
 * Newer gateways (e.xhmcn.com): POST /api/pay/create, code=0, pay_info.
 * Legacy: POST /mapi.php, code=1, payurl|qrcode.
 */
function pay_create_gateway_order_md5($base, array $channel, array $params, $type) {
    $method = (string)($channel['method'] ?: 'jump');
    if ($method === '') $method = 'jump';

    // Official page-jump / unified-order style (see e.xhmcn.com/doc/pay_create.html)
    $v2 = $params;
    $v2['method'] = $method;
    if ($method === 'web' && empty($v2['device'])) {
        $v2['device'] = 'pc';
    }
    $v2['timestamp'] = (string)time();
    $v2['sign_type'] = 'MD5';
    $v2['sign'] = pay_sign_md5($v2, $channel['md5_key']);

    $resp = null;
    $lastErr = '';
    try {
        $resp = pay_http_post_raw($base . '/api/pay/create', $v2);
        if ((int)($resp['code'] ?? -1) === 0) {
            $payUrl = (string)($resp['pay_info'] ?? $resp['payurl'] ?? $resp['qrcode'] ?? '');
            if ($payUrl !== '') {
                return [
                    'trade_no' => (string)($resp['trade_no'] ?? ''),
                    'pay_type' => (string)($resp['pay_type'] ?? $type),
                    'pay_url' => $payUrl,
                    'channel_id' => $channel['id'],
                    'raw' => $resp,
                ];
            }
            $lastErr = 'empty pay_info';
        } else {
            $lastErr = (string)($resp['msg'] ?? ('code ' . ($resp['code'] ?? '?')));
        }
    } catch (Throwable $e) {
        $lastErr = $e->getMessage();
        $resp = null;
    }

    // Legacy mapi.php (old 易支付)
    $v1 = $params;
    $v1['sign'] = pay_sign_md5($v1, $channel['md5_key']);
    $v1['sign_type'] = 'MD5';
    try {
        $resp1 = pay_http_post_raw($base . '/mapi.php', $v1);
        if ((int)($resp1['code'] ?? 0) === 1) {
            $payUrl = (string)($resp1['payurl'] ?? $resp1['qrcode'] ?? $resp1['urlscheme'] ?? $resp1['pay_info'] ?? '');
            if ($payUrl !== '') {
                return [
                    'trade_no' => (string)($resp1['trade_no'] ?? ''),
                    'pay_type' => (string)($resp1['pay_type'] ?? $type),
                    'pay_url' => $payUrl,
                    'channel_id' => $channel['id'],
                    'raw' => $resp1,
                ];
            }
            $lastErr = 'mapi empty pay url';
        } else {
            $lastErr = (string)($resp1['msg'] ?? ('mapi code ' . ($resp1['code'] ?? '?')));
        }
        $resp = $resp1;
    } catch (Throwable $e) {
        $lastErr = $lastErr !== '' ? ($lastErr . '; mapi: ' . $e->getMessage()) : $e->getMessage();
    }

    throw new RuntimeException($lastErr !== '' ? $lastErr : 'create failed');
}

function pay_query_gateway_order($outTradeNo, $tradeNo = '', $channel = null) {
    // AUDIT-7 P1-2: never silently fall back to another enabled channel.
    if ($channel === null && $outTradeNo !== '') {
        $order = db_one('SELECT * FROM orders WHERE id = ?', [$outTradeNo]);
        if ($order) {
            $channel = pay_channel_for_order($order);
        }
    }
    if (!$channel || !pay_channel_is_configured($channel)) {
        return ['ok' => false, 'raw' => null, 'paid' => false, 'error' => 'no_channel'];
    }

    $driver = (string)($channel['driver'] ?? 'epay_v1_md5');
    $base = rtrim((string)$channel['api_base'], '/');

    if ($driver === 'epay_v2_rsa') {
        $params = [
            'pid' => (string)$channel['pid'],
            'timestamp' => (string)time(),
        ];
        if ($tradeNo !== '') $params['trade_no'] = $tradeNo;
        if ($outTradeNo !== '') $params['out_trade_no'] = $outTradeNo;
        $params['sign_type'] = 'RSA';
        $params['sign'] = pay_sign_rsa($params, $channel['merchant_private_key']);
        $resp = pay_http_post_raw($base . '/api/pay/query', $params);
        if ((int)($resp['code'] ?? -1) !== 0) {
            return ['ok' => false, 'raw' => $resp, 'paid' => false];
        }
        if (!empty($resp['sign']) && !pay_verify_with_channel($resp, $channel)) {
            return ['ok' => false, 'raw' => $resp, 'paid' => false, 'error' => 'bad_sign'];
        }
    } else {
        // MD5: try modern /api/pay/query first (do not require RSA response verify without platform key)
        $resp = null;
        try {
            $q = [
                'pid' => (string)$channel['pid'],
                'timestamp' => (string)time(),
                'sign_type' => 'MD5',
            ];
            if ($tradeNo !== '') $q['trade_no'] = $tradeNo;
            if ($outTradeNo !== '') $q['out_trade_no'] = $outTradeNo;
            $q['sign'] = pay_sign_md5($q, $channel['md5_key']);
            $try = pay_http_post_raw($base . '/api/pay/query', $q);
            if (is_array($try) && (int)($try['code'] ?? -1) === 0) {
                $resp = $try;
            }
        } catch (Throwable $ignored) {
            $resp = null;
        }
        if ($resp === null) {
            // Legacy query: prefer POST body so md5 key is not in the URL/access logs.
            $legacy = [
                'act' => 'order',
                'pid' => (string)$channel['pid'],
                'key' => (string)$channel['md5_key'],
                'out_trade_no' => (string)$outTradeNo,
            ];
            if ($tradeNo !== '') $legacy['trade_no'] = $tradeNo;
            try {
                $tryLegacy = pay_http_post_raw($base . '/api.php', $legacy);
                if (is_array($tryLegacy) && (int)($tryLegacy['code'] ?? 0) === 1) {
                    $resp = $tryLegacy;
                }
            } catch (Throwable $ignored) {
                $resp = null;
            }
            if ($resp === null) {
                // GET fallback only if gateway rejects POST
                $url = $base . '/api.php?act=order&pid=' . rawurlencode((string)$channel['pid'])
                    . '&key=' . rawurlencode((string)$channel['md5_key'])
                    . '&out_trade_no=' . rawurlencode((string)$outTradeNo);
                if ($tradeNo !== '') $url .= '&trade_no=' . rawurlencode($tradeNo);
                $chCurl = curl_init($url);
                curl_setopt_array($chCurl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
                $body = curl_exec($chCurl);
                $resp = json_decode((string)$body, true);
                if (!is_array($resp) || (int)($resp['code'] ?? 0) !== 1) {
                    return ['ok' => false, 'raw' => $resp, 'paid' => false];
                }
            }
        }
    }

    $status = $resp['status'] ?? null;
    $tradeStatus = (string)($resp['trade_status'] ?? '');
    $paid = ((string)$status === '1' || (int)$status === 1 || $tradeStatus === 'TRADE_SUCCESS');
    return [
        'ok' => true,
        'paid' => $paid,
        'trade_no' => (string)($resp['trade_no'] ?? ''),
        'money' => (string)($resp['money'] ?? ''),
        'raw' => $resp,
        'channel_id' => $channel['id'] ?? '',
    ];
}

function pay_notify_params() {
    $params = array_merge($_GET, $_POST);
    unset($params['PHPSESSID']);
    return $params;
}

function pay_notify_log($level, $reason, array $ctx = []) {
    $dir = rtrim((string)xlog_config('data_dir', XLOG_ROOT . '/data'), '/');
    if ($dir === '' || !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $orderId = (string)($ctx['order_id'] ?? $ctx['out_trade_no'] ?? '');
    $payload = [
        'ts' => function_exists('now_iso') ? now_iso() : gmdate('c'),
        'level' => (string)$level,
        'reason' => (string)$reason,
        'order_id' => $orderId !== '' ? $orderId : null,
        'trade_no' => isset($ctx['trade_no']) ? (string)$ctx['trade_no'] : null,
        'ip' => function_exists('client_ip') ? client_ip() : '',
        'detail' => $ctx,
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = '{"ts":"' . gmdate('c') . '","level":"error","reason":"log_encode_failed"}';
    }
    @file_put_contents($dir . '/pay-notify.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

function pay_notify_is_paid(array $params) {
    $tradeStatus = (string)($params['trade_status'] ?? '');
    if ($tradeStatus === 'TRADE_SUCCESS') return true;
    $status = $params['status'] ?? null;
    return ((string)$status === '1' || (int)$status === 1);
}

function pay_create_local_order($userId, array $package, $channel = 'alipay', $channelId = '') {
    $id = pay_new_order_id();
    $now = now_iso();
    $ip = client_ip();
    db_exec(
        'INSERT INTO orders (id, user_id, amount_cents, credits, status, pay_channel, channel_id, package_id, client_ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $id,
            (int)$userId,
            (int)$package['amount_cents'],
            (int)$package['credits'],
            'pending',
            $channel,
            $channelId,
            $package['id'],
            $ip,
            $now,
        ]
    );
    return db_one('SELECT * FROM orders WHERE id = ?', [$id]);
}

function pay_mark_gateway_info($orderId, $tradeNo, $payUrl, $channelId = null) {
    if ($channelId !== null && $channelId !== '') {
        db_exec(
            'UPDATE orders SET trade_no = ?, pay_url = ?, channel_id = ? WHERE id = ? AND status = ?',
            [(string)$tradeNo, (string)$payUrl, (string)$channelId, $orderId, 'pending']
        );
        return;
    }
    db_exec(
        'UPDATE orders SET trade_no = ?, pay_url = ? WHERE id = ? AND status = ?',
        [(string)$tradeNo, (string)$payUrl, $orderId, 'pending']
    );
}

function pay_fulfill_order($orderId, array $opts = []) {
    $pdo = db();
    $orderId = (string)$orderId;
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'already' => false, 'order' => null, 'error' => 'order_not_found'];
            }
            if (($order['status'] ?? '') === 'paid') {
                $pdo->exec('COMMIT');
                return ['ok' => true, 'already' => true, 'order' => $order, 'credits' => (int)$order['credits']];
            }
            if (($order['status'] ?? '') !== 'pending') {
                $pdo->exec('ROLLBACK');
                return ['ok' => false, 'already' => false, 'order' => $order, 'error' => 'bad_status'];
            }

            // AUDIT-7 P1-3: money is always required and must match fen amount.
            $money = array_key_exists('money', $opts) ? $opts['money'] : null;
            if ($money === null || $money === '' || !pay_money_required_match($money, $order['amount_cents'])) {
                $pdo->exec('ROLLBACK');
                $err = ($money === null || $money === '') ? 'money_missing' : 'money_mismatch';
                return ['ok' => false, 'already' => false, 'order' => $order, 'error' => $err];
            }

            $tradeNo = (string)($opts['trade_no'] ?? $order['trade_no'] ?? '');
            $now = now_iso();
            $upd = $pdo->prepare('UPDATE orders SET status = ?, paid_at = ?, trade_no = COALESCE(NULLIF(?, ""), trade_no), notify_raw = ? WHERE id = ? AND status = ?');
            $upd->execute([
                'paid',
                $now,
                $tradeNo,
                isset($opts['notify_raw']) ? (string)$opts['notify_raw'] : (string)($order['notify_raw'] ?? ''),
                $orderId,
                'pending',
            ]);
            if ($upd->rowCount() === 0) {
                $pdo->exec('COMMIT');
                $fresh = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
                if ($fresh && ($fresh['status'] ?? '') === 'paid') {
                    return ['ok' => true, 'already' => true, 'order' => $fresh, 'credits' => (int)$fresh['credits']];
                }
                return ['ok' => false, 'already' => false, 'order' => $fresh, 'error' => 'race'];
            }

            $credits = (int)$order['credits'];
            $userId = (int)$order['user_id'];
            $pdo->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([$credits, $userId]);
            $pdo->prepare(
                'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([$userId, $credits, 'recharge', $orderId, $now]);
            $pdo->exec('COMMIT');
            $order['status'] = 'paid';
            $order['paid_at'] = $now;
            if ($tradeNo !== '') $order['trade_no'] = $tradeNo;
            return ['ok' => true, 'already' => false, 'order' => $order, 'credits' => $credits];
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {}
            $locked = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
            if ($locked && $attempt < 4) {
                usleep(30000 * $attempt);
                continue;
            }
            throw $e;
        }
    }
}

function pay_sync_order_from_gateway(array $order) {
    if (($order['status'] ?? '') === 'paid') {
        return ['ok' => true, 'already' => true, 'order' => $order, 'credits' => (int)$order['credits']];
    }
    $channel = pay_channel_for_order($order);
    if (!$channel) {
        return ['ok' => false, 'already' => false, 'order' => $order, 'paid' => false, 'error' => 'no_channel'];
    }
    $q = pay_query_gateway_order((string)$order['id'], (string)($order['trade_no'] ?? ''), $channel);
    if (empty($q['ok']) || empty($q['paid'])) {
        return ['ok' => true, 'already' => false, 'order' => $order, 'paid' => false, 'query' => $q];
    }
    $money = $q['money'] ?? '';
    if ($money === null || $money === '') {
        return ['ok' => false, 'already' => false, 'order' => $order, 'paid' => false, 'error' => 'money_missing', 'query' => $q];
    }
    return pay_fulfill_order($order['id'], [
        'trade_no' => $q['trade_no'] ?? '',
        'money' => $money,
        'notify_raw' => json_encode($q['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]) + ['paid' => true];
}

/** Validate pay_info/pay_url from gateway before handing to browser (E3). */
function pay_is_safe_pay_url($url) {
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (empty($parts['host'])) {
        return false;
    }
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || $host === '127.0.0.1') {
        return (bool)xlog_config('pay.allow_http_api', false);
    }
    return true;
}

function pay_public_order(array $order) {
    return [
        'id' => $order['id'],
        'status' => $order['status'],
        'credits' => (int)$order['credits'],
        'amount_cents' => (int)$order['amount_cents'],
        'amount_yuan' => pay_money_from_cents($order['amount_cents']),
        'pay_channel' => $order['pay_channel'],
        'channel_id' => $order['channel_id'] ?? '',
        'package_id' => $order['package_id'] ?? '',
        'pay_url' => $order['pay_url'] ?? '',
        'trade_no' => $order['trade_no'] ?? '',
        'created_at' => $order['created_at'],
        'paid_at' => $order['paid_at'],
    ];
}
