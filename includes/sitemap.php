<?php
require_once __DIR__ . '/db.php';

function sitemap_xml_escape($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function build_sitemap_file() {
    $rows = db_all(
        'SELECT slug, updated_at, created_at FROM pages WHERE status = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 49990',
        ['live']
    );
    $urls = [
        ['loc' => 'https://xlog.ink/', 'lastmod' => gmdate('Y-m-d')],
        ['loc' => 'https://xlog.ink/recent.html', 'lastmod' => gmdate('Y-m-d')],
    ];
    foreach ($rows as $row) {
        $date = (string)($row['updated_at'] ?: $row['created_at']);
        $timestamp = $date !== '' ? strtotime($date) : false;
        $urls[] = [
            'loc' => 'https://' . $row['slug'] . '.xlog.ink/',
            'lastmod' => $timestamp ? gmdate('Y-m-d', $timestamp) : '',
        ];
    }

    $items = '';
    foreach ($urls as $url) {
        $items .= '  <url><loc>' . sitemap_xml_escape($url['loc']) . '</loc>';
        if ($url['lastmod'] !== '') $items .= '<lastmod>' . sitemap_xml_escape($url['lastmod']) . '</lastmod>';
        $items .= "</url>\n";
    }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
        . $items
        . "</urlset>\n";
    if (@file_put_contents(XLOG_ROOT . '/sitemap.xml', $xml, LOCK_EX) === false) {
        throw new RuntimeException('Could not write sitemap.xml');
    }
    return count($urls);
}
