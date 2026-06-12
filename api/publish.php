<?php
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/imageproc.php';
require_once __DIR__ . '/../includes/page_edit.php';
require_once __DIR__ . '/../includes/recent.php';
require_once __DIR__ . '/../includes/turnstile.php';

@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ignore_user_abort(true);

require_method('POST');
$data = json_input();
$sessionId = trim($data['session_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', '你不能发布这个会话。', 403);
if (!current_user_id()) xlog_cookie_id();

sse_start();
$quotaCharge = null;
$editPage = null;
$editMode = $session['edit_mode'] ?? '';
$usage = [];
try {
    if (!api_turnstile_ok($data['turnstile_token'] ?? '')) {
        record_publish_event($sessionId, null, 'generate', 'failed', 'turnstile_failed');
        sse_event('error', ['code' => 'turnstile_failed', 'message' => '请完成人机验证后再生成。']);
        exit;
    }

    if (!empty($session['page_slug']) && in_array($editMode, ['edit_owner', 'edit_token'], true)) {
        $editPage = db_one('SELECT * FROM pages WHERE slug = ? AND status = ?', [$session['page_slug'], 'live']);
        if (!$editPage) {
            record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'edit_page_not_found');
            sse_event('error', ['code' => 'edit_page_not_found', 'message' => '要修改的页面不存在或已下线。']);
            exit;
        }
        if ($editMode === 'edit_owner') {
            if (!current_user_can_edit_page($editPage)) {
                record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'forbidden_edit_session');
                sse_event('error', ['code' => 'forbidden_edit_session', 'message' => '你没有权限覆盖这个页面。']);
                exit;
            }
        } elseif ($editMode === 'edit_token') {
            if (empty($editPage['editable'])) {
                record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'forbidden_edit_session');
                sse_event('error', ['code' => 'forbidden_edit_session', 'message' => '这个页面当前不可通过邮件链接修改。']);
                exit;
            }
        }
    } elseif (!empty($editMode)) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', 'invalid_edit_session');
        sse_event('error', ['code' => 'invalid_edit_session', 'message' => '编辑会话无效，请重新进入修改链接。']);
        exit;
    }

    $quotaCharge = consume_quota('generate');
    if (!$quotaCharge['ok']) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', 'quota_exceeded');
        sse_event('error', ['code' => 'quota_exceeded', 'message' => '今日生成额度已用完。登录后每天可生成 50 个页面。']);
        exit;
    }

    sse_event('stage', ['stage' => 'generating']);
    write_live_preview($sessionId, '');
    sse_event('preview', [
        'url' => '/api/preview.php?session_id=' . rawurlencode($sessionId),
        'refresh_ms' => 1000,
    ]);
    $messages = session_messages($sessionId) ?: [];
    $images = session_images_context($sessionId);
    $system = prompt_text('gen-system.txt');
    $context = "【图片清单 JSON】\n" . json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $modelMessages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "下面是完整对话历史 JSON，请生成最终页面。\n" . json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n" . $context],
    ];
    $raw = '';
    $lastPreviewWrite = 0.0;
    $usage = ai_stream_generate($modelMessages, function ($delta) use (&$raw, &$lastPreviewWrite, $sessionId) {
        $raw .= $delta;
        sse_event('delta', ['text' => mb_substr($delta, 0, 160, 'UTF-8')]);
        $now = microtime(true);
        if ($now - $lastPreviewWrite >= 0.8) {
            write_live_preview($sessionId, $raw);
            $lastPreviewWrite = $now;
        }
    });
    write_live_preview($sessionId, $raw);

    if (strpos($raw, '<!-- REFUSED:') !== false) {
        refund_generate_charge($quotaCharge);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'refused', 'AI refused the request', $usage);
        sse_event('error', ['code' => 'refused', 'message' => '这个请求不适合公开生成，请调整内容后再试。']);
        exit;
    }

    $html = extract_html_document($raw);
    validate_generated_html($html);
    $pageSlug = $editPage ? $session['page_slug'] : generate_unique_slug();
    $html = move_session_assets_to_slug($sessionId, $pageSlug, $html);
    $isAdult = !empty($data['is_adult']);
    $adultFlagCleared = $editPage && !empty($editPage['is_adult']) && !$isAdult;
    if ($isAdult) {
        $html = inject_adult_gate($html, $pageSlug);
    }
    $html = inject_generated_csp($html);
    $html = inject_generated_footer($html);
    write_live_preview($sessionId, $html);

    sse_event('stage', ['stage' => 'writing']);
    $path = xlog_config('site_dir') . '/' . $pageSlug . '.html';
    if (file_put_contents($path, $html, LOCK_EX) === false) {
        throw new RuntimeException('Write failed');
    }

    $title = extract_title($html) ?: 'AI Page';
    $type = infer_page_type($messages);
    $lang = extract_html_lang($html) ?: 'zh-CN';
    $now = now_iso();
    $cost = (int)(($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));
    if (db_one('SELECT slug FROM pages WHERE slug = ?', [$pageSlug])) {
        db_exec('UPDATE pages SET title = ?, type = ?, lang = ?, updated_at = ?, cost_tokens = ?, session_id = ?, html_path = ?, is_adult = ? WHERE slug = ?', [$title, $type, $lang, $now, $cost, $sessionId, $path, $isAdult ? 1 : 0, $pageSlug]);
    } else {
        db_exec(
            'INSERT INTO pages (slug, title, type, lang, created_at, owner_user_id, status, cost_tokens, session_id, html_path, is_adult) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pageSlug, $title, $type, $lang, $now, current_user_id(), 'live', $cost, $sessionId, $path, $isAdult ? 1 : 0]
        );
    }
    db_exec('UPDATE sessions SET page_slug = ?, state = ?, updated_at = ? WHERE id = ?', [$pageSlug, 'done', $now, $sessionId]);
    record_publish_event($sessionId, $pageSlug, 'generate', 'success', null, $usage, $isAdult);
    if ($adultFlagCleared) {
        record_publish_event($sessionId, $pageSlug, 'adult_flag', 'notice', 'adult_flag_cleared', [], false);
    }
    $quotaCharge = null;
    try {
        build_recent_html_file();
    } catch (Throwable $e) {
        error_log('recent rebuild failed: ' . $e->getMessage());
    }

    $url = 'https://' . $pageSlug . '.xlog.ink/';
    append_session_message($sessionId, 'system', '[系统事件] 页面已发布：' . $url . '。标题《' . $title . '》。如果用户继续要求修改，默认是修改这个页面；如果用户明确说“重新做一个/再生成一个/新页面”，则进入下一次发布流程。');
    sse_event('stage', ['stage' => 'done']);
    sse_event('result', ['url' => $url, 'slug' => $pageSlug, 'qr_payload' => $url, 'is_adult' => $isAdult]);
    sse_event('done', ['usage' => $usage]);
} catch (Throwable $e) {
    refund_generate_charge($quotaCharge);
    record_publish_event($sessionId ?? null, isset($session) ? ($session['page_slug'] ?: null) : null, 'generate', 'failed', $e->getMessage(), $usage);
    error_log('publish failed: ' . $e->getMessage());
    sse_event('error', ['code' => 'publish_failed', 'message' => friendly_publish_error($e)]);
}

