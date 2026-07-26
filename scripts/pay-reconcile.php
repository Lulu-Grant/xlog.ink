<?php
/**
 * Reconcile pending payment orders against the gateway.
 *
 * Usage:
 *   php scripts/pay-reconcile.php
 *   php scripts/pay-reconcile.php --dry-run --limit=50 --max-age-hours=48
 *
 * Exit 0 on success (including zero pending). Exit 1 on fatal error.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/pay.php';

$dryRun = in_array('--dry-run', $argv, true);
$limit = 50;
$maxAgeHours = 48;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = max(1, min(500, (int)$m[1]));
    if (preg_match('/^--max-age-hours=(\d+)$/', $arg, $m)) $maxAgeHours = max(1, min(720, (int)$m[1]));
}

$cutoff = gmdate('c', time() - $maxAgeHours * 3600);
$orders = db_all(
    "SELECT * FROM orders WHERE status = 'pending' AND created_at >= ? ORDER BY created_at ASC LIMIT ?",
    [$cutoff, $limit]
);

$summary = [
    'ts' => now_iso(),
    'dry_run' => $dryRun,
    'max_age_hours' => $maxAgeHours,
    'limit' => $limit,
    'pending_scanned' => count($orders),
    'paid' => 0,
    'still_pending' => 0,
    'errors' => 0,
];

echo json_encode(['event' => 'reconcile_start', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

foreach ($orders as $order) {
    $line = [
        'event' => 'reconcile_order',
        'id' => $order['id'],
        'channel_id' => $order['channel_id'] ?? '',
        'pay_channel' => $order['pay_channel'] ?? '',
        'amount_cents' => (int)$order['amount_cents'],
        'user_id' => (int)$order['user_id'],
        'created_at' => $order['created_at'],
    ];
    if ($dryRun) {
        $line['action'] = 'dry_run_skip';
        $line['result'] = 'pending';
        $summary['still_pending']++;
        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        continue;
    }
    try {
        $res = pay_sync_order_from_gateway($order);
        $fresh = db_one('SELECT status FROM orders WHERE id = ?', [$order['id']]);
        $status = (string)($fresh['status'] ?? 'unknown');
        $line['result'] = $status;
        $line['sync_ok'] = !empty($res['ok']);
        $line['already'] = !empty($res['already']);
        if ($status === 'paid') {
            $summary['paid']++;
        } else {
            $summary['still_pending']++;
        }
    } catch (Throwable $e) {
        $summary['errors']++;
        $line['result'] = 'error';
        $line['error'] = $e->getMessage();
    }
    echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$summary['ts_end'] = now_iso();
echo json_encode(['event' => 'reconcile_done', 'summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($summary['errors'] > 0 ? 1 : 0);
