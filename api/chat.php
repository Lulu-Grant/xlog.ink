<?php
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/chat_actions.php';

@set_time_limit(120);
@ini_set('max_execution_time', '120');

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$sessionId = trim($data['session_id'] ?? '');
$message = sanitize_user_chat_message($data['message'] ?? '');
$messageTruncated = false;
if (mb_strlen($message, 'UTF-8') > 4000) {
    $message = mb_substr($message, 0, 4000, 'UTF-8');
    $messageTruncated = true;
}
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($message === '') api_error('empty_message', 'Message required');

$row = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$row) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($row)) api_error('forbidden_session', t('api', 'forbiddenChatSession', $locale), 403);
db_exec('UPDATE sessions SET locale = ?, updated_at = ? WHERE id = ?', [$locale, now_iso(), $sessionId]);
$row['locale'] = $locale;

if (($row['state'] ?? '') === 'generating') {
    sse_start();
    sse_event('notice', ['type' => 'generating', 'message' => t('api', 'sessionGenerating', $locale)]);
    sse_event('done', ['usage' => []]);
    exit;
}

$q = consume_quota('chat_turn');
if (!$q['ok']) {
    sse_start();
    sse_event('notice', ['type' => 'quota', 'identity' => $q['identity'], 'message' => t('api', 'chatQuotaExceeded', $locale)]);
    sse_event('done', ['usage' => []]);
    exit;
}

append_session_message($sessionId, 'user', $message);
$history = session_messages($sessionId) ?: [];
$quota = quota_status('generate');
$system = prompt_text('chat-system.txt')
    . "\n\n" . t('prompt', 'chatLanguage', $locale)
    . "\n\n" . format_quota_status_for_prompt($locale, $quota);
$modelMessages = [['role' => 'system', 'content' => $system]];
$GLOBALS['xlog_chat_locale'] = $locale;
$modelMessages = array_merge($modelMessages, truncate_messages_for_chat($history));

sse_start();
if ($messageTruncated) {
    sse_event('notice', ['type' => 'input', 'message' => t('api', 'messageTruncated', $locale)]);
}
$assistant = '';
$streamTail = '';
$streamTailLimit = 64;
try {
    $usage = ai_stream_chat($modelMessages, function ($delta) use (&$assistant, &$streamTail, $streamTailLimit) {
        $assistant .= $delta;
        $streamTail .= $delta;
        if (mb_strlen($streamTail, 'UTF-8') > $streamTailLimit) {
            $sendLen = mb_strlen($streamTail, 'UTF-8') - $streamTailLimit;
            $send = mb_substr($streamTail, 0, $sendLen, 'UTF-8');
            $streamTail = mb_substr($streamTail, $sendLen, null, 'UTF-8');
            if ($send !== '') sse_event('delta', ['text' => $send]);
        }
    });
    $action = extract_chat_action($assistant);
    $cleanTail = strip_chat_action_markers($streamTail);
    if ($cleanTail !== '') sse_event('delta', ['text' => $cleanTail]);
    $clean = strip_chat_action_markers($assistant);
    append_session_message($sessionId, 'assistant', trim($clean));
    if ($action && $action['type'] === 'ready') {
        db_exec('UPDATE sessions SET state = ?, updated_at = ? WHERE id = ?', ['ready', now_iso(), $sessionId]);
    }
    if ($action) {
        sse_event('action', $action);
    }
    sse_event('done', ['usage' => $usage]);
} catch (Throwable $e) {
    error_log('chat ai_error: ' . $e->getMessage());
    sse_event('error', ['code' => 'ai_error', 'message' => t('app', 'aiChatFailed', $locale) ?: 'AI chat failed']);
}

function truncate_messages_for_chat(array $messages) {
    if (count($messages) <= 30) {
        return array_map('chat_model_message', $messages);
    }
    $first = array_slice($messages, 0, 2);
    $last = array_slice($messages, -20);
    $locale = $GLOBALS['xlog_chat_locale'] ?? resolve_locale();
    $middle = [['role' => 'assistant', 'content' => t('prompt', 'truncated', $locale)]];
    return array_map('chat_model_message', array_merge($first, $middle, $last));
}

function chat_model_message(array $m) {
    $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
    return ['role' => $role, 'content' => (string)($m['content'] ?? '')];
}
