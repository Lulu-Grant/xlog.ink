<?php
/**
 * Isolated SQLite + config for integration tests (AUDIT-7 P1-4).
 * Refuses to run against the project default data directory.
 */

function xlog_test_project_root() {
    return dirname(__DIR__, 2);
}

function xlog_test_default_data_dir() {
    return realpath(xlog_test_project_root() . '/data') ?: (xlog_test_project_root() . '/data');
}

/**
 * @return array{tmp:string,data_dir:string,site_dir:string,asset_dir:string,config_path:string}
 */
function xlog_test_bootstrap(array $extraConfig = []) {
    $root = xlog_test_project_root();
    $defaultData = xlog_test_default_data_dir();

    $tmp = sys_get_temp_dir() . '/xlog-test-' . bin2hex(random_bytes(6));
    $dataDir = $tmp . '/data';
    $siteDir = $tmp . '/site';
    $assetDir = $tmp . '/site-assets';
    foreach ([$tmp, $dataDir, $siteDir, $assetDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('Cannot create test dir: ' . $dir);
        }
    }

    $realData = realpath($dataDir) ?: $dataDir;
    $realDefault = realpath($defaultData) ?: $defaultData;
    if ($realData === $realDefault || strpos($realData, $realDefault) === 0) {
        throw new RuntimeException('REFUSE: test data_dir must not be project data/');
    }

    $config = array_replace_recursive([
        'base_url' => 'https://xlog.test',
        'data_dir' => $dataDir,
        'site_dir' => $siteDir,
        'asset_dir' => $assetDir,
        'app' => ['env' => 'test'],
        'billing' => [
            'credit_mode' => true,
            'generate_credit_cost' => 1,
            'guest_generate_quota' => 5,
            'signup_credits' => 10,
            'user_fallback_daily_generate' => 2,
            'packages' => [
                ['id' => 'c10', 'credits' => 10, 'amount_cents' => 1000],
                ['id' => 'c30', 'credits' => 30, 'amount_cents' => 2800],
                ['id' => 'c100', 'credits' => 100, 'amount_cents' => 8800],
                ['id' => 'c500', 'credits' => 500, 'amount_cents' => 39800],
            ],
        ],
        'pay' => [
            'enabled' => true,
            'allow_http_api' => true,
        ],
        'admin' => [
            'token' => 'test-admin-token',
            'allow_credit_grant' => false,
        ],
        'ai' => [
            'mock' => true,
        ],
        'analytics' => [
            'salt' => 'test-salt',
        ],
    ], $extraConfig);

    $configPath = $tmp . '/config.php';
    file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';');
    putenv('XLOG_CONFIG_PATH=' . $configPath);
    $_ENV['XLOG_CONFIG_PATH'] = $configPath;

    return [
        'tmp' => $tmp,
        'data_dir' => $dataDir,
        'site_dir' => $siteDir,
        'asset_dir' => $assetDir,
        'config_path' => $configPath,
        'root' => $root,
    ];
}

function xlog_test_assert_not_default_data_dir() {
    $dataDir = (string)xlog_config('data_dir', '');
    $default = xlog_test_default_data_dir();
    $real = realpath($dataDir) ?: $dataDir;
    $realDefault = realpath($default) ?: $default;
    if ($real === $realDefault || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $realDefault)) === 0) {
        throw new RuntimeException('REFUSE: configured data_dir is project default data/');
    }
}

function xlog_test_remove_tree($path) {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $item = $path . '/' . $name;
        if (is_dir($item)) {
            xlog_test_remove_tree($item);
        } else {
            @unlink($item);
        }
    }
    @rmdir($path);
}
