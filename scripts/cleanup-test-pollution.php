<?php
/**
 * Dry-run (default) listing of likely test pollution rows in the configured DB.
 * AUDIT-7 P1-4 — does NOT delete unless --execute is passed (still requires --i-know).
 *
 * Usage:
 *   php scripts/cleanup-test-pollution.php
 *   php scripts/cleanup-test-pollution.php --execute --i-know
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';

$execute = in_array('--execute', $argv, true);
$iKnow = in_array('--i-know', $argv, true);

// Patterns from automated tests
$emailLike = [
    '%@example.com',
    'admin-tab-%',
    'guest-flow-%',
    'smoke-pay-%',
    'guest-user-%',
    'admin-tab-%@%',
    'smoke-%@%',
    'xlog-test-%@%',
    'launch-%@%',
    'atk-%@%',
    'leg-%@%',
    'fb-cons-%@%',
];

$users = [];
foreach ($emailLike as $pat) {
    $rows = db_all('SELECT id, email, credits, created_at FROM users WHERE lower(email) LIKE lower(?)', [$pat]);
    foreach ($rows ?: [] as $r) {
        $users[(int)$r['id']] = $r;
    }
}

$userIds = array_keys($users);
$orders = [];
$txs = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $orders = db_all("SELECT id, user_id, status, amount_cents, credits, created_at FROM orders WHERE user_id IN ($placeholders)", $userIds) ?: [];
    $txs = db_all("SELECT id, user_id, delta, reason, ref, created_at FROM credit_transactions WHERE user_id IN ($placeholders)", $userIds) ?: [];
}

echo "=== xlog test pollution dry-run ===\n";
echo 'data_dir=' . xlog_config('data_dir') . "\n";
echo 'users=' . count($users) . ' orders=' . count($orders) . ' credit_tx=' . count($txs) . "\n";
foreach ($users as $u) {
    echo "  user #{$u['id']} {$u['email']} credits={$u['credits']}\n";
}
foreach ($orders as $o) {
    echo "  order {$o['id']} user={$o['user_id']} status={$o['status']}\n";
}

if (!$execute) {
    echo "\nDry-run only. To delete, re-run with: --execute --i-know\n";
    exit(0);
}
if (!$iKnow) {
    fwrite(STDERR, "Refusing --execute without --i-know\n");
    exit(2);
}

$pdo = db();
$pdo->exec('BEGIN');
try {
    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $pdo->prepare("DELETE FROM credit_transactions WHERE user_id IN ($ph)")->execute($userIds);
        $pdo->prepare("DELETE FROM orders WHERE user_id IN ($ph)")->execute($userIds);
        $pdo->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($userIds);
    }
    $pdo->exec('COMMIT');
    echo "Deleted users/orders/tx for " . count($userIds) . " users.\n";
} catch (Throwable $e) {
    $pdo->exec('ROLLBACK');
    throw $e;
}
