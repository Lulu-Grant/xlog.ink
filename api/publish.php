<?php
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/content_tools.php';
require_once __DIR__ . '/../includes/imageproc.php';
require_once __DIR__ . '/../includes/page_edit.php';
require_once __DIR__ . '/../includes/recent.php';
require_once __DIR__ . '/../includes/sitemap.php';
require_once __DIR__ . '/../includes/turnstile.php';

@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ignore_user_abort(true);

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$GLOBALS['xlog_publish_locale'] = $locale;
$sessionId = trim($data['session_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenSessionPublish', $locale), 403);
db_exec('UPDATE sessions SET locale = ?, updated_at = ? WHERE id = ?', [$locale, now_iso(), $sessionId]);
$session['locale'] = $locale;
if (!current_user_id()) xlog_cookie_id();

sse_start();
$quotaCharge = null;
$editPage = null;
$editMode = $session['edit_mode'] ?? '';
$usage = [];
$generationLocked = false;
$previousState = (string)($session['state'] ?? 'chatting');
try {
    if (!api_turnstile_ok($data['turnstile_token'] ?? '')) {
        record_publish_event($sessionId, null, 'generate', 'failed', 'turnstile_failed');
        sse_event('error', ['code' => 'turnstile_failed', 'message' => t('api', 'turnstileFailed', $locale)]);
        exit;
    }

    if (!empty($session['page_slug']) && in_array($editMode, ['edit_owner', 'edit_token'], true)) {
        $editPage = db_one('SELECT * FROM pages WHERE slug = ? AND status = ?', [$session['page_slug'], 'live']);
        if (!$editPage) {
            record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'edit_page_not_found');
            sse_event('error', ['code' => 'edit_page_not_found', 'message' => t('api', 'editPageNotFound', $locale)]);
            exit;
        }
        if ($editMode === 'edit_owner') {
            if (!current_user_can_edit_page($editPage)) {
                record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'forbidden_edit_session');
                sse_event('error', ['code' => 'forbidden_edit_session', 'message' => t('api', 'forbiddenEditOwner', $locale)]);
                exit;
            }
        } elseif ($editMode === 'edit_token') {
            if (empty($editPage['editable'])) {
                record_publish_event($sessionId, $session['page_slug'], 'generate', 'failed', 'forbidden_edit_session');
                sse_event('error', ['code' => 'forbidden_edit_session', 'message' => t('api', 'forbiddenEditToken', $locale)]);
                exit;
            }
        }
    } elseif (!empty($editMode)) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', 'invalid_edit_session');
        sse_event('error', ['code' => 'invalid_edit_session', 'message' => t('api', 'invalidEditSession', $locale)]);
        exit;
    }

    $generationLocked = lock_publish_session($sessionId);
    if (!$generationLocked) {
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', 'session_generating');
        sse_event('error', ['code' => 'session_generating', 'message' => t('api', 'sessionGenerating', $locale)]);
        exit;
    }

    $messages = session_messages($sessionId) ?: [];
    $images = session_images_context($sessionId);
    $generationMessages = generation_context_messages($messages);
    $editContext = $editPage ? build_edit_page_generation_context($editPage) : null;
    sse_event('stage', ['stage' => 'moderating']);
    $adult = assess_session_adult($sessionId, $generationMessages);
    if (!empty($adult['must_block'])) {
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'moderation', 'refused', $adult['reason']);
        sse_event('error', ['code' => 'content_blocked', 'message' => t('api', 'contentBlocked', $locale)]);
        exit;
    }
    if (($adult['status'] ?? 'error') !== 'ok') {
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'moderation', 'failed', $adult['reason']);
        sse_event('error', ['code' => 'moderation_unavailable', 'message' => t('api', 'moderationUnavailable', $locale)]);
        exit;
    }

    $quotaCharge = consume_quota('generate');
    if (!$quotaCharge['ok']) {
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        $quotaReason = (string)($quotaCharge['reason'] ?? 'daily_quota_exceeded');
        $isCredits = $quotaReason === 'credits_exhausted';
        $errorCode = $isCredits ? 'credits_exhausted' : 'quota_exceeded';
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'failed', $errorCode);
        sse_event('error', [
            'code' => $errorCode,
            'reason' => $quotaReason,
            'identity' => $quotaCharge['identity'] ?? null,
            'message' => $isCredits
                ? t('api', 'creditsExhausted', $locale)
                : t('api', 'quotaExceeded', $locale),
            'can_recharge' => $isCredits || (($quotaCharge['identity'] ?? '') === 'user'),
        ]);
        exit;
    }

    sse_event('stage', ['stage' => 'generating']);
    $system = prompt_text('gen-system.txt') . "\n\n" . t('prompt', 'genLanguage', $locale);
    $context = "【图片清单 JSON】\n" . json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($editContext) {
        $context .= "\n\n【当前页面编辑上下文 JSON】\n" . json_encode($editContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $modelMessages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "下面是精简后的最新有效对话上下文 JSON，请生成最终页面。旧的已发布页面、系统事件和交付卡片已被移除；图片地址一律以图片清单 JSON 为准。\n" . json_encode($generationMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n" . $context],
    ];
    $raw = '';
    $usage = ai_stream_generate($modelMessages, function ($delta) use (&$raw) {
        $raw .= $delta;
        sse_event('delta', ['text' => mb_substr($delta, 0, 160, 'UTF-8')]);
    });

    if (strpos($raw, '<!-- REFUSED:') !== false) {
        refund_generate_charge($quotaCharge);
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'generate', 'refused', 'AI refused the request', $usage);
        sse_event('error', ['code' => 'refused', 'message' => t('api', 'refused', $locale)]);
        exit;
    }

    $html = extract_html_document($raw);
    require_once __DIR__ . '/../includes/html_sanitize.php';
    $html = xlog_sanitize_generated_html($html);
    validate_generated_html($html);
    $htmlModeration = assess_adult_text_with_ai(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $adult = merge_moderation_results([$adult, $htmlModeration]);
    if (!empty($adult['must_block'])) {
        refund_generate_charge($quotaCharge);
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'moderation', 'refused', $adult['reason'], $usage);
        sse_event('error', ['code' => 'content_blocked', 'message' => t('api', 'contentBlocked', $locale)]);
        exit;
    }
    if (($adult['status'] ?? 'error') !== 'ok') {
        refund_generate_charge($quotaCharge);
        restore_publish_session_state($sessionId, $previousState, $generationLocked);
        record_publish_event($sessionId, $session['page_slug'] ?: null, 'moderation', 'failed', $adult['reason'], $usage);
        sse_event('error', ['code' => 'moderation_unavailable', 'message' => t('api', 'moderationUnavailable', $locale)]);
        exit;
    }
    $title = extract_title($html) ?: 'AI Page';
    $desiredSlug = trim((string)($session['desired_slug'] ?? ''));
    // AUDIT-8 P1-1: edit uses existing slug only; create reserves via INSERT (never blind UPDATE).
    if ($editPage) {
        $slugResult = ['slug' => (string)$session['page_slug'], 'source' => 'edit'];
        $pageSlug = $slugResult['slug'];
    } else {
        // Provisional slug for asset rewrite; final slug confirmed after INSERT reserve.
        $provisional = generate_semantic_slug($generationMessages, $title, $desiredSlug);
        $pageSlug = $provisional['slug'];
        $slugResult = $provisional;
    }
    $html = move_session_assets_to_slug($sessionId, $pageSlug, $html);
    $isAdult = !empty($adult['is_adult']);
    $adultFlagCleared = $editPage && !empty($editPage['is_adult']) && !$isAdult;
    $ogImagePath = first_session_image_path($sessionId);
    $ogImageUrl = $ogImagePath ? image_public_url($ogImagePath) : '';
    $description = generated_page_description($html, $title);
    $html = ensure_page_meta($html, [
        'title' => $title,
        'description' => $description,
        'og_title' => $title,
        'og_description' => $description,
        'og_image' => $ogImageUrl,
    ]);
    if ($isAdult) {
        $html = inject_adult_gate($html, $pageSlug, normalize_locale(extract_html_lang($html)) ?: $locale);
    }
    $html = inject_generated_csp($html);
    $pageLang = normalize_locale(extract_html_lang($html)) ?: $locale;
    $html = inject_generated_footer($html, $pageSlug, $pageLang);

    sse_event('stage', ['stage' => 'writing']);
    $screenshotPath = '';
    $type = infer_page_type($generationMessages);
    $lang = normalize_locale(extract_html_lang($html)) ?: $locale;
    $now = now_iso();
    $cost = (int)(($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0));

    if ($editPage) {
        $path = rtrim((string)xlog_config('site_dir'), '/') . '/' . $pageSlug . '.html';
        write_site_html_atomic($path, $html);
        $updated = publish_update_edit_page($pageSlug, [
            'title' => $title,
            'type' => $type,
            'lang' => $lang,
            'updated_at' => $now,
            'cost_tokens' => $cost,
            'session_id' => $sessionId,
            'html_path' => $path,
            'is_adult' => $isAdult ? 1 : 0,
            'adult_score' => $adult['score'],
            'adult_reason' => $adult['reason'],
            'og_image_path' => $ogImagePath,
            'screenshot_path' => $screenshotPath ?: '',
            'slug_source' => 'edit',
        ]);
        if ($updated < 1) {
            throw new RuntimeException('Edit page update failed');
        }
    } else {
        // Reserve slug with INSERT first (unique PK), then write HTML atomically.
        // On collision, INSERT retries with a new suffix — never UPDATE another user's row.
        $reserved = publish_insert_new_page([
            'title' => $title,
            'type' => $type,
            'lang' => $lang,
            'created_at' => $now,
            'owner_user_id' => current_user_id(),
            'status' => 'live',
            'cost_tokens' => $cost,
            'session_id' => $sessionId,
            'is_adult' => $isAdult ? 1 : 0,
            'adult_score' => $adult['score'],
            'adult_reason' => $adult['reason'],
            'og_image_path' => $ogImagePath,
            'screenshot_path' => $screenshotPath ?: '',
        ], $generationMessages, $title, $desiredSlug);
        $pageSlug = $reserved['slug'];
        $slugResult = ['slug' => $pageSlug, 'source' => $reserved['source']];
        $path = $reserved['path'];
        // Re-stamp footer/meta if reserved slug differs from provisional (rare).
        if ($pageSlug !== $provisional['slug']) {
            $html = move_session_assets_to_slug($sessionId, $pageSlug, $html);
            if ($isAdult) {
                $html = inject_adult_gate($html, $pageSlug, normalize_locale(extract_html_lang($html)) ?: $locale);
            }
            $html = inject_generated_footer($html, $pageSlug, $pageLang);
        }
        write_site_html_atomic($path, $html);
    }
    db_exec('UPDATE sessions SET page_slug = ?, state = ?, updated_at = ? WHERE id = ?', [$pageSlug, 'done', $now, $sessionId]);
    $generationLocked = false;
    record_publish_event($sessionId, $pageSlug, 'generate', 'success', null, $usage, $isAdult);
    if ($adultFlagCleared) {
        record_publish_event($sessionId, $pageSlug, 'adult_flag', 'notice', 'adult_flag_cleared', [], false);
    }
    $quotaCharge = null;
    try {
        build_recent_html_file();
        build_sitemap_file();
    } catch (Throwable $e) {
        error_log('public index rebuild failed: ' . $e->getMessage());
    }

    $url = 'https://' . $pageSlug . '.xlog.ink/';
    $ownerId = current_user_id();
    if ($ownerId) {
        $publishGuide = '[系统事件] 页面已发布：' . $url . '。标题《' . $title . '》。页面已归入当前登录账号。普通创建会话中再次生成会创建新地址（编辑模式除外）。引导用户用右上角「我的」→「我的页面」修改此页；不要索要邮箱绑定。';
    } else {
        $publishGuide = '[系统事件] 页面已发布：' . $url . '。标题《' . $title . '》。普通创建会话中，后续再次生成会创建新的页面地址，不覆盖这个页面；如果用户想修改这个已发布页面，请引导其使用邮箱修改链接。登录后同会话或同邮箱可认领到「我的页面」。';
    }
    append_session_message($sessionId, 'system', $publishGuide);
    sse_event('stage', ['stage' => 'done']);
    sse_event('result', [
        'url' => $url,
        'slug' => $pageSlug,
        'is_adult' => $isAdult,
        'adult_reason' => $adult['reason'],
        'slug_source' => $slugResult['source'],
        'image_url' => $screenshotPath ? image_public_url($screenshotPath) : '',
        'og_image_url' => $ogImageUrl,
    ]);

    try {
        $screenshotPath = capture_page_image($pageSlug);
        if ($screenshotPath) {
            $screenshotUrl = image_public_url($screenshotPath);
            if ($ogImageUrl === '') {
                $ogImagePath = $screenshotPath;
                $ogImageUrl = $screenshotUrl;
                $html = ensure_page_meta($html, [
                    'title' => $title,
                    'description' => $description,
                    'og_title' => $title,
                    'og_description' => $description,
                    'og_image' => $ogImageUrl,
                ]);
                write_site_html_atomic($path, $html);
            }
            db_exec(
                'UPDATE pages SET og_image_path = ?, screenshot_path = ?, updated_at = ? WHERE slug = ?',
                [$ogImagePath, $screenshotPath, now_iso(), $pageSlug]
            );
            sse_event('preview_image', [
                'slug' => $pageSlug,
                'image_url' => $screenshotUrl,
                'og_image_url' => $ogImageUrl,
            ]);
        }
    } catch (Throwable $e) {
        error_log('post-publish screenshot failed: ' . $e->getMessage());
    }
    sse_event('done', ['usage' => $usage]);
} catch (Throwable $e) {
    refund_generate_charge($quotaCharge);
    restore_publish_session_state($sessionId ?? null, $previousState, $generationLocked);
    record_publish_event($sessionId ?? null, isset($session) ? ($session['page_slug'] ?: null) : null, 'generate', 'failed', $e->getMessage(), $usage);
    error_log('publish failed: ' . $e->getMessage());
    sse_event('error', ['code' => 'publish_failed', 'message' => friendly_publish_error($e, $locale ?? resolve_locale())]);
}

