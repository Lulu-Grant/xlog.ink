<?php
// Shared helpers for safe page edit sessions.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function page_public_url($slug) {
    return 'https://' . $slug . '.xlog.ink/';
}

function clean_generated_html_for_edit($html) {
    $html = (string)$html;
    $html = preg_replace('/<div style="position:fixed;right:12px;bottom:12px;.*?Made with xlog\.ink.*?<\/div>\s*/is', '', $html);
    $html = preg_replace('/<style>\s*\.adult-gate--locked.*?<\/style>\s*/is', '', $html);
    $html = preg_replace('/<script>\s*\(function\(\)\{.*?adult-gate.*?<\/script>\s*/is', '', $html);
    $html = preg_replace('/<div class="adult-gate".*?<\/div>\s*<\/div>\s*/is', '', $html);
    $html = preg_replace('/\sclass="([^"]*)adult-gate--enabled adult-gate--locked([^"]*)"/i', ' class="$1$2"', $html);
    $html = preg_replace('/\sdata-adult-key="[^"]*"/i', '', $html);
    return trim($html);
}

function clamp_generated_html_for_edit_context($html, $maxLength = 24000) {
    $html = trim((string)$html);
    $maxLength = max(4000, (int)$maxLength);
    if (mb_strlen($html, 'UTF-8') <= $maxLength) return $html;

    $head = '';
    if (preg_match('/<head\b[^>]*>.*?<\/head>/is', $html, $match)) {
        $head = mb_substr($match[0], 0, min(6000, $maxLength), 'UTF-8');
    }
    $body = $html;
    if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $match)) {
        $body = $match[1];
    }

    $marker = "\n<!-- xlog: middle of current page omitted for context size -->\n";
    $remaining = max(1000, $maxLength - mb_strlen($head, 'UTF-8') - mb_strlen($marker, 'UTF-8'));
    $frontLength = (int)floor($remaining * 0.68);
    $tailLength = $remaining - $frontLength;
    return trim(
        $head
        . "\n<body>\n"
        . mb_substr($body, 0, $frontLength, 'UTF-8')
        . $marker
        . mb_substr($body, -$tailLength, null, 'UTF-8')
        . "\n</body>"
    );
}

function build_edit_page_generation_context(array $page) {
    $path = (string)($page['html_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Current page HTML is unavailable');
    }
    $html = file_get_contents($path);
    if ($html === false || trim($html) === '') {
        throw new RuntimeException('Current page HTML is unreadable');
    }
    return [
        'url' => page_public_url((string)$page['slug']),
        'slug' => (string)$page['slug'],
        'title' => (string)($page['title'] ?? ''),
        'type' => (string)($page['type'] ?? 'page'),
        'lang' => normalize_locale($page['lang'] ?? '') ?: 'zh-CN',
        'current_html' => clamp_generated_html_for_edit_context(clean_generated_html_for_edit($html)),
    ];
}

function page_edit_seed_messages(array $page) {
    $locale = normalize_locale($page['lang'] ?? '') ?: resolve_locale();
    $html = '';
    if (!empty($page['html_path']) && is_file($page['html_path'])) {
        $html = file_get_contents($page['html_path']);
    }
    $cleanHtml = clean_generated_html_for_edit($html);
    if (mb_strlen($cleanHtml, 'UTF-8') > 18000) {
        $truncated = $locale === 'en'
            ? "\n<!-- Current HTML was truncated; the beginning structure and main content were kept. -->"
            : ($locale === 'zh-TW'
                ? "\n<!-- 目前 HTML 已截斷，保留了開頭結構與主要內容 -->"
                : "\n<!-- 当前 HTML 已截断，保留了开头结构与主要内容 -->");
        $cleanHtml = mb_substr($cleanHtml, 0, 18000, 'UTF-8') . $truncated;
    }
    $title = $page['title'] ?: $page['slug'];
    if ($locale === 'en') {
        $intro = 'This is your "' . $title . '" page. Tell me what you want to change, and I will regenerate it based on the current page while keeping the same URL.';
        $info = "[Current page info]\nURL: " . page_public_url($page['slug']) . "\nTitle: " . $title . "\nType: " . ($page['type'] ?: 'page') . "\n\n[Current page HTML]\n" . $cleanHtml;
    } elseif ($locale === 'zh-TW') {
        $intro = '這是你的「' . $title . '」頁面。告訴我你想修改哪裡，我會基於目前頁面重新生成並覆蓋原地址。';
        $info = "[目前頁面資訊]\nURL: " . page_public_url($page['slug']) . "\n標題: " . $title . "\n類型: " . ($page['type'] ?: 'page') . "\n\n[目前頁面 HTML]\n" . $cleanHtml;
    } else {
        $intro = '这是你的「' . $title . '」页面。告诉我你想修改哪里，我会基于当前页面重新生成并覆盖原地址。';
        $info = "[当前页面信息]\nURL: " . page_public_url($page['slug']) . "\n标题: " . $title . "\n类型: " . ($page['type'] ?: 'page') . "\n\n[当前页面 HTML]\n" . $cleanHtml;
    }
    return [
        [
            'role' => 'assistant',
            'content' => $intro,
            'ts' => now_iso(),
        ],
        [
            'role' => 'user',
            'content' => $info,
            'ts' => now_iso(),
        ],
    ];
}

