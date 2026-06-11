<?php
require_once __DIR__ . '/../includes/quota.php';

require_method('POST');
xlog_start_session();

$data = json_input();
$sessionId = create_session(null, []);
$greeting = '你想创建什么类型的页面？可以选择名片、宣传海报、文章页面、活动页面，或者直接自由描述。';
api_json([
    'session_id' => $sessionId,
    'greeting' => $greeting,
    'quota' => quota_status('generate'),
    'user' => current_user_id(),
]);
