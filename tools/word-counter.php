<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('word_counter'));

include __DIR__ . '/../inc/header.php';
?>

<section class="rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-white to-slate-50 p-6 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-950">
  <div class="mb-6 max-w-3xl">
    <p class="text-sm uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400"><?= e(trans('word_counter_live', 'Live update')) ?></p>
    <p class="mt-3 text-base leading-7 text-slate-600 dark:text-slate-300"><?= e(trans('word_counter_intro', 'Paste or type text below and the counts update instantly.')) ?></p>
  </div>

  <div class="grid gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(260px,0.85fr)]">
    <div class="space-y-4">
      <label for="wordCounterInput" class="tool-label">
        <?= e(trans('word_counter_input_label', 'Text to analyze')) ?>
      </label>
      <textarea
        id="wordCounterInput"
        rows="12"
        spellcheck="false"
        autocomplete="off"
        placeholder="<?= e(trans('word_counter_placeholder', 'Paste or type your text here...')) ?>"
        class="tool-textarea min-h-72 text-base leading-7"
      ></textarea>

      <div class="flex flex-wrap items-center gap-3">
        <button
          id="wordCounterClear"
          type="button"
          class="tool-button-secondary"
        >
          <?= e(trans('word_counter_clear', 'Clear text')) ?>
        </button>
        <p class="tool-status-text"><?= e(trans('word_counter_note', 'A blank line starts a new paragraph. Characters include spaces and punctuation.')) ?></p>
      </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
      <div class="tool-kpi-card">
        <div class="tool-kpi-label"><?= e(trans('word_counter_characters', 'Characters')) ?></div>
        <div id="wordCounterCharacters" class="tool-kpi-number">0</div>
      </div>
      <div class="tool-kpi-card">
        <div class="tool-kpi-label"><?= e(trans('word_counter_words', 'Words')) ?></div>
        <div id="wordCounterWords" class="tool-kpi-number">0</div>
      </div>
      <div class="tool-kpi-card">
        <div class="tool-kpi-label"><?= e(trans('word_counter_lines', 'Lines')) ?></div>
        <div id="wordCounterLines" class="tool-kpi-number">0</div>
      </div>
      <div class="tool-kpi-card">
        <div class="tool-kpi-label"><?= e(trans('word_counter_paragraphs', 'Paragraphs')) ?></div>
        <div id="wordCounterParagraphs" class="tool-kpi-number">0</div>
      </div>
      <div class="tool-muted-panel tool-help-panel sm:col-span-2 lg:col-span-1">
        <div class="tool-note-title"><?= e(trans('word_counter_ready', 'Ready')) ?></div>
        <p class="mt-2 leading-7"><?= e(trans('word_counter_help', 'Counts include characters, words, lines, and paragraphs.')) ?></p>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('wordCounterInput');
    const clearButton = document.getElementById('wordCounterClear');
    const charactersEl = document.getElementById('wordCounterCharacters');
    const wordsEl = document.getElementById('wordCounterWords');
    const linesEl = document.getElementById('wordCounterLines');
    const paragraphsEl = document.getElementById('wordCounterParagraphs');

    if (!input || !clearButton || !charactersEl || !wordsEl || !linesEl || !paragraphsEl) {
      return;
    }

    function countCharacters(text) {
      return Array.from(text).length;
    }

    function countWords(text) {
      const trimmed = text.trim();
      if (!trimmed) {
        return 0;
      }

      if (window.Intl && typeof Intl.Segmenter === 'function') {
        try {
          const segmenter = new Intl.Segmenter(undefined, { granularity: 'word' });
          let total = 0;
          for (const segment of segmenter.segment(text)) {
            if (segment.isWordLike) {
              total += 1;
            }
          }
          return total;
        } catch (error) {}
      }

      const matches = text.match(/[\p{Script=Han}\p{L}\p{N}]+(?:['’_-][\p{Script=Han}\p{L}\p{N}]+)*/gu);
      return matches ? matches.length : 0;
    }

    function countLines(text) {
      if (text === '') {
        return 0;
      }

      return text.replace(/\r\n/g, '\n').split('\n').length;
    }

    function countParagraphs(text) {
      const trimmed = text.replace(/\r\n/g, '\n').trim();
      if (!trimmed) {
        return 0;
      }

      return trimmed.split(/\n\s*\n+/).filter(Boolean).length;
    }

    function updateCounts() {
      const value = input.value;
      charactersEl.textContent = String(countCharacters(value));
      wordsEl.textContent = String(countWords(value));
      linesEl.textContent = String(countLines(value));
      paragraphsEl.textContent = String(countParagraphs(value));
    }

    input.addEventListener('input', updateCounts);
    clearButton.addEventListener('click', () => {
      input.value = '';
      updateCounts();
      input.focus();
    });

    updateCounts();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
