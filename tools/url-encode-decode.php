<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('url_encode_decode'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-6xl space-y-6">
  <div class="tool-panel sm:p-8">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
      <div class="max-w-3xl">
        <div class="tool-badge">
          <?= e(trans('url_tool_badge', 'Developer tool')) ?>
        </div>
        <h2 class="tool-hero-title sm:text-3xl">
          <?= e(trans('url_tool_heading', 'Encode and decode URL text instantly')) ?>
        </h2>
        <p class="tool-hero-copy">
          <?= e(trans('url_tool_intro', 'Paste a link, query value, or any text and convert it safely for URLs.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-3 lg:w-auto lg:min-w-[28rem]">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('url_status_label', 'Status')) ?></div>
          <div id="urlStatus" class="tool-stat-value"><?= e(trans('url_idle', 'Ready to encode or decode.')) ?></div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('url_input_count_label', 'Input chars')) ?></div>
          <div id="urlInputCount" class="tool-stat-value">0</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('url_output_count_label', 'Output chars')) ?></div>
          <div id="urlOutputCount" class="tool-stat-value">0</div>
        </div>
      </div>
    </div>
  </div>

  <div class="grid gap-6 xl:grid-cols-2">
    <section class="tool-panel-compact">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('url_input_label', 'Text input')) ?></h3>
          <p class="tool-copy"><?= e(trans('url_input_hint', 'Spaces, symbols, and non-ASCII text are all supported.')) ?></p>
        </div>
        <button id="loadSampleBtn" type="button" class="tool-button-primary shrink-0">
          <?= e(trans('url_load_sample', 'Load sample')) ?>
        </button>
      </div>

      <textarea
        id="urlInput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        placeholder="<?= e(trans('url_input_placeholder', '猫柠咔 toolbox?name=JSON & value=100%')) ?>"
      ></textarea>

      <div class="mt-4 flex flex-wrap gap-3">
        <button id="encodeBtn" type="button" class="tool-button-primary"><?= e(trans('url_encode', 'Encode')) ?></button>
        <button id="decodeBtn" type="button" class="tool-button-primary"><?= e(trans('url_decode', 'Decode')) ?></button>
        <button id="clearBtn" type="button" class="tool-button-primary"><?= e(trans('url_clear', 'Clear')) ?></button>
      </div>
    </section>

    <section class="tool-panel-compact">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('url_output_label', 'Encoded / decoded output')) ?></h3>
          <p class="tool-copy"><?= e(trans('url_output_hint', 'Copy the result after converting.')) ?></p>
        </div>
        <button id="copyBtn" type="button" class="tool-button-primary shrink-0">
          <?= e(trans('url_copy', 'Copy')) ?>
        </button>
      </div>

      <textarea
        id="urlOutput"
        class="tool-textarea mt-4 min-h-[22rem]"
        spellcheck="false"
        autocapitalize="off"
        autocomplete="off"
        autocorrect="off"
        dir="auto"
        readonly
        placeholder="<?= e(trans('url_output_placeholder', 'Converted text will appear here.')) ?>"
      ></textarea>

      <div id="urlMessage" class="tool-soft-panel mt-4 rounded-2xl px-4 py-3 text-sm" role="status" aria-live="polite">
        <?= e(trans('url_idle', 'Ready to encode or decode.')) ?>
      </div>
    </section>
  </div>

  <div class="tool-muted-panel rounded-3xl px-5 py-4 text-sm">
    <?= e(trans('url_note', 'Tip: decode treats + as a space, which is useful for query strings.')) ?>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('urlInput');
    const output = document.getElementById('urlOutput');
    const statusBox = document.getElementById('urlStatus');
    const messageBox = document.getElementById('urlMessage');
    const inputCount = document.getElementById('urlInputCount');
    const outputCount = document.getElementById('urlOutputCount');
    const encodeBtn = document.getElementById('encodeBtn');
    const decodeBtn = document.getElementById('decodeBtn');
    const copyBtn = document.getElementById('copyBtn');
    const clearBtn = document.getElementById('clearBtn');
    const loadSampleBtn = document.getElementById('loadSampleBtn');

    if (!input || !output || !statusBox || !messageBox || !inputCount || !outputCount || !encodeBtn || !decodeBtn || !copyBtn || !clearBtn || !loadSampleBtn) {
      return;
    }

    const text = {
      idle: <?= json_encode(trans('url_idle', 'Ready to encode or decode.')) ?>,
      empty: <?= json_encode(trans('url_empty_hint', 'Paste text to start.')) ?>,
      encoded: <?= json_encode(trans('url_encoded_message', 'Encoded text is ready.')) ?>,
      decoded: <?= json_encode(trans('url_decoded_message', 'Decoded text is ready.')) ?>,
      copied: <?= json_encode(trans('copied_success', 'Copied to clipboard.')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      cleared: <?= json_encode(trans('cleared_success', 'Cleared.')) ?>,
      nothingToCopy: <?= json_encode(trans('url_copy_empty', 'Nothing to copy yet.')) ?>,
      sample: <?= json_encode("https://example.com/search?q=猫柠咔 toolbox&lang=zh-cn&value=100%") ?>,
      sampleLoaded: <?= json_encode(trans('url_sample_loaded', 'Sample loaded.')) ?>,
      decodeError: <?= json_encode(trans('url_decode_error', 'Decode failed. Check for malformed percent-encoding.')) ?>,
    };

    const baseStatusClass = 'tool-stat-value';
    const toneClasses = {
      neutral: 'text-slate-900 dark:text-white',
      success: 'text-cyan-700 dark:text-cyan-300',
      error: 'text-rose-700 dark:text-rose-300',
    };
    const baseMessageClass = 'mt-4 rounded-2xl px-4 py-3 text-sm';
    const messageToneClasses = {
      neutral: 'tool-soft-panel rounded-2xl px-4 py-3 text-sm',
      success: 'bg-cyan-50 text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-200',
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

    function encodeText() {
      if (input.value.trim() === '') {
        output.value = '';
        updateCounts();
        setStatus(text.empty, 'neutral');
        return;
      }

      output.value = encodeURIComponent(input.value);
      updateCounts();
      setStatus(text.encoded, 'success');
    }

    function decodeText() {
      if (input.value.trim() === '') {
        output.value = '';
        updateCounts();
        setStatus(text.empty, 'neutral');
        return;
      }

      try {
        output.value = decodeURIComponent(input.value.replace(/\+/g, ' '));
        updateCounts();
        setStatus(text.decoded, 'success');
      } catch (error) {
        output.value = '';
        updateCounts();
        setStatus(text.decodeError, 'error');
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
    encodeBtn.addEventListener('click', encodeText);
    decodeBtn.addEventListener('click', decodeText);
    copyBtn.addEventListener('click', copyOutput);
    clearBtn.addEventListener('click', clearAll);
    loadSampleBtn.addEventListener('click', loadSample);

    updateCounts();
    setStatus(text.idle, 'neutral');
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
