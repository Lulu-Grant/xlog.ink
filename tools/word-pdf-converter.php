<?php
require_once __DIR__ . '/../config.php';
require_once APP_ROOT . '/app/document_converter.php';

extract(tool_context('word_pdf_converter'));

function document_converter_human_file_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    return round($size, $unitIndex === 0 ? 0 : 2) . ' ' . $units[$unitIndex];
}

maybe_cleanup_runtime_storage(app_config('downloads_ttl'));

$allowedModes = ['docx-to-pdf', 'pdf-to-docx'];
$mode = isset($_POST['mode']) && in_array($_POST['mode'], $allowedModes, true)
    ? (string) $_POST['mode']
    : 'docx-to-pdf';
$errors = [];
$conversion = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploaded = $_FILES['document'] ?? null;

    if (!is_array($uploaded) || empty($uploaded['tmp_name']) || !is_string($uploaded['tmp_name'])) {
        $errors[] = trans('word_pdf_converter_upload_error', 'Choose a document before converting.');
    } elseif (!is_uploaded_file($uploaded['tmp_name'])) {
        $errors[] = trans('word_pdf_converter_upload_error', 'The uploaded document could not be read.');
    } else {
        $originalName = (string) ($uploaded['name'] ?? 'document');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $expectedExtension = $mode === 'docx-to-pdf' ? 'docx' : 'pdf';

        if ($extension !== $expectedExtension) {
            $errors[] = $mode === 'docx-to-pdf'
                ? trans('word_pdf_converter_invalid_docx', 'Upload a DOCX file when converting Word to PDF.')
                : trans('word_pdf_converter_invalid_pdf', 'Upload a text-based PDF file when converting PDF to Word.');
        } else {
            $size = (int) ($uploaded['size'] ?? 0);
            if ($size <= 0 || $size > (int) app_config('max_upload_size')) {
                $errors[] = trans('word_pdf_converter_file_too_large', 'The file is too large for this converter.');
            } else {
                $batchDir = ensure_directory(app_runtime_path('document-converter/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4))));
                $safeStem = sanitize_filename_stem($originalName);
                $sourcePath = $batchDir . '/source-' . $safeStem . '.' . $extension;
                $outputExtension = $mode === 'docx-to-pdf' ? 'pdf' : 'docx';
                $outputPath = $batchDir . '/converted-' . $safeStem . '.' . $outputExtension;
                $downloadName = 'converted_' . $safeStem . '.' . $outputExtension;

                if (!move_uploaded_file($uploaded['tmp_name'], $sourcePath)) {
                    $errors[] = trans('word_pdf_converter_upload_error', 'The uploaded document could not be read.');
                } elseif ($mode === 'docx-to-pdf' && !document_converter_is_valid_docx($sourcePath)) {
                    $errors[] = trans('word_pdf_converter_invalid_docx', 'Upload a DOCX file when converting Word to PDF.');
                } elseif ($mode === 'pdf-to-docx' && !document_converter_is_valid_pdf($sourcePath)) {
                    $errors[] = trans('word_pdf_converter_invalid_pdf', 'Upload a text-based PDF file when converting PDF to Word.');
                } else {
                    try {
                        if ($mode === 'docx-to-pdf') {
                            $result = document_converter_convert_docx_to_pdf($sourcePath, $outputPath);
                            $resultLabel = trans('word_pdf_converter_result_docx_to_pdf', 'DOCX converted to PDF.');
                            $downloadMime = 'application/pdf';
                        } else {
                            $result = document_converter_convert_pdf_to_docx($sourcePath, $outputPath);
                            $resultLabel = trans('word_pdf_converter_result_pdf_to_docx', 'PDF converted to DOCX.');
                            $downloadMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                        }

                        $token = register_download($outputPath, $downloadName, $downloadMime);
                        $conversion = [
                            'result_label' => $resultLabel,
                            'download_name' => $downloadName,
                            'download_url' => download_url($token),
                            'file_size' => is_file($outputPath) ? (filesize($outputPath) ?: 0) : 0,
                            'backend' => (string) ($result['backend'] ?? 'internal'),
                            'text_length' => isset($result['text_length']) ? (int) $result['text_length'] : null,
                        ];
                    } catch (Throwable $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }
            }
        }
    }
}

$rendererAvailable = document_converter_find_chrome_binary() !== null;
$pandocAvailable = document_converter_find_pandoc_binary() !== null;
$statusMessage = $conversion !== null
    ? trans('word_pdf_converter_status_done', 'Conversion completed.')
    : ($errors !== [] ? trans('word_pdf_converter_status_failed', 'Conversion failed.') : trans('word_pdf_converter_status_empty', 'Please upload a DOCX or PDF file to continue.'));
$statusState = $conversion !== null ? 'success' : ($errors !== [] ? 'error' : 'idle');

include __DIR__ . '/../inc/header.php';
?>

<section class="space-y-6">
  <div class="tool-panel">
    <div class="tool-badge"><?= e(trans('document_tool', 'Document tool')) ?></div>
    <div class="mt-4">
      <p class="tool-hero-eyebrow"><?= e(trans('word_pdf_converter', 'Word / PDF Converter')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('word_pdf_converter_heading', 'Convert DOCX and PDF with a text-first workflow')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('word_pdf_converter_intro', 'Convert DOCX to PDF or extract readable PDF text into a DOCX file. This lightweight tool prioritizes paragraph text over complex layout.')) ?></p>
    </div>
  </div>

  <div class="grid gap-6 lg:grid-cols-[1fr_0.96fr]">
    <section class="tool-panel">
      <form id="word-pdf-converter-form" method="post" enctype="multipart/form-data" class="space-y-6">
        <label class="tool-label">
          <?= e(trans('word_pdf_converter_mode_label', 'Conversion mode')) ?>
          <select id="converter-mode" name="mode" class="tool-control mt-2">
            <option value="docx-to-pdf" <?= $mode === 'docx-to-pdf' ? 'selected' : '' ?>><?= e(trans('word_pdf_converter_mode_docx_to_pdf', 'DOCX to PDF')) ?></option>
            <option value="pdf-to-docx" <?= $mode === 'pdf-to-docx' ? 'selected' : '' ?>><?= e(trans('word_pdf_converter_mode_pdf_to_docx', 'PDF to DOCX')) ?></option>
          </select>
          <span id="converter-mode-hint" class="tool-field-hint mt-2 block">
            <?= e($mode === 'docx-to-pdf'
                ? trans('word_pdf_converter_mode_docx_hint', 'Upload a DOCX file and generate a readable PDF copy.')
                : trans('word_pdf_converter_mode_pdf_hint', 'Upload a text-based PDF and rebuild its extracted text as a DOCX file.')) ?>
          </span>
        </label>

        <label class="tool-label">
          <?= e(trans('word_pdf_converter_upload_label', 'Upload file')) ?>
          <input
            id="converter-file"
            type="file"
            name="document"
            required
            accept="<?= $mode === 'docx-to-pdf' ? '.docx' : '.pdf' ?>"
            class="tool-file-input-hidden"
          />
          <div class="tool-file-picker mt-2">
            <button id="converter-file-trigger" type="button" class="tool-button-secondary">
              <?= e(trans('word_pdf_converter_choose_file', 'Choose file')) ?>
            </button>
            <span id="converter-file-name" class="tool-file-picker-name">
              <?= e(trans('word_pdf_converter_no_file', 'No file selected yet.')) ?>
            </span>
          </div>
          <span id="converter-file-hint" class="tool-field-hint mt-2 block">
            <?= e($mode === 'docx-to-pdf'
                ? trans('word_pdf_converter_upload_docx_hint', 'Supported input: .docx')
                : trans('word_pdf_converter_upload_pdf_hint', 'Supported input: .pdf')) ?>
          </span>
        </label>

        <div class="tool-muted-panel tool-help-panel">
          <div class="tool-note-title"><?= e(trans('word_pdf_converter_engine_title', 'Current conversion path')) ?></div>
          <ul class="tool-copy-list mt-3 space-y-2">
            <li><?= e($pandocAvailable
                ? trans('word_pdf_converter_engine_docx', 'DOCX to PDF can use Pandoc plus a headless browser renderer on this machine.')
                : trans('word_pdf_converter_engine_docx_fallback', 'DOCX to PDF will fall back to internal text extraction before rendering a PDF.')) ?></li>
            <li><?= e(trans('word_pdf_converter_engine_pdf', 'PDF to DOCX works best for text-based PDFs with selectable text.')) ?></li>
            <li><?= e($rendererAvailable
                ? trans('word_pdf_converter_renderer_ready', 'A PDF renderer is available for DOCX to PDF output.')
                : trans('word_pdf_converter_renderer_missing', 'No PDF renderer was detected, so DOCX to PDF will not work until one is installed.')) ?></li>
          </ul>
        </div>

        <div class="flex flex-wrap gap-3">
          <button id="converter-submit" type="submit" class="tool-button-primary"><?= e(trans('word_pdf_converter_convert_button', 'Convert document')) ?></button>
        </div>

        <div
          id="converter-status"
          class="tool-inline-status tool-inline-status-<?= e($statusState) ?>"
          role="status"
          aria-live="polite"
          aria-atomic="true"
        >
          <?= e($statusMessage) ?>
        </div>

        <p class="tool-status-text text-xs"><?= e(trans('privacy_notice', 'We do not store your images longer than needed to generate downloads.')) ?></p>
      </form>

      <?php if ($errors !== []): ?>
        <div class="tool-alert-warning mt-6">
          <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($conversion !== null): ?>
        <div class="tool-alert-success mt-6">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 class="tool-title-lg text-emerald-900 dark:text-emerald-100"><?= e(trans('word_pdf_converter_result_title', 'Conversion result')) ?></h3>
              <p class="tool-status-text text-emerald-800 dark:text-emerald-200"><?= e($conversion['result_label']) ?></p>
            </div>
            <a href="<?= e($conversion['download_url']) ?>" class="tool-button-secondary"><?= e(trans('word_pdf_converter_download_button', 'Download file')) ?></a>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="tool-stat-card bg-white/90 dark:bg-slate-900/80">
              <div class="tool-stat-label"><?= e(trans('word_pdf_converter_output_label', 'Output file')) ?></div>
              <div class="tool-stat-value mt-1 break-all"><?= e($conversion['download_name']) ?></div>
            </div>
            <div class="tool-stat-card bg-white/90 dark:bg-slate-900/80">
              <div class="tool-stat-label"><?= e(trans('word_pdf_converter_size_label', 'File size')) ?></div>
              <div class="tool-stat-value mt-1"><?= e(document_converter_human_file_size((int) $conversion['file_size'])) ?></div>
            </div>
            <div class="tool-stat-card bg-white/90 dark:bg-slate-900/80">
              <div class="tool-stat-label"><?= e(trans('word_pdf_converter_backend_label', 'Backend')) ?></div>
              <div class="tool-stat-value mt-1"><?= e($conversion['backend']) ?></div>
            </div>
            <?php if ($conversion['text_length'] !== null): ?>
              <div class="tool-stat-card bg-white/90 dark:bg-slate-900/80">
                <div class="tool-stat-label"><?= e(trans('word_pdf_converter_text_length_label', 'Extracted characters')) ?></div>
                <div class="tool-stat-value mt-1"><?= e((string) $conversion['text_length']) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <aside class="space-y-4">
      <section class="tool-panel">
        <h3 class="tool-title-lg"><?= e(trans('word_pdf_converter_limits_title', 'What this tool is optimized for')) ?></h3>
        <ul class="tool-copy-list mt-4 space-y-3">
          <li><?= e(trans('word_pdf_converter_limit_one', 'Best for text-heavy DOCX and PDF files with selectable text.')) ?></li>
          <li><?= e(trans('word_pdf_converter_limit_two', 'Complex layout, images, tables, tracked changes, and embedded objects may not survive the round trip.')) ?></li>
          <li><?= e(trans('word_pdf_converter_limit_three', 'PDF to DOCX rebuilds a clean text document rather than preserving the original page design.')) ?></li>
        </ul>
      </section>

      <section class="tool-panel">
        <h3 class="tool-title-lg"><?= e(trans('word_pdf_converter_tips_title', 'Practical tips')) ?></h3>
        <ul class="tool-copy-list mt-4 space-y-3">
          <li><?= e(trans('word_pdf_converter_tip_one', 'Use DOCX to PDF when you need a quick readable export of a Word document.')) ?></li>
          <li><?= e(trans('word_pdf_converter_tip_two', 'Use PDF to DOCX when the PDF is mostly paragraphs and you want editable text back.')) ?></li>
          <li><?= e(trans('word_pdf_converter_tip_three', 'If a scanned PDF has no selectable text, convert it with OCR first and then upload it here.')) ?></li>
        </ul>
      </section>
    </aside>
  </div>
</section>

<script>
  (function () {
    const form = document.getElementById('word-pdf-converter-form');
    const modeField = document.getElementById('converter-mode');
    const fileField = document.getElementById('converter-file');
    const fileTrigger = document.getElementById('converter-file-trigger');
    const fileName = document.getElementById('converter-file-name');
    const modeHint = document.getElementById('converter-mode-hint');
    const fileHint = document.getElementById('converter-file-hint');
    const submitButton = document.getElementById('converter-submit');
    const statusField = document.getElementById('converter-status');

    if (!form || !modeField || !fileField || !fileTrigger || !fileName || !modeHint || !fileHint || !submitButton || !statusField) {
      return;
    }

    const copy = {
      docxHint: <?= json_encode(trans('word_pdf_converter_mode_docx_hint', 'Upload a DOCX file and generate a readable PDF copy.')) ?>,
      pdfHint: <?= json_encode(trans('word_pdf_converter_mode_pdf_hint', 'Upload a text-based PDF and rebuild its extracted text as a DOCX file.')) ?>,
      docxFile: <?= json_encode(trans('word_pdf_converter_upload_docx_hint', 'Supported input: .docx')) ?>,
      pdfFile: <?= json_encode(trans('word_pdf_converter_upload_pdf_hint', 'Supported input: .pdf')) ?>,
      ready: <?= json_encode(trans('word_pdf_converter_status_ready', 'Ready to convert.')) ?>,
      empty: <?= json_encode(trans('word_pdf_converter_status_empty', 'Please upload a DOCX or PDF file to continue.')) ?>,
      uploading: <?= json_encode(trans('word_pdf_converter_status_uploading', 'File uploaded, preparing conversion.')) ?>,
      converting: <?= json_encode(trans('word_pdf_converter_status_converting', 'Converting file...')) ?>,
      done: <?= json_encode(trans('word_pdf_converter_status_done', 'Conversion completed.')) ?>,
      failed: <?= json_encode(trans('word_pdf_converter_status_failed', 'Conversion failed.')) ?>,
      chooseFile: <?= json_encode(trans('word_pdf_converter_choose_file', 'Choose file')) ?>,
      noFile: <?= json_encode(trans('word_pdf_converter_no_file', 'No file selected yet.')) ?>,
      convertButton: <?= json_encode(trans('word_pdf_converter_convert_button', 'Convert document')) ?>,
      resultTitle: <?= json_encode(trans('word_pdf_converter_result_title', 'Conversion result')) ?>,
      successDetail: <?= json_encode($conversion !== null ? $conversion['result_label'] : '') ?>,
      errorDetail: <?= json_encode($errors !== [] ? $errors[0] : '') ?>,
    };
    const initialState = <?= json_encode($statusState) ?>;
    let lockInitialStatus = initialState === 'success' || initialState === 'error';

    function setStatus(state, text) {
      statusField.className = 'tool-inline-status tool-inline-status-' + state;
      statusField.textContent = text;
    }

    function showToast(state, title, detail) {
      const toast = document.createElement('div');
      toast.className = 'tool-toast tool-toast-' + state;
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      const titleNode = document.createElement('strong');
      titleNode.textContent = title;
      toast.appendChild(titleNode);

      if (detail) {
        const detailNode = document.createElement('span');
        detailNode.textContent = detail;
        toast.appendChild(detailNode);
      }

      document.body.appendChild(toast);

      requestAnimationFrame(() => {
        toast.classList.add('is-visible');
      });

      window.setTimeout(() => {
        toast.classList.remove('is-visible');
        window.setTimeout(() => toast.remove(), 220);
      }, 2800);
    }

    function syncFileName() {
      if (fileField.files && fileField.files.length > 0) {
        fileName.textContent = fileField.files[0].name;
      } else {
        fileName.textContent = copy.noFile;
      }
    }

    function syncMode() {
      if (modeField.value === 'pdf-to-docx') {
        fileField.accept = '.pdf';
        modeHint.textContent = copy.pdfHint;
        fileHint.textContent = copy.pdfFile;
      } else {
        fileField.accept = '.docx';
        modeHint.textContent = copy.docxHint;
        fileHint.textContent = copy.docxFile;
      }

      if (lockInitialStatus) {
        syncFileName();
        return;
      }

      if (!fileField.files || fileField.files.length === 0) {
        setStatus('idle', copy.empty);
      } else {
        setStatus('pending', copy.ready);
      }

      syncFileName();
    }

    fileTrigger.addEventListener('click', function () {
      fileField.click();
    });

    fileField.addEventListener('change', function () {
      lockInitialStatus = false;
      if (fileField.files && fileField.files.length > 0) {
        setStatus('pending', copy.uploading);
      } else {
        setStatus('idle', copy.empty);
      }
      syncFileName();
    });

    form.addEventListener('submit', function () {
      lockInitialStatus = false;
      submitButton.disabled = true;
      submitButton.textContent = copy.converting;
      setStatus('pending', copy.converting);
    });

    modeField.addEventListener('change', syncMode);
    syncMode();
    syncFileName();

    if (initialState === 'success') {
      showToast('success', copy.resultTitle, copy.successDetail || copy.done);
    } else if (initialState === 'error' && copy.errorDetail) {
      showToast('error', copy.failed, copy.errorDetail);
    }
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
