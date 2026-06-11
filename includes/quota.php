<?php
// Daily quota and turn limits.

require_once __DIR__ . '/db.php';

function quota_limit_for($kind, $userId) {
    if ($kind === 'chat_turn') return 200;
    if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
        return PHP_INT_MAX;
    }
    if ($userId) {
        $row = db_one('SELECT daily_quota FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        return $row ? (int)$row['daily_quota'] : 0;
    }
    return 10;
}

function quota_count($key, $kind) {
    $row = db_one('SELECT count FROM quota_counters WHERE key = ? AND date = ? AND kind = ?', [$key, utc_date(), $kind]);
    return $row ? (int)$row['count'] : 0;
}

function quota_increment($key, $kind) {
    db_exec(
        'INSERT INTO quota_counters (key, date, kind, count) VALUES (?, ?, ?, 1)
         ON CONFLICT(key, date, kind) DO UPDATE SET count = count + 1',
        [$key, utc_date(), $kind]
    );
}

function quota_status($kind = 'generate') {
    $userId = current_user_id();
    if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
        $row = db_one('SELECT credits FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        $credits = $row ? (int)$row['credits'] : 0;
        $cost = max(1, (int)xlog_config('billing.generate_credit_cost', 1));
        return [
            'identity' => 'user',
            'limit' => $credits,
            'used' => 0,
            'remaining' => max(0, intdiv($credits, $cost)),
            'credits' => $credits,
            'credit_cost' => $cost,
        ];
    }
    if ($userId) {
        $key = 'user:' . $userId;
        $limit = quota_limit_for($kind, $userId);
        $used = quota_count($key, $kind);
        return [
            'identity' => 'user',
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    $limit = quota_limit_for($kind, null);
    $ipKey = 'ip:' . client_ip();
    $cookieKey = 'cookie:' . xlog_cookie_id();
    $used = max(quota_count($ipKey, $kind), quota_count($cookieKey, $kind));
    return [
        'identity' => 'guest',
        'limit' => $limit,
        'used' => $used,
        'remaining' => max(0, $limit - $used),
    ];
}

function consume_quota($kind = 'generate') {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId = current_user_id();
        if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
            $cost = max(1, (int)xlog_config('billing.generate_credit_cost', 1));
            $row = db_one('SELECT credits FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
            $credits = $row ? (int)$row['credits'] : 0;
            if ($credits < $cost) {
                $pdo->commit();
                return ['ok' => false, 'remaining' => 0, 'identity' => 'user', 'reason' => 'credits_exhausted'];
            }
            db_exec('UPDATE users SET credits = credits - ? WHERE id = ?', [$cost, $userId]);
            db_exec(
                'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, -$cost, 'generate', null, now_iso()]
            );
            $pdo->commit();
            return ['ok' => true, 'remaining' => intdiv($credits - $cost, $cost), 'identity' => 'user', 'reason' => null];
        }
        if ($userId) {
            $key = 'user:' . $userId;
            $limit = quota_limit_for($kind, $userId);
            $used = quota_count($key, $kind);
            if ($used >= $limit) {
                $pdo->commit();
                return ['ok' => false, 'remaining' => 0, 'identity' => 'user', 'reason' => 'daily_quota_exceeded'];
            }
            quota_increment($key, $kind);
            $pdo->commit();
            return ['ok' => true, 'remaining' => $limit - $used - 1, 'identity' => 'user', 'reason' => null];
        }

        $limit = quota_limit_for($kind, null);
        $keys = ['ip:' . client_ip(), 'cookie:' . xlog_cookie_id()];
        foreach ($keys as $key) {
            if (quota_count($key, $kind) >= $limit) {
                $pdo->commit();
                return ['ok' => false, 'remaining' => 0, 'identity' => 'guest', 'reason' => 'daily_quota_exceeded'];
            }
        }
        foreach ($keys as $key) quota_increment($key, $kind);
        $remaining = $limit - max(quota_count($keys[0], $kind), quota_count($keys[1], $kind));
        $pdo->commit();
        return ['ok' => true, 'remaining' => max(0, $remaining), 'identity' => 'guest', 'reason' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function can_consume_quota($kind = 'generate') {
    $status = quota_status($kind);
    return [
        'ok' => $status['remaining'] > 0,
        'remaining' => $status['remaining'],
        'identity' => $status['identity'],
        'reason' => $status['remaining'] > 0 ? null : 'daily_quota_exceeded',
    ];
}
