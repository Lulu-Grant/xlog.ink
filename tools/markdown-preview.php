<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('markdown_preview'));

include __DIR__ . '/../inc/header.php';
?>

<section class="grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
  <div class="tool-panel space-y-4">
    <div>
      <p class="tool-hero-eyebrow"><?= e(trans('markdown_preview', 'Markdown Preview')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('markdown_preview_title', 'Live Markdown Editor')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('markdown_preview_description', 'Preview Markdown in real time with lightweight, safe rendering.')) ?></p>
    </div>

    <div class="flex flex-wrap gap-3">
      <button id="md-sample-btn" type="button" class="tool-button-primary"><?= e(trans('markdown_sample', 'Load sample')) ?></button>
      <button id="md-copy-btn" type="button" class="tool-button-secondary"><?= e(trans('copy', 'Copy')) ?></button>
      <button id="md-clear-btn" type="button" class="tool-button-secondary"><?= e(trans('clear', 'Clear')) ?></button>
    </div>

    <label class="tool-label">
      <?= e(trans('markdown_input_label', 'Markdown source')) ?>
      <textarea
        id="markdown-input"
        class="tool-textarea mt-2 min-h-[28rem]"
        placeholder="<?= e(trans('markdown_input_placeholder', 'Type Markdown here and preview it on the right.')) ?>"
        spellcheck="false"
      ><?= e("## " . trans('markdown_sample_title', 'Welcome to Markdown Preview') . "\n\n- " . trans('markdown_sample_line_one', 'Supports headings, lists, bold, italic, links, and code.') . "\n- " . trans('markdown_sample_line_two', 'HTML is escaped first to prevent direct injection.') . "\n\n`" . trans('markdown_sample_tip_label', 'Tip') . "`: " . trans('markdown_sample_tip_body', 'Paste content to start editing.')) ?></textarea>
    </label>
  </div>

  <div class="tool-panel space-y-4">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="tool-hero-eyebrow"><?= e(trans('preview', 'Preview')) ?></p>
        <h2 class="tool-hero-title"><?= e(trans('markdown_preview_output_title', 'Rendered output')) ?></h2>
      </div>
      <span id="md-status-badge" class="tool-status-pill" aria-live="polite"><?= e(trans('ready', 'Ready')) ?></span>
    </div>

    <div class="tool-muted-panel tool-help-panel text-sm leading-7 text-slate-600 dark:text-slate-300">
      <?= e(trans('markdown_preview_note', 'This preview escapes HTML first, then applies a lightweight Markdown renderer for common syntax.')) ?>
    </div>

    <div id="markdown-preview" class="tool-preview-panel prose prose-slate max-w-none rounded-3xl p-5 dark:prose-invert sm:p-6" aria-live="polite"></div>
  </div>
</section>

