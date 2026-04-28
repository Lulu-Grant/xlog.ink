<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('uuid_generator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
  <div class="tool-panel">
    <div class="space-y-4">
      <div>
        <h2 class="tool-title-xl"><?= e(trans('uuid_settings_title')) ?></h2>
        <p class="tool-copy"><?= e(trans('uuid_settings_hint')) ?></p>
      </div>

      <label class="tool-label">
        <?= e(trans('uuid_count_label')) ?>
        <input
          id="uuid-count"
          type="number"
          min="1"
          max="50"
          value="5"
          class="tool-control tool-control-sm mt-2"
        />
        <span class="tool-field-hint"><?= e(trans('uuid_count_hint')) ?></span>
      </label>

      <div class="flex flex-wrap gap-3">
        <button id="generate-btn" type="button" class="tool-button-primary"><?= e(trans('uuid_generate')) ?></button>
        <button id="copy-all-btn" type="button" class="tool-button-secondary"><?= e(trans('uuid_copy_all')) ?></button>
        <button id="download-btn" type="button" class="tool-button-secondary"><?= e(trans('uuid_export')) ?></button>
        <button id="clear-btn" type="button" class="tool-button-secondary"><?= e(trans('clear_output')) ?></button>
      </div>

      <div class="tool-soft-panel rounded-2xl px-4 py-3 text-sm">
        <?= e(trans('uuid_tip')) ?>
      </div>
    </div>
  </div>

  <div class="tool-panel">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="tool-title-xl"><?= e(trans('uuid_result_title')) ?></h2>
        <p class="tool-copy"><?= e(trans('uuid_result_hint')) ?></p>
      </div>
      <span id="uuid-count-badge" class="tool-status-pill">0</span>
    </div>

    <div id="uuid-list" class="space-y-3"></div>
  </div>
</section>

<script>
  (function () {
    const countInput = document.getElementById('uuid-count');
    const generateBtn = document.getElementById('generate-btn');
    const copyAllBtn = document.getElementById('copy-all-btn');
    const downloadBtn = document.getElementById('download-btn');
    const clearBtn = document.getElementById('clear-btn');
    const list = document.getElementById('uuid-list');
    const badge = document.getElementById('uuid-count-badge');

    if (!countInput || !generateBtn || !copyAllBtn || !downloadBtn || !clearBtn || !list || !badge) {
      return;
    }

    const messages = {
      copied: <?= json_encode(trans('copied_to_clipboard')) ?>,
      cleared: <?= json_encode(trans('cleared')) ?>,
      generated: <?= json_encode(trans('uuid_generated')) ?>,
      empty: <?= json_encode(trans('uuid_empty')) ?>,
      downloaded: <?= json_encode(trans('uuid_downloaded')) ?>,
      invalidCount: <?= json_encode(trans('uuid_invalid_count')) ?>,
      copyLabel: <?= json_encode(trans('copy')) ?>,
    };

    let currentValues = [];

    function setBadge(count) {
      badge.textContent = String(count);
    }

    function showMessage(message) {
      badge.textContent = message;
      window.setTimeout(() => {
        badge.textContent = String(currentValues.length);
      }, 1600);
    }

    function generateUuid() {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
      }

      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      bytes[6] = (bytes[6] & 0x0f) | 0x40;
      bytes[8] = (bytes[8] & 0x3f) | 0x80;

      const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'));
      return [
        hex.slice(0, 4).join(''),
        hex.slice(4, 6).join(''),
        hex.slice(6, 8).join(''),
        hex.slice(8, 10).join(''),
        hex.slice(10, 16).join(''),
      ].join('-');
    }

    function clampCount(value) {
      const parsed = Number.parseInt(value, 10);
      if (Number.isNaN(parsed)) {
        return null;
      }
      return Math.min(50, Math.max(1, parsed));
    }

    function render() {
      const count = clampCount(countInput.value);
      if (count === null) {
        list.innerHTML = `<div class="tool-alert-error">${messages.invalidCount}</div>`;
        currentValues = [];
        setBadge(0);
        return;
      }

      currentValues = Array.from({ length: count }, generateUuid);
      setBadge(currentValues.length);
      list.innerHTML = currentValues.map((value, index) => `
        <div class="tool-stat-card">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
              <div class="tool-stat-label">#${index + 1}</div>
              <div class="tool-stat-value mt-1 break-all font-mono">${value}</div>
            </div>
            <button type="button" class="uuid-copy-btn tool-button-secondary" data-value="${value}">${messages.copyLabel}</button>
          </div>
        </div>
      `).join('');
      showMessage(messages.generated);
    }

    async function copyText(text) {
      if (!text) {
        return false;
      }
      await navigator.clipboard.writeText(text);
      return true;
    }

    async function copyAll() {
      if (!currentValues.length) {
        showMessage(messages.empty);
        return;
      }
      await copyText(currentValues.join('\n'));
      showMessage(messages.copied);
    }

    function downloadText() {
      if (!currentValues.length) {
        showMessage(messages.empty);
        return;
      }

      const blob = new Blob([currentValues.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'uuid-list.txt';
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      showMessage(messages.downloaded);
    }

    generateBtn.addEventListener('click', render);
    copyAllBtn.addEventListener('click', () => copyAll().catch(() => showMessage(messages.empty)));
    downloadBtn.addEventListener('click', downloadText);
    clearBtn.addEventListener('click', () => {
      currentValues = [];
      list.innerHTML = '';
      setBadge(0);
      countInput.value = '';
      showMessage(messages.cleared);
    });

    list.addEventListener('click', async (event) => {
      const button = event.target.closest('.uuid-copy-btn');
      if (!button) {
        return;
      }

      const value = button.getAttribute('data-value') || '';
      if (!value) {
        showMessage(messages.empty);
        return;
      }

      try {
        await copyText(value);
        showMessage(messages.copied);
      } catch (error) {
        showMessage(messages.empty);
      }
    });

    countInput.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        render();
      }
    });

    render();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
