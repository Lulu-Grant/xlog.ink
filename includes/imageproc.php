<?php
// Upload image normalization to webp.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/content_tools.php';

function image_session_dir($sessionId) {
    return xlog_config('asset_dir') . '/tmp/' . preg_replace('/[^a-f0-9]/', '', $sessionId);
}

function image_public_url($path) {
    return rtrim(xlog_config('base_url'), '/') . $path;
}

function image_process_upload($sessionId, array $file, $caption = '', $slot = '') {
    $caption = trim((string)$caption);
    if (mb_strlen($caption, 'UTF-8') > 200) {
        $caption = mb_substr($caption, 0, 200, 'UTF-8');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Image exceeds 10MB');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) throw new RuntimeException('Invalid image');
    [$width, $height] = $info;
    if ($width > 8000 || $height > 8000) throw new RuntimeException('Image dimensions too large');

    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Unsupported image type');
    }

    $count = db_one('SELECT COUNT(*) AS c FROM images WHERE session_id = ?', [$sessionId]);
    if ((int)$count['c'] >= 8) throw new RuntimeException('Up to 8 images per session');
    $n = (int)$count['c'] + 1;

    $dir = image_session_dir($sessionId);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $out = $dir . '/' . $n . '.webp';

    if (extension_loaded('imagick')) {
        image_with_imagick($file['tmp_name'], $out);
        [$newW, $newH] = getimagesize($out);
    } else {
        [$newW, $newH] = image_with_gd($file['tmp_name'], $mime, $out);
    }

    $rel = '/site-assets/tmp/' . $sessionId . '/' . $n . '.webp';
    $slot = in_array($slot, ['hero', 'avatar', 'product', 'gallery'], true) ? $slot : '';
    $adult = assess_uploaded_image_adult($file, $caption, $out, 'image/webp');
    db_exec(
        'INSERT INTO images (session_id, path, caption, slot, source, adult_score, adult_reason, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$sessionId, $rel, $caption, $slot, 'upload', $adult['score'], $adult['reason'], $newW, $newH, now_iso()]
    );
    return [
        'id' => (int)db()->lastInsertId(),
        'url' => image_public_url($rel),
        'path' => $rel,
        'slot' => $slot,
        'width' => $newW,
        'height' => $newH,
        'adult_score' => $adult['score'],
    ];
}

function image_create_generated_placeholder($sessionId, $prompt, $slot = 'hero') {
    $prompt = mb_substr(trim((string)$prompt), 0, 500, 'UTF-8');
    if ($prompt === '') throw new RuntimeException('Prompt required');
    $slot = in_array($slot, ['hero', 'avatar', 'product', 'gallery'], true) ? $slot : 'hero';
    $dir = image_session_dir($sessionId);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $count = db_one('SELECT COUNT(*) AS c FROM images WHERE session_id = ?', [$sessionId]);
    $n = (int)($count['c'] ?? 0) + 1;
    if ($n > 8) throw new RuntimeException('Up to 8 images per session');
    $out = $dir . '/gen-' . $n . '.webp';
    $rel = '/site-assets/tmp/' . $sessionId . '/gen-' . $n . '.webp';
    if (!ai_has_key('image')) {
        throw new RuntimeException('AI image generation is not configured');
    }
    $generated = ai_generate_image($prompt, [
        'size' => '1024x1024',
        'quality' => 'low',
        'output_format' => 'webp',
    ]);
    if (!is_array($generated)) {
        throw new RuntimeException('AI image generation returned no image');
    }
    [$w, $h] = image_write_generated_bytes($generated['bytes'], $generated['mime'], $out);
    $adult = assess_generated_image_adult($prompt, $out);
    db_exec(
        'INSERT INTO images (session_id, path, caption, slot, source, adult_score, adult_reason, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$sessionId, $rel, $prompt, $slot, 'generated_ai', $adult['score'], $adult['reason'], $w, $h, now_iso()]
    );
    return [
        'id' => (int)db()->lastInsertId(),
        'url' => image_public_url($rel),
        'path' => $rel,
        'slot' => $slot,
        'width' => $w,
        'height' => $h,
        'adult_score' => $adult['score'],
        'provider' => $generated['model'] ?? 'gpt-image',
    ];
}

function image_write_generated_bytes($bytes, $mime, $out) {
    $mime = strtolower((string)$mime);
    if ($mime === 'image/webp') {
        if (file_put_contents($out, $bytes) === false) {
            throw new RuntimeException('Could not save generated image');
        }
    } else {
        $tmp = tempnam(sys_get_temp_dir(), 'xlog-gen-image-');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('Could not save generated image temp file');
        }
        try {
            if (extension_loaded('imagick')) {
                image_with_imagick($tmp, $out);
            } else {
                image_with_gd($tmp, $mime, $out);
            }
        } finally {
            @unlink($tmp);
        }
    }
    $info = @getimagesize($out);
    if (!$info) {
        throw new RuntimeException('Generated image could not be decoded');
    }
    return [(int)$info[0], (int)$info[1]];
}

function assess_generated_image_adult($prompt, $path) {
    $result = adult_keyword_score($prompt);
    $score = (float)$result['score'];
    $reason = $score >= 0.55 ? 'text:' . $result['reason'] : 'generated_image';
    if (ai_has_key('moderation')) {
        try {
            $visual = ai_moderate_image($path, 'image/webp', $prompt);
            if (is_array($visual)) {
                $visualScore = (float)($visual['score'] ?? 0);
                if ($visualScore >= $score) {
                    $score = $visualScore;
                    $reason = 'visual:' . ($visual['reason'] ?? 'moderation');
                } elseif ($score >= 0.55) {
                    $reason .= '; visual:' . ($visual['reason'] ?? 'moderation');
                }
            }
        } catch (Throwable $e) {
            error_log('generated image moderation failed: ' . $e->getMessage());
            $reason = $score >= 0.55
                ? 'text:' . $result['reason'] . '; visual_error_default_non_adult'
                : 'visual_error_default_non_adult:' . mb_substr($e->getMessage(), 0, 120, 'UTF-8');
        }
    }
    return [
        'score' => max(0.0, min(1.0, $score)),
        'reason' => $reason,
    ];
}