function refund_generate_charge(&$charge) {
    if ($charge) {
        refund_quota('generate', $charge);
        $charge = null;
    }
}

function lock_publish_session($sessionId) {
    $updated = db_exec(
        'UPDATE sessions SET state = ?, updated_at = ? WHERE id = ? AND state IN (?, ?, ?)',
        ['generating', now_iso(), $sessionId, 'chatting', 'ready', 'done']
    )->rowCount();
    return $updated === 1;
}

function restore_publish_session_state($sessionId, $state, &$locked) {
    if (!$locked || !$sessionId) return;
    $state = in_array($state, ['chatting', 'ready', 'done'], true) ? $state : 'chatting';
    db_exec('UPDATE sessions SET state = ?, updated_at = ? WHERE id = ? AND state = ?', [$state, now_iso(), $sessionId, 'generating']);
    $locked = false;
}

function api_turnstile_ok($token) {
    if (!xlog_config('turnstile.enabled', false)) return true;
    return turnstile_verify($token, client_ip())['ok'];
}

function generation_context_messages(array $messages) {
    $budget = 24000;
    $total = 0;
    $kept = [];
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $message = $messages[$i];
        $role = (string)($message['role'] ?? '');
        if (!in_array($role, ['user', 'assistant'], true)) continue;
        $content = trim((string)($message['content'] ?? ''));
        if ($content === '') continue;
        $isImageMarker = preg_match('/^\[图片已(上传|生成):/u', $content);
        if (!$isImageMarker && generation_context_skip_message($content)) continue;

        $limit = $role === 'user' ? 3000 : 1600;
        if ($isImageMarker) $limit = 1400;
        $content = generation_context_clamp_text($content, $limit);
        $len = mb_strlen($content, 'UTF-8');
        if (!$isImageMarker && $total + $len > $budget) continue;

        $kept[] = ['role' => $role, 'content' => $content];
        $total += $len;
        if ($total >= $budget) break;
    }
    $kept = array_reverse($kept);
    return $kept ?: array_slice(array_map(function ($message) {
        return [
            'role' => in_array(($message['role'] ?? ''), ['user', 'assistant'], true) ? $message['role'] : 'user',
            'content' => generation_context_clamp_text((string)($message['content'] ?? ''), 1200),
        ];
    }, $messages), -12);
}

