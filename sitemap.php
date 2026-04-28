<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$urls = [
    [
        'loc' => build_absolute_url('/'),
        'priority' => '1.0',
    ],
];

foreach (tool_registry() as $tool) {
    $urls[] = [
        'loc' => build_absolute_url(tool_url($tool)),
        'priority' => '0.8',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $url): ?>
  <url>
    <loc><?= e($url['loc']) ?></loc>
    <priority><?= e($url['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
