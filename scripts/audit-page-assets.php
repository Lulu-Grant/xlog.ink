<?php
require_once __DIR__ . '/../includes/db.php';

$jsonOutput = in_array('--json', array_slice($argv, 1), true);
$siteDir = rtrim((string)xlog_config('site_dir'), '/');
$assetDir = rtrim((string)xlog_config('asset_dir'), '/');
$issues = [];
$checkedPages = 0;
$checkedReferences = 0;

function asset_audit_reference_path($url) {
    $path = parse_url(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);
    if (!is_string($path) || strpos($path, '/site-assets/') !== 0) return null;
    $relative = substr($path, strlen('/site-assets/'));
    if ($relative === '' || strpos($relative, '..') !== false) return null;
    return $relative;
}

function asset_audit_html_urls($html) {
    preg_match_all('~(?:https://xlog\.ink)?(/site-assets/[A-Za-z0-9_./-]+)~i', (string)$html, $matches);
    return array_values(array_unique($matches[1] ?? []));
}

$dbPaths = [];
foreach (db_all('SELECT session_id, slug, path, source FROM images ORDER BY id') as $row) {
    $relative = asset_audit_reference_path($row['path'] ?? '');
    if ($relative === null) continue;
    $dbPaths['/site-assets/' . $relative] = $row;
    $file = $assetDir . '/' . $relative;
    if (!is_file($file)) {
        $issues[] = [
            'type' => 'database_asset_missing',
            'path' => '/site-assets/' . $relative,
            'page' => $row['slug'] ?? null,
            'session_id' => $row['session_id'] ?? null,
            'source' => $row['source'] ?? null,
        ];
    }
}

foreach (glob($siteDir . '/*.html') ?: [] as $pageFile) {
    if (!is_file($pageFile)) continue;
    $checkedPages++;
    $slug = pathinfo($pageFile, PATHINFO_FILENAME);
    $html = file_get_contents($pageFile);
    foreach (asset_audit_html_urls($html) as $urlPath) {
        $checkedReferences++;
        $relative = asset_audit_reference_path($urlPath);
        if ($relative === null) continue;
        if (!is_file($assetDir . '/' . $relative)) {
            $issues[] = [
                'type' => 'page_asset_missing',
                'path' => '/site-assets/' . $relative,
                'page' => $slug,
                'database_record' => isset($dbPaths['/site-assets/' . $relative]),
            ];
        }
    }
}

$report = [
    'ok' => count($issues) === 0,
    'checked_pages' => $checkedPages,
    'checked_references' => $checkedReferences,
    'issues_count' => count($issues),
    'issues' => $issues,
];

if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "xlog.ink page asset audit\n";
    echo "Pages: {$checkedPages}; references: {$checkedReferences}; issues: " . count($issues) . "\n";
    foreach ($issues as $issue) {
        $page = $issue['page'] ?? '-';
        echo '[MISSING] ' . $issue['type'] . ' page=' . $page . ' path=' . $issue['path'] . "\n";
    }
}

exit($report['ok'] ? 0 : 1);
