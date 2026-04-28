<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('timestamp_converter'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-5xl space-y-6">
  <div class="grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
    <article class="tool-panel-compact">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="tool-hero-eyebrow"><?= e(trans('timestamp_converter_label', 'Timestamp converter')) ?></p>
          <h2 class="tool-hero-title"><?= e(trans('timestamp_converter_heading', 'Unix timestamp to date and back')) ?></h2>
          <p class="tool-hero-copy"><?= e(trans('timestamp_converter_intro', 'Keep both fields in sync to quickly jump between epoch seconds and readable date times.')) ?></p>
        </div>
        <button id="use-now" type="button" class="tool-button-secondary shrink-0"><?= e(trans('use_current_time', 'Use current time')) ?></button>
      </div>

      <div class="mt-6 grid gap-4">
        <label class="block">
          <span class="tool-label mb-2"><?= e(trans('unix_timestamp_label', 'Unix timestamp')) ?></span>
          <input id="timestamp-input" inputmode="numeric" type="text" class="tool-control tool-control-filled tool-control-mono text-base placeholder:text-slate-400" placeholder="1712345678" />
          <span class="tool-field-hint"><?= e(trans('timestamp_hint', 'Enter seconds since 1970 or paste a millisecond timestamp.')) ?></span>
        </label>

        <label class="block">
          <span class="tool-label mb-2"><?= e(trans('human_datetime_label', 'Human-readable date and time')) ?></span>
          <input id="datetime-input" type="datetime-local" class="tool-control tool-control-filled text-base" />
          <span class="tool-field-hint"><?= e(trans('datetime_hint', 'Uses your local time zone in the browser.')) ?></span>
        </label>
      </div>
    </article>

    <aside class="tool-panel-compact space-y-4">
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('conversion_result', 'Conversion result')) ?></div>
        <div class="mt-3 space-y-3 text-sm text-slate-700 dark:text-slate-200">
          <div>
            <div class="tool-meta-label"><?= e(trans('timestamp_utc_label', 'UTC date and time')) ?></div>
            <div id="utc-output" class="tool-meta-value">-</div>
          </div>
          <div>
            <div class="tool-meta-label"><?= e(trans('timestamp_iso_label', 'ISO 8601')) ?></div>
            <div id="iso-output" class="tool-meta-value break-all">-</div>
          </div>
          <div>
            <div class="tool-meta-label"><?= e(trans('timestamp_ms_label', 'Milliseconds')) ?></div>
            <div id="ms-output" class="tool-meta-value">-</div>
          </div>
        </div>
      </div>

      <div class="tool-muted-panel rounded-2xl p-4 text-sm">
        <p class="tool-note-title"><?= e(trans('timestamp_tips_title', 'Tips')) ?></p>
        <ul class="mt-2 space-y-2">
          <li><?= e(trans('timestamp_tips_seconds', 'Unix timestamps are usually seconds, while many APIs return milliseconds.')) ?></li>
          <li><?= e(trans('timestamp_tips_local', 'The date field follows your browser time zone.')) ?></li>
          <li><?= e(trans('timestamp_tips_copy', 'You can copy either field and keep editing from there.')) ?></li>
        </ul>
      </div>
    </aside>
  </div>
</section>

<script>
  (function () {
    const timestampInput = document.getElementById('timestamp-input');
    const datetimeInput = document.getElementById('datetime-input');
    const utcOutput = document.getElementById('utc-output');
    const isoOutput = document.getElementById('iso-output');
    const msOutput = document.getElementById('ms-output');
    const useNowButton = document.getElementById('use-now');

    if (!timestampInput || !datetimeInput || !utcOutput || !isoOutput || !msOutput) {
      return;
    }

    let activeSource = 'timestamp';

    function pad(number) {
      return String(number).padStart(2, '0');
    }

    function formatLocalInput(date) {
      const year = date.getFullYear();
      const month = pad(date.getMonth() + 1);
      const day = pad(date.getDate());
      const hours = pad(date.getHours());
      const minutes = pad(date.getMinutes());
      return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    function parseLocalInput(value) {
      if (!value) {
        return null;
      }

      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    function interpretTimestamp(value) {
      const raw = String(value ?? '').trim();
      if (!raw) {
        return null;
      }

      const number = Number(raw);
      if (!Number.isFinite(number)) {
        return null;
      }

      const milliseconds = Math.abs(number) >= 1e12 ? number : number * 1000;
      const date = new Date(milliseconds);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    function updateOutputs(date, timestampValue) {
      if (!date) {
        utcOutput.textContent = '-';
        isoOutput.textContent = '-';
        msOutput.textContent = '-';
        return;
      }

      utcOutput.textContent = new Intl.DateTimeFormat([], {
        timeZone: 'UTC',
        dateStyle: 'medium',
        timeStyle: 'medium'
      }).format(date) + ' UTC';
      isoOutput.textContent = date.toISOString();
      msOutput.textContent = String(Math.round(timestampValue * 1000));
    }

    function syncFromTimestamp() {
      const rawValue = String(timestampInput.value ?? '').trim();
      const rawNumber = Number(rawValue);
      const date = interpretTimestamp(rawValue);
      if (!date) {
        updateOutputs(null);
        return;
      }

      datetimeInput.value = formatLocalInput(date);
      updateOutputs(date, Math.abs(rawNumber) >= 1e12 ? rawNumber / 1000 : rawNumber);
    }

    function syncFromDatetime() {
      const date = parseLocalInput(datetimeInput.value);
      if (!date) {
        updateOutputs(null);
        return;
      }

      const seconds = Math.floor(date.getTime() / 1000);
      timestampInput.value = String(seconds);
      updateOutputs(date, seconds);
    }

    function setNow() {
      const now = new Date();
      timestampInput.value = String(Math.floor(now.getTime() / 1000));
      datetimeInput.value = formatLocalInput(now);
      updateOutputs(now, Math.floor(now.getTime() / 1000));
    }

    timestampInput.addEventListener('input', () => {
      activeSource = 'timestamp';
      syncFromTimestamp();
    });

    datetimeInput.addEventListener('input', () => {
      activeSource = 'datetime';
      syncFromDatetime();
    });

    if (useNowButton) {
      useNowButton.addEventListener('click', setNow);
    }

    const initialNow = new Date();
    timestampInput.value = String(Math.floor(initialNow.getTime() / 1000));
    datetimeInput.value = formatLocalInput(initialNow);
    updateOutputs(initialNow, Math.floor(initialNow.getTime() / 1000));

    if (activeSource === 'timestamp') {
      syncFromTimestamp();
    }
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
