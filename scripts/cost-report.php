<?php
require_once __DIR__ . '/../includes/db.php';

$today = utc_date();
$total = db_one('SELECT COUNT(*) AS c FROM pages')['c'] ?? 0;
$todayCount = db_one('SELECT COUNT(*) AS c FROM pages WHERE substr(created_at, 1, 10) = ?', [$today])['c'] ?? 0;
$adult = db_one('SELECT COUNT(*) AS c FROM pages WHERE is_adult = 1')['c'] ?? 0;
$tokens = db_one('SELECT COALESCE(SUM(cost_tokens), 0) AS t FROM pages')['t'] ?? 0;
$editable = db_one('SELECT COUNT(*) AS c FROM pages WHERE editable = 1')['c'] ?? 0;
$live = db_one('SELECT COUNT(*) AS c FROM pages WHERE status = ?', ['live'])['c'] ?? 0;
$eventTotal = db_one('SELECT COUNT(*) AS c FROM publish_events')['c'] ?? 0;
$eventToday = db_one('SELECT COUNT(*) AS c FROM publish_events WHERE substr(created_at, 1, 10) = ?', [$today])['c'] ?? 0;
$success = db_one('SELECT COUNT(*) AS c FROM publish_events WHERE status = ?', ['success'])['c'] ?? 0;
$failed = db_one('SELECT COUNT(*) AS c FROM publish_events WHERE status = ?', ['failed'])['c'] ?? 0;
$refused = db_one('SELECT COUNT(*) AS c FROM publish_events WHERE status = ?', ['refused'])['c'] ?? 0;
$eventTokens = db_one('SELECT COALESCE(SUM(input_tokens + output_tokens), 0) AS t FROM publish_events')['t'] ?? 0;
$adultEvents = db_one('SELECT COUNT(*) AS c FROM publish_events WHERE is_adult = 1 AND status = ?', ['success'])['c'] ?? 0;

echo "xlog.ink V2 cost report\n";
echo "Date UTC: {$today}\n";
echo "Pages total: {$total}\n";
echo "Pages live: {$live}\n";
echo "Pages today: {$todayCount}\n";
echo "Editable pages: {$editable}\n";
echo "Adult pages: {$adult}\n";
echo "Recorded tokens: {$tokens}\n";
echo "Publish events total: {$eventTotal}\n";
echo "Publish events today: {$eventToday}\n";
echo "Publish success: {$success}\n";
echo "Publish failed: {$failed}\n";
echo "Publish refused: {$refused}\n";
echo "Publish adult success: {$adultEvents}\n";
echo "Publish event tokens: {$eventTokens}\n";
