<?php
// Model adapter for OpenAI-compatible and Anthropic-compatible gateways.

require_once __DIR__ . '/quota.php';

function prompt_text($name) {
    $path = XLOG_ROOT . '/prompts/' . $name;
    return is_file($path) ? file_get_contents($path) : '';
}

function ai_config($purpose) {
    $cfg = xlog_config('ai.' . $purpose);
    $cfg['base_url'] = rtrim(xlog_config('ai.base_url'), '/');
    return $cfg;
}

function ai_has_key($purpose) {
    $cfg = ai_config($purpose);
    return !empty($cfg['key']) && strpos($cfg['key'], '<') === false;
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

function ai_stream_string($text, callable $onDelta) {
    $chunks = preg_split('/(?<=\G.{80})/su', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($chunks as $chunk) {
        $onDelta($chunk);
    }
}

function ai_stream_request($purpose, array $messages, callable $onDelta) {
    $cfg = ai_config($purpose);
    $format = $cfg['format'] ?? 'openai';
    if ($format === 'anthropic') {
        return ai_stream_anthropic($cfg, $messages, $onDelta);
    }
    return ai_stream_openai($cfg, $messages, $onDelta);
}

function ai_stream_openai(array $cfg, array $messages, callable $onDelta) {
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
    curl_close($ch);
    if ($ok === false || $status >= 400) {
        throw new RuntimeException('AI gateway error: ' . ($err ?: 'HTTP ' . $status));
    }
    return $usage;
}

function ai_mock_chat(array $messages) {
    $last = '';
    foreach (array_reverse($messages) as $m) {
        if ($m['role'] === 'user') { $last = $m['content']; break; }
    }
    if ($last === '') {
        return "你想创建哪一类页面？可以选名片、宣传海报、文章页面、活动页面，或者直接自由描述。";
    }
    if (preg_match('/图片|照片|素材|上传|配图|logo|主视觉|产品图|活动图/i', $last)) {
        return "可以上传图片。请为每张图写一句用途说明，比如“页面顶部主视觉”或“产品细节图”。\n\n[[ACTION:UPLOAD slot=hero hint=页面顶部主视觉]]";
    }
    return "我已记录你的需求。为了让页面更完整，请再补充目标受众、希望的视觉风格，以及是否有联系方式或行动按钮。\n\n如果现在信息已经够用，也可以直接点击生成页面。\n\n[[ACTION:READY reason=需求已基本完整]]";
}

function ai_mock_html(array $messages) {
    $text = '';
    foreach ($messages as $m) {
        if ($m['role'] !== 'system') $text .= "\n" . $m['role'] . ': ' . $m['content'];
    }
    $summary = h(excerpt_plain_text($text, 240));
    return <<<HTML
```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>xlog 页面</title>
  <meta name="description" content="由 xlog.ink AI 创建的个人页面">
  <meta property="og:title" content="xlog 页面">
  <meta property="og:description" content="由 xlog.ink AI 创建的个人页面">
  <style>
    *{box-sizing:border-box}body{margin:0;font-family:Georgia,'Times New Roman',serif;background:#f5f1e8;color:#191714}main{min-height:100vh;padding:8vw;display:grid;place-items:center}.sheet{max-width:880px;border:1px solid #191714;background:#fffdf7;padding:clamp(28px,6vw,72px);box-shadow:18px 18px 0 #191714}p{font-size:20px;line-height:1.7}.eyebrow{font:700 12px/1.2 ui-monospace,monospace;letter-spacing:.16em;text-transform:uppercase}h1{font-size:clamp(42px,10vw,108px);line-height:.9;margin:20px 0}.cta{display:inline-block;margin-top:24px;color:#fff;background:#191714;padding:14px 18px;text-decoration:none}
  </style>
</head>
<body><main><section class="sheet"><div class="eyebrow">Generated by xlog.ink</div><h1>你的页面已准备好</h1><p>{$summary}</p><a class="cta" href="https://xlog.ink">xlog.ink</a></section></main></body>
</html>
```
HTML;
}
