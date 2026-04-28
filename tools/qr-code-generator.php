<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('qr_code_generator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="space-y-6">
  <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
    <section class="tool-panel">
      <div class="space-y-5">
        <div>
          <h2 class="tool-title-lg">
            <?= e(trans('qr_input_title', 'Content')) ?>
          </h2>
          <p class="tool-copy">
            <?= e(trans('qr_input_hint', 'Enter the text or URL you want to encode.')) ?>
          </p>
        </div>

        <label class="tool-label">
          <?= e(trans('qr_content_label', 'QR content')) ?>
          <textarea
            id="qrContent"
            rows="6"
            class="tool-control tool-control-mono mt-2 placeholder:text-slate-400"
            placeholder="<?= e(trans('qr_content_placeholder', 'https://example.com')) ?>"
          ><?= e(trans('qr_content_sample', '猫柠咔百宝箱')) ?></textarea>
        </label>

        <div class="grid gap-4 sm:grid-cols-3">
          <label class="tool-label">
            <?= e(trans('qr_size_label', 'Size')) ?>
            <select id="qrSize" class="tool-control tool-control-sm mt-2">
              <option value="small"><?= e(trans('qr_size_small', 'Small')) ?></option>
              <option value="medium" selected><?= e(trans('qr_size_medium', 'Medium')) ?></option>
              <option value="large"><?= e(trans('qr_size_large', 'Large')) ?></option>
            </select>
          </label>

          <label class="tool-label">
            <?= e(trans('qr_foreground_label', 'Foreground')) ?>
            <input
              id="qrForeground"
              type="color"
              value="#111827"
              class="tool-color-input mt-2"
            />
          </label>

          <label class="tool-label">
            <?= e(trans('qr_background_label', 'Background')) ?>
            <input
              id="qrBackground"
              type="color"
              value="#ffffff"
              class="tool-color-input mt-2"
            />
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="tool-label">
            <?= e(trans('qr_error_level_label', 'Error correction')) ?>
            <select id="qrErrorLevel" class="tool-control tool-control-sm mt-2">
              <option value="M" selected><?= e(trans('qr_error_level_m', 'Medium (M)')) ?></option>
              <option value="L"><?= e(trans('qr_error_level_l', 'Low (L)')) ?></option>
              <option value="Q"><?= e(trans('qr_error_level_q', 'Quartile (Q)')) ?></option>
              <option value="H"><?= e(trans('qr_error_level_h', 'High (H)')) ?></option>
            </select>
          </label>

          <label class="tool-label">
            <?= e(trans('qr_margin_label', 'Quiet zone')) ?>
            <select id="qrMargin" class="tool-control tool-control-sm mt-2">
              <option value="default" selected><?= e(trans('qr_margin_default', 'Default')) ?></option>
              <option value="small"><?= e(trans('qr_margin_small', 'Small')) ?></option>
              <option value="large"><?= e(trans('qr_margin_large', 'Large')) ?></option>
            </select>
          </label>
        </div>

        <div class="flex flex-wrap gap-3">
          <button id="qrGenerateBtn" type="button" class="tool-button-primary">
            <?= e(trans('qr_generate_button', 'Generate QR')) ?>
          </button>
          <button id="qrDownloadBtn" type="button" class="tool-button-secondary">
            <?= e(trans('qr_download_button', 'Download SVG')) ?>
          </button>
        </div>
        <p id="qrStatus" class="tool-status-text" aria-live="polite"><?= e(trans('qr_status_ready', 'Ready to generate a QR code.')) ?></p>
      </div>
    </section>

    <aside class="space-y-4">
      <section class="tool-panel">
        <h2 class="tool-title-lg">
          <?= e(trans('qr_preview_title', 'Preview')) ?>
        </h2>
        <div id="qrPreview" class="tool-preview-panel mt-4 flex min-h-[22rem] items-center justify-center rounded-3xl p-6 text-center">
          <div class="max-w-sm space-y-3">
            <div class="tool-preview-glyph">
              ◻
            </div>
            <p class="tool-note-title text-sm">
              <?= e(trans('qr_preview_placeholder', 'QR preview will appear here after generation.')) ?>
            </p>
            <p class="tool-copy leading-6">
              <?= e(trans('qr_preview_note', 'Choose your content, colors, and size, then generate an SVG QR code locally.')) ?>
            </p>
          </div>
        </div>
      </section>

      <section class="tool-panel">
        <h2 class="tool-title-lg">
          <?= e(trans('qr_usage_title', 'Suggested uses')) ?>
        </h2>
        <ul class="tool-copy-list space-y-3">
          <li><?= e(trans('qr_usage_one', 'Share a link, Wi-Fi password, contact card, or short note.')) ?></li>
          <li><?= e(trans('qr_usage_two', 'Keep the interface lightweight so it works well on mobile and desktop.')) ?></li>
          <li><?= e(trans('qr_usage_three', 'Connect a local encoder later without changing the page structure.')) ?></li>
        </ul>
      </section>
    </aside>
  </div>
</section>

<script src="/assets/vendor/qrcode.js"></script>
<script>
  (function () {
    const contentField = document.getElementById('qrContent');
    const sizeSelect = document.getElementById('qrSize');
    const levelSelect = document.getElementById('qrErrorLevel');
    const marginSelect = document.getElementById('qrMargin');
    const foregroundField = document.getElementById('qrForeground');
    const backgroundField = document.getElementById('qrBackground');
    const generateButton = document.getElementById('qrGenerateBtn');
    const downloadButton = document.getElementById('qrDownloadBtn');
    const preview = document.getElementById('qrPreview');
    const status = document.getElementById('qrStatus');

    if (!contentField || !sizeSelect || !levelSelect || !marginSelect || !foregroundField || !backgroundField || !generateButton || !downloadButton || !preview || !status || typeof qrcode !== 'function') {
      return;
    }
    let latestSvg = '';

    const messages = {
      ready: <?= json_encode(trans('qr_status_ready', 'Ready to generate a QR code.')) ?>,
      empty: <?= json_encode(trans('qr_status_empty', 'Enter text or a link first.')) ?>,
      generated: <?= json_encode(trans('qr_status_generated', 'QR code generated.')) ?>,
      failed: <?= json_encode(trans('qr_status_failed', 'QR generation failed. Try shorter content or a lower error-correction level.')) ?>,
      downloaded: <?= json_encode(trans('qr_status_downloaded', 'SVG download started.')) ?>,
      noDownload: <?= json_encode(trans('qr_status_no_download', 'Generate a QR code before downloading.')) ?>,
    };

    function sizeValue() {
      const map = { small: 4, medium: 6, large: 8 };
      return map[sizeSelect.value] || 6;
    }

    function marginValue() {
      const map = { default: 4, small: 2, large: 8 };
      return map[marginSelect.value] || 4;
    }

    function levelValue() {
      return String(levelSelect.value || 'M');
    }

    function setStatus(message) {
      status.textContent = message;
    }

    function buildQrSvg() {
      const value = contentField.value.trim();
      if (!value) {
        latestSvg = '';
        setStatus(messages.empty);
        return;
      }

      try {
        const qr = qrcode(0, levelValue());
        qr.addData(value, 'Byte');
        qr.make();

        const rawSvg = qr.createSvgTag(sizeValue(), marginValue());
        const parser = new DOMParser();
        const doc = parser.parseFromString(rawSvg, 'image/svg+xml');
        const svg = doc.documentElement;
        const path = svg.querySelector('path:last-of-type');

        if (svg && path) {
          svg.setAttribute('role', 'img');
          svg.setAttribute('aria-label', value);
          svg.style.maxWidth = '100%';
          svg.style.height = 'auto';
          svg.style.display = 'block';
          svg.style.backgroundColor = backgroundField.value;
          path.setAttribute('fill', foregroundField.value);
          latestSvg = new XMLSerializer().serializeToString(svg);
          preview.innerHTML = latestSvg;
          setStatus(messages.generated);
          return;
        }

        throw new Error('SVG generation failed');
      } catch (error) {
        latestSvg = '';
        preview.innerHTML = `<div class="max-w-sm space-y-3"><div class="mx-auto flex h-24 w-24 items-center justify-center rounded-3xl border border-rose-200 bg-rose-50 text-4xl text-rose-500 dark:border-rose-900/50 dark:bg-rose-950/40">!</div><p class="text-sm font-medium text-rose-700 dark:text-rose-200"><?= e(trans('qr_error_preview', 'Unable to generate a QR code with the current settings.')) ?></p></div>`;
        setStatus(messages.failed);
      }
    }

    function downloadSvg() {
      if (!latestSvg) {
        setStatus(messages.noDownload);
        return;
      }

      const blob = new Blob([latestSvg], { type: 'image/svg+xml;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'qr-code.svg';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      setStatus(messages.downloaded);
    }

    generateButton.addEventListener('click', buildQrSvg);
    downloadButton.addEventListener('click', downloadSvg);
    contentField.addEventListener('input', buildQrSvg);
    sizeSelect.addEventListener('change', buildQrSvg);
    levelSelect.addEventListener('change', buildQrSvg);
    marginSelect.addEventListener('change', buildQrSvg);
    foregroundField.addEventListener('input', buildQrSvg);
    backgroundField.addEventListener('input', buildQrSvg);

    buildQrSvg();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
