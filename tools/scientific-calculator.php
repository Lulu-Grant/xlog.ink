<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('scientific_calculator'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-4xl space-y-6">
  <div class="tool-panel">
    <p class="tool-hero-eyebrow"><?= e(trans('scientific_calculator', 'Scientific Calculator')) ?></p>
    <h2 class="tool-hero-title"><?= e(trans('scientific_calculator', 'Scientific Calculator')) ?></h2>
    <p class="tool-hero-copy"><?= e(trans('scientific-calculator_description', 'Use a lightweight scientific calculator in your browser with trig, logs, roots, and exponent shortcuts.')) ?></p>
  </div>

  <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
    <div class="tool-panel">
      <div class="tool-inset-panel rounded-3xl">
        <input
          id="calc-display"
          type="text"
          readonly
          class="w-full border-0 bg-transparent text-right font-mono text-2xl text-slate-900 outline-none dark:text-white"
        />
      </div>

      <div class="mt-4 grid grid-cols-6 gap-2">
      <?php
      $buttons = [
          'C', '←', '(', ')', '/', 'sqrt',
          '7', '8', '9', '*', '^', '%',
          '4', '5', '6', '-', 'sin', 'cos',
          '1', '2', '3', '+', 'tan', 'log',
          '0', '.', '=', 'ln', 'exp', 'pi',
      ];
      foreach ($buttons as $button):
      ?>
        <button
          type="button"
          class="calc-btn tool-keypad-button"
          data-value="<?= e($button) ?>"
        ><?= e($button) ?></button>
      <?php endforeach; ?>
      </div>
    </div>

    <aside class="tool-panel space-y-4">
      <div class="tool-muted-panel tool-help-panel">
        <div class="tool-note-title"><?= e(trans('scientific_calculator', 'Scientific Calculator')) ?></div>
        <p class="mt-2 leading-7"><?= e(trans('scientific-calculator_description', 'Use a lightweight scientific calculator in your browser with trig, logs, roots, and exponent shortcuts.')) ?></p>
      </div>
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('calculator_trig_label', 'Trig')) ?></div>
        <div class="tool-meta-value"><?= e(trans('calculator_trig_note', 'sin / cos / tan use radians')) ?></div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const display = document.getElementById('calc-display');
    const buttons = document.querySelectorAll('.calc-btn');
    const errorLabel = <?= json_encode(trans('calculator_error', 'Error')) ?>;
    let expression = '';

    if (!display || buttons.length === 0) {
      return;
    }

    function updateDisplay() {
      display.value = expression;
    }

    function appendValue(value) {
      expression += value;
      updateDisplay();
    }

    function clearAll() {
      expression = '';
      updateDisplay();
    }

    function backspace() {
      expression = expression.slice(0, -1);
      updateDisplay();
    }

    function calculate() {
      const safeExpression = expression.replace(/\s+/g, '');
      if (!/^[0-9+\-*/%^().a-z]*$/i.test(safeExpression)) {
        display.value = errorLabel;
        expression = '';
        return;
      }

      try {
        const compiled = safeExpression
          .replace(/sqrt/g, 'Math.sqrt')
          .replace(/sin/g, 'Math.sin')
          .replace(/cos/g, 'Math.cos')
          .replace(/tan/g, 'Math.tan')
          .replace(/log\b/g, 'Math.log10')
          .replace(/ln/g, 'Math.log')
          .replace(/exp/g, 'Math.exp')
          .replace(/pi/g, 'Math.PI')
          .replace(/\^/g, '**');

        const result = Function(`"use strict"; return (${compiled});`)();
        if (!Number.isFinite(result)) {
          throw new Error('Invalid result');
        }

        expression = String(result);
        updateDisplay();
      } catch (error) {
        expression = '';
        display.value = errorLabel;
      }
    }

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const value = button.dataset.value;
        switch (value) {
          case 'C':
            clearAll();
            break;
          case '←':
            backspace();
            break;
          case '=':
            calculate();
            break;
          default:
            appendValue(value);
            break;
        }
      });
    });

    document.addEventListener('keydown', (event) => {
      if ((event.key >= '0' && event.key <= '9') || ['+', '-', '*', '/', '%', '.', '(', ')'].includes(event.key)) {
        appendValue(event.key);
      } else if (event.key === 'Enter') {
        event.preventDefault();
        calculate();
      } else if (event.key === 'Backspace') {
        backspace();
      } else if (event.key === 'Escape') {
        clearAll();
      }
    });

    updateDisplay();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
