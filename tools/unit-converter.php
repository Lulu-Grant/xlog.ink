<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('unit_converter'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-4xl space-y-6">
  <div class="tool-panel">
    <p class="tool-hero-eyebrow"><?= e(trans('unit_converter', 'Unit Converter')) ?></p>
    <h2 class="tool-hero-title"><?= e(trans('select_category', 'Select category')) ?></h2>
    <p class="tool-hero-copy"><?= e(trans('unit-converter_description', 'Convert common units online: length, weight, temperature, volume, area, and speed.')) ?></p>
  </div>

  <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
    <div class="tool-panel space-y-4">
      <label class="tool-label">
        <?= e(trans('select_category', 'Select category')) ?>
        <select id="category" class="tool-control mt-2">
        <option value="length"><?= e(trans('unit_length', 'Length')) ?></option>
        <option value="weight"><?= e(trans('unit_weight', 'Weight')) ?></option>
        <option value="temperature"><?= e(trans('unit_temperature', 'Temperature')) ?></option>
        <option value="volume"><?= e(trans('unit_volume', 'Volume')) ?></option>
        <option value="area"><?= e(trans('unit_area', 'Area')) ?></option>
        <option value="speed"><?= e(trans('unit_speed', 'Speed')) ?></option>
        </select>
      </label>

      <div id="converter" class="grid gap-4 md:grid-cols-3"></div>

      <div class="flex flex-wrap gap-3">
        <button id="convertBtn" type="button" class="tool-button-primary"><?= e(trans('convert', 'Convert')) ?></button>
      </div>
    </div>

    <aside class="tool-panel space-y-4">
      <div class="tool-inset-panel">
        <div class="tool-meta-label"><?= e(trans('convert', 'Convert')) ?></div>
        <div id="result" class="tool-meta-value mt-3 text-lg"> <?= e(trans('enter_value_hint', 'Enter a number to convert.')) ?></div>
      </div>
      <div class="tool-muted-panel tool-help-panel">
        <div class="tool-note-title"><?= e(trans('unit_converter', 'Unit Converter')) ?></div>
        <p class="mt-2 leading-7"><?= e(trans('enter_value_hint', 'Enter a number to convert.')) ?></p>
      </div>
    </aside>
  </div>
</section>

<script>
  (function () {
    const categoryField = document.getElementById('category');
    const converter = document.getElementById('converter');
    const result = document.getElementById('result');
    const convertBtn = document.getElementById('convertBtn');

    if (!categoryField || !converter || !result || !convertBtn) {
      return;
    }

    const labels = {
      value: <?= json_encode(trans('value', 'Value')) ?>,
      fromUnit: <?= json_encode(trans('from_unit', 'From unit')) ?>,
      toUnit: <?= json_encode(trans('to_unit', 'To unit')) ?>,
      empty: <?= json_encode(trans('enter_value_hint', 'Enter a number to convert.')) ?>,
    };

    const data = {
      length: {
        units: { m: 1, km: 1000, ft: 0.3048, yd: 0.9144, mi: 1609.34 },
        labels: { m: 'm', km: 'km', ft: 'ft', yd: 'yd', mi: 'mi' }
      },
      weight: {
        units: { g: 1, kg: 1000, lb: 453.592, oz: 28.3495 },
        labels: { g: 'g', kg: 'kg', lb: 'lb', oz: 'oz' }
      },
      temperature: {
        units: ['C', 'F', 'K'],
        labels: { C: '°C', F: '°F', K: 'K' }
      },
      volume: {
        units: { ml: 1, l: 1000, cup_us: 240, pt_us: 473.176, gal_us: 3785.41 },
        labels: { ml: 'ml', l: 'l', cup_us: 'cup (US)', pt_us: 'pt (US)', gal_us: 'gal (US)' }
      },
      area: {
        units: { m2: 1, km2: 1e6, ft2: 0.092903, ac: 4046.86, ha: 10000 },
        labels: { m2: 'm²', km2: 'km²', ft2: 'ft²', ac: 'acre', ha: 'ha' }
      },
      speed: {
        units: { m_s: 1, km_h: 0.277778, mi_h: 0.44704, ft_s: 0.3048 },
        labels: { m_s: 'm/s', km_h: 'km/h', mi_h: 'mph', ft_s: 'ft/s' }
      },
    };

    function formatNumber(value) {
      return new Intl.NumberFormat(undefined, { maximumFractionDigits: 6 }).format(value);
    }

    function buildSelectOptions(units) {
      return units.map((unit) => `<option value="${unit}">${unit.label}</option>`).join('');
    }

    function renderForm() {
      const category = categoryField.value;
      const config = data[category];
      const unitKeys = Array.isArray(config.units) ? config.units : Object.keys(config.units);
      const units = unitKeys.map((unitKey) => ({
        value: unitKey,
        label: (config.labels && config.labels[unitKey]) ? config.labels[unitKey] : unitKey,
      }));
      const options = buildSelectOptions(units);

      converter.innerHTML = `
        <label class="tool-label">
          ${labels.value}
          <input id="inputVal" type="number" step="any" class="tool-control mt-2" />
        </label>
        <label class="tool-label">
          ${labels.fromUnit}
          <select id="fromUnit" class="tool-control mt-2">${options}</select>
        </label>
        <label class="tool-label">
          ${labels.toUnit}
          <select id="toUnit" class="tool-control mt-2">${options}</select>
        </label>
      `;

      const inputVal = document.getElementById('inputVal');
      const fromUnit = document.getElementById('fromUnit');
      const toUnit = document.getElementById('toUnit');

      inputVal.addEventListener('input', convert);
      fromUnit.addEventListener('change', convert);
      toUnit.addEventListener('change', convert);
      convert();
    }

    function convertTemperature(value, from, to) {
      if (from === to) return value;
      if (from === 'C' && to === 'F') return value * 9 / 5 + 32;
      if (from === 'F' && to === 'C') return (value - 32) * 5 / 9;
      if (from === 'C' && to === 'K') return value + 273.15;
      if (from === 'K' && to === 'C') return value - 273.15;
      if (from === 'F' && to === 'K') return (value - 32) * 5 / 9 + 273.15;
      if (from === 'K' && to === 'F') return (value - 273.15) * 9 / 5 + 32;
      return value;
    }

    function convert() {
      const category = categoryField.value;
      const inputVal = document.getElementById('inputVal');
      const fromUnit = document.getElementById('fromUnit');
      const toUnit = document.getElementById('toUnit');

      if (!inputVal || !fromUnit || !toUnit) {
        return;
      }

      const value = parseFloat(inputVal.value);
      if (Number.isNaN(value)) {
        result.textContent = labels.empty;
        return;
      }

      let converted;
      const config = data[category];
      const fromLabel = (config.labels && config.labels[fromUnit.value]) ? config.labels[fromUnit.value] : fromUnit.value;
      const toLabel = (config.labels && config.labels[toUnit.value]) ? config.labels[toUnit.value] : toUnit.value;
      if (category === 'temperature') {
        converted = convertTemperature(value, fromUnit.value, toUnit.value);
      } else {
        const ratios = config.units;
        converted = value * ratios[fromUnit.value] / ratios[toUnit.value];
      }

      result.textContent = `${formatNumber(value)} ${fromLabel} = ${formatNumber(converted)} ${toLabel}`;
    }

    convertBtn.addEventListener('click', convert);
    categoryField.addEventListener('change', renderForm);
    renderForm();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
