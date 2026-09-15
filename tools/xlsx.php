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

function write_xlsx(string $path, array $sheets): void {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("cannot write $path");
    }

    $n = count($sheets);
    $types = $rels = $tabs = '';
    $i = 0;
    foreach ($sheets as $name => $sheet) {
        $i++;
        $types .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $rels  .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $tabs  .= '<sheet name="' . xlsx_esc(xlsx_tab_name($name)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $zip->addFromString("xl/worksheets/sheet$i.xml", xlsx_sheet($sheet));
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $types . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $tabs . '</sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    // Three cell formats: 0 plain, 1 bold, 2 yellow. Excel rejects a fills list
    // without the gray125 entry at index 1, hence the unused second fill.
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEB9C"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="0" xfId="0" applyFill="1"/></cellXfs>'
        . '</styleSheet>');

    // close() is where the write actually happens; it fails if the file is
    // open in Excel, and returns false rather than throwing.
    if (!$zip->close()) {
        throw new RuntimeException("cannot save $path - is it open in Excel?");
    }
}

function xlsx_sheet(array $sheet): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    if (!empty($sheet['cols'])) {
        $xml .= '<cols>';
        foreach ($sheet['cols'] as $i => $w) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';
    foreach ($sheet['rows'] as $r => $row) {
        $cells = '';
        foreach ($row as $c => $cell) {
            [$kind, $value] = is_array($cell) ? $cell : ['', $cell];
            $style = ['' => '', 'b' => ' s="1"', 'y' => ' s="2"'][$kind];
            $ref = xlsx_col($c) . ($r + 1);
            if ($value === '' || $value === null) {
                if ($style !== '') $cells .= '<c r="' . $ref . '"' . $style . '/>';
                continue;
            }
            $cells .= is_numeric($value)
                ? '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>'
                : '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string)$value) . '</t></is></c>';
        }
        if ($cells !== '') $xml .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function xlsx_col(int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

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

// Excel: 31 chars max, and []:*?/\ are illegal in a tab name.
function xlsx_tab_name(string $name): string {
    return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $name), 0, 31);
}

function xlsx_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
