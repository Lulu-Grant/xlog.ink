<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('json_formatter'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-6xl space-y-6">
  <div class="tool-panel sm:p-8">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div class="max-w-3xl">
        <div class="tool-badge">
          <?= e(trans('json_formatter_badge', 'Developer tool')) ?>
        </div>
        <h2 class="tool-hero-title sm:text-3xl">
          <?= e(trans('json_formatter_heading', 'Format, validate, and minify JSON')) ?>
        </h2>
        <p class="tool-hero-copy">
          <?= e(trans('json_formatter_intro', 'Paste JSON, check it for errors, then format or minify it instantly in your browser.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 lg:w-auto lg:min-w-[28rem]">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('json_status_label', 'Status')) ?></div>
          <div id="jsonStatus" class="tool-stat-value"><?= e(trans('json_idle', 'Ready to parse JSON.')) ?></div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('json_input_count_label', 'Input chars')) ?></div>
          <div id="jsonInputCount" class="tool-stat-value">0</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('json_output_count_label', 'Output chars')) ?></div>
          <div id="jsonOutputCount" class="tool-stat-value">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="tool-panel-compact">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('json_input_label', 'JSON input')) ?></h3>
          <p class="tool-copy"><?= e(trans('json_input_hint', 'Paste an object or array here.')) ?></p>
        </div>
        <button id="loadSampleBtn" type="button" class="tool-button-primary shrink-0">
          <?= e(trans('json_load_sample', 'Load sample')) ?>
        </button>
      </div>

      <textarea
        id="jsonInput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        placeholder="<?= e(trans('json_input_placeholder', "{\n  \"name\": \"MaoNingKa\",\n  \"tools\": [\"json\", \"url\"]\n}")) ?>"
      ></textarea>

      <div class="mt-4 flex flex-wrap gap-3">
        <button id="formatBtn" type="button" class="tool-button-primary"><?= e(trans('json_format', 'Format')) ?></button>
        <button id="validateBtn" type="button" class="tool-button-primary"><?= e(trans('json_validate', 'Validate')) ?></button>
        <button id="minifyBtn" type="button" class="tool-button-primary"><?= e(trans('json_minify', 'Minify')) ?></button>
        <button id="clearBtn" type="button" class="tool-button-primary"><?= e(trans('json_clear', 'Clear')) ?></button>
      </div>
    </section>

    <section class="tool-panel-compact">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('json_output_label', 'Formatted output')) ?></h3>
          <p class="tool-copy"><?= e(trans('json_output_hint', 'The result appears here and can be copied immediately.')) ?></p>
        </div>
        <button id="copyBtn" type="button" class="tool-button-primary shrink-0">
          <?= e(trans('json_copy', 'Copy')) ?>
        </button>
      </div>

      <textarea
        id="jsonOutput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        readonly
        placeholder="<?= e(trans('json_output_placeholder', 'Formatted or minified JSON will appear here.')) ?>"
      ></textarea>

      <div id="jsonMessage" class="tool-soft-panel mt-4 rounded-2xl px-4 py-3 text-sm" role="status" aria-live="polite">
        <?= e(trans('json_idle', 'Ready to parse JSON.')) ?>
      </div>
    </section>
  </div>

  <div class="tool-muted-panel rounded-3xl px-5 py-4 text-sm">
    <?= e(trans('json_note', 'Tip: use Validate first if you only want to check the JSON without changing the output.')) ?>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('jsonInput');
    const output = document.getElementById('jsonOutput');
    const statusBox = document.getElementById('jsonStatus');
    const messageBox = document.getElementById('jsonMessage');
    const inputCount = document.getElementById('jsonInputCount');
    const outputCount = document.getElementById('jsonOutputCount');
    const formatBtn = document.getElementById('formatBtn');
    const validateBtn = document.getElementById('validateBtn');
    const minifyBtn = document.getElementById('minifyBtn');
    const copyBtn = document.getElementById('copyBtn');
    const clearBtn = document.getElementById('clearBtn');
    const loadSampleBtn = document.getElementById('loadSampleBtn');

    if (!input || !output || !statusBox || !messageBox || !inputCount || !outputCount || !formatBtn || !validateBtn || !minifyBtn || !copyBtn || !clearBtn || !loadSampleBtn) {
      return;
    }

    const text = {
      idle: <?= json_encode(trans('json_idle', 'Ready to parse JSON.')) ?>,
      empty: <?= json_encode(trans('json_empty_hint', 'Paste JSON to start.')) ?>,
      valid: <?= json_encode(trans('json_valid_message', 'Valid JSON.')) ?>,
      invalid: <?= json_encode(trans('json_invalid_message', 'Invalid JSON.')) ?>,
      formatted: <?= json_encode(trans('json_formatted_message', 'Formatted JSON is ready.')) ?>,
      minified: <?= json_encode(trans('json_minified_message', 'Minified JSON is ready.')) ?>,
      copied: <?= json_encode(trans('copied_success', 'Copied to clipboard.')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      cleared: <?= json_encode(trans('cleared_success', 'Cleared.')) ?>,
      nothingToCopy: <?= json_encode(trans('json_copy_empty', 'Nothing to copy yet.')) ?>,
      sample: <?= json_encode("{\n  \"name\": \"猫柠咔\",\n  \"brand\": \"toolbox\",\n  \"tools\": [\"json-formatter\", \"url-encode-decode\"],\n  \"active\": true\n}") ?>,
      sampleLoaded: <?= json_encode(trans('json_sample_loaded', 'Sample loaded.')) ?>,
      parsePrefix: <?= json_encode(trans('json_parse_error_prefix', 'Parse error:')) ?>,
    };

    const baseStatusClass = 'tool-stat-value';
    const toneClasses = {
      neutral: 'text-slate-900 dark:text-white',
      success: 'text-emerald-700 dark:text-emerald-300',
      error: 'text-rose-700 dark:text-rose-300',
    };
    const baseMessageClass = 'mt-4 rounded-2xl px-4 py-3 text-sm';
    const messageToneClasses = {
      neutral: 'tool-soft-panel rounded-2xl px-4 py-3 text-sm',
      success: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200',
      error: 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200',
    };

    function updateCounts() {
      inputCount.textContent = String(input.value.length);
      outputCount.textContent = String(output.value.length);
    }

    function setStatus(message, tone) {
      const resolvedTone = tone || 'neutral';
      statusBox.className = `${baseStatusClass} ${toneClasses[resolvedTone] || toneClasses.neutral}`;
      statusBox.textContent = message;
      messageBox.className = `${baseMessageClass} ${messageToneClasses[resolvedTone] || messageToneClasses.neutral}`;
      messageBox.textContent = message;
    }

    function parseJson(source) {
      const cleaned = source.replace(/^\uFEFF/, '').trim();

      if (cleaned === '') {
        return { empty: true };
      }

      try {
        return { value: JSON.parse(cleaned) };
      } catch (error) {
        return {
          error: error && error.message ? error.message : text.invalid,
        };
      }
    }

    function applyResult(result) {
      output.value = result;
      updateCounts();
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

    function formatJson() {
      const parsed = parseJson(input.value);

      if (parsed.empty) {
        output.value = '';
        setStatus(text.empty, 'neutral');
        updateCounts();
        return;
      }

      if (parsed.error) {
        output.value = '';
        setStatus(`${text.parsePrefix} ${parsed.error}`, 'error');
        updateCounts();
        return;
      }

      applyResult(JSON.stringify(parsed.value, null, 2));
      setStatus(text.formatted, 'success');
    }

    function validateJson() {
      const parsed = parseJson(input.value);

      if (parsed.empty) {
        output.value = '';
        setStatus(text.empty, 'neutral');
        updateCounts();
        return;
      }

      if (parsed.error) {
        setStatus(`${text.parsePrefix} ${parsed.error}`, 'error');
        return;
      }

      setStatus(text.valid, 'success');
    }

    function minifyJson() {
      const parsed = parseJson(input.value);

      if (parsed.empty) {
        output.value = '';
        setStatus(text.empty, 'neutral');
        updateCounts();
        return;
      }

      if (parsed.error) {
        output.value = '';
        setStatus(`${text.parsePrefix} ${parsed.error}`, 'error');
        updateCounts();
        return;
      }

      applyResult(JSON.stringify(parsed.value));
      setStatus(text.minified, 'success');
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
    formatBtn.addEventListener('click', formatJson);
    validateBtn.addEventListener('click', validateJson);
    minifyBtn.addEventListener('click', minifyJson);
    copyBtn.addEventListener('click', copyOutput);
    clearBtn.addEventListener('click', clearAll);
    loadSampleBtn.addEventListener('click', loadSample);

    updateCounts();
    setStatus(text.idle, 'neutral');
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
