<?php

function extract_chat_action($text) {
    $text = (string)$text;
    if (preg_match_all('/\[\[ACTION:([A-Z_]+)((?:\s+\w+=\S+)*)\]\]/u', $text, $matches, PREG_SET_ORDER)) {
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $type = strtolower($matches[$i][1]);
            if (in_array($type, ['upload', 'ready', 'publish', 'email', 'domain', 'image_gen', 'new_session'], true)) {
                return ['type' => $type, 'params' => parse_action_params($matches[$i][2] ?? '')];
            }
        }
    }
    if (preg_match('/\n?\s*\[READY\]\s*$/u', $text)) {
        return ['type' => 'ready', 'params' => []];
    }
    if (preg_match('/\n?\s*\[UPLOAD\]\s*$/u', $text)) {
        return ['type' => 'upload', 'params' => []];
    }
    return null;
}

function parse_action_params($raw) {
    $params = [];
    if (preg_match_all('/\s+(\w+)=([^\s\]]+)/u', (string)$raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $value = str_replace('_', ' ', $match[2]);
            if (in_array($key, ['slot', 'hint', 'reason', 'prefix', 'prompt'], true)) {
                $params[$key] = mb_substr($value, 0, 120, 'UTF-8');
            }
        }
    }
    if (isset($params['slot']) && !in_array($params['slot'], ['hero', 'avatar', 'product', 'gallery'], true)) {
        unset($params['slot']);
    }
    return $params;
}

function strip_chat_action_markers($text) {
    $text = preg_replace('/\s*\[\[ACTION:[A-Z_]+(?:\s+\w+=\S+)*\]\]\s*/u', '', (string)$text);
    $text = preg_replace('/\s*\[(?:READY|UPLOAD)\]\s*/u', '', $text);
    return $text;
}

function sanitize_user_chat_message($text) {
    $text = trim((string)$text);
    $text = preg_replace('/\s*\[\[ACTION:[A-Z_]+(?:\s+\w+=\S+)*\]\]\s*/u', ' ', $text);
    $text = preg_replace('/\s*\[(?:READY|UPLOAD)\]\s*/u', ' ', $text);
    return trim(preg_replace('/[ \t]{2,}/u', ' ', $text));
}
