<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\OleCompoundFile;

/**
 * Text extraction for legacy binary and RTF documents, without LibreOffice:
 *
 *  - Word 97-2003 .doc and WPS .wps ([MS-DOC]): piece table for the text,
 *    paragraph properties (PAPX) for heading styles, list items and table rows.
 *  - PowerPoint 97-2003 .ppt and WPS .dps ([MS-PPT]): slide text atoms in
 *    slide order, titles from the text header type.
 *  - RTF: control-word tokenizer with code-page aware \'hh decoding, \uN,
 *    headings from \outlinelevel and "heading N" styles, and \cell/\row tables.
 *
 * Results are neutral blocks that RagDocumentParserService renders as Markdown:
 *   ['type' => 'heading', 'level' => int, 'text' => string]
 *   ['type' => 'paragraph', 'text' => string, 'list' => bool]
 *   ['type' => 'table', 'rows' => list<list<string>>]
 */
final class RagLegacyDocumentReader
{
    /* ----------------------------------------------------------------- Word */

    /**
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    public function word(string $path, array &$warnings): array
    {
        $ole = OleCompoundFile::open($path);
        if ($ole === null) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The .doc file is not a Word 97-2003 document; save it as .docx and upload again', 422);
        }
        if ($ole->hasStream('EncryptedPackage')) {
            abort_json('RAG_DOCUMENT_ENCRYPTED', 'The document is password protected; remove the password and upload again', 422);
        }
        $wd = $ole->stream('WordDocument');
        if ($wd === null || strlen($wd) < 0x60) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The file has no Word document stream; save it as .docx and upload again', 422);
        }
        $nFib = $this->u16($wd, 2);
        $flags = $this->u16($wd, 0x0A);
        if (($flags & 0x0100) !== 0) {
            abort_json('RAG_DOCUMENT_ENCRYPTED', 'The document is password protected; remove the password and upload again', 422);
        }
        if ($nFib >= 101 && $nFib <= 105) {
            return $this->word95($wd, $flags, $warnings);
        }
        if ($this->u16($wd, 0) !== 0xA5EC || strlen($wd) < 0x1AA || $nFib < 101) {
            abort_json('RAG_DOCUMENT_UNSUPPORTED', 'This Word format version is not supported; save it as .docx and upload again', 422);
        }
        $table = $ole->stream(($flags & 0x0200) !== 0 ? '1Table' : '0Table') ?? '';
        $ccpText = $this->u32($wd, 0x4C);
        $pieces = $this->wordPieces($table, $this->u32($wd, 0x1A2), $this->u32($wd, 0x1A6));
        if ($pieces === []) {
            // Non-complex files without a CLX: the text is one uncompressed run after the FIB.
            $fcMin = $this->u32($wd, 0x18);
            $pieces = [['cp' => 0, 'cpEnd' => $ccpText, 'fc' => $fcMin, 'compressed' => false]];
        }
        $paragraphProps = $this->wordParagraphProperties($wd, $table, $this->u32($wd, 0x102), $this->u32($wd, 0x106));
        $headingStyles = $this->wordHeadingStyles($table, $this->u32($wd, 0xA2), $this->u32($wd, 0xA6));

        $blocks = [];
        $rows = [];
        $row = [];
        $cell = '';
        $paragraph = '';
        $fieldStack = [];
        $flushTable = static function () use (&$rows, &$row, &$cell, &$blocks): void {
            if ($cell !== '' || $row !== []) {
                $row[] = trim($cell);
                $rows[] = $row;
            }
            if ($rows !== []) {
                $blocks[] = ['type' => 'table', 'rows' => $rows];
            }
            $rows = [];
            $row = [];
            $cell = '';
        };

        foreach ($pieces as $piece) {
            $cpStart = $piece['cp'];
            $cpEnd = min($piece['cpEnd'], $ccpText);
            if ($cpEnd <= $cpStart) {
                continue;
            }
            $width = $piece['compressed'] ? 1 : 2;
            $raw = substr($wd, $piece['fc'], ($cpEnd - $cpStart) * $width);
            if ($raw === '') {
                continue;
            }
            $text = $piece['compressed']
                ? (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')
                : (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            $segments = preg_split('/([\r\x07\x0C])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $unitOffset = 0;
            foreach ($segments as $segment) {
                if ($segment === "\r" || $segment === "\x07" || $segment === "\x0C") {
                    $fc = $piece['fc'] + $unitOffset * $width;
                    $unitOffset++;
                    $props = $this->lookupProps($paragraphProps, $fc);
                    $line = $this->inline($paragraph);
                    $paragraph = '';
                    if ($segment === "\x07") {
                        if ($props['ttp']) {
                            if ($cell !== '' || $line !== '') {
                                $row[] = trim($cell . ' ' . $line);
                            }
                            $rows[] = $row;
                            $row = [];
                            $cell = '';
                        } else {
                            $row[] = trim($cell . ' ' . $line);
                            $cell = '';
                        }
                        continue;
                    }
                    if ($props['inTable'] && $segment === "\r") {
                        $cell .= ($cell === '' ? '' : ' ') . $line;
                        continue;
                    }
                    if ($rows !== [] || $row !== []) {
                        $flushTable();
                    }
                    if ($line === '') {
                        continue;
                    }
                    $level = $headingStyles[$props['istd']] ?? null;
                    if ($level === null && $props['outline'] !== null && $props['outline'] < 9) {
                        $level = $props['outline'] + 2;
                    }
                    if ($level !== null && mb_strlen($line) <= 200) {
                        $blocks[] = ['type' => 'heading', 'level' => min(6, $level), 'text' => $line];
                    } else {
                        $blocks[] = ['type' => 'paragraph', 'text' => $line, 'list' => $props['list']];
                    }
                    continue;
                }
                $unitOffset += $width === 1 ? strlen($segment) : intdiv(strlen((string) mb_convert_encoding($segment, 'UTF-16LE', 'UTF-8')), 2);
                $paragraph .= $this->stripWordFields($segment, $fieldStack);
            }
        }
        $line = $this->inline($paragraph);
        if ($line !== '') {
            $blocks[] = ['type' => 'paragraph', 'text' => $line, 'list' => false];
        }
        $flushTable();
        if ($paragraphProps === []) {
            $warnings[] = 'Paragraph properties could not be read; headings and table rows may be incomplete.';
        }

        return $blocks;
    }

    /**
     * Word 6.0/95: 8-bit text in the document's code page. Text only: the
     * paragraph properties of this version are not read, so headings come from
     * the numbered-heading heuristic and table cells are joined on one line.
     *
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    private function word95(string $wd, int $flags, array &$warnings): array
    {
        $ccpText = $this->u32($wd, 0x34);
        $codepage = match ($this->u16($wd, 0x06)) {
            0x0804, 0x1004 => 936,
            0x0404, 0x0C04, 0x1404 => 950,
            0x0411 => 932,
            0x0412 => 949,
            0x0419, 0x0402, 0x0422, 0x0423, 0x0C1A => 1251,
            0x0405, 0x0415, 0x040E, 0x041B, 0x0424, 0x041A => 1250,
            0x0408 => 1253,
            0x041F => 1254,
            default => 1252,
        };
        $raw = '';
        if (($flags & 0x0004) !== 0) {
            // Fast-saved: the piece table lives in the WordDocument stream; pieces are 8-bit.
            foreach ($this->wordPieces($wd, $this->u32($wd, 0x160), $this->u32($wd, 0x164)) as $piece) {
                $cpEnd = min($piece['cpEnd'], $ccpText);
                if ($cpEnd > $piece['cp']) {
                    $fc = $piece['compressed'] ? $piece['fc'] * 2 : $piece['fc'];
                    $raw .= substr($wd, $fc, $cpEnd - $piece['cp']);
                }
            }
        } else {
            $fcMin = $this->u32($wd, 0x18);
            $fcMac = $this->u32($wd, 0x1C);
            $raw = substr($wd, $fcMin, max(0, min($fcMac - $fcMin, $ccpText > 0 ? $ccpText : PHP_INT_MAX)));
        }
        if ($codepage === 1252 && preg_match_all('/[\x80-\xFF]/', $raw) > strlen($raw) / 5 && mb_check_encoding($raw, 'CP936')) {
            // Chinese Word 95 files often carry an English language id; GBK text is mostly high bytes.
            $candidate = (string) mb_convert_encoding($raw, 'UTF-8', 'CP936');
            if (preg_match_all('/\p{Han}/u', $candidate) > mb_strlen($candidate) / 4) {
                $codepage = 936;
            }
        }
        $text = $this->decodeCodepage($raw, $codepage);
        $stack = [];
        $blocks = [];
        foreach (preg_split('/[\r\x0C]/', $text) ?: [] as $paragraph) {
            $line = $this->inline(str_replace("\x07", ' | ', $this->stripWordFields($paragraph, $stack)));
            $line = trim((string) preg_replace('/(\s*\|\s*)+/', ' | ', $line), ' |');
            if ($line !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $line, 'list' => false];
            }
        }
        $warnings[] = 'Word 6.0/95 document: text only (no heading styles or table layout).';

        return $blocks;
    }

    /** @return list<array{cp: int, cpEnd: int, fc: int, compressed: bool}> */
    private function wordPieces(string $table, int $fcClx, int $lcbClx): array
    {
        if ($lcbClx <= 0 || $fcClx + $lcbClx > strlen($table)) {
            return [];
        }
        $offset = $fcClx;
        $end = $fcClx + $lcbClx;
        while ($offset < $end && ord($table[$offset]) === 0x01) {
            $offset += 3 + $this->u16($table, $offset + 1); // Prc: clxt + cbGrpprl + grpprl
        }
        if ($offset >= $end || ord($table[$offset]) !== 0x02) {
            return [];
        }
        $lcb = $this->u32($table, $offset + 1);
        $plc = $offset + 5;
        if ($lcb < 16 || $plc + $lcb > strlen($table)) {
            return [];
        }
        $count = intdiv($lcb - 4, 12);
        $pieces = [];
        for ($i = 0; $i < $count; $i++) {
            $cp = $this->u32($table, $plc + $i * 4);
            $cpEnd = $this->u32($table, $plc + ($i + 1) * 4);
            $fcCompressed = $this->u32($table, $plc + ($count + 1) * 4 + $i * 8 + 2);
            $compressed = ($fcCompressed & 0x40000000) !== 0;
            $fc = $fcCompressed & 0x3FFFFFFF;
            $pieces[] = ['cp' => $cp, 'cpEnd' => $cpEnd, 'fc' => $compressed ? intdiv($fc, 2) : $fc, 'compressed' => $compressed];
        }
        usort($pieces, static fn (array $a, array $b): int => $a['cp'] <=> $b['cp']);

        return $pieces;
    }

