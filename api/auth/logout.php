<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

require_method('POST');
xlog_start_session();
$_SESSION = [];
session_destroy();
api_json(['ok' => true]);
