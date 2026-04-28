<?php

if (defined('APP_DOCUMENT_CONVERTER')) {
    return;
}

define('APP_DOCUMENT_CONVERTER', true);

function document_converter_path_is_within_open_basedir(string $path): bool
{
    $openBasedir = trim((string) ini_get('open_basedir'));
    if ($openBasedir === '') {
        return true;
    }

    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedPath = rtrim($normalizedPath, '/');
    if ($normalizedPath === '') {
        return false;
    }

    foreach (explode(PATH_SEPARATOR, $openBasedir) as $allowedPath) {
        $allowedPath = trim($allowedPath);
        if ($allowedPath === '') {
            continue;
        }

        if ($allowedPath === '.') {
            return true;
        }

        $normalizedAllowedPath = str_replace('\\', '/', $allowedPath);
        $normalizedAllowedPath = rtrim($normalizedAllowedPath, '/');
        if ($normalizedAllowedPath === '') {
            continue;
        }

        if ($normalizedPath === $normalizedAllowedPath || str_starts_with($normalizedPath . '/', $normalizedAllowedPath . '/')) {
            return true;
        }
    }

    return false;
}

function document_converter_find_binary(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
            if (!document_converter_path_is_within_open_basedir($candidate)) {
                continue;
            }

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }

            continue;
        }

        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if (
            $resolved !== ''
            && document_converter_path_is_within_open_basedir($resolved)
            && is_file($resolved)
            && is_executable($resolved)
        ) {
            return $resolved;
        }
    }

    return null;
}

function document_converter_find_chrome_binary(): ?string
{
    $env = getenv('CHROME_BIN');

    return document_converter_find_binary([
        is_string($env) ? $env : '',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        'google-chrome',
        'chromium',
        'chromium-browser',
    ]);
}

function document_converter_find_pandoc_binary(): ?string
{
    return document_converter_find_binary(['pandoc', '/opt/homebrew/bin/pandoc', '/usr/local/bin/pandoc', '/usr/bin/pandoc']);
}

function document_converter_find_pdftotext_binary(): ?string
{
    return document_converter_find_binary(['pdftotext', '/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext']);
}

function document_converter_find_mdls_binary(): ?string
{
    if (PHP_OS_FAMILY !== 'Darwin') {
        return null;
    }

    return document_converter_find_binary(['mdls', '/usr/bin/mdls']);
}

function document_converter_file_url(string $absolutePath): string
{
    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);
    $segments = array_map('rawurlencode', array_values(array_filter(explode('/', $normalized), 'strlen')));

    if (preg_match('/^[A-Za-z]:/', $normalized) === 1) {
        return 'file:///' . implode('/', $segments);
    }

    return 'file:///' . implode('/', $segments);
}

function document_converter_normalize_text(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $normalized = preg_replace("/\x{00A0}/u", ' ', $normalized);
    $normalized = preg_replace("/[ \t]+\n/u", "\n", $normalized);
    $normalized = preg_replace("/\n{3,}/u", "\n\n", $normalized);

    return trim((string) $normalized);
}

function document_converter_escape_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function document_converter_clean_text_for_xml(string $text): string
{
    $normalized = document_converter_normalize_text($text);
    $clean = preg_replace('/[^\P{C}\n\t]/u', '', $normalized);

    return $clean === null ? $normalized : $clean;
}

function document_converter_decode_text_bytes(string $bytes): string
{
    if ($bytes === '') {
        return '';
    }

    if (str_starts_with($bytes, "\xFE\xFF")) {
        return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
    }

    if (str_starts_with($bytes, "\xFF\xFE")) {
        return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
    }

    if (mb_check_encoding($bytes, 'UTF-8')) {
        return $bytes;
    }

    return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
}

function document_converter_is_valid_docx(string $absolutePath): bool
{
    if (!is_file($absolutePath)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        return false;
    }

    $hasDocumentXml = is_string($zip->getFromName('word/document.xml'));
    $zip->close();

    return $hasDocumentXml;
}

function document_converter_is_valid_pdf(string $absolutePath): bool
{
    if (!is_file($absolutePath)) {
        return false;
    }

    $handle = @fopen($absolutePath, 'rb');
    if ($handle === false) {
        return false;
    }

    $header = fread($handle, 5);
    fclose($handle);

    return $header === '%PDF-';
}