    /**
     * Paragraph runs from the PAPX FKPs: [fcStart, fcEnd, props], sorted by fc.
     *
     * @return list<array{0: int, 1: int, 2: array{istd: int, ttp: bool, inTable: bool, list: bool, outline: ?int}}>
     */
    private function wordParagraphProperties(string $wd, string $table, int $fcPlcf, int $lcbPlcf): array
    {
        if ($lcbPlcf < 12 || $fcPlcf + $lcbPlcf > strlen($table)) {
            return [];
        }
        $count = intdiv($lcbPlcf - 4, 8);
        $runs = [];
        for ($i = 0; $i < $count && $i < 65536; $i++) {
            $pn = $this->u32($table, $fcPlcf + ($count + 1) * 4 + $i * 4) & 0x3FFFFF;
            $page = substr($wd, $pn * 512, 512);
            if (strlen($page) !== 512) {
                continue;
            }
            $crun = ord($page[511]);
            if (4 * ($crun + 1) + 13 * $crun > 511) {
                continue;
            }
            for ($r = 0; $r < $crun; $r++) {
                $fcStart = $this->u32($page, $r * 4);
                $fcEnd = $this->u32($page, ($r + 1) * 4);
                $bOffset = ord($page[4 * ($crun + 1) + $r * 13]) * 2;
                $props = ['istd' => 0, 'ttp' => false, 'inTable' => false, 'list' => false, 'outline' => null];
                if ($bOffset > 0 && $bOffset < 511) {
                    $cb = ord($page[$bOffset]);
                    if ($cb !== 0) {
                        $start = $bOffset + 1;
                        $size = 2 * $cb - 1;
                    } else {
                        $start = $bOffset + 2;
                        $size = 2 * ord($page[$bOffset + 1] ?? "\0");
                    }
                    $grpprl = substr($page, $start, max(0, min($size, 511 - $start)));
                    $props = $this->wordPapx($grpprl);
                }
                $runs[] = [$fcStart, $fcEnd, $props];
            }
        }
        usort($runs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $runs;
    }

    /** @return array{istd: int, ttp: bool, inTable: bool, list: bool, outline: ?int} */
    private function wordPapx(string $grpprl): array
    {
        $props = ['istd' => $this->u16($grpprl, 0), 'ttp' => false, 'inTable' => false, 'list' => false, 'outline' => null];
        $offset = 2;
        $length = strlen($grpprl);
        while ($offset + 2 <= $length) {
            $sprm = $this->u16($grpprl, $offset);
            $offset += 2;
            $operandSize = match ($sprm >> 13) {
                0, 1 => 1,
                2, 4, 5 => 2,
                3 => 4,
                7 => 3,
                default => -1,
            };
            if ($operandSize === -1) {
                if ($sprm === 0xD608 || $sprm === 0xD606) {
                    $operandSize = 2 + max(0, $this->u16($grpprl, $offset) - 1);
                } else {
                    $operandSize = 1 + ord($grpprl[$offset] ?? "\0");
                }
            }
            if ($offset + $operandSize > $length) {
                break;
            }
            $value = $operandSize >= 1 ? ord($grpprl[$offset]) : 0;
            match ($sprm) {
                0x2417, 0x244C => $props['ttp'] = $props['ttp'] || $value === 1,
                0x2416 => $props['inTable'] = $value === 1,
                0x6649 => $props['inTable'] = $props['inTable'] || $this->u32($grpprl, $offset) > 0,
                0x460B => $props['list'] = $this->u16($grpprl, $offset) !== 0,
                0x2640 => $props['outline'] = $value,
                default => null,
            };
            $offset += $operandSize;
        }

        return $props;
    }

    /**
     * @param list<array{0: int, 1: int, 2: array{istd: int, ttp: bool, inTable: bool, list: bool, outline: ?int}}> $runs
     * @return array{istd: int, ttp: bool, inTable: bool, list: bool, outline: ?int}
     */
    private function lookupProps(array $runs, int $fc): array
    {
        $low = 0;
        $high = count($runs) - 1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if ($fc < $runs[$mid][0]) {
                $high = $mid - 1;
            } elseif ($fc >= $runs[$mid][1]) {
                $low = $mid + 1;
            } else {
                return $runs[$mid][2];
            }
        }

        return ['istd' => 0, 'ttp' => false, 'inTable' => false, 'list' => false, 'outline' => null];
    }