function generation_context_skip_message($content) {
    if (preg_match('/^\[(图片已上传|圖片已上傳|图片已生成|圖片已生成)/u', $content)) {
        return false;
    }
    if (preg_match('/^\[(系统事件|系統事件|当前页面信息|目前頁面資訊|Current page info)/u', $content)) {
        return true;
    }
    if (preg_match('/^(页面已上线|頁面已上線|最终页面已嵌入|最終頁面已嵌入|页面写入完成|頁面寫入完成)/u', $content)) {
        return true;
    }
    if (strpos($content, 'Page Forge Stream') !== false) return true;
    if (preg_match('/https:\/\/[a-z0-9-]+\.xlog\.ink\//i', $content) && mb_strlen($content, 'UTF-8') < 500) {
        return true;
    }
    return false;
}

function generation_context_clamp_text($text, $max) {
    $text = trim((string)$text);
    if (mb_strlen($text, 'UTF-8') <= $max) return $text;
    $headLen = max(1, (int)floor($max * 0.65));
    $tailLen = max(1, (int)floor($max * 0.25));
    $head = mb_substr($text, 0, $headLen, 'UTF-8');
    $tail = mb_substr($text, -$tailLen, null, 'UTF-8');
    return $head . "\n...[中间内容已压缩]...\n" . $tail;
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
    require_once __DIR__ . '/../includes/html_sanitize.php';
    xlog_assert_safe_generated_html($html);
}