function create_page_edit_session(array $page, $mode) {
    if (!in_array($mode, ['edit_owner', 'edit_token'], true)) {
        throw new InvalidArgumentException('Invalid edit mode');
    }
    return create_session($page['slug'], page_edit_seed_messages($page), $mode);
}

function current_user_can_edit_page(array $page) {
    $userId = current_user_id();
    return $userId && !empty($page['owner_user_id']) && (int)$page['owner_user_id'] === (int)$userId;
}

/**
 * Bind a chat session to a logged-in user when unbound or already theirs (G8).
 * Requires session_access_allowed (same browser client_id / owner rules as other session APIs).
 */
function bind_session_to_user($sessionId, $userId) {
    $sessionId = trim((string)$sessionId);
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        return ['ok' => false, 'error' => 'bad_request'];
    }
    $row = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
    if (!$row) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    // AUDIT-7 P1-1: knowing the session id alone is not enough.
    if (!session_access_allowed($row)) {
        return ['ok' => false, 'error' => 'forbidden_session'];
    }
    $existing = $row['user_id'] ?? null;
    if ($existing !== null && $existing !== '' && (int)$existing !== $userId) {
        return ['ok' => false, 'error' => 'owned_by_other', 'already' => true];
    }
    if ($existing !== null && $existing !== '' && (int)$existing === $userId) {
        return ['ok' => true, 'already' => true, 'session_id' => $sessionId];
    }
    db_exec(
        'UPDATE sessions SET user_id = ?, updated_at = ? WHERE id = ? AND (user_id IS NULL OR user_id = ?)',
        [$userId, now_iso(), $sessionId, $userId]
    );
    return ['ok' => true, 'already' => false, 'session_id' => $sessionId];
}

/**
 * Claim a page for a user when owner is null and an authorization rule passes.
 *
 * opts:
 *  - session_id: claim if sessions.page_slug matches (same-session guest publish)
 *  - email_match: claim if pages.email equals user email (case-insensitive)
 *  - allow_already_owned_self: treat self-owned as success
 *
 * Never overwrites a non-null owner belonging to someone else (G2/G10).
 */
