<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('image_compress'));

function hex_to_rgb(string $hexColor): array
{
    $value = ltrim($hexColor, '#');
    if (strlen($value) === 3) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }

    return [
        'r' => hexdec(substr($value, 0, 2)),
        'g' => hexdec(substr($value, 2, 2)),
        'b' => hexdec(substr($value, 4, 2)),
    ];
}

function create_resized_image($image, ?int $targetWidth, ?int $targetHeight)
{
    if ($targetWidth === null && $targetHeight === null) {
        return $image;
    }

    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    $newWidth = $targetWidth ?: (int) round($sourceWidth * ($targetHeight / $sourceHeight));
    $newHeight = $targetHeight ?: (int) round($sourceHeight * ($targetWidth / $sourceWidth));
    $newWidth = max($newWidth, 1);
    $newHeight = max($newHeight, 1);

    $resized = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

    return $resized;
}

function apply_text_watermark($image, string $text, string $fontPath, string $position, int $fontSize, int $opacity, string $hexColor)
{
    if (!is_file($fontPath) || $text === '') {
        return $image;
    }

    $dimensions = imagettfbbox($fontSize, 0, $fontPath, $text);
    $textWidth = abs($dimensions[4] - $dimensions[0]);
    $textHeight = abs($dimensions[5] - $dimensions[1]);
    $imageWidth = imagesx($image);
    $imageHeight = imagesy($image);
    $padding = 14;
    $rgb = hex_to_rgb($hexColor);
    $alpha = (int) round(127 * (100 - $opacity) / 100);
    $color = imagecolorallocatealpha($image, $rgb['r'], $rgb['g'], $rgb['b'], $alpha);

    switch ($position) {
        case 'top-left':
            $x = $padding;
            $y = $padding + $textHeight;
            break;
        case 'top-right':
            $x = $imageWidth - $textWidth - $padding;
            $y = $padding + $textHeight;
            break;
        case 'bottom-left':
            $x = $padding;
            $y = $imageHeight - $padding;
            break;
        case 'center':
            $x = (int) round(($imageWidth - $textWidth) / 2);
            $y = (int) round(($imageHeight + $textHeight) / 2);
            break;
        case 'bottom-right':
        default:
            $x = $imageWidth - $textWidth - $padding;
            $y = $imageHeight - $padding;
            break;
    }

    imagettftext($image, $fontSize, 0, $x, $y, $color, $fontPath, $text);
    return $image;
}

function save_image_resource($image, string $savePath, string $format, int $quality): bool
{
    switch ($format) {
        case 'jpg':
            imageinterlace($image, true);
            return imagejpeg($image, $savePath, $quality);
        case 'png':
            $pngCompression = (int) round((100 - $quality) / 11.2);
            return imagepng($image, $savePath, max(0, min(9, $pngCompression)));
        case 'webp':
            return imagewebp($image, $savePath, $quality);
        default:
            return false;
    }
}

function human_file_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return round($size, $unitIndex === 0 ? 0 : 2) . ' ' . $units[$unitIndex];
}

maybe_cleanup_runtime_storage(app_config('downloads_ttl'));

$quality = max(10, min(100, (int) ($_POST['quality'] ?? 70)));
$requestedFormat = $_POST['format'] ?? 'jpg';
$format = in_array($requestedFormat, ['jpg', 'png', 'webp'], true) ? $requestedFormat : 'jpg';
$width = isset($_POST['width']) && $_POST['width'] !== '' ? max(1, (int) $_POST['width']) : null;
$height = isset($_POST['height']) && $_POST['height'] !== '' ? max(1, (int) $_POST['height']) : null;
$watermarkText = trim((string) ($_POST['watermark_text'] ?? ''));
$watermarkColor = normalise_hex_color((string) ($_POST['watermark_color'] ?? '#ffffff'));
$allowedPositions = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];
$requestedWatermarkPosition = $_POST['watermark_position'] ?? 'bottom-right';
$watermarkPosition = in_array($requestedWatermarkPosition, $allowedPositions, true)
    ? $requestedWatermarkPosition
    : 'bottom-right';