    /**
     * Built-in heading styles from the stylesheet: sti 1-9 are "heading 1-9",
     * sti 62 is "Title". Levels follow the DOCX mapping (Title 1, Heading N -> N+1).
     *
     * @return array<int, int> istd => heading level
     */
    private function wordHeadingStyles(string $table, int $fcStshf, int $lcbStshf): array
    {
        if ($lcbStshf < 4 || $fcStshf + $lcbStshf > strlen($table)) {
            return [];
        }
        $stsh = substr($table, $fcStshf, $lcbStshf);
        $cbStshi = $this->u16($stsh, 0);
        $cstd = $this->u16($stsh, 2);
        $offset = 2 + $cbStshi;
        $map = [];
        for ($istd = 0; $istd < $cstd && $offset + 2 <= strlen($stsh); $istd++) {
            $cbStd = $this->u16($stsh, $offset);
            if ($cbStd > 0) {
                $sti = $this->u16($stsh, $offset + 2) & 0x0FFF;
                if ($sti >= 1 && $sti <= 9) {
                    $map[$istd] = min(6, $sti + 1);
                } elseif ($sti === 62) {
                    $map[$istd] = 1;
                }
            }
            $offset += 2 + $cbStd;
        }

        return $map;
    }

    /**
     * Drops field instructions (\x13 instruction \x14 result \x15) but keeps
     * the displayed result; strips object anchors and other control marks.
     *
     * @param list<bool> $stack true once the field reached its result part
     */
    private function stripWordFields(string $text, array &$stack): string
    {
        if (strpbrk($text, "\x13\x14\x15") === false) {
            return in_array(false, $stack, true) ? '' : $this->wordChars($text);
        }
        $out = '';
        $parts = preg_split('/([\x13\x14\x15])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $part) {
            if ($part === "\x13") {
                $stack[] = false;
            } elseif ($part === "\x14") {
                if ($stack !== []) {
                    $stack[count($stack) - 1] = true;
                }
            } elseif ($part === "\x15") {
                array_pop($stack);
            } elseif (!in_array(false, $stack, true)) {
                $out .= $this->wordChars($part);
            }
        }

        return $out;
    }

