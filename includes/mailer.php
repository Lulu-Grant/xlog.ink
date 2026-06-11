<?php
// Minimal SMTP/mail wrapper. Uses PHPMailer if installed, falls back to PHP mail().

require_once __DIR__ . '/db.php';

function normalize_email($email) {
    $email = trim(strtolower((string)$email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function send_mail_template($to, $template, array $vars) {
    $to = normalize_email($to);
    if ($to === '') throw new RuntimeException('Invalid email');
    [$subject, $body] = mail_render_template($template, $vars);
    return send_plain_mail($to, $subject, $body);
}

function mail_event_key($value) {
    return hash('sha256', strtolower(trim((string)$value)));
}

function mail_recently_sent($kind, $key, $seconds) {
    $eventKey = mail_event_key($key);
    $row = db_one(
        'SELECT created_at FROM mail_events WHERE kind = ? AND event_key = ? ORDER BY created_at DESC LIMIT 1',
        [$kind, $eventKey]
    );
    return $row && strtotime($row['created_at']) > time() - $seconds;
}

function record_mail_event($kind, $key) {
    db_exec(
        'INSERT INTO mail_events (kind, event_key, created_at) VALUES (?, ?, ?)',
        [$kind, mail_event_key($key), now_iso()]
    );
}

function mail_render_template($template, array $vars) {
    if ($template === 'login-code') {
        return ['xlog.ink 通知', "xlog.ink 登录验证码\n\n验证码：{$vars['code']}\n\n5 分钟内有效。如果不是你本人操作，可以忽略这封邮件。"];
    }
    if ($template === 'edit-link') {
        return ['你的 xlog.ink 页面修改链接', "页面：{$vars['url']}\n\n修改链接：{$vars['edit_url']}\n\n请妥善保存。"];
    }
    return ['xlog.ink 通知', $vars['body'] ?? ''];
}

function send_plain_mail($to, $subject, $body) {
    $cfg = xlog_config('smtp');
    if (empty($cfg['host'])) {
        $log = xlog_config('data_dir') . '/mail.log';
        @file_put_contents($log, "TO: $to\nSUBJECT: $subject\n$body\n\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    return smtp_send_plain($cfg, $to, $subject, $body);
}

function smtp_send_plain(array $cfg, $to, $subject, $body) {
    $host = $cfg['host'];
    $port = (int)($cfg['port'] ?? 465);
    $secure = $cfg['secure'] ?? 'ssl';
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) throw new RuntimeException('SMTP connect failed: ' . $errstr);
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $response;
    };
    $cmd = function ($line, array $ok) use ($fp, $read) {
        fwrite($fp, $line . "\r\n");
        $response = $read();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP error after ' . strtok($line, ' ') . ': ' . trim($response));
        }
        return $response;
    };

    $banner = $read();
    if ((int)substr($banner, 0, 3) !== 220) throw new RuntimeException('SMTP banner failed: ' . trim($banner));
    $serverName = $_SERVER['SERVER_NAME'] ?? 'xlog.ink';
    $cmd('EHLO ' . $serverName, [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('SMTP STARTTLS failed');
        }
        $cmd('EHLO ' . $serverName, [250]);
    }
    if (!empty($cfg['user']) && !empty($cfg['pass'])) {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($cfg['user']), [334]);
        $cmd(base64_encode($cfg['pass']), [235]);
    }

    $from = $cfg['from'] ?: $cfg['user'];
    $fromName = mail_header_encode($cfg['from_name'] ?: 'xlog.ink');
    $subjectEncoded = mail_header_encode($subject);
    $messageId = '<' . bin2hex(random_bytes(12)) . '@xlog.ink>';
    $headers = [
        'From: ' . $fromName . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $subjectEncoded,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));

    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    fwrite($fp, $data . "\r\n.\r\n");
    $response = $read();
    if ((int)substr($response, 0, 3) !== 250) {
        throw new RuntimeException('SMTP DATA failed: ' . trim($response));
    }
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}

function mail_header_encode($text) {
    return '=?UTF-8?B?' . base64_encode((string)$text) . '?=';
}
