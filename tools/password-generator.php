<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('password_generator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
  <section class="tool-panel">
    <div class="space-y-6">
      <div>
        <h2 class="tool-title-lg">
          <?= e(trans('password_options_title', 'Password options')) ?>
        </h2>
        <p class="tool-copy">
          <?= e(trans('password_options_hint', 'Choose the character set and length for the password you want to generate.')) ?>
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="tool-label sm:col-span-2">
          <div class="flex items-center justify-between">
            <span><?= e(trans('password_length_label', 'Length')) ?></span>
            <output id="passwordLengthValue" class="tool-status-text">16</output>
          </div>
          <input
            id="passwordLength"
            type="range"
            min="8"
            max="64"
            value="16"
            class="mt-2 w-full accent-sky-500"
          />
        </label>

        <label class="tool-label">
          <?= e(trans('password_length_number_label', 'Custom length')) ?>
          <input
            id="passwordLengthNumber"
            type="number"
            min="8"
            max="64"
            value="16"
            class="tool-control tool-control-sm mt-2"
          />
        </label>

        <label class="tool-label">
          <?= e(trans('password_count_label', 'Passwords to generate')) ?>
          <input
            id="passwordCount"
            type="number"
            min="1"
            max="20"
            value="1"
            class="tool-control tool-control-sm mt-2"
          />
        </label>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="tool-toggle-row">
          <input id="optUppercase" type="checkbox" checked class="tool-checkbox" />
          <span><?= e(trans('password_uppercase_label', 'Uppercase letters')) ?></span>
        </label>
        <label class="tool-toggle-row">
          <input id="optLowercase" type="checkbox" checked class="tool-checkbox" />
          <span><?= e(trans('password_lowercase_label', 'Lowercase letters')) ?></span>
        </label>
        <label class="tool-toggle-row">
          <input id="optNumbers" type="checkbox" checked class="tool-checkbox" />
          <span><?= e(trans('password_numbers_label', 'Numbers')) ?></span>
        </label>
        <label class="tool-toggle-row">
          <input id="optSymbols" type="checkbox" checked class="tool-checkbox" />
          <span><?= e(trans('password_symbols_label', 'Symbols')) ?></span>
        </label>
      </div>

      <label class="tool-toggle-row items-start">
        <input id="optAmbiguous" type="checkbox" class="tool-checkbox mt-1" />
        <span><?= e(trans('password_ambiguous_label', 'Avoid ambiguous characters')) ?></span>
      </label>

      <div class="flex flex-wrap gap-3">
        <button id="generateBtn" type="button" class="tool-button-primary">
          <?= e(trans('password_generate_button', 'Generate password')) ?>
        </button>
        <button id="copyBtn" type="button" class="tool-button-secondary">
          <?= e(trans('password_copy_button', 'Copy')) ?>
        </button>
      </div>

      <p id="passwordStatus" class="tool-status-text" aria-live="polite">
        <?= e(trans('password_status_ready', 'Ready to generate.')) ?>
      </p>
    </div>
  </section>

  <section class="space-y-4">
    <div class="tool-panel">
      <div class="flex items-center justify-between gap-4">
        <h2 class="tool-title-lg">
          <?= e(trans('password_result_title', 'Generated password')) ?>
        </h2>
        <span id="passwordStrength" class="tool-status-pill">
          <?= e(trans('password_strength_label', 'Strength')) ?>
        </span>
      </div>

      <div class="tool-inset-panel mt-4 rounded-3xl">
        <textarea
          id="passwordOutput"
          rows="3"
          readonly
          class="tool-output-display"
        ></textarea>
      </div>

      <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="tool-stat-card">
          <div class="tool-stat-label">
            <?= e(trans('password_entropy_label', 'Entropy')) ?>
          </div>
          <div id="passwordEntropy" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label">
            <?= e(trans('password_charset_label', 'Character set')) ?>
          </div>
          <div id="passwordCharset" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label">
            <?= e(trans('password_length_summary_label', 'Length')) ?>
          </div>
          <div id="passwordLengthSummary" class="tool-stat-value-lg">16</div>
        </div>
      </div>
    </div>

    <div class="tool-panel">
      <h2 class="tool-title-lg">
        <?= e(trans('password_tips_title', 'Tips')) ?>
      </h2>
      <ul class="tool-copy-list space-y-3">
        <li><?= e(trans('password_tips_one', 'Use at least 16 characters for general-purpose accounts.')) ?></li>
        <li><?= e(trans('password_tips_two', 'Mix letter classes and numbers for better entropy.')) ?></li>
        <li><?= e(trans('password_tips_three', 'Avoid ambiguous characters if you need to type the password by hand.')) ?></li>
      </ul>
    </div>
  </section>
</section>

<script>
  (function () {
    const lengthRange = document.getElementById('passwordLength');
    const lengthNumber = document.getElementById('passwordLengthNumber');
    const countField = document.getElementById('passwordCount');
    const uppercaseField = document.getElementById('optUppercase');
    const lowercaseField = document.getElementById('optLowercase');
    const numbersField = document.getElementById('optNumbers');
    const symbolsField = document.getElementById('optSymbols');
    const ambiguousField = document.getElementById('optAmbiguous');
    const generateBtn = document.getElementById('generateBtn');
    const copyBtn = document.getElementById('copyBtn');
    const output = document.getElementById('passwordOutput');
    const status = document.getElementById('passwordStatus');
    const entropy = document.getElementById('passwordEntropy');
    const charset = document.getElementById('passwordCharset');
    const lengthSummary = document.getElementById('passwordLengthSummary');
    const strength = document.getElementById('passwordStrength');

    if (!lengthRange || !lengthNumber || !countField || !uppercaseField || !lowercaseField || !numbersField || !symbolsField || !ambiguousField || !generateBtn || !copyBtn || !output || !status || !entropy || !charset || !lengthSummary || !strength) {
      return;
    }

    const fallbacks = {
      ready: <?= json_encode(trans('password_status_ready', 'Ready to generate.')) ?>,
      copied: <?= json_encode(trans('password_status_copied', 'Copied to clipboard.')) ?>,
      copiedFallback: <?= json_encode(trans('password_status_copied_fallback', 'Copied.')) ?>,
      invalid: <?= json_encode(trans('password_status_invalid', 'Please enable at least one character set.')) ?>,
      generated: <?= json_encode(trans('password_status_generated', 'Password generated.')) ?>,
      copyFailed: <?= json_encode(trans('password_status_copy_failed', 'Copy failed. Please copy it manually.')) ?>,
      strong: <?= json_encode(trans('password_strength_strong', 'Strong')) ?>,
      medium: <?= json_encode(trans('password_strength_medium', 'Medium')) ?>,
      weak: <?= json_encode(trans('password_strength_weak', 'Weak')) ?>,
      chars: <?= json_encode(trans('password_charset_summary', 'characters')) ?>,
    };

    const pools = {
      lowercase: 'abcdefghijklmnopqrstuvwxyz',
      uppercase: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
      numbers: '0123456789',
      symbols: '!@#$%^&*()-_=+[]{};:,.?/|~',
    };

    const ambiguous = new Set(['0', 'O', 'o', '1', 'l', 'I', '|', '`', '"', "'", '\\', '/', '{', '}', '[', ']', '(', ')', ',', ';', ':', '.']);

    function clampLength(value) {
      const numeric = Number.parseInt(value, 10);
      if (Number.isNaN(numeric)) {
        return 16;
      }
      return Math.min(64, Math.max(8, numeric));
    }

    function syncLength(value) {
      const length = clampLength(value);
      lengthRange.value = String(length);
      lengthNumber.value = String(length);
      lengthSummary.textContent = String(length);
      return length;
    }

    function filteredPool(source) {
      if (!ambiguousField.checked) {
        return source;
      }
      return source.split('').filter((char) => !ambiguous.has(char)).join('');
    }

    function getActivePools() {
      const selected = [];
      if (lowercaseField.checked) {
        selected.push(filteredPool(pools.lowercase));
      }
      if (uppercaseField.checked) {
        selected.push(filteredPool(pools.uppercase));
      }
      if (numbersField.checked) {
        selected.push(filteredPool(pools.numbers));
      }
      if (symbolsField.checked) {
        selected.push(filteredPool(pools.symbols));
      }
      return selected.filter(Boolean);
    }

    function randomIndex(max) {
      if (max <= 1) {
        return 0;
      }
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        const arr = new Uint32Array(1);
        const limit = Math.floor(0x100000000 / max) * max;
        let value = 0;
        do {
          window.crypto.getRandomValues(arr);
          value = arr[0];
        } while (value >= limit);
        return value % max;
      }
      return Math.floor(Math.random() * max);
    }

    function pickChar(pool) {
      return pool.charAt(randomIndex(pool.length));
    }

    function generateOne(length) {
      const poolsList = getActivePools();
      if (!poolsList.length) {
        return '';
      }

      const assembled = poolsList.join('');
      const chars = [];

      for (const pool of poolsList) {
        chars.push(pickChar(pool));
      }

      while (chars.length < length) {
        chars.push(pickChar(assembled));
      }

      for (let i = chars.length - 1; i > 0; i -= 1) {
        const j = randomIndex(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
      }

      return chars.join('').slice(0, length);
    }

    function estimateEntropy(length, poolSize) {
      if (!poolSize || !length) {
        return 0;
      }
      return Math.log2(Math.pow(poolSize, length));
    }

    function strengthLabel(bits) {
      if (bits >= 80) {
        return fallbacks.strong;
      }
      if (bits >= 50) {
        return fallbacks.medium;
      }
      return fallbacks.weak;
    }

    function updateMetrics(password, length) {
      const poolsList = getActivePools();
      const poolSize = poolsList.reduce((sum, pool) => sum + pool.length, 0);
      const bits = estimateEntropy(length, poolSize);
      entropy.textContent = bits ? `${bits.toFixed(1)} bit` : '-';
      charset.textContent = poolSize ? `${poolSize} ${fallbacks.chars}` : '-';
      strength.textContent = strengthLabel(bits);
    }

    function renderPasswordList(passwords) {
      output.value = passwords.join('\n');
    }

    function generate() {
      const length = syncLength(lengthNumber.value);
      const count = Math.min(20, Math.max(1, Number.parseInt(countField.value, 10) || 1));
      const poolsList = getActivePools();

      if (!poolsList.length) {
        renderPasswordList(['']);
        status.textContent = fallbacks.invalid;
        updateMetrics('', length);
        return;
      }

      const passwords = [];
      for (let i = 0; i < count; i += 1) {
        passwords.push(generateOne(length));
      }

      renderPasswordList(passwords);
      status.textContent = fallbacks.generated;
      updateMetrics(passwords[0], length);
    }

    function copyToClipboard() {
      const text = output.value.trim();
      if (!text) {
        status.textContent = fallbacks.invalid;
        return;
      }

      const copyFallback = () => {
        output.focus();
        output.select();
        const successful = document.execCommand('copy');
        window.getSelection().removeAllRanges();
        status.textContent = successful ? fallbacks.copiedFallback : fallbacks.copyFailed;
      };

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).then(() => {
          status.textContent = fallbacks.copied;
        }).catch(copyFallback);
        return;
      }

      copyFallback();
    }

    lengthRange.addEventListener('input', () => {
      syncLength(lengthRange.value);
      generate();
    });

    lengthNumber.addEventListener('input', () => {
      syncLength(lengthNumber.value);
      generate();
    });

    countField.addEventListener('input', generate);
    [uppercaseField, lowercaseField, numbersField, symbolsField, ambiguousField].forEach((field) => {
      field.addEventListener('change', generate);
    });

    generateBtn.addEventListener('click', generate);
    copyBtn.addEventListener('click', copyToClipboard);

    syncLength(lengthNumber.value);
    generate();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