function document_converter_extract_docx_inline_text(DOMNode $node): string
{
    $text = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
            $text .= $child->nodeValue;
            continue;
        }

        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        switch ($child->localName) {
            case 't':
                $text .= $child->textContent;
                break;
            case 'tab':
                $text .= "\t";
                break;
            case 'br':
            case 'cr':
                $text .= "\n";
                break;
            default:
                $text .= document_converter_extract_docx_inline_text($child);
                break;
        }
    }

    return $text;
}

function document_converter_extract_text_from_docx(string $absolutePath): string
{
    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Failed to open DOCX archive.');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('DOCX document.xml is missing.');
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    if (@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) === false) {
        throw new RuntimeException('DOCX XML could not be parsed.');
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $blocks = [];
    foreach ($xpath->query('/w:document/w:body/*') ?: [] as $block) {
        if (!$block instanceof DOMElement) {
            continue;
        }

        if ($block->localName === 'p') {
            $paragraph = document_converter_normalize_text(document_converter_extract_docx_inline_text($block));
            if ($paragraph !== '') {
                $blocks[] = $paragraph;
            }
            continue;
        }

        if ($block->localName === 'tbl') {
            $rows = [];
            foreach ($xpath->query('./w:tr', $block) ?: [] as $row) {
                $cells = [];
                foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                    $paragraphs = [];
                    foreach ($xpath->query('.//w:p', $cell) ?: [] as $paragraphNode) {
                        $cellParagraph = document_converter_normalize_text(document_converter_extract_docx_inline_text($paragraphNode));
                        if ($cellParagraph !== '') {
                            $paragraphs[] = $cellParagraph;
                        }
                    }
                    $cells[] = implode("\n", $paragraphs);
                }

                $rowText = trim(implode(' | ', array_filter($cells, static fn ($value) => $value !== '')));
                if ($rowText !== '') {
                    $rows[] = $rowText;
                }
            }

            if ($rows !== []) {
                $blocks[] = implode("\n", $rows);
            }
        }
    }

    return document_converter_normalize_text(implode("\n\n", $blocks));
}

function document_converter_markdown_or_html_from_docx(string $sourcePath, string $htmlPath): bool
{
    $pandoc = document_converter_find_pandoc_binary();
    if ($pandoc === null) {
        return false;
    }

    $command = escapeshellarg($pandoc)
        . ' --from=docx --to=html5 --standalone'
        . ' --wrap=none'
        . ' -o ' . escapeshellarg($htmlPath)
        . ' ' . escapeshellarg($sourcePath)
        . ' 2>&1';

    exec($command, $output, $exitCode);

    return $exitCode === 0 && is_file($htmlPath) && filesize($htmlPath) > 0;
}

function document_converter_build_html_from_text(string $title, string $bodyText, string $directionLabel): string
{
    $safeTitle = document_converter_escape_xml($title);
    $safeDirection = document_converter_escape_xml($directionLabel);
    $safeText = nl2br(document_converter_escape_xml($bodyText), false);

    return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$safeTitle}</title>
    <style>
      @page { margin: 18mm 16mm; }
      body {
        font-family: "Noto Sans CJK SC", "PingFang SC", "Microsoft YaHei", "Hiragino Sans GB", "Apple SD Gothic Neo", Arial, sans-serif;
        color: #0f172a;
        line-height: 1.7;
        font-size: 13px;
      }
      h1 {
        margin: 0 0 8px;
        font-size: 22px;
      }
      .meta {
        margin: 0 0 18px;
        color: #475569;
        font-size: 11px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .content {
        white-space: normal;
        word-break: break-word;
      }
      .content p {
        margin: 0 0 12px;
      }
    </style>
  </head>
  <body>
    <h1>{$safeTitle}</h1>
    <div class="meta">{$safeDirection}</div>
    <div class="content"><p>{$safeText}</p></div>
  </body>
</html>
HTML;
}

