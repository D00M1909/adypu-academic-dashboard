<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_export_haazir.php
//
// The Haazir export (tools/timetable/haazir.php): every booking lands once per
// division it teaches or is reported, never dropped quietly; a lecture written
// twice becomes one slot; times leave the sheets' 12-hour clock; subject codes
// fit Haazir's pattern, never clash, and never change once given.

require_once __DIR__ . '/../tools/timetable/haazir.php';

$rows = [
    ['school' => 'eng', 'year' => '1st Year', 'branch' => 'Core', 'division' => 'F', 'strength' => 60],
    ['school' => 'eng', 'year' => '2nd Year', 'branch' => 'CSE', 'division' => 'A', 'strength' => 60],
    ['school' => 'mgmt', 'year' => '1st Year', 'branch' => 'BBA', 'division' => 'A', 'strength' => 30],
    ['school' => 'mgmt', 'year' => '1st Year', 'branch' => 'BBA', 'division' => 'B', 'strength' => 60],
    ['school' => 'hosp', 'year' => '2nd Year', 'branch' => 'BSc Hospitality and Hotel Administration', 'division' => 'A',
        'strength' => 40],
    ['school' => 'arch', 'year' => '1st Year', 'branch' => '', 'division' => 'A', 'strength' => 30],
];
$b = fn($day, $time, $code, $class, $extra = []) => $extra + ['day' => $day, 'time' => $time, 'span' => 1,
    'code' => $code, 'class' => $class, 'classes' => [$class], 'faculty' => 'Dr. Test One', 'lab' => false, 'blocked' => ''];
$t = [
    'buildings' => ['ULC 1' => ['610', '611'], 'School of Design' => ['201 (School of Design)']],
    'rooms' => [
        '610' => [
            $b('Monday', '1.30 to 2.30', 'Applied Mathematics I', 'B.Tech First Year Div F'),
            $b('Tuesday', '9.30 to 10.30', 'Data Structures', 'B.Tech CSE Sem III Div A', ['span' => 2, 'lab' => true]),
            $b('Monday', '9.30 to 10.30', 'Held', 'B.Tech CSE Sem III Div A', ['blocked' => 'SOE CSE/AIDS']),
        ],
        '611' => [
            // The same lab in a second room, with a second teacher: one slot.
            $b('Tuesday', '9.30 to 10.30', 'data  structures', 'B.Tech CSE Sem III Div A',
                ['span' => 2, 'lab' => true, 'faculty' => 'Prof. Test Two / Industry Faculty']),
            $b('Wednesday', '11.30 to 12.30', 'Accounting (online)', 'BBA Sem I'),
            $b('Thursday', '9.30 to 10.30', 'Nothing', 'B.Tech ECE'),
        ],
        '201 (School of Design)' => [$b('Friday', '3.30 to 4.30', 'Culinary Arts', 'B.Sc HHA Sem III Culinary')],
    ],
    'extra' => [['day' => 'Friday', 'time' => '4.30 to 5.30', 'span' => 1, 'code' => 'Applied Mathematics I',
        'class' => 'B.Tech First Year Div F', 'faculty' => 'Dr. Test One', 'lab' => false, 'blocked' => '', 'src' => []]],
];

// Classes: every division with a branch code; a school with none is reported.
$skipped = [];
$classes = haazir_classes($rows, $skipped);
assert(count($classes) === 5);
assert(isset($skipped['arch']));
assert($classes[2] === ['school_code' => 'MGMT', 'school_name' => 'School of Management', 'branch_code' => 'BBA',
    'branch_name' => 'BBA', 'year' => 1, 'division' => 'A']);
$codes = [];
foreach (HAAZIR_BRANCHES as $branches) foreach ($branches as $code) {
    assert(preg_match('/^[A-Z0-9&]{1,12}$/', $code) === 1, $code);
    assert(!in_array($code, $codes, true), "branch code $code twice");
    $codes[] = $code;
}

// Lectures: one per division taught; the hold is not a lecture; ECE is reported.
[$lectures, $unmatched] = haazir_lectures($t, $rows);
assert($unmatched === ['B.Tech ECE' => 1]);
$groups = array_count_values(array_column($lectures, 'group'));
assert($groups === ['CORE-F' => 2, 'CSE-A' => 2, 'BBA-A' => 1, 'BBA-B' => 1, 'BSCHHA-A' => 1], json_encode($groups));
// Every booking is a lecture or reported: 7 weekly room bookings + 1 extra, minus the hold, BBA counted twice.
assert(count($lectures) + array_sum($unmatched) === 8 - 1 + 1);

// Subjects: case and spacing merge, codes valid and unique, kept once given.
$subjects = haazir_subjects(array_column($lectures, 'subject'));
assert(count($subjects) === 4);
assert($subjects['applied mathematics i'] === ['AM I', 'Applied Mathematics I']);
assert($subjects['data structures'][0] === 'DS');
assert($subjects['accounting (online)'][0] === 'ACCOUNTING', 'a bracket is not part of the code');
foreach ($subjects as [$code]) assert(preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]{0,29}$/', $code) === 1, $code);
$again = haazir_subjects(['Applied Mathematics I', 'Applied Mechanics I'], ['applied mathematics i' => 'MATHS1']);
assert($again['applied mathematics i'][0] === 'MATHS1', 'a known code is kept');
assert($again['applied mechanics i'][0] === 'AM I');
assert(haazir_subject_code('Applied Mathematics I', ['AM I']) === 'AM I-2', 'a clash is numbered');
assert(haazir_subject_code('Industrial Automation: PLC & SCADA Lab', []) === 'IA PLC SCADA LAB');
assert(haazir_subject_code('CAD/CAM', []) === 'CAD/CAM');

// Timetable: the two-room lab is one slot with both rooms, 24-hour times, emails where known.
$noEmail = [];
$tt = haazir_timetable($lectures, $subjects, ['test one' => 'One@College.test'], $noEmail);
assert(count($tt) === 6, json_encode($tt));
$lab = array_values(array_filter($tt, fn($r) => $r['group'] === 'CSE-A'))[0];
assert($lab === ['subject_code' => 'DS', 'year' => 2, 'group' => 'CSE-A', 'weekday' => 'Tue', 'start' => '09:30',
    'end' => '11:30', 'room' => 'ULC 1 610 / ULC 1 611', 'professor_email' => 'one@college.test', 'term' => ''],
    json_encode($lab));
assert($noEmail === ['test two' => 'Prof. Test Two'], 'the second teacher has no email; a seat is not a person');
$first = array_values(array_filter($tt, fn($r) => $r['group'] === 'CORE-F'));
assert(array_column($first, 'start') === ['13:30', '16:30'], 'afternoon hours: 1.30 is 13:30');
assert($first[1]['room'] === '', 'a roomless class has no room');
$hosp = array_values(array_filter($tt, fn($r) => $r['group'] === 'BSCHHA-A'))[0];
assert($hosp['room'] === 'School of Design 201' && $hosp['year'] === 2);
$keys = array_map(fn($r) => "{$r['year']}|{$r['group']}|{$r['subject_code']}|{$r['weekday']}|{$r['start']}", $tt);
assert(count($keys) === count(array_unique($keys)), 'no slot twice (Haazir refuses the file)');

echo "OK\n";
