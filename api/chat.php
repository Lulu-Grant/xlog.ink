<?php
require_once __DIR__ . '/../includes/ai.php';

require_method('POST');
$data = json_input();
$sessionId = trim($data['session_id'] ?? '');
$message = trim($data['message'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($message === '') api_error('empty_message', 'Message required');

$row = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$row) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($row)) api_error('forbidden_session', '你不能使用这个会话。', 403);

$q = consume_quota('chat_turn');
if (!$q['ok']) {
    sse_start();
    sse_event('notice', ['type' => 'quota', 'identity' => $q['identity'], 'message' => '今日对话额度已用完。登录后可获得更高生成额度。']);
    sse_event('done', ['usage' => []]);
    exit;
}

append_session_message($sessionId, 'user', $message);
$history = session_messages($sessionId) ?: [];
$quota = quota_status('generate');
$system = prompt_text('chat-system.txt') . "\n\n【当前状态】\n当前用户：" . $quota['identity'] . "，今日剩余生成额度：" . $quota['remaining'];
$modelMessages = [['role' => 'system', 'content' => $system]];
$modelMessages = array_merge($modelMessages, truncate_messages_for_chat($history));

sse_start();
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
    sse_event('error', ['code' => 'ai_error', 'message' => $e->getMessage()]);
}

function truncate_messages_for_chat(array $messages) {
    if (count($messages) <= 30) {
        return array_map('chat_model_message', $messages);
    }
    $first = array_slice($messages, 0, 2);
    $last = array_slice($messages, -20);
    $middle = [['role' => 'assistant', 'content' => '（中间较早对话已省略，继续基于最近上下文引导用户。）']];
    return array_map('chat_model_message', array_merge($first, $middle, $last));
}

function chat_model_message(array $m) {
    $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
    return ['role' => $role, 'content' => (string)($m['content'] ?? '')];
}

function extract_chat_action($text) {
    $text = (string)$text;
    if (preg_match('/\[\[ACTION:([A-Z]+)((?:\s+\w+=\S+)*)\]\]\s*$/u', $text, $m)) {
        $type = strtolower($m[1]);
        if (in_array($type, ['upload', 'ready', 'publish', 'email'], true)) {
            return ['type' => $type, 'params' => parse_action_params($m[2] ?? '')];
        }
        return null;
    }
    if (preg_match('/\n?\s*\[READY\]\s*$/u', $text)) {
        return ['type' => 'ready', 'params' => []];
    }
    if (preg_match('/\n?\s*\[UPLOAD\]\s*$/u', $text)) {
        return ['type' => 'upload', 'params' => []];
    }
    return null;
}

function parse_action_params($raw) {
    $params = [];
    if (preg_match_all('/\s+(\w+)=([^\s\]]+)/u', (string)$raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $value = str_replace('_', ' ', $match[2]);
            if (in_array($key, ['slot', 'hint', 'reason'], true)) {
                $params[$key] = mb_substr($value, 0, 120, 'UTF-8');
            }
        }
    }
    if (isset($params['slot']) && !in_array($params['slot'], ['hero', 'avatar', 'product', 'gallery'], true)) {
        unset($params['slot']);
    }
    return $params;
}

function strip_chat_action_markers($text) {
    $text = preg_replace('/\s*\[\[ACTION:[A-Z]+(?:\s+\w+=\S+)*\]\]\s*/u', '', (string)$text);
    $text = preg_replace('/\s*\[(?:READY|UPLOAD)\]\s*/u', '', $text);
    return $text;
}
