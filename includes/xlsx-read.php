<?php
// Reading an .xlsx without a library: every tab's cells as strings. Here
// rather than in tools/ because the Exams tab reads uploaded schedules on the
// server, and tools/ is never uploaded. tools/xlsx.php writes them.

function xlsx_col_index(string $letters): int {
    $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n - 1;
}

// Every tab's cell values as trimmed strings, blanks left out. A formula cell
// reads as the result Excel last saved, and a number as Excel stored it
// ("60.0", "9.860074278E9"): what it meant is the caller's business.
function read_xlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("cannot read $path");
    }
    $part = fn(string $name) => simplexml_load_string((string)$zip->getFromName($name));
    // Rich text splits one string into runs, each with its own <t>.
    $text = fn(SimpleXMLElement $e) => implode('', array_map('strval', $e->xpath('.//*[local-name()="t"]')));

    $strings = [];
    if ($zip->locateName('xl/sharedStrings.xml') !== false) {
        foreach ($part('xl/sharedStrings.xml')->si as $si) $strings[] = $text($si);
    }
    $targets = [];
    foreach ($part('xl/_rels/workbook.xml.rels')->Relationship as $rel) {
        $targets[(string)$rel['Id']] = preg_replace('#^/?(xl/)?#', '', (string)$rel['Target']);
    }

    $book = [];
    foreach ($part('xl/workbook.xml')->sheets->sheet as $sheet) {
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $grid = [];
        foreach ($part('xl/' . $targets[$rid])->sheetData->row as $row) {
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $ref);
                $value = trim(match ((string)$c['t']) {
                    's' => $strings[(int)$c->v] ?? '',
                    'inlineStr' => $text($c),
                    default => (string)$c->v,
                });
                if ($value !== '') $grid[(int)$ref[2] - 1][xlsx_col_index($ref[1])] = $value;
            }
        }
        $book[(string)$sheet['name']] = $grid;
    }
    $zip->close();
    return $book;
}
