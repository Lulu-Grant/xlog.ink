<?php
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/imageproc.php';
require_once __DIR__ . '/../includes/recent.php';
require_once __DIR__ . '/../includes/turnstile.php';

require_method('POST');
$data = json_input();
$sessionId = trim($data['session_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!current_user_id()) xlog_cookie_id();

sse_start();
try {
    if (!api_turnstile_ok($data['turnstile_token'] ?? '')) {
        record_publish_event($sessionId, null, 'generate', 'failed', 'turnstile_failed');
        sse_event('error', ['code' => 'turnstile_failed', 'message' => '请完成人机验证后再生成。']);
        exit;
    }
    $quotaCheck = can_consume_quota('generate');
    if (!$quotaCheck['ok']) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', 'quota_exceeded');
        sse_event('error', ['code' => 'quota_exceeded', 'message' => '今日生成额度已用完。登录后每天可生成 50 个页面。']);
        exit;
    }

    sse_event('stage', ['stage' => 'generating']);
    $messages = session_messages($sessionId) ?: [];
    $images = session_images_context($sessionId);
    $system = prompt_text('gen-system.txt');
    $context = "【图片清单 JSON】\n" . json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $modelMessages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "下面是完整对话历史 JSON，请生成最终页面。\n" . json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n" . $context],
    ];
    $raw = '';
    $usage = ai_stream_generate($modelMessages, function ($delta) use (&$raw) {
        $raw .= $delta;
        sse_event('delta', ['text' => mb_substr($delta, 0, 160, 'UTF-8')]);
    });

    if (strpos($raw, '<!-- REFUSED:') !== false) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'refused', 'AI refused the request', $usage);
        sse_event('error', ['code' => 'refused', 'message' => '这个请求不适合公开生成，请调整内容后再试。']);
        exit;
    }

    $html = extract_html_document($raw);
    validate_generated_html($html);
    $pageSlug = $session['page_slug'] ?: generate_unique_slug();
    $html = move_session_assets_to_slug($sessionId, $pageSlug, $html);
    $isAdult = !empty($data['is_adult']) || conversation_indicates_adult($messages);
    if ($isAdult) {
        $html = inject_adult_gate($html, $pageSlug);
    }
    $html = inject_generated_csp($html);
    $html = inject_generated_footer($html);

    $quota = consume_quota('generate');
    if (!$quota['ok']) {
        sse_event('error', ['code' => 'quota_exceeded', 'message' => '今日生成额度已用完。登录后每天可生成 50 个页面。']);
        exit;
    }

    sse_event('stage', ['stage' => 'writing']);
    $path = xlog_config('site_dir') . '/' . $pageSlug . '.html';
    if (file_put_contents($path, $html, LOCK_EX) === false) {
        throw new RuntimeException('Write failed');
    }

    $title = extract_title($html) ?: 'AI Page';
    $type = infer_page_type($messages);
    $now = now_iso();
    $cost = (int)(($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));
    if (db_one('SELECT slug FROM pages WHERE slug = ?', [$pageSlug])) {
        db_exec('UPDATE pages SET title = ?, type = ?, updated_at = ?, cost_tokens = ?, session_id = ?, html_path = ?, is_adult = ? WHERE slug = ?', [$title, $type, $now, $cost, $sessionId, $path, $isAdult ? 1 : 0, $pageSlug]);
    } else {
        db_exec(
            'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, cost_tokens, session_id, html_path, is_adult) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pageSlug, $title, $type, 'zh-CN', $now, current_user_id(), 'live', $cost, $sessionId, $path, $isAdult ? 1 : 0]
        );
    }
    db_exec('UPDATE sessions SET page_slug = ?, state = ?, updated_at = ? WHERE id = ?', [$pageSlug, 'done', $now, $sessionId]);
    record_publish_event($sessionId, $pageSlug, 'generate', 'success', null, $usage, $isAdult);
    try {
        build_recent_html_file();
    } catch (Throwable $e) {
        error_log('recent rebuild failed: ' . $e->getMessage());
    }

    $url = 'https://' . $pageSlug . '.xlog.ink/';
    sse_event('stage', ['stage' => 'done']);
    sse_event('result', ['url' => $url, 'slug' => $pageSlug, 'qr_payload' => $url]);
    sse_event('done', ['usage' => $usage]);
} catch (Throwable $e) {
    record_publish_event($sessionId ?? null, isset($session) ? ($session['page_slug'] ?: null) : null, 'generate', 'failed', $e->getMessage(), $usage ?? []);
    sse_event('error', ['code' => 'publish_failed', 'message' => $e->getMessage()]);
}

function api_turnstile_ok($token) {
    if (!xlog_config('turnstile.enabled', false)) return true;
    return turnstile_verify($token, client_ip())['ok'];
}

function extract_html_document($raw) {
    if (preg_match('/```html\s*(.*?)```/is', $raw, $m)) $raw = $m[1];
    elseif (preg_match('/```\s*(.*?)```/is', $raw, $m)) $raw = $m[1];
    $raw = trim($raw);
    $pos = stripos($raw, '<!DOCTYPE html');
    if ($pos !== false) $raw = substr($raw, $pos);
    if (!preg_match('/<!DOCTYPE html/i', $raw) || stripos($raw, '</html>') === false) {
        throw new RuntimeException('AI did not return a complete HTML document');
    }
    return trim($raw);
}

