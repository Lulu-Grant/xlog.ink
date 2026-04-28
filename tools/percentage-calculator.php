<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('percentage_calculator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-5xl space-y-6">
  <div class="grid gap-6 lg:grid-cols-[1.02fr_0.98fr]">
    <article class="tool-panel-compact space-y-6">
      <div>
        <p class="tool-hero-eyebrow"><?= e(trans('percentage_calculator_label', 'Percentage calculator')) ?></p>
        <h2 class="tool-hero-title"><?= e(trans('percentage_calculator_heading', 'Solve common percentage problems')) ?></h2>
        <p class="tool-hero-copy"><?= e(trans('percentage_calculator_intro', 'Switch between percentage modes to calculate a share, a change, or a percentage from known values.')) ?></p>
      </div>

      <div class="grid gap-4">
        <label class="tool-label">
          <?= e(trans('percentage_mode_label', 'Calculation mode')) ?>
          <select id="mode" class="tool-control mt-2">
            <option value="of"><?= e(trans('percentage_mode_of', 'X of Y%')) ?></option>
            <option value="change"><?= e(trans('percentage_mode_change', 'A to B change')) ?></option>
            <option value="part"><?= e(trans('percentage_mode_part', 'Part of whole')) ?></option>
          </select>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="tool-label">
            <span id="first-label"><?= e(trans('percentage_first_label_of', 'Base value')) ?></span>
            <input id="first-value" type="number" inputmode="decimal" step="any" class="tool-control tool-control-filled mt-2" placeholder="100" />
          </label>

          <label class="tool-label">
            <span id="second-label"><?= e(trans('percentage_second_label_of', 'Percent value')) ?></span>
            <input id="second-value" type="number" inputmode="decimal" step="any" class="tool-control tool-control-filled mt-2" placeholder="15" />
          </label>
        </div>

        <div class="tool-muted-panel tool-help-panel">
          <div id="mode-helper" class="tool-note-title"><?= e(trans('percentage_mode_helper_of', 'Enter a base value and a percentage to calculate the result.')) ?></div>
          <p class="mt-2 leading-7"><?= e(trans('percentage_calculator_note', 'All calculations update instantly and use your browser locale for number formatting.')) ?></p>
        </div>
      </div>
    </article>

    <aside class="tool-panel-compact space-y-4">
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('percentage_result_title', 'Result')) ?></div>
        <div id="percentage-main" class="mt-3 text-2xl font-bold tracking-tight text-slate-900 dark:text-white" aria-live="polite">-</div>
        <p id="percentage-subtitle" class="mt-2 text-sm text-slate-600 dark:text-slate-300" aria-live="polite">
          <?= e(trans('percentage_result_placeholder', 'Choose a mode and enter values to calculate.')) ?>
        </p>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('percentage_value_label', 'Value')) ?></div>
          <div id="percentage-value" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('percentage_rate_label', 'Percent')) ?></div>
          <div id="percentage-rate" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('percentage_delta_label', 'Change')) ?></div>
          <div id="percentage-delta" class="tool-stat-value-lg">-</div>
        </div>
        <div class="tool-stat-card">
          <div class="tool-stat-label"><?= e(trans('percentage_fraction_label', 'Fraction')) ?></div>
          <div id="percentage-fraction" class="tool-stat-value-lg">-</div>
        </div>
      </div>

      <div class="tool-muted-panel tool-help-panel">
        <div class="tool-note-title"><?= e(trans('percentage_examples_title', 'Common uses')) ?></div>
        <ul class="mt-2 space-y-2 leading-7">
          <li><?= e(trans('percentage_example_of', 'Find 15% of 200.')) ?></li>
          <li><?= e(trans('percentage_example_change', 'Compare 120 to 150 and see the change rate.')) ?></li>
          <li><?= e(trans('percentage_example_part', 'Check what percent 25 is of 80.')) ?></li>
        </ul>
      </div>
    </aside>
  </div>
</section>