function document_converter_render_pdf_with_chrome(string $htmlPath, string $pdfPath): bool
{
    $chrome = document_converter_find_chrome_binary();
    if ($chrome === null) {
        return false;
    }

    $fileUrl = document_converter_file_url($htmlPath);
    $commands = [
        escapeshellarg($chrome)
            . ' --headless=new --disable-gpu --no-pdf-header-footer'
            . ' --print-to-pdf=' . escapeshellarg($pdfPath)
            . ' ' . escapeshellarg($fileUrl)
            . ' 2>&1',
        escapeshellarg($chrome)
            . ' --headless --disable-gpu --no-pdf-header-footer'
            . ' --print-to-pdf=' . escapeshellarg($pdfPath)
            . ' ' . escapeshellarg($fileUrl)
            . ' 2>&1',
    ];

    foreach ($commands as $command) {
        exec($command, $output, $exitCode);
        if ($exitCode === 0 && is_file($pdfPath) && filesize($pdfPath) > 0) {
            return true;
        }
    }

    return false;
}

function document_converter_convert_docx_to_pdf(string $sourcePath, string $pdfPath): array
{
    $workDir = ensure_directory(app_runtime_path('document-converter/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4))));
    $htmlPath = $workDir . '/source.html';
    $backend = 'internal';

    if (document_converter_markdown_or_html_from_docx($sourcePath, $htmlPath)) {
        $backend = 'pandoc+chrome';
    } else {
        $text = document_converter_extract_text_from_docx($sourcePath);
        if ($text === '') {
            throw new RuntimeException('The DOCX file did not contain readable text.');
        }

        file_put_contents(
            $htmlPath,
            document_converter_build_html_from_text('Word to PDF', $text, 'DOCX to PDF · text-first conversion')
        );
        $backend = 'text+chrome';
    }

    if (!document_converter_render_pdf_with_chrome($htmlPath, $pdfPath)) {
        throw new RuntimeException('A PDF renderer is not available.');
    }

    return ['backend' => $backend];
}

function document_converter_decode_pdf_literal_string(string $value): string
{
    return document_converter_decode_text_bytes(document_converter_decode_pdf_literal_bytes($value));
}

function document_converter_decode_pdf_literal_bytes(string $value): string
{
    $bytes = '';
    $length = strlen($value);

    for ($index = 0; $index < $length; $index += 1) {
        $char = $value[$index];
        if ($char !== '\\') {
            $bytes .= $char;
            continue;
        }

        $index++;
        if ($index >= $length) {
            break;
        }

        $escape = $value[$index];
        switch ($escape) {
            case 'n':
                $bytes .= "\n";
                break;
            case 'r':
                $bytes .= "\r";
                break;
            case 't':
                $bytes .= "\t";
                break;
            case 'b':
                $bytes .= "\x08";
                break;
            case 'f':
                $bytes .= "\x0C";
                break;
            case '(':
            case ')':
            case '\\':
                $bytes .= $escape;
                break;
            default:
                if ($escape >= '0' && $escape <= '7') {
                    $octal = $escape;
                    for ($count = 0; $count < 2 && $index + 1 < $length; $count += 1) {
                        $peek = $value[$index + 1];
                        if ($peek < '0' || $peek > '7') {
                            break;
                        }
                        $octal .= $peek;
                        $index++;
                    }
                    $bytes .= chr(octdec($octal));
                } else {
                    $bytes .= $escape;
                }
                break;
        }
    }

    return $bytes;
}

function document_converter_decode_pdf_hex_string(string $hex): string
{
    $clean = preg_replace('/\s+/', '', $hex);
    if ($clean === null || $clean === '') {
        return '';
    }

    if (strlen($clean) % 2 === 1) {
        $clean .= '0';
    }

    $bytes = hex2bin($clean);
    if ($bytes === false) {
        return '';
    }

    return document_converter_decode_text_bytes($bytes);
}

function document_converter_decode_pdf_stream(string $dictionary, string $stream): ?string
{
    if (str_contains($dictionary, '/FlateDecode')) {
        $decoded = @zlib_decode($stream);
        if ($decoded === false) {
            $decoded = @gzuncompress($stream);
        }
        if ($decoded === false && strlen($stream) > 2) {
            $decoded = @gzinflate(substr($stream, 2));
        }

        return is_string($decoded) ? $decoded : null;
    }

    if (str_contains($dictionary, '/LZWDecode')) {
        return null;
    }

    return $stream;
}

function document_converter_collect_pdf_array_strings(string $arrayBody): string
{
    preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<[\da-fA-F\s]+>/', $arrayBody, $matches);
    $parts = [];

    foreach ($matches[0] as $token) {
        if ($token[0] === '(') {
            $parts[] = document_converter_decode_pdf_literal_string(substr($token, 1, -1));
        } elseif ($token[0] === '<') {
            $parts[] = document_converter_decode_pdf_hex_string(substr($token, 1, -1));
        }
    }

    return implode('', $parts);
}

function document_converter_extract_text_from_pdf_stream(string $content): string
{
    $events = [];

    preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*(Tj|\'|")/s', $content, $literalMatches, PREG_OFFSET_CAPTURE);
    foreach ($literalMatches[0] as $index => $match) {
        $raw = $match[0];
        $operator = $literalMatches[1][$index][0];
        $events[] = [
            'offset' => $match[1],
            'text' => document_converter_decode_pdf_literal_string(substr($raw, 1, strrpos($raw, ')') - 1)),
            'linebreak' => $operator === "'" || $operator === '"',
        ];
    }

    preg_match_all('/<([\da-fA-F\s]+)>\s*(Tj|\'|")/s', $content, $hexMatches, PREG_OFFSET_CAPTURE);
    foreach ($hexMatches[0] as $index => $match) {
        $events[] = [
            'offset' => $match[1],
            'text' => document_converter_decode_pdf_hex_string($hexMatches[1][$index][0]),
            'linebreak' => in_array($hexMatches[2][$index][0], ["'", '"'], true),
        ];
    }

    preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $arrayMatches, PREG_OFFSET_CAPTURE);
    foreach ($arrayMatches[0] as $index => $match) {
        $events[] = [
            'offset' => $match[1],
            'text' => document_converter_collect_pdf_array_strings($arrayMatches[1][$index][0]),
            'linebreak' => false,
        ];
    }

    usort($events, static fn ($left, $right) => $left['offset'] <=> $right['offset']);

    $fragments = [];
    foreach ($events as $event) {
        $text = document_converter_normalize_text((string) $event['text']);
        if ($text === '') {
            continue;
        }

        $fragments[] = $text;
        if ($event['linebreak']) {
            $fragments[] = "\n";
        }
    }

    return document_converter_normalize_text(implode("\n", $fragments));
}

function document_converter_extract_text_with_pdftotext(string $absolutePath): string
{
    $binary = document_converter_find_pdftotext_binary();
    if ($binary === null) {
        return '';
    }

    $command = escapeshellarg($binary)
        . ' -layout -enc UTF-8 '
        . escapeshellarg($absolutePath)
        . ' - 2>/dev/null';

    $output = shell_exec($command);

    return is_string($output) ? document_converter_normalize_text($output) : '';
}

function document_converter_extract_text_with_mdls(string $absolutePath): string
{
    $binary = document_converter_find_mdls_binary();
    if ($binary === null) {
        return '';
    }

    $command = escapeshellarg($binary)
        . ' -raw -name kMDItemTextContent '
        . escapeshellarg($absolutePath)
        . ' 2>/dev/null';

    $output = shell_exec($command);
    if (!is_string($output)) {
        return '';
    }

    $trimmed = trim($output);
    if ($trimmed === '' || $trimmed === '(null)') {
        return '';
    }

    if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
        $decoded = json_decode($trimmed, true);
        if (is_string($decoded)) {
            return document_converter_normalize_text($decoded);
        }
    }

    return document_converter_normalize_text($trimmed);
}

