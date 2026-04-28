<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('line_sorter'));

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
          <?= e(trans('line_sorter_heading', 'Sort lines your way')) ?>
        </h2>
        <p class="tool-hero-copy mt-3 max-w-2xl">
          <?= e(trans('line_sorter_intro', 'Sort text alphabetically or numerically while keeping the workflow fast and simple.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 lg:w-auto lg:min-w-[28rem]">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_status_label', 'Status')) ?></div>
          <div id="sortStatus" class="tool-stat-value"><?= e(trans('line_idle', 'Ready to sort lines.')) ?></div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_input_count_label', 'Input lines')) ?></div>
          <div id="sortInputCount" class="tool-stat-value">0</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('line_output_count_label', 'Output lines')) ?></div>
          <div id="sortOutputCount" class="tool-stat-value">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="tool-panel">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('line_input_label', 'Text input')) ?></h3>
          <p class="tool-copy mt-1"><?= e(trans('line_input_hint', 'Each line is sorted independently.')) ?></p>
        </div>
        <button id="loadSampleBtn" type="button" class="tool-button-secondary shrink-0">
          <?= e(trans('line_load_sample', 'Load sample')) ?>
        </button>
      </div>

      <textarea
        id="sortInput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        placeholder="<?= e(trans('line_input_placeholder', "delta\n10\n2\nalpha\nBravo\n")) ?>"
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

      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <label class="tool-label">
          <?= e(trans('line_sort_mode', 'Sort mode')) ?>
          <select id="sortMode" class="tool-control mt-2">
            <option value="alpha"><?= e(trans('line_sort_alpha', 'Alphabetical')) ?></option>
            <option value="numeric"><?= e(trans('line_sort_numeric', 'Numeric')) ?></option>
          </select>
        </label>
        <label class="tool-label">
          <?= e(trans('line_sort_order', 'Sort order')) ?>
          <select id="sortOrder" class="tool-control mt-2">
            <option value="asc"><?= e(trans('line_sort_asc', 'Ascending')) ?></option>
            <option value="desc"><?= e(trans('line_sort_desc', 'Descending')) ?></option>
          </select>
        </label>
      </div>

      <div class="mt-4 flex flex-wrap gap-3">
        <button id="sortBtn" type="button" class="tool-button-primary"><?= e(trans('line_sort', 'Sort')) ?></button>
        <button id="clearBtn" type="button" class="tool-button-secondary"><?= e(trans('line_clear', 'Clear')) ?></button>
      </div>
    </section>

    <section class="tool-panel">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('line_output_label', 'Sorted output')) ?></h3>
          <p class="tool-copy mt-1"><?= e(trans('line_output_hint', 'The result appears here and keeps the selected order.')) ?></p>
        </div>
        <button id="copyBtn" type="button" class="tool-button-secondary shrink-0">
          <?= e(trans('line_copy', 'Copy')) ?>
        </button>
      </div>

      <textarea
        id="sortOutput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        readonly
        placeholder="<?= e(trans('line_output_placeholder', 'Sorted lines will appear here.')) ?>"
      ></textarea>

      <div id="sortMessage" class="tool-soft-panel tool-help-panel mt-4" role="status" aria-live="polite">
        <?= e(trans('line_idle', 'Ready to sort lines.')) ?>
      </div>
    </section>
  </div>

  <div class="tool-muted-panel tool-help-panel px-5 py-4">
    <?= e(trans('line_note_sort', 'Tip: numeric sorting compares line values as numbers when possible, then falls back to text.')) ?>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('sortInput');
    const output = document.getElementById('sortOutput');
    const statusBox = document.getElementById('sortStatus');
    const messageBox = document.getElementById('sortMessage');
    const inputCount = document.getElementById('sortInputCount');
    const outputCount = document.getElementById('sortOutputCount');
    const ignoreCase = document.getElementById('ignoreCase');
    const removeEmpty = document.getElementById('removeEmpty');
    const sortMode = document.getElementById('sortMode');
    const sortOrder = document.getElementById('sortOrder');
    const sortBtn = document.getElementById('sortBtn');
    const clearBtn = document.getElementById('clearBtn');
    const copyBtn = document.getElementById('copyBtn');
    const loadSampleBtn = document.getElementById('loadSampleBtn');

    if (!input || !output || !statusBox || !messageBox || !inputCount || !outputCount || !ignoreCase || !removeEmpty || !sortMode || !sortOrder || !sortBtn || !clearBtn || !copyBtn || !loadSampleBtn) {
      return;
    }

    const text = {
      idle: <?= json_encode(trans('line_idle', 'Ready to sort lines.')) ?>,
      empty: <?= json_encode(trans('line_empty_hint', 'Paste text to start.')) ?>,
      done: <?= json_encode(trans('line_done_sort_message', 'Lines sorted.')) ?>,
      copied: <?= json_encode(trans('copied_success', 'Copied to clipboard.')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      cleared: <?= json_encode(trans('cleared_success', 'Cleared.')) ?>,
      nothingToCopy: <?= json_encode(trans('line_copy_empty', 'Nothing to copy yet.')) ?>,
      sample: <?= json_encode("delta\n10\n2\nalpha\nBravo\n\n7\n100\n") ?>,
      sampleLoaded: <?= json_encode(trans('line_sample_loaded', 'Sample loaded.')) ?>,
    };

    const baseStatusClass = 'tool-stat-value';
    const toneClasses = {
      neutral: 'text-slate-900 dark:text-white',
      success: 'text-sky-700 dark:text-sky-300',
      error: 'text-rose-700 dark:text-rose-300',
    };
    const baseMessageClass = 'tool-soft-panel tool-help-panel mt-4';
    const messageToneClasses = {
      neutral: 'bg-slate-50 text-slate-600 dark:bg-slate-800/80 dark:text-slate-300',
      success: 'bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-200',
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

    function getComparableLine(line) {
      return ignoreCase.checked ? line.toLowerCase() : line;
    }

    function sortLines() {
      const lines = splitLines(input.value);
      const dropEmpty = removeEmpty.checked;
      const mode = sortMode.value;
      const order = sortOrder.value;

      const filtered = [];
      for (const line of lines) {
        if (dropEmpty && line.trim() === '') {
          continue;
        }
        filtered.push(line);
      }

      filtered.sort((left, right) => {
        if (mode === 'numeric') {
          const leftNumber = Number.parseFloat(left.trim());
          const rightNumber = Number.parseFloat(right.trim());
          const leftIsNumber = Number.isFinite(leftNumber);
          const rightIsNumber = Number.isFinite(rightNumber);

          if (leftIsNumber && rightIsNumber && leftNumber !== rightNumber) {
            return leftNumber - rightNumber;
          }

          if (leftIsNumber !== rightIsNumber) {
            return leftIsNumber ? -1 : 1;
          }
        }

        const leftValue = getComparableLine(left);
        const rightValue = getComparableLine(right);
        const compareResult = leftValue.localeCompare(rightValue, undefined, {
          numeric: mode === 'numeric',
          sensitivity: ignoreCase.checked ? 'accent' : 'variant',
        });

        if (compareResult !== 0) {
          return compareResult;
        }

        return left.localeCompare(right);
      });

      if (order === 'desc') {
        filtered.reverse();
      }

      output.value = filtered.join('\n');
      updateCounts();

      if (input.value.trim() === '' && filtered.length === 0) {
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
    ignoreCase.addEventListener('change', sortLines);
    removeEmpty.addEventListener('change', sortLines);
    sortMode.addEventListener('change', sortLines);
    sortOrder.addEventListener('change', sortLines);
    sortBtn.addEventListener('click', sortLines);
    clearBtn.addEventListener('click', clearAll);
    copyBtn.addEventListener('click', copyOutput);
    loadSampleBtn.addEventListener('click', loadSample);

    updateCounts();
    setStatus(text.idle, 'neutral');
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
