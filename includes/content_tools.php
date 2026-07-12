<?php
// Content safety, slug, SEO/OG, and page capture helpers.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

function assess_session_adult($sessionId, array $messages) {
    $text = '';
    foreach ($messages as $message) {
        $text .= "\n" . (string)($message['content'] ?? '');
    }
    $textResult = assess_adult_text_with_ai($text);
    $results = [$textResult];
    $imageRows = db_all(
        'SELECT id, path, caption, adult_score, adult_reason, moderation_status, moderation_blocked, moderation_categories, sexual_minors_score FROM images WHERE session_id = ?',
        [$sessionId]
    );
    foreach ($imageRows as $row) {
        $status = (string)($row['moderation_status'] ?? '');
        if ($status === 'ok') {
            $categories = json_decode((string)($row['moderation_categories'] ?? '[]'), true);
            $results[] = normalize_moderation_result([
                'status' => 'ok',
                'score' => (float)($row['adult_score'] ?? 0),
                'sexual_minors_score' => (float)($row['sexual_minors_score'] ?? 0),
                'must_block' => !empty($row['moderation_blocked']),
                'categories' => is_array($categories) ? $categories : [],
                'reason' => (string)($row['adult_reason'] ?? ''),
            ], 'image', 0.55);
            continue;
        }

        $path = moderation_asset_file_path((string)($row['path'] ?? ''));
        $result = assess_adult_image_with_ai($path, 'image/webp', (string)($row['caption'] ?? ''));
        persist_image_moderation_result((int)$row['id'], $result);
        $results[] = normalize_moderation_result($result, 'image', 0.55);
    }
    return merge_moderation_results($results);
}

function moderation_asset_file_path($publicPath) {
    $path = parse_url((string)$publicPath, PHP_URL_PATH);
    if (!is_string($path) || strpos($path, '/site-assets/') !== 0 || strpos($path, '..') !== false) {
        return null;
    }
    return rtrim((string)xlog_config('asset_dir'), '/') . '/' . substr($path, strlen('/site-assets/'));
}

function normalize_moderation_result(array $result, $source, $adultThreshold) {
    $status = (string)($result['status'] ?? 'ok');
    if (!in_array($status, ['ok', 'unavailable', 'error'], true)) $status = 'error';
    $score = max(0.0, min(1.0, (float)($result['adult_score'] ?? ($result['score'] ?? 0))));
    $minorScore = max(0.0, min(1.0, (float)($result['sexual_minors_score'] ?? 0)));
    $categories = $result['categories'] ?? [];
    if (!is_array($categories)) $categories = [];
    return [
        'status' => $status,
        'source' => (string)$source,
        'score' => $score,
        'adult_score' => $score,
        'sexual_minors_score' => $minorScore,
        'is_adult' => $status === 'ok' && $score >= (float)$adultThreshold,
        'must_block' => $status === 'ok' && (!empty($result['must_block']) || $minorScore >= 0.1),
        'categories' => array_values(array_slice($categories, 0, 12)),
        'reason' => mb_substr(trim((string)($result['reason'] ?? 'moderation')), 0, 300, 'UTF-8'),
    ];
}

function merge_moderation_results(array $results) {
    $merged = [
        'status' => 'ok',
        'score' => 0.0,
        'adult_score' => 0.0,
        'sexual_minors_score' => 0.0,
        'is_adult' => false,
        'must_block' => false,
        'categories' => [],
        'reason' => 'clean',
    ];
    $reasons = [];
    foreach ($results as $result) {
        if (!is_array($result)) continue;
        $status = (string)($result['status'] ?? 'error');
        if ($status !== 'ok' && $merged['status'] === 'ok') $merged['status'] = $status;
        $merged['score'] = max($merged['score'], (float)($result['score'] ?? 0));
        $merged['adult_score'] = max($merged['adult_score'], (float)($result['adult_score'] ?? ($result['score'] ?? 0)));
        $merged['sexual_minors_score'] = max($merged['sexual_minors_score'], (float)($result['sexual_minors_score'] ?? 0));
        $merged['is_adult'] = $merged['is_adult'] || !empty($result['is_adult']);
        $merged['must_block'] = $merged['must_block'] || !empty($result['must_block']);
        $merged['categories'] = array_values(array_unique(array_merge($merged['categories'], $result['categories'] ?? [])));
        $reason = trim((string)($result['reason'] ?? ''));
        if ($reason !== '' && $reason !== 'clean') $reasons[] = (($result['source'] ?? 'content') . ':' . $reason);
    }
    if ($reasons) $merged['reason'] = implode('; ', array_slice($reasons, 0, 6));
    return $merged;
}

