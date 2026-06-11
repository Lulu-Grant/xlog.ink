<?php
require_once __DIR__ . '/../includes/quota.php';

require_method('POST');
xlog_start_session();

$data = json_input();
$charge = consume_quota('session_create');
if (!$charge['ok']) {
    api_error('session_quota_exceeded', '今日会话创建次数已达上限。', 429);
}
try {
    $sessionId = create_session(null, []);
} catch (Throwable $e) {
    refund_quota('session_create', $charge);
    throw $e;
}
$greeting = '你想创建什么类型的页面？可以选择名片、宣传海报、文章页面、活动页面，或者直接自由描述。';
api_json([
    'session_id' => $sessionId,
    'greeting' => $greeting,
    'quota' => quota_status('generate'),
    'user' => current_user_id(),
]);
