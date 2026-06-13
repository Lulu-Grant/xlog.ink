<?php
// Model adapter for OpenAI-compatible and Anthropic-compatible gateways.

require_once __DIR__ . '/quota.php';

function prompt_text($name) {
    $path = XLOG_ROOT . '/prompts/' . $name;
    return is_file($path) ? file_get_contents($path) : '';
}

function ai_config($purpose) {
    $cfg = xlog_config('ai.' . $purpose);
    $cfg['base_url'] = rtrim($cfg['base_url'] ?? xlog_config('ai.base_url'), '/');
    return $cfg;
}

function ai_configs($purpose) {
    $primary = ai_config($purpose);
    $configs = [$primary];
    $fallbacks = $primary['fallbacks'] ?? [];
    if (is_array($fallbacks)) {
        foreach ($fallbacks as $fallback) {
            if (!is_array($fallback)) continue;
            $cfg = $primary;
            unset($cfg['fallbacks']);
            $cfg = xlog_array_merge_deep($cfg, $fallback);
            $cfg['base_url'] = rtrim($cfg['base_url'] ?? xlog_config('ai.base_url'), '/');
            $configs[] = $cfg;
        }
    }
    return $configs;
}

function ai_config_has_key(array $cfg) {
    return !empty($cfg['model'])
        && strpos((string)$cfg['model'], '<') === false
        && !empty($cfg['key'])
        && strpos((string)$cfg['key'], '<') === false;
}

function ai_has_key($purpose) {
    foreach (ai_configs($purpose) as $cfg) {
        if (ai_config_has_key($cfg)) return true;
    }
    return false;
}

function ai_stream_chat(array $messages, callable $onDelta) {
    if (!ai_has_key('chat')) {
        $text = ai_mock_chat($messages);
        ai_stream_string($text, $onDelta);
        return ['input_tokens' => 0, 'output_tokens' => mb_strlen($text, 'UTF-8'), 'mock' => true];
    }
    return ai_stream_request('chat', $messages, $onDelta);
}

function ai_stream_generate(array $messages, callable $onDelta) {
    if (!ai_has_key('gen')) {
        $text = ai_mock_html($messages);
        ai_stream_string($text, $onDelta);
        return ['input_tokens' => 0, 'output_tokens' => mb_strlen($text, 'UTF-8'), 'mock' => true];
    }
    return ai_stream_request('gen', $messages, $onDelta);
}

function ai_generate_image($prompt, array $options = []) {
    if (!ai_has_key('image')) {
        return null;
    }
    $errors = [];
    foreach (ai_configs('image') as $cfg) {
        if (!ai_config_has_key($cfg)) continue;
        try {
            return ai_generate_image_with_config($cfg, $prompt, $options);
        } catch (Throwable $e) {
            $errors[] = ($cfg['model'] ?? 'unknown') . ': ' . $e->getMessage();
            error_log('AI image provider failed: ' . end($errors));
        }
    }
    throw new RuntimeException('All image providers failed: ' . implode(' | ', $errors));
}

function ai_generate_image_with_config(array $cfg, $prompt, array $options = []) {
    $format = $cfg['format'] ?? 'openai_image';
    if ($format !== 'openai_image') {
        throw new RuntimeException('Unsupported image generation format');
    }
    $outputFormat = strtolower((string)($options['output_format'] ?? ($cfg['output_format'] ?? 'webp')));
    if (!in_array($outputFormat, ['webp', 'png', 'jpeg'], true)) {
        $outputFormat = 'webp';
    }
    $payload = [
        'model' => $cfg['model'],
        'prompt' => mb_substr(trim((string)$prompt), 0, 2000, 'UTF-8'),
        'n' => 1,
        'size' => $options['size'] ?? ($cfg['size'] ?? '1024x1024'),
        'quality' => $options['quality'] ?? ($cfg['quality'] ?? 'low'),
        'output_format' => $outputFormat,
    ];
    if ($payload['prompt'] === '') {
        throw new RuntimeException('Image prompt required');
    }
    $data = ai_curl_json_timeout(rtrim($cfg['base_url'], '/') . '/v1/images/generations', [
        'Authorization: Bearer ' . $cfg['key'],
        'Content-Type: application/json',
    ], $payload, 180);
    $b64 = $data['data'][0]['b64_json'] ?? '';
    $url = $data['data'][0]['url'] ?? '';
    if ($b64 === '' && $url === '') {
        throw new RuntimeException('Image API returned no image data');
    }
    $mime = $outputFormat === 'png' ? 'image/png' : ($outputFormat === 'jpeg' ? 'image/jpeg' : 'image/webp');
    if ($b64 !== '') {
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Image API returned invalid image data');
        }
    } else {
        [$bytes, $downloadMime] = ai_download_image_url($url);
        if ($downloadMime !== '') $mime = $downloadMime;
    }
    return [
        'bytes' => $bytes,
        'mime' => $mime,
        'model' => $data['model'] ?? $cfg['model'],
    ];
}

