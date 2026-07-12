<?php
require_once __DIR__ . '/../includes/content_tools.php';

$apply = in_array('--apply', $argv, true);
$siteDir = rtrim((string)xlog_config('site_dir'), '/');
$matched = 0;
$updated = 0;

foreach (glob($siteDir . '/*.html') ?: [] as $path) {
    $html = file_get_contents($path);
    if (!is_string($html) || $html === '') continue;
    $description = extract_generated_meta_description($html);
    if (!preg_match('/[\[{]\s*["\']role["\']\s*:|&quot;role&quot;|\\"role\\"/i', $description)) continue;

    $matched++;
    $title = extract_title($html) ?: 'xlog page';
    $replacement = generated_page_visible_description($html, $title);
    echo basename($path) . ': ' . $replacement . PHP_EOL;
    if (!$apply) continue;

    $repaired = ensure_page_meta($html, [
        'title' => $title,
        'description' => $replacement,
        'og_title' => $title,
        'og_description' => $replacement,
    ]);
    if ($repaired !== $html && file_put_contents($path, $repaired, LOCK_EX) !== false) $updated++;
}

echo ($apply ? 'Updated' : 'Matched') . " metadata: " . ($apply ? $updated : $matched) . PHP_EOL;
