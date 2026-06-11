<?php
// Upload image normalization to webp.

require_once __DIR__ . '/db.php';

function image_session_dir($sessionId) {
    return xlog_config('asset_dir') . '/tmp/' . preg_replace('/[^a-f0-9]/', '', $sessionId);
}

function image_public_url($path) {
    return rtrim(xlog_config('base_url'), '/') . $path;
}

function image_process_upload($sessionId, array $file, $caption = '') {
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
    db_exec(
        'INSERT INTO images (session_id, path, caption, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$sessionId, $rel, trim((string)$caption), $newW, $newH, now_iso()]
    );
    return [
        'id' => (int)db()->lastInsertId(),
        'url' => image_public_url($rel),
        'path' => $rel,
        'width' => $newW,
        'height' => $newH,
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
    return [$nw, $nh];
}

function move_session_assets_to_slug($sessionId, $slug, $html) {
    $tmpDir = image_session_dir($sessionId);
    $finalDir = xlog_config('asset_dir') . '/' . $slug;
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
            @rename($file, $finalDir . '/' . $targetName);
            $html = str_replace($oldBase . $basename, $newBase . $targetName, $html);
            db_exec(
                'UPDATE images SET slug = ?, path = ? WHERE session_id = ? AND path = ?',
                [$slug, '/site-assets/' . $slug . '/' . $targetName, $sessionId, '/site-assets/tmp/' . $sessionId . '/' . $basename]
            );
        }
        @rmdir($tmpDir);
    }
    return $html;
}

function session_images_context($sessionId) {
    $rows = db_all('SELECT path, caption, width, height FROM images WHERE session_id = ? ORDER BY id ASC', [$sessionId]);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'url' => image_public_url($row['path']),
            'description' => $row['caption'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
        ];
    }
    return $out;
}