function inject_generated_footer($html, $slug = '', $lang = 'zh-CN') {
    $baseUrl = rtrim((string)xlog_config('base_url', 'https://xlog.ink'), '/');
    // Strip legacy floating badge so re-publishes upgrade UX (no batch rewrite of old pages).
    $html = preg_replace(
        '/<div\b[^>]*style=["\'][^"\']*position\s*:\s*fixed[^"\']*["\'][^>]*>\s*<a\b[^>]*href=["\']https?:\/\/(?:www\.)?xlog\.ink\/?["\'][^>]*>\s*Made with xlog\.ink\s*<\/a>\s*<\/div>/is',
        '',
        $html,
        1
    );

    $pixel = '';
    if (preg_match('/^[a-z0-9]{3,20}$/', (string)$slug)) {
        $pixelUrl = $baseUrl . '/api/visit.php?slug=' . rawurlencode($slug);
        $pixel = '<img src="' . h($pixelUrl) . '" alt="" width="1" height="1" loading="eager" referrerpolicy="origin-when-cross-origin" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden">';
    }

    $hasCredit = (bool)preg_match('/\bdata-xlog-credit\s*=\s*["\']?1["\']?/i', $html);
    if (!$hasCredit) {
        $line = build_xlog_credit_line_html($lang, $baseUrl);
        if (preg_match('/<\/footer>/i', $html)) {
            // Merge into existing footer as a quiet last line (not a nested <footer>).
            $inner = '<p data-xlog-credit="1" style="margin:16px 0 0;padding:0;text-align:center;font:12px/1.6 system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:rgba(0,0,0,.45)">' . $line . '</p>';
            $html = preg_replace('/<\/footer>/i', $inner . "\n</footer>", $html, 1);
        } else {
            $creditFooter = '<footer data-xlog-credit="1" style="margin:0;padding:28px 16px 32px;text-align:center;font:12px/1.6 system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:rgba(0,0,0,.45)"><p style="margin:0">' . $line . '</p></footer>';
            $html = preg_replace('/<\/body>/i', $creditFooter . "\n</body>", $html, 1);
        }
    }

    if ($pixel !== '') {
        $html = preg_replace('/<\/body>/i', $pixel . "\n</body>", $html, 1);
    }
    return $html;
}