function refund_generate_charge(&$charge) {
    if ($charge) {
        refund_quota('generate', $charge);
        $charge = null;
    }
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

function live_preview_path($sessionId) {
    if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        throw new RuntimeException('Invalid preview session');
    }
    $dir = xlog_config('data_dir') . '/previews';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir . '/' . $sessionId . '.html';
}

function write_live_preview($sessionId, $raw) {
    try {
        file_put_contents(live_preview_path($sessionId), live_preview_document($raw), LOCK_EX);
    } catch (Throwable $e) {
        error_log('live preview write failed: ' . $e->getMessage());
    }
}

function live_preview_document($raw) {
    $clean = preg_replace('/^\s*```(?:html)?\s*/i', '', (string)$raw);
    $clean = preg_replace('/```\s*$/', '', $clean);
    $clean = trim($clean);
    $pos = stripos($clean, '<!DOCTYPE html');
    if ($pos === false) $pos = stripos($clean, '<html');
    if ($pos !== false) {
        $clean = substr($clean, $pos);
    }
    if ($clean === '' || strpos($clean, '<') === false) {
        $escaped = h($clean === '' ? '等待模型开始输出 HTML...' : $clean);
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0c10;color:#b6ffe7;font:14px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;padding:24px}
    pre{max-width:78ch;white-space:pre-wrap;opacity:.86}
  </style>
</head>
<body><pre>{$escaped}</pre></body>
</html>
HTML;
    }
    return $clean . "\n<!-- xlog live preview: partial html stream -->";
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

function extract_title($html) {
    return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
}

function extract_html_lang($html) {
    if (!preg_match('/<html\b[^>]*\blang=["\']?([a-z]{2,3}(?:-[a-z0-9]+)?)/i', $html, $m)) {
        return '';
    }
    return strtolower($m[1]);
}

function infer_page_type(array $messages) {
    foreach ($messages as $message) {
        if (($message['role'] ?? '') !== 'user') continue;
        $text = mb_strtolower((string)($message['content'] ?? ''), 'UTF-8');
        if (preg_match('/(名片|个人主页|个人介绍|联系卡|business\s*card)/iu', $text)) return 'card';
        if (preg_match('/(宣传海报|海报页|产品宣传|服务宣传|poster|promo)/iu', $text)) return 'poster';
        if (preg_match('/(文章页面|文章页|长文|博客|article|blog)/iu', $text)) return 'article';
        if (preg_match('/(活动页面|活动页|报名页|发布会|沙龙|节日活动|event)/iu', $text)) return 'event';
    }
    return 'free';
}

function friendly_publish_error(Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'External scripts') !== false) return '页面里包含外链脚本，已被安全策略拒绝。请让 AI 重新生成纯内联页面。';
    if (stripos($message, 'External stylesheets') !== false) return '页面里包含外链样式表，已被安全策略拒绝。请让 AI 重新生成纯内联样式。';
    if (stripos($message, 'Only xlog.ink site-assets') !== false) return '页面引用了非本站图片，已被安全策略拒绝。请重新生成或先上传图片。';
    if (stripos($message, 'iframes') !== false || stripos($message, 'forms') !== false) return '页面包含 iframe 或表单，暂不允许发布。请换一种静态展示方式。';
    if (stripos($message, 'complete HTML') !== false || stripos($message, 'DOCTYPE') !== false) return '模型没有返回完整 HTML，额度已退回。请再试一次或减少页面复杂度。';
    if (stripos($message, 'Write failed') !== false) return '页面文件写入失败，额度已退回。请稍后重试。';
    return '生成失败，额度已退回。请稍后重试，或减少页面内容后再生成。';
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
