<?php
/**
 * Baseline migration marker (AUDIT-8 P2-3).
 * Schema is created by db_init(); this records that the running DB matches V2 baseline.
 */
return [
    'version' => '001_baseline',
    'up' => static function (PDO $pdo) {
        // No-op: CREATE TABLE IF NOT EXISTS in db_init already applied.
        // Future migrations add columns/indexes/constraints here.
    },
];