function claim_page_for_user($slug, $userId, array $opts = []) {
    $slug = trim((string)$slug);
    $userId = (int)$userId;
    if ($slug === '' || $userId <= 0 || !preg_match('/^[a-z0-9]{3,10}$/', $slug)) {
        return ['ok' => false, 'error' => 'bad_request'];
    }

    $page = db_one('SELECT slug, owner_user_id, email, session_id FROM pages WHERE slug = ?', [$slug]);
    if (!$page) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $owner = $page['owner_user_id'];
    if ($owner !== null && $owner !== '' && (int)$owner > 0) {
        if ((int)$owner === $userId) {
            return ['ok' => true, 'already' => true, 'slug' => $slug];
        }
        return ['ok' => false, 'error' => 'already_owned', 'owner_user_id' => (int)$owner];
    }

    $allowed = false;
    $via = null;

    if (!empty($opts['session_id'])) {
        $sid = trim((string)$opts['session_id']);
        if (preg_match('/^[a-f0-9]{32}$/', $sid)) {
            $sess = db_one('SELECT * FROM sessions WHERE id = ?', [$sid]);
            // Must pass same browser/session access rules as chat APIs (P1-1).
            $sessionUsable = $sess && session_access_allowed($sess);
            if ($sessionUsable) {
                $sessUser = $sess['user_id'] ?? null;
                // Session must be unbound or already owned by claimer.
                if ($sessUser !== null && $sessUser !== '' && (int)$sessUser !== $userId) {
                    $sessionUsable = false;
                }
            }
            if ($sessionUsable) {
                if ((string)($sess['page_slug'] ?? '') === $slug) {
                    $allowed = true;
                    $via = 'session';
                } elseif ((string)($page['session_id'] ?? '') === $sid) {
                    $allowed = true;
                    $via = 'page_session';
                }
            }
        }
    }

    if (!$allowed && !empty($opts['email_match'])) {
        $user = db_one('SELECT email FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        $userEmail = normalize_email($user['email'] ?? '');
        $pageEmail = normalize_email($page['email'] ?? '');
        if ($userEmail !== '' && $pageEmail !== '' && $userEmail === $pageEmail) {
            $allowed = true;
            $via = 'email';
        }
    }

    if (!$allowed) {
        return ['ok' => false, 'error' => 'not_eligible'];
    }

    // Atomic null-owner claim only — never overwrite an existing owner.
    $stmt = db()->prepare(
        'UPDATE pages SET owner_user_id = ?, updated_at = ? WHERE slug = ? AND owner_user_id IS NULL'
    );
    $stmt->execute([$userId, now_iso(), $slug]);
    if ($stmt->rowCount() === 0) {
        // Race: another owner won, or already self.
        $fresh = db_one('SELECT owner_user_id FROM pages WHERE slug = ?', [$slug]);
        if ($fresh && (int)($fresh['owner_user_id'] ?? 0) === $userId) {
            return ['ok' => true, 'already' => true, 'slug' => $slug, 'via' => $via];
        }
        return ['ok' => false, 'error' => 'already_owned'];
    }
    return ['ok' => true, 'already' => false, 'slug' => $slug, 'via' => $via];
}

/**
 * After login: bind session + claim same-session page + email-matched orphan pages.
 */
function claim_pages_after_login($userId, $sessionId = null, $email = null) {
    $userId = (int)$userId;
    $results = ['session_bind' => null, 'session_claim' => null, 'email_claims' => []];
    if ($userId <= 0) {
        return $results;
    }

    $sessionId = $sessionId !== null ? trim((string)$sessionId) : '';
    if ($sessionId !== '' && preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        $results['session_bind'] = bind_session_to_user($sessionId, $userId);
        // Only claim via session when bind succeeded (unbound or already ours).
        // owned_by_other must not open a claim path for a foreign session.
        $bindOk = !empty($results['session_bind']['ok']);
        if ($bindOk) {
            $sess = db_one('SELECT page_slug FROM sessions WHERE id = ?', [$sessionId]);
            $slug = trim((string)($sess['page_slug'] ?? ''));
            if ($slug !== '') {
                $results['session_claim'] = claim_page_for_user($slug, $userId, ['session_id' => $sessionId]);
            }
        } else {
            $results['session_claim'] = [
                'ok' => false,
                'error' => (string)($results['session_bind']['error'] ?? 'not_eligible'),
            ];
        }
    }

    if ($email === null) {
        $user = db_one('SELECT email FROM users WHERE id = ?', [$userId]);
        $email = $user['email'] ?? '';
    }
    $emailNorm = normalize_email($email);
    if ($emailNorm === '') {
        return $results;
    }

    // Limited batch: orphan pages whose guest email matches the account (G10).
    $rows = db_all(
        'SELECT slug FROM pages WHERE owner_user_id IS NULL AND email IS NOT NULL AND lower(email) = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 20',
        [$emailNorm]
    );
    if (!is_array($rows)) {
        $rows = [];
    }
    foreach ($rows as $row) {
        $slug = (string)($row['slug'] ?? '');
        if ($slug === '') continue;
        $results['email_claims'][] = claim_page_for_user($slug, $userId, ['email_match' => true]);
    }
    return $results;
}
