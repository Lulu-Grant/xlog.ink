<?php
// includes/markdown.php — minimal server-side Markdown renderer with escaped output

require_once __DIR__ . '/helpers.php';

function markdown_to_html($markdown) {
    $markdown = str_replace(["\r\n", "\r"], "\n", (string)$markdown);
    $lines = explode("\n", $markdown);

    $html = [];
    $paragraph = [];
    $listItems = [];
    $blockquote = [];
    $codeLines = [];
    $inCode = false;

    $flush_paragraph = function () use (&$html, &$paragraph) {
        if (!$paragraph) return;
        $text = trim(implode(' ', $paragraph));
        if ($text !== '') {
            $html[] = '<p>' . markdown_inline($text) . '</p>';
        }
        $paragraph = [];
    };

    $flush_list = function () use (&$html, &$listItems) {
        if (!$listItems) return;
        $html[] = '<ul><li>' . implode('</li><li>', $listItems) . '</li></ul>';
        $listItems = [];
    };

    $flush_blockquote = function () use (&$html, &$blockquote) {
        if (!$blockquote) return;
        $parts = [];
        foreach ($blockquote as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts[] = '<p>' . markdown_inline($line) . '</p>';
        }
        if ($parts) {
            $html[] = '<blockquote>' . implode('', $parts) . '</blockquote>';
        }
        $blockquote = [];
    };

    $flush_code = function () use (&$html, &$codeLines, &$inCode) {
        if (!$inCode) return;
        $html[] = '<pre><code>' . h(implode("\n", $codeLines)) . '</code></pre>';
        $codeLines = [];
        $inCode = false;
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            $flush_paragraph();
            $flush_list();
            $flush_blockquote();
            if ($inCode) {
                $flush_code();
            } else {
                $inCode = true;
                $codeLines = [];
            }
            continue;
        }

        if ($inCode) {
            $codeLines[] = $line;
            continue;
        }

        if (trim($line) === '') {
            $flush_paragraph();
            $flush_list();
            $flush_blockquote();
            continue;
        }

        if (preg_match('/^\s*[-*_]\s*[-*_]\s*[-*_][\s*_ -]*$/', $line)) {
            $flush_paragraph();
            $flush_list();
            $flush_blockquote();
            $html[] = '<hr>';
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.*)$/u', $line, $m)) {
            $flush_paragraph();
            $flush_list();
            $flush_blockquote();
            $level = strlen($m[1]);
            $html[] = '<h' . $level . '>' . markdown_inline(trim($m[2])) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\s*>\s?(.*)$/u', $line, $m)) {
            $flush_paragraph();
            $flush_list();
            $blockquote[] = $m[1];
            continue;
        }

        if (preg_match('/^\s*[-*]\s+(.*)$/u', $line, $m)) {
            $flush_paragraph();
            $flush_blockquote();
            $listItems[] = markdown_inline(trim($m[1]));
            continue;
        }

        $flush_list();
        $flush_blockquote();
        $paragraph[] = trim($line);
    }

    $flush_paragraph();
    $flush_list();
    $flush_blockquote();
    $flush_code();

    return implode("\n", $html);
}

function markdown_inline($text) {
    $codeMap = [];
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeMap) {
        $key = '__CODE_' . count($codeMap) . '__';
        $codeMap[$key] = '<code>' . h($m[1]) . '</code>';
        return $key;
    }, $text);

    $colorMap = [];
    $text = preg_replace_callback('/<span\s+style="color:\s*(#[0-9A-Fa-f]{6})"\s*>(.*?)<\/span>/su', function ($m) use (&$colorMap) {
        $key = '__COLOR_' . count($colorMap) . '__';
        $color = strtolower($m[1]);
        $inner = markdown_inline($m[2]);
        $colorMap[$key] = '<span style="color:' . h($color) . '">' . $inner . '</span>';
        return $key;
    }, $text);

    $text = h($text);
    $text = preg_replace('~\[(.+?)\]\((https?://[^\s)]+)\)~', '<a href="$2" target="_blank" rel="nofollow noopener">$1</a>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);

    if ($colorMap) {
        $text = strtr($text, $colorMap);
    }

    if ($codeMap) {
        $text = strtr($text, $codeMap);
    }

    return $text;
}
