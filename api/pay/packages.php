<?php
require_once __DIR__ . '/../../includes/pay.php';

require_method('POST');
xlog_start_session();

api_json([
    'enabled' => pay_enabled(),
    'credit_mode' => (bool)xlog_config('billing.credit_mode', false),
    'credit_cost' => max(1, (int)xlog_config('billing.generate_credit_cost', 1)),
    'channels' => pay_enabled() ? pay_public_channels() : [],
    'packages' => pay_enabled() ? pay_packages() : [],
    'quota' => quota_status('generate'),
]);
