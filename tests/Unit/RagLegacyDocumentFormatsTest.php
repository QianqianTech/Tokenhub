<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/RagSqliteFixture.php';

/*
 * RAG document formats beyond DOCX/PPTX/XLSX: Word 97-2003 (.doc / WPS .wps),
 * PowerPoint 97-2003 (.ppt / WPS .dps), RTF (including GBK \'hh bytes),
 * OpenDocument (.odt / .odp / .ods) and WPS spreadsheets (.et). Every format
 * must keep headings, tables and list items so the p2 chunker can split by
 * section, and broken or encrypted files must fail with a 422, not a 500.
 *
 * Fixtures: tests/fixtures/rag-documents/*.gz were saved by LibreOffice from
 * one source document ("差旅管理办法": title, two chapters, a 3x3 table, a list).
 */

use TokenHub\Services\RagDocumentParserService;

$dir = sys_get_temp_dir() . '/rag-legacy-' . bin2hex(random_bytes(4));
mkdir($dir);
$fixtures = __DIR__ . '/../fixtures/rag-documents';
$parser = new RagDocumentParserService(static fn (): ?string => null, [$dir]);
$write = static function (string $name, string $bytes) use ($dir): string {
    file_put_contents($dir . '/' . $name, $bytes);
    return $dir . '/' . $name;
};
$parse = static fn (string $path) => $parser->parse($path, basename($path));
$expectedTable = "| 职级 | 一线城市 | 其他城市 |\n| --- | --- | --- |\n| P5及以下 | 500 | 350 |\n| P6及以上 | 800 | 500 |";

// ------------------------------------------------------------ format detection
foreach (['a.doc' => 'doc', 'a.WPS' => 'wps', 'a.rtf' => 'rtf', 'a.odt' => 'odt', 'a.ppt' => 'ppt', 'a.dps' => 'dps', 'a.odp' => 'odp', 'a.et' => 'et', 'a.ods' => 'ods'] as $name => $format) {
    assert_same($format, RagDocumentParserService::formatFor($name), $name . ' is a supported format.');
}
assert_same('doc', RagDocumentParserService::formatFor('upload.bin', 'application/msword'), 'MIME type maps .doc.');
assert_same('rtf', RagDocumentParserService::formatFor('upload.bin', 'text/rtf'), 'MIME type maps RTF.');
assert_same('ppt', RagDocumentParserService::formatFor('upload.bin', 'application/vnd.ms-powerpoint'), 'MIME type maps .ppt.');
assert_true(!in_array('doc', RagDocumentParserService::SPREADSHEET_FORMATS, true) && in_array('et', RagDocumentParserService::SPREADSHEET_FORMATS, true), 'Only spreadsheets use the table templates.');

// ------------------------------------------------------------ Word 97-2003 / WPS
$docBytes = (string) gzdecode((string) file_get_contents($fixtures . '/travel.doc.gz'));
foreach (['travel.doc', 'travel.wps'] as $name) {
    $doc = $parse($write($name, $docBytes));
    assert_same('php:' . pathinfo($name, PATHINFO_EXTENSION), $doc['parser'], $name . ' is parsed in PHP.');
    assert_same('差旅管理办法', $doc['title'], $name . ': title comes from the Title style.');
    assert_true(str_contains($doc['markdown'], "## 第一章 总则\n"), $name . ': Heading 1 style becomes ##.');
    assert_true(str_contains($doc['markdown'], "### 2.1 一线城市\n"), $name . ': Heading 2 style becomes ###.');
    assert_true(str_contains($doc['markdown'], $expectedTable), $name . ': table rows and cells survive.');
    assert_true(str_contains($doc['markdown'], "- 出差人员须提前在系统中提交申请。\n- 报销须在返回后 15 日内完成。"), $name . ': list items stay list items.');
    assert_same([], $doc['warnings'], $name . ': no warnings.');
}

// A ".doc" that is really RTF (a common export) is read as RTF.
$rtfAsDoc = $parse($write('export.doc', '{\rtf1\ansi\ansicpg936{\fonttbl{\f0\fcharset134 SimSun;}}\f0 ' . "\\'d6\\'d0\\'ce\\'c4" . '\par}'));
assert_true(str_contains($rtfAsDoc['markdown'], '中文'), 'RTF content saved with a .doc extension is detected.');

// Password protected: fEncrypted set in the FIB.
$encrypted = $docBytes;
$fib = strpos($encrypted, "\xEC\xA5");
assert_true($fib !== false, 'Fixture has a FIB.');
$encrypted[$fib + 11] = chr(ord($encrypted[$fib + 11]) | 0x01);
rag_expect_abort(static fn () => $parse($write('locked.doc', $encrypted)), 'RAG_DOCUMENT_ENCRYPTED', 'Encrypted .doc files are refused with a clear code.');
rag_expect_abort(static fn () => $parse($write('broken.doc', substr($docBytes, 0, 700))), 'RAG_DOCUMENT_UNREADABLE', 'A truncated .doc is a 422, not a crash.');
rag_expect_abort(static fn () => $parse($write('fake.doc', "%PDF-1.4 not a word file")), 'RAG_DOCUMENT_UNREADABLE', 'A mislabelled file is a 422.');

// ------------------------------------------------------------ PowerPoint 97-2003 / WPS
$pptBytes = (string) gzdecode((string) file_get_contents($fixtures . '/train.ppt.gz'));
foreach (['train.ppt', 'train.dps'] as $name) {
    $ppt = $parse($write($name, $pptBytes));
    assert_same("## 第 1 页：季度培训\n\n- 安全生产第一\n", $ppt['markdown'], $name . ': slide title and body are extracted in order, master placeholders are not.');
}
rag_expect_abort(static fn () => $parse($write('broken.ppt', substr($pptBytes, 0, 600))), 'RAG_DOCUMENT_UNREADABLE', 'A truncated .ppt is a 422.');

