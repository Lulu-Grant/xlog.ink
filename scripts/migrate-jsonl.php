<?php
require_once __DIR__ . '/../includes/db.php';

$files = [
    XLOG_ROOT . '/data/pages.jsonl',
    XLOG_ROOT . '/pages.jsonl',
];

$count = 0;
$skipped = 0;
foreach ($files as $file) {
    if (!is_file($file)) continue;
    $fh = fopen($file, 'r');
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $entry = json_decode($line, true);
        if (!is_array($entry) || empty($entry['slug'])) {
            $skipped++;
            continue;
        }
        $slug = preg_replace('/[^a-z0-9]/i', '', $entry['slug']);
        if (!preg_match('/^[a-z0-9]{10}$/i', $slug)) {
            $skipped++;
            continue;
        }
        if (db_one('SELECT slug FROM pages WHERE slug = ?', [$slug])) {
            $skipped++;
            continue;
        }
        db_exec(
            'INSERT INTO pages (slug, title, type, lang, created_at, editable, token_hash, is_adult, status, html_path) VALUES (?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)',
            [
                strtolower($slug),
                $entry['title'] ?? $slug,
                $entry['type'] ?? 'link',
                $entry['lang'] ?? 'zh-CN',
                $entry['time'] ?? now_iso(),
                !empty($entry['adult']) ? 1 : 0,
                'live',
                XLOG_ROOT . '/site/' . strtolower($slug) . '.html',
            ]
        );
        $count++;
    }
    fclose($fh);
}

echo "Migrated: {$count}\nSkipped: {$skipped}\nDB: " . xlog_config('data_dir') . "/xlog.db\n";