function validate_generated_html($html) {
    if (preg_match('/<script\b[^>]*\bsrc\s*=/i', $html)) throw new RuntimeException('External scripts are not allowed');
    if (preg_match('/<link\b[^>]*\brel=["\']?stylesheet/i', $html)) throw new RuntimeException('External stylesheets are not allowed');
    if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (strpos($src, 'data:') === 0) continue;
            if (strpos($src, 'https://xlog.ink/site-assets/') !== 0) {
                throw new RuntimeException('Only xlog.ink site-assets images are allowed');
            }
        }
    }
    if (preg_match('/<iframe\b/i', $html)) throw new RuntimeException('iframes are not allowed');
    if (preg_match('/<form\b/i', $html)) throw new RuntimeException('forms are not allowed');
}

function inject_generated_footer($html) {
    $badge = '<div style="position:fixed;right:12px;bottom:12px;z-index:9999;font:12px/1.2 ui-monospace,monospace;background:rgba(0,0,0,.72);color:#fff;padding:8px 10px;border-radius:999px"><a href="https://xlog.ink" style="color:inherit;text-decoration:none">Made with xlog.ink</a></div>';
    return preg_replace('/<\/body>/i', $badge . "\n</body>", $html, 1);
}

function inject_generated_csp($html) {
    if (stripos($html, 'http-equiv="Content-Security-Policy"') !== false || stripos($html, "http-equiv='Content-Security-Policy'") !== false) {
        return $html;
    }
    $policy = "default-src 'self'; img-src 'self' data: https://xlog.ink; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; media-src 'self' https://xlog.ink; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    $meta = '<meta http-equiv="Content-Security-Policy" content="' . h($policy) . '">';
    return preg_replace('/<\/head>/i', $meta . "\n</head>", $html, 1);
}

function inject_adult_gate($html, $slug) {
    $adult = build_adult_gate_parts('zh-CN', $slug, true);
    $headInsert = adult_gate_inline_css() . "\n" . $adult['boot_html'];
    $bodyInsert = $adult['body_boot_html'] . "\n" . $adult['gate_html'];
    $html = preg_replace('/<\/head>/i', $headInsert . "\n</head>", $html, 1);
    $html = preg_replace_callback('/<body([^>]*)>/i', function ($m) use ($adult, $bodyInsert) {
        $attrs = $m[1];
        if (preg_match('/\sclass=["\']([^"\']*)["\']/i', $attrs, $cm)) {
            $newClass = trim($cm[1] . ' adult-gate--enabled adult-gate--locked');
            $attrs = preg_replace('/\sclass=["\'][^"\']*["\']/i', ' class="' . h($newClass) . '"', $attrs, 1);
        } else {
            $attrs .= ' class="adult-gate--enabled adult-gate--locked"';
        }
        if (!preg_match('/\sdata-adult-key=/i', $attrs)) {
            $attrs .= ' data-adult-key="' . h($adult['adult_key']) . '"';
        }
        return '<body' . $attrs . ">\n" . $bodyInsert;
    }, $html, 1);
    $runtime = build_generated_page_runtime_html();
    $html = preg_replace('/<\/body>/i', $runtime . "\n</body>", $html, 1);
    return $html;
}

function adult_gate_inline_css() {
    return <<<HTML
<style>
.adult-gate--locked > *:not(.adult-gate):not(script):not(style){filter:blur(12px);pointer-events:none;user-select:none}
.adult-gate{display:none;position:fixed;inset:0;z-index:2147483647;place-items:center;background:rgba(8,8,10,.76);padding:24px;color:#fff}
.adult-gate--locked .adult-gate{display:grid}
.adult-gate--approved .adult-gate{display:none}
.adult-gate-card{width:min(460px,100%);background:#141414;border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:28px;box-shadow:0 30px 90px rgba(0,0,0,.45);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.adult-gate-badge{display:inline-flex;margin-bottom:12px;padding:6px 10px;border-radius:999px;background:#fff;color:#111;font-weight:800;font-size:13px}
.adult-gate-card h1{margin:0 0 10px;font-size:26px;line-height:1.15}
.adult-gate-card p{margin:0;color:rgba(255,255,255,.76);line-height:1.6}
.adult-gate-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.adult-gate-actions .button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 14px;border-radius:12px;border:1px solid rgba(255,255,255,.24);background:#fff;color:#111;text-decoration:none;font-weight:800}
.adult-gate-actions .button--ghost{background:transparent;color:#fff}
</style>
HTML;
}

function conversation_indicates_adult(array $messages) {
    $text = mb_strtolower(json_encode($messages, JSON_UNESCAPED_UNICODE), 'UTF-8');
    foreach (['18+', '成人', '色情', 'nsfw', 'adult'] as $needle) {
        if (strpos($text, $needle) !== false) return true;
    }
    return false;
}

function extract_title($html) {
    return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
}

function infer_page_type(array $messages) {
    $text = mb_strtolower(json_encode($messages, JSON_UNESCAPED_UNICODE), 'UTF-8');
    foreach (['card' => '名片', 'poster' => '宣传', 'article' => '文章', 'event' => '活动'] as $type => $needle) {
        if (strpos($text, $needle) !== false || strpos($text, $type) !== false) return $type;
    }
    return 'free';
}

function record_publish_event($sessionId, $slug, $kind, $status, $message = null, array $usage = [], $isAdult = false) {
    try {
        db_exec(
            'INSERT INTO publish_events (session_id, slug, user_id, kind, status, message, input_tokens, output_tokens, is_adult, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sessionId,
                $slug,
                current_user_id(),
                $kind,
                $status,
                $message,
                (int)($usage['input_tokens'] ?? 0),
                (int)($usage['output_tokens'] ?? 0),
                $isAdult ? 1 : 0,
                now_iso(),
            ]
        );
    } catch (Throwable $e) {
        error_log('publish event write failed: ' . $e->getMessage());
    }
}
