<?php
// Generates the three files to send out. They ask for different things, of
// different people, and must not be merged:
//
//   php tools/data-request.php engineering > 01-engineering-headcounts.csv
//   php tools/data-request.php structure     02-structure-request.xlsx
//   php tools/data-request.php partners      03-partners-request.xlsx
//   php tools/data-request.php roster        04-roster-request.xlsx
//   php tools/data-request.php partner-divisions "Partners Information.xlsx" 05-partner-divisions-request.xlsx
//
// Engineering's structure is real, read from the timetable DB — it needs a
// headcount against each known division. Every other school's structure is
// invented (two divisions called A and B, because the UI needed something to
// draw), so those schools get BLANK rows and state their own reality. Sending
// them the placeholders would invite them to fill numbers in beside divisions
// that do not exist.
//
// The roster file asks for the students themselves, one row each, and is the
// only request that pre-fills a row per enrolled student rather than a blank
// form: a school correcting our count by adding or deleting rows is exactly how
// we learn the real enrolment, which no other request has managed to extract.
//
// The partners file goes to whoever owns the partnerships, not to a school. We
// know the partner names and which schools they appear against; what we do
// not know is which divisions their students actually sit in, which is the one
// thing that would let the Knowledge Partner tab show attendance instead of
// inert tiles.
//
// The partner divisions file reads which programs and years each partner has
// students in from that office's own workbook, and asks only which division
// each sits in. See tools/partner-divisions.php.

require_once __DIR__ . '/../includes/structure.php';
require_once __DIR__ . '/xlsx.php';

// The same four fields are asked for by both workbooks, so they get the same
// words in both. Each request adds its own title and any extra field below.
$terms = [
    [['b', 'YEAR'], 'Year of study, e.g. "1st Year", "2nd Year".'],
    [['b', 'BRANCH'], 'Specialisation or stream, e.g. "CSE". Leave blank if the school has none.'],
    [['b', 'DIVISION'], 'The individual class or section, e.g. "A", "B".'],
    [['b', 'ENROLLED'], 'Students enrolled in the division.'],
];

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

if ($mode === 'partners') {
    $outFile = $argv[2] ?? '03-partners-request.xlsx';

    $header = ['Partner', 'School', 'Year', 'Branch (blank if none)', 'Division',
               'ENROLLED STUDENTS', 'Who marks attendance', 'Contact (name and email)', 'Comments'];

    $intro = array_merge([
        [['b', 'KNOWLEDGE PARTNERS - CLASSES AND STUDENT NUMBERS - PLEASE COMPLETE']],
        [],
        ['We know which partners work with which schools, but not which classes their students sit in.'],
        ['The Partners tab lists what we hold today: please correct anything wrong, complete one row for'],
        ['every division a partner teaches, and add a row for any partner or class we have missed.'],
        [],
    ], $terms, [
        [['b', 'WHO MARKS ATTENDANCE'], 'Partner faculty, university faculty, or both.'],
    ]);

    $rows = [array_map(fn($h) => ['b', $h], $header)];

    // One row per partner-school pair: a partner in four schools is four rows,
    // because a division belongs to exactly one school and the recipient is
    // being asked to name divisions.
    foreach (KNOWLEDGE_PARTNERS as $p) {
        foreach ($p['schools'] as $school) {
            $rows[] = [$p['name'], SCHOOLS[$school]['name'] ?? $school, '', '', '', '', '', '', ''];
        }
    }
    // Instructions on their own tab, same as the structure request: the intro
    // needs a wide second column, the table needs nine narrow ones.
    write_xlsx($outFile, [
        'Instructions' => ['cols' => [24, 95], 'rows' => $intro],
        'Partners' => ['cols' => [16, 26, 12, 22, 12, 20, 24, 30, 34], 'rows' => $rows],
    ]);
    fwrite(STDERR, "wrote $outFile (" . count(KNOWLEDGE_PARTNERS) . " partners)\n");
    exit;
}

