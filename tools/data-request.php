<?php
// Generates the two files to send to the schools. They ask for different things
// and must not be merged:
//
//   php tools/data-request.php engineering > 01-engineering-headcounts.csv
//   php tools/data-request.php structure   > 02-structure-request.csv
//
// Engineering's structure is real, read from the timetable DB — it needs a
// headcount against each known division. Every other school's structure is
// invented (two divisions called A and B, because the UI needed something to
// draw), so those schools get BLANK rows and state their own reality. Sending
// them the placeholders would invite them to fill numbers in beside divisions
// that do not exist.

require_once __DIR__ . '/../includes/structure.php';

$mode = $argv[1] ?? '';
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

if ($mode !== 'structure') {
    fwrite(STDERR, "usage: php tools/data-request.php engineering|structure\n");
    exit(1);
}

fputcsv($out, ['CLASS STRUCTURE AND STUDENT NUMBERS - PLEASE COMPLETE']);
fputcsv($out, []);
fputcsv($out, ['We do not yet have the real class structure for these schools, so this form is deliberately blank.']);
fputcsv($out, ['Please add one row for every division that exists, and delete any blank rows you do not use.']);
fputcsv($out, []);
fputcsv($out, ['YEAR', 'Whatever you call it - "1st Year", "Semester 3", etc. Use your own wording.']);
fputcsv($out, ['BRANCH', 'Specialisation or stream, e.g. "CSE". LEAVE BLANK if the year is not split into branches.']);
fputcsv($out, ['DIVISION', 'The individual class or section, e.g. "A", "B1". If a year has only one class, write one row.']);
fputcsv($out, ['ENROLLED', 'Students enrolled in THAT division - not the total for the programme or the year.']);
fputcsv($out, []);
fputcsv($out, ['EXAMPLE of a completed row - delete this line:']);
fputcsv($out, ['2nd Year', 'Corporate Law', 'A', '48', 'merged with B last semester']);
fputcsv($out, []);

foreach (SCHOOLS as $id => $school) {
    if ($id === 'eng') continue;
    fputcsv($out, []);
    fputcsv($out, [strtoupper($school['name'])]);
    fputcsv($out, ['Year', 'Branch (blank if none)', 'Division', 'ENROLLED STUDENTS', 'Comments']);
    for ($i = 0; $i < 24; $i++) {
        fputcsv($out, ['', '', '', '', '']);
    }
}
fclose($out);
