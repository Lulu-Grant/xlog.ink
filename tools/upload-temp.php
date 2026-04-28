<?php
require_once __DIR__ . '/../config.php';

json_response([
    'error' => 'This legacy upload endpoint has been retired.',
], 410);
