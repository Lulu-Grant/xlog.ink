<?php
// Content safety, slug, SEO/OG, and page capture helpers.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

function adult_keyword_score($text) {
    $text = mb_strtolower((string)$text, 'UTF-8');
    $text = preg_replace('/(不是|並非|并非|非|不含|沒有|没有|no|not|without)\s*(成人|情色|色情|裸露|裸体|裸體|porn|xxx|nsfw|nude|naked|sex|erotic)/iu', ' ', $text);
    $critical = [
        'porn', 'xxx', 'nsfw', 'nude', 'naked', 'hardcore', 'escort',
        '情色', '裸露', '裸體', '裸体', '性愛', '性爱', '約炮', '约炮', '援交', '18禁',
    ];
    $strong = ['adult content', 'erotic', '成人内容', '成人內容', '色情'];
    $soft = ['性感', '私密', '情趣', '泳装', '泳裝', '內衣', '内衣', 'lingerie', 'bikini'];
    $score = 0.0;
    $hits = [];
    foreach ($critical as $kw) {
        if (mb_stripos($text, $kw, 0, 'UTF-8') !== false) {
            $score += 0.9;
            $hits[] = $kw;
        }
    }
    foreach ($strong as $kw) {
        if (mb_stripos($text, $kw, 0, 'UTF-8') !== false) {
            $score += 0.45;
            $hits[] = $kw;
        }
    }
    foreach ($soft as $kw) {
        if (mb_stripos($text, $kw, 0, 'UTF-8') !== false) {
            $score += 0.05;
            $hits[] = $kw;
        }
    }
    return [
        'score' => min(1.0, $score),
        'reason' => $hits ? ('matched:' . implode(',', array_slice(array_unique($hits), 0, 6))) : 'clean',
    ];
}

function assess_session_adult($sessionId, array $messages) {
    $text = '';
    foreach ($messages as $message) {
        $text .= "\n" . (string)($message['content'] ?? '');
    }
    $textResult = adult_keyword_score($text);
    $imageRows = db_all('SELECT adult_score, adult_reason FROM images WHERE session_id = ?', [$sessionId]);
    $imageScore = 0.0;
    $imageAdult = false;
    $imageReasons = [];
    foreach ($imageRows as $row) {
        $score = (float)($row['adult_score'] ?? 0);
        if ($score > $imageScore) $imageScore = $score;
        if ($score >= 0.55) $imageAdult = true;
        if ($score >= 0.55 && !empty($row['adult_reason'])) $imageReasons[] = $row['adult_reason'];
    }
    $score = max((float)$textResult['score'], $imageScore);
    $reasons = [];
    $textAdult = (float)$textResult['score'] >= 0.85;
    if ($textAdult) $reasons[] = 'text:' . $textResult['reason'];
    foreach (array_slice($imageReasons, 0, 3) as $reason) $reasons[] = 'image:' . $reason;
    return [
        'is_adult' => $textAdult || $imageAdult,
        'text_adult' => $textAdult,
        'image_adult' => $imageAdult,
        'score' => $score,
        'reason' => $reasons ? implode('; ', $reasons) : 'clean',
    ];
}

function assess_uploaded_image_adult(array $file, $caption = '', $processedPath = null, $processedMime = 'image/webp') {
    $name = (string)($file['name'] ?? '');
    $result = adult_keyword_score($name . "\n" . $caption);
    $score = (float)$result['score'];
    $reason = $score >= 0.55 ? $result['reason'] : 'visual_not_configured';
    if ($processedPath && ai_has_key('moderation')) {
        try {
            $visual = ai_moderate_image($processedPath, $processedMime, $name . "\n" . $caption);
            if (is_array($visual)) {
                $visualScore = (float)($visual['score'] ?? 0);
                if ($visualScore >= $score) {
                    $score = $visualScore;
                    $reason = 'visual:' . ($visual['reason'] ?? 'moderation');
                } elseif ($score >= 0.55) {
                    $reason = 'text:' . $result['reason'] . '; visual:' . ($visual['reason'] ?? 'moderation');
                } else {
                    $reason = 'visual:' . ($visual['reason'] ?? 'moderation');
                }
            }
        } catch (Throwable $e) {
            error_log('image moderation failed: ' . $e->getMessage());
            $score = max($score, 0.55);
            $reason = 'visual_error:' . mb_substr($e->getMessage(), 0, 120, 'UTF-8');
        }
    }
    return [
        'score' => max(0.0, min(1.0, $score)),
        'reason' => $reason,
    ];
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
    return isset($words[$slug]);
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
        "SELECT path FROM images WHERE session_id = ? AND slug IS NOT NULL ORDER BY CASE slot WHEN 'hero' THEN 0 WHEN 'product' THEN 1 WHEN 'avatar' THEN 2 ELSE 3 END, id ASC LIMIT 1",
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