function image_with_imagick($src, $out) {
    $im = new Imagick($src);
    if (method_exists($im, 'autoOrient')) $im->autoOrient();
    if ($im->getNumberImages() > 1) $im->setIteratorIndex(0);
    $im->stripImage();
    $w = max(1, $im->getImageWidth());
    $h = max(1, $im->getImageHeight());
    $scale = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    if ($scale < 1) {
        $im->thumbnailImage($nw, $nh, true);
    }
    $im->setImageFormat('webp');
    $im->setImageCompressionQuality(80);
    $im->writeImage($out);
    $im->clear();
}

function image_with_gd($src, $mime, $out) {
    if ($mime === 'image/jpeg') $im = imagecreatefromjpeg($src);
    elseif ($mime === 'image/png') $im = imagecreatefrompng($src);
    elseif ($mime === 'image/webp') $im = imagecreatefromwebp($src);
    else $im = imagecreatefromgif($src);
    if (!$im) throw new RuntimeException('Could not decode image');
    if ($mime === 'image/jpeg') {
        $im = gd_apply_exif_orientation($im, $src);
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1, 1600 / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagewebp($dst, $out, 80);
    if (PHP_VERSION_ID < 80500) {
        @imagedestroy($im);
        @imagedestroy($dst);
    }
    return [$nw, $nh];
}

function gd_apply_exif_orientation($im, $src) {
    if (!function_exists('exif_read_data')) return $im;
    $exif = @exif_read_data($src);
    $orientation = (int)($exif['Orientation'] ?? 1);
    if ($orientation === 2 && function_exists('imageflip')) {
        imageflip($im, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 3) {
        $im = imagerotate($im, 180, 0);
    } elseif ($orientation === 4 && function_exists('imageflip')) {
        imageflip($im, IMG_FLIP_VERTICAL);
    } elseif ($orientation === 5 && function_exists('imageflip')) {
        imageflip($im, IMG_FLIP_HORIZONTAL);
        $im = imagerotate($im, 90, 0);
    } elseif ($orientation === 6) {
        $im = imagerotate($im, -90, 0);
    } elseif ($orientation === 7 && function_exists('imageflip')) {
        imageflip($im, IMG_FLIP_HORIZONTAL);
        $im = imagerotate($im, -90, 0);
    } elseif ($orientation === 8) {
        $im = imagerotate($im, 90, 0);
    }
    return $im;
}

function move_session_assets_to_slug($sessionId, $slug, $html) {
    $tmpDir = image_session_dir($sessionId);
    $finalDir = xlog_config('asset_dir') . '/' . $slug;
    $mappings = [];
    if (is_dir($tmpDir)) {
        if (!is_dir($finalDir)) @mkdir($finalDir, 0755, true);
        $oldBase = rtrim(xlog_config('base_url'), '/') . '/site-assets/tmp/' . $sessionId . '/';
        $newBase = rtrim(xlog_config('base_url'), '/') . '/site-assets/' . $slug . '/';
        foreach (glob($tmpDir . '/*.webp') ?: [] as $file) {
            $basename = basename($file);
            $targetName = $basename;
            if (file_exists($finalDir . '/' . $targetName)) {
                $targetName = substr($sessionId, 0, 8) . '-' . $basename;
            }
            if (!@rename($file, $finalDir . '/' . $targetName)) {
                throw new RuntimeException('Could not move uploaded image asset');
            }
            $oldRel = '/site-assets/tmp/' . $sessionId . '/' . $basename;
            $newRel = '/site-assets/' . $slug . '/' . $targetName;
            $oldAbs = $oldBase . $basename;
            $newAbs = $newBase . $targetName;
            $mappings[$oldAbs] = $newAbs;
            $mappings[$oldRel] = $newRel;
            $html = str_replace([$oldAbs, $oldRel], [$newAbs, $newRel], $html);
            db_exec(
                'UPDATE images SET slug = ?, path = ? WHERE session_id = ? AND path = ?',
                [$slug, $newRel, $sessionId, $oldRel]
            );
        }
        @rmdir($tmpDir);
    }
    if ($mappings) {
        rewrite_session_message_asset_urls($sessionId, $mappings);
    }
    return $html;
}

function rewrite_session_message_asset_urls($sessionId, array $mappings) {
    $messages = session_messages($sessionId);
    if (!is_array($messages) || !$messages) return;
    $changed = false;
    foreach ($messages as &$message) {
        $content = (string)($message['content'] ?? '');
        $updated = str_replace(array_keys($mappings), array_values($mappings), $content);
        if ($updated !== $content) {
            $message['content'] = $updated;
            $changed = true;
        }
    }
    unset($message);
    if ($changed) {
        save_session_messages($sessionId, $messages);
    }
}

function session_images_context($sessionId) {
    $rows = db_all('SELECT path, caption, slot, source, width, height FROM images WHERE session_id = ? ORDER BY id DESC LIMIT 8', [$sessionId]);
    $rows = array_reverse($rows);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'url' => image_public_url($row['path']),
            'description' => $row['caption'],
            'slot' => $row['slot'] ?? '',
            'source' => $row['source'] ?? 'upload',
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
        ];
    }
    return $out;
}