function document_converter_extract_text_from_pdf_internal(string $absolutePath): string
{
    $raw = @file_get_contents($absolutePath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Failed to read PDF.');
    }

    $objects = document_converter_parse_pdf_objects($raw);
    $pages = document_converter_parse_pdf_pages($objects);
    $chunks = [];

    foreach ($pages as $page) {
        $fontMaps = [];
        foreach ($page['fonts'] as $alias => $fontObjectId) {
            $fontMaps[$alias] = document_converter_build_pdf_tounicode_map($objects, $fontObjectId);
        }

        foreach ($page['contents'] as $contentObjectId) {
            if (!isset($objects[$contentObjectId])) {
                continue;
            }

            $decodedStream = document_converter_extract_decoded_stream_from_object($objects[$contentObjectId]);
            if ($decodedStream === null || $decodedStream === '') {
                continue;
            }

            $text = document_converter_extract_text_from_pdf_content_stream($decodedStream, $fontMaps);
            if ($text !== '') {
                $chunks[] = $text;
            }
        }
    }

    if ($chunks === []) {
        preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $decoded = document_converter_decode_pdf_stream($match[1], $match[2]);
            if (!is_string($decoded) || $decoded === '') {
                continue;
            }

            $text = document_converter_extract_text_from_pdf_stream($decoded);
            if ($text !== '') {
                $chunks[] = $text;
            }
        }
    }

    return document_converter_normalize_text(implode("\n\n", $chunks));
}

