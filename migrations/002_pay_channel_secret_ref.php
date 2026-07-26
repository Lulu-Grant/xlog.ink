<?php
/**
 * Optional secret_ref on pay_channels (AUDIT-8 P2-2).
 * Real secrets may live in /etc/xlog/secrets.php or config pay.secrets map.
 */
return [
    'version' => '002_pay_channel_secret_ref',
    'up' => static function (PDO $pdo) {
        $cols = $pdo->query('PRAGMA table_info(pay_channels)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static function ($r) {
            return $r['name'] ?? '';
        }, $cols);
        if (!in_array('secret_ref', $names, true)) {
            $pdo->exec("ALTER TABLE pay_channels ADD COLUMN secret_ref TEXT DEFAULT ''");
        }
    },
];