function build_xlog_credit_line_html($lang = 'zh-CN', $baseUrl = 'https://xlog.ink') {
    $lang = validate_lang(normalize_locale($lang) ?: 'zh-CN');
    $href = h(rtrim((string)$baseUrl, '/') ?: 'https://xlog.ink');
    $label = 'xlog.ink';
    if ($lang === 'en') {
        return 'Page created with <a href="' . $href . '" style="color:inherit;text-decoration:underline;text-underline-offset:2px">' . h($label) . '</a>';
    }
    if ($lang === 'zh-TW') {
        return '本頁面由 <a href="' . $href . '" style="color:inherit;text-decoration:underline;text-underline-offset:2px">' . h($label) . '</a> 生成';
    }
    return '本页面由 <a href="' . $href . '" style="color:inherit;text-decoration:underline;text-underline-offset:2px">' . h($label) . '</a> 生成';
}

function inject_generated_csp($html) {
    require_once __DIR__ . '/../includes/html_sanitize.php';
    // Always enforce our generated-page CSP (replace any model-supplied CSP meta).
    $html = preg_replace('/<meta\b[^>]*http-equiv=["\']Content-Security-Policy["\'][^>]*>\s*/i', '', $html);
    $policy = xlog_generated_page_csp();
    $meta = '<meta http-equiv="Content-Security-Policy" content="' . h($policy) . '">';
    return preg_replace('/<\/head>/i', $meta . "\n</head>", $html, 1);
}