// ------------------------------------------------------------ RTF
$gbk = static fn (string $text): string => implode('', array_map(static fn (string $byte): string => "\\'" . bin2hex($byte), str_split((string) mb_convert_encoding($text, 'CP936', 'UTF-8'))));
$rtf = '{\rtf1\ansi\ansicpg936\deff0{\fonttbl{\f0\fnil\fcharset134 ' . $gbk('宋体') . ';}{\f1\fswiss\fcharset0 Arial;}}'
    . '{\stylesheet{\s0 Normal;}{\s1\outlinelevel0 heading 1;}{\s2\sbasedon0 heading 2;}}'
    . '{\*\generator Msftedit 5.41;}\viewkind4\uc1'
    . '\pard\s1\f0 ' . $gbk('第一章 采购管理') . '\par'
    . '\pard\s2 ' . $gbk('1.1 范围') . '\par'
    . '\pard ' . $gbk('本制度适用于') . '{\field{\*\fldinst HYPERLINK "http://example.com"}{\fldrslt ' . $gbk('集团') . '}}' . $gbk('全体员工。') . '\par'
    . '\pard\f1 Unicode: ' . chr(92) . 'u20013?' . chr(92) . 'u25991?\par'
    . '{\header \pard ' . $gbk('页眉不进入正文') . '\par}'
    . '\trowd\cellx2000\cellx4000\pard\intbl ' . $gbk('项目') . '\cell ' . $gbk('金额') . '\cell\row'
    . '\trowd\cellx2000\cellx4000\pard\intbl A\cell 100\cell\row'
    . '\pard ' . $gbk('结束') . '\par}';
$parsed = $parse($write('policy.rtf', $rtf));
assert_same('policy', $parsed['title'], 'Without a Title style the RTF title is the file name.');
assert_true(str_contains($parsed['markdown'], "## 第一章 采购管理\n\n### 1.1 范围\n"), 'RTF headings come from \outlinelevel and "heading N" styles.');
assert_true(str_contains($parsed['markdown'], '本制度适用于集团全体员工。'), 'GBK bytes decode and the field result is kept without its instruction.');
assert_true(str_contains($parsed['markdown'], 'Unicode: 中文'), '\uN characters decode and their fallback characters are skipped.');
assert_true(str_contains($parsed['markdown'], "| 项目 | 金额 |\n| --- | --- |\n| A | 100 |"), 'RTF table rows become a Markdown table.');
assert_true(!str_contains($parsed['markdown'], '页眉'), 'Header destinations are skipped.');
rag_expect_abort(static fn () => $parse($write('bad.rtf', 'plain text, not rtf')), 'RAG_DOCUMENT_UNREADABLE', 'A non-RTF .rtf is a 422.');

// ------------------------------------------------------------ OpenDocument
$odf = static function (string $name, string $mime, string $body) use ($write): string {
    $path = $write($name, '');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('mimetype', $mime);
    $zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-content'
        . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
        . ' xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0">'
        . '<office:automatic-styles><style:style style:name="P1" style:parent-style-name="Title"/></office:automatic-styles>'
        . '<office:body>' . $body . '</office:body></office:document-content>');
    $zip->close();
    return $path;
};
$odt = $parse($odf('manual.odt', 'application/vnd.oasis.opendocument.text', '<office:text>'
    . '<text:p text:style-name="P1">设备手册</text:p>'
    . '<text:h text:outline-level="1">第一章 总则</text:h><text:p>正文<text:s text:c="2"/>内容<text:note><text:note-body><text:p>脚注</text:p></text:note-body></text:note></text:p>'
    . '<text:list><text:list-item><text:p>条目一</text:p></text:list-item><text:list-item><text:p>条目二</text:p></text:list-item></text:list>'
    . '<table:table><table:table-row><table:table-cell><text:p>参数</text:p></table:table-cell><table:table-cell><text:p>值</text:p></table:table-cell></table:table-row>'
    . '<table:table-row><table:table-cell><text:p>温度</text:p></table:table-cell><table:table-cell><text:p>80</text:p></table:table-cell></table:table-row></table:table>'
    . '</office:text>'));
assert_same('设备手册', $odt['title'], 'ODT title comes from the Title paragraph style.');
assert_true(str_contains($odt['markdown'], "## 第一章 总则\n\n正文 内容\n"), 'ODT headings and spacing elements are kept; footnote bodies are dropped.');
assert_true(str_contains($odt['markdown'], "- 条目一\n- 条目二"), 'ODT lists become list items.');
assert_true(str_contains($odt['markdown'], "| 参数 | 值 |\n| --- | --- |\n| 温度 | 80 |"), 'ODT tables become Markdown tables.');

$odp = $parse($odf('deck.odp', 'application/vnd.oasis.opendocument.presentation', '<office:presentation>'
    . '<draw:page><draw:frame presentation:class="title"><draw:text-box><text:p>季度培训</text:p></draw:text-box></draw:frame>'
    . '<draw:custom-shape><text:p>安全生产第一</text:p></draw:custom-shape>'
    . '<presentation:notes><draw:frame><draw:text-box><text:p>讲者备注</text:p></draw:text-box></draw:frame></presentation:notes></draw:page>'
    . '</office:presentation>'));
assert_same("## 第 1 页：季度培训\n\n- 安全生产第一\n\n备注：讲者备注\n", $odp['markdown'], 'ODP slides keep title, shape text and notes.');

// ------------------------------------------------------------ cleanup
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
echo "OK\n";