<script>
  (function () {
    const modeField = document.getElementById('mode');
    const firstValue = document.getElementById('first-value');
    const secondValue = document.getElementById('second-value');
    const firstLabel = document.getElementById('first-label');
    const secondLabel = document.getElementById('second-label');
    const modeHelper = document.getElementById('mode-helper');
    const percentageMain = document.getElementById('percentage-main');
    const percentageSubtitle = document.getElementById('percentage-subtitle');
    const percentageValue = document.getElementById('percentage-value');
    const percentageRate = document.getElementById('percentage-rate');
    const percentageDelta = document.getElementById('percentage-delta');
    const percentageFraction = document.getElementById('percentage-fraction');

    if (!modeField || !firstValue || !secondValue || !firstLabel || !secondLabel || !modeHelper || !percentageMain || !percentageSubtitle || !percentageValue || !percentageRate || !percentageDelta || !percentageFraction) {
      return;
    }

    const formatter = new Intl.NumberFormat(undefined, {
      maximumFractionDigits: 6
    });

    const texts = {
      resultPlaceholder: <?= json_encode(trans('percentage_result_placeholder', 'Choose a mode and enter values to calculate.')) ?>,
      invalid: <?= json_encode(trans('percentage_invalid_input', 'Enter valid numbers in both fields.')) ?>,
      zeroChange: <?= json_encode(trans('percentage_zero_error', 'Cannot calculate percentage change from zero.')) ?>,
      zeroWhole: <?= json_encode(trans('percentage_zero_whole_error', 'The total cannot be zero.')) ?>,
      ofMain: <?= json_encode(trans('percentage_result_of', 'Percent result')) ?>,
      changeMain: <?= json_encode(trans('percentage_result_change', 'Percentage change')) ?>,
      partMain: <?= json_encode(trans('percentage_result_part', 'Percentage of total')) ?>,
      ofHelper: <?= json_encode(trans('percentage_mode_helper_of', 'Enter a base value and a percentage to calculate the result.')) ?>,
      changeHelper: <?= json_encode(trans('percentage_mode_helper_change', 'Compare two numbers and calculate the percentage change.')) ?>,
      partHelper: <?= json_encode(trans('percentage_mode_helper_part', 'Enter a part value and the whole to find the percentage.')) ?>,
      baseLabel: <?= json_encode(trans('percentage_first_label_of', 'Base value')) ?>,
      percentLabel: <?= json_encode(trans('percentage_second_label_of', 'Percent value')) ?>,
      originalLabel: <?= json_encode(trans('percentage_first_label_change', 'Original value')) ?>,
      newLabel: <?= json_encode(trans('percentage_second_label_change', 'New value')) ?>,
      partLabel: <?= json_encode(trans('percentage_first_label_part', 'Part value')) ?>,
      wholeLabel: <?= json_encode(trans('percentage_second_label_part', 'Whole value')) ?>,
      resultLabel: <?= json_encode(trans('percentage_value_label', 'Value')) ?>,
      rateLabel: <?= json_encode(trans('percentage_rate_label', 'Percent')) ?>,
      deltaLabel: <?= json_encode(trans('percentage_delta_label', 'Change')) ?>,
      fractionLabel: <?= json_encode(trans('percentage_fraction_label', 'Fraction')) ?>,
      ofPattern: <?= json_encode(trans('percentage_pattern_of', '{percent}% of {base} = {result}')) ?>,
      changePattern: <?= json_encode(trans('percentage_pattern_change', '{old} to {new} = {change}')) ?>,
      partPattern: <?= json_encode(trans('percentage_pattern_part', '{part} out of {whole} = {percent}%')) ?>,
    };

    function parseNumber(value) {
      const number = Number(String(value ?? '').trim());
      return Number.isFinite(number) ? number : null;
    }

    function format(value) {
      return formatter.format(value);
    }

    function setLabels(mode) {
      if (mode === 'change') {
        firstLabel.textContent = texts.originalLabel;
        secondLabel.textContent = texts.newLabel;
        firstValue.placeholder = '120';
        secondValue.placeholder = '150';
        modeHelper.textContent = texts.changeHelper;
      } else if (mode === 'part') {
        firstLabel.textContent = texts.partLabel;
        secondLabel.textContent = texts.wholeLabel;
        firstValue.placeholder = '25';
        secondValue.placeholder = '80';
        modeHelper.textContent = texts.partHelper;
      } else {
        firstLabel.textContent = texts.baseLabel;
        secondLabel.textContent = texts.percentLabel;
        firstValue.placeholder = '200';
        secondValue.placeholder = '15';
        modeHelper.textContent = texts.ofHelper;
      }
    }

    function renderInvalid(message) {
      percentageMain.textContent = '-';
      percentageSubtitle.textContent = message || texts.invalid;
      percentageValue.textContent = '-';
      percentageRate.textContent = '-';
      percentageDelta.textContent = '-';
      percentageFraction.textContent = '-';
    }

    function update() {
      const mode = modeField.value;
      setLabels(mode);

      const first = parseNumber(firstValue.value);
      const second = parseNumber(secondValue.value);

      if (first === null || second === null) {
        renderInvalid(texts.resultPlaceholder);
        return;
      }

      if (mode === 'of') {
        const result = first * second / 100;
        percentageMain.textContent = texts.ofMain;
        percentageSubtitle.textContent = texts.ofPattern
          .replace('{percent}', format(second))
          .replace('{base}', format(first))
          .replace('{result}', format(result));
        percentageValue.textContent = format(result);
        percentageRate.textContent = format(second) + '%';
        percentageDelta.textContent = format(result - first);
        percentageFraction.textContent = format(result) + ' / ' + format(first);
        return;
      }

      if (mode === 'change') {
        if (first === 0) {
          renderInvalid(texts.zeroChange);
          return;
        }

        const change = ((second - first) / first) * 100;
        const delta = second - first;
        percentageMain.textContent = texts.changeMain;
        percentageSubtitle.textContent = texts.changePattern
          .replace('{old}', format(first))
          .replace('{new}', format(second))
          .replace('{change}', format(change) + '%');
        percentageValue.textContent = format(change) + '%';
        percentageRate.textContent = format(change) + '%';
        percentageDelta.textContent = (delta >= 0 ? '+' : '') + format(delta);
        percentageFraction.textContent = format(delta) + ' / ' + format(first);
        return;
      }

      if (second === 0) {
        renderInvalid(texts.zeroWhole);
        return;
      }

      const percent = (first / second) * 100;
      percentageMain.textContent = texts.partMain;
      percentageSubtitle.textContent = texts.partPattern
        .replace('{part}', format(first))
        .replace('{whole}', format(second))
        .replace('{percent}', format(percent));
      percentageValue.textContent = format(percent) + '%';
      percentageRate.textContent = format(percent) + '%';
      percentageDelta.textContent = format(first - second);
      percentageFraction.textContent = format(first) + ' / ' + format(second);
    }

    modeField.addEventListener('change', update);
    firstValue.addEventListener('input', update);
    secondValue.addEventListener('input', update);

    modeField.value = 'of';
    firstValue.value = '200';
    secondValue.value = '15';
    setLabels('of');
    update();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