function ai_download_image_url($url) {
    $url = trim((string)$url);
    if (!preg_match('/^https:\\/\\//i', $url)) {
        throw new RuntimeException('Image API returned unsupported image URL');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (PHP_VERSION_ID < 80500) curl_close($ch);
    if ($body === false || $status >= 400 || $body === '') {
        throw new RuntimeException('Could not download generated image: ' . ($err ?: 'HTTP ' . $status));
    }
    $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $info = @getimagesizefromstring($body);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    }
    return [$body, $mime];
}

function ai_moderate_image($imagePath, $mime, $context = '') {
    if (!ai_has_key('moderation')) {
        return null;
    }
    if (!is_file($imagePath)) {
        throw new RuntimeException('Moderation image not found');
    }
    $cfg = ai_config('moderation');
    $format = $cfg['format'] ?? 'openai';
    $imageBytes = file_get_contents($imagePath);
    if ($imageBytes === false) {
        throw new RuntimeException('Could not read moderation image');
    }
    $prompt = "Assess whether this image should be treated as adult/18+ content for a public web page.\n"
        . "Return only compact JSON with keys: adult_score (number 0-1), reason (short string).\n"
        . "Score 0.55 or above means adult gate should be enabled.\n"
        . "Context/caption: " . mb_substr((string)$context, 0, 500, 'UTF-8');

    if ($format === 'openai_moderation') {
        return ai_openai_moderate_image($cfg, $imageBytes, $mime, $context);
    }

    if ($format === 'anthropic') {
        $payload = [
            'model' => $cfg['model'],
            'max_tokens' => (int)($cfg['max_tokens'] ?? 512),
            'system' => 'You are a strict content safety classifier. Output JSON only.',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mime,
                            'data' => base64_encode($imageBytes),
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ];
        $data = ai_curl_json($cfg['base_url'] . '/v1/messages', [
            'x-api-key: ' . $cfg['key'],
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ], $payload);
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'] ?? '';
        }
        return ai_parse_moderation_json($text);
    }

    $payload = [
        'model' => $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => 'You are a strict content safety classifier. Output JSON only.'],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($imageBytes)]],
                ],
            ],
        ],
        'max_tokens' => (int)($cfg['max_tokens'] ?? 512),
        'stream' => false,
    ];
    $data = ai_curl_json($cfg['base_url'] . '/v1/chat/completions', [
        'Authorization: Bearer ' . $cfg['key'],
        'Content-Type: application/json',
    ], $payload);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return ai_parse_moderation_json($text);
}

function ai_openai_moderate_image(array $cfg, $imageBytes, $mime, $context = '') {
    $input = [];
    $context = trim((string)$context);
    if ($context !== '') {
        $input[] = [
            'type' => 'text',
            'text' => "Context for image moderation: " . mb_substr($context, 0, 500, 'UTF-8'),
        ];
    }
    $input[] = [
        'type' => 'image_url',
        'image_url' => [
            'url' => 'data:' . $mime . ';base64,' . base64_encode($imageBytes),
        ],
    ];
    $payload = [
        'model' => $cfg['model'],
        'input' => $input,
    ];
    $data = ai_curl_json(rtrim($cfg['base_url'], '/') . '/v1/moderations', [
        'Authorization: Bearer ' . $cfg['key'],
        'Content-Type: application/json',
    ], $payload);
    $result = $data['results'][0] ?? [];
    $scores = $result['category_scores'] ?? [];
    $categories = $result['categories'] ?? [];
    $sexual = (float)($scores['sexual'] ?? 0);
    $sexualMinors = (float)($scores['sexual/minors'] ?? 0);
    $score = max($sexual, $sexualMinors);
    $flagged = !empty($result['flagged']);
    $active = [];
    foreach ($categories as $name => $enabled) {
        if ($enabled) $active[] = $name;
    }
    if (!$active && $score > 0) {
        $active[] = 'sexual_score=' . round($sexual, 4);
        if ($sexualMinors > 0) $active[] = 'sexual_minors_score=' . round($sexualMinors, 4);
    }
    return [
        'score' => max(0.0, min(1.0, $score)),
        'reason' => $flagged || $active ? ('openai_moderation:' . implode(',', array_slice($active, 0, 6))) : 'openai_moderation:clean',
    ];
}

