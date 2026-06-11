<?php
require_once __DIR__ . '/../includes/recent.php';

$count = build_recent_html_file();
echo "Wrote recent.html from SQLite: {$count} items\n";