function document_converter_extract_text_from_pdf(string $absolutePath): array
{
    $pdftotextText = document_converter_extract_text_with_pdftotext($absolutePath);
    if ($pdftotextText !== '') {
        return ['text' => $pdftotextText, 'backend' => 'pdftotext'];
    }

    $mdlsText = document_converter_extract_text_with_mdls($absolutePath);
    if ($mdlsText !== '') {
        return ['text' => $mdlsText, 'backend' => 'mdls'];
    }

    $internalText = document_converter_extract_text_from_pdf_internal($absolutePath);
    if ($internalText !== '') {
        return ['text' => $internalText, 'backend' => 'internal-parser'];
    }

    throw new RuntimeException('The PDF did not expose readable text.');
}

function document_converter_build_docx_from_text(string $text, string $docxPath): bool
{
    $normalized = document_converter_clean_text_for_xml($text);
    $paragraphs = preg_split("/\n{2,}/u", $normalized !== '' ? $normalized : ' ', -1, PREG_SPLIT_NO_EMPTY);

    if ($paragraphs === false || $paragraphs === []) {
        $paragraphs = [' '];
    }

    $bodyXml = '';
    foreach ($paragraphs as $paragraph) {
        $paragraphRuns = '';
        $lines = explode("\n", $paragraph);
        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $paragraphRuns .= '<w:r><w:br/></w:r>';
            }

            $segments = explode("\t", $line);
            foreach ($segments as $segmentIndex => $segment) {
                if ($segmentIndex > 0) {
                    $paragraphRuns .= '<w:r><w:tab/></w:r>';
                }

                $safeText = document_converter_escape_xml($segment);
                $spaceAttr = preg_match('/^\s|\s$/u', $segment) === 1 ? ' xml:space="preserve"' : '';
                $paragraphRuns .= '<w:r><w:t' . $spaceAttr . '>' . $safeText . '</w:t></w:r>';
            }
        }

        $bodyXml .= '<w:p>' . $paragraphRuns . '</w:p>';
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"'
        . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"'
        . ' xmlns:v="urn:schemas-microsoft-com:vml"'
        . ' xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . ' xmlns:w10="urn:schemas-microsoft-com:office:word"'
        . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"'
        . ' xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"'
        . ' xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"'
        . ' xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"'
        . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
        . ' mc:Ignorable="w14 wp14">'
        . '<w:body>'
        . $bodyXml
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . '</w:styles>';

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Word to PDF Converter Output</dc:title>'
        . '<dc:creator>猫柠咔百宝箱</dc:creator>'
        . '<cp:lastModifiedBy>猫柠咔百宝箱</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>猫柠咔百宝箱</Application>'
        . '</Properties>';

    $zip = new ZipArchive();
    if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', $documentRels);
    $zip->addFromString('word/styles.xml', $stylesXml);

    return $zip->close();
}

function document_converter_parse_pdf_objects(string $raw): array
{
    $objects = [];
    preg_match_all('/(\d+)\s+(\d+)\s+obj\s*(.*?)\s*endobj/s', $raw, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $objects[(int) $match[1]] = $match[3];
    }

    return $objects;
}

function document_converter_extract_decoded_stream_from_object(string $objectContent): ?string
{
    if (preg_match('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $objectContent, $match) !== 1) {
        return null;
    }

    return document_converter_decode_pdf_stream($match[1], $match[2]);
}

function document_converter_parse_pdf_pages(array $objects): array
{
    $pages = [];

    foreach ($objects as $objectId => $content) {
        if (!str_contains($content, '/Type /Page')) {
            continue;
        }

        $fonts = [];
        if (preg_match('/\/Font\s*<<(.*?)>>/s', $content, $fontMatch) === 1) {
            preg_match_all('/\/([A-Za-z0-9]+)\s+(\d+)\s+\d+\s+R/', $fontMatch[1], $fontRefs, PREG_SET_ORDER);
            foreach ($fontRefs as $fontRef) {
                $fonts[$fontRef[1]] = (int) $fontRef[2];
            }
        }

        $contents = [];
        if (preg_match('/\/Contents\s+(\d+)\s+\d+\s+R/', $content, $singleContent) === 1) {
            $contents[] = (int) $singleContent[1];
        } elseif (preg_match('/\/Contents\s*\[(.*?)\]/s', $content, $contentArray) === 1) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $contentArray[1], $contentRefs);
            $contents = array_map('intval', $contentRefs[1]);
        }

        if ($contents !== []) {
            $pages[] = [
                'id' => $objectId,
                'fonts' => $fonts,
                'contents' => $contents,
            ];
        }
    }

    return $pages;
}

