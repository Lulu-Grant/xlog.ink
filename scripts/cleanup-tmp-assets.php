<?php
require_once __DIR__ . '/../includes/db.php';

$base = dirname(__DIR__) . '/site-assets/tmp';
$cutoff = time() - 48 * 3600;
if (is_dir($base)) {
    foreach (glob($base . '/*') ?: [] as $dir) {
        if (!is_dir($dir) || filemtime($dir) > $cutoff) continue;
        foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($dir);
        echo "Removed {$dir}\n";
    }
} else {
    echo "No tmp asset directory\n";
}

$previewBase = dirname(__DIR__) . '/data/previews';
if (is_dir($previewBase)) {
    foreach (glob($previewBase . '/*.html') ?: [] as $file) {
        if (!is_file($file) || filemtime($file) > $cutoff) continue;
        @unlink($file);
        echo "Removed {$file}\n";
    }
}

$expiredCodes = db_exec('DELETE FROM login_codes WHERE expires_at < ?', [now_iso()])->rowCount();
echo "Removed expired login codes: {$expiredCodes}\n";

$sessionCutoff = gmdate('c', time() - 30 * 86400);
$oldSessions = db_exec(
    'DELETE FROM sessions WHERE state != ? AND updated_at < ?',
    ['done', $sessionCutoff]
)->rowCount();
echo "Removed stale unfinished sessions: {$oldSessions}\n";

$oldMailEvents = db_exec('DELETE FROM mail_events WHERE created_at < ?', [$sessionCutoff])->rowCount();
echo "Removed old mail events: {$oldMailEvents}\n";

$visitRetentionDays = max(1, (int)xlog_config('analytics.visit_retention_days', 90));
$visitCutoff = gmdate('c', time() - $visitRetentionDays * 86400);
$oldVisits = db_exec('DELETE FROM page_visits WHERE created_at < ?', [$visitCutoff])->rowCount();
echo "Removed old page visits older than {$visitRetentionDays} days: {$oldVisits}\n";

$adminAttemptCutoff = gmdate('c', time() - 30 * 86400);
$oldAdminAttempts = db_exec('DELETE FROM admin_login_attempts WHERE created_at < ?', [$adminAttemptCutoff])->rowCount();
echo "Removed old admin login attempts: {$oldAdminAttempts}\n";

$orphanDoneSessions = db_exec(
    'DELETE FROM sessions
     WHERE state = ?
       AND updated_at < ?
       AND NOT EXISTS (
         SELECT 1 FROM pages
         WHERE pages.session_id = sessions.id
            OR pages.slug = sessions.page_slug
       )',
    ['done', $sessionCutoff]
)->rowCount();
echo "Removed orphan done sessions: {$orphanDoneSessions}\n";