function inject_adult_gate($html, $slug, $locale = 'zh-CN') {
    $adult = build_adult_gate_parts(validate_lang($locale), $slug, true);
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
    // No runtime JS (CSP script-src 'none').
    return $html;
}

function adult_gate_inline_css() {
    // Pure CSS unlock via :has(.adult-gate-check:checked) — no JavaScript.
    return <<<HTML
<style>
.adult-gate--locked > *:not(.adult-gate):not(style){filter:blur(12px);pointer-events:none;user-select:none}
.adult-gate{display:none;position:fixed;inset:0;z-index:2147483647;place-items:center;background:rgba(8,8,10,.76);padding:24px;color:#fff}
.adult-gate--locked .adult-gate{display:grid}
body:has(.adult-gate-check:checked) > *:not(.adult-gate):not(style){filter:none!important;pointer-events:auto;user-select:auto}
body:has(.adult-gate-check:checked) .adult-gate{display:none!important}
.adult-gate-check{position:absolute;opacity:0;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.adult-gate-card{width:min(460px,100%);background:#141414;border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:28px;box-shadow:0 30px 90px rgba(0,0,0,.45);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.adult-gate-badge{display:inline-flex;margin-bottom:12px;padding:6px 10px;border-radius:999px;background:#fff;color:#111;font-weight:800;font-size:13px}
.adult-gate-card h1{margin:0 0 10px;font-size:26px;line-height:1.15}
.adult-gate-card p{margin:0;color:rgba(255,255,255,.76);line-height:1.6}
.adult-gate-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;align-items:center}
.adult-gate-actions .button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 14px;border-radius:12px;border:1px solid rgba(255,255,255,.24);background:#fff;color:#111;text-decoration:none;font-weight:800;cursor:pointer}
.adult-gate-actions .button--ghost{background:transparent;color:#fff}
.adult-gate-actions label.button{cursor:pointer}
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

function friendly_publish_error(Throwable $e, $locale = 'zh-CN') {
    $message = $e->getMessage();
    if (stripos($message, 'External scripts') !== false) return t('api', 'publishExternalScript', $locale);
    if (stripos($message, 'External stylesheets') !== false) return t('api', 'publishExternalStyle', $locale);
    if (stripos($message, 'Only xlog.ink site-assets') !== false) return t('api', 'publishExternalImage', $locale);
    if (stripos($message, 'iframes') !== false || stripos($message, 'forms') !== false) return t('api', 'publishIframeForm', $locale);
    if (stripos($message, 'complete HTML') !== false || stripos($message, 'DOCTYPE') !== false) return t('api', 'publishIncomplete', $locale);
    if (stripos($message, 'Write failed') !== false) return t('api', 'publishWriteFailed', $locale);
    return t('api', 'publishFailed', $locale);
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
