<?php
// Daily quota and turn limits.

require_once __DIR__ . '/db.php';

/** Kind key for logged-in free-daily fallback counters (G1). */
function quota_free_daily_kind() {
    return 'generate_free';
}

function user_fallback_daily_limit() {
    return max(0, (int)xlog_config('billing.user_fallback_daily_generate', 2));
}

function quota_limit_for($kind, $userId) {
    if ($kind === 'chat_turn') return 200;
    if ($kind === 'session_create') return $userId ? 200 : 50;
    if ($kind === 'upload_image') return $userId ? 400 : 80;
    if ($kind === 'image_generate') return $userId ? 30 : 5;
    if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
        // Credit-mode users are not capped by users.daily_quota for paid generations.
        // Free fallback uses a separate counter (see consume_quota).
        return PHP_INT_MAX;
    }
    if ($userId) {
        $row = db_one('SELECT daily_quota FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        return $row ? (int)$row['daily_quota'] : 0;
    }
    // Guest free daily generations (not credit balance).
    return max(0, (int)xlog_config('billing.guest_generate_quota', 5));
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

function quota_decrement($key, $kind) {
    db_exec(
        'UPDATE quota_counters SET count = CASE WHEN count > 0 THEN count - 1 ELSE 0 END WHERE key = ? AND date = ? AND kind = ?',
        [$key, utc_date(), $kind]
    );
}

/**
 * Generate-quota status with billing mode for UI + prompt injection (G4).
 *
 * mode:
 *  - guest_daily
 *  - user_credits   (enough wallet credits for at least one generate)
 *  - user_fallback  (credits insufficient; free daily remaining)
 *  - user_daily     (credit_mode off; classic daily_quota)
 */
function quota_status($kind = 'generate') {
    $userId = current_user_id();
    if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
        $row = db_one('SELECT credits FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        $credits = $row ? (int)$row['credits'] : 0;
        $cost = max(1, (int)xlog_config('billing.generate_credit_cost', 1));
        $fallbackLimit = user_fallback_daily_limit();
        $fallbackKey = 'user:' . $userId;
        $fallbackUsed = quota_count($fallbackKey, quota_free_daily_kind());
        $fallbackRemaining = max(0, $fallbackLimit - $fallbackUsed);

        if ($credits >= $cost) {
            return [
                'identity' => 'user',
                'mode' => 'user_credits',
                'limit' => $credits,
                'used' => 0,
                'remaining' => max(0, intdiv($credits, $cost)),
                'credits' => $credits,
                'credit_cost' => $cost,
                'fallback_remaining' => $fallbackRemaining,
                'fallback_limit' => $fallbackLimit,
            ];
        }

        if ($fallbackLimit > 0 && $fallbackRemaining > 0) {
            return [
                'identity' => 'user',
                'mode' => 'user_fallback',
                'limit' => $fallbackLimit,
                'used' => $fallbackUsed,
                'remaining' => $fallbackRemaining,
                'credits' => $credits,
                'credit_cost' => $cost,
                'fallback_remaining' => $fallbackRemaining,
                'fallback_limit' => $fallbackLimit,
            ];
        }

        return [
            'identity' => 'user',
            'mode' => 'user_credits',
            'limit' => 0,
            'used' => 0,
            'remaining' => 0,
            'credits' => $credits,
            'credit_cost' => $cost,
            'fallback_remaining' => 0,
            'fallback_limit' => $fallbackLimit,
        ];
    }
    if ($userId) {
        $key = 'user:' . $userId;
        $limit = quota_limit_for($kind, $userId);
        $used = quota_count($key, $kind);
        return [
            'identity' => 'user',
            'mode' => 'user_daily',
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
        'mode' => 'guest_daily',
        'limit' => $limit,
        'used' => $used,
        'remaining' => max(0, $limit - $used),
    ];
}

/**
 * Prompt-facing status block keyed by quota mode (G4).
 */
function format_quota_status_for_prompt($locale, ?array $quota = null) {
    if ($quota === null) {
        $quota = quota_status('generate');
    }
    $mode = (string)($quota['mode'] ?? 'guest_daily');
    if ($mode === 'user_credits') {
        return t('prompt', 'statusCredits', $locale, [
            'credits' => (int)($quota['credits'] ?? 0),
            'cost' => (int)($quota['credit_cost'] ?? 1),
        ]);
    }
    if ($mode === 'user_fallback') {
        return t('prompt', 'statusFallback', $locale, [
            'remaining' => (int)($quota['remaining'] ?? 0),
            'limit' => (int)($quota['limit'] ?? 0),
            'credits' => (int)($quota['credits'] ?? 0),
        ]);
    }
    if ($mode === 'user_daily' || (($quota['identity'] ?? '') === 'user' && $mode !== 'guest_daily')) {
        return t('prompt', 'statusUserDaily', $locale, [
            'remaining' => (int)($quota['remaining'] ?? 0),
            'limit' => (int)($quota['limit'] ?? 0),
        ]);
    }
    return t('prompt', 'statusGuest', $locale, [
        'remaining' => (int)($quota['remaining'] ?? 0),
        'limit' => (int)($quota['limit'] ?? 0),
    ]);
}

function quota_begin_immediate(PDO $pdo) {
    $pdo->exec('BEGIN IMMEDIATE');
}

function quota_commit(PDO $pdo) {
    $pdo->exec('COMMIT');
}

function quota_rollback(PDO $pdo) {
    try {
        $pdo->exec('ROLLBACK');
    } catch (Throwable $ignored) {
    }
}

function consume_quota($kind = 'generate') {
    $pdo = db();
    quota_begin_immediate($pdo);
    try {
        $userId = current_user_id();
        if ($kind === 'generate' && xlog_config('billing.credit_mode', false) && $userId) {
            $cost = max(1, (int)xlog_config('billing.generate_credit_cost', 1));
            // Atomic deduct: only one concurrent consume can win when credits is tight.
            $stmt = $pdo->prepare(
                'UPDATE users SET credits = credits - ? WHERE id = ? AND status = ? AND credits >= ?'
            );
            $stmt->execute([$cost, $userId, 'active', $cost]);
            if ($stmt->rowCount() > 0) {
                $row = db_one('SELECT credits FROM users WHERE id = ?', [$userId]);
                $creditsLeft = $row ? (int)$row['credits'] : 0;
                db_exec(
                    'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$userId, -$cost, 'generate', null, now_iso()]
                );
                quota_commit($pdo);
                return [
                    'ok' => true,
                    'remaining' => intdiv($creditsLeft, $cost),
                    'identity' => 'user',
                    'mode' => 'user_credits',
                    'reason' => null,
                    'kind' => $kind,
                    'credit_mode' => true,
                    'user_id' => $userId,
                    'cost' => $cost,
                ];
            }

            // G1: credits insufficient — try daily free fallback for logged-in users.
            $fallbackLimit = user_fallback_daily_limit();
            if ($fallbackLimit > 0) {
                $key = 'user:' . $userId;
                $freeKind = quota_free_daily_kind();
                $used = quota_count($key, $freeKind);
                if ($used < $fallbackLimit) {
                    quota_increment($key, $freeKind);
                    $remaining = $fallbackLimit - $used - 1;
                    quota_commit($pdo);
                    return [
                        'ok' => true,
                        'remaining' => max(0, $remaining),
                        'identity' => 'user',
                        'mode' => 'user_fallback',
                        'reason' => 'free_daily',
                        'kind' => $kind,
                        'free_daily' => true,
                        'free_kind' => $freeKind,
                        'keys' => [$key],
                        'user_id' => $userId,
                    ];
                }
            }

            quota_commit($pdo);
            return [
                'ok' => false,
                'remaining' => 0,
                'identity' => 'user',
                'mode' => 'user_credits',
                'reason' => 'credits_exhausted',
                'kind' => $kind,
            ];
        }
        if ($userId) {
            $key = 'user:' . $userId;
            $limit = quota_limit_for($kind, $userId);
            $used = quota_count($key, $kind);
            if ($used >= $limit) {
                quota_commit($pdo);
                return ['ok' => false, 'remaining' => 0, 'identity' => 'user', 'mode' => 'user_daily', 'reason' => 'daily_quota_exceeded', 'kind' => $kind];
            }
            quota_increment($key, $kind);
            quota_commit($pdo);
            return [
                'ok' => true,
                'remaining' => $limit - $used - 1,
                'identity' => 'user',
                'mode' => 'user_daily',
                'reason' => null,
                'kind' => $kind,
                'keys' => [$key],
            ];
        }

        $limit = quota_limit_for($kind, null);
        $keys = ['ip:' . client_ip(), 'cookie:' . xlog_cookie_id()];
        foreach ($keys as $key) {
            if (quota_count($key, $kind) >= $limit) {
                quota_commit($pdo);
                return ['ok' => false, 'remaining' => 0, 'identity' => 'guest', 'mode' => 'guest_daily', 'reason' => 'daily_quota_exceeded', 'kind' => $kind];
            }
        }
        foreach ($keys as $key) quota_increment($key, $kind);
        $remaining = $limit - max(quota_count($keys[0], $kind), quota_count($keys[1], $kind));
        quota_commit($pdo);
        return [
            'ok' => true,
            'remaining' => max(0, $remaining),
            'identity' => 'guest',
            'mode' => 'guest_daily',
            'reason' => null,
            'kind' => $kind,
            'keys' => $keys,
        ];
    } catch (Throwable $e) {
        quota_rollback($pdo);
        throw $e;
    }
}

function refund_quota($kind, array $charge) {
    if (empty($charge['ok']) || ($charge['kind'] ?? $kind) !== $kind) return;
    $pdo = db();
    quota_begin_immediate($pdo);
    try {
        if (!empty($charge['credit_mode']) && !empty($charge['user_id'])) {
            $cost = max(1, (int)($charge['cost'] ?? xlog_config('billing.generate_credit_cost', 1)));
            db_exec('UPDATE users SET credits = credits + ? WHERE id = ?', [$cost, (int)$charge['user_id']]);
            db_exec(
                'INSERT INTO credit_transactions (user_id, delta, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
                [(int)$charge['user_id'], $cost, $kind . '_refund', null, now_iso()]
            );
        } elseif (!empty($charge['free_daily'])) {
            $freeKind = (string)($charge['free_kind'] ?? quota_free_daily_kind());
            foreach (($charge['keys'] ?? []) as $key) {
                quota_decrement($key, $freeKind);
            }
        } else {
            foreach (($charge['keys'] ?? []) as $key) {
                quota_decrement($key, $kind);
            }
        }
        quota_commit($pdo);
    } catch (Throwable $e) {
        quota_rollback($pdo);
        throw $e;
    }
}

function can_consume_quota($kind = 'generate') {
    $status = quota_status($kind);
    $ok = ($status['remaining'] ?? 0) > 0;
    $mode = (string)($status['mode'] ?? '');
    $reason = null;
    if (!$ok) {
        if (in_array($mode, ['user_credits', 'user_fallback'], true) || ($status['identity'] ?? '') === 'user' && xlog_config('billing.credit_mode', false)) {
            $reason = 'credits_exhausted';
        } else {
            $reason = 'daily_quota_exceeded';
        }
    }
    return [
        'ok' => $ok,
        'remaining' => $status['remaining'],
        'identity' => $status['identity'],
        'mode' => $mode,
        'reason' => $reason,
    ];
}