function ai_stream_string($text, callable $onDelta) {
    $chunks = preg_split('/(?<=\G.{80})/su', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chunks as $chunk) {
        $onDelta($chunk);
    }
}

function ai_stream_request($purpose, array $messages, callable $onDelta) {
    $errors = [];
    foreach (ai_configs($purpose) as $cfg) {
        if (!ai_config_has_key($cfg)) continue;
        $emitted = false;
        $wrappedDelta = function ($delta) use ($onDelta, &$emitted) {
            if ($delta !== '') $emitted = true;
            $onDelta($delta);
        };
        try {
            $format = $cfg['format'] ?? 'openai';
            if ($format === 'anthropic') {
                return ai_stream_anthropic($cfg, $messages, $wrappedDelta);
            }
            return ai_stream_openai($cfg, $messages, $wrappedDelta);
        } catch (Throwable $e) {
            $errors[] = ($cfg['model'] ?? 'unknown') . ': ' . $e->getMessage();
            error_log('AI provider failed for ' . $purpose . ': ' . end($errors));
            if ($emitted) {
                throw new RuntimeException('AI stream failed after output started: ' . $e->getMessage());
            }
        }
    }
    throw new RuntimeException('All AI providers failed for ' . $purpose . ': ' . implode(' | ', $errors));
}

function ai_curl_json($url, array $headers, array $payload) {
    return ai_curl_json_timeout($url, $headers, $payload, 90);
}

function ai_curl_json_timeout($url, array $headers, array $payload, $timeout) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)$timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80500) curl_close($ch);
    if ($body === false || $status >= 400) {
        throw new RuntimeException('AI gateway error: ' . ($err ?: 'HTTP ' . $status));
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('AI gateway returned non-JSON response');
    }
    return $data;
}

function ai_parse_moderation_json($text) {
    $text = trim((string)$text);
    if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $m)) {
        $text = trim($m[1]);
    } elseif (preg_match('/\{.*\}/s', $text, $m)) {
        $text = $m[0];
    }
    $json = json_decode($text, true);
    if (!is_array($json)) {
        throw new RuntimeException('Moderation model did not return JSON');
    }
    $score = (float)($json['adult_score'] ?? ($json['score'] ?? 0));
    $reason = trim((string)($json['reason'] ?? 'visual_moderation'));
    return [
        'score' => max(0.0, min(1.0, $score)),
        'reason' => $reason !== '' ? mb_substr($reason, 0, 240, 'UTF-8') : 'visual_moderation',
    ];
}

function ai_stream_openai(array $cfg, array $messages, callable $onDelta) {
    if (array_key_exists('stream', $cfg) && !$cfg['stream']) {
        $payload = [
            'model' => $cfg['model'],
            'messages' => array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
            'max_tokens' => (int)$cfg['max_tokens'],
            'stream' => false,
        ];
        $data = ai_curl_json(rtrim($cfg['base_url'], '/') . '/v1/chat/completions', [
            'Authorization: Bearer ' . $cfg['key'],
            'Content-Type: application/json',
        ], $payload);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            throw new RuntimeException('AI gateway returned empty content');
        }
        ai_stream_string($text, $onDelta);
        $usage = $data['usage'] ?? [];
        return [
            'input_tokens' => $usage['input_tokens'] ?? ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => $usage['output_tokens'] ?? ($usage['completion_tokens'] ?? mb_strlen($text, 'UTF-8')),
        ];
    }

    $payload = [
        'model' => $cfg['model'],
        'messages' => array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
        'max_tokens' => (int)$cfg['max_tokens'],
        'stream' => true,
    ];
    return ai_curl_sse($cfg['base_url'] . '/v1/chat/completions', [
        'Authorization: Bearer ' . $cfg['key'],
        'Content-Type: application/json',
    ], $payload, function ($data) use ($onDelta) {
        $delta = $data['choices'][0]['delta']['content'] ?? '';
        if ($delta !== '') $onDelta($delta);
    });
}

