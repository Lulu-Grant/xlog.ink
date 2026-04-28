<?php

require_once __DIR__ . '/config.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = __DIR__ . $requestPath;

if ($requestPath !== '/' && is_file($publicFile)) {
    return false;
}

if ($requestPath === '/') {
    require __DIR__ . '/index.php';
    return true;
}

$toolRoutes = [];
foreach (tool_registry() as $tool) {
    $toolRoutes['/' . $tool['slug']] = __DIR__ . '/tools/' . $tool['slug'] . '.php';
    foreach ($tool['legacy_slugs'] as $legacySlug) {
        $toolRoutes['/' . $legacySlug] = __DIR__ . '/tools/' . $legacySlug . '.php';
    }
}

$staticRoutes = [
    '/download.php' => __DIR__ . '/download.php',
    '/sitemap.php' => __DIR__ . '/sitemap.php',
    '/robots.txt' => __DIR__ . '/robots.txt',
];

if (isset($staticRoutes[$requestPath])) {
    require $staticRoutes[$requestPath];
    return true;
}

if (isset($toolRoutes[$requestPath]) && is_file($toolRoutes[$requestPath])) {
    require $toolRoutes[$requestPath];
    return true;
}

http_response_code(404);
require __DIR__ . '/index.php';
