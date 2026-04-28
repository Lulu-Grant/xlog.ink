<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('date_difference_calculator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-5xl space-y-6">
  <div class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
    <article class="tool-panel-compact">
      <p class="tool-hero-eyebrow"><?= e(trans('date_difference_label', 'Date difference calculator')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('date_difference_heading', 'Compare two moments and read the gap clearly')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('date_difference_intro', 'Use this tool to measure the distance between two dates or times in days, hours, minutes, and seconds.')) ?></p>

      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="tool-label mb-2"><?= e(trans('start_datetime_label', 'Start date and time')) ?></span>
          <input id="start-input" type="datetime-local" class="tool-control tool-control-filled text-base" />
        </label>

        <label class="block">
          <span class="tool-label mb-2"><?= e(trans('end_datetime_label', 'End date and time')) ?></span>
          <input id="end-input" type="datetime-local" class="tool-control tool-control-filled text-base" />
        </label>
      </div>

      <div class="mt-4 flex flex-wrap gap-3">
        <button id="set-now-start" type="button" class="tool-button-secondary"><?= e(trans('set_start_now', 'Set start to now')) ?></button>
        <button id="set-now-end" type="button" class="tool-button-secondary"><?= e(trans('set_end_now', 'Set end to now')) ?></button>
        <button id="swap-dates" type="button" class="tool-button-secondary"><?= e(trans('swap_dates', 'Swap dates')) ?></button>
      </div>
    </article>

    <aside class="tool-panel-compact space-y-4">
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('difference_result', 'Difference result')) ?></div>
        <div class="mt-3 space-y-4">
          <div>
            <div id="difference-main" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">-</div>
            <p id="difference-subtitle" class="tool-copy"><?= e(trans('difference_placeholder', 'Choose two values to see the result.')) ?></p>
          </div>

          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('difference_days', 'Days')) ?></div>
              <div id="days-output" class="tool-stat-value-lg">-</div>
            </div>
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('difference_hours', 'Hours')) ?></div>
              <div id="hours-output" class="tool-stat-value-lg">-</div>
            </div>
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('difference_minutes', 'Minutes')) ?></div>
              <div id="minutes-output" class="tool-stat-value-lg">-</div>
            </div>
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('difference_seconds', 'Seconds')) ?></div>
              <div id="seconds-output" class="tool-stat-value-lg">-</div>
            </div>
          </div>

          <div class="tool-muted-panel rounded-2xl p-4 text-sm">
            <p class="tool-note-title"><?= e(trans('difference_notes_title', 'Notes')) ?></p>
            <ul class="mt-2 space-y-2">
              <li><?= e(trans('difference_notes_abs', 'The breakdown shows the absolute difference, no matter which date is earlier.')) ?></li>
              <li><?= e(trans('difference_notes_signed', 'The headline also tells you which date comes first.')) ?></li>
              <li><?= e(trans('difference_notes_local', 'Inputs use your browser time zone.')) ?></li>
            </ul>
          </div>
        </div>
      </div>
    </aside>
  </div>
</section>

<script>
  (function () {
    const startInput = document.getElementById('start-input');
    const endInput = document.getElementById('end-input');
    const setNowStart = document.getElementById('set-now-start');
    const setNowEnd = document.getElementById('set-now-end');
    const swapDates = document.getElementById('swap-dates');
    const differenceMain = document.getElementById('difference-main');
    const differenceSubtitle = document.getElementById('difference-subtitle');
    const daysOutput = document.getElementById('days-output');
    const hoursOutput = document.getElementById('hours-output');
    const minutesOutput = document.getElementById('minutes-output');
    const secondsOutput = document.getElementById('seconds-output');

    if (!startInput || !endInput || !differenceMain || !differenceSubtitle) {
      return;
    }

    function pad(number) {
      return String(number).padStart(2, '0');
    }

    function formatLocalInput(date) {
      return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate())
      ].join('-') + 'T' + [pad(date.getHours()), pad(date.getMinutes())].join(':');
    }

    function parseInput(value) {
      if (!value) {
        return null;
      }

      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? null : date;
    }

    function plural(value, label) {
      return value + ' ' + label + (value === 1 ? '' : 's');
    }

    function renderEmpty() {
      differenceMain.textContent = '-';
      differenceSubtitle.textContent = <?= json_encode(trans('difference_placeholder', 'Choose two values to see the result.')) ?>;
      daysOutput.textContent = '-';
      hoursOutput.textContent = '-';
      minutesOutput.textContent = '-';
      secondsOutput.textContent = '-';
    }

    function updateDifference() {
      const startDate = parseInput(startInput.value);
      const endDate = parseInput(endInput.value);

      if (!startDate || !endDate) {
        renderEmpty();
        return;
      }

      const diffMs = endDate.getTime() - startDate.getTime();
      const absSeconds = Math.floor(Math.abs(diffMs) / 1000);
      const absMinutes = Math.floor(absSeconds / 60);
      const absHours = Math.floor(absMinutes / 60);
      const absDays = Math.floor(absHours / 24);
      const remainingHours = absHours % 24;
      const remainingMinutes = absMinutes % 60;
      const remainingSeconds = absSeconds % 60;
      const isFuture = diffMs >= 0;

      daysOutput.textContent = String(absDays);
      hoursOutput.textContent = String(absHours);
      minutesOutput.textContent = String(absMinutes);
      secondsOutput.textContent = String(absSeconds);

      const parts = [];
      if (absDays) {
        parts.push(plural(absDays, <?= json_encode(trans('difference_day_unit', 'day')) ?>));
      }
      if (remainingHours) {
        parts.push(plural(remainingHours, <?= json_encode(trans('difference_hour_unit', 'hour')) ?>));
      }
      if (remainingMinutes) {
        parts.push(plural(remainingMinutes, <?= json_encode(trans('difference_minute_unit', 'minute')) ?>));
      }
      if (remainingSeconds || !parts.length) {
        parts.push(plural(remainingSeconds, <?= json_encode(trans('difference_second_unit', 'second')) ?>));
      }

      const prefix = isFuture ? <?= json_encode(trans('difference_ahead_prefix', 'End is later than start by')) ?> : <?= json_encode(trans('difference_behind_prefix', 'End is earlier than start by')) ?>;
      differenceMain.textContent = prefix + ' ' + parts.join(' ');
      differenceSubtitle.textContent = startDate.toLocaleString() + ' → ' + endDate.toLocaleString();
    }

    function setNow(input) {
      input.value = formatLocalInput(new Date());
      updateDifference();
    }

    function initialize() {
      const now = new Date();
      const later = new Date(now.getTime() + 26 * 60 * 60 * 1000 + 15 * 60 * 1000);
      startInput.value = formatLocalInput(now);
      endInput.value = formatLocalInput(later);
      updateDifference();
    }

    startInput.addEventListener('input', updateDifference);
    endInput.addEventListener('input', updateDifference);

    if (setNowStart) {
      setNowStart.addEventListener('click', () => setNow(startInput));
    }
    if (setNowEnd) {
      setNowEnd.addEventListener('click', () => setNow(endInput));
    }
    if (swapDates) {
      swapDates.addEventListener('click', () => {
        const startValue = startInput.value;
        startInput.value = endInput.value;
        endInput.value = startValue;
        updateDifference();
      });
    }

    initialize();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
