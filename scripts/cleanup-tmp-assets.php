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
