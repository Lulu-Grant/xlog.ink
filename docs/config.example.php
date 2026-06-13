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
            'model' => 'google/gemma-4-E4B-it',
            'format' => 'openai',
            'key' => '<CHAT_API_KEY>',
            'max_tokens' => 1024,
            'fallbacks' => [
                [
                    'base_url' => 'https://api.3s3.org',
                    'model' => 'gpt-5.4-mini',
                    'format' => 'openai',
                    'key' => '<CHAT_FALLBACK_API_KEY>',
                    'max_tokens' => 1024,
                ],
            ],
        ],
        'gen' => [
            'model' => 'Qwen/Qwen3.6-35B-A3B',
            'format' => 'openai',
            'key' => '<GEN_API_KEY>',
            'max_tokens' => 16384,
            'stream' => true,
            'timeout' => 180,
            'low_speed_time' => 35,
            'fallbacks' => [
                [
                    'base_url' => 'https://api.3s3.org',
                    'model' => 'gpt-5.4',
                    'format' => 'openai',
                    'key' => '<GEN_FALLBACK_API_KEY>',
                    'max_tokens' => 16384,
                    'stream' => true,
                    'timeout' => 240,
                    'low_speed_time' => 45,
                ],
            ],
        ],
        'image' => [
            'base_url' => 'https://api.tu-zi.com',
            'model' => 'gpt-image-2',
            'format' => 'openai_image',
            'key' => '<IMAGE_API_KEY>',
            'size' => '1024x1024',
            'quality' => 'low',
            'output_format' => 'webp',
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
        'credit_mode' => false,
        'generate_credit_cost' => 1,
    ],
    'admin' => [
        // Required in production. Do not rely on localhost fallback outside local development.
        'token' => '<ADMIN_DASHBOARD_TOKEN>',
        'max_attempts' => 8,
        'lock_seconds' => 900,
    ],
    'analytics' => [
        'salt' => '<RANDOM_ANALYTICS_HASH_SALT>',
        'visit_ip_minute_limit' => 120,
        'visit_retention_days' => 90,
    ],
];
