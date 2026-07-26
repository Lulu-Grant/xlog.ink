<?php
// Copy this file to /etc/xlog/config.php on the server.
// Never commit real API keys or SMTP passwords into the project.

return [
    'base_url' => 'https://xlog.ink',
    'turnstile' => [
        'enabled' => true,
        'site_key' => '<TURNSTILE_SITE_KEY>',
        'secret_key' => '<TURNSTILE_SECRET_KEY>',
    ],
    'ai' => [
        'base_url' => 'https://api.3s3.org',
        'chat' => [
            'base_url' => 'https://api.3s3.org',
            'model' => 'gpt-5.4-mini',
            'format' => 'openai',
            'key' => '<CHAT_API_KEY>',
            'max_tokens' => 1024,
            'fallbacks' => [
                [
                    'base_url' => 'https://api.3s3.org',
                    'model' => 'grok-4.5',
                    'format' => 'openai',
                    'key' => '<CHAT_FALLBACK_API_KEY>',
                    'max_tokens' => 1024,
                ],
            ],
        ],
        'gen' => [
            'model' => 'grok-4.5',
            'format' => 'openai',
            'key' => '<GEN_API_KEY>',
            'max_tokens' => 16384,
            'stream' => true,
            'timeout' => 180,
            'low_speed_time' => 35,
            'fallbacks' => [
                [
                    'base_url' => 'https://api.3s3.org',
                    'model' => 'gpt-5.6',
                    'format' => 'openai',
                    'key' => '<GEN_FALLBACK_API_KEY>',
                    'max_tokens' => 16384,
                    'stream' => true,
                    'timeout' => 240,
                    'low_speed_time' => 45,
                ],
            ],
        ],
        'audit' => [
            // Batch page-risk audit. Keep the real key outside the repository.
            'base_url' => 'https://api.3s3.org',
            'model' => 'grok-4.5',
            'format' => 'openai',
            'key' => '<PAGE_AUDIT_API_KEY>',
            'max_tokens' => 900,
            'timeout' => 120,
        ],
        'image' => [
            'base_url' => 'https://api.tu-zi.com',
            'model' => 'gpt-image-2',
            'format' => 'openai_image',
            'key' => '<IMAGE_API_KEY>',
            'size' => '1024x1024',
            'quality' => 'low',
            'output_format' => 'webp',
            // Empty allows any public HTTPS host. Prefer provider-owned hosts when known.
            'download_hosts' => [],
            'download_max_bytes' => 20 * 1024 * 1024,
            'max_tokens' => 0,
            'fallbacks' => [
                [
                    'base_url' => 'https://api.3s3.org',
                    'model' => 'gpt-image-2',
                    'format' => 'openai_image',
                    'key' => '<IMAGE_FALLBACK_API_KEY>',
                    'size' => '1024x1024',
                    'quality' => 'low',
                    'output_format' => 'webp',
                    'max_tokens' => 0,
                ],
            ],
        ],
        'moderation' => [
            'base_url' => 'https://api.openai.com',
            'model' => 'omni-moderation-latest',
            'format' => 'openai_moderation',
            'key' => '<OPENAI_MODERATION_API_KEY>',
            'max_tokens' => 512,
            // Tests may set this true only in an isolated environment. Production must keep false.
            'mock' => false,
        ],
    ],
    'smtp' => [
        'host' => 'smtpdm-ap-southeast-1.aliyun.com',
        'port' => 465,
        'secure' => 'ssl',
        'user' => '<SMTP_USER>',
        'pass' => '<SMTP_PASSWORD>',
        'from' => '<SMTP_FROM>',
        'from_name' => 'xlog.ink',
    ],
    'billing' => [
        // When true, logged-in users spend credits for page generation (guests still use daily free quota).
        'credit_mode' => true,
        'generate_credit_cost' => 1,
        'guest_generate_quota' => 5,
        'signup_credits' => 10,
        // Legacy daily_quota for users when credit_mode is false. With credit_mode=true,
        // generate ignores users.daily_quota; free-daily uses user_fallback_daily_generate.
        'signup_daily_quota' => 10,
        // When credits < generate cost, allow this many free generations per day (G1). 0 disables.
        'user_fallback_daily_generate' => 2,
        // amount_cents is RMB fen. Platform minimum is usually ¥1.00 (100).
        'packages' => [
            ['id' => 'c10', 'credits' => 10, 'amount_cents' => 1000],
            ['id' => 'c30', 'credits' => 30, 'amount_cents' => 2800],
            ['id' => 'c100', 'credits' => 100, 'amount_cents' => 8800],
            ['id' => 'c500', 'credits' => 500, 'amount_cents' => 39800],
        ],
    ],
    'pay' => [
        // XAi_pay / 彩虹易支付 V2 (RSA SHA256WithRSA). Keys only on the server.
        'enabled' => true,
        'api_base' => 'https://api.xuanfanpay.top',
        'pid' => '<MERCHANT_PID>',
        'merchant_private_key' => '<MERCHANT_RSA_PRIVATE_KEY_BASE64_OR_PEM>',
        'platform_public_key' => '<PLATFORM_RSA_PUBLIC_KEY_BASE64_OR_PEM>',
        'md5_key' => '<OPTIONAL_V1_MD5_KEY>',
        'default_type' => 'alipay',
        'method' => 'jump', // jump | web
        'notify_url' => 'https://xlog.ink/api/pay/notify.php',
        'return_url' => 'https://xlog.ink/api/pay/return.php',
    ],
    'admin' => [
        // Required in production. Do not rely on localhost fallback outside local development.
        'token' => '<ADMIN_DASHBOARD_TOKEN>',
        'max_attempts' => 8,
        'lock_seconds' => 900,
        // When true, admin users tab can grant credits (CSRF + credit_transactions admin_grant).
        'allow_credit_grant' => false,
    ],
    'analytics' => [
        'salt' => '<RANDOM_ANALYTICS_HASH_SALT>',
        'visit_ip_minute_limit' => 120,
        'visit_retention_days' => 90,
    ],
];
