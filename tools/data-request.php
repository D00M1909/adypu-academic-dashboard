<?php
// Generates the two files to send to the schools. They ask for different things
// and must not be merged:
//
//   php tools/data-request.php engineering > 01-engineering-headcounts.csv
//   php tools/data-request.php structure     02-structure-request.xlsx
//
// Engineering's structure is real, read from the timetable DB — it needs a
// headcount against each known division. Every other school's structure is
// invented (two divisions called A and B, because the UI needed something to
// draw), so those schools get BLANK rows and state their own reality. Sending
// them the placeholders would invite them to fill numbers in beside divisions
// that do not exist.

require_once __DIR__ . '/../includes/structure.php';
require_once __DIR__ . '/xlsx.php';

$mode = $argv[1] ?? '';
$outFile = $argv[2] ?? '02-structure-request.xlsx';
$out = fopen('php://output', 'w');

if ($mode === 'engineering') {
    fputcsv($out, ['SCHOOL OF ENGINEERING - CONFIRM STUDENT NUMBERS']);
    fputcsv($out, ['These divisions come from the university timetable system, so the list itself should be correct.']);
    fputcsv($out, ['Please tell us if any division is missing, has closed, or is named differently.']);
    fputcsv($out, ['The number we need is students ENROLLED in that division - the total the attendance is out of.']);
    fputcsv($out, ['The "60" against every row is the timetable system default, not a real count. Please replace all of them.']);
    fputcsv($out, []);
    fputcsv($out, ['Year', 'Branch', 'Division', 'Number we are using now', 'ENROLLED STUDENTS (please fill in)', 'Comments']);

    foreach (class_rows() as $c) {
        if ($c['school'] !== 'eng') continue;
        fputcsv($out, [$c['year'], $c['branch'], $c['division'], $c['strength'], '', '']);
    }
    fclose($out);
    exit;
}

if ($mode !== 'structure' && $mode !== 'structure-csv') {
    fwrite(STDERR, "usage: php tools/data-request.php engineering|structure [outfile.xlsx]|structure-csv\n");
    exit(1);
}

$intro = [
    [['b', 'CLASS STRUCTURE AND STUDENT NUMBERS - PLEASE COMPLETE']],
    [],
    ['We do not yet have the real class structure for these schools, so this form is deliberately blank.'],
    ['Please add one row for every division that exists, and delete any blank rows you do not use.'],
    [],
    [['b', 'YEAR'], 'Year of study, e.g. "1st Year", "2nd Year".'],
    [['b', 'BRANCH'], 'Specialisation or stream, e.g. "CSE".'],
    [['b', 'DIVISION'], 'The individual class or section, e.g. "A", "B".'],
    [['b', 'ENROLLED'], 'Students enrolled in the division.'],
];

$header = ['Year', 'Branch (blank if none)', 'Division', 'ENROLLED STUDENTS', 'Comments'];
$blankRows = 24;

// One tab per school. A single CSV cannot hold tabs, so the structure request
// is a real workbook; the CSV path stays for anyone who wants it flat.
if ($mode === 'structure') {
    $sheets = ['Instructions' => ['cols' => [12, 95], 'rows' => $intro]];
    foreach (SCHOOLS as $id => $school) {
        if ($id === 'eng') continue;
        $rows = [
            [['b', strtoupper($school['name'])]],
            [],
            array_map(fn($h) => ['b', $h], $header),
        ];
        for ($i = 0; $i < $blankRows; $i++) $rows[] = [];
        // "School of Management" -> "Management": the tab is already in context.
        $tab = preg_replace('/^School of /', '', $school['name']);
        $sheets[$tab] = ['cols' => [16, 26, 12, 20, 34], 'rows' => $rows];
    }
    write_xlsx($outFile, $sheets);
    fwrite(STDERR, "wrote $outFile (" . count($sheets) . " tabs)\n");
    exit;
}

// structure-csv: the same request, flattened, every school stacked in one sheet.
foreach ($intro as $row) {
    fputcsv($out, array_map(fn($c) => is_array($c) ? $c[1] : $c, $row));
}

foreach (SCHOOLS as $id => $school) {
    if ($id === 'eng') continue;
    fputcsv($out, []);
    fputcsv($out, [strtoupper($school['name'])]);
    fputcsv($out, $header);
    for ($i = 0; $i < $blankRows; $i++) {
        fputcsv($out, ['', '', '', '', '']);
    }
}
fclose($out);
