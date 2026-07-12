<?php
require_once __DIR__ . '/../includes/sitemap.php';

$count = build_sitemap_file();
echo "Built sitemap.xml with {$count} URLs\n";
