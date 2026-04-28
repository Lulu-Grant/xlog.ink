<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('age_calculator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-5xl space-y-6">
  <div class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
    <article class="tool-panel-compact space-y-6">
      <div>
        <p class="tool-hero-eyebrow"><?= e(trans('age_calculator_label', 'Age calculator')) ?></p>
        <h2 class="tool-hero-title"><?= e(trans('age_calculator_heading', 'Find age in years, months, and days')) ?></h2>
        <p class="tool-hero-copy"><?= e(trans('age_calculator_intro', 'Pick a birth date and compare it with today or any custom reference date.')) ?></p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="tool-label">
          <?= e(trans('birth_date_label', 'Birth date')) ?>
          <input id="birth-date" type="date" class="tool-control tool-control-filled mt-2" />
        </label>

        <label class="tool-label">
          <?= e(trans('reference_date_label', 'Reference date')) ?>
          <input id="reference-date" type="date" class="tool-control tool-control-filled mt-2" />
        </label>
      </div>

      <div class="flex flex-wrap gap-3">
        <button id="use-today" type="button" class="tool-button-secondary">
          <?= e(trans('use_today', 'Use today')) ?>
        </button>
      </div>

      <p class="tool-status-text">
        <?= e(trans('age_calculator_hint', 'The result is calculated using calendar years, months, and days in your local time zone.')) ?>
      </p>
    </article>

    <aside class="tool-panel-compact space-y-4">
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('age_result_title', 'Age result')) ?></div>
        <div id="age-main" class="mt-3 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">-</div>
        <p id="age-subtitle" class="mt-2 text-sm text-slate-600 dark:text-slate-300">
          <?= e(trans('age_result_placeholder', 'Choose a birth date to see the age breakdown.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('age_years_label', 'Years')) ?></div>
          <div id="age-years" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('age_months_label', 'Months')) ?></div>
          <div id="age-months" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('age_days_label', 'Days')) ?></div>
          <div id="age-days" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('age_total_days_label', 'Total days lived')) ?></div>
          <div id="age-total-days" class="tool-stat-value-lg">-</div>
        </div>
      </div>

      <div class="tool-muted-panel tool-help-panel">
        <div class="tool-note-title"><?= e(trans('age_calculator_notes_title', 'Notes')) ?></div>
        <p class="mt-2 leading-7"><?= e(trans('age_calculator_notes_body', 'Use the reference date to check age on a specific day, or keep it set to today for the current age.')) ?></p>
      </div>
    </aside>
  </div>
</section>

<script>
  (function () {
    const birthDateInput = document.getElementById('birth-date');
    const referenceDateInput = document.getElementById('reference-date');
    const useTodayButton = document.getElementById('use-today');
    const ageMain = document.getElementById('age-main');
    const ageSubtitle = document.getElementById('age-subtitle');
    const ageYears = document.getElementById('age-years');
    const ageMonths = document.getElementById('age-months');
    const ageDays = document.getElementById('age-days');
    const ageTotalDays = document.getElementById('age-total-days');

    if (!birthDateInput || !referenceDateInput || !ageMain || !ageSubtitle || !ageYears || !ageMonths || !ageDays || !ageTotalDays) {
      return;
    }

    const texts = {
      empty: <?= json_encode(trans('age_result_placeholder', 'Choose a birth date to see the age breakdown.')) ?>,
      invalid: <?= json_encode(trans('age_invalid_range', 'The birth date cannot be later than the reference date.')) ?>,
      asOf: <?= json_encode(trans('age_as_of_label', 'As of')) ?>,
      years: <?= json_encode(trans('age_years_label', 'Years')) ?>,
      months: <?= json_encode(trans('age_months_label', 'Months')) ?>,
      days: <?= json_encode(trans('age_days_label', 'Days')) ?>,
      totalDays: <?= json_encode(trans('age_total_days_label', 'Total days lived')) ?>,
      and: <?= json_encode(trans('age_and_connector', 'and')) ?>,
    };

    function pad(number) {
      return String(number).padStart(2, '0');
    }

    function formatDateInput(date) {
      return [
        date.getFullYear(),
        pad(date.getMonth() + 1),
        pad(date.getDate())
      ].join('-');
    }

    function parseDateInput(value) {
      if (!value) {
        return null;
      }

      const parts = value.split('-').map(Number);
      if (parts.length !== 3 || parts.some(Number.isNaN)) {
        return null;
      }

      const [year, month, day] = parts;
      const date = new Date(year, month - 1, day);

      if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
      ) {
        return null;
      }

      return date;
    }

    function dateOnlyUtc(date) {
      return Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function describeAge(result) {
      const parts = [];
      if (result.years) {
        parts.push(result.years + ' ' + texts.years);
      }
      if (result.months) {
        parts.push(result.months + ' ' + texts.months);
      }
      if (result.days || !parts.length) {
        parts.push(result.days + ' ' + texts.days);
      }

      return parts.join(', ');
    }

    function renderEmpty(message) {
      ageMain.textContent = '-';
      ageSubtitle.textContent = message || texts.empty;
      ageYears.textContent = '-';
      ageMonths.textContent = '-';
      ageDays.textContent = '-';
      ageTotalDays.textContent = '-';
    }

    function calculateAge(birthDate, referenceDate) {
      if (!birthDate || !referenceDate) {
        return null;
      }

      if (referenceDate < birthDate) {
        return { error: texts.invalid };
      }

      let years = referenceDate.getFullYear() - birthDate.getFullYear();
      let months = referenceDate.getMonth() - birthDate.getMonth();
      let days = referenceDate.getDate() - birthDate.getDate();

      if (days < 0) {
        months -= 1;
        const previousMonth = new Date(referenceDate.getFullYear(), referenceDate.getMonth(), 0);
        days += previousMonth.getDate();
      }

      if (months < 0) {
        years -= 1;
        months += 12;
      }

      const totalDays = Math.floor((dateOnlyUtc(referenceDate) - dateOnlyUtc(birthDate)) / 86400000);

      return {
        years: Math.max(0, years),
        months: Math.max(0, months),
        days: Math.max(0, days),
        totalDays: Math.max(0, totalDays)
      };
    }

    function updateAge() {
      const birthDate = parseDateInput(birthDateInput.value);
      const referenceDate = parseDateInput(referenceDateInput.value);

      if (!birthDate || !referenceDate) {
        renderEmpty();
        return;
      }

      const result = calculateAge(birthDate, referenceDate);
      if (!result) {
        renderEmpty();
        return;
      }

      if (result.error) {
        renderEmpty(result.error);
        return;
      }

      ageMain.textContent = describeAge(result);
      ageSubtitle.textContent = texts.asOf + ' ' + formatDateInput(referenceDate);
      ageYears.textContent = String(result.years);
      ageMonths.textContent = String(result.months);
      ageDays.textContent = String(result.days);
      ageTotalDays.textContent = String(result.totalDays);
    }

    function setToday() {
      referenceDateInput.value = formatDateInput(new Date());
      updateAge();
    }

    birthDateInput.addEventListener('input', updateAge);
    referenceDateInput.addEventListener('input', updateAge);
    useTodayButton.addEventListener('click', setToday);

    const today = new Date();
    const defaultBirth = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
    birthDateInput.value = formatDateInput(defaultBirth);
    referenceDateInput.value = formatDateInput(today);
    updateAge();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