function ai_stream_anthropic(array $cfg, array $messages, callable $onDelta) {
    $system = '';
    $bodyMessages = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') {
            $system .= ($system === '' ? '' : "\n\n") . $m['content'];
        } else {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $bodyMessages[] = ['role' => $role, 'content' => $m['content']];
        }
    }
    $payload = [
        'model' => $cfg['model'],
        'max_tokens' => (int)$cfg['max_tokens'],
        'messages' => $bodyMessages,
        'stream' => true,
    ];
    if ($system !== '') $payload['system'] = $system;
    return ai_curl_sse($cfg['base_url'] . '/v1/messages', [
        'x-api-key: ' . $cfg['key'],
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ], $payload, function ($data) use ($onDelta) {
        $delta = $data['delta']['text'] ?? '';
        if ($delta === '' && isset($data['content_block']['text'])) $delta = $data['content_block']['text'];
        if ($delta !== '') $onDelta($delta);
    });
}

function ai_curl_sse($url, array $headers, array $payload, callable $onData) {
    $usage = ['input_tokens' => 0, 'output_tokens' => 0];
    $buffer = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 260,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, &$usage, $onData) {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                foreach (explode("\n", $event) as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data:') !== 0) continue;
                    $raw = trim(substr($line, 5));
                    if ($raw === '' || $raw === '[DONE]') continue;
                    $data = json_decode($raw, true);
                    if (!is_array($data)) continue;
                    if (isset($data['usage'])) {
                        $usage['input_tokens'] = $data['usage']['input_tokens'] ?? ($data['usage']['prompt_tokens'] ?? $usage['input_tokens']);
                        $usage['output_tokens'] = $data['usage']['output_tokens'] ?? ($data['usage']['completion_tokens'] ?? $usage['output_tokens']);
                    }
                    $onData($data);
                }
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80500) curl_close($ch);
    if ($ok === false || $status >= 400) {
        throw new RuntimeException('AI gateway error: ' . ($err ?: 'HTTP ' . $status));
    }
    return $usage;
}

function ai_mock_chat(array $messages) {
    $locale = resolve_locale();
    $last = '';
    foreach (array_reverse($messages) as $m) {
        if ($m['role'] === 'user') { $last = $m['content']; break; }
    }
    if ($last === '') {
        return t('app', 'greeting', $locale);
    }
    if (preg_match('/(域名|網域|前缀|前綴|二级域名|二級網域|subdomain|domain|prefix)/i', $last)
        && preg_match('/(自定义|自訂|指定|设置|設定|使用|用|叫|改成|想让|想讓|我要|希望|[a-z0-9]{3,10})/i', $last)) {
        if (preg_match('/[a-z0-9]{3,10}/i', $last, $m)) {
            $hint = strtolower($m[0]);
        } else {
            $hint = 'page';
        }
        if ($locale === 'en') return "Sure. I will check that subdomain prefix and save it for this page if available.\n\n[[ACTION:DOMAIN hint={$hint}]]";
        if ($locale === 'zh-TW') return "可以，我會檢查這個二級網域前綴是否可用，若已被占用會自動加短後綴。\n\n[[ACTION:DOMAIN hint={$hint}]]";
        return "可以，我会检查这个二级域名前缀是否可用，如果已被占用会自动加短后缀。\n\n[[ACTION:DOMAIN hint={$hint}]]";
    }
    if (preg_match('/(生成|画|畫|做|create|generate).{0,60}(图片|圖片|图|圖|配图|配圖|主视觉|主視覺|参考图|參考圖|image|visual|illustration)/iu', $last)) {
        if ($locale === 'en') return "I can generate a reference image and use it as page material.\n\n[[ACTION:IMAGE_GEN slot=hero prompt=hero_visual]]";
        if ($locale === 'zh-TW') return "可以，我會生成一張資料圖，並把它作為頁面主視覺素材。\n\n[[ACTION:IMAGE_GEN slot=hero prompt=主視覺資料圖]]";
        return "可以，我会生成一张资料图，并把它作为页面主视觉素材。\n\n[[ACTION:IMAGE_GEN slot=hero prompt=主视觉资料图]]";
    }
    if (preg_match('/图片|照片|素材|上传|上傳|配图|配圖|logo|主视觉|主視覺|产品图|產品圖|活动图|活動圖|image|photo|upload|visual/i', $last)) {
        if ($locale === 'en') return "You can upload images. Add a short note for each one, such as “hero visual” or “product detail”.\n\n[[ACTION:UPLOAD slot=hero hint=hero_visual]]";
        if ($locale === 'zh-TW') return "可以上傳圖片。請為每張圖寫一句用途說明，例如「頁面頂部主視覺」或「產品細節圖」。\n\n[[ACTION:UPLOAD slot=hero hint=頁面頂部主視覺]]";
        return "可以上传图片。请为每张图写一句用途说明，比如“页面顶部主视觉”或“产品细节图”。\n\n[[ACTION:UPLOAD slot=hero hint=页面顶部主视觉]]";
    }
    if (preg_match('/直接生成|开始生成|開始生成|可以生成|生成吧|发布吧|發布吧|上线吧|上線吧|重新生成|generate|publish|go live/i', $last)) {
        if ($locale === 'en') return "Great, the key points are ready. I will start generating the page now.\n\n[[ACTION:PUBLISH reason=user_confirmed]]";
        if ($locale === 'zh-TW') return "好的，要點已齊，我現在開始生成頁面。\n\n[[ACTION:PUBLISH reason=使用者已確認生成]]";
        return "好的，要点已齐，我现在开始生成页面。\n\n[[ACTION:PUBLISH reason=用户已确认生成]]";
    }
    if ($locale === 'en') return "I have noted your request. To make the page stronger, please add the target audience, desired visual style, and whether there should be contact info or a call-to-action.\n\nIf the information is enough now, you can also generate the page directly.\n\n[[ACTION:READY reason=brief_ready]]";
    if ($locale === 'zh-TW') return "我已記錄你的需求。為了讓頁面更完整，請再補充目標受眾、希望的視覺風格，以及是否有聯絡方式或行動按鈕。\n\n如果現在資訊已經夠用，也可以直接生成頁面。\n\n[[ACTION:READY reason=需求已基本完整]]";
    return "我已记录你的需求。为了让页面更完整，请再补充目标受众、希望的视觉风格，以及是否有联系方式或行动按钮。\n\n如果现在信息已经够用，也可以直接点击生成页面。\n\n[[ACTION:READY reason=需求已基本完整]]";
}

