<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('slug_generator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-white to-slate-50 p-6 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-950">
  <div class="mb-6 max-w-3xl">
    <p class="text-sm uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400"><?= e(trans('slug_generator_ready', 'Ready')) ?></p>
    <p class="mt-3 text-base leading-7 text-slate-600 dark:text-slate-300"><?= e(trans('slug_generator_intro', 'Trim, normalize, and copy a ready-to-use slug in one step.')) ?></p>
  </div>

  <div class="grid gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(260px,0.85fr)]">
    <div class="space-y-4">
      <label for="slugGeneratorInput" class="tool-label">
        <?= e(trans('slug_generator_input_label', 'Text to slugify')) ?>
      </label>
      <textarea
        id="slugGeneratorInput"
        rows="9"
        spellcheck="false"
        autocomplete="off"
        placeholder="<?= e(trans('slug_generator_placeholder', 'Enter a page title, heading, or phrase...')) ?>"
        class="tool-textarea min-h-64 text-base leading-7"
      ></textarea>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="tool-label">
          <span><?= e(trans('slug_generator_separator_label', 'Separator')) ?></span>
          <select id="slugGeneratorSeparator" class="tool-control mt-2">
            <option value="-"><?= e(trans('slug_generator_separator_dash', 'Hyphen (-)')) ?></option>
            <option value="_"><?= e(trans('slug_generator_separator_underscore', 'Underscore (_)')) ?></option>
            <option value="."><?= e(trans('slug_generator_separator_dot', 'Dot (.)')) ?></option>
          </select>
        </label>

        <div class="tool-muted-panel tool-help-panel">
          <div class="tool-note-title"><?= e(trans('slug_generator_helper', 'Accents are folded, punctuation is removed, and letters and numbers from any script are preserved.')) ?></div>
          <p class="mt-2 leading-7"><?= e(trans('slug_generator_empty', 'Type text to generate a slug.')) ?></p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button
          id="slugGeneratorClear"
          type="button"
          class="tool-button-secondary"
        >
          <?= e(trans('slug_generator_clear', 'Clear text')) ?>
        </button>
        <p id="slugGeneratorHint" class="tool-status-text"><?= e(trans('slug_generator_empty', 'Type text to generate a slug.')) ?></p>
      </div>
    </div>

    <div class="space-y-4">
      <div class="tool-kpi-card">
        <div class="flex items-center justify-between gap-4">
          <div class="tool-kpi-label"><?= e(trans('slug_generator_output_label', 'Generated slug')) ?></div>
          <div id="slugGeneratorStatus" class="tool-status-pill"><?= e(trans('slug_generator_ready', 'Ready')) ?></div>
        </div>
        <input
          id="slugGeneratorOutput"
          type="text"
          readonly
          value=""
          placeholder="<?= e(trans('slug_generator_empty', 'Type text to generate a slug.')) ?>"
          class="tool-control tool-control-filled tool-control-mono mt-3"
        />
      </div>

      <div class="flex gap-3">
        <button
          id="slugGeneratorCopy"
          type="button"
          class="tool-button-primary flex-1"
          disabled
        >
          <?= e(trans('slug_generator_copy', 'Copy slug')) ?>
        </button>
      </div>

      <p id="slugGeneratorFeedback" class="tool-status-text min-h-6" aria-live="polite"></p>
    </div>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('slugGeneratorInput');
    const separator = document.getElementById('slugGeneratorSeparator');
    const output = document.getElementById('slugGeneratorOutput');
    const copyButton = document.getElementById('slugGeneratorCopy');
    const clearButton = document.getElementById('slugGeneratorClear');
    const feedback = document.getElementById('slugGeneratorFeedback');
    const status = document.getElementById('slugGeneratorStatus');
    const hint = document.getElementById('slugGeneratorHint');

    if (!input || !separator || !output || !copyButton || !clearButton || !feedback || !status || !hint) {
      return;
    }

    const textCopied = <?= json_encode(trans('slug_generator_copied', 'Copied')) ?>;
    const noCopyText = <?= json_encode(trans('slug_generator_no_copy', 'Nothing to copy yet.')) ?>;
    const emptyText = <?= json_encode(trans('slug_generator_empty', 'Type text to generate a slug.')) ?>;
    const readyText = <?= json_encode(trans('slug_generator_ready', 'Ready')) ?>;

    function escapeRegExp(text) {
      return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function slugify(value, separatorValue) {
      const sep = ['-', '_', '.'].includes(separatorValue) ? separatorValue : '-';
      let text = value.trim();

      if (!text) {
        return '';
      }

      try {
        text = text.normalize('NFKD');
      } catch (error) {}

      text = text.replace(/[\u0300-\u036f]/g, '');
      text = text.replace(/['’`´]/g, '');
      text = text.toLowerCase();
      text = text.replace(/[^\p{L}\p{N}]+/gu, sep);

      const repeated = new RegExp(escapeRegExp(sep) + '{2,}', 'g');
      const trimPattern = new RegExp('^' + escapeRegExp(sep) + '+|' + escapeRegExp(sep) + '+$', 'g');

      return text.replace(repeated, sep).replace(trimPattern, '');
    }

    async function copyText(text) {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }

      const temp = document.createElement('textarea');
      temp.value = text;
      temp.setAttribute('readonly', '');
      temp.style.position = 'fixed';
      temp.style.opacity = '0';
      document.body.appendChild(temp);
      temp.select();
      const copied = document.execCommand('copy');
      document.body.removeChild(temp);
      return copied;
    }

    function updateSlug() {
      const value = slugify(input.value, separator.value);
      output.value = value;
      copyButton.disabled = value === '';

      if (value === '') {
        status.textContent = readyText;
        hint.textContent = emptyText;
      } else {
        status.textContent = readyText;
        hint.textContent = value;
      }

      feedback.textContent = '';
    }

    input.addEventListener('input', updateSlug);
    separator.addEventListener('change', updateSlug);

    clearButton.addEventListener('click', () => {
      input.value = '';
      separator.value = '-';
      updateSlug();
      input.focus();
    });

    copyButton.addEventListener('click', async () => {
      const value = output.value.trim();
      if (!value) {
        feedback.textContent = noCopyText;
        return;
      }

      try {
        const copied = await copyText(value);
        if (copied) {
          feedback.textContent = textCopied;
          status.textContent = textCopied;
        } else {
          feedback.textContent = noCopyText;
        }
      } catch (error) {
        feedback.textContent = noCopyText;
      }
    });

    updateSlug();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