$results = [];
$errors = [];
$zipDownloadUrl = null;
$statusMessage = trans('image_compress_status_empty', 'Select one or more images to start.');
$statusState = 'idle';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['images']['tmp_name']) || !is_array($_FILES['images']['tmp_name'])) {
        $errors[] = trans('error', 'Failed to process image.') . ' ' . trans('upload_images', 'Upload images') . '.';
    } else {
        $batchDir = ensure_directory(app_runtime_path('downloads/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4))));
        $zipPath = $batchDir . '/compressed-images.zip';
        $zipArchive = new ZipArchive();

        if ($zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $errors[] = trans('zip_creation_error', 'ZIP archive could not be created.');
        } else {
            $fontPath = APP_ROOT . '/assets/fonts/NotoSansCJK-Regular.ttc';
            $fileCount = count($_FILES['images']['tmp_name']);

            if ($fileCount > app_config('max_upload_count')) {
                $errors[] = trans('too_many_files_error', 'Too many files uploaded at once.');
            } else {
                foreach ($_FILES['images']['tmp_name'] as $index => $tmpName) {
                    $originalName = (string) ($_FILES['images']['name'][$index] ?? ('image-' . ($index + 1)));
                    $safeOriginalName = e($originalName);

                    if (!is_uploaded_file($tmpName)) {
                        continue;
                    }

                    $fileSize = filesize($tmpName);
                    if ($fileSize === false || $fileSize > app_config('max_upload_size')) {
                        $errors[] = $safeOriginalName . ': ' . trans('file_too_large_error', 'file is too large.');
                        continue;
                    }

                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if (!image_extension_allowed($extension)) {
                        $errors[] = $safeOriginalName . ': ' . trans('unsupported_format_error', 'unsupported format.');
                        continue;
                    }

                    $imageData = @file_get_contents($tmpName);
                    $image = $imageData !== false ? @imagecreatefromstring($imageData) : false;
                    if ($image === false) {
                        $errors[] = $safeOriginalName . ': ' . trans('invalid_image_error', 'invalid image.');
                        continue;
                    }

                    $resizedImage = create_resized_image($image, $width, $height);
                    if ($resizedImage !== $image) {
                        $image = $resizedImage;
                    }

                    if ($watermarkText !== '') {
                        $image = apply_text_watermark($image, $watermarkText, $fontPath, $watermarkPosition, 16, 72, $watermarkColor);
                    }

                    $downloadName = sprintf('compressed_%s_%02d.%s', sanitize_filename_stem($originalName), $index + 1, $format);
                    $savePath = $batchDir . '/' . $downloadName;

                    if (!save_image_resource($image, $savePath, $format, $quality)) {
                        $errors[] = $safeOriginalName . ': ' . trans('save_compressed_error', 'failed to save compressed image.');
                        continue;
                    }

                    $zipArchive->addFile($savePath, $downloadName);
                    $token = register_download($savePath, $downloadName, image_output_mime($format));
                    $results[] = [
                        'original' => $originalName,
                        'download_name' => $downloadName,
                        'download_url' => download_url($token),
                        'file_size' => filesize($savePath) ?: 0,
                    ];
                }
            }

            $zipArchive->close();
            if ($results !== [] && is_file($zipPath)) {
                $zipToken = register_download($zipPath, basename($zipPath), image_output_mime('zip'));
                $zipDownloadUrl = download_url($zipToken);
            } elseif (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }
}

if ($results !== []) {
    $statusMessage = trans('image_compress_status_done', 'Compression completed.');
    $statusState = 'success';
} elseif ($errors !== []) {
    $statusMessage = trans('image_compress_status_failed', 'Compression failed.');
    $statusState = 'error';
}

include __DIR__ . '/../inc/header.php';
?>

<section class="space-y-6">
  <div class="tool-panel">
    <p class="tool-hero-eyebrow"><?= e(trans('image_compress', 'Image Compression')) ?></p>
    <h2 class="tool-hero-title"><?= e(trans('upload_images', 'Upload images')) ?></h2>
    <p class="tool-hero-copy"><?= e(trans('image-compress_description', 'Compress images online, adjust output size, convert format, and add a simple text watermark.')) ?></p>
  </div>

  <section class="tool-panel">
  <form id="image-compress-form" method="post" enctype="multipart/form-data" class="space-y-6">
    <label class="tool-label">
      <?= e(trans('upload_images', 'Upload images')) ?>
      <input
        id="image-compress-input"
        type="file"
        name="images[]"
        multiple
        required
        accept="image/*"
        class="tool-file-input-hidden"
      />
      <div class="tool-file-picker mt-2">
        <button id="image-compress-trigger" type="button" class="tool-button-secondary"><?= e(trans('image_compress_choose_files', 'Choose images')) ?></button>
        <span id="image-compress-file-name" class="tool-file-picker-name"><?= e(trans('image_compress_no_files', 'No images selected yet.')) ?></span>
      </div>
      <span class="tool-field-hint"><?= e(trans('upload_tip', 'You can select multiple image files.')) ?></span>
    </label>

    <label class="tool-label">
      <?= e(trans('compression_quality', 'Compression quality')) ?>
      <input
        type="range"
        name="quality"
        min="10"
        max="100"
        value="<?= e((string) $quality) ?>"
        step="1"
        class="mt-2 w-full"
        oninput="document.getElementById('quality-value').textContent = this.value"
      />
      <span class="tool-status-text mt-2 block">
        <?= e(trans('quality_selected', 'Selected')) ?>: <strong id="quality-value"><?= e((string) $quality) ?></strong>%
      </span>
    </label>

    <label class="tool-label">
      <?= e(trans('output_format', 'Output format')) ?>
      <select name="format" class="tool-control mt-2">
        <option value="jpg" <?= $format === 'jpg' ? 'selected' : '' ?>>JPG</option>
        <option value="png" <?= $format === 'png' ? 'selected' : '' ?>>PNG</option>
        <option value="webp" <?= $format === 'webp' ? 'selected' : '' ?>>WebP</option>
      </select>
    </label>

    <div class="grid gap-4 md:grid-cols-2">
      <label class="tool-label">
        <?= e(trans('width_optional', 'Width (optional)')) ?>
        <input
          type="number"
          name="width"
          min="1"
          placeholder="<?= e(trans('auto_placeholder', 'Auto')) ?>"
          value="<?= $width === null ? '' : e((string) $width) ?>"
          class="tool-control mt-2"
        />
      </label>

      <label class="tool-label">
        <?= e(trans('height_optional', 'Height (optional)')) ?>
        <input
          type="number"
          name="height"
          min="1"
          placeholder="<?= e(trans('auto_placeholder', 'Auto')) ?>"
          value="<?= $height === null ? '' : e((string) $height) ?>"
          class="tool-control mt-2"
        />
        <span class="tool-field-hint"><?= e(trans('note_aspect_ratio', 'If only width or height is filled, the image will be resized proportionally.')) ?></span>
      </label>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <label class="tool-label">
        <?= e(trans('watermark_text', 'Watermark text (optional)')) ?>
        <input
          type="text"
          name="watermark_text"
          placeholder="<?= e(trans('watermark_placeholder', 'e.g. Tool.GLS.LAT')) ?>"
          value="<?= e($watermarkText) ?>"
          class="tool-control mt-2"
        />
      </label>

      <label class="tool-label">
        <?= e(trans('watermark_color', 'Watermark color (HEX)')) ?>
        <input
          type="text"
          name="watermark_color"
          placeholder="#ffffff"
          value="<?= e($watermarkColor) ?>"
          class="tool-control mt-2"
        />
      </label>
    </div>

    <label class="tool-label">
      <?= e(trans('watermark_position', 'Watermark position')) ?>
      <select name="watermark_position" class="tool-control mt-2">
        <option value="top-left" <?= $watermarkPosition === 'top-left' ? 'selected' : '' ?>><?= e(trans('top_left', 'Top left')) ?></option>
        <option value="top-right" <?= $watermarkPosition === 'top-right' ? 'selected' : '' ?>><?= e(trans('top_right', 'Top right')) ?></option>
        <option value="bottom-left" <?= $watermarkPosition === 'bottom-left' ? 'selected' : '' ?>><?= e(trans('bottom_left', 'Bottom left')) ?></option>
        <option value="bottom-right" <?= $watermarkPosition === 'bottom-right' ? 'selected' : '' ?>><?= e(trans('bottom_right', 'Bottom right')) ?></option>
        <option value="center" <?= $watermarkPosition === 'center' ? 'selected' : '' ?>><?= e(trans('center', 'Center')) ?></option>
      </select>
    </label>

    <div class="flex flex-wrap gap-3">
      <button id="image-compress-submit" type="submit" class="tool-button-primary"><?= e(trans('compress_download', 'Compress & Download')) ?></button>
    </div>

    <div
      id="image-compress-status"
      class="tool-inline-status tool-inline-status-<?= e($statusState) ?>"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      <?= e($statusMessage) ?>
    </div>

    <p class="tool-status-text text-xs"><?= e(trans('privacy_notice', 'We do not store your images longer than needed to generate downloads.')) ?></p>
  </form>

  <?php if ($errors !== []): ?>
    <div class="tool-alert-warning mt-6">
      <?php foreach ($errors as $error): ?>
        <div><?= e($error) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($results !== []): ?>
    <div class="tool-alert-success mt-6">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="tool-title-lg text-emerald-900 dark:text-emerald-100"><?= e(trans('compressed_result', 'Compression result')) ?></h2>
        <?php if ($zipDownloadUrl !== null): ?>
          <a href="<?= e($zipDownloadUrl) ?>" class="tool-button-secondary"><?= e(trans('download_all', 'Download all as ZIP')) ?></a>
        <?php endif; ?>
      </div>

      <div class="mt-4 space-y-3">
        <?php foreach ($results as $result): ?>
          <div class="tool-stat-card flex flex-col gap-2 bg-white/90 dark:bg-slate-900/80 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="tool-stat-value"><?= e($result['original']) ?></div>
              <div class="tool-status-text"><?= e($result['download_name']) ?> • <?= e(human_file_size((int) $result['file_size'])) ?></div>
            </div>
            <a href="<?= e($result['download_url']) ?>" class="tool-button-secondary"><?= e(trans('download_image', 'Download image')) ?></a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  </section>
</section>

<script>
  (function () {
    const form = document.getElementById('image-compress-form');
    const fileInput = document.getElementById('image-compress-input');
    const fileTrigger = document.getElementById('image-compress-trigger');
    const fileName = document.getElementById('image-compress-file-name');
    const submitButton = document.getElementById('image-compress-submit');
    const statusField = document.getElementById('image-compress-status');

    if (!form || !fileInput || !fileTrigger || !fileName || !submitButton || !statusField) {
      return;
    }

    const copy = {
      noFiles: <?= json_encode(trans('image_compress_no_files', 'No images selected yet.')) ?>,
      selectedOne: <?= json_encode(trans('image_compress_selected_one', '1 image selected.')) ?>,
      selectedMany: <?= json_encode(trans('image_compress_selected_many', ':count images selected.')) ?>,
      ready: <?= json_encode(trans('image_compress_status_ready', 'Images are ready to compress.')) ?>,
      empty: <?= json_encode(trans('image_compress_status_empty', 'Select one or more images to start.')) ?>,
      uploading: <?= json_encode(trans('image_compress_status_uploading', 'Images selected, preparing compression.')) ?>,
      compressing: <?= json_encode(trans('image_compress_status_compressing', 'Compressing images...')) ?>,
      done: <?= json_encode(trans('image_compress_status_done', 'Compression completed.')) ?>,
      failed: <?= json_encode(trans('image_compress_status_failed', 'Compression failed.')) ?>,
      submit: <?= json_encode(trans('compress_download', 'Compress & Download')) ?>,
      resultTitle: <?= json_encode(trans('compressed_result', 'Compression result')) ?>,
      successDetail: <?= json_encode($results !== [] ? trans('image_compress_status_done', 'Compression completed.') : '') ?>,
      errorDetail: <?= json_encode($errors !== [] ? $errors[0] : '') ?>,
    };
    const initialState = <?= json_encode($statusState) ?>;
    let lockInitialStatus = initialState === 'success' || initialState === 'error';

    function setStatus(state, text) {
      statusField.className = 'tool-inline-status tool-inline-status-' + state;
      statusField.textContent = text;
    }

    function selectedLabel() {
      const count = fileInput.files ? fileInput.files.length : 0;
      if (count === 0) {
        return copy.noFiles;
      }
      if (count === 1) {
        return fileInput.files[0].name || copy.selectedOne;
      }
      return copy.selectedMany.replace(':count', String(count));
    }

    function syncSelection() {
      fileName.textContent = selectedLabel();
      if (lockInitialStatus) {
        return;
      }
      if (!fileInput.files || fileInput.files.length === 0) {
        setStatus('idle', copy.empty);
      } else {
        setStatus('pending', copy.ready);
      }
    }

    function showToast(state, title, detail) {
      const toast = document.createElement('div');
      toast.className = 'tool-toast tool-toast-' + state;
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');

      const titleNode = document.createElement('strong');
      titleNode.textContent = title;
      toast.appendChild(titleNode);

      if (detail) {
        const detailNode = document.createElement('span');
        detailNode.textContent = detail;
        toast.appendChild(detailNode);
      }

      document.body.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('is-visible'));
      window.setTimeout(() => {
        toast.classList.remove('is-visible');
        window.setTimeout(() => toast.remove(), 220);
      }, 2800);
    }

    fileTrigger.addEventListener('click', function () {
      fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      lockInitialStatus = false;
      if (fileInput.files && fileInput.files.length > 0) {
        setStatus('pending', copy.uploading);
      } else {
        setStatus('idle', copy.empty);
      }
      syncSelection();
    });

    form.addEventListener('submit', function () {
      lockInitialStatus = false;
      submitButton.disabled = true;
      submitButton.textContent = copy.compressing;
      setStatus('pending', copy.compressing);
    });

    syncSelection();

    if (initialState === 'success') {
      showToast('success', copy.resultTitle, copy.successDetail || copy.done);
    } else if (initialState === 'error' && copy.errorDetail) {
      showToast('error', copy.failed, copy.errorDetail);
    }
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
