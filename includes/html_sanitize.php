<?php
// Allowlist-oriented sanitizer for AI-generated page HTML (AUDIT-7 P1-5 + AUDIT-8 P2-4).

/**
 * Normalize HTML for dangerous-URI detection (entities + control chars).
 */
function xlog_html_decode_for_safety($html) {
    $h = html_entity_decode((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Strip C0 controls including tab/newline inside attribute values for protocol checks.
    $h = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $h);
    return $h;
}

/**
 * True if a string looks like a javascript: URI after decode/whitespace strip.
 */
function xlog_is_javascript_uri($value) {
    $v = xlog_html_decode_for_safety($value);
    $v = preg_replace('/\s+/', '', $v);
    $v = strtolower($v);
    return strpos($v, 'javascript:') === 0;
}

/**
 * True if URL is an allowed generated-page image source.
 */
function xlog_is_allowed_site_asset_url($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return false;
    }
    if (strpos($url, 'data:image/') === 0) {
        return true;
    }
    if (strpos($url, 'https://xlog.ink/site-assets/') === 0) {
        return true;
    }
    // Relative site-assets paths used before absolute rewrite.
    if (preg_match('#^/?site-assets/#', $url)) {
        return true;
    }
    return false;
}

/**
 * Strip or neutralize dangerous CSS url()/import targets.
 */
function xlog_sanitize_css_urls($css) {
    $css = (string)$css;
    // Remove @import entirely (external stylesheet load).
    $css = preg_replace('/@import\b[^;]*;?/i', '/* import removed */', $css);
    // Neutralize url(...) that are not data: or xlog site-assets.
    $css = preg_replace_callback(
        '/url\s*\(\s*(["\']?)([^)\'"]+)\1\s*\)/i',
        static function ($m) {
            $u = trim($m[2]);
            $decoded = xlog_html_decode_for_safety($u);
            if (xlog_is_javascript_uri($decoded) || preg_match('#^\s*data:\s*text/html#i', $decoded)) {
                return 'url(#)';
            }
            if (preg_match('#^\s*data:image/#i', $decoded)) {
                return $m[0];
            }
            if (stripos($decoded, 'https://xlog.ink/site-assets/') === 0 || preg_match('#^/?site-assets/#i', $decoded)) {
                return $m[0];
            }
            // Block external http(s) and protocol-relative URLs in CSS.
            if (preg_match('#^\s*(https?:)?//#i', $decoded) || preg_match('#^\s*[a-z]+:#i', $decoded)) {
                return 'url(#)';
            }
            return $m[0];
        },
        $css
    );
    return $css;
}

/**
 * Strip dangerous constructs from a full HTML document.
 * Removes scripts, event handlers, javascript: URLs, iframes, forms, meta refresh,
 * external img/srcset, and style @import/url external targets (AUDIT-8 P2-4).
 */
function xlog_sanitize_generated_html($html) {
    $html = (string)$html;
    if (trim($html) === '') {
        return $html;
    }

    // Fast path rejections / pre-clean with regex before final assert.
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<script\b[^>]*\/?>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*\/?>/is', '', $html);
    $html = preg_replace('/<form\b[^>]*>.*?<\/form>/is', '', $html);
    $html = preg_replace('/<form\b[^>]*\/?>/is', '', $html);
    $html = preg_replace('/<object\b[^>]*>.*?<\/object>/is', '', $html);
    $html = preg_replace('/<embed\b[^>]*\/?>/is', '', $html);
    $html = preg_replace('/<link\b[^>]*>/i', '', $html);
    $html = preg_replace('/<meta\b[^>]*http-equiv\s*=\s*["\']?refresh["\']?[^>]*>/i', '', $html);
    // Event handler attributes
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);

    // Neutralize href/src/action that resolve to javascript: (including entities / whitespace).
    $html = preg_replace_callback(
        '/\s(href|src|action|xlink:href|formaction)\s*=\s*(["\'])(.*?)\2/is',
        static function ($m) {
            $attr = $m[1];
            $q = $m[2];
            $val = $m[3];
            if (xlog_is_javascript_uri($val) || preg_match('/^\s*data:\s*text\/html/i', xlog_html_decode_for_safety($val))) {
                return ' ' . $attr . '=' . $q . '#' . $q;
            }
            if (strtolower($attr) === 'src' && !xlog_is_allowed_site_asset_url($val)
                && !preg_match('#^\s*data:image/#i', $val)
                && preg_match('#^\s*(https?:)?//#i', $val)) {
                return ' ' . $attr . '=' . $q . $q; // drop external src
            }
            return $m[0];
        },
        $html
    );
    // Unquoted javascript: / external src attributes (AUDIT-8 gap).
    $html = preg_replace_callback(
        '/\s(href|src|action)\s*=\s*([^\s>]+)/i',
        static function ($m) {
            $attr = strtolower($m[1]);
            $val = $m[2];
            // Skip already-quoted matches that slipped through (start with quote).
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                return $m[0];
            }
            if (xlog_is_javascript_uri($val)) {
                return ' ' . $m[1] . '="#"';
            }
            if ($attr === 'src' && !xlog_is_allowed_site_asset_url($val)
                && !preg_match('#^\s*data:image/#i', $val)
                && preg_match('#^\s*(https?:)?//#i', $val)) {
                return ' ' . $m[1] . '=""';
            }
            return $m[0];
        },
        $html
    );

    // srcset: drop any external candidate.
    $html = preg_replace_callback(
        '/\ssrcset\s*=\s*(["\'])(.*?)\1/is',
        static function ($m) {
            $q = $m[1];
            $parts = preg_split('/\s*,\s*/', $m[2]);
            $kept = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') continue;
                $url = preg_split('/\s+/', $part)[0];
                if (xlog_is_allowed_site_asset_url($url) || preg_match('#^\s*data:image/#i', $url)) {
                    $kept[] = $part;
                }
            }
            return ' srcset=' . $q . implode(', ', $kept) . $q;
        },
        $html
    );
    // Unquoted srcset
    $html = preg_replace_callback(
        '/\ssrcset\s*=\s*([^\s>]+)/i',
        static function ($m) {
            $val = $m[1];
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                return $m[0];
            }
            if (xlog_is_allowed_site_asset_url($val) || preg_match('#^\s*data:image/#i', $val)) {
                return $m[0];
            }
            return ' srcset=""';
        },
        $html
    );

    // <style> blocks: sanitize CSS urls / @import.
    $html = preg_replace_callback(
        '/(<style\b[^>]*>)(.*?)(<\/style>)/is',
        static function ($m) {
            return $m[1] . xlog_sanitize_css_urls($m[2]) . $m[3];
        },
        $html
    );
    // style="" attributes
    $html = preg_replace_callback(
        '/\sstyle\s*=\s*(["\'])(.*?)\1/is',
        static function ($m) {
            return ' style=' . $m[1] . xlog_sanitize_css_urls($m[2]) . $m[1];
        },
        $html
    );

    return $html;
}

