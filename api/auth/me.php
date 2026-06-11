<?php
require_once __DIR__ . '/../../includes/quota.php';

xlog_start_session();
$userId = current_user_id();
if (!$userId) api_json(['user' => null, 'quota' => quota_status('generate')]);
$user = db_one('SELECT id, email, daily_quota, credits FROM users WHERE id = ?', [$userId]);
api_json(['user' => $user, 'quota' => quota_status('generate')]);
