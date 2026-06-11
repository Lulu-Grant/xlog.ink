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
try {
    $usage = ai_stream_chat($modelMessages, function ($delta) use (&$assistant) {
        $assistant .= $delta;
        sse_event('delta', ['text' => $delta]);
    });
    $ready = preg_match('/\n?\s*\[READY\]\s*$/u', $assistant) === 1;
    $uploadPrompt = preg_match('/\n?\s*\[UPLOAD\]\s*$/u', $assistant) === 1;
    $clean = preg_replace('/(?:\n?\s*\[(?:READY|UPLOAD)\]\s*)+$/u', '', $assistant);
    append_session_message($sessionId, 'assistant', trim($clean));
    if ($uploadPrompt) {
        sse_event('upload_prompt', []);
    }
    if ($ready) {
        db_exec('UPDATE sessions SET state = ?, updated_at = ? WHERE id = ?', ['ready', now_iso(), $sessionId]);
        sse_event('ready', []);
    }
    sse_event('done', ['usage' => $usage]);
} catch (Throwable $e) {
    sse_event('error', ['code' => 'ai_error', 'message' => $e->getMessage()]);
}

function truncate_messages_for_chat(array $messages) {
    if (count($messages) <= 30) {
        return array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages);
    }
    $first = array_slice($messages, 0, 2);
    $last = array_slice($messages, -20);
    $middle = [['role' => 'assistant', 'content' => '（中间较早对话已省略，继续基于最近上下文引导用户。）']];
    return array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], array_merge($first, $middle, $last));
}
