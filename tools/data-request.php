<?php
// Generates the headcount request to send to the schools, as a CSV they can
// open in Excel and fill in.
//
//   php tools/data-request.php > data-request.csv
//
// Two different asks are mixed in here, and the Status column says which:
//   - Engineering's structure is confirmed (read from the timetable DB), so it
//     only needs a real headcount per division.
//   - Every other school's structure is a PLACEHOLDER invented to make the UI
//     work. Those schools must confirm their real years, branches and divisions
//     first — the rows below are a guess and are probably wrong.

require_once __DIR__ . '/../includes/structure.php';

$out = fopen('php://output', 'w');
fputcsv($out, [
    'School', 'Year', 'Branch', 'Division',
    'Current number used', 'Where that number came from', 'Status',
    'CONFIRMED HEADCOUNT (fill in)', 'Notes (fill in)',
]);

foreach (class_rows() as $c) {
    $confirmed = $c['school'] === 'eng';
    fputcsv($out, [
        SCHOOLS[$c['school']]['name'],
        $c['year'],
        $c['branch'] !== '' ? $c['branch'] : '(no branch)',
        $c['division'],
        $c['strength'],
        $confirmed
            ? 'Timetable DB scheduling default - NOT a real headcount'
            : 'Invented placeholder - NOT a real headcount',
        $confirmed
            ? 'Structure confirmed. Need headcount only.'
            : 'Structure UNCONFIRMED. Confirm years/branches/divisions AND headcount.',
        '', '',
    ]);
}
fclose($out);
