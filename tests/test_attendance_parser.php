<?php
// Run: php tests/test_attendance_parser.php
require_once __DIR__ . '/../includes/attendance.php';

$csv = "school,year,division,strength,present,date\n" .
       "eng,2nd Year,A,30,25,2026-08-26\n" .
       "eng,2nd Year,B,60,50,2026-08-26\n" .
       "eng,2nd Year,A,30,28,2026-08-26\n"; // resubmission for div A, should win

$rows = parse_attendance_csv($csv);
assert(count($rows) === 3, 'expected 3 parsed rows, got ' . count($rows));

$tree = aggregate_attendance($rows);
assert(count($tree['eng']['2nd Year']) === 2, 'resubmission should collapse to 2 divisions');

$divA = null;
foreach ($tree['eng']['2nd Year'] as $d) {
    if ($d['division'] === 'A') $divA = $d;
}
assert($divA !== null, 'division A missing');
assert($divA['present'] === 28, 'latest submission should win, got ' . $divA['present']);

$totals = attendance_totals($tree);
assert($totals['strength'] === 90, 'total strength wrong: ' . $totals['strength']);
assert($totals['present'] === 78, 'total present wrong: ' . $totals['present']);

echo "OK\n";
