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
            'model' => 'google/gemma-4-26B-A4B-it',
            'format' => 'openai',
            'key' => '<CHAT_API_KEY>',
            'max_tokens' => 1024,
        ],
        'gen' => [
            'model' => 'claude-sonnet-4-6',
            'format' => 'anthropic',
            'key' => '<GEN_API_KEY>',
            'max_tokens' => 49152,
        ],
        'image' => [
            'base_url' => 'https://api.3s3.org',
            'model' => 'gpt-image-2',
            'format' => 'openai_image',
            'key' => '<IMAGE_API_KEY>',
            'size' => '1024x1024',
            'quality' => 'low',
            'output_format' => 'webp',
            'max_tokens' => 0,
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
];
