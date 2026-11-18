<?php
// A spreadsheet with tabs, without a library. An .xlsx is a zip of XML parts,
// and the handful below is everything Excel needs: no sharedStrings (cells
// carry inline strings), no theme, one bold font, one yellow fill.
//
// write_xlsx($path, ['Tab name' => ['cols' => [width, ...], 'rows' => [row, ...]]])
// A row is a list of cell values; a cell is a string, or [style, 'string'] where
// style is 'b' for bold or 'y' for a yellow cell someone still has to fill in,
// which is written even when empty.
//
// read_xlsx($path) returns every tab as [row => [column => value]], both 0-based.
//
// ponytail: inline strings, so a huge sheet repeats every string. Switch to a
// sharedStrings table if these ever stop being 25-row forms.

require_once __DIR__ . '/../includes/xlsx-read.php';
require_once __DIR__ . '/../includes/xlsx-write.php';

function read_xlsx_merges(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("cannot read $path");
    }
    $part = fn(string $name) => simplexml_load_string((string)$zip->getFromName($name));
    $targets = [];
    foreach ($part('xl/_rels/workbook.xml.rels')->Relationship as $rel) {
        $targets[(string)$rel['Id']] = preg_replace('#^/?(xl/)?#', '', (string)$rel['Target']);
    }
    $book = [];
    foreach ($part('xl/workbook.xml')->sheets->sheet as $sheet) {
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $ranges = [];
        foreach ($part('xl/' . $targets[$rid])->mergeCells->mergeCell ?? [] as $m) {
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string)$m['ref'], $x)) continue;
            $ranges[] = [(int)$x[2] - 1, xlsx_col_index($x[1]), (int)$x[4] - 1, xlsx_col_index($x[3])];
        }
        $book[(string)$sheet['name']] = $ranges;
    }
    $zip->close();
    return $book;
}

