<?php
require_once __DIR__ . '/../includes/content_tools.php';

require_method('POST');
$data = json_input();
$locale = resolve_locale($data['locale'] ?? null);
set_locale_cookie($locale);
$sessionId = trim($data['session_id'] ?? '');
$prefix = slug_clean($data['prefix'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) api_error('bad_session', 'Invalid session');
if ($prefix === '' || strlen($prefix) < 3) api_error('bad_domain', t('api', 'badDomainPrefix', $locale));
if (slug_is_reserved($prefix)) api_error('reserved_domain', t('api', 'reservedDomainPrefix', $locale), 409);
$session = db_one('SELECT * FROM sessions WHERE id = ?', [$sessionId]);
if (!$session) api_error('session_not_found', 'Session not found', 404);
if (!session_access_allowed($session)) api_error('forbidden_session', t('api', 'forbiddenChatSession', $locale), 403);

$final = $prefix;
$available = slug_is_available_for_page($final);
if (!$available) {
    $base = substr($prefix, 0, 7);
    for ($i = 0; $i < 30; $i++) {
        $candidate = $base . random_letters(3);
        if (slug_is_available_for_page($candidate)) {
            $final = $candidate;
            break;
        }
    }
    if (!slug_is_available_for_page($final)) {
        $generated = generate_semantic_slug([], '', $prefix);
        $final = $generated['slug'];
    }
}
db_exec('UPDATE sessions SET desired_slug = ?, slug_mode = ?, updated_at = ? WHERE id = ?', [$final, $available ? 'custom' : 'custom_suffix', now_iso(), $sessionId]);
append_session_message($sessionId, 'system', '[系统事件] 用户指定二级域名前缀：' . $final);
api_json([
    'ok' => true,
    'available' => $available,
    'prefix' => $prefix,
    'final_prefix' => $final,
    'url' => 'https://' . $final . '.xlog.ink/',
]);
