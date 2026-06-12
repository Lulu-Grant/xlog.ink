<?php
require_once __DIR__ . '/../includes/quota.php';

require_method('POST');
xlog_start_session();

$data = json_input();
$resumeId = trim($data['session_id'] ?? '');
if ($resumeId !== '') {
    if (!preg_match('/^[a-f0-9]{32}$/', $resumeId)) api_error('bad_session', 'Invalid session');
    $session = db_one('SELECT * FROM sessions WHERE id = ?', [$resumeId]);
    if (!$session) api_error('session_not_found', 'Session not found', 404);
    if (!session_access_allowed($session)) api_error('forbidden_session', '你不能恢复这个会话。', 403);
    api_json(session_response_payload($session));
}

$charge = consume_quota('session_create');
if (!$charge['ok']) {
    api_error('session_quota_exceeded', '今日会话创建次数已达上限。', 429);
}
try {
    $sessionId = create_session(null, []);
    $session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
} catch (Throwable $e) {
    refund_quota('session_create', $charge);
    throw $e;
}
$greeting = '你想创建什么类型的页面？可以选择名片、宣传海报、文章页面、活动页面，或者直接自由描述。';
api_json(session_response_payload($session, $greeting));

function session_response_payload(array $session, $greeting = null) {
    $page = null;
    if (!empty($session['page_slug'])) {
        $row = db_one('SELECT slug, title, type, lang, updated_at, created_at, email, editable, is_adult, status FROM pages WHERE slug = ?', [$session['page_slug']]);
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
            ];
        }
    }

    $payload = [
        'session_id' => $session['id'],
        'messages' => session_messages($session['id']) ?: [],
        'state' => $session['state'],
        'edit_mode' => $session['edit_mode'] ?? '',
        'page' => $page,
        'quota' => quota_status('generate'),
        'user' => current_user_id(),
    ];
    if ($greeting !== null) $payload['greeting'] = $greeting;
    return $payload;
}
