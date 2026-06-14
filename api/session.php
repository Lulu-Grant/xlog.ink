<?php
require_once __DIR__ . '/../includes/quota.php';
require_once __DIR__ . '/../includes/imageproc.php';

require_method('POST');
xlog_start_session();

$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$resumeId = trim($data['session_id'] ?? '');
if ($resumeId !== '') {
    if (!preg_match('/^[a-f0-9]{32}$/', $resumeId)) api_error('bad_session', 'Invalid session');
    $session = db_one('SELECT * FROM sessions WHERE id = ?', [$resumeId]);
    if (!$session) api_error('session_not_found', 'Session not found', 404);
    if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenResumeSession', $locale), 403);
    db_exec('UPDATE sessions SET locale = ?, updated_at = ? WHERE id = ?', [$locale, now_iso(), $resumeId]);
    $session['locale'] = $locale;
    api_json(session_response_payload($session));
}

$charge = consume_quota('session_create');
if (!$charge['ok']) {
    api_error('session_quota_exceeded', t('api', 'sessionCreateExceeded', $locale), 429);
}
try {
    $sessionId = create_session(null, []);
    $session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
} catch (Throwable $e) {
    refund_quota('session_create', $charge);
    throw $e;
}
$greeting = t('app', 'greeting', $locale);
api_json(session_response_payload($session, $greeting));

function session_response_payload(array $session, $greeting = null) {
    $page = null;
    if (!empty($session['page_slug'])) {
        $row = db_one('SELECT slug, title, type, lang, updated_at, created_at, email, editable, is_adult, status, screenshot_path, og_image_path FROM pages WHERE slug = ?', [$session['page_slug']]);
        if ($row) {
            $page = [
                'slug' => $row['slug'],
                'title' => $row['title'],
                'type' => $row['type'],
                'lang' => $row['lang'],
                'url' => 'https://' . $row['slug'] . '.xlog.ink/',
                'updated_at' => $row['updated_at'],
                'created_at' => $row['created_at'],
                'email' => $row['email'],
                'editable' => !empty($row['editable']),
                'is_adult' => !empty($row['is_adult']),
                'status' => $row['status'],
                'image_url' => !empty($row['screenshot_path']) ? image_public_url($row['screenshot_path']) : '',
                'og_image_url' => !empty($row['og_image_path']) ? image_public_url($row['og_image_path']) : '',
            ];
        }
    }

    $payload = [
        'session_id' => $session['id'],
        'messages' => session_public_messages($session['id']),
        'state' => $session['state'],
        'edit_mode' => $session['edit_mode'] ?? '',
        'page' => $page,
        'quota' => quota_status('generate'),
        'user' => current_user_id(),
        'locale' => validate_lang($session['locale'] ?? resolve_locale()),
    ];
    if ($greeting !== null) $payload['greeting'] = $greeting;
    return $payload;
}

function session_public_messages($sessionId) {
    $messages = session_messages($sessionId) ?: [];
    return array_values(array_filter($messages, function ($message) {
        $role = $message['role'] ?? 'assistant';
        $content = (string)($message['content'] ?? '');
        if (str_starts_with($content, '[系统事件]')) return false;
        if ($role === 'user' && str_starts_with($content, '[当前页面信息]')) return false;
        if ($role === 'user' && str_starts_with($content, '[目前頁面資訊]')) return false;
        if ($role === 'user' && str_starts_with($content, '[Current page info]')) return false;
        if ($role === 'user' && str_starts_with($content, '[图片已上传:')) return false;
        if ($role === 'user' && str_starts_with($content, '[图片已生成:')) return false;
        if ($role === 'user' && str_starts_with($content, '[圖片已上傳:')) return false;
        if ($role === 'user' && str_starts_with($content, '[圖片已生成:')) return false;
        if ($role === 'user' && str_starts_with($content, '[Image uploaded:')) return false;
        if ($role === 'user' && str_starts_with($content, '[Image generated:')) return false;
        return true;
    }));
}
