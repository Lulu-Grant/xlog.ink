<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('hash_generator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
  <section class="tool-panel space-y-5">
    <div class="tool-badge"><?= e(trans('developer_tool', 'Developer tool')) ?></div>
    <div>
      <h2 class="tool-title-xl"><?= e(trans('hash_input_title', 'Text to hash')) ?></h2>
      <p class="tool-copy"><?= e(trans('hash_input_hint', 'Type or paste text and the hashes will update locally in your browser.')) ?></p>
    </div>

    <label class="tool-label">
      <?= e(trans('hash_input_label', 'Input text')) ?>
      <textarea id="hashInput" rows="10" class="tool-textarea tool-control-mono mt-2" placeholder="<?= e(trans('hash_input_placeholder', 'Type text to hash...')) ?>"><?= e(trans('hash_input_sample', '猫柠咔百宝箱')) ?></textarea>
    </label>

    <div class="grid gap-3 sm:grid-cols-3">
      <div class="tool-stat-card">
        <div class="tool-stat-label"><?= e(trans('hash_character_count', 'Characters')) ?></div>
        <div id="hashCharacterCount" class="tool-stat-value-lg">0</div>
      </div>
      <div class="tool-stat-card">
        <div class="tool-stat-label"><?= e(trans('hash_byte_count', 'UTF-8 bytes')) ?></div>
        <div id="hashByteCount" class="tool-stat-value-lg">0</div>
      </div>
      <div class="tool-stat-card">
        <div class="tool-stat-label"><?= e(trans('hash_algorithm_count', 'Algorithms')) ?></div>
        <div id="hashAlgorithmCount" class="tool-stat-value-lg">4</div>
      </div>
    </div>

    <div class="flex flex-wrap gap-3">
      <button id="hashGenerateBtn" type="button" class="tool-button-primary">
        <?= e(trans('hash_generate_button', 'Generate hashes')) ?>
      </button>
      <button id="hashCopyAllBtn" type="button" class="tool-button-secondary">
        <?= e(trans('hash_copy_all_button', 'Copy all hashes')) ?>
      </button>
      <button id="hashCopyInputBtn" type="button" class="tool-button-secondary">
        <?= e(trans('copy_input', 'Copy input')) ?>
      </button>
      <button id="hashClearBtn" type="button" class="tool-button-secondary">
        <?= e(trans('clear_input', 'Clear input')) ?>
      </button>
    </div>

    <p id="hashStatus" class="tool-status-text" role="status" aria-live="polite" aria-atomic="true">
      <?= e(trans('hash_status_ready', 'Ready to hash.')) ?>
    </p>
  </section>

  <section class="space-y-4">
    <div class="tool-panel">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="tool-title-xl"><?= e(trans('hash_results_title', 'Hash results')) ?></h2>
          <p class="tool-copy"><?= e(trans('hash_results_hint', 'MD5 is computed in JavaScript; SHA hashes use the browser crypto API when available.')) ?></p>
        </div>
        <span id="hashStatusPill" class="tool-status-pill" aria-hidden="true">4</span>
      </div>

      <div class="mt-4 grid gap-3">
        <div class="tool-stat-card" data-algorithm="md5">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="tool-stat-label">MD5</div>
              <div class="tool-stat-value mt-1"><?= e(trans('hash_md5_hint', '128-bit digest')) ?></div>
            </div>
            <button type="button" class="hash-copy-btn tool-button-secondary" data-target="hash-md5"><?= e(trans('copy', 'Copy')) ?></button>
          </div>
          <input id="hash-md5" type="text" readonly spellcheck="false" class="tool-control tool-control-filled tool-control-mono mt-3" aria-label="<?= e(trans('hash_md5_output', 'MD5 hash output')) ?>" placeholder="<?= e(trans('hash_output_placeholder', 'Result will appear here.')) ?>" />
        </div>

        <div class="tool-stat-card" data-algorithm="sha1">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="tool-stat-label">SHA-1</div>
              <div class="tool-stat-value mt-1"><?= e(trans('hash_sha1_hint', '160-bit digest')) ?></div>
            </div>
            <button type="button" class="hash-copy-btn tool-button-secondary" data-target="hash-sha1"><?= e(trans('copy', 'Copy')) ?></button>
          </div>
          <input id="hash-sha1" type="text" readonly spellcheck="false" class="tool-control tool-control-filled tool-control-mono mt-3" aria-label="<?= e(trans('hash_sha1_output', 'SHA-1 hash output')) ?>" placeholder="<?= e(trans('hash_output_placeholder', 'Result will appear here.')) ?>" />
        </div>

        <div class="tool-stat-card" data-algorithm="sha256">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="tool-stat-label">SHA-256</div>
              <div class="tool-stat-value mt-1"><?= e(trans('hash_sha256_hint', '256-bit digest')) ?></div>
            </div>
            <button type="button" class="hash-copy-btn tool-button-secondary" data-target="hash-sha256"><?= e(trans('copy', 'Copy')) ?></button>
          </div>
          <input id="hash-sha256" type="text" readonly spellcheck="false" class="tool-control tool-control-filled tool-control-mono mt-3" aria-label="<?= e(trans('hash_sha256_output', 'SHA-256 hash output')) ?>" placeholder="<?= e(trans('hash_output_placeholder', 'Result will appear here.')) ?>" />
        </div>

        <div class="tool-stat-card" data-algorithm="sha512">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="tool-stat-label">SHA-512</div>
              <div class="tool-stat-value mt-1"><?= e(trans('hash_sha512_hint', '512-bit digest')) ?></div>
            </div>
            <button type="button" class="hash-copy-btn tool-button-secondary" data-target="hash-sha512"><?= e(trans('copy', 'Copy')) ?></button>
          </div>
          <input id="hash-sha512" type="text" readonly spellcheck="false" class="tool-control tool-control-filled tool-control-mono mt-3" aria-label="<?= e(trans('hash_sha512_output', 'SHA-512 hash output')) ?>" placeholder="<?= e(trans('hash_output_placeholder', 'Result will appear here.')) ?>" />
        </div>
      </div>
    </div>

    <div class="tool-panel">
      <h2 class="tool-title-xl"><?= e(trans('hash_notes_title', 'Notes')) ?></h2>
      <ul class="tool-copy-list space-y-3">
        <li><?= e(trans('hash_note_one', 'All hashes are calculated locally in the browser.')) ?></li>
        <li><?= e(trans('hash_note_two', 'SHA-1, SHA-256, and SHA-512 use Web Crypto when available.')) ?></li>
        <li><?= e(trans('hash_note_three', 'MD5 is included for legacy checksums, but it is not suitable for passwords.')) ?></li>
        <li><?= e(trans('hash_note_four', 'The input is treated as UTF-8 text, so multi-byte characters are handled consistently.')) ?></li>
      </ul>
    </div>
  </section>
</section>

<script>
  (function () {
    const hashInput = document.getElementById('hashInput');
    const generateBtn = document.getElementById('hashGenerateBtn');
    const copyAllBtn = document.getElementById('hashCopyAllBtn');
    const copyInputBtn = document.getElementById('hashCopyInputBtn');
    const clearBtn = document.getElementById('hashClearBtn');
    const status = document.getElementById('hashStatus');
    const statusPill = document.getElementById('hashStatusPill');
    const charCount = document.getElementById('hashCharacterCount');
    const byteCount = document.getElementById('hashByteCount');
    const algorithmCount = document.getElementById('hashAlgorithmCount');
    const outputs = {
      md5: document.getElementById('hash-md5'),
      sha1: document.getElementById('hash-sha1'),
      sha256: document.getElementById('hash-sha256'),
      sha512: document.getElementById('hash-sha512'),
    };

    if (!hashInput || !generateBtn || !copyAllBtn || !copyInputBtn || !clearBtn || !status || !statusPill || !charCount || !byteCount || !algorithmCount || !outputs.md5 || !outputs.sha1 || !outputs.sha256 || !outputs.sha512) {
      return;
    }

    const textEncoder = new TextEncoder();
    const statusMessages = {
      ready: <?= json_encode(trans('hash_status_ready', 'Ready to hash.')) ?>,
      empty: <?= json_encode(trans('hash_status_empty', 'Enter text to generate hashes.')) ?>,
      updated: <?= json_encode(trans('hash_status_updated', 'Hashes updated.')) ?>,
      failed: <?= json_encode(trans('hash_status_failed', 'Hash generation failed.')) ?>,
      copied: <?= json_encode(trans('copied_to_clipboard', 'Copied to clipboard.')) ?>,
      copiedAll: <?= json_encode(trans('hash_status_copied_all', 'All hashes copied.')) ?>,
      calculating: <?= json_encode(trans('hash_status_calculating', 'Calculating hashes...')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      noWebCrypto: <?= json_encode(trans('hash_status_no_webcrypto', 'Web Crypto is not available, so only MD5 can be generated in this browser.')) ?>,
    };

    const hashLabels = [
      { id: 'md5', label: 'MD5' },
      { id: 'sha1', label: 'SHA-1' },
      { id: 'sha256', label: 'SHA-256' },
      { id: 'sha512', label: 'SHA-512' },
    ];

    let generationToken = 0;
    let inputDebounce = 0;

    function setStatus(message) {
      status.textContent = message;
    }

    function setAlgorithmCount(value) {
      algorithmCount.textContent = String(value);
      statusPill.textContent = String(value);
    }

    function textToBytes(value) {
      return textEncoder.encode(value);
    }

    function bytesToHex(bytes) {
      return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function bytesToBinaryString(bytes) {
      let binary = '';
      for (let index = 0; index < bytes.length; index += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(index, index + 0x8000));
      }
      return binary;
    }

    function addUnsigned(x, y) {
      const x4 = x & 0x40000000;
      const y4 = y & 0x40000000;
      const x8 = x & 0x80000000;
      const y8 = y & 0x80000000;
      const result = (x & 0x3FFFFFFF) + (y & 0x3FFFFFFF);

      if (x4 & y4) {
        return result ^ 0x80000000 ^ x8 ^ y8;
      }

      if (x4 | y4) {
        if (result & 0x40000000) {
          return result ^ 0xC0000000 ^ x8 ^ y8;
        }
        return result ^ 0x40000000 ^ x8 ^ y8;
      }

      return result ^ x8 ^ y8;
    }

    function rotateLeft(value, shift) {
      return (value << shift) | (value >>> (32 - shift));
    }

    function cmn(q, a, b, x, s, t) {
      return addUnsigned(rotateLeft(addUnsigned(addUnsigned(a, q), addUnsigned(x, t)), s), b);
    }

    function ff(a, b, c, d, x, s, t) {
      return cmn((b & c) | ((~b) & d), a, b, x, s, t);
    }

    function gg(a, b, c, d, x, s, t) {
      return cmn((b & d) | (c & (~d)), a, b, x, s, t);
    }

    function hh(a, b, c, d, x, s, t) {
      return cmn(b ^ c ^ d, a, b, x, s, t);
    }

    function ii(a, b, c, d, x, s, t) {
      return cmn(c ^ (b | (~d)), a, b, x, s, t);
    }

    function md5cycle(words) {
      let a = 1732584193;
      let b = -271733879;
      let c = -1732584194;
      let d = 271733878;

      for (let offset = 0; offset < words.length; offset += 16) {
        const aa = a;
        const bb = b;
        const cc = c;
        const dd = d;

        a = ff(a, b, c, d, words[offset + 0], 7, -680876936);
        d = ff(d, a, b, c, words[offset + 1], 12, -389564586);
        c = ff(c, d, a, b, words[offset + 2], 17, 606105819);
        b = ff(b, c, d, a, words[offset + 3], 22, -1044525330);
        a = ff(a, b, c, d, words[offset + 4], 7, -176418897);
        d = ff(d, a, b, c, words[offset + 5], 12, 1200080426);
        c = ff(c, d, a, b, words[offset + 6], 17, -1473231341);
        b = ff(b, c, d, a, words[offset + 7], 22, -45705983);
        a = ff(a, b, c, d, words[offset + 8], 7, 1770035416);
        d = ff(d, a, b, c, words[offset + 9], 12, -1958414417);
        c = ff(c, d, a, b, words[offset + 10], 17, -42063);
        b = ff(b, c, d, a, words[offset + 11], 22, -1990404162);
        a = ff(a, b, c, d, words[offset + 12], 7, 1804603682);
        d = ff(d, a, b, c, words[offset + 13], 12, -40341101);
        c = ff(c, d, a, b, words[offset + 14], 17, -1502002290);
        b = ff(b, c, d, a, words[offset + 15], 22, 1236535329);

        a = gg(a, b, c, d, words[offset + 1], 5, -165796510);
        d = gg(d, a, b, c, words[offset + 6], 9, -1069501632);
        c = gg(c, d, a, b, words[offset + 11], 14, 643717713);
        b = gg(b, c, d, a, words[offset + 0], 20, -373897302);
        a = gg(a, b, c, d, words[offset + 5], 5, -701558691);
        d = gg(d, a, b, c, words[offset + 10], 9, 38016083);
        c = gg(c, d, a, b, words[offset + 15], 14, -660478335);
        b = gg(b, c, d, a, words[offset + 4], 20, -405537848);
        a = gg(a, b, c, d, words[offset + 9], 5, 568446438);
        d = gg(d, a, b, c, words[offset + 14], 9, -1019803690);
        c = gg(c, d, a, b, words[offset + 3], 14, -187363961);
        b = gg(b, c, d, a, words[offset + 8], 20, 1163531501);
        a = gg(a, b, c, d, words[offset + 13], 5, -1444681467);
        d = gg(d, a, b, c, words[offset + 2], 9, -51403784);
        c = gg(c, d, a, b, words[offset + 7], 14, 1735328473);
        b = gg(b, c, d, a, words[offset + 12], 20, -1926607734);

        a = hh(a, b, c, d, words[offset + 5], 4, -378558);
        d = hh(d, a, b, c, words[offset + 8], 11, -2022574463);
        c = hh(c, d, a, b, words[offset + 11], 16, 1839030562);
        b = hh(b, c, d, a, words[offset + 14], 23, -35309556);
        a = hh(a, b, c, d, words[offset + 1], 4, -1530992060);
        d = hh(d, a, b, c, words[offset + 4], 11, 1272893353);
        c = hh(c, d, a, b, words[offset + 7], 16, -155497632);
        b = hh(b, c, d, a, words[offset + 10], 23, -1094730640);
        a = hh(a, b, c, d, words[offset + 13], 4, 681279174);
        d = hh(d, a, b, c, words[offset + 0], 11, -358537222);
        c = hh(c, d, a, b, words[offset + 3], 16, -722521979);
        b = hh(b, c, d, a, words[offset + 6], 23, 76029189);
        a = hh(a, b, c, d, words[offset + 9], 4, -640364487);
        d = hh(d, a, b, c, words[offset + 12], 11, -421815835);
        c = hh(c, d, a, b, words[offset + 15], 16, 530742520);
        b = hh(b, c, d, a, words[offset + 2], 23, -995338651);

        a = ii(a, b, c, d, words[offset + 0], 6, -198630844);
        d = ii(d, a, b, c, words[offset + 7], 10, 1126891415);
        c = ii(c, d, a, b, words[offset + 14], 15, -1416354905);
        b = ii(b, c, d, a, words[offset + 5], 21, -57434055);
        a = ii(a, b, c, d, words[offset + 12], 6, 1700485571);
        d = ii(d, a, b, c, words[offset + 3], 10, -1894986606);
        c = ii(c, d, a, b, words[offset + 10], 15, -1051523);
        b = ii(b, c, d, a, words[offset + 1], 21, -2054922799);
        a = ii(a, b, c, d, words[offset + 8], 6, 1873313359);
        d = ii(d, a, b, c, words[offset + 15], 10, -30611744);
        c = ii(c, d, a, b, words[offset + 6], 15, -1560198380);
        b = ii(b, c, d, a, words[offset + 13], 21, 1309151649);
        a = ii(a, b, c, d, words[offset + 4], 6, -145523070);
        d = ii(d, a, b, c, words[offset + 11], 10, -1120210379);
        c = ii(c, d, a, b, words[offset + 2], 15, 718787259);
        b = ii(b, c, d, a, words[offset + 9], 21, -343485551);

        a = addUnsigned(a, aa);
        b = addUnsigned(b, bb);
        c = addUnsigned(c, cc);
        d = addUnsigned(d, dd);
      }

      return [a, b, c, d];
    }

    function wordToHex(value) {
      const hex = [];
      for (let index = 0; index < 4; index += 1) {
        const byte = (value >>> (index * 8)) & 255;
        hex.push(byte.toString(16).padStart(2, '0'));
      }
      return hex.join('');
    }

    function binaryStringToMd5(binary) {
      const words = [];
      for (let index = 0; index < binary.length; index += 1) {
        words[index >> 2] = (words[index >> 2] || 0) | (binary.charCodeAt(index) << ((index % 4) << 3));
      }
      words[binary.length >> 2] = (words[binary.length >> 2] || 0) | (0x80 << ((binary.length % 4) << 3));
      words[(((binary.length + 8) >> 6) << 4) + 14] = binary.length * 8;
      const digest = md5cycle(words);
      return digest.map(wordToHex).join('');
    }

    function md5Hex(text) {
      return binaryStringToMd5(bytesToBinaryString(textToBytes(text)));
    }

    async function shaHex(name, bytes) {
      const digest = await crypto.subtle.digest(name, bytes);
      return bytesToHex(new Uint8Array(digest));
    }

    async function copyText(text) {
      if (!text) {
        return false;
      }

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
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
      temp.remove();
      return copied;
    }

    function renderHashes(values) {
      outputs.md5.value = values.md5 || '';
      outputs.sha1.value = values.sha1 || '';
      outputs.sha256.value = values.sha256 || '';
      outputs.sha512.value = values.sha512 || '';
    }

    async function updateHashes() {
      const token = ++generationToken;
      const text = hashInput.value;
      const bytes = textToBytes(text);
      const characterCount = Array.from(text).length;

      charCount.textContent = String(characterCount);
      byteCount.textContent = String(bytes.length);

      if (!text) {
        renderHashes({ md5: '', sha1: '', sha256: '', sha512: '' });
        setAlgorithmCount(window.crypto && window.crypto.subtle ? 4 : 1);
        setStatus(statusMessages.empty);
        return;
      }

      const results = {
        md5: md5Hex(text),
        sha1: '',
        sha256: '',
        sha512: '',
      };

      if (!window.crypto || !window.crypto.subtle) {
        if (token !== generationToken) {
          return;
        }
        setAlgorithmCount(1);
        renderHashes({
          md5: results.md5,
          sha1: '',
          sha256: '',
          sha512: '',
        });
        setStatus(statusMessages.noWebCrypto);
        return;
      }

      try {
        const [sha1, sha256, sha512] = await Promise.all([
          shaHex('SHA-1', bytes),
          shaHex('SHA-256', bytes),
          shaHex('SHA-512', bytes),
        ]);

        if (token !== generationToken) {
          return;
        }

        results.sha1 = sha1;
        results.sha256 = sha256;
        results.sha512 = sha512;
        setAlgorithmCount(4);
        renderHashes(results);
        setStatus(statusMessages.updated);
      } catch (error) {
        if (token !== generationToken) {
          return;
        }

        setAlgorithmCount(1);
        renderHashes({
          md5: results.md5,
          sha1: '',
          sha256: '',
          sha512: '',
        });
        setStatus(statusMessages.failed);
      }
    }

    generateBtn.addEventListener('click', () => {
      updateHashes().catch(() => setStatus(statusMessages.failed));
    });

    copyInputBtn.addEventListener('click', async () => {
      try {
        const copied = await copyText(hashInput.value);
        setStatus(copied ? statusMessages.copied : statusMessages.copyFailed);
      } catch (error) {
        setStatus(statusMessages.copyFailed);
      }
    });

    copyAllBtn.addEventListener('click', async () => {
      const text = hashLabels
        .map(({ id, label }) => {
          const value = outputs[id].value.trim();
          return value ? `${label}: ${value}` : '';
        })
        .filter(Boolean)
        .join('\n');

      if (!text) {
        setStatus(statusMessages.empty);
        return;
      }

      try {
        const copied = await copyText(text);
        setStatus(copied ? statusMessages.copiedAll : statusMessages.copyFailed);
      } catch (error) {
        setStatus(statusMessages.copyFailed);
      }
    });

    clearBtn.addEventListener('click', () => {
      hashInput.value = '';
      window.clearTimeout(inputDebounce);
      renderHashes({ md5: '', sha1: '', sha256: '', sha512: '' });
      charCount.textContent = '0';
      byteCount.textContent = '0';
      setAlgorithmCount(window.crypto && window.crypto.subtle ? 4 : 1);
      setStatus(statusMessages.ready);
      hashInput.focus();
    });

    document.querySelectorAll('.hash-copy-btn').forEach((button) => {
      button.addEventListener('click', async () => {
        const targetId = button.getAttribute('data-target');
        const target = targetId ? document.getElementById(targetId) : null;
        if (!target || !target.value) {
          setStatus(statusMessages.empty);
          return;
        }

        try {
          const copied = await copyText(target.value);
          setStatus(copied ? statusMessages.copied : statusMessages.copyFailed);
        } catch (error) {
          setStatus(statusMessages.copyFailed);
        }
      });
    });

    hashInput.addEventListener('input', () => {
      window.clearTimeout(inputDebounce);
      if (!hashInput.value) {
        updateHashes().catch(() => setStatus(statusMessages.failed));
        return;
      }
      setStatus(statusMessages.calculating);
      inputDebounce = window.setTimeout(() => {
        updateHashes().catch(() => setStatus(statusMessages.failed));
      }, 180);
    });

    updateHashes().catch(() => setStatus(statusMessages.failed));
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