/**
 * Validate sanitized HTML still looks safe.
 * @throws RuntimeException
 */
function xlog_assert_safe_generated_html($html) {
    $check = xlog_html_decode_for_safety($html);
    $checkCompact = preg_replace('/\s+/', '', $check);

    if (preg_match('/<script\b/i', $html) || preg_match('/<script\b/i', $check)) {
        throw new RuntimeException('Scripts are not allowed in generated pages');
    }
    if (preg_match('/\son[a-z]+\s*=/i', $html) || preg_match('/\son[a-z]+\s*=/i', $check)) {
        throw new RuntimeException('Event handler attributes are not allowed');
    }
    if (preg_match('/javascript:/i', $checkCompact)) {
        throw new RuntimeException('javascript: URLs are not allowed');
    }
    if (preg_match('/<iframe\b/i', $html)) {
        throw new RuntimeException('iframes are not allowed');
    }
    if (preg_match('/<form\b/i', $html)) {
        throw new RuntimeException('forms are not allowed');
    }
    if (preg_match('/<meta\b[^>]*http-equiv\s*=\s*["\']?refresh/i', $check)) {
        throw new RuntimeException('meta refresh is not allowed');
    }
    if (preg_match('/<link\b[^>]*\brel=["\']?stylesheet/i', $html)) {
        throw new RuntimeException('External stylesheets are not allowed');
    }
    // Quoted img src
    if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            if (strpos($src, 'data:') === 0) {
                if (strpos($src, 'data:image/') !== 0) {
                    throw new RuntimeException('Only image data: URLs are allowed');
                }
                continue;
            }
            if (!xlog_is_allowed_site_asset_url($src)) {
                throw new RuntimeException('Only xlog.ink site-assets images are allowed');
            }
        }
    }
    // Unquoted img src
    if (preg_match_all('/<img\b[^>]*\bsrc\s*=\s*([^\s>\'"]+)/i', $html, $m2)) {
        foreach ($m2[1] as $src) {
            if (strpos($src, 'data:image/') === 0) continue;
            if (!xlog_is_allowed_site_asset_url($src)) {
                throw new RuntimeException('Only xlog.ink site-assets images are allowed');
            }
        }
    }
    // srcset must not contain external hosts
    if (preg_match_all('/\bsrcset\s*=\s*["\']([^"\']*)["\']/i', $html, $sm)) {
        foreach ($sm[1] as $set) {
            if (trim($set) === '') continue;
            foreach (preg_split('/\s*,\s*/', $set) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                $url = preg_split('/\s+/', $part)[0];
                if (strpos($url, 'data:image/') === 0) continue;
                if (!xlog_is_allowed_site_asset_url($url)) {
                    throw new RuntimeException('External srcset is not allowed');
                }
            }
        }
    }
    // CSS @import / external url in style
    if (preg_match('/@import\b/i', $html)) {
        throw new RuntimeException('CSS @import is not allowed');
    }
    if (preg_match_all('/url\s*\(\s*(["\']?)([^)\'"]+)\1\s*\)/i', $html, $um)) {
        foreach ($um[2] as $u) {
            $decoded = xlog_html_decode_for_safety($u);
            if (preg_match('#^\s*data:image/#i', $decoded)) continue;
            if (xlog_is_allowed_site_asset_url($decoded)) continue;
            // relative path without scheme ok for local CSS
            if (!preg_match('#^\s*(https?:)?//#i', $decoded) && !preg_match('#^\s*[a-z][a-z0-9+.-]*:#i', $decoded)) {
                continue;
            }
            throw new RuntimeException('External CSS url() is not allowed');
        }
    }
}

function xlog_generated_page_csp() {
    // No scripts allowed on generated pages (adult gate is pure CSS/HTML).
    return "default-src 'self'; img-src 'self' data: https://xlog.ink; style-src 'self' 'unsafe-inline'; script-src 'none'; font-src 'self' data:; media-src 'self' https://xlog.ink; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
}
