<?php
// includes/ratelimit.php — IP 限流

require_once __DIR__ . '/helpers.php';

function check_rate_limit($maxPerMin = 1, $maxPerDay = 50) {
    $ip  = client_ip();
    $now = time();

    $limitDir = dirname(__DIR__) . '/data/ratelimit';
    if (!is_dir($limitDir)) @mkdir($limitDir, 0755, true);

    $ipKey  = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $ip);
    $ipFile = $limitDir . '/' . $ipKey . '.json';

    $events = [];
    if (is_file($ipFile)) {
        $raw = file_get_contents($ipFile);
        $events = $raw ? json_decode($raw, true) : [];
        if (!is_array($events)) $events = [];
    }

    $dayAgo = $now - 86400;
    $minAgo = $now - 60;
    $events = array_values(array_filter($events, fn($ts) => is_int($ts) && $ts >= $dayAgo));

    $cnt24h = 0;
    $cnt1min = 0;
    foreach ($events as $ts) {
        if ($ts >= $dayAgo)  $cnt24h++;
        if ($ts >= $minAgo)  $cnt1min++;
    }

    $exceeded = ($cnt1min >= $maxPerMin || $cnt24h >= $maxPerDay);

    return [
        'exceeded' => $exceeded,
        'events'   => $events,
        'ipFile'   => $ipFile,
        'now'      => $now,
    ];
}

function record_rate_event($ipFile, $events, $now) {
    $events[] = $now;
    @file_put_contents($ipFile, json_encode($events), LOCK_EX);
}
