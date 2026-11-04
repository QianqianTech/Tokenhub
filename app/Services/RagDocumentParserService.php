<?php

declare(strict_types=1);

namespace TokenHub\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use TokenHub\Services\Media\PathConfinement;
use TokenHub\Services\Media\ZipLimits;
use TokenHub\Support\HttpAbortException;
use TokenHub\Support\OleCompoundFile;
use ZipArchive;

/**
 * Converts uploaded documents into structure-preserving Markdown (RAG-020):
 * headings keep their level, tables become Markdown tables, lists and code
 * blocks stay intact, and PDF pages are marked with <!-- page:N --> so chunks
 * can cite the page. The Markdown is the single intermediate format: it is
 * shown in the upload preview and consumed by RagStructuredChunkerService.
 *
 * Supported here without extra dependencies: TXT, Markdown, HTML, DOCX, PPTX,
 * XLSX/XLS/ET/ODS/CSV (all sheets, via PhpSpreadsheet), OpenDocument ODT/ODP,
 * legacy Word .doc/.wps, PowerPoint .ppt/.dps and RTF (RagLegacyDocumentReader),
 * and PDF when the poppler `pdftotext` binary is installed. Other PDFs (or
 * scanned ones) are handed to the Python RAG worker (job_type "parse"), which
 * also runs OCR.
 */
final class RagDocumentParserService
{
    public const FORMATS = [
        'txt', 'text', 'md', 'markdown', 'html', 'htm', 'pdf',
        'docx', 'doc', 'wps', 'rtf', 'odt',
        'pptx', 'ppt', 'dps', 'odp',
        'xlsx', 'xls', 'et', 'ods', 'csv',
    ];

    /** Formats imported through the table templates (text / QA / image list) unless "document" is chosen. */
    public const SPREADSHEET_FORMATS = ['csv', 'xlsx', 'xls', 'et', 'ods'];

    /** ZIP-based containers bounded by ZipLimits before anything is inflated. */
    private const ZIP_FORMATS = ['docx', 'pptx', 'xlsx', 'odt', 'odp', 'ods'];

    /** @param null|callable(string): ?string $pdfTextExtractor returns form-feed separated pages, or null */
    /**
     * @param list<string>|null $allowedRoots directories a parsed file must lie in; null = RAG upload storage
     */
    public function __construct(
        private readonly mixed $pdfTextExtractor = null,
        private readonly ?array $allowedRoots = null,
        private readonly ?ZipLimits $limits = null,
    ) {
    }

    /** @return list<string> */
    private function allowedRoots(): array
    {
        return $this->allowedRoots ?? PathConfinement::ragUploadRoots();
    }

    private function zipLimits(): ZipLimits
    {
        return $this->limits ?? new ZipLimits();
    }