function persist_image_moderation_result($imageId, array $result) {
    $normalized = normalize_moderation_result($result, 'image', 0.55);
    db_exec(
        'UPDATE images SET adult_score = ?, adult_reason = ?, moderation_status = ?, moderation_blocked = ?, moderation_categories = ?, sexual_minors_score = ? WHERE id = ?',
        [
            $normalized['adult_score'],
            $normalized['reason'],
            $normalized['status'],
            $normalized['must_block'] ? 1 : 0,
            json_encode($normalized['categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $normalized['sexual_minors_score'],
            (int)$imageId,
        ]
    );
}

function moderation_result_allows_publication(array $result) {
    return ($result['status'] ?? 'error') === 'ok' && empty($result['must_block']);
}

function generated_page_description($html, $title = '') {
    $html = (string)$html;
    $description = '';
    if (preg_match('/<meta\b(?=[^>]*\bname=["\']description["\'])(?=[^>]*\bcontent=["\']([^"\']*)["\'])[^>]*>/i', $html, $match)) {
        $description = html_entity_decode((string)$match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (trim($description) === '') {
        $visible = preg_replace('/<(script|style|template)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $visible = html_entity_decode(strip_tags((string)$visible), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = trim((string)$title . ' ' . $visible);
    }
    $description = excerpt_plain_text($description, 160);
    return $description !== '' ? $description : excerpt_plain_text((string)$title, 160);
}

function assess_uploaded_image_adult(array $file, $caption = '', $processedPath = null, $processedMime = 'image/webp') {
    $name = (string)($file['name'] ?? '');
    return assess_adult_image_with_ai($processedPath, $processedMime, $name . "\n" . $caption);
}

function assess_adult_text_with_ai($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return normalize_moderation_result([
            'status' => 'ok',
            'score' => 0.0,
            'reason' => 'ai_moderation:empty_text',
        ], 'text', 0.85);
    }
    if (!ai_has_key('moderation')) {
        if (xlog_config('ai.moderation.mock', false)) {
            return normalize_moderation_result([
                'status' => 'ok',
                'score' => 0.0,
                'reason' => 'ai_moderation:explicit_mock',
            ], 'text', 0.85);
        }
        return normalize_moderation_result([
            'status' => 'unavailable',
            'reason' => 'ai_moderation:not_configured',
        ], 'text', 0.85);
    }
    try {
        $result = ai_moderate_text($text);
        if (is_array($result)) {
            return normalize_moderation_result($result, 'text', 0.85);
        }
    } catch (Throwable $e) {
        error_log('text moderation failed: ' . $e->getMessage());
        return normalize_moderation_result([
            'status' => 'error',
            'reason' => 'ai_moderation_error:' . mb_substr($e->getMessage(), 0, 120, 'UTF-8'),
        ], 'text', 0.85);
    }
    return normalize_moderation_result([
        'status' => 'error',
        'reason' => 'ai_moderation:no_result',
    ], 'text', 0.85);
}

function assess_adult_image_with_ai($path, $mime, $context = '') {
    if (!$path || !ai_has_key('moderation')) {
        if ($path && xlog_config('ai.moderation.mock', false)) {
            return normalize_moderation_result([
                'status' => 'ok',
                'score' => 0.0,
                'reason' => 'ai_moderation:explicit_mock',
            ], 'image', 0.55);
        }
        return normalize_moderation_result([
            'status' => 'unavailable',
            'reason' => $path ? 'ai_moderation:not_configured' : 'ai_moderation:image_missing',
        ], 'image', 0.55);
    }
    try {
        $visual = ai_moderate_image($path, $mime, $context);
        if (is_array($visual)) {
            return normalize_moderation_result($visual, 'image', 0.55);
        }
    } catch (Throwable $e) {
        error_log('image moderation failed: ' . $e->getMessage());
        return normalize_moderation_result([
            'status' => 'error',
            'reason' => 'ai_moderation_error:' . mb_substr($e->getMessage(), 0, 120, 'UTF-8'),
        ], 'image', 0.55);
    }
    return normalize_moderation_result([
        'status' => 'error',
        'reason' => 'ai_moderation:no_result',
    ], 'image', 0.55);
}

function slug_clean($value) {
    $value = strtolower((string)$value);
    $value = preg_replace('/[^a-z0-9]/', '', $value);
    return substr($value, 0, 10);
}

function slug_reserved_words() {
    static $words = null;
    if ($words !== null) return $words;
    $raw = [
        'www','web','app','m','wap','mobile','home','index','portal',
        'login','signin','signup','register','auth','oauth','sso','passport','id','account','accounts','profile','user','users','member','members','center','uc',
        'admin','administrator','manage','manager','dashboard','console','panel','cpanel','backend','system','ops',
        'api','apiv1','apiv2','apiv3','open','gateway','gw','rpc','graphql','rest','service','services','sdk',
        'mail','webmail','smtp','imap','pop','mx','relay',
        'files','file','upload','uploads','download','downloads','attachment','attachments','share','storage',
        'img','image','images','pic','pics','photo','photos','media','video','live','stream',
        'cdn','static','assets','resource','resources','cache','edge','accelerate',
        'docs','doc','developer','developers','dev','apidocs','reference','guide','manual','wiki','kb','help','support','ticket','faq','feedback','contact',
        'status','stats','statistics','analytics','report','reports','monitor','monitoring','metrics','logs','audit',
        'db','mysql','pgsql','mongo','redis','memcache','es','elastic','mq','queue','rabbitmq','kafka','event',
        'cloud','object','bucket','oss','cos','s3','backup','archive',
        'oa','crm','erp','hr','finance','office','work','meeting',
        'test','beta','alpha','uat','staging','preview','demo','sandbox','lab',
        'git','gitlab','gitea','repo','svn','ci','cd','build','deploy','docker','k8s','jenkins',
        'security','safe','vpn','waf','firewall','scan','verify','trust',
        'bbs','forum','community','club','group','social','blog','news','press',
        'shop','store','mall','cart','order','orders','payment','pay','billing','invoice',
        'search','find','query','engine',
        'ai','gpt','chat','bot','agent','assistant','prompt','model','llm','inference','proxy',
        'data','edit','vip',
    ];
    $words = [];
    foreach ($raw as $word) {
        $clean = slug_clean($word);
        if ($clean !== '') $words[$clean] = true;
    }
    return $words;
}

function slug_is_reserved($slug) {
    $slug = slug_clean($slug);
    if ($slug === '') return false;
    $words = slug_reserved_words();
    if (isset($words[$slug])) return true;
    foreach (slug_reserved_prefix_words() as $prefix) {
        if (strpos($slug, $prefix) === 0) return true;
    }
    return false;
}

function slug_reserved_prefix_words() {
    return [
        'www',
        'admin',
        'api',
        'login',
        'signin',
        'signup',
        'auth',
        'mail',
        'smtp',
        'imap',
        'pop',
        'pay',
        'bank',
        'account',
        'support',
        'status',
    ];
}

function slug_is_available_for_page($slug) {
    $slug = slug_clean($slug);
    return $slug !== '' && !slug_is_reserved($slug) && !slug_exists($slug);
}

function slug_base_from_text($text, $fallback = 'page') {
    $text = strtolower((string)$text);
    $map = [
        'coffee' => ['咖啡', 'coffee', 'cafe'],
        'beer' => ['啤酒', 'beer'],
        'food' => ['餐厅', '餐飲', '餐饮', '美食', 'food'],
        'music' => ['音乐', '音樂', 'music'],
        'event' => ['活动', '活動', 'event'],
        'shop' => ['商店', '商城', '店铺', '店鋪', 'shop'],
        'card' => ['名片', '导航', '導航', 'card', 'profile'],
        'art' => ['艺术', '藝術', '设计', '設計', 'art', 'design'],
    ];
    foreach ($map as $base => $needles) {
        foreach ($needles as $needle) {
            if (mb_stripos($text, $needle, 0, 'UTF-8') !== false) {
                return slug_is_reserved($base) ? 'page' : $base;
            }
        }
    }
    if (preg_match_all('/[a-z][a-z0-9]{2,}/', $text, $m) && !empty($m[0])) {
        $stop = ['page', 'html', 'with', 'this', 'that', 'make', 'create'];
        foreach ($m[0] as $word) {
            $base = substr($word, 0, 7);
            if (!in_array($word, $stop, true) && !slug_is_reserved($base)) return $base;
        }
    }
    $fallback = slug_clean($fallback);
    return ($fallback !== '' && !slug_is_reserved($fallback)) ? $fallback : 'page';
}

function random_letters($length = 3) {
    $letters = 'abcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $letters[random_int(0, strlen($letters) - 1)];
    return $out;
}

function generate_semantic_slug(array $messages, $title = '', $desired = '') {
    $desired = slug_clean($desired);
    if ($desired !== '' && !slug_is_reserved($desired)) {
        if (!slug_exists($desired)) return ['slug' => $desired, 'source' => 'custom'];
        $base = substr($desired, 0, 7);
        for ($i = 0; $i < 30; $i++) {
            $slug = substr($base, 0, 7) . random_letters(3);
            if (slug_is_available_for_page($slug)) return ['slug' => $slug, 'source' => 'custom_suffix'];
        }
    }
    $text = $title;
    foreach ($messages as $message) $text .= "\n" . (string)($message['content'] ?? '');
    $base = substr(slug_base_from_text($text), 0, 7);
    if (strlen($base) < 3) $base = 'page';
    for ($i = 0; $i < 30; $i++) {
        $slug = substr($base, 0, 7) . random_letters(3);
        if (slug_is_available_for_page($slug)) return ['slug' => $slug, 'source' => 'auto'];
    }
    for ($i = 0; $i < 30; $i++) {
        $slug = random_name(10);
        if (slug_is_available_for_page($slug)) return ['slug' => $slug, 'source' => 'random_fallback'];
    }
    throw new RuntimeException('Could not generate safe slug');
}

function first_session_image_path($sessionId) {
    $row = db_one(
        "SELECT path FROM images WHERE session_id = ? AND slug IS NOT NULL ORDER BY CASE slot WHEN 'hero' THEN 0 WHEN 'product' THEN 1 WHEN 'avatar' THEN 2 ELSE 3 END, id DESC LIMIT 1",
        [$sessionId]
    );
    return $row['path'] ?? '';
}

function ensure_page_meta($html, array $meta) {
    $title = h($meta['title'] ?? 'xlog page');
    $description = h($meta['description'] ?? 'A page created with xlog.ink');
    $ogTitle = h($meta['og_title'] ?? $title);
    $ogDescription = h($meta['og_description'] ?? $description);
    $ogImage = trim((string)($meta['og_image'] ?? ''));

    if (!preg_match('/<title>.*?<\/title>/is', $html)) {
        $html = preg_replace('/<head([^>]*)>/i', "<head$1>\n<title>{$title}</title>", $html, 1);
    }
    $tags = [
        'description' => '<meta name="description" content="' . $description . '">',
        'og:title' => '<meta property="og:title" content="' . $ogTitle . '">',
        'og:description' => '<meta property="og:description" content="' . $ogDescription . '">',
    ];
    if ($ogImage !== '') {
        $tags['og:image'] = '<meta property="og:image" content="' . h($ogImage) . '">';
    }
    foreach ($tags as $key => $tag) {
        if ($key === 'description') {
            if (preg_match('/<meta\b[^>]*name=["\']description["\'][^>]*>/i', $html)) {
                $html = preg_replace('/<meta\b[^>]*name=["\']description["\'][^>]*>/i', $tag, $html, 1);
            } else {
                $html = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);
            }
        } else {
            $prop = preg_quote($key, '/');
            if (preg_match('/<meta\b[^>]*property=["\']' . $prop . '["\'][^>]*>/i', $html)) {
                $html = preg_replace('/<meta\b[^>]*property=["\']' . $prop . '["\'][^>]*>/i', $tag, $html, 1);
            } else {
                $html = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);
            }
        }
    }
    return $html;
}

function capture_page_image($slug) {
    if (!xlog_config('screenshot.enabled', true)) return null;
    if (!function_exists('exec')) {
        error_log('capture_page_image skipped: exec() is disabled');
        return null;
    }
    $script = XLOG_ROOT . '/scripts/capture-page.js';
    $html = xlog_config('site_dir') . '/' . $slug . '.html';
    $outRel = '/site-assets/' . $slug . '/page-shot.png';
    $out = xlog_config('asset_dir') . '/' . $slug . '/page-shot.png';
    if (!is_file($script) || !is_file($html)) return null;
    if (!is_dir(dirname($out))) @mkdir(dirname($out), 0755, true);
    $node = xlog_config('screenshot.node', 'node');
    $cmd = escapeshellcmd($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('file://' . $html) . ' ' . escapeshellarg($out) . ' 2>&1';
    @exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($out)) {
        error_log('capture_page_image failed: ' . implode("\n", $output));
        return null;
    }
    return $outRel;
}
