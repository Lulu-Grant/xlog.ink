<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('jwt_decoder'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
  <section class="tool-panel space-y-5">
    <div class="tool-badge"><?= e(trans('developer_tool', 'Developer tool')) ?></div>
    <div>
      <h2 class="tool-title-xl"><?= e(trans('jwt_input_title', 'JWT input')) ?></h2>
      <p class="tool-copy"><?= e(trans('jwt_input_hint', 'Paste a JWT and decode its header and payload locally in the browser.')) ?></p>
    </div>

    <label class="tool-label">
      <?= e(trans('jwt_input_label', 'JWT token')) ?>
      <textarea id="jwtInput" rows="10" class="tool-textarea tool-control-mono mt-2" placeholder="<?= e(trans('jwt_input_placeholder', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0IiwibmFtZSI6IkRlbW8gVXNlciIsImV4cCI6MTg5MzQ1NjAwMH0.signature')) ?>"></textarea>
    </label>

    <div class="flex flex-wrap gap-3">
      <button id="jwtDecodeBtn" type="button" class="tool-button-primary">
        <?= e(trans('jwt_decode_button', 'Decode token')) ?>
      </button>
      <button id="jwtCopyTokenBtn" type="button" class="tool-button-secondary">
        <?= e(trans('jwt_copy_button', 'Copy token')) ?>
      </button>
      <button id="jwtLoadSampleBtn" type="button" class="tool-button-secondary">
        <?= e(trans('jwt_load_sample_button', 'Load sample')) ?>
      </button>
      <button id="jwtClearBtn" type="button" class="tool-button-secondary">
        <?= e(trans('clear_input', 'Clear input')) ?>
      </button>
    </div>

    <p id="jwtStatus" class="tool-status-text" role="status" aria-live="polite" aria-atomic="true">
      <?= e(trans('jwt_status_ready', 'Ready to decode a JWT.')) ?>
    </p>
  </section>

  <section class="space-y-4">
    <div class="tool-panel">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="tool-title-xl"><?= e(trans('jwt_summary_title', 'Decoded details')) ?></h2>
          <p class="tool-copy"><?= e(trans('jwt_summary_hint', 'This tool decodes the token structure but does not verify the signature.')) ?></p>
        </div>
        <span id="jwtStatusPill" class="tool-status-pill" aria-hidden="true"><?= e(trans('jwt_status_ready', 'Ready')) ?></span>
      </div>

      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('jwt_segments_label', 'Segments')) ?></div>
          <div id="jwtSegments" class="tool-stat-value-lg">0</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('jwt_algorithm_label', 'Algorithm')) ?></div>
          <div id="jwtAlgorithm" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('jwt_expiration_label', 'Expiration')) ?></div>
          <div id="jwtExpiration" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('jwt_state_label', 'Status')) ?></div>
          <div id="jwtState" class="tool-stat-value-lg">-</div>
        </div>
      </div>

      <div class="mt-4 tool-alert-warning">
        <?= e(trans('jwt_warning', 'Signature verification is not performed here. Use this tool to inspect token structure, claims, and expiry only.')) ?>
      </div>

      <div class="mt-4 tool-inset-panel">
        <div class="tool-note-title"><?= e(trans('jwt_claims_title', 'Claims overview')) ?></div>
        <ul id="jwtClaims" class="tool-copy-list mt-3 space-y-2" aria-live="polite"></ul>
      </div>
    </div>

    <div class="tool-panel-compact">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('jwt_header_title', 'Header')) ?></h3>
          <p class="tool-copy"><?= e(trans('jwt_header_hint', 'Decoded JWT header in JSON format.')) ?></p>
        </div>
        <button id="copyHeaderBtn" type="button" class="tool-button-secondary"><?= e(trans('copy_output', 'Copy output')) ?></button>
      </div>
      <textarea id="jwtHeaderOutput" rows="8" readonly spellcheck="false" class="tool-textarea tool-control-mono mt-3" placeholder="<?= e(trans('jwt_output_placeholder', 'Decoded JSON will appear here.')) ?>"></textarea>
    </div>

    <div class="tool-panel-compact">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h3 class="tool-title-lg"><?= e(trans('jwt_payload_title', 'Payload')) ?></h3>
          <p class="tool-copy"><?= e(trans('jwt_payload_hint', 'Decoded JWT payload in JSON format.')) ?></p>
        </div>
        <button id="copyPayloadBtn" type="button" class="tool-button-secondary"><?= e(trans('copy_output', 'Copy output')) ?></button>
      </div>
      <textarea id="jwtPayloadOutput" rows="12" readonly spellcheck="false" class="tool-textarea tool-control-mono mt-3" placeholder="<?= e(trans('jwt_output_placeholder', 'Decoded JSON will appear here.')) ?>"></textarea>
    </div>
  </section>
