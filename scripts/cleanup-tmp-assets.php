<?php
$base = dirname(__DIR__) . '/site-assets/tmp';
$cutoff = time() - 48 * 3600;
if (!is_dir($base)) {
    echo "No tmp asset directory\n";
    exit;
}
foreach (glob($base . '/*') ?: [] as $dir) {
    if (!is_dir($dir) || filemtime($dir) > $cutoff) continue;
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
    echo "Removed {$dir}\n";
}
