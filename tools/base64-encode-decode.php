<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('base64_encode_decode'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
  <div class="tool-panel">
    <div class="mb-4">
      <h2 class="tool-title-xl"><?= e(trans('base64_source_title')) ?></h2>
      <p class="tool-copy"><?= e(trans('base64_source_hint')) ?></p>
    </div>

    <textarea
      id="base64-input"
      class="tool-textarea min-h-[18rem]"
      placeholder="<?= e(trans('base64_input_placeholder')) ?>"
      spellcheck="false"
    ></textarea>

    <div class="mt-4 flex flex-wrap gap-3">
      <button id="encode-btn" type="button" class="tool-button-primary"><?= e(trans('base64_encode')) ?></button>
      <button id="decode-btn" type="button" class="tool-button-primary"><?= e(trans('base64_decode')) ?></button>
      <button id="clear-input-btn" type="button" class="tool-button-secondary"><?= e(trans('clear_input')) ?></button>
      <button id="copy-input-btn" type="button" class="tool-button-secondary"><?= e(trans('copy_input')) ?></button>
    </div>
  </div>

  <div class="tool-panel">
    <div class="mb-4">
      <h2 class="tool-title-xl"><?= e(trans('base64_result_title')) ?></h2>
      <p class="tool-copy"><?= e(trans('base64_result_hint')) ?></p>
    </div>

    <textarea
      id="base64-output"
      class="tool-textarea min-h-[18rem]"
      placeholder="<?= e(trans('base64_output_placeholder')) ?>"
      spellcheck="false"
    ></textarea>

    <div class="mt-4 flex flex-wrap gap-3">
      <button id="clear-output-btn" type="button" class="tool-button-secondary"><?= e(trans('clear_output')) ?></button>
      <button id="copy-output-btn" type="button" class="tool-button-secondary"><?= e(trans('copy_output')) ?></button>
    </div>

    <div id="base64-status" class="tool-soft-panel mt-4 rounded-2xl px-4 py-3 text-sm">
      <?= e(trans('base64_ready')) ?>
    </div>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('base64-input');
    const output = document.getElementById('base64-output');
    const status = document.getElementById('base64-status');
    const encodeBtn = document.getElementById('encode-btn');
    const decodeBtn = document.getElementById('decode-btn');
    const clearInputBtn = document.getElementById('clear-input-btn');
    const clearOutputBtn = document.getElementById('clear-output-btn');
    const copyInputBtn = document.getElementById('copy-input-btn');
    const copyOutputBtn = document.getElementById('copy-output-btn');

    if (!input || !output || !status || !encodeBtn || !decodeBtn || !clearInputBtn || !clearOutputBtn || !copyInputBtn || !copyOutputBtn) {
      return;
    }

    const messages = {
      encoded: <?= json_encode(trans('base64_encoded_success')) ?>,
      decoded: <?= json_encode(trans('base64_decoded_success')) ?>,
      copied: <?= json_encode(trans('copied_to_clipboard')) ?>,
      cleared: <?= json_encode(trans('cleared')) ?>,
      invalidBase64: <?= json_encode(trans('base64_invalid')) ?>,
      emptyInput: <?= json_encode(trans('base64_empty_input')) ?>,
      emptyOutput: <?= json_encode(trans('base64_empty_output')) ?>,
    };

    const textEncoder = new TextEncoder();
    const textDecoder = new TextDecoder('utf-8', { fatal: false });

    function setStatus(message) {
      status.textContent = message;
    }

    function bytesToBase64(bytes) {
      let binary = '';
      const chunkSize = 0x8000;
      for (let i = 0; i < bytes.length; i += chunkSize) {
        const chunk = bytes.subarray(i, i + chunkSize);
        binary += String.fromCharCode.apply(null, chunk);
      }
      return btoa(binary);
    }

    function base64ToBytes(text) {
      const cleaned = text.replace(/\s+/g, '');
      const normalized = cleaned.replace(/-/g, '+').replace(/_/g, '/');
      const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
      const binary = atob(padded);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
      }
      return bytes;
    }

    function encodeText() {
      const value = input.value;
      if (!value) {
        setStatus(messages.emptyInput);
        output.value = '';
        return;
      }

      const bytes = textEncoder.encode(value);
      output.value = bytesToBase64(bytes);
      setStatus(messages.encoded);
    }

    function decodeText() {
      const value = input.value;
      if (!value) {
        setStatus(messages.emptyInput);
        output.value = '';
        return;
      }

      try {
        const bytes = base64ToBytes(value);
        output.value = textDecoder.decode(bytes);
        setStatus(messages.decoded);
      } catch (error) {
        output.value = '';
        setStatus(messages.invalidBase64);
      }
    }

    async function copyValue(field) {
      const value = field.value.trim();
      if (!value) {
        setStatus(field === input ? messages.emptyInput : messages.emptyOutput);
        return;
      }

      await navigator.clipboard.writeText(field.value);
      setStatus(messages.copied);
    }

    encodeBtn.addEventListener('click', encodeText);
    decodeBtn.addEventListener('click', decodeText);
    clearInputBtn.addEventListener('click', () => {
      input.value = '';
      setStatus(messages.cleared);
      input.focus();
    });
    clearOutputBtn.addEventListener('click', () => {
      output.value = '';
      setStatus(messages.cleared);
      output.focus();
    });
    copyInputBtn.addEventListener('click', () => copyValue(input).catch(() => setStatus(messages.emptyInput)));
    copyOutputBtn.addEventListener('click', () => copyValue(output).catch(() => setStatus(messages.emptyOutput)));

    input.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        encodeText();
      }
    });
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
