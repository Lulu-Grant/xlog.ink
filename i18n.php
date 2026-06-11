<?php
// includes/i18n.php — 服务端三语文案（统一管理）

function get_i18n() {
    return [
        // 限流页
        'rateLimit' => [
            'zh-CN' => [
                'title' => '请求过于频繁',
                'msg'   => '为了保障服务稳定，请稍后再试。规则：1分钟内1次、24小时内50次。',
                'cta'   => '返回创建页',
            ],
            'zh-TW' => [
                'title' => '請求過於頻繁',
                'msg'   => '為了保障服務穩定，請稍後再試。規則：1 分鐘內 1 次、24 小時內 50 次。',
                'cta'   => '返回建立頁',
            ],
            'en' => [
                'title' => 'Too Many Requests',
                'msg'   => 'To keep the service stable, please try again later. Limits: 1 per minute, 50 per 24 hours.',
                'cta'   => 'Back to Creator',
            ],
        ],

        // 成功页
        'success' => [
            'zh-CN' => [
                'ok'        => '✅ 生成成功',
                'slug'      => '专属二级域名',
                'copy'      => '复制链接',
                'open'      => '打开',
                'tipsTitle' => '重要提示',
                'tips'      => [
                    '页面为静态内容，访问体验毫秒级加载。',
                    '生成后不可修改、不可删除，请妥善保管链接。',
                    '可随时在社交平台、简历、个人简介中使用该链接。',
                ],
                'back' => '返回继续创建',
            ],
            'zh-TW' => [
                'ok'        => '✅ 生成成功',
                'slug'      => '專屬二級網域',
                'copy'      => '複製連結',
                'open'      => '打開',
                'tipsTitle' => '重要提示',
                'tips'      => [
                    '頁面為靜態內容，體驗近乎毫秒級載入。',
                    '生成後不可修改、不可刪除，請妥善保存連結。',
                    '可在社群、履歷、個人簡介等場景使用此連結。',
                ],
                'back' => '返回繼續建立',
            ],
            'en' => [
                'ok'        => '✅ Created',
                'slug'      => 'Your Subdomain',
                'copy'      => 'Copy Link',
                'open'      => 'Open',
                'tipsTitle' => 'Important',
                'tips'      => [
                    'Your page is static — virtually instant load.',
                    'Once generated, it cannot be edited or deleted. Keep the link safe.',
                    'Use it in bios, resumes, and social profiles anytime.',
                ],
                'back' => 'Back to Create',
            ],
        ],

        // 生成页面内文案
        'page' => [
            'zh-CN' => ['generatedAt' => '创建时间', 'links' => '链接'],
            'zh-TW' => ['generatedAt' => '創建時間', 'links' => '連結'],
            'en'    => ['generatedAt' => 'Created at', 'links' => 'Links'],
        ],

        'adultGate' => [
            'zh-CN' => [
                'badge'   => '18+ 内容提示',
                'title'   => '此页面包含仅限成年人查看的内容',
                'msg'     => '继续访问即表示你已年满 18 岁，并愿意自行承担浏览责任。',
                'confirm' => '我已满 18 岁，继续访问',
                'leave'   => '离开此页面',
            ],
            'zh-TW' => [
                'badge'   => '18+ 內容提示',
                'title'   => '此頁面包含僅限成年人查看的內容',
                'msg'     => '繼續訪問即表示你已年滿 18 歲，並願意自行承擔瀏覽責任。',
                'confirm' => '我已滿 18 歲，繼續訪問',
                'leave'   => '離開此頁面',
            ],
            'en' => [
                'badge'   => '18+ Content Notice',
                'title'   => 'This page contains adult content intended for viewers 18+ only',
                'msg'     => 'By continuing, you confirm that you are at least 18 years old and choose to view this content at your own discretion.',
                'confirm' => 'I am 18+, continue',
                'leave'   => 'Leave this page',
            ],
        ],

        'turnstile' => [
            'zh-CN' => [
                'title' => '请完成人机验证',
                'msg'   => '为了防止滥用，提交前需要完成人机验证，请返回创建页后重试。',
                'cta'   => '返回创建页',
            ],
            'zh-TW' => [
                'title' => '請完成人機驗證',
                'msg'   => '為了防止濫用，提交前需要完成人機驗證，請返回建立頁後重試。',
                'cta'   => '返回建立頁',
            ],
            'en' => [
                'title' => 'Please complete the verification',
                'msg'   => 'To prevent abuse, please complete the human verification before submitting and try again.',
                'cta'   => 'Back to Creator',
            ],
        ],
    ];
}