function document_converter_unicode_from_hex(string $hex): string
{
    $clean = preg_replace('/\s+/', '', strtoupper($hex));
    if ($clean === null || $clean === '') {
        return '';
    }

    if (strlen($clean) % 4 !== 0) {
        $clean = str_pad($clean, (int) ceil(strlen($clean) / 4) * 4, '0');
    }

    $bytes = hex2bin($clean);
    if ($bytes === false) {
        return '';
    }

    return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
}

function document_converter_parse_pdf_tounicode_cmap(string $decoded): array
{
    $map = [];

    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $decoded, $bfcharBlocks, PREG_SET_ORDER)) {
        foreach ($bfcharBlocks as $block) {
            preg_match_all('/<([\dA-Fa-f]+)>\s*<([\dA-Fa-f]+)>/', $block[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $map[strtoupper($pair[1])] = document_converter_unicode_from_hex($pair[2]);
            }
        }
    }

    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $decoded, $bfrangeBlocks, PREG_SET_ORDER)) {
        foreach ($bfrangeBlocks as $block) {
            $lines = preg_split('/\r?\n/', trim($block[1])) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/<([\dA-Fa-f]+)>\s*<([\dA-Fa-f]+)>\s*<([\dA-Fa-f]+)>/', $line, $range) === 1) {
                    $start = hexdec($range[1]);
                    $end = hexdec($range[2]);
                    $target = hexdec($range[3]);

                    for ($code = $start; $code <= $end; $code++) {
                        $sourceHex = strtoupper(str_pad(dechex($code), strlen($range[1]), '0', STR_PAD_LEFT));
                        $targetHex = strtoupper(str_pad(dechex($target + ($code - $start)), strlen($range[3]), '0', STR_PAD_LEFT));
                        $map[$sourceHex] = document_converter_unicode_from_hex($targetHex);
                    }
                    continue;
                }

                if (preg_match('/<([\dA-Fa-f]+)>\s*<([\dA-Fa-f]+)>\s*\[(.*?)\]/', $line, $rangeArray) === 1) {
                    $start = hexdec($rangeArray[1]);
                    preg_match_all('/<([\dA-Fa-f]+)>/', $rangeArray[3], $targets);
                    foreach ($targets[1] as $index => $targetHex) {
                        $sourceHex = strtoupper(str_pad(dechex($start + $index), strlen($rangeArray[1]), '0', STR_PAD_LEFT));
                        $map[$sourceHex] = document_converter_unicode_from_hex($targetHex);
                    }
                }
            }
        }
    }

    return $map;
}

function document_converter_build_pdf_tounicode_map(array $objects, int $fontObjectId): array
{
    if (!isset($objects[$fontObjectId])) {
        return [];
    }

    if (preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $objects[$fontObjectId], $match) !== 1) {
        return [];
    }

    $toUnicodeObjectId = (int) $match[1];
    if (!isset($objects[$toUnicodeObjectId])) {
        return [];
    }

    $decoded = document_converter_extract_decoded_stream_from_object($objects[$toUnicodeObjectId]);
    if (!is_string($decoded) || $decoded === '') {
        return [];
    }

    return document_converter_parse_pdf_tounicode_cmap($decoded);
}

