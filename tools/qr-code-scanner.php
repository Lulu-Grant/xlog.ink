<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('qr_code_scanner'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
  <div class="tool-panel space-y-4">
    <div>
      <p class="tool-hero-eyebrow"><?= e(trans('qr_code_scanner', 'QR Code Scanner')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('qr_code_scanner_title', 'Image QR Decoder')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('qr_code_scanner_description', 'Upload an image and decode QR codes from it when the browser supports native BarcodeDetector.')) ?></p>
    </div>

    <div class="tool-alert-warning">
      <?= e(trans('qr_code_scanner_note', 'This page decodes uploaded images only. It does not pretend to support live camera scanning.')) ?>
    </div>

    <label id="qr-dropzone" for="qr-file" class="block cursor-pointer rounded-3xl border-2 border-dashed border-slate-200 bg-white p-6 text-center transition hover:border-indigo-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-sky-400 dark:hover:bg-slate-900">
      <input id="qr-file" type="file" accept="image/*" class="sr-only" />
      <div class="space-y-2">
        <div class="text-lg font-semibold text-slate-900 dark:text-white"><?= e(trans('qr_upload_label', 'Upload image')) ?></div>
        <div class="text-sm text-slate-600 dark:text-slate-300"><?= e(trans('qr_upload_hint', 'Drop or click to choose an image that contains a QR code.')) ?></div>
      </div>
    </label>

    <div class="flex flex-wrap gap-3">
      <button id="qr-clear-btn" type="button" class="tool-button-secondary"><?= e(trans('clear', 'Clear')) ?></button>
      <button id="qr-copy-btn" type="button" class="tool-button-secondary"><?= e(trans('copy', 'Copy')) ?></button>
    </div>

    <div class="tool-inset-panel text-sm leading-7">
      <div class="tool-note-title"><?= e(trans('qr_support_title', 'Browser support status')) ?></div>
      <p id="qr-support-text" class="mt-2"><?= e(trans('qr_support_unknown', 'Checking native QR decoding capability.')) ?></p>
    </div>
  </div>

  <div class="tool-panel space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="tool-hero-eyebrow"><?= e(trans('result', 'Result')) ?></p>
        <h2 class="tool-hero-title"><?= e(trans('qr_result_title', 'Decoded result')) ?></h2>
      </div>
      <span id="qr-status-badge" class="tool-status-pill" aria-live="polite"><?= e(trans('ready', 'Ready')) ?></span>
    </div>

    <div id="qr-preview-wrap" class="tool-preview-panel hidden rounded-3xl p-4">
      <img id="qr-preview" alt="" class="mx-auto max-h-80 rounded-2xl object-contain" />
    </div>

    <div id="qr-empty-state" class="tool-muted-panel tool-help-panel">
      <div class="tool-note-title"><?= e(trans('qr_empty_title', 'Nothing scanned yet')) ?></div>
      <p class="mt-2 leading-7"><?= e(trans('qr_empty_hint', 'Choose an image and the decoded content will appear here if native decoding is supported.')) ?></p>
    </div>

    <div id="qr-results" class="space-y-3"></div>
  </div>
</section>

<script>
  (function () {
    const fileInput = document.getElementById('qr-file');
    const dropZone = document.getElementById('qr-dropzone');
    const clearBtn = document.getElementById('qr-clear-btn');
    const copyBtn = document.getElementById('qr-copy-btn');
    const supportText = document.getElementById('qr-support-text');
    const statusBadge = document.getElementById('qr-status-badge');
    const previewWrap = document.getElementById('qr-preview-wrap');
    const preview = document.getElementById('qr-preview');
    const emptyState = document.getElementById('qr-empty-state');
    const results = document.getElementById('qr-results');

    if (!fileInput || !dropZone || !clearBtn || !copyBtn || !supportText || !statusBadge || !previewWrap || !preview || !emptyState || !results) {
      return;
    }

    const messages = {
      ready: <?= json_encode(trans('ready', 'Ready')) ?>,
      copied: <?= json_encode(trans('copied_to_clipboard', 'Copied to clipboard.')) ?>,
      cleared: <?= json_encode(trans('cleared', 'Cleared.')) ?>,
      scanning: <?= json_encode(trans('qr_scanning', 'Decoding image...')) ?>,
      unsupported: <?= json_encode(trans('qr_unsupported', 'This browser does not expose native BarcodeDetector, so uploaded-image QR decoding is unavailable.')) ?>,
      noResult: <?= json_encode(trans('qr_no_result', 'No QR code detected.')) ?>,
      error: <?= json_encode(trans('qr_error', 'Failed to decode the image.')) ?>,
      multiple: <?= json_encode(trans('qr_multiple', 'Multiple barcodes detected.')) ?>,
      oneResult: <?= json_encode(trans('qr_one_result', '1 result found.')) ?>,
      copyEmpty: <?= json_encode(trans('qr_copy_empty', 'Nothing to copy.')) ?>,
    };

    function toggleDropZone(active) {
      dropZone.classList.toggle('border-indigo-400', active);
      dropZone.classList.toggle('bg-slate-50', active);
      dropZone.classList.toggle('dark:border-sky-400', active);
      dropZone.classList.toggle('dark:bg-slate-900', active);
    }

    function pickFile(file) {
      if (!file || !String(file.type || '').startsWith('image/')) {
        setStatus(messages.error);
        return;
      }

      void decodeFile(file);
    }

    let currentValues = [];
    let currentPreviewUrl = '';
    let detector = null;

    function setStatus(message) {
      statusBadge.textContent = message;
    }

    function setSupport(message) {
      supportText.textContent = message;
    }

    function copyText(text) {
      if (!text) {
        return Promise.reject(new Error('empty'));
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
      }

      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      const copied = document.execCommand('copy');
      textarea.remove();
      return copied ? Promise.resolve() : Promise.reject(new Error('copy-failed'));
    }

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function renderResults(items) {
      if (!items.length) {
        results.innerHTML = '';
        emptyState.classList.remove('hidden');
        return;
      }

      emptyState.classList.add('hidden');
      results.innerHTML = items.map((item, index) => `
        <div class="tool-stat-card">
          <div class="mb-2 flex items-center justify-between gap-3">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">#${index + 1}</div>
            <button type="button" class="qr-copy-item tool-button-secondary" data-value="${escapeHtml(item.rawValue)}">${<?= json_encode(trans('copy', 'Copy')) ?>}</button>
          </div>
          <div class="tool-inset-panel break-all font-mono text-sm text-slate-900 dark:text-slate-100">${escapeHtml(item.rawValue)}</div>
          <div class="mt-3 text-xs text-slate-500 dark:text-slate-400">${escapeHtml(item.format || 'QR_CODE')}</div>
        </div>
      `).join('');
    }

    function updatePreview(file) {
      if (currentPreviewUrl) {
        URL.revokeObjectURL(currentPreviewUrl);
        currentPreviewUrl = '';
      }

      currentPreviewUrl = URL.createObjectURL(file);
      preview.src = currentPreviewUrl;
      preview.alt = file.name || 'QR preview';
      previewWrap.classList.remove('hidden');
    }

    async function getDetector() {
      if (!('BarcodeDetector' in window)) {
        return null;
      }

      if (detector) {
        return detector;
      }

      if (typeof window.BarcodeDetector.getSupportedFormats === 'function') {
        try {
          const formats = await window.BarcodeDetector.getSupportedFormats();
          if (Array.isArray(formats) && !formats.includes('qr_code')) {
            return null;
          }
        } catch (error) {
          // ignore and fall through to a best-effort detector
        }
      }

      try {
        detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        return detector;
      } catch (error) {
        return null;
      }
    }

    async function decodeFile(file) {
      setStatus(messages.scanning);
      renderResults([]);
      emptyState.classList.add('hidden');
      updatePreview(file);

      const nativeDetector = await getDetector();
      if (!nativeDetector) {
        setSupport(messages.unsupported);
        setStatus(messages.ready);
        results.innerHTML = `<div class="tool-alert-warning">${messages.unsupported}</div>`;
        return;
      }

      setSupport(<?= json_encode(trans('qr_support_native', 'The browser supports native QR decoding.')) ?>);

      try {
        let imageSource = null;
        if (typeof window.createImageBitmap === 'function') {
          imageSource = await window.createImageBitmap(file);
        } else {
          imageSource = await new Promise((resolve, reject) => {
            const img = new Image();
            const tempUrl = URL.createObjectURL(file);
            img.onload = () => {
              URL.revokeObjectURL(tempUrl);
              resolve(img);
            };
            img.onerror = () => {
              URL.revokeObjectURL(tempUrl);
              reject(new Error('load-failed'));
            };
            img.src = tempUrl;
          });
        }

        const codes = await nativeDetector.detect(imageSource);
        if (imageSource && typeof imageSource.close === 'function') {
          imageSource.close();
        }

        if (!codes || codes.length === 0) {
          setStatus(messages.noResult);
          results.innerHTML = `<div class="tool-muted-panel tool-help-panel">${messages.noResult}</div>`;
          currentValues = [];
          return;
        }

        currentValues = codes.map((code) => code.rawValue || '').filter(Boolean);
        setStatus(codes.length > 1 ? messages.multiple : messages.oneResult);
        renderResults(codes);
      } catch (error) {
        setStatus(messages.error);
        results.innerHTML = `<div class="tool-alert-error">${messages.error}</div>`;
        currentValues = [];
      }
    }

    fileInput.addEventListener('change', async (event) => {
      const file = event.target.files && event.target.files[0];
      pickFile(file || null);
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
      dropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        toggleDropZone(true);
      });
    });

    ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
      dropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        toggleDropZone(false);
      });
    });

    dropZone.addEventListener('drop', (event) => {
      const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
      pickFile(file || null);
    });

    clearBtn.addEventListener('click', () => {
      fileInput.value = '';
      currentValues = [];
      results.innerHTML = '';
      preview.src = '';
      preview.alt = '';
      previewWrap.classList.add('hidden');
      emptyState.classList.remove('hidden');
      setStatus(messages.cleared);
      setSupport(<?= json_encode(trans('qr_support_unknown', 'Checking native QR decoding capability.')) ?>);
    });

    copyBtn.addEventListener('click', () => {
      if (!currentValues.length) {
        setStatus(messages.copyEmpty);
        return;
      }

      copyText(currentValues.join('\n'))
        .then(() => setStatus(messages.copied))
        .catch(() => setStatus(messages.copyEmpty));
    });

    results.addEventListener('click', async (event) => {
      const button = event.target.closest('.qr-copy-item');
      if (!button) {
        return;
      }

      const value = button.getAttribute('data-value') || '';
      try {
        await copyText(value);
        setStatus(messages.copied);
      } catch (error) {
        setStatus(messages.copyEmpty);
      }
    });

    if (!('BarcodeDetector' in window)) {
      setSupport(messages.unsupported);
      results.innerHTML = `<div class="tool-alert-warning">${messages.unsupported}</div>`;
    } else {
      setSupport(<?= json_encode(trans('qr_support_native', 'The browser supports native QR decoding.')) ?>);
    }
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
