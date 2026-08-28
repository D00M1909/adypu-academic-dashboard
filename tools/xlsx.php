<?php
// A spreadsheet with tabs, without a library. An .xlsx is a zip of XML parts,
// and the handful below is everything Excel needs: no sharedStrings (cells
// carry inline strings), no theme, one bold font.
//
// write_xlsx($path, ['Tab name' => ['cols' => [width, ...], 'rows' => [row, ...]]])
// A row is a list of cell values; a cell is a string, or ['b', 'string'] for bold.
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

    // Two cell formats: 0 plain, 1 bold. Excel rejects a fills list without
    // the gray125 entry at index 1, hence the unused second fill.
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
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
            $bold = is_array($cell);
            $value = $bold ? $cell[1] : $cell;
            if ($value === '' || $value === null) continue;
            $ref = xlsx_col($c) . ($r + 1);
            $style = $bold ? ' s="1"' : '';
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

// Excel: 31 chars max, and []:*?/\ are illegal in a tab name.
function xlsx_tab_name(string $name): string {
    return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '-', $name), 0, 31);
}

function xlsx_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
