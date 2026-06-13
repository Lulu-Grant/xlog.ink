<?php
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/turnstile.php';

$args = array_slice($argv, 1);
$liveAi = in_array('--live-ai', $args, true);
$smtpTo = null;
foreach ($args as $arg) {
    if (strpos($arg, '--smtp-to=') === 0) $smtpTo = substr($arg, 10);
}

function diag($name, $ok, $message) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ' - ' . $message . PHP_EOL;
}

echo "xlog.ink V2 config diagnostics\n";
echo "Config: local config.php " . (is_file(XLOG_ROOT . '/config.php') ? 'present' : 'missing') . "\n";
echo "Config: /etc/xlog/config.php " . (is_file('/etc/xlog/config.php') ? 'present' : 'missing') . "\n\n";

$cfg = xlog_config();
diag('SQLite', is_writable(xlog_config('data_dir')) && db() instanceof PDO, xlog_config('data_dir') . '/xlog.db');
diag('site_dir', is_writable(xlog_config('site_dir')), xlog_config('site_dir'));
diag('asset_dir', is_writable(xlog_config('asset_dir')), xlog_config('asset_dir'));

foreach (['chat', 'gen', 'image', 'moderation'] as $purpose) {
    $ai = ai_config($purpose);
    $hasKey = !empty($ai['model']) && strpos((string)$ai['model'], '<') === false && !empty($ai['key']) && strpos((string)$ai['key'], '<') === false;
    diag("AI {$purpose} config", $hasKey, $ai['base_url'] . ' · ' . $ai['model'] . ' · ' . $ai['format']);
}

if ($liveAi) {
    if (!ai_has_key('chat')) {
        diag('AI chat stream', false, 'missing chat API key');
    } else {
        try {
            $text = '';
            ai_stream_chat([
                ['role' => 'system', 'content' => 'Reply with OK only.'],
                ['role' => 'user', 'content' => 'ping'],
            ], function ($delta) use (&$text) { $text .= $delta; });
            diag('AI chat stream', trim($text) !== '', mb_substr(trim($text), 0, 80, 'UTF-8'));
        } catch (Throwable $e) {
            diag('AI chat stream', false, $e->getMessage());
        }
    }

    if (!ai_has_key('gen')) {
        diag('AI gen stream', false, 'missing generation API key');
    } else {
        try {
            $text = '';
            ai_stream_generate([
                ['role' => 'system', 'content' => 'Output only ```html\n<!DOCTYPE html><html><head><title>OK</title><meta name="description" content="OK"><meta property="og:title" content="OK"><meta property="og:description" content="OK"></head><body>OK</body></html>\n```'],
                ['role' => 'user', 'content' => 'ping'],
            ], function ($delta) use (&$text) { $text .= $delta; });
            diag('AI gen stream', strpos($text, '<!DOCTYPE html>') !== false || trim($text) !== '', mb_substr(trim($text), 0, 80, 'UTF-8'));
        } catch (Throwable $e) {
            diag('AI gen stream', false, $e->getMessage());
        }
    }

    if (!ai_has_key('moderation')) {
        diag('AI visual moderation', false, 'missing moderation API key');
    } else {
        try {
            $probe = tempnam(sys_get_temp_dir(), 'xlog-moderation-') . '.png';
            $im = imagecreatetruecolor(64, 64);
            $bg = imagecolorallocate($im, 255, 255, 255);
            imagefill($im, 0, 0, $bg);
            imagepng($im, $probe);
            if (PHP_VERSION_ID < 80500) imagedestroy($im);
            $result = ai_moderate_image($probe, 'image/png', 'diagnostic clean 64x64 pixel');
            @unlink($probe);
            diag('AI visual moderation', is_array($result), 'score=' . ($result['score'] ?? 'n/a') . ' reason=' . ($result['reason'] ?? 'n/a'));
        } catch (Throwable $e) {
            diag('AI visual moderation', false, $e->getMessage());
        }
    }
} else {
    echo "[SKIP] AI live stream - pass --live-ai to test api.3s3.org\n";
}

$turnstileEnabled = (bool)xlog_config('turnstile.enabled', false);
$turnstileOk = !$turnstileEnabled || (xlog_config('turnstile.site_key') && xlog_config('turnstile.secret_key'));
diag('Turnstile config', $turnstileOk, $turnstileEnabled ? 'enabled' : 'disabled');

$smtp = xlog_config('smtp');
$smtpConfigured = !empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass']) && !empty($smtp['from']);
diag('SMTP config', $smtpConfigured, ($smtp['host'] ?: 'not configured') . ':' . ($smtp['port'] ?? 465));
if ($smtpTo) {
    if (!$smtpConfigured) {
        diag('SMTP send', false, 'missing SMTP config');
    } else {
        try {
            send_mail_template($smtpTo, 'notice', ['body' => 'xlog.ink SMTP diagnostic message']);
            diag('SMTP send', true, 'sent to ' . $smtpTo);
        } catch (Throwable $e) {
            diag('SMTP send', false, $e->getMessage());
        }
    }
} else {
    echo "[SKIP] SMTP send - pass --smtp-to=email@example.com to send a test message\n";
}