<script>
  (function () {
    const input = document.getElementById('markdown-input');
    const preview = document.getElementById('markdown-preview');
    const sampleBtn = document.getElementById('md-sample-btn');
    const copyBtn = document.getElementById('md-copy-btn');
    const clearBtn = document.getElementById('md-clear-btn');
    const statusBadge = document.getElementById('md-status-badge');

    if (!input || !preview || !sampleBtn || !copyBtn || !clearBtn || !statusBadge) {
      return;
    }

    const messages = {
      ready: <?= json_encode(trans('ready', 'Ready')) ?>,
      copied: <?= json_encode(trans('copied_to_clipboard', 'Copied to clipboard.')) ?>,
      cleared: <?= json_encode(trans('cleared', 'Cleared.')) ?>,
      sampleLoaded: <?= json_encode(trans('markdown_sample_loaded', 'Sample loaded.')) ?>,
      emptyPreview: <?= json_encode(trans('markdown_empty_preview', 'Type Markdown on the left to see a live preview here.')) ?>,
    };

    const sampleMarkdown = [
      '## ' + <?= json_encode(trans('markdown_sample_title', 'Welcome to Markdown Preview')) ?>,
      '',
      '- ' + <?= json_encode(trans('markdown_sample_line_one', 'Supports headings, lists, bold, italic, links, and code.')) ?>,
      '- ' + <?= json_encode(trans('markdown_sample_line_two', 'HTML is escaped first to prevent direct injection.')) ?>,
      '',
      '```',
      'console.log("safe preview");',
      '```',
      '',
      '> ' + <?= json_encode(trans('markdown_sample_quote', 'This is a simple, safe, real-time previewer.')) ?>,
      '',
      '[' + <?= json_encode(trans('markdown_sample_link_text', 'Visit homepage')) ?> + '](https://tool.gls.lat/)',
    ].join('\n');

    function escapeHtml(value) {
      return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function copyText(text) {
      if (!text) {
        return Promise.reject(new Error('empty'));
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
      }

      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.select();
      const copied = document.execCommand('copy');
      textarea.remove();
      return copied ? Promise.resolve() : Promise.reject(new Error('copy-failed'));
    }

    function sanitizeUrl(rawUrl) {
      const trimmed = rawUrl.trim();
      if (!trimmed) {
        return null;
      }

      if (trimmed.startsWith('#') || trimmed.startsWith('/')) {
        return trimmed;
      }

      try {
        const parsed = new URL(trimmed, window.location.href);
        if (['http:', 'https:', 'mailto:'].includes(parsed.protocol)) {
          return parsed.href;
        }
      } catch (error) {
        return null;
      }

      return null;
    }

    function renderInline(text) {
      const codeSegments = [];
      let output = text.replace(/`([^`]+)`/g, (_, code) => {
        codeSegments.push(code);
        return `@@CODE_${codeSegments.length - 1}@@`;
      });

      output = output.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, url) => {
        const safeUrl = sanitizeUrl(url);
        if (!safeUrl) {
          return label;
        }
        return `<a href="${escapeHtml(safeUrl)}" rel="noopener noreferrer nofollow" target="_blank">${label}</a>`;
      });

      output = output.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      output = output.replace(/__([^_]+)__/g, '<strong>$1</strong>');
      output = output.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
      output = output.replace(/(^|[^_])_([^_\n]+)_(?!_)/g, '$1<em>$2</em>');

      output = output.replace(/@@CODE_(\d+)@@/g, (_, index) => `<code>${codeSegments[Number(index)]}</code>`);

      return output;
    }

    function renderMarkdown(source) {
      const lines = source.replace(/\r\n?/g, '\n').split('\n');
      const blocks = [];
      let inList = false;
      let inCode = false;
      let codeBuffer = [];
      let blockquoteBuffer = [];

      const closeList = () => {
        if (inList) {
          blocks.push('</ul>');
          inList = false;
        }
      };

      const closeQuote = () => {
        if (blockquoteBuffer.length > 0) {
          blocks.push(`<blockquote>${blockquoteBuffer.join('<br>')}</blockquote>`);
          blockquoteBuffer = [];
        }
      };

      lines.forEach((line) => {
        const fence = line.match(/^```/);
        if (fence) {
          if (inCode) {
            blocks.push(`<pre><code>${codeBuffer.join('\n')}</code></pre>`);
            codeBuffer = [];
            inCode = false;
          } else {
            closeList();
            closeQuote();
            inCode = true;
          }
          return;
        }

        if (inCode) {
          codeBuffer.push(escapeHtml(line));
          return;
        }

        if (!line.trim()) {
          closeList();
          closeQuote();
          return;
        }

        const heading = line.match(/^(#{1,6})\s+(.*)$/);
        if (heading) {
          closeList();
          closeQuote();
          const level = heading[1].length;
          blocks.push(`<h${level}>${renderInline(escapeHtml(heading[2]))}</h${level}>`);
          return;
        }

        if (/^(---|\*\*\*|___)$/.test(line.trim())) {
          closeList();
          closeQuote();
          blocks.push('<hr />');
          return;
        }

        const quote = line.match(/^>\s?(.*)$/);
        if (quote) {
          closeList();
          blockquoteBuffer.push(renderInline(escapeHtml(quote[1])));
          return;
        }

        const listItem = line.match(/^(?:[-*+]\s+|\d+\.\s+)(.*)$/);
        if (listItem) {
          closeQuote();
          if (!inList) {
            blocks.push('<ul>');
            inList = true;
          }
          blocks.push(`<li>${renderInline(escapeHtml(listItem[1]))}</li>`);
          return;
        }

        closeQuote();
        closeList();
        blocks.push(`<p>${renderInline(escapeHtml(line))}</p>`);
      });

      if (inCode) {
        blocks.push(`<pre><code>${codeBuffer.join('\n')}</code></pre>`);
      }

      closeQuote();
      closeList();

      return blocks.join('');
    }

    function updatePreview() {
      const value = input.value.trim();
      if (!value) {
        preview.innerHTML = `<div class="tool-muted-panel tool-help-panel">${escapeHtml(messages.emptyPreview)}</div>`;
        return;
      }

      preview.innerHTML = renderMarkdown(value);
    }

    async function handleCopy() {
      await copyText(input.value);
      statusBadge.textContent = messages.copied;
      window.setTimeout(() => {
        statusBadge.textContent = messages.ready;
      }, 1600);
    }

    function flashStatus(message) {
      statusBadge.textContent = message;
      window.setTimeout(() => {
        statusBadge.textContent = messages.ready;
      }, 1600);
    }

    sampleBtn.addEventListener('click', () => {
      input.value = sampleMarkdown;
      updatePreview();
      flashStatus(messages.sampleLoaded);
    });

    copyBtn.addEventListener('click', () => {
      handleCopy().catch(() => {
        flashStatus(messages.ready);
      });
    });

    clearBtn.addEventListener('click', () => {
      input.value = '';
      updatePreview();
      flashStatus(messages.cleared);
      input.focus();
    });

    input.addEventListener('input', updatePreview);
    updatePreview();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
