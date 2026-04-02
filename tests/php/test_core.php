<?php

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/i18n.php';
require_once dirname(__DIR__, 2) . '/includes/response.php';
require_once dirname(__DIR__, 2) . '/includes/turnstile.php';
require_once dirname(__DIR__, 2) . '/includes/markdown.php';

assert_same('zh-TW', validate_lang('zh-TW'), 'validate_lang should accept supported language');
assert_same('zh-CN', validate_lang('fr'), 'validate_lang should fall back to zh-CN');

assert_same('dark', validate_theme('dark'), 'validate_theme should accept dark');
assert_same('light', validate_theme('anything-else'), 'validate_theme should fall back to light');

assert_same('abc', clamp_len_u('abcdef', 3), 'clamp_len_u should trim ASCII strings');
assert_same('你好', clamp_len_u('你好世界', 2), 'clamp_len_u should trim multibyte strings');

assert_same('Title text bold', markdown_excerpt("# Title\ntext **bold**", 40), 'markdown_excerpt should flatten simple markdown');
assert_same('', markdown_excerpt("   \n   ", 40), 'markdown_excerpt should return empty string for blank content');

$tmpDir = sys_get_temp_dir() . '/xlog-test-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);
$slugInfo = generate_slug($tmpDir);
assert_matches('/^[a-z0-9]{10}$/', $slugInfo['slug'], 'generate_slug should create a 10-character slug');
assert_true(str_ends_with($slugInfo['file'], '.html'), 'generate_slug should produce an html filename');
assert_same($tmpDir . '/' . $slugInfo['file'], $slugInfo['path'], 'generate_slug path should match output directory');
rmdir($tmpDir);

putenv('TURNSTILE_SECRET_KEY');
$turnstileResult = turnstile_verify('dummy-token', '127.0.0.1');
assert_same(false, $turnstileResult['ok'], 'turnstile_verify should fail when secret is not configured');
assert_same('turnstile-secret-not-configured', $turnstileResult['reason'], 'turnstile_verify should expose the missing-secret reason');

$markdownHtml = markdown_to_html("# Title\n\nParagraph with **bold** and [link](https://example.com).\n\n- One\n- Two\n\n> Quote");
assert_true(str_contains($markdownHtml, '<h1>Title</h1>'), 'markdown_to_html should render headings');
assert_true(str_contains($markdownHtml, '<strong>bold</strong>'), 'markdown_to_html should render strong text');
assert_true(str_contains($markdownHtml, '<a href="https://example.com" target="_blank" rel="nofollow noopener">link</a>'), 'markdown_to_html should render links');
assert_true(str_contains($markdownHtml, '<ul><li>One</li><li>Two</li></ul>'), 'markdown_to_html should render unordered lists');
assert_true(str_contains($markdownHtml, '<blockquote><p>Quote</p></blockquote>'), 'markdown_to_html should render blockquotes');
assert_true(str_contains(markdown_to_html('<script>alert(1)</script>'), '&lt;script&gt;alert(1)&lt;/script&gt;'), 'markdown_to_html should escape raw HTML');

fwrite(STDOUT, "PHP tests passed\n");