if ($mode === 'roster') {
    $outFile = $argv[2] ?? '04-roster-request.xlsx';

    $header = ['Year', 'Branch (blank if none)', 'Division', 'ROLL NUMBER', 'STUDENT NAME'];

    $intro = array_merge([
        [['b', 'STUDENT LISTS - PLEASE COMPLETE']],
        [],
        ['Faculty currently type in a present count. We want to give them their actual class list on'],
        ['their phone, with a tick box against each student, so attendance takes one tap per absentee'],
        ['and the count can never be mistyped. To do that we need the students.'],
        [],
        ['Your tab already has one blank row for every student we believe is enrolled, with the Year,'],
        ['Branch and Division filled in. Please put a roll number and a name on each row.'],
        [],
        [['b', 'IF THE ROW COUNT IS WRONG'], 'Add or delete rows. That is the most useful thing in this file:'],
        ['', 'our enrolment figures come from an earlier request and we know some are still estimates.'],
        [],
        [['b', 'PLEASE DO NOT SORT'], 'the sheet, and please do not clear the Year, Branch or Division cells.'],
        ['', 'Every row must keep its own three values, or we cannot tell which class a student is in.'],
        [],
    ], $terms, [
        [['b', 'ROLL NUMBER'], 'Whatever your office uses as the unique student id: roll number, PRN, enrolment number.'],
        [['b', 'STUDENT NAME'], 'As it should appear to the faculty member marking attendance.'],
    ]);

    $sheets = ['Instructions' => ['cols' => [22, 95], 'rows' => $intro]];
    foreach (SCHOOLS as $id => $school) {
        $rows = [
            [['b', strtoupper($school['name'])]],
            [],
            array_map(fn($h) => ['b', $h], $header),
        ];

        // A placeholder school has no confirmed structure, so pre-filling its
        // divisions would ask it to list students against classes we invented.
        // Same reasoning as the structure request: those schools get a blank
        // form and state their own reality.
        if (is_placeholder_school($id)) {
            $rows[1] = ['We do not have your class structure yet, so this tab is deliberately blank.'];
            for ($i = 0; $i < 200; $i++) $rows[] = [];
        } else {
            foreach (class_rows() as $c) {
                if ($c['school'] !== $id) continue;
                // Repeated on every row rather than only the first of a block:
                // a sheet that comes back sorted still says which class each
                // student is in, where a forward-filled one would silently
                // reassign most of the university.
                for ($i = 0; $i < $c['strength']; $i++) {
                    $rows[] = [$c['year'], $c['branch'], $c['division'], '', ''];
                }
            }
        }

        $tab = preg_replace('/^School of /', '', $school['name']);
        $sheets[$tab] = ['cols' => [16, 30, 22, 18, 34], 'rows' => $rows];
    }

    write_xlsx($outFile, $sheets);
    $students = array_sum(array_map(
        fn($c) => is_placeholder_school($c['school']) ? 0 : $c['strength'],
        class_rows()
    ));
    fwrite(STDERR, "wrote $outFile (" . count($sheets) . " tabs, $students student rows)\n");
    exit;
}

if ($mode === 'partner-divisions') {
    require_once __DIR__ . '/partner-divisions.php';
    $in = $argv[2] ?? '';
    $outFile = $argv[3] ?? '05-partner-divisions-request.xlsx';
    if (!is_file($in)) {
        fwrite(STDERR, "usage: php tools/data-request.php partner-divisions \"Partners Information.xlsx\" [outfile.xlsx]\n");
        exit(1);
    }
    $sheets = pd_workbook(read_xlsx($in));
    write_xlsx($outFile, $sheets);
    fwrite(STDERR, "wrote $outFile (" . (count($sheets['Divisions']['rows']) - 8) . " rows)\n");
    exit;
}

if ($mode !== 'structure' && $mode !== 'structure-csv') {
    fwrite(STDERR, "usage: php tools/data-request.php engineering|structure|partners|roster [outfile.xlsx]|partner-divisions <in.xlsx> [outfile.xlsx]|structure-csv\n");
    exit(1);
}

$intro = array_merge([
    [['b', 'CLASS STRUCTURE AND STUDENT NUMBERS - PLEASE COMPLETE']],
    [],
    ['We do not yet have the real class structure for these schools, so this form is deliberately blank.'],
    ['Please add one row for every division that exists, and delete any blank rows you do not use.'],
    [],
], $terms);

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