function ai_mock_html(array $messages) {
    $locale = resolve_locale();
    $text = '';
    foreach ($messages as $m) {
        if ($m['role'] !== 'system') $text .= "\n" . $m['role'] . ': ' . $m['content'];
    }
    $summary = h(excerpt_plain_text($text, 240));
    $title = $locale === 'en' ? 'xlog page' : ($locale === 'zh-TW' ? 'xlog 頁面' : 'xlog 页面');
    $description = $locale === 'en' ? 'A personal page created by xlog.ink AI' : ($locale === 'zh-TW' ? '由 xlog.ink AI 建立的個人頁面' : '由 xlog.ink AI 创建的个人页面');
    $headline = $locale === 'en' ? 'Your page is ready' : ($locale === 'zh-TW' ? '你的頁面已準備好' : '你的页面已准备好');
    return <<<HTML
```html
<!DOCTYPE html>
<html lang="{$locale}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$title}</title>
  <meta name="description" content="{$description}">
  <meta property="og:title" content="{$title}">
  <meta property="og:description" content="{$description}">
  <style>
    *{box-sizing:border-box}body{margin:0;font-family:Georgia,'Times New Roman',serif;background:#f5f1e8;color:#191714}main{min-height:100vh;padding:8vw;display:grid;place-items:center}.sheet{max-width:880px;border:1px solid #191714;background:#fffdf7;padding:clamp(28px,6vw,72px);box-shadow:18px 18px 0 #191714}p{font-size:20px;line-height:1.7}.eyebrow{font:700 12px/1.2 ui-monospace,monospace;letter-spacing:.16em;text-transform:uppercase}h1{font-size:clamp(42px,10vw,108px);line-height:.9;margin:20px 0}.cta{display:inline-block;margin-top:24px;color:#fff;background:#191714;padding:14px 18px;text-decoration:none}
  </style>
</head>
<body><main><section class="sheet"><div class="eyebrow">Generated by xlog.ink</div><h1>{$headline}</h1><p>{$summary}</p><a class="cta" href="https://xlog.ink">xlog.ink</a></section></main></body>
</html>
```
HTML;
}