function document_converter_map_pdf_hex_text(string $hex, array $fontMap): string
{
    $clean = preg_replace('/\s+/', '', strtoupper($hex));
    if ($clean === null || $clean === '') {
        return '';
    }

    if ($fontMap === []) {
        return document_converter_decode_pdf_hex_string($clean);
    }

    $lengths = array_unique(array_map('strlen', array_keys($fontMap)));
    rsort($lengths, SORT_NUMERIC);
    $result = '';

    while ($clean !== '') {
        $matched = false;
        foreach ($lengths as $length) {
            if (strlen($clean) < $length) {
                continue;
            }

            $chunk = substr($clean, 0, $length);
            if (!isset($fontMap[$chunk])) {
                continue;
            }

            $result .= $fontMap[$chunk];
            $clean = (string) substr($clean, $length);
            $matched = true;
            break;
        }

        if (!$matched) {
            $result .= document_converter_decode_pdf_hex_string(substr($clean, 0, 2));
            $clean = (string) substr($clean, 2);
        }
    }

    return $result;
}

function document_converter_map_pdf_literal_text(string $literal, array $fontMap): string
{
    $bytes = document_converter_decode_pdf_literal_bytes($literal);
    if ($bytes === '') {
        return '';
    }

    if ($fontMap === []) {
        return document_converter_decode_text_bytes($bytes);
    }

    $hex = strtoupper(bin2hex($bytes));

    return document_converter_map_pdf_hex_text($hex, $fontMap);
}

function document_converter_parse_pdf_array_text(string $arrayBody, array $fontMap): string
{
    preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<[\da-fA-F\s]+>/', $arrayBody, $matches);
    $parts = [];

    foreach ($matches[0] as $token) {
        if ($token[0] === '(') {
            $parts[] = document_converter_map_pdf_literal_text(substr($token, 1, -1), $fontMap);
        } elseif ($token[0] === '<') {
            $parts[] = document_converter_map_pdf_hex_text(substr($token, 1, -1), $fontMap);
        }
    }

    return implode('', $parts);
}

function document_converter_extract_text_from_pdf_content_stream(string $content, array $fontMaps): string
{
    $offset = 0;
    $currentFont = null;
    $result = '';
    $tokenPattern = '/\/([A-Za-z0-9]+)\s+\d+(?:\.\d+)?\s+Tf|1\s+0\s+0\s+-?1\s+[-+]?\d+(?:\.\d+)?\s+[-+]?\d+(?:\.\d+)?\s+Tm|T\*|\[(.*?)\]\s*TJ|<([\dA-Fa-f\s]+)>\s*(Tj|\'|")|\(((?:\\\\.|[^\\\\)])*)\)\s*(Tj|\'|")/s';

    while (preg_match($tokenPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $fullMatch = $match[0][0];
        $offset = $match[0][1] + strlen($fullMatch);

        $hasGroup = static function (array $groups, int $index): bool {
            return isset($groups[$index]) && isset($groups[$index][1]) && $groups[$index][1] !== -1;
        };

        if ($hasGroup($match, 1)) {
            $currentFont = $match[1][0];
            continue;
        }

        if (str_ends_with($fullMatch, ' Tm') || $fullMatch === 'T*') {
            if ($result !== '' && !str_ends_with($result, "\n")) {
                $result .= "\n";
            }
            continue;
        }

        $fontMap = $currentFont !== null && isset($fontMaps[$currentFont]) ? $fontMaps[$currentFont] : [];

        if ($hasGroup($match, 2)) {
            $text = document_converter_parse_pdf_array_text($match[2][0], $fontMap);
            $result .= $text;
            continue;
        }

        if ($hasGroup($match, 3)) {
            $result .= document_converter_map_pdf_hex_text($match[3][0], $fontMap);
            if ($hasGroup($match, 4) && in_array($match[4][0], ["'", '"'], true)) {
                $result .= "\n";
            }
            continue;
        }

        if ($hasGroup($match, 5)) {
            $result .= document_converter_map_pdf_literal_text($match[5][0], $fontMap);
            if ($hasGroup($match, 6) && in_array($match[6][0], ["'", '"'], true)) {
                $result .= "\n";
            }
        }
    }

    return document_converter_normalize_text($result);
}

function document_converter_convert_pdf_to_docx(string $sourcePath, string $docxPath): array
{
    $result = document_converter_extract_text_from_pdf($sourcePath);
    if ($result['text'] === '') {
        throw new RuntimeException('The PDF did not contain readable text.');
    }

    if (!document_converter_build_docx_from_text($result['text'], $docxPath)) {
        throw new RuntimeException('Failed to build the DOCX file.');
    }

    return ['backend' => $result['backend'], 'text_length' => mb_strlen($result['text'], 'UTF-8')];
}
