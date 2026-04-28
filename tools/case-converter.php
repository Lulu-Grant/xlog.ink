<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('case_converter'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[1.02fr_0.98fr]">
  <section class="tool-panel space-y-5">
    <div>
      <p class="tool-hero-eyebrow"><?= e(trans('case_converter', 'Case Converter')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('case_converter_title', '大小写转换')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('case_converter_intro', '输入一段文本，实时查看不同大小写与 slug 风格的转换结果。')) ?></p>
    </div>

    <label class="tool-label">
      <?= e(trans('case_source_label', 'Source text')) ?>
      <textarea
        id="sourceText"
        rows="10"
        class="tool-textarea mt-2"
        placeholder="<?= e(trans('case_source_placeholder', 'Type or paste text here.')) ?>"
      ><?= e(trans('case_source_sample', '猫柠咔百宝箱 / MaoNingKa Toolbox / hello world from tools.')) ?></textarea>
    </label>

    <div class="flex flex-wrap gap-3">
      <button id="copySourceBtn" type="button" class="tool-button-primary">
        <?= e(trans('copy', 'Copy')) ?>
      </button>
      <button id="resetBtn" type="button" class="tool-button-secondary">
        <?= e(trans('case_reset_button', 'Reset sample')) ?>
      </button>
    </div>
  </section>

  <aside class="space-y-4">
    <section class="tool-panel">
      <div class="flex items-center justify-between gap-3">
        <h3 class="tool-note-title"><?= e(trans('case_results_title', 'Converted results')) ?></h3>
        <span id="resultCount" class="text-sm text-slate-500 dark:text-slate-400">0</span>
      </div>
      <div id="resultsGrid" class="mt-4 grid gap-4"></div>
    </section>

    <section class="tool-panel">
      <h3 class="tool-note-title"><?= e(trans('case_notes_title', 'Notes')) ?></h3>
      <ul class="tool-copy-list mt-4 space-y-3">
        <li><?= e(trans('case_note_one', 'Title and sentence casing handle simple punctuation and spacing.')) ?></li>
        <li><?= e(trans('case_note_two', 'Slug and id-style outputs normalize repeated separators.')) ?></li>
        <li><?= e(trans('case_note_three', 'Copy buttons are available on every generated result.')) ?></li>
      </ul>
    </section>
  </aside>
</section>

<script>
  (function () {
    const sourceText = document.getElementById('sourceText');
    const resultsGrid = document.getElementById('resultsGrid');
    const resultCount = document.getElementById('resultCount');
    const copySourceBtn = document.getElementById('copySourceBtn');
    const resetBtn = document.getElementById('resetBtn');

    if (!sourceText || !resultsGrid || !resultCount || !copySourceBtn || !resetBtn) {
      return;
    }

    const labels = {
      copied: <?= json_encode(trans('copied', 'Copied')) ?>,
      copy: <?= json_encode(trans('copy', 'Copy')) ?>,
      upper: <?= json_encode(trans('case_upper_label', 'UPPERCASE')) ?>,
      lower: <?= json_encode(trans('case_lower_label', 'lowercase')) ?>,
      title: <?= json_encode(trans('case_title_label', 'Title Case')) ?>,
      sentence: <?= json_encode(trans('case_sentence_label', 'Sentence case')) ?>,
      slug: <?= json_encode(trans('case_slug_label', 'Slug')) ?>,
      camel: <?= json_encode(trans('case_camel_label', 'camelCase')) ?>,
      pascal: <?= json_encode(trans('case_pascal_label', 'PascalCase')) ?>,
      snake: <?= json_encode(trans('case_snake_label', 'snake_case')) ?>,
      kebab: <?= json_encode(trans('case_kebab_label', 'kebab-case')) ?>,
      inverse: <?= json_encode(trans('case_inverse_label', 'Inverse Case')) ?>,
      copyAll: <?= json_encode(trans('case_copy_all_label', 'Copy result')) ?>,
    };

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function words(value) {
      return String(value)
        .trim()
        .replace(/[_\-]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .split(/\s+/)
        .filter(Boolean);
    }

    function capitalize(word) {
      if (!word) {
        return '';
      }
      return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
    }

    function toTitleCase(value) {
      return words(value).map(capitalize).join(' ');
    }

    function toSentenceCase(value) {
      const trimmed = String(value).trim().toLowerCase();
      if (!trimmed) {
        return '';
      }
      return trimmed.charAt(0).toUpperCase() + trimmed.slice(1);
    }

    function toSlug(value, separator) {
      return words(value)
        .map((word) => word.toLowerCase())
        .join(separator)
        .replace(new RegExp(`${separator}+`, 'g'), separator)
        .replace(new RegExp(`^${separator}|${separator}$`, 'g'), '');
    }

    function toCamel(value) {
      const parts = words(value).map((word) => word.toLowerCase());
      return parts.map((word, index) => index === 0 ? word : capitalize(word)).join('');
    }

    function toPascal(value) {
      return words(value).map((word) => capitalize(word)).join('');
    }

    function toSnake(value) {
      return toSlug(value, '_');
    }

    function toKebab(value) {
      return toSlug(value, '-');
    }

    function toInverse(value) {
      return String(value).split('').map((char) => {
        const upper = char.toUpperCase();
        const lower = char.toLowerCase();
        return char === upper ? lower : upper;
      }).join('');
    }

    function copyText(text) {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text);
      }

      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'absolute';
      textarea.style.left = '-9999px';
      document.body.appendChild(textarea);
      textarea.select();

      try {
        document.execCommand('copy');
      } finally {
        document.body.removeChild(textarea);
      }

      return Promise.resolve();
    }

    const transforms = [
      { key: 'upper', label: labels.upper, run: (value) => value.toUpperCase() },
      { key: 'lower', label: labels.lower, run: (value) => value.toLowerCase() },
      { key: 'title', label: labels.title, run: toTitleCase },
      { key: 'sentence', label: labels.sentence, run: toSentenceCase },
      { key: 'slug', label: labels.slug, run: (value) => toSlug(value, '-') },
      { key: 'camel', label: labels.camel, run: toCamel },
      { key: 'pascal', label: labels.pascal, run: toPascal },
      { key: 'snake', label: labels.snake, run: toSnake },
      { key: 'kebab', label: labels.kebab, run: toKebab },
      { key: 'inverse', label: labels.inverse, run: toInverse },
    ];

    function render() {
      const source = sourceText.value;
      const items = transforms.map((transform) => {
        const value = transform.run(source);
        return `
          <article class="tool-stat-card">
            <div class="flex items-center justify-between gap-3">
              <h4 class="text-sm font-semibold text-slate-900 dark:text-white">${escapeHtml(transform.label)}</h4>
              <button type="button" class="copy-result-btn tool-button-secondary" data-copy="${escapeHtml(value)}">${escapeHtml(labels.copyAll)}</button>
            </div>
            <div class="tool-inset-panel mt-3 break-words font-mono text-sm text-slate-900 dark:text-white">${escapeHtml(value || '—')}</div>
          </article>
        `;
      }).join('');

      resultsGrid.innerHTML = items;
      resultCount.textContent = `${transforms.length}`;

      resultsGrid.querySelectorAll('.copy-result-btn').forEach((button) => {
        button.addEventListener('click', async () => {
          const value = button.getAttribute('data-copy') || '';
          await copyText(value);
          const original = button.textContent;
          button.textContent = labels.copied;
          setTimeout(() => {
            button.textContent = original;
          }, 1200);
        });
      });
    }

    copySourceBtn.addEventListener('click', async () => {
      await copyText(sourceText.value);
      const original = copySourceBtn.textContent;
      copySourceBtn.textContent = labels.copied;
      setTimeout(() => {
        copySourceBtn.textContent = original;
      }, 1200);
    });

    resetBtn.addEventListener('click', () => {
      sourceText.value = <?= json_encode(trans('case_source_sample', '猫柠咔百宝箱 / MaoNingKa Toolbox / hello world from tools.')) ?>;
      render();
    });

    sourceText.addEventListener('input', render);
    render();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