</section>

<script>
  (function () {
    const jwtInput = document.getElementById('jwtInput');
    const decodeBtn = document.getElementById('jwtDecodeBtn');
    const copyTokenBtn = document.getElementById('jwtCopyTokenBtn');
    const loadSampleBtn = document.getElementById('jwtLoadSampleBtn');
    const clearBtn = document.getElementById('jwtClearBtn');
    const copyHeaderBtn = document.getElementById('copyHeaderBtn');
    const copyPayloadBtn = document.getElementById('copyPayloadBtn');
    const status = document.getElementById('jwtStatus');
    const pill = document.getElementById('jwtStatusPill');
    const segments = document.getElementById('jwtSegments');
    const algorithm = document.getElementById('jwtAlgorithm');
    const expiration = document.getElementById('jwtExpiration');
    const state = document.getElementById('jwtState');
    const claims = document.getElementById('jwtClaims');
    const headerOutput = document.getElementById('jwtHeaderOutput');
    const payloadOutput = document.getElementById('jwtPayloadOutput');

    if (!jwtInput || !decodeBtn || !copyTokenBtn || !loadSampleBtn || !clearBtn || !copyHeaderBtn || !copyPayloadBtn || !status || !pill || !segments || !algorithm || !expiration || !state || !claims || !headerOutput || !payloadOutput) {
      return;
    }

    const textEncoder = new TextEncoder();
    const textDecoder = new TextDecoder();
    const messages = {
      ready: <?= json_encode(trans('jwt_status_ready', 'Ready to decode a JWT.')) ?>,
      empty: <?= json_encode(trans('jwt_status_empty', 'Paste a JWT token to start.')) ?>,
      copied: <?= json_encode(trans('copied_to_clipboard', 'Copied to clipboard.')) ?>,
      copyFailed: <?= json_encode(trans('copy_failed', 'Copy failed.')) ?>,
      copiedToken: <?= json_encode(trans('jwt_status_copied_token', 'Token copied.')) ?>,
      malformed: <?= json_encode(trans('jwt_status_malformed', 'JWT must have exactly three dot-separated segments.')) ?>,
      invalidHeader: <?= json_encode(trans('jwt_status_invalid_header', 'Header is not valid JSON.')) ?>,
      invalidPayload: <?= json_encode(trans('jwt_status_invalid_payload', 'Payload is not valid JSON.')) ?>,
      expired: <?= json_encode(trans('jwt_status_expired', 'Token is expired.')) ?>,
      notYetValid: <?= json_encode(trans('jwt_status_not_yet_valid', 'Token is not valid yet.')) ?>,
      valid: <?= json_encode(trans('jwt_status_valid', 'Token structure looks valid.')) ?>,
      noExp: <?= json_encode(trans('jwt_status_no_exp', 'No expiration claim is set.')) ?>,
      signatureHint: <?= json_encode(trans('jwt_signature_hint', 'base64url characters')) ?>,
      localTimeLabel: <?= json_encode(trans('jwt_time_local_label', 'Local')) ?>,
      utcTimeLabel: <?= json_encode(trans('jwt_time_utc_label', 'UTC')) ?>,
    };

    function setStatus(message, tone) {
      status.textContent = message;
      pill.textContent = message;
      pill.className = 'tool-status-pill';
      if (tone === 'success') {
        pill.classList.add('bg-emerald-100', 'text-emerald-700', 'dark:bg-emerald-950/60', 'dark:text-emerald-200');
      } else if (tone === 'warning') {
        pill.classList.add('bg-amber-100', 'text-amber-700', 'dark:bg-amber-950/60', 'dark:text-amber-200');
      } else if (tone === 'error') {
        pill.classList.add('bg-rose-100', 'text-rose-700', 'dark:bg-rose-950/60', 'dark:text-rose-200');
      }
    }

    function base64UrlNormalize(value) {
      const normalized = value.replace(/-/g, '+').replace(/_/g, '/').replace(/\s+/g, '');
      const padding = (4 - (normalized.length % 4)) % 4;
      return normalized + '='.repeat(padding);
    }

    function binaryStringToUtf8(binary) {
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
      }
      return textDecoder.decode(bytes);
    }

    function utf8ToBinaryString(value) {
      const bytes = textEncoder.encode(value);
      let binary = '';
      for (let index = 0; index < bytes.length; index += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(index, index + 0x8000));
      }
      return binary;
    }

    function decodeBase64Url(value) {
      const normalized = base64UrlNormalize(value);
      const binary = atob(normalized);
      return binaryStringToUtf8(binary);
    }

    function encodeBase64Url(value) {
      return btoa(utf8ToBinaryString(value))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/g, '');
    }

    function prettyJson(raw) {
      return JSON.stringify(JSON.parse(raw), null, 2);
    }

    function formatDateTime(seconds) {
      const date = new Date(seconds * 1000);
      if (Number.isNaN(date.getTime())) {
        return '-';
      }

      const local = date.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      });

      return `${messages.localTimeLabel}: ${local} · ${messages.utcTimeLabel}: ${date.toISOString()}`;
    }

    function formatRelative(seconds) {
      const targetMs = seconds * 1000;
      const diff = targetMs - Date.now();
      const absSeconds = Math.max(1, Math.round(Math.abs(diff) / 1000));
      const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
        ['second', 1],
      ];

      const unit = units.find(([, value]) => absSeconds >= value) || units[units.length - 1];
      const amount = Math.round(absSeconds / unit[1]);
      const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
      return formatter.format(diff >= 0 ? amount : -amount, unit[0]);
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

    function clearOutputs() {
      headerOutput.value = '';
      payloadOutput.value = '';
      segments.textContent = '0';
      algorithm.textContent = '-';
      expiration.textContent = '-';
      state.textContent = '-';
      claims.innerHTML = '';
    }

    function addClaim(label, value) {
      if (value === undefined || value === null || value === '') {
        return;
      }

      const item = document.createElement('li');
      item.textContent = `${label}: ${value}`;
      claims.appendChild(item);
    }

    function renderTokenView(token) {
      const parts = token.split('.');
      segments.textContent = String(parts.length);

      if (!token) {
        clearOutputs();
        setStatus(messages.empty, 'warning');
        return;
      }

      if (parts.length !== 3) {
        clearOutputs();
        segments.textContent = String(parts.length);
        setStatus(messages.malformed, 'error');
        return;
      }

      let headerRaw = '';
      let payloadRaw = '';
      let headerData = null;
      let payloadData = null;
      let headerMessage = '';
      let payloadMessage = '';

      try {
        headerRaw = decodeBase64Url(parts[0]);
        headerData = JSON.parse(headerRaw);
        headerOutput.value = prettyJson(headerRaw);
      } catch (error) {
        headerOutput.value = headerRaw || '';
        headerMessage = messages.invalidHeader;
      }

      try {
        payloadRaw = decodeBase64Url(parts[1]);
        payloadData = JSON.parse(payloadRaw);
        payloadOutput.value = prettyJson(payloadRaw);
      } catch (error) {
        payloadOutput.value = payloadRaw || '';
        payloadMessage = messages.invalidPayload;
      }

      if (!headerData || !payloadData) {
        algorithm.textContent = headerData && typeof headerData.alg === 'string' ? headerData.alg : '-';
        expiration.textContent = '-';
        state.textContent = headerMessage || payloadMessage || messages.malformed;
        claims.innerHTML = '';
        setStatus(headerMessage || payloadMessage || messages.malformed, 'error');
        return;
      }

      const alg = typeof headerData.alg === 'string' ? headerData.alg : '-';
      const typ = typeof headerData.typ === 'string' ? headerData.typ : (typeof payloadData.typ === 'string' ? payloadData.typ : '-');
      const signatureLength = parts[2].length;
      const now = Math.floor(Date.now() / 1000);
      let tokenState = messages.valid;
      let tone = 'success';

      if (typeof payloadData.iss === 'string') {
        addClaim('iss', payloadData.iss);
      }
      if (typeof payloadData.sub === 'string') {
        addClaim('sub', payloadData.sub);
      }
      if (payloadData.aud !== undefined) {
        addClaim('aud', Array.isArray(payloadData.aud) ? payloadData.aud.join(', ') : String(payloadData.aud));
      }
      if (payloadData.jti !== undefined) {
        addClaim('jti', String(payloadData.jti));
      }
      if (typeof payloadData.iat === 'number') {
        addClaim('iat', `${formatDateTime(payloadData.iat)} · ${formatRelative(payloadData.iat)}`);
      }
      if (typeof payloadData.nbf === 'number') {
        addClaim('nbf', `${formatDateTime(payloadData.nbf)} · ${formatRelative(payloadData.nbf)}`);
      }
      if (typeof payloadData.exp === 'number') {
        const expiryText = `${formatDateTime(payloadData.exp)} · ${formatRelative(payloadData.exp)}`;
        expiration.textContent = expiryText;
        addClaim('exp', expiryText);
        if (payloadData.exp < now) {
          tokenState = messages.expired;
          tone = 'warning';
        }
      } else {
        expiration.textContent = messages.noExp;
        tokenState = messages.noExp;
        tone = 'warning';
      }

      if (typeof payloadData.nbf === 'number' && payloadData.nbf > now) {
        tokenState = messages.notYetValid;
        tone = 'warning';
      }

      addClaim('typ', typ);
      addClaim('alg', alg);
      addClaim('signature', `${signatureLength} ${messages.signatureHint}`);

      algorithm.textContent = alg;
      state.textContent = tokenState;
      setStatus(tokenState, tone);
    }

    function loadSampleToken() {
      const header = { alg: 'HS256', typ: 'JWT' };
      const payload = {
        sub: '1234567890',
        name: 'Demo User',
        admin: true,
        iss: 'https://tool.gls.lat',
        aud: 'catnimga-toolbox',
        iat: 1712345678,
        nbf: 1712345678,
        exp: 1893456000,
      };

      jwtInput.value = `${encodeBase64Url(JSON.stringify(header))}.${encodeBase64Url(JSON.stringify(payload))}.sample-signature`;
      renderTokenView(jwtInput.value.trim());
    }

    decodeBtn.addEventListener('click', () => {
      renderTokenView(jwtInput.value.trim());
    });

    copyTokenBtn.addEventListener('click', async () => {
      try {
        const copied = await copyText(jwtInput.value.trim());
        setStatus(copied ? messages.copiedToken : messages.copyFailed, copied ? 'success' : 'warning');
      } catch (error) {
        setStatus(messages.copyFailed, 'warning');
      }
    });

    loadSampleBtn.addEventListener('click', loadSampleToken);

    clearBtn.addEventListener('click', () => {
      jwtInput.value = '';
      clearOutputs();
      setStatus(messages.ready, '');
      pill.textContent = messages.ready;
      pill.className = 'tool-status-pill';
      jwtInput.focus();
    });

    copyHeaderBtn.addEventListener('click', async () => {
      try {
        const copied = await copyText(headerOutput.value);
        setStatus(copied ? messages.copied : messages.copyFailed, copied ? 'success' : 'warning');
      } catch (error) {
        setStatus(messages.copyFailed, 'warning');
      }
    });

    copyPayloadBtn.addEventListener('click', async () => {
      try {
        const copied = await copyText(payloadOutput.value);
        setStatus(copied ? messages.copied : messages.copyFailed, copied ? 'success' : 'warning');
      } catch (error) {
        setStatus(messages.copyFailed, 'warning');
      }
    });

    jwtInput.addEventListener('input', () => {
      renderTokenView(jwtInput.value.trim());
    });

  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