    /** SEC-107: docx/pptx/xlsx are ZIP containers; bound them before inflating anything. */
    private function assertZipWithinLimits(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return; // the format reader reports the unreadable file
        }
        $violation = $this->zipLimits()->violation($zip);
        $zip->close();
        if ($violation !== null) {
            abort_json($violation, 'Document exceeds the allowed number of files or uncompressed size', 422);
        }
    }

    public static function formatFor(string $filename, string $mime = ''): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'markdown') {
            return 'md';
        }
        if ($extension === 'htm') {
            return 'html';
        }
        if ($extension === 'text') {
            return 'txt';
        }
        if (in_array($extension, self::FORMATS, true)) {
            return $extension;
        }

        return match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'text/markdown' => 'md',
            'text/html' => 'html',
            'text/plain' => 'txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.ms-word', 'application/kswps', 'application/vnd.ms-works', 'application/wps-office.wps' => 'wps',
            'application/rtf', 'text/rtf', 'application/x-rtf' => 'rtf',
            'application/vnd.oasis.opendocument.text' => 'odt',
            'application/vnd.ms-powerpoint', 'application/wps-office.dps' => 'ppt',
            'application/vnd.oasis.opendocument.presentation' => 'odp',
            'application/vnd.ms-excel', 'application/wps-office.et' => 'xls',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            'text/csv' => 'csv',
            default => null,
        };
    }

    /**
     * @return array{format: string, title: string, markdown: string, pages: int, warnings: list<string>, needs_worker: bool, parser: string}
     */
    public function parse(string $path, string $filename, string $mime = ''): array
    {
        $format = self::formatFor($filename, $mime);
        if ($format === null) {
            abort_json('RAG_DOCUMENT_UNSUPPORTED', 'Unsupported document format', 422);
        }
        // SEC-102: only files stored by the upload service are parsed.
        $resolved = PathConfinement::resolve($path, $this->allowedRoots());
        if ($resolved === null) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'Document file is not readable', 422);
        }
        $path = $resolved;
        if (in_array($format, self::ZIP_FORMATS, true)) {
            $this->assertZipWithinLimits($path);
        }
        $title = trim((string) pathinfo($filename, PATHINFO_FILENAME)) ?: 'document';
        $warnings = [];
        $pages = 0;
        $needsWorker = false;
        $parser = 'php:' . $format;
        $legacy = new RagLegacyDocumentReader();
        try {
            $markdown = match ($format) {
                'txt' => $this->plainText($this->readText($path)),
                'md' => $this->normalizeMarkdown($this->readText($path)),
                'html' => $this->html($this->readText($path), $title),
                'docx' => $this->docx($path, $title, $warnings),
                'doc', 'wps' => $this->wordBinary($path, $legacy, $warnings),
                'rtf' => $this->renderBlocks($legacy->rtf((string) @file_get_contents($path), $warnings)),
                'odt' => $this->odt($path, $warnings),
                'pptx' => $this->pptx($path, $warnings),
                'ppt', 'dps' => $this->renderSlides($legacy->powerPoint($path, $warnings)),
                'odp' => $this->odp($path, $warnings),
                'xlsx', 'xls', 'et', 'ods', 'csv' => $this->spreadsheet($path, $format, $warnings),
                'pdf' => $this->pdf($path, $pages, $needsWorker, $warnings),
            };
        } catch (\Throwable $exception) {
            HttpAbortException::rethrowIfAbort($exception);
            // Corrupt or mislabelled files (a renamed PDF, a damaged ZIP) are a client error, not a 500.
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The ' . strtoupper($format) . ' file could not be read; check that it opens in Office/WPS and is not damaged or password protected', 422);
        }
        if ($format === 'pdf' && $needsWorker) {
            $parser = 'python:pdf';
        }
        $markdown = $this->clean($markdown, $warnings);
        if (!$needsWorker && trim($markdown) === '') {
            $warnings[] = 'No text could be extracted.';
        }
        if ($format === 'pdf' && !$needsWorker && $pages === 0) {
            $pages = max(1, substr_count($markdown, '<!-- page:'));
        }
        if (preg_match('/^#\s+(.+)$/m', $markdown, $m) === 1 && in_array($format, ['md', 'html', 'docx', 'doc', 'wps', 'rtf', 'odt'], true)) {
            $title = trim($m[1]);
        }

        return ['format' => $format, 'title' => mb_substr($title, 0, 250), 'markdown' => $markdown, 'pages' => $pages, 'warnings' => $warnings, 'needs_worker' => $needsWorker, 'parser' => $parser];
    }

    private function readText(string $path): string
    {
        $raw = (string) @file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
            $raw = is_string($converted) ? $converted : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    private function plainText(string $text): string
    {
        // Plain text keeps its paragraphs; short lines that look like numbered headings
        // ("第一章 总则", "1.2 适用范围") become headings so sections survive.
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && mb_strlen($trimmed) <= 40 && preg_match('/^(第[一二三四五六七八九十百零\d]+[章节部分篇条]\s*|[一二三四五六七八九十]+、\s*|\d+(\.\d+){0,3}[\s、.．]\s*)\S/u', $trimmed) === 1 && !preg_match('/[。；;，,]$/u', $trimmed)) {
                $level = preg_match('/^第.+[章篇部分]/u', $trimmed) === 1 ? 1 : (preg_match('/^\d+\.\d+/u', $trimmed) === 1 ? 3 : 2);
                $out[] = '';
                $out[] = str_repeat('#', $level) . ' ' . $trimmed;
                $out[] = '';
                continue;
            }
            $out[] = rtrim($line);
        }

        return implode("\n", $out);
    }

    private function normalizeMarkdown(string $text): string
    {
        // Setext headings ("Title\n====") become ATX headings so one heading syntax remains.
        $text = preg_replace('/^([^\n#>|`-][^\n]*)\n=+\s*$/m', '# $1', $text) ?? $text;

        return preg_replace('/^([^\n#>|`-][^\n]*)\n-{3,}\s*$/m', '## $1', $text) ?? $text;
    }

    private function html(string $html, string $title): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        foreach (['//script', '//style', '//noscript', '//nav', '//footer', '//header', '//aside', '//form', '//iframe', '//svg'] as $query) {
            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $pageTitle = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $body = $xpath->query('//body')->item(0) ?? $document->documentElement;
        $lines = [];
        if ($pageTitle !== '' && $xpath->query('//h1')->length === 0) {
            $lines[] = '# ' . $pageTitle;
            $lines[] = '';
        }
        if ($body !== null) {
            $this->htmlNode($body, $lines);
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function htmlNode(DOMNode $node, array &$lines): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ($child->nodeType === XML_TEXT_NODE && trim((string) $child->textContent) !== '') {
                    $lines[] = $this->inline((string) $child->textContent);
                    $lines[] = '';
                }
                continue;
            }
            $tag = strtolower($child->tagName);
            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                $text = $this->inline($child->textContent);
                if ($text !== '') {
                    $lines[] = str_repeat('#', (int) $m[1]) . ' ' . $text;
                    $lines[] = '';
                }
            } elseif ($tag === 'p' || $tag === 'blockquote' || $tag === 'dd' || $tag === 'dt' || $tag === 'figcaption') {
                $text = $this->inline($child->textContent);
                if ($text !== '') {
                    $lines[] = $text;
                    $lines[] = '';
                }
            } elseif ($tag === 'ul' || $tag === 'ol') {
                $index = 1;
                foreach ($child->childNodes as $item) {
                    if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                        $lines[] = ($tag === 'ol' ? ($index++) . '. ' : '- ') . $this->inline($item->textContent);
                    }
                }
                $lines[] = '';
            } elseif ($tag === 'pre') {
                $lines[] = '```';
                $lines[] = rtrim(str_replace("\r", '', (string) $child->textContent));
                $lines[] = '```';
                $lines[] = '';
            } elseif ($tag === 'table') {
                $rows = [];
                foreach ((new DOMXPath($child->ownerDocument))->query('.//tr', $child) ?: [] as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $cell) {
                        if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                            $cells[] = $this->inline($cell->textContent);
                        }
                    }
                    if ($cells !== []) {
                        $rows[] = $cells;
                    }
                }
                array_push($lines, ...$this->markdownTable($rows));
                $lines[] = '';
            } elseif (in_array($tag, ['br', 'hr', 'img'], true)) {
                continue;
            } else {
                $this->htmlNode($child, $lines);
            }
        }
    }

    /** @param list<string> $warnings */
    private function docx(string $path, string $title, array &$warnings): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'DOCX file cannot be opened', 422);
        }
        $xml = (string) $this->zipLimits()->read($zip, 'word/document.xml');
        $styles = (string) $this->zipLimits()->read($zip, 'word/styles.xml');
        $zip->close();
        if ($xml === '') {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'DOCX has no document body', 422);
        }
        $headingStyles = $this->docxHeadingStyles($styles);
        $document = new DOMDocument();
        $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $xpath->query('//w:body')->item(0);
        $lines = [];
        $plain = [];
        $hasHeadings = false;
        if ($body === null) {
            return '';
        }
        foreach ($body->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $text = $this->docxText($xpath, $node);
                if ($text === '') {
                    continue;
                }
                $style = (string) ($xpath->query('./w:pPr/w:pStyle/@w:val', $node)->item(0)?->nodeValue ?? '');
                $outline = $xpath->query('./w:pPr/w:outlineLvl/@w:val', $node)->item(0)?->nodeValue;
                $level = $headingStyles[$style] ?? ($outline !== null ? ((int) $outline) + 1 : null);
                if ($level !== null && $level >= 1 && $level <= 6 && mb_strlen($text) <= 200) {
                    $hasHeadings = true;
                    $lines[] = '';
                    $lines[] = str_repeat('#', $level) . ' ' . $text;
                    $lines[] = '';
                } elseif ($xpath->query('./w:pPr/w:numPr', $node)->length > 0 || preg_match('/^List/i', $style) === 1) {
                    $lines[] = '- ' . $text;
                } else {
                    $plain[] = count($lines);
                    $lines[] = $text;
                    $lines[] = '';
                }
            } elseif ($node->localName === 'tbl') {
                $rows = [];
                foreach ($xpath->query('./w:tr', $node) ?: [] as $tr) {
                    $cells = [];
                    foreach ($xpath->query('./w:tc', $tr) ?: [] as $tc) {
                        $cellText = [];
                        foreach ($xpath->query('.//w:p', $tc) ?: [] as $p) {
                            $t = $this->docxText($xpath, $p);
                            if ($t !== '') {
                                $cellText[] = $t;
                            }
                        }
                        $cells[] = implode(' ', $cellText);
                    }
                    $rows[] = $cells;
                }
                $lines[] = '';
                array_push($lines, ...$this->markdownTable($rows));
                $lines[] = '';
            }
        }
        if ($lines === []) {
            $warnings[] = 'DOCX contains no text paragraphs.';
        }
        if (!$hasHeadings) {
            // Headings formatted by hand (bold, larger font) instead of a heading style:
            // numbered titles such as "一、项目背景" or "1.2 范围" still delimit sections.
            foreach ($plain as $index) {
                $lines[$index] = trim($this->plainText($lines[$index]), "\n");
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, int> style id => heading level */
    private function docxHeadingStyles(string $stylesXml): array
    {
        $map = ['Title' => 1];
        for ($i = 1; $i <= 9; $i++) {
            $map['Heading' . $i] = min(6, $i + 1);
            $map['heading' . $i] = min(6, $i + 1);
            $map[(string) $i] = min(6, $i + 1); // localized Word templates often use numeric style ids
        }
        if ($stylesXml === '') {
            return $map;
        }
        $document = new DOMDocument();
        if (!@$document->loadXML($stylesXml, LIBXML_NONET)) {
            return $map;
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        foreach ($xpath->query('//w:style[@w:type="paragraph"]') ?: [] as $style) {
            $id = (string) $style->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'styleId');
            $name = strtolower((string) ($xpath->query('./w:name/@w:val', $style)->item(0)?->nodeValue ?? ''));
            $outline = $xpath->query('./w:pPr/w:outlineLvl/@w:val', $style)->item(0)?->nodeValue;
            if ($name === 'title') {
                $map[$id] = 1;
            } elseif (preg_match('/^heading\s*(\d)$/', $name, $m) === 1) {
                $map[$id] = min(6, (int) $m[1] + 1);
            } elseif ($outline !== null) {
                $map[$id] = min(6, (int) $outline + 2);
            }
        }

        return $map;
    }

    private function docxText(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
            $parts[] = $node->localName === 't' ? $node->textContent : ' ';
        }

        return $this->inline(implode('', $parts));
    }

    /** @param list<string> $warnings */
    private function pptx(string $path, array &$warnings): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'PPTX file cannot be opened', 422);
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m) === 1) {
                $slides[(int) $m[1]] = $name;
            }
        }
        ksort($slides);
        $lines = [];
        foreach ($slides as $number => $name) {
            $document = new DOMDocument();
            if (!@$document->loadXML((string) $this->zipLimits()->read($zip, $name), LIBXML_NONET)) {
                $warnings[] = 'Slide ' . $number . ' could not be read.';
                continue;
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $title = '';
            $body = [];
            foreach ($xpath->query('//p:sp') ?: [] as $shape) {
                $type = (string) ($xpath->query('.//p:nvPr/p:ph/@type', $shape)->item(0)?->nodeValue ?? '');
                $paragraphs = [];
                foreach ($xpath->query('.//a:p', $shape) ?: [] as $p) {
                    $text = $this->inline(implode('', array_map(static fn ($t) => $t->textContent, iterator_to_array($xpath->query('.//a:t', $p) ?: []))));
                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }
                if ($paragraphs === []) {
                    continue;
                }
                if ($title === '' && in_array($type, ['title', 'ctrTitle'], true)) {
                    $title = implode(' ', $paragraphs);
                } else {
                    foreach ($paragraphs as $paragraph) {
                        $body[] = '- ' . $paragraph;
                    }
                }
            }
            $rows = [];
            foreach ($xpath->query('//a:tbl/a:tr') ?: [] as $tr) {
                $cells = [];
                foreach ($xpath->query('./a:tc', $tr) ?: [] as $tc) {
                    $cells[] = $this->inline($tc->textContent);
                }
                $rows[] = $cells;
            }
            $notes = '';
            $notesXml = $this->zipLimits()->read($zip, 'ppt/notesSlides/notesSlide' . $number . '.xml');
            if (is_string($notesXml) && $notesXml !== '') {
                $notesDocument = new DOMDocument();
                if (@$notesDocument->loadXML($notesXml, LIBXML_NONET)) {
                    $notesPath = new DOMXPath($notesDocument);
                    $notesPath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                    $notes = $this->inline(implode(' ', array_map(static fn ($t) => $t->textContent, iterator_to_array($notesPath->query('//a:t') ?: []))));
                    $notes = preg_replace('/\s*\d+\s*$/', '', $notes) ?? $notes;
                }
            }
            $lines[] = '## 第 ' . $number . ' 页' . ($title !== '' ? '：' . $title : '');
            $lines[] = '';
            array_push($lines, ...$body);
            if ($rows !== []) {
                $lines[] = '';
                array_push($lines, ...$this->markdownTable($rows));
            }
            if ($notes !== '') {
                $lines[] = '';
                $lines[] = '备注：' . $notes;
            }
            $lines[] = '';
        }
        $zip->close();

        return implode("\n", $lines);
    }

    /** @param list<string> $warnings */
    private function spreadsheet(string $path, string $format, array &$warnings): string
    {
        $sheets = [];
        if ($format === 'csv' || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            $handle = fopen($path, 'r');
            $rows = [];
            while ($handle !== false && ($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rows[] = array_map(fn ($cell): string => $this->inline((string) $cell), $row);
            }
            if ($handle !== false) {
                fclose($handle);
            }
            $sheets['Sheet1'] = $rows;
        } else {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
            foreach ($book->getWorksheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    $rows[] = array_map(fn ($cell): string => $this->inline(is_scalar($cell) ? (string) $cell : ''), $row);
                }
                $sheets[$sheet->getTitle()] = $rows;
            }
        }
        $lines = [];
        foreach ($sheets as $name => $rows) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => implode('', $row) !== ''));
            if ($rows === []) {
                continue;
            }
            // Drop columns that are empty in every row (common in exported sheets).
            $width = max(array_map('count', $rows));
            $keep = [];
            for ($c = 0; $c < $width; $c++) {
                foreach ($rows as $row) {
                    if (($row[$c] ?? '') !== '') {
                        $keep[] = $c;
                        break;
                    }
                }
            }
            $rows = array_map(static fn (array $row): array => array_map(static fn (int $c): string => $row[$c] ?? '', $keep), $rows);
            $lines[] = '## ' . $name;
            $lines[] = '';
            array_push($lines, ...$this->markdownTable($rows));
            $lines[] = '';
        }
        if ($lines === []) {
            $warnings[] = 'Spreadsheet has no data.';
        }

        return implode("\n", $lines);
    }

    /**
     * .doc/.wps: Word 97-2003 binary. A file with the .doc extension that is
     * really RTF, HTML or DOCX (common for exports and "Save as .doc") is
     * routed to the matching reader instead.
     *
     * @param list<string> $warnings
     */
    private function wordBinary(string $path, RagLegacyDocumentReader $legacy, array &$warnings): string
    {
        if (!OleCompoundFile::isCompoundFile($path)) {
            $head = (string) @file_get_contents($path, false, null, 0, 512);
            if (str_starts_with(ltrim($head), '{\rtf')) {
                return $this->renderBlocks($legacy->rtf((string) @file_get_contents($path), $warnings));
            }
            if (str_starts_with($head, "PK\x03\x04")) {
                $this->assertZipWithinLimits($path);
                return $this->docx($path, '', $warnings);
            }
            if (preg_match('/<html|<!doctype html|xmlns:w=/i', $head) === 1) {
                return $this->html($this->readText($path), '');
            }
        }

        return $this->renderBlocks($legacy->word($path, $warnings));
    }

    /**
     * Renders RagLegacyDocumentReader blocks. Documents without heading styles
     * fall back to the numbered-heading heuristic used for plain text.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $hasHeadings = false;
        foreach ($blocks as $block) {
            if ($block['type'] === 'heading') {
                $hasHeadings = true;
                break;
            }
        }
        $lines = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'heading') {
                array_push($lines, '', str_repeat('#', (int) $block['level']) . ' ' . $block['text'], '');
            } elseif ($block['type'] === 'table') {
                $lines[] = '';
                array_push($lines, ...$this->markdownTable($this->squareRows($block['rows'])));
                $lines[] = '';
            } elseif (!empty($block['list'])) {
                $lines[] = '- ' . $block['text'];
            } else {
                $lines[] = $hasHeadings ? (string) $block['text'] : $this->plainText((string) $block['text']);
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{title: string, body: list<string>}> $slides
     */
    private function renderSlides(array $slides): string
    {
        $lines = [];
        foreach ($slides as $index => $slide) {
            $lines[] = '## 第 ' . ($index + 1) . ' 页' . ($slide['title'] !== '' ? '：' . $slide['title'] : '');
            $lines[] = '';
            foreach ($slide['body'] as $paragraph) {
                $lines[] = '- ' . $paragraph;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function squareRows(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => array_map(fn ($cell): string => $this->inline((string) $cell), array_values($row)), $rows));
    }

    private const ODF_NS = [
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
        'presentation' => 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0',
        'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
    ];

    /** @return array{0: DOMDocument, 1: DOMXPath, 2: array<string, string>} content.xml + automatic style => parent style */
    private function odfContent(string $path, string $label): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            abort_json('RAG_DOCUMENT_UNREADABLE', $label . ' file cannot be opened', 422);
        }
        $xml = (string) $this->zipLimits()->read($zip, 'content.xml');
        $zip->close();
        $document = new DOMDocument();
        if ($xml === '' || !@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            abort_json('RAG_DOCUMENT_UNREADABLE', $label . ' has no readable content', 422);
        }
        $xpath = new DOMXPath($document);
        foreach (self::ODF_NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
        $parents = [];
        foreach ($xpath->query('//office:automatic-styles/style:style') ?: [] as $style) {
            if ($style instanceof DOMElement) {
                $parents[$style->getAttributeNS(self::ODF_NS['style'], 'name')] = $style->getAttributeNS(self::ODF_NS['style'], 'parent-style-name');
            }
        }

        return [$document, $xpath, $parents];
    }

    /** @param list<string> $warnings */
    private function odt(string $path, array &$warnings): string
    {
        [, $xpath, $parents] = $this->odfContent($path, 'ODT');
        $body = $xpath->query('//office:body/office:text')->item(0);
        $lines = [];
        if ($body instanceof DOMElement) {
            $this->odfBlocks($body, $xpath, $parents, $lines, false);
        }
        if ($lines === []) {
            $warnings[] = 'ODT contains no text paragraphs.';
        }
        if (preg_grep('/^#{1,6} /', $lines) === []) {
            foreach ($lines as $index => $line) {
                if ($line !== '' && !str_starts_with($line, '|') && !str_starts_with($line, '- ')) {
                    $lines[$index] = trim($this->plainText($line), "\n");
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $parents
     * @param list<string> $lines
     */
    private function odfBlocks(DOMElement $container, DOMXPath $xpath, array $parents, array &$lines, bool $inList): void
    {
        foreach ($container->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = $node->localName;
            if ($node->namespaceURI === self::ODF_NS['text'] && $name === 'h') {
                $text = $this->odfText($node);
                if ($text !== '') {
                    $level = min(6, max(1, (int) ($node->getAttributeNS(self::ODF_NS['text'], 'outline-level') ?: 1)) + 1);
                    array_push($lines, '', str_repeat('#', $level) . ' ' . $text, '');
                }
            } elseif ($node->namespaceURI === self::ODF_NS['text'] && $name === 'p') {
                $text = $this->odfText($node);
                if ($text === '') {
                    continue;
                }
                $style = $node->getAttributeNS(self::ODF_NS['text'], 'style-name');
                $style = $parents[$style] ?? $style;
                if (!$inList && in_array($style, ['Title', 'Heading'], true)) {
                    array_push($lines, '', '# ' . $text, '');
                } elseif ($inList) {
                    $lines[] = '- ' . $text;
                } else {
                    array_push($lines, $text, '');
                }
            } elseif ($node->namespaceURI === self::ODF_NS['text'] && in_array($name, ['list', 'list-item', 'list-header'], true)) {
                $this->odfBlocks($node, $xpath, $parents, $lines, true);
                if ($name === 'list' && !$inList) {
                    $lines[] = '';
                }
            } elseif ($node->namespaceURI === self::ODF_NS['table'] && $name === 'table') {
                $lines[] = '';
                array_push($lines, ...$this->markdownTable($this->odfTableRows($node, $xpath)));
                $lines[] = '';
            } elseif (in_array($name, ['section', 'index-body', 'table-of-content', 'illustration-index', 'alphabetical-index'], true)) {
                if ($name === 'section') {
                    $this->odfBlocks($node, $xpath, $parents, $lines, $inList);
                }
            }
        }
    }

    /** @return list<list<string>> */
    private function odfTableRows(DOMElement $table, DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('./table:table-header-rows/table:table-row|./table:table-row|./table:table-rows/table:table-row', $table) ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query('./table:table-cell|./table:covered-table-cell', $tr) ?: [] as $tc) {
                if (!$tc instanceof DOMElement) {
                    continue;
                }
                $texts = [];
                foreach ($xpath->query('.//text:p|.//text:h', $tc) ?: [] as $p) {
                    $t = $p instanceof DOMElement ? $this->odfText($p) : '';
                    if ($t !== '') {
                        $texts[] = $t;
                    }
                }
                $repeat = min(64, max(1, (int) ($tc->getAttributeNS(self::ODF_NS['table'], 'number-columns-repeated') ?: 1)));
                $value = implode(' ', $texts);
                for ($i = 0; $i < ($value === '' ? min($repeat, 1) : $repeat); $i++) {
                    $cells[] = $value;
                }
            }
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** Paragraph text with ODF spacing elements expanded and footnote bodies left out. */
    private function odfText(DOMElement $element): string
    {
        $out = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $out .= match ($child->localName) {
                    's' => str_repeat(' ', max(1, (int) ($child->getAttributeNS(self::ODF_NS['text'], 'c') ?: 1))),
                    'tab', 'line-break' => ' ',
                    'note', 'annotation', 'tracked-changes' => '',
                    default => $this->odfText($child),
                };
            } else {
                $out .= $child->textContent;
            }
        }

        return $this->inline($out);
    }

    /** @param list<string> $warnings */
    private function odp(string $path, array &$warnings): string
    {
        [, $xpath] = $this->odfContent($path, 'ODP');
        $lines = [];
        $number = 0;
        foreach ($xpath->query('//office:presentation/draw:page') ?: [] as $page) {
            $number++;
            $title = '';
            $body = [];
            $tables = [];
            // Text lives in frames (placeholders, text boxes) and in custom shapes; tables in frames.
            foreach ($xpath->query('.//draw:frame|.//draw:custom-shape|.//draw:rect|.//draw:ellipse|.//draw:polygon', $page) ?: [] as $shape) {
                if (!$shape instanceof DOMElement || $xpath->query('ancestor::presentation:notes', $shape)->length > 0) {
                    continue;
                }
                $class = $shape->getAttributeNS(self::ODF_NS['presentation'], 'class');
                foreach ($xpath->query('./table:table', $shape) ?: [] as $table) {
                    if ($table instanceof DOMElement) {
                        $tables[] = $this->odfTableRows($table, $xpath);
                    }
                }
                $query = $shape->localName === 'frame' ? './draw:text-box//text:p|./draw:text-box//text:h' : './text:p|./text:list//text:p|./text:h';
                $paragraphs = [];
                foreach ($xpath->query($query, $shape) ?: [] as $p) {
                    $t = $p instanceof DOMElement ? $this->odfText($p) : '';
                    if ($t !== '') {
                        $paragraphs[] = $t;
                    }
                }
                if ($paragraphs === []) {
                    continue;
                }
                if ($title === '' && $class === 'title') {
                    $title = implode(' ', $paragraphs);
                } else {
                    foreach ($paragraphs as $paragraph) {
                        $body[] = '- ' . $paragraph;
                    }
                }
            }
            $notes = [];
            foreach ($xpath->query('./presentation:notes//draw:text-box//text:p', $page) ?: [] as $p) {
                $t = $p instanceof DOMElement ? $this->odfText($p) : '';
                if ($t !== '') {
                    $notes[] = $t;
                }
            }
            $lines[] = '## 第 ' . $number . ' 页' . ($title !== '' ? '：' . $title : '');
            $lines[] = '';
            array_push($lines, ...$body);
            foreach ($tables as $rows) {
                $lines[] = '';
                array_push($lines, ...$this->markdownTable($rows));
            }
            if ($notes !== []) {
                $lines[] = '';
                $lines[] = '备注：' . implode(' ', $notes);
            }
            $lines[] = '';
        }
        if ($number === 0) {
            $warnings[] = 'ODP contains no slides.';
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $warnings */
    private function pdf(string $path, int &$pages, bool &$needsWorker, array &$warnings): string
    {
        $text = is_callable($this->pdfTextExtractor) ? ($this->pdfTextExtractor)($path) : $this->pdftotext($path);
        if ($text === null) {
            $needsWorker = true;
            return '';
        }
        $pageTexts = explode("\f", $text);
        if (end($pageTexts) !== false && trim((string) end($pageTexts)) === '') {
            array_pop($pageTexts);
        }
        $pages = count($pageTexts);
        $emptyPages = 0;
        $repeated = $this->repeatedPageLines($pageTexts);
        $lines = [];
        foreach ($pageTexts as $index => $pageText) {
            $lines[] = '<!-- page:' . ($index + 1) . ' -->';
            $kept = [];
            foreach (explode("\n", $pageText) as $line) {
                if (!isset($repeated[$this->lineKey($line)])) {
                    $kept[] = $line;
                }
            }
            $body = trim(implode("\n", $kept));
            if ($body === '') {
                $emptyPages++;
                continue;
            }
            $lines[] = $this->plainText($this->joinPdfLines($body));
            $lines[] = '';
        }
        if ($repeated !== []) {
            $warnings[] = 'Removed ' . count($repeated) . ' repeated page header/footer line(s).';
        }
        if ($pages > 0 && $emptyPages / $pages > 0.5) {
            // Mostly image pages: the worker OCRs them.
            $warnings[] = 'Most PDF pages have no text layer; OCR is required.';
            $needsWorker = true;
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * Lines that appear at the top or bottom of most pages (digits ignored) are
     * running headers/footers.
     *
     * @param list<string> $pageTexts
     * @return array<string, true>
     */
    private function repeatedPageLines(array $pageTexts): array
    {
        if (count($pageTexts) < 3) {
            return [];
        }
        $counts = [];
        foreach ($pageTexts as $pageText) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $pageText)), static fn (string $line): bool => $line !== ''));
            $edge = array_unique(array_map(fn (string $line): string => $this->lineKey($line), [...array_slice($lines, 0, 2), ...array_slice($lines, -2)]));
            foreach ($edge as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $threshold = max(3, (int) ceil(count($pageTexts) * 0.6));
        $repeated = [];
        foreach ($counts as $key => $count) {
            if ($key !== '' && $count >= $threshold && mb_strlen($key) <= 80) {
                $repeated[$key] = true;
            }
        }

        return $repeated;
    }

    private function lineKey(string $line): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        // Page counters ("第 3 页 共 10 页", "Page 3 of 10", "3/10") differ per page only in digits.
        if (mb_strlen($line) <= 30 && preg_match('/(页|page|Page|PAGE|\/|of)/u', $line) === 1) {
            return preg_replace('/\d+/', '#', $line) ?? $line;
        }

        return $line;
    }

    /** Re-joins lines that pdftotext broke in the middle of a sentence (wrapped paragraph lines only). */
    private function joinPdfLines(string $text): string
    {
        $lines = explode("\n", $text);
        $lengths = array_values(array_filter(array_map(static fn (string $line): int => mb_strlen(trim($line)), $lines)));
        sort($lengths);
        $typical = $lengths === [] ? 0 : $lengths[(int) floor(count($lengths) * 0.75)];
        $out = [];
        foreach ($lines as $line) {
            $line = rtrim($line);
            $previous = $out === [] ? '' : (string) end($out);
            $wrapped = $previous !== '' && $line !== ''
                && mb_strlen(trim($previous)) >= max(20, (int) ($typical * 0.8))
                && preg_match('/[。！？.!?:：;；]$/u', $previous) !== 1
                && preg_match('/^\s*(第.{1,6}[章节条]|\d+(\.\d+)*[\s.、]|[一二三四五六七八九十]+、|[-*•])/u', $line) !== 1;
            if ($wrapped) {
                $joiner = preg_match('/\p{Han}$/u', $previous) === 1 ? '' : ' ';
                $out[count($out) - 1] = $previous . $joiner . ltrim($line);
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function pdftotext(string $path): ?string
    {
        static $binary = null;
        $binary ??= trim((string) @shell_exec('command -v pdftotext 2>/dev/null')) ?: false;
        if ($binary === false) {
            return null;
        }
        $output = @shell_exec(escapeshellarg((string) $binary) . ' -enc UTF-8 -eol unix ' . escapeshellarg($path) . ' - 2>/dev/null');

        return is_string($output) ? $output : null;
    }

    /**
     * Removes repeated page headers/footers, bare page numbers and control
     * characters; collapses runs of blank lines (RAG-022).
     *
     * @param list<string> $warnings
     */
    private function clean(string $markdown, array &$warnings): string
    {
        $markdown = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FEFF}\x{200B}]/u', '', $markdown) ?? $markdown;
        $lines = explode("\n", $markdown);
        $lines = array_values(array_filter($lines, static fn (string $line): bool => preg_match('/^\s*(-\s*)?(第\s*)?\d{1,4}(\s*页)?(\s*\/\s*\d{1,4})?\s*(-\s*)?$/u', $line) !== 1));
        $markdown = implode("\n", $lines);

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown) . "\n";
    }

    /**
     * @param list<list<string>> $rows first row is the header
     * @return list<string>
     */
    private function markdownTable(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => implode('', $row) !== ''));
        if ($rows === []) {
            return [];
        }
        $width = max(array_map('count', $rows));
        $escape = static fn (string $cell): string => str_replace(['|', "\n"], ['\\|', ' '], $cell);
        $line = static fn (array $row): string => '| ' . implode(' | ', array_map(static fn (int $i): string => $escape($row[$i] ?? ''), range(0, $width - 1))) . ' |';
        $out = [$line($rows[0]), '| ' . implode(' | ', array_fill(0, $width, '---')) . ' |'];
        foreach (array_slice($rows, 1) as $row) {
            $out[] = $line($row);
        }

        return $out;
    }

    private function inline(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
