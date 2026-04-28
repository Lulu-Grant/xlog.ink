<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('line_deduplicator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-6xl space-y-6">
  <div class="tool-panel space-y-5 sm:p-8">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div class="max-w-3xl">
        <div class="tool-badge">
          <?= e(trans('line_tool_badge', 'Text tool')) ?>
        </div>
        <h2 class="tool-hero-title mt-4 sm:text-3xl">
          <?= e(trans('line_deduplicator_heading', 'Remove duplicate lines instantly')) ?>
        </h2>
        <p class="tool-hero-copy mt-3 max-w-2xl">
          <?= e(trans('line_deduplicator_intro', 'Paste text, keep the first occurrence of each line, and clean it up without changing the original order.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 lg:w-auto lg:min-w-[28rem]">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_status_label', 'Status')) ?></div>
          <div id="lineStatus" class="tool-stat-value"><?= e(trans('line_idle', 'Ready to deduplicate lines.')) ?></div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_input_count_label', 'Input lines')) ?></div>
          <div id="lineInputCount" class="tool-stat-value">0</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_output_count_label', 'Output lines')) ?></div>
          <div id="lineOutputCount" class="tool-stat-value">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="tool-panel">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('line_input_label', 'Text input')) ?></h3>
          <p class="tool-copy mt-1"><?= e(trans('line_input_hint', 'Each line is treated as one item.')) ?></p>
        </div>
        <button id="loadSampleBtn" type="button" class="tool-button-secondary shrink-0">
          <?= e(trans('line_load_sample', 'Load sample')) ?>
        </button>
      </div>

      <textarea
        id="lineInput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        placeholder="<?= e(trans('line_input_placeholder', "alpha\nbeta\nalpha\nGamma\n")) ?>"
      ></textarea>

      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <label class="tool-toggle-row">
          <input id="ignoreCase" type="checkbox" class="tool-checkbox" />
          <span><?= e(trans('line_ignore_case', 'Ignore case')) ?></span>
        </label>
        <label class="tool-toggle-row">
          <input id="removeEmpty" type="checkbox" class="tool-checkbox" checked />
          <span><?= e(trans('line_remove_empty', 'Remove empty lines')) ?></span>
        </label>
      </div>

      <div class="mt-4 flex flex-wrap gap-3">
        <button id="dedupeBtn" type="button" class="tool-button-primary"><?= e(trans('line_deduplicate', 'Deduplicate')) ?></button>
        <button id="clearBtn" type="button" class="tool-button-secondary"><?= e(trans('line_clear', 'Clear')) ?></button>
      </div>
    </section>

    <section class="tool-panel">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('line_output_label', 'Deduplicated output')) ?></h3>
          <p class="tool-copy mt-1"><?= e(trans('line_output_hint', 'The first occurrence of each line is preserved.')) ?></p>
        </div>
        <button id="copyBtn" type="button" class="tool-button-secondary shrink-0">
          <?= e(trans('line_copy', 'Copy')) ?>
        </button>
      </div>

      <textarea
        id="lineOutput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        readonly
        placeholder="<?= e(trans('line_output_placeholder', 'Cleaned lines will appear here.')) ?>"
      ></textarea>

      <div id="lineMessage" class="tool-soft-panel tool-help-panel mt-4" role="status" aria-live="polite">
        <?= e(trans('line_idle', 'Ready to deduplicate lines.')) ?>
      </div>
    </section>
  </div>

  <div class="tool-muted-panel tool-help-panel px-5 py-4">
    <?= e(trans('line_note', 'Tip: deduplication keeps the first matching line and removes later duplicates.')) ?>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('lineInput');
    const output = document.getElementById('lineOutput');
    const statusBox = document.getElementById('lineStatus');
    const messageBox = document.getElementById('lineMessage');
    const inputCount = document.getElementById('lineInputCount');
    const outputCount = document.getElementById('lineOutputCount');
    const ignoreCase = document.getElementById('ignoreCase');
    const removeEmpty = document.getElementById('removeEmpty');
    const dedupeBtn = document.getElementById('dedupeBtn');
    const clearBtn = document.getElementById('clearBtn');
    const copyBtn = document.getElementById('copyBtn');
    const loadSampleBtn = document.getElementById('loadSampleBtn');

    if (!input || !output || !statusBox || !messageBox || !inputCount || !outputCount || !ignoreCase || !removeEmpty || !dedupeBtn || !clearBtn || !copyBtn || !loadSampleBtn) {
      return;
    }

    const text = {
      idle: <?= json_encode(trans('line_idle', 'Ready to deduplicate lines.')) ?>,
      empty: <?= json_encode(trans('line_empty_hint', 'Paste text to start.')) ?>,
      done: <?= json_encode(trans('line_done_message', 'Duplicate lines removed.')) ?>,
      copied: <?= json_encode(trans('copied_success', 'Copied to clipboard.')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      cleared: <?= json_encode(trans('cleared_success', 'Cleared.')) ?>,
      nothingToCopy: <?= json_encode(trans('line_copy_empty', 'Nothing to copy yet.')) ?>,
      sample: <?= json_encode("alpha\nbeta\nalpha\nGamma\n\ngamma\nbeta\n") ?>,
      sampleLoaded: <?= json_encode(trans('line_sample_loaded', 'Sample loaded.')) ?>,
    };

    const baseStatusClass = 'tool-stat-value';
    const toneClasses = {
      neutral: 'text-slate-900 dark:text-white',
      success: 'text-emerald-700 dark:text-emerald-300',
      error: 'text-rose-700 dark:text-rose-300',
    };
    const baseMessageClass = 'tool-soft-panel tool-help-panel mt-4';
    const messageToneClasses = {
      neutral: 'bg-slate-50 text-slate-600 dark:bg-slate-800/80 dark:text-slate-300',
      success: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200',
      error: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    };

    function splitLines(value) {
      return value.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
    }

    function updateCounts() {
      inputCount.textContent = String(splitLines(input.value).length);
      outputCount.textContent = String(output.value === '' ? 0 : splitLines(output.value).length);
    }

    function setStatus(message, tone) {
      const resolvedTone = tone || 'neutral';
      statusBox.className = `${baseStatusClass} ${toneClasses[resolvedTone] || toneClasses.neutral}`;
      statusBox.textContent = message;
      messageBox.className = `${baseMessageClass} ${messageToneClasses[resolvedTone] || messageToneClasses.neutral}`;
      messageBox.textContent = message;
    }

    function copyText(value) {
      const temp = document.createElement('textarea');
      temp.value = value;
      temp.setAttribute('readonly', '');
      temp.style.position = 'fixed';
      temp.style.top = '-9999px';
      temp.style.left = '-9999px';
      temp.style.opacity = '0';
      document.body.appendChild(temp);
      temp.focus();
      temp.select();
      temp.setSelectionRange(0, temp.value.length);
      const copied = document.execCommand('copy');
      document.body.removeChild(temp);
      return copied;
    }

    function deduplicateLines() {
      const lines = splitLines(input.value);
      const seen = new Set();
      const result = [];
      const useIgnoreCase = ignoreCase.checked;
      const dropEmpty = removeEmpty.checked;

      for (const originalLine of lines) {
        const line = originalLine;

        if (dropEmpty && line.trim() === '') {
          continue;
        }

        const key = useIgnoreCase ? line.toLowerCase() : line;
        if (seen.has(key)) {
          continue;
        }

        seen.add(key);
        result.push(line);
      }

      output.value = result.join('\n');
      updateCounts();

      if (input.value.trim() === '' && result.length === 0) {
        setStatus(text.empty, 'neutral');
      } else {
        setStatus(text.done, 'success');
      }
    }

    async function copyOutput() {
      const value = output.value.trim() !== '' ? output.value : input.value;

      if (value.trim() === '') {
        setStatus(text.nothingToCopy, 'neutral');
        return;
      }

      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(value);
        } else {
          const copied = copyText(value);
          if (!copied) {
            throw new Error('copy failed');
          }
        }

        setStatus(text.copied, 'success');
      } catch (error) {
        setStatus(text.copyFailed, 'error');
      }
    }

    function clearAll() {
      input.value = '';
      output.value = '';
      updateCounts();
      setStatus(text.cleared, 'neutral');
      input.focus();
    }

    function loadSample() {
      input.value = text.sample;
      updateCounts();
      setStatus(text.sampleLoaded, 'neutral');
      input.focus();
    }

    input.addEventListener('input', updateCounts);
    ignoreCase.addEventListener('change', deduplicateLines);
    removeEmpty.addEventListener('change', deduplicateLines);
    dedupeBtn.addEventListener('click', deduplicateLines);
    clearBtn.addEventListener('click', clearAll);
    copyBtn.addEventListener('click', copyOutput);
    loadSampleBtn.addEventListener('click', loadSample);

    updateCounts();
    setStatus(text.idle, 'neutral');
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