    private function wordChars(string $text): string
    {
        return strtr($text, [
            "\x0B" => ' ', "\x09" => ' ', "\x1E" => '-', "\x1F" => '', "\x01" => '', "\x02" => '', "\x05" => '', "\x08" => '',
        ]);
    }

    /* ----------------------------------------------------------- PowerPoint */

    /**
     * @param list<string> $warnings
     * @return list<array{title: string, body: list<string>}>
     */
    public function powerPoint(string $path, array &$warnings): array
    {
        $ole = OleCompoundFile::open($path);
        if ($ole === null) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The .ppt file is not a PowerPoint 97-2003 presentation; save it as .pptx and upload again', 422);
        }
        if ($ole->hasStream('EncryptedPackage') || $ole->hasStream('EncryptedSummary')) {
            abort_json('RAG_DOCUMENT_ENCRYPTED', 'The presentation is password protected; remove the password and upload again', 422);
        }
        $ppt = $ole->stream('PowerPoint Document');
        if ($ppt === null || strlen($ppt) < 8) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The file has no PowerPoint document stream; save it as .pptx and upload again', 422);
        }
        $persist = $this->pptPersistDirectory($ppt, (string) $ole->stream('Current User'));

        // Slide order and placeholder text: SlideListWithText (instance 0) in the document container.
        $slides = [];
        $current = null;
        $textType = 1;
        $this->pptWalk($ppt, 0, strlen($ppt), 0, function (int $type, int $instance, int $offset, int $length, array $path) use (&$slides, &$current, &$textType, $ppt): void {
            $inSlideList = false;
            foreach ($path as $ancestor) {
                if ($ancestor[0] === 0x0FF0) {
                    $inSlideList = $ancestor[1] === 0;
                }
            }
            if (!$inSlideList || !in_array(0x03E8, array_column($path, 0), true)) {
                return;
            }
            if ($type === 0x03F3) {
                $current = count($slides);
                $slides[] = ['persist' => $this->u32($ppt, $offset), 'texts' => []];
            } elseif ($type === 0x0F9F) {
                $textType = $this->u32($ppt, $offset);
            } elseif (($type === 0x0FA0 || $type === 0x0FA8) && $current !== null) {
                $slides[$current]['texts'][] = [$textType, $this->pptText($ppt, $type, $offset, $length)];
            }
        });

        // Non-placeholder shapes keep their text inside the slide's own drawing.
        $result = [];
        $visitedSlideOffsets = [];
        foreach ($slides as $slide) {
            $texts = $slide['texts'];
            $slideOffset = $persist[$slide['persist']] ?? null;
            if ($slideOffset !== null && $slideOffset + 8 <= strlen($ppt) && $this->u16($ppt, $slideOffset + 2) === 0x03EE) {
                $visitedSlideOffsets[$slideOffset] = true;
                $texts = [...$texts, ...$this->pptContainerTexts($ppt, $slideOffset)];
            }
            $result[] = $this->pptSlide($texts);
        }
        if ($result === []) {
            // No slide list (unusual writers): fall back to every slide container in file order.
            $offset = 0;
            while ($offset + 8 <= strlen($ppt)) {
                $type = $this->u16($ppt, $offset + 2);
                $length = $this->u32($ppt, $offset + 4);
                if ($type === 0x03EE) {
                    $result[] = $this->pptSlide($this->pptContainerTexts($ppt, $offset));
                }
                if ($length <= 0 && $type === 0) {
                    break;
                }
                $offset += 8 + $length;
            }
            if ($result !== []) {
                $warnings[] = 'Slide order could not be read; slides are listed in file order.';
            }
        }

        $result = array_values(array_filter($result, static fn (array $s): bool => $s['title'] !== '' || $s['body'] !== []));
        if ($result === [] && str_contains($ppt, "\x0F\x00\x14\x2F")) {
            // CryptSession10Container: RC4 / CryptoAPI encrypted presentation.
            abort_json('RAG_DOCUMENT_ENCRYPTED', 'The presentation is password protected; remove the password and upload again', 422);
        }

        return $result;
    }

    /**
     * persistId => stream offset, following the current user edit and older edits.
     *
     * @return array<int, int>
     */
    private function pptPersistDirectory(string $ppt, string $currentUser): array
    {
        $edit = strlen($currentUser) >= 20 ? $this->u32($currentUser, 16) : -1;
        $directory = [];
        $seen = [];
        while ($edit >= 0 && $edit + 8 + 20 <= strlen($ppt) && !isset($seen[$edit]) && $this->u16($ppt, $edit + 2) === 0x0FF5) {
            $seen[$edit] = true;
            $body = $edit + 8;
            $lastEdit = $this->u32($ppt, $body + 8);
            $persistOffset = $this->u32($ppt, $body + 12);
            if ($persistOffset + 8 <= strlen($ppt) && $this->u16($ppt, $persistOffset + 2) === 0x1772) {
                $length = $this->u32($ppt, $persistOffset + 4);
                $p = $persistOffset + 8;
                $end = min(strlen($ppt), $p + $length);
                while ($p + 4 <= $end) {
                    $head = $this->u32($ppt, $p);
                    $id = $head & 0xFFFFF;
                    $count = $head >> 20;
                    $p += 4;
                    for ($i = 0; $i < $count && $p + 4 <= $end; $i++, $p += 4) {
                        $directory[$id + $i] ??= $this->u32($ppt, $p);
                    }
                }
            }
            $edit = $lastEdit > 0 ? $lastEdit : -1;
        }

        return $directory;
    }

    /** @return list<array{0: int, 1: string}> [text type, text] pairs inside one container */
    private function pptContainerTexts(string $ppt, int $offset): array
    {
        $length = $this->u32($ppt, $offset + 4);
        $texts = [];
        $textType = 4;
        $this->pptWalk($ppt, $offset + 8, min(strlen($ppt), $offset + 8 + $length), 1, function (int $type, int $instance, int $atom, int $atomLength) use (&$texts, &$textType, $ppt): void {
            if ($type === 0x0F9F) {
                $textType = $this->u32($ppt, $atom);
            } elseif ($type === 0x0FA0 || $type === 0x0FA8) {
                $texts[] = [$textType, $this->pptText($ppt, $type, $atom, $atomLength)];
            }
        });

        return $texts;
    }

    /**
     * Depth-first walk over records; containers (recVer 0xF) are entered.
     *
     * @param callable(int, int, int, int, list<array{0: int, 1: int}>): void $visit
     * @param list<array{0: int, 1: int}> $path
     */
    private function pptWalk(string $ppt, int $offset, int $end, int $depth, callable $visit, array $path = []): void
    {
        while ($offset + 8 <= $end) {
            $verInstance = $this->u16($ppt, $offset);
            $type = $this->u16($ppt, $offset + 2);
            $length = $this->u32($ppt, $offset + 4);
            $body = $offset + 8;
            if ($length < 0 || $body + $length > $end) {
                return;
            }
            $instance = $verInstance >> 4;
            if (($verInstance & 0x0F) === 0x0F) {
                if ($depth < 32) {
                    $this->pptWalk($ppt, $body, $body + $length, $depth + 1, $visit, [...$path, [$type, $instance]]);
                }
            } else {
                $visit($type, $instance, $body, $length, $path);
            }
            $offset = $body + $length;
        }
    }

    private function pptText(string $ppt, int $type, int $offset, int $length): string
    {
        $raw = substr($ppt, $offset, $length);

        return $type === 0x0FA0
            ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
            : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * @param list<array{0: int, 1: string}> $texts
     * @return array{title: string, body: list<string>}
     */
    private function pptSlide(array $texts): array
    {
        $title = '';
        $body = [];
        foreach ($texts as [$type, $text]) {
            $paragraphs = array_values(array_filter(array_map(fn (string $p): string => $this->inline(str_replace("\x0B", ' ', $p)), preg_split('/\r|\n/', $text) ?: []), static fn (string $p): bool => $p !== '' && $p !== '*'));
            if ($paragraphs === []) {
                continue;
            }
            if ($title === '' && in_array($type, [0, 6], true)) {
                $title = implode(' ', $paragraphs);
                continue;
            }
            foreach ($paragraphs as $paragraph) {
                if (!in_array($paragraph, $body, true)) {
                    $body[] = $paragraph;
                }
            }
        }

        return ['title' => $title, 'body' => $body];
    }

    /* ------------------------------------------------------------------ RTF */

    private const RTF_CHARSET_CODEPAGES = [
        0 => 1252, 128 => 932, 129 => 949, 134 => 936, 136 => 950, 161 => 1253, 162 => 1254,
        163 => 1258, 177 => 1255, 178 => 1256, 186 => 1257, 204 => 1251, 222 => 874, 238 => 1250,
    ];

    private const RTF_SKIP_DESTINATIONS = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'headerl', 'headerr', 'headerf',
        'footer', 'footerl', 'footerr', 'footerf', 'fldinst', 'themedata', 'colorschememapping', 'latentstyles',
        'datastore', 'listtable', 'listoverridetable', 'rsidtbl', 'generator', 'xmlnstbl', 'mmathpr', 'filetbl',
        'revtbl', 'pgdsctbl', 'object', 'objdata', 'shpinst', 'nonshppict', 'footnote', 'annotation', 'atnid',
        'atnauthor', 'bkmkstart', 'bkmkend', 'xe', 'tc', 'listtext', 'pntext', 'pntxta', 'pntxtb', 'fldtype',
    ];

    /**
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    public function rtf(string $rtf, array &$warnings): array
    {
        if (!str_starts_with(ltrim($rtf), '{\\rtf')) {
            abort_json('RAG_DOCUMENT_UNREADABLE', 'The file is not a valid RTF document', 422);
        }
        $defaultCodepage = preg_match('/\\\\ansicpg(\d+)/', substr($rtf, 0, 2048), $m) === 1 ? (int) $m[1] : 1252;
        $fontCodepages = $this->rtfFontCodepages($rtf);
        $styleLevels = $this->rtfStyleLevels($rtf, $defaultCodepage);

        $blocks = [];
        $rows = [];
        $row = [];
        $cell = '';
        $text = '';
        $bytes = '';
        $para = ['style' => 0, 'outline' => null, 'inTable' => false, 'list' => false];
        $state = ['skip' => false, 'uc' => 1, 'codepage' => $defaultCodepage];
        $stack = [];
        $skipChars = 0;
        $length = strlen($rtf);

        $flushBytes = function () use (&$bytes, &$text, &$state): void {
            if ($bytes !== '') {
                $text .= $this->decodeCodepage($bytes, $state['codepage']);
                $bytes = '';
            }
        };
        $endParagraph = function (bool $cellEnd = false, bool $rowEnd = false) use (&$text, &$cell, &$row, &$rows, &$blocks, &$para, $styleLevels, $flushBytes): void {
            $flushBytes();
            $line = $this->inline($text);
            $text = '';
            if ($cellEnd) {
                $row[] = trim($cell . ' ' . $line);
                $cell = '';
                return;
            }
            if ($rowEnd) {
                if ($cell !== '' || $line !== '') {
                    $row[] = trim($cell . ' ' . $line);
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
                $row = [];
                $cell = '';
                return;
            }
            if ($para['inTable']) {
                $cell .= ($cell === '' ? '' : ' ') . $line;
                return;
            }
            if ($rows !== []) {
                $blocks[] = ['type' => 'table', 'rows' => $rows];
                $rows = [];
            }
            if ($line === '') {
                return;
            }
            $level = $para['outline'] !== null && $para['outline'] < 9 ? $para['outline'] + 2 : ($styleLevels[$para['style']] ?? null);
            if ($level !== null && mb_strlen($line) <= 200) {
                $blocks[] = ['type' => 'heading', 'level' => min(6, $level), 'text' => $line];
            } else {
                $blocks[] = ['type' => 'paragraph', 'text' => $line, 'list' => $para['list']];
            }
        };

        for ($i = 0; $i < $length; $i++) {
            $char = $rtf[$i];
            if ($char === '{') {
                $stack[] = $state;
                // "{\*\dest" and known non-text destinations are skipped as a whole.
                if (preg_match('/\G\{\s*\\\\(\*|[a-z]+)/A', $rtf, $dm, 0, $i) === 1) {
                    if ($dm[1] === '*' || in_array($dm[1], self::RTF_SKIP_DESTINATIONS, true)) {
                        $state['skip'] = true;
                    }
                }
                continue;
            }
            if ($char === '}') {
                if (!$state['skip']) {
                    $flushBytes();
                }
                $state = array_pop($stack) ?? $state;
                continue;
            }
            if ($char === '\\') {
                $next = $rtf[$i + 1] ?? '';
                if ($next === "'") {
                    $hex = substr($rtf, $i + 2, 2);
                    $i += 3;
                    if ($skipChars > 0) {
                        $skipChars--;
                        continue;
                    }
                    if (!$state['skip'] && ctype_xdigit($hex)) {
                        $bytes .= chr((int) hexdec($hex));
                    }
                    continue;
                }
                if ($next === '\\' || $next === '{' || $next === '}') {
                    $i++;
                    if ($skipChars > 0) {
                        $skipChars--;
                        continue;
                    }
                    if (!$state['skip']) {
                        $flushBytes();
                        $text .= $next;
                    }
                    continue;
                }
                if ($next === '~' || $next === '_' || $next === '-' || $next === '*' || $next === ':' || $next === '|') {
                    $i++;
                    if (!$state['skip']) {
                        $flushBytes();
                        $text .= match ($next) { '~' => ' ', '_' => '-', default => '' };
                    }
                    continue;
                }
                if ($next === "\n" || $next === "\r") {
                    $i++;
                    if (!$state['skip']) {
                        $endParagraph();
                    }
                    continue;
                }
                if (preg_match('/\G\\\\([a-zA-Z]{1,32})(-?\d{1,10})? ?/A', $rtf, $cm, 0, $i) !== 1) {
                    $i++;
                    continue;
                }
                $i += strlen($cm[0]) - 1;
                $word = $cm[1];
                $param = isset($cm[2]) && $cm[2] !== '' ? (int) $cm[2] : null;
                if ($word === 'bin' && $param !== null && $param > 0) {
                    $i += $param;
                    continue;
                }
                if ($state['skip']) {
                    continue;
                }
                if ($word === 'u' && $param !== null) {
                    $flushBytes();
                    $code = $param < 0 ? $param + 65536 : $param;
                    $bytes = pack('v', $code);
                    $text .= (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
                    $bytes = '';
                    $skipChars = $state['uc'];
                    continue;
                }
                if ($skipChars > 0) {
                    $skipChars--;
                    continue;
                }
                switch ($word) {
                    case 'uc':
                        $state['uc'] = max(0, (int) $param);
                        break;
                    case 'f':
                        $flushBytes();
                        $state['codepage'] = $fontCodepages[(int) $param] ?? $defaultCodepage;
                        break;
                    case 'par':
                    case 'sect':
                    case 'page':
                        $endParagraph();
                        break;
                    case 'line':
                    case 'tab':
                        $flushBytes();
                        $text .= ' ';
                        break;
                    case 'cell':
                        $endParagraph(true);
                        break;
                    case 'row':
                        $endParagraph(false, true);
                        break;
                    case 'nestcell':
                    case 'nestrow':
                        $flushBytes();
                        $text .= ' ';
                        break;
                    case 'pard':
                        $para = ['style' => 0, 'outline' => null, 'inTable' => false, 'list' => false];
                        break;
                    case 's':
                        $para['style'] = (int) $param;
                        break;
                    case 'outlinelevel':
                        $para['outline'] = (int) $param;
                        break;
                    case 'intbl':
                        $para['inTable'] = true;
                        break;
                    case 'ls':
                    case 'ilvl':
                        $para['list'] = true;
                        break;
                    case 'emdash':
                        $flushBytes();
                        $text .= '—';
                        break;
                    case 'endash':
                        $flushBytes();
                        $text .= '–';
                        break;
                    case 'bullet':
                        $flushBytes();
                        $text .= '•';
                        break;
                    case 'lquote':
                    case 'rquote':
                        $flushBytes();
                        $text .= "'";
                        break;
                    case 'ldblquote':
                    case 'rdblquote':
                        $flushBytes();
                        $text .= '"';
                        break;
                    default:
                        if (in_array($word, self::RTF_SKIP_DESTINATIONS, true)) {
                            $state['skip'] = true;
                        }
                }
                continue;
            }
            if ($char === "\r" || $char === "\n") {
                continue;
            }
            if ($state['skip']) {
                continue;
            }
            if ($skipChars > 0) {
                $skipChars--;
                continue;
            }
            $flushBytes();
            // Plain text run: copy until the next special character.
            $run = strcspn($rtf, "\\{}\r\n", $i);
            $chunk = substr($rtf, $i, max(1, $run));
            $text .= $this->decodeCodepage($chunk, $state['codepage']);
            $i += max(1, $run) - 1;
        }
        $endParagraph();
        if ($row !== []) {
            $rows[] = $row;
        }
        if ($rows !== []) {
            $blocks[] = ['type' => 'table', 'rows' => $rows];
        }
        if ($blocks === []) {
            $warnings[] = 'RTF contains no text.';
        }

        return $blocks;
    }

    /** @return array<int, int> font number => code page */
    private function rtfFontCodepages(string $rtf): array
    {
        $group = $this->rtfGroup($rtf, '\\fonttbl');
        $map = [];
        if ($group !== null && preg_match_all('/\\\\f(\d+)[^;{}]*?\\\\fcharset(\d+)/', $group, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $codepage = self::RTF_CHARSET_CODEPAGES[(int) $match[2]] ?? null;
                if ($codepage !== null) {
                    $map[(int) $match[1]] = $codepage;
                }
            }
        }
        // \fcharset0 means "ANSI": use the document code page, not 1252, for CJK documents.
        $default = preg_match('/\\\\ansicpg(\d+)/', substr($rtf, 0, 2048), $m) === 1 ? (int) $m[1] : 1252;
        foreach ($map as $font => $codepage) {
            if ($codepage === 1252 && $default !== 1252) {
                $map[$font] = $default;
            }
        }

        return $map;
    }

    /** @return array<int, int> paragraph style number => heading level */
    private function rtfStyleLevels(string $rtf, int $codepage): array
    {
        $group = $this->rtfGroup($rtf, '\\stylesheet');
        $map = [];
        if ($group === null) {
            return $map;
        }
        if (preg_match_all('/\{(?:\\\\\*)?\\\\s(\d+)(?![a-z\d])([^{}]*?);\}/', $group, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $controls = $match[2];
                $name = (string) preg_replace_callback("/\\\\'([0-9a-f]{2})/i", static fn ($h) => chr((int) hexdec($h[1])), $controls);
                $name = (string) preg_replace('/\\\\[a-z]+-?\d*\s?/i', '', $name);
                $name = mb_strtolower(trim($this->decodeCodepage($name, $codepage)));
                if (preg_match('/\\\\outlinelevel(\d)/', $controls, $o) === 1) {
                    $map[(int) $match[1]] = min(6, (int) $o[1] + 2);
                } elseif (preg_match('/^(heading|标题)\s*(\d)$/u', $name, $h) === 1) {
                    $map[(int) $match[1]] = min(6, (int) $h[2] + 1);
                } elseif ($name === 'title' || $name === '标题') {
                    $map[(int) $match[1]] = 1;
                }
            }
        }

        return $map;
    }

    private function rtfGroup(string $rtf, string $control): ?string
    {
        $start = strpos($rtf, '{' . $control);
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $length = strlen($rtf);
        for ($i = $start; $i < $length; $i++) {
            if ($rtf[$i] === '\\') {
                $i++;
                continue;
            }
            if ($rtf[$i] === '{') {
                $depth++;
            } elseif ($rtf[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($rtf, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function decodeCodepage(string $bytes, int $codepage): string
    {
        if ($bytes === '' || preg_match('/[\x80-\xFF]/', $bytes) !== 1) {
            return $bytes;
        }
        $encoding = match ($codepage) {
            936 => 'CP936',
            950 => 'BIG-5',
            932 => 'SJIS-win',
            949 => 'UHC',
            65001 => 'UTF-8',
            default => 'Windows-' . $codepage,
        };
        $converted = @mb_convert_encoding($bytes, 'UTF-8', $encoding);
        if (!is_string($converted) || $converted === '') {
            $converted = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        return $converted;
    }

    /* -------------------------------------------------------------- helpers */

    private function inline(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000E}-\x{001F}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function u16(string $bytes, int $offset): int
    {
        return $offset >= 0 && $offset + 2 <= strlen($bytes) ? (int) unpack('v', $bytes, $offset)[1] : 0;
    }

    private function u32(string $bytes, int $offset): int
    {
        return $offset >= 0 && $offset + 4 <= strlen($bytes) ? (int) unpack('V', $bytes, $offset)[1] : 0;
    }
}
