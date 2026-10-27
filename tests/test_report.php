<?php
// The report's order and its two faculty columns: run with
// php -d zend.assertions=1 -d assert.exception=1 tests/test_report.php
require_once __DIR__ . '/../includes/report.php';

$rows = [
    ['school' => 'law', 'year' => '2nd Year', 'branch' => 'BA LLB', 'division' => 'A', 'strength' => 60],
    ['school' => 'eng', 'year' => '2nd Year', 'branch' => 'CSE', 'division' => 'A', 'strength' => 60],
    ['school' => 'law', 'year' => '1st Year', 'branch' => 'BA LLB', 'division' => 'A', 'strength' => 60],
    ['school' => 'eng', 'year' => '10th Year', 'branch' => 'X', 'division' => 'A', 'strength' => 60],
    ['school' => 'eng', 'year' => '1st Year', 'branch' => 'Core', 'division' => 'B', 'strength' => 60],
    ['school' => 'eng', 'year' => '1st Year', 'branch' => 'Core', 'division' => 'A', 'strength' => 60],
];
$days = ['2026-10-12' => [
    'eng|1st Year|Core|A' => ['09:30' => 50, '11:30' => 48],
    'law|2nd Year|BA LLB|A' => ['10:30' => 40],
]];
$faculty = ['2026-10-12' => ['eng|1st Year|Core|A' => ['09:30' => 'Dr. Rao', '11:30' => 'Rao']]];
$tree = aggregate_days($days, '2026-10-12', '2026-10-12', $rows);
$due = ['eng|1st Year|Core|B' => [['name' => 'Prof. Shah', 'time' => 570, 'code' => 'M1']]];
$out = report_rows($rows, $tree, $days, $faculty, '2026-10-12', '2026-10-12', $due);

// Year first, then the school in SCHOOLS order, then the structure's own
// order, so Core B (listed first) stays ahead of Core A.
assert(array_column($out, 'key') === [
    'eng|1st Year|Core|B', 'eng|1st Year|Core|A', 'law|1st Year|BA LLB|A',
    'eng|2nd Year|CSE|A', 'law|2nd Year|BA LLB|A', 'eng|10th Year|X|A',
]);
$by = array_column($out, null, 'key');
assert($by['eng|1st Year|Core|A']['reported'] && $by['eng|1st Year|Core|A']['present'] === 48);
// One person filing twice, spelled two ways, is named once.
assert($by['eng|1st Year|Core|A']['filed_by'] === ['Dr. Rao']);
assert(count($by['eng|1st Year|Core|A']['readings']) === 2);
assert(!$by['eng|1st Year|Core|B']['reported'] && $by['eng|1st Year|Core|B']['due'][0]['name'] === 'Prof. Shah');
assert($by['law|2nd Year|BA LLB|A']['filed_by'] === []);

// The timetable side: who teaches a class on the days in range.
$t = [
    'days' => ['Monday', 'Tuesday'],
    'rooms' => ['101' => [
        ['day' => 'Monday', 'time' => '11.30 to 12.30', 'span' => 1, 'code' => 'PHY', 'class' => 'B.Tech First Year Div A', 'classes' => ['B.Tech First Year Div A'], 'faculty' => 'Dr. Iyer / Prof. Shah', 'blocked' => ''],
        ['day' => 'Monday', 'time' => '9.30 to 10.30', 'span' => 1, 'code' => 'M1', 'class' => 'B.Tech First Year Div A', 'classes' => ['B.Tech First Year Div A'], 'faculty' => 'Prof. Shah', 'blocked' => ''],
        ['day' => 'Tuesday', 'time' => '9.30 to 10.30', 'span' => 1, 'code' => 'CH', 'class' => 'B.Tech First Year Div A', 'classes' => ['B.Tech First Year Div A'], 'faculty' => 'Ms. Nair', 'blocked' => ''],
        ['day' => 'Monday', 'time' => '2.30 to 3.30', 'span' => 1, 'code' => '', 'class' => 'B.Tech First Year Div A', 'classes' => ['B.Tech First Year Div A'], 'faculty' => 'Industry Faculty', 'blocked' => ''],
    ]],
    'calendars' => [],
];
$map = report_due_faculty($t, $rows, '2026-10-12', '2026-10-12');   // a Monday
// Earliest lecture first, each person once, the Tuesday teacher left out,
// and "Industry Faculty" is a seat, not a person.
assert(array_column($map['eng|1st Year|Core|A'], 'name') === ['Prof. Shah', 'Dr. Iyer']);
assert($map['eng|1st Year|Core|A'][0]['time'] === 570);
$week = report_due_faculty($t, $rows, '2026-10-12', '2026-10-13');
assert(array_column($week['eng|1st Year|Core|A'], 'name') === ['Prof. Shah', 'Ms. Nair', 'Dr. Iyer']);

echo "OK\n";
