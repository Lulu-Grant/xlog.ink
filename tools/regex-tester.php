<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('regex_tester'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
  <section class="tool-panel space-y-5">
    <div>
      <p class="tool-hero-eyebrow"><?= e(trans('regex_tester', 'Regex Tester')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('regex_tester_title', 'Regex tester')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('regex_tester_intro', 'Enter a pattern, flags, and sample text to inspect matches and errors instantly.')) ?></p>
    </div>

    <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
      <label class="tool-label">
        <?= e(trans('regex_pattern_label', 'Pattern')) ?>
        <input
          id="patternInput"
          type="text"
          value="\\b\\w+\\b"
          class="tool-control mt-2 font-mono"
          placeholder="<?= e(trans('regex_pattern_placeholder', '\\b\\w+\\b')) ?>"
        />
      </label>

      <label class="tool-label">
        <?= e(trans('regex_flags_label', 'Flags')) ?>
        <input
          id="flagsInput"
          type="text"
          value="g"
          maxlength="4"
          class="tool-control mt-2 w-32 font-mono"
          placeholder="<?= e(trans('regex_flags_placeholder', 'gim')) ?>"
        />
      </label>
    </div>

    <label class="tool-label">
      <?= e(trans('regex_input_label', 'Input text')) ?>
      <textarea
        id="textInput"
        rows="10"
        class="tool-textarea mt-2"
        placeholder="<?= e(trans('regex_input_placeholder', 'Paste text to test your pattern.')) ?>"
      ><?= e(trans('regex_input_sample', '猫柠咔百宝箱 / MaoNingKa Toolbox / Regex tester sample text.')) ?></textarea>
    </label>

    <div class="flex flex-wrap gap-3">
      <button id="runBtn" type="button" class="tool-button-primary">
        <?= e(trans('regex_run_button', 'Run test')) ?>
      </button>
      <button id="clearBtn" type="button" class="tool-button-secondary">
        <?= e(trans('regex_clear_button', 'Clear')) ?>
      </button>
    </div>

    <div class="tool-inset-panel">
      <div class="tool-meta-label"><?= e(trans('regex_status_label', 'Status')) ?></div>
      <div id="statusLine" class="tool-meta-value text-slate-700 dark:text-slate-100" aria-live="polite">
        <?= e(trans('regex_status_ready', 'Ready.')) ?>
      </div>
    </div>
  </section>

  <aside class="space-y-4">
    <section class="tool-panel">
      <h3 class="tool-note-title"><?= e(trans('regex_matches_title', 'Matches')) ?></h3>
      <div id="summaryLine" class="mt-2 text-sm text-slate-600 dark:text-slate-300" aria-live="polite">
        <?= e(trans('regex_summary_empty', 'Waiting for a pattern.')) ?>
      </div>
      <div id="matchesList" class="mt-4 space-y-3"></div>
    </section>

    <section class="tool-panel">
      <h3 class="tool-note-title"><?= e(trans('regex_groups_title', 'Capture groups')) ?></h3>
      <div id="groupsList" class="mt-4 space-y-3 text-sm text-slate-600 dark:text-slate-300" aria-live="polite">
        <?= e(trans('regex_groups_empty', 'No matches yet.')) ?>
      </div>
    </section>
  </aside>
</section>

<script>
  (function () {
    const patternInput = document.getElementById('patternInput');
    const flagsInput = document.getElementById('flagsInput');
    const textInput = document.getElementById('textInput');
    const runBtn = document.getElementById('runBtn');
    const clearBtn = document.getElementById('clearBtn');
    const statusLine = document.getElementById('statusLine');
    const summaryLine = document.getElementById('summaryLine');
    const matchesList = document.getElementById('matchesList');
    const groupsList = document.getElementById('groupsList');

    if (!patternInput || !flagsInput || !textInput || !runBtn || !clearBtn || !statusLine || !summaryLine || !matchesList || !groupsList) {
      return;
    }

    const labels = {
      invalidFlags: <?= json_encode(trans('regex_error_invalid_flags', 'Invalid flags')) ?>,
      invalidPattern: <?= json_encode(trans('regex_error_invalid_pattern', 'Invalid pattern')) ?>,
      noMatches: <?= json_encode(trans('regex_no_matches', 'No matches found.')) ?>,
      matchesFound: <?= json_encode(trans('regex_matches_found', 'Matches found:')) ?>,
      oneMatch: <?= json_encode(trans('regex_match_one', 'match')) ?>,
      manyMatches: <?= json_encode(trans('regex_match_many', 'matches')) ?>,
      groupsLabel: <?= json_encode(trans('regex_groups_label', 'Groups')) ?>,
      indexLabel: <?= json_encode(trans('regex_match_index_label', 'Match')) ?>,
      emptyInput: <?= json_encode(trans('regex_empty_input', 'The input text is empty.')) ?>,
      copyText: <?= json_encode(trans('copy', 'Copy')) ?>,
      copied: <?= json_encode(trans('copied', 'Copied')) ?>,
    };

    function escapeHtml(value) {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function normalizeFlags(value) {
      return Array.from(new Set(String(value || '').replace(/[^gimsuy]/g, '')))
        .join('');
    }

    function buildRegex(pattern, flags) {
      return new RegExp(pattern, flags);
    }

    function renderEmpty(message) {
      matchesList.innerHTML = '';
      groupsList.innerHTML = `<div class="tool-muted-panel tool-help-panel">${escapeHtml(message)}</div>`;
    }

    function renderError(message) {
      statusLine.textContent = message;
      summaryLine.textContent = message;
      renderEmpty(message);
    }

    function render() {
      const pattern = patternInput.value.trim();
      const rawFlags = String(flagsInput.value || '').trim();
      const text = textInput.value;

      if (/[^gimsuy]/.test(rawFlags)) {
        const message = `${labels.invalidFlags}: ${rawFlags}`;
        renderError(message);
        return;
      }

      const flags = normalizeFlags(rawFlags);
      flagsInput.value = flags;

      if (!pattern) {
        renderEmpty(labels.emptyInput);
        statusLine.textContent = labels.emptyInput;
        summaryLine.textContent = labels.emptyInput;
        return;
      }

      let regex;
      try {
        regex = buildRegex(pattern, flags);
      } catch (error) {
        const message = `${labels.invalidPattern}: ${error.message || error}`;
        renderError(message);
        return;
      }

      const matches = [];

      try {
        if (flags.includes('g')) {
          let match;
          while ((match = regex.exec(text)) !== null) {
            matches.push(match);
            if (match[0] === '') {
              regex.lastIndex += 1;
            }
          }
        } else {
          const match = regex.exec(text);
          if (match) {
            matches.push(match);
          }
        }
      } catch (error) {
        const message = `${labels.invalidPattern}: ${error.message || error}`;
        renderError(message);
        return;
      }

      if (!matches.length) {
        renderEmpty(labels.noMatches);
        statusLine.textContent = labels.noMatches;
        summaryLine.textContent = labels.noMatches;
        return;
      }

      const total = matches.length;
      const suffix = total === 1 ? labels.oneMatch : labels.manyMatches;
      const source = flags.includes('g') ? `${labels.matchesFound} ${total} ${suffix}` : labels.matchesFound;
      statusLine.textContent = source;
      summaryLine.textContent = source;

      matchesList.innerHTML = matches.map((match, index) => {
        const groups = match.slice(1).map((group, groupIndex) => {
          const value = group === undefined ? 'undefined' : String(group);
          return `<span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">#${groupIndex + 1}: ${escapeHtml(value)}</span>`;
        }).join(' ');

        return `
          <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950/70">
            <div class="flex items-center justify-between gap-3">
              <div class="text-sm font-semibold text-slate-900 dark:text-white">${labels.indexLabel} ${index + 1}</div>
              <button type="button" class="copy-match-btn tool-button-secondary" data-copy="${escapeHtml(match[0])}">${labels.copyText}</button>
            </div>
            <div class="mt-3 rounded-xl bg-white px-3 py-2 font-mono text-sm text-slate-900 dark:bg-slate-900 dark:text-white">${escapeHtml(match[0])}</div>
            <div class="mt-3 text-xs text-slate-500 dark:text-slate-400">${escapeHtml(`index=${match.index}, length=${match[0].length}`)}</div>
            ${match.length > 1 ? `<div class="mt-3 flex flex-wrap gap-2">${groups}</div>` : ''}
          </div>
        `;
      }).join('');

      const first = matches[0];
      const groups = first.length > 1
        ? first.slice(1).map((group, index) => `<div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-950/70"><div class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">${labels.groupsLabel} ${index + 1}</div><div class="mt-1 font-mono text-sm text-slate-900 dark:text-white">${escapeHtml(group === undefined ? 'undefined' : String(group))}</div></div>`).join('')
        : `<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-950/60 dark:text-slate-400">${escapeHtml(labels.noMatches)}</div>`;

      groupsList.innerHTML = groups;

      matchesList.querySelectorAll('.copy-match-btn').forEach((button) => {
        button.addEventListener('click', async () => {
          const value = button.getAttribute('data-copy') || '';
          try {
            await navigator.clipboard.writeText(value);
            button.textContent = labels.copied;
            setTimeout(() => {
              button.textContent = labels.copyText;
            }, 1200);
          } catch (error) {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            try {
              document.execCommand('copy');
              button.textContent = labels.copied;
              setTimeout(() => {
                button.textContent = labels.copyText;
              }, 1200);
            } finally {
              document.body.removeChild(textarea);
            }
          }
        });
      });
    }

    runBtn.addEventListener('click', render);
    clearBtn.addEventListener('click', () => {
      patternInput.value = '';
      flagsInput.value = 'g';
      textInput.value = '';
      render();
    });

    [patternInput, flagsInput, textInput].forEach((field) => {
      field.addEventListener('input', render);
    });

    render();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
