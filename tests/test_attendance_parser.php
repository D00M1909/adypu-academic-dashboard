<?php
// Run: php tests/test_attendance_parser.php
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';

// --- Four-column rows (hand-maintained sheet / DB export shape) -------------
// Note the strength column: every value here is deliberately wrong. Strength
// comes from structure.php, never from the row, so these must be ignored.
$csv = "school,year,branch,division,strength,present,date\n" .
       "eng,2nd Year,CSE,A,7,25,2026-08-26\n" .
       "eng,2nd Year,CSE,B,7,50,2026-08-26\n" .
       "eng,2nd Year,CSE,A,7,28,2026-08-26\n" . // resubmission for CSE div A, should win
       "eng,2nd Year,ECE,A,7,20,2026-08-26\n" . // same division letter, different branch
       "mgmt,1st Year,,A,7,25,2026-08-26\n" .   // no branch structure -> branch key ''
       "eng,2nd Year,CSE,ZZ,7,99,2026-08-26\n"; // division that doesn't exist -> dropped

$rows = parse_attendance_csv($csv);
assert(count($rows) === 5, 'expected 5 parsed rows, got ' . count($rows));
assert($rows[0]['strength'] === 60, 'strength must come from structure, not the row');

$tree = aggregate_attendance($rows);
assert(count($tree['eng']['2nd Year']['CSE']) === 2, 'resubmission should collapse to 2 CSE divisions');
assert(count($tree['eng']['2nd Year']['ECE']) === 1, 'ECE div A should not merge with CSE div A');

$divA = null;
foreach ($tree['eng']['2nd Year']['CSE'] as $d) {
    if ($d['division'] === 'A') $divA = $d;
}
assert($divA !== null, 'CSE division A missing');
assert($divA['present'] === 28, 'latest submission should win, got ' . $divA['present']);

assert(count($tree['mgmt']['1st Year']['']) === 1, 'branchless school should key under empty branch');

$totals = attendance_totals($tree);
assert($totals['strength'] === 210, 'total strength wrong: ' . $totals['strength']);
assert($totals['present'] === 123, 'total present wrong: ' . $totals['present']);

// --- Flat "Class" rows (what the Google Form actually submits) --------------
$formCsv = "timestamp,class,present\n" .
           "26/08/2026 09:14:02,School of Engineering / 2nd Year / CSE / A,52\n" .
           "26/08/2026 09:15:41,School of Law / 1st Year / B,44\n" .           // branchless: 3 segments
           "26/08/2026 09:16:03,School of Nowhere / 1st Year / A,10\n" .       // unknown school -> dropped
           "26/08/2026 09:17:20,School of Engineering / 9th Year / CSE / A,5\n"; // unknown year -> dropped

$formRows = parse_attendance_csv($formCsv);
assert(count($formRows) === 2, 'expected 2 valid form rows, got ' . count($formRows));
assert($formRows[0]['school'] === 'eng', 'display name should resolve to school id');
assert($formRows[0]['branch'] === 'CSE', 'branch segment lost');
assert($formRows[0]['strength'] === 60, 'form row strength should come from structure');
assert($formRows[1]['school'] === 'law' && $formRows[1]['branch'] === '', 'branchless label mis-parsed');
assert($formRows[1]['strength'] === 60, 'law 1st Year div B strength wrong: ' . $formRows[1]['strength']);
assert($formRows[0]['date'] !== '', 'missing date column should default to today');

// --- Label round-trips ------------------------------------------------------
foreach (class_rows() as $c) {
    $label = class_label($c['school'], $c['year'], $c['branch'], $c['division']);
    $back = parse_class_label($label);
    assert($back !== null, "label did not round-trip: $label");
    assert($back['school'] === $c['school'] && $back['year'] === $c['year']
        && $back['branch'] === $c['branch'] && $back['division'] === $c['division'],
        "label round-tripped to the wrong class: $label");
}

// Every class the Form can offer must be unique, or two divisions collide on
// one dropdown entry and one of them can never be submitted.
$labels = array_map(fn($c) => class_label($c['school'], $c['year'], $c['branch'], $c['division']), class_rows());
assert(count($labels) === count(array_unique($labels)), 'duplicate class labels in the dropdown');

echo "OK\n";
