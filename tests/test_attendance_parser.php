<?php
// Run: php tests/test_attendance_parser.php
require_once __DIR__ . '/../includes/attendance.php';

$csv = "school,year,branch,division,strength,present,date\n" .
       "eng,2nd Year,CSE,A,30,25,2026-08-26\n" .
       "eng,2nd Year,CSE,B,60,50,2026-08-26\n" .
       "eng,2nd Year,CSE,A,30,28,2026-08-26\n" . // resubmission for CSE div A, should win
       "eng,2nd Year,ECE,A,30,20,2026-08-26\n" . // same division letter, different branch
       "mgmt,1st Year,,A,40,35,2026-08-26\n";    // no branch structure -> branch key ''

$rows = parse_attendance_csv($csv);
assert(count($rows) === 5, 'expected 5 parsed rows, got ' . count($rows));

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
assert($totals['strength'] === 160, 'total strength wrong: ' . $totals['strength']);
assert($totals['present'] === 133, 'total present wrong: ' . $totals['present']);

echo "OK\n";
