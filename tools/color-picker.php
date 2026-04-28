<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('color_picker'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-4xl space-y-6">
  <div class="tool-panel">
    <p class="tool-hero-eyebrow"><?= e(trans('color_picker', 'Color Picker')) ?></p>
    <h2 class="tool-hero-title"><?= e(trans('choose_color', 'Choose color')) ?></h2>
    <p class="tool-hero-copy"><?= e(trans('color-picker_description', 'Choose a web color and inspect its HEX and RGB values instantly.')) ?></p>
  </div>

  <div class="tool-panel">
    <div class="space-y-5">
      <label for="colorInput" class="tool-label">
        <?= e(trans('choose_color', 'Choose color')) ?>
      </label>

      <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
        <input
          id="colorInput"
          type="color"
          value="#ff0000"
          class="tool-color-input h-24 w-24"
        />

        <div class="flex-1">
          <div id="colorPreview" class="h-24 rounded-2xl border border-slate-200 dark:border-slate-700"></div>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('color_hex_label', 'HEX')) ?></div>
              <div class="tool-stat-value-lg" id="hexCode">#FF0000</div>
            </div>
            <div class="tool-stat-card">
              <div class="tool-stat-label"><?= e(trans('color_rgb_label', 'RGB')) ?></div>
              <div class="tool-stat-value-lg" id="rgbCode">rgb(255, 0, 0)</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('colorInput');
    const preview = document.getElementById('colorPreview');
    const hexCode = document.getElementById('hexCode');
    const rgbCode = document.getElementById('rgbCode');

    if (!input || !preview || !hexCode || !rgbCode) {
      return;
    }

    function hexToRgb(hex) {
      const normalized = hex.replace('#', '');
      const fullHex = normalized.length === 3
        ? normalized.split('').map((char) => char + char).join('')
        : normalized;

      const value = parseInt(fullHex, 16);
      return {
        r: (value >> 16) & 255,
        g: (value >> 8) & 255,
        b: value & 255,
      };
    }

    function updateColor(colorValue) {
      const rgb = hexToRgb(colorValue);
      preview.style.backgroundColor = colorValue;
      hexCode.textContent = colorValue.toUpperCase();
      rgbCode.textContent = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;
    }

    input.addEventListener('input', (event) => {
      updateColor(event.target.value);
    });

    updateColor(input.value);
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
