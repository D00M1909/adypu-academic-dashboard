<?php
// Run: php tests/test_timetable.php
//
// The room timetable (includes/timetable.php): a wrong file is refused with a
// reason, a right one is rebuilt field by field, written behind the guard line
// and read back, and the grid gives a two-hour lab two rows without ever
// painting over a break or a second booking.

$dir = sys_get_temp_dir() . '/adypu-test-tt-' . getmypid();
define('TIMETABLE_FILE', $dir . '/timetable/rooms.php');
require_once __DIR__ . '/../includes/timetable.php';

register_shutdown_function(function () use ($dir) {
    @unlink(TIMETABLE_FILE);
    @rmdir(dirname(TIMETABLE_FILE));
    @rmdir($dir);
});

$e = fn($day, $time, $extra = []) => $extra + ['day' => $day, 'time' => $time, 'span' => 1, 'code' => 'OS',
    'class' => 'SY CSE A', 'faculty' => 'Dr. Test', 'lab' => false, 'blocked' => ''];
$export = [
    'format' => 'adypu-room-timetable', 'version' => 1, 'exported_at' => '2026-10-09 17:30', 'source' => 'test.xls',
    'days' => ['Monday', 'Tuesday'],
    'slots' => [
        ['time' => '09.30 to 10.30', 'break' => ''],
        ['time' => '10.30 to 11.30', 'break' => ''],
        ['time' => '11.30 to 12.30', 'break' => ''],
        ['time' => '12.30 to 1.15', 'break' => 'LUNCH BREAK'],
        ['time' => '1.15 to 2.15', 'break' => ''],
    ],
    'rooms' => [
        '102' => [
            $e('Monday', '09.30 to 10.30', ['span' => 2, 'lab' => true]),
            $e('Monday', '11.30 to 12.30', ['span' => 2, 'lab' => true]),     // runs into lunch
            $e('Tuesday', '09.30 to 10.30', ['span' => 3]),
            $e('Tuesday', '10.30 to 11.30', ['code' => 'CN']),                 // inside the one above
            $e('Saturday', '09.30 to 10.30'),                                  // not a day we draw
            $e('Monday', '8.30 to 9.30'),                                      // not a slot we draw
        ],
        '001' => [$e('Tuesday', '1.15 to 2.15', ['blocked' => 'BBA Seminar', 'code' => '', 'class' => ''])],
        '2' => [],
    ],
];

// --- Refusals ---------------------------------------------------------------
assert(is_string(timetable_parse('not json')));
assert(is_string(timetable_parse(json_encode(['format' => 'something-else']))));
assert(is_string(timetable_parse(json_encode(['version' => 2] + $export))), 'a newer format is refused, not half read');
assert(is_string(timetable_parse(json_encode(['rooms' => []] + $export))));
assert(is_string(timetable_parse(json_encode(['slots' => []] + $export))));

// --- A good file ------------------------------------------------------------
$t = timetable_parse(json_encode($export));
assert(is_array($t), 'good file: ' . (is_string($t) ? $t : ''));
assert(array_map('strval', array_keys($t['rooms'])) === ['001', '2', '102'], 'rooms in natural order');
assert(count($t['rooms']['102']) === 4, 'entries off the grid are dropped');
assert($t['source'] === 'test.xls' && $t['exported_at'] === '2026-10-09 17:30');

assert(timetable_read() === [], 'no file yet reads as no timetable');
assert(timetable_write($t), 'creates timetable/ when it is missing');
assert(str_starts_with(file_get_contents(TIMETABLE_FILE), STORE_GUARD), 'faculty names stay behind the guard');
assert(timetable_read() === $t, 'reads back exactly what was written');
assert(timetable_read()['rooms']['001'][0]['blocked'] === 'BBA Seminar');

// --- ULC grouping ------------------------------------------------------------
assert($t['buildings'] === ['All rooms' => ['001', '2', '102']], 'no buildings: one group, nothing lost');
$b = timetable_parse(json_encode(['buildings' => ['ULC 1' => ['102', '001', 'gone'], 'Online' => [], 'Twice' => ['001']]] + $export));
assert($b['buildings'] === ['ULC 1' => ['001', '102'], 'Online' => [], 'Twice' => [], 'Other rooms' => ['2']],
    'listed rooms keep room order, unknown and duplicate rooms drop, an empty ULC stays, the rest stay reachable: ' . json_encode($b['buildings']));

// --- A date picks its weekday -------------------------------------------------
$d = fn($s) => new DateTimeImmutable($s);
assert(timetable_day($t, $d('2026-10-12')) === 'Monday');
assert(timetable_day($t, $d('2026-10-13')) === 'Tuesday');
assert(timetable_day($t, $d('2026-10-14')) === '', 'a day the timetable does not teach');
assert(timetable_day($t, $d('2026-10-18')) === '', 'Sunday');

// --- The grid ---------------------------------------------------------------
$g = timetable_grid($t, '102');
assert($g['cells']['09.30 to 10.30']['Monday']['rows'] === 2, 'two-hour lab takes two rows');
assert(!empty($g['covered']['10.30 to 11.30']['Monday']));
assert($g['cells']['11.30 to 12.30']['Monday']['rows'] === 1, 'a span stops at lunch');
assert(empty($g['covered']['12.30 to 1.15']));
assert($g['cells']['09.30 to 10.30']['Tuesday']['rows'] === 1, 'a span stops at another booking');
assert($g['cells']['10.30 to 11.30']['Tuesday']['entries'][0]['code'] === 'CN', 'the second booking still shows');

[$used, $total] = timetable_usage($t, $g);
assert($total === 8 && $used === 5, "usage $used of $total");

// The day view is the same grid over one day.
$mon = ['days' => ['Monday']] + $t;
$g = timetable_grid($mon, '102');
assert(!isset($g['cells']['09.30 to 10.30']['Tuesday']), 'one day shows only that day');
assert($g['cells']['09.30 to 10.30']['Monday']['rows'] === 2);
[$used, $total] = timetable_usage($mon, $g);
assert($total === 4 && $used === 3, "day usage $used of $total");

// A room draws only the rows it uses, breaks between them kept.
assert(array_column(timetable_room_slots($t, '001'), 'time') === ['1.15 to 2.15'], 'one booking, one row');
assert(array_column(timetable_room_slots($t, '102'), 'time') === ['09.30 to 10.30', '10.30 to 11.30', '11.30 to 12.30'],
    'nothing after the last booking, and the rows a span covers are kept');
$withLunch = timetable_room_slots(['rooms' => ['x' => [['time' => '09.30 to 10.30'], ['time' => '1.15 to 2.15']]]] + $t, 'x');
assert(array_column($withLunch, 'time') === ['09.30 to 10.30', '12.30 to 1.15', '1.15 to 2.15'], 'a break between two bookings stays');
assert(timetable_room_slots($t, '2') === []);
$lab = timetable_room_slots(['rooms' => ['x' => [['time' => '09.30 to 10.30', 'span' => 2]]]] + $t, 'x');
assert(array_column($lab, 'time') === ['09.30 to 10.30', '10.30 to 11.30'], 'a two-hour lab keeps its second row');

// Two schools' clocks in one list: a two-hour lab from 1.30 runs into the
// 2.30 row, never into another school's 1.30 to 3.30 or 3.00 to 4.00.
$mixed = ['days' => ['Monday'], 'slots' => array_map(fn($x) => ['time' => $x, 'break' => ''],
    ['1.30 to 2.30', '1.30 to 3.30', '2.30 to 3.30', '3.00 to 4.00', '3.30 to 4.30']),
    'rooms' => ['L' => [
        ['day' => 'Monday', 'time' => '1.30 to 2.30', 'span' => 2] + $e('Monday', ''),
        ['day' => 'Monday', 'time' => '3.30 to 4.30', 'span' => 1] + $e('Monday', ''),
    ]]] + $t;
assert(array_column(timetable_room_slots($mixed, 'L'), 'time') === ['1.30 to 2.30', '2.30 to 3.30', '3.30 to 4.30'],
    'span rows found by the clock: ' . json_encode(array_column(timetable_room_slots($mixed, 'L'), 'time')));
$g = timetable_grid(['slots' => timetable_room_slots($mixed, 'L')] + $mixed, 'L');
assert($g['cells']['1.30 to 2.30']['Monday']['rows'] === 2 && !empty($g['covered']['2.30 to 3.30']['Monday']));
// Drawn over every row, the lab covers every row that starts before it ends at
// 3.30, another clock's 3.00 included (the room is busy then), and stops there.
$g = timetable_grid($mixed, 'L');
assert($g['cells']['1.30 to 2.30']['Monday']['rows'] === 4);
assert(!empty($g['covered']['3.00 to 4.00']) && isset($g['cells']['3.30 to 4.30']['Monday']));
assert(timetable_minutes('12.30 to 1.30') === [750, 810] && timetable_minutes('Room no.') === null);

// A room number in two ULCs is stored apart but shown plain.
assert(timetable_room_label('105 (ULC 1)') === '105' && timetable_room_label('IT Lab (S2)') === 'IT Lab (S2)' && timetable_room_label('402') === '402');

assert(timetable_grid($t, 'nope') === ['cells' => [], 'covered' => []]);

// The school a booking belongs to is read off its class label: Law before BA
// and BBA, a shared lecture belongs to every class's school, and a hold names
// its department.
$x = fn($class, $blocked = '') => ['class' => $class, 'blocked' => $blocked];
assert(timetable_schools($x('BA LLB Sem III')) === ['law'] && timetable_schools($x('BBA LLB Sem I')) === ['law']);
assert(timetable_schools($x('BBA Sem I Div A')) === ['mgmt'] && timetable_schools($x('MBA Sem III')) === ['mgmt']);
assert(timetable_schools($x('BA Economics Sem V')) === ['lib'] && timetable_schools($x('LLM Corporate')) === ['law']);
assert(timetable_schools($x('B.Tech First Year Div F')) === ['eng'] && timetable_schools($x('M.Tech BT Sem I')) === ['eng']);
assert(timetable_schools($x('MA Industrial Psychology; BBA Sem I')) === ['lib', 'mgmt']);
assert(timetable_schools($x('', 'SOE CSE/AIDS')) === ['eng'] && timetable_schools($x('', 'BBA Seminar')) === [] && timetable_schools($x('Guest')) === []);

// One school's view keeps only the rooms it teaches in, and shows another
// school's class there as a bare hold rather than a free slot.
$s = ['day' => 'Monday', 'time' => '9.30 to 10.30', 'span' => 1, 'code' => 'Torts', 'faculty' => 'Dr. Test', 'lab' => false, 'blocked' => ''];
$both = ['days' => ['Monday'], 'slots' => [], 'buildings' => ['ULC 1' => ['301', '302'], 'ULC 6' => ['001']], 'rooms' => [
    '301' => [['class' => 'BA LLB Sem I'] + $s, ['class' => 'BBA Sem I', 'time' => '10.30 to 11.30'] + $s],
    '302' => [['class' => 'BBA Sem III'] + $s],
    '001' => [['class' => '', 'blocked' => 'SOE CSE/AIDS'] + $s],
]];
$law = timetable_for_school($both, 'law');
assert(array_keys($law['rooms']) === [301] && $law['buildings'] === ['ULC 1' => ['301']]);
assert($law['rooms']['301'][0]['code'] === 'Torts' && $law['rooms']['301'][1]['blocked'] === 'Other school');
assert($law['rooms']['301'][1]['class'] === '' && $law['rooms']['301'][1]['faculty'] === '' && $law['rooms']['301'][1]['time'] === '10.30 to 11.30');
// A hold alone does not put a room in the school's list.
assert(timetable_for_school($both, 'eng')['rooms'] === []);

// Sources: a document is kept only with a safe path under docs/, a citation
// only of a listed document, and a cell only as four numbers.
$cited = $export;
$cited['docs'] = [
    'eng-cse' => ['name' => 'CSE.xlsx', 'file' => 'docs/eng-cse.xlsx', 'kind' => 'sheet', 'how' => 'script'],
    'fy' => ['name' => 'FY.pdf', 'file' => 'docs/fy.pdf', 'kind' => 'pdf', 'how' => 'hand'],
    'bad' => ['name' => 'x', 'file' => '../includes/config.local.php'],
];
$cited['rooms'] = ['102' => [$e('Monday', '09.30 to 10.30', ['classes' => ['B.Tech CSE Sem III Div A', ''], 'src' => [
    ['doc' => 'eng-cse', 'tab' => 'CSE A', 'cell' => [5, 1, 5, 1], 'fix' => 'swapped'],
    ['doc' => 'fy', 'page' => '6', 'cell' => 'x'],
    ['doc' => 'bad', 'tab' => 'A'],
]])]];
$c = timetable_parse(json_encode($cited));
assert(array_keys($c['docs']) === ['eng-cse', 'fy'] && $c['docs']['fy']['kind'] === 'pdf' && $c['docs']['eng-cse']['how'] === 'script');
$src = $c['rooms'][102][0]['src'];
assert(count($src) === 2 && $src[0]['cell'] === [5, 1, 5, 1] && $src[0]['fix'] === 'swapped');
assert($src[1]['page'] === 6 && $src[1]['cell'] === null);
assert($c['rooms'][102][0]['classes'] === ['B.Tech CSE Sem III Div A']);
// A file from before sources existed still reads, with none.
assert($t['docs'] === [] && $t['rooms'][102][0]['src'] === [] && $t['rooms'][102][0]['classes'] === []);
assert(timetable_cell_name([5, 1, 5, 1]) === 'B6' && timetable_cell_name([10, 3, 13, 27]) === 'D11:AB14');

// Attendance: which structure classes a timetable class is.
$rows = [];
foreach ([['eng', '1st Year', 'Core', 'F'], ['eng', '1st Year', 'CS', 'AA'], ['eng', '1st Year', 'Biomedical', 'B'],
    ['eng', '2nd Year', 'CSE', 'A'], ['eng', '3rd Year', 'SE', 'A'], ['eng', '3rd Year', 'CS', 'A'],
    ['eng', '3rd Year', 'Biotechnology', 'Food Technology'], ['eng', '2nd Year', 'Biotechnology', 'A'],
    ['eng', '2nd Year', 'Mechanical', 'A'], ['eng', '2nd Year', 'Civil Engineering', 'A'], ['eng', '1st Year', 'M. Tech', 'Biotechnology'],
    ['mgmt', '1st Year', 'BBA', 'A'], ['mgmt', '1st Year', 'BBA', 'B'], ['mgmt', '3rd Year', 'BBA', 'A'],
    ['law', '1st Year', '', 'A']] as [$s, $y, $b, $d]) $rows[] = ['school' => $s, 'year' => $y, 'branch' => $b, 'division' => $d, 'strength' => 60];
$which = fn($label) => array_map(fn($r) => "{$r['year']}/{$r['branch']}/{$r['division']}", timetable_attendance_classes($label, $rows));
assert($which('B.Tech First Year Div F') === ['1st Year/Core/F'] && $which('B.Tech First Year Div AA') === ['1st Year/CS/AA']);
assert($which('B.Tech CSE Sem III Div A') === ['2nd Year/CSE/A'] && $which('B.Tech CSE Sem V Div A') === ['3rd Year/SE/A']);
assert($which('B.Tech BT-FT Sem V') === ['3rd Year/Biotechnology/Food Technology'] && $which('B.Tech BT Sem III') === ['2nd Year/Biotechnology/A']);
assert($which('B.Tech ME-AE Sem III') === ['2nd Year/Mechanical/A'] && $which('B.Tech Civil Sem III (SE-A)') === ['2nd Year/Civil Engineering/A']);
assert($which('M.Tech BT Sem I') === ['1st Year/M. Tech/Biotechnology']);
assert($which('BBA Sem I') === ['1st Year/BBA/A', '1st Year/BBA/B']);
// A specialisation, Law, Liberal Arts and ECE match nothing rather than a guess.
assert($which('BBA Sem V Marketing') === [] && $which('BA LLB Sem I') === [] && $which('BA Psychology Sem I') === [] && $which('B.Tech ECE') === []);

// The reading for a lecture is the latest filed between its start and end.
$days = ['2026-10-12' => ['k' => ['09:30' => 50, '10:15' => 52, '10:30' => 40], 'old' => 30]];
$lec = ['time' => '9.30 to 10.30', 'span' => 1];
assert(timetable_reading($days, '2026-10-12', 'k', $lec) === 52);
assert(timetable_reading($days, '2026-10-12', 'k', ['time' => '10.30 to 11.30', 'span' => 1]) === 40);
assert(timetable_reading($days, '2026-10-12', 'k', ['time' => '8.30 to 9.30', 'span' => 1]) === null);
assert(timetable_reading($days, '2026-10-13', 'k', $lec) === null && timetable_reading($days, '2026-10-12', 'old', $lec) === null);
// Up to 30 minutes early counts (the Form's 13:15 for First Year's 1.30)...
$days = ['d' => ['k' => ['13:15' => 37]]];
$at130 = ['time' => '1.30 to 2.30', 'span' => 1];
assert(timetable_reading($days, 'd', 'k', $at130) === 37);
assert(timetable_reading($days, 'd', 'k', ['time' => '1.45 to 2.45', 'span' => 1]) === 37);
assert(timetable_reading($days, 'd', 'k', ['time' => '2.00 to 3.00', 'span' => 1]) === null);
// ...but not when the class is in another lecture then: it is that one's.
assert(timetable_reading($days, 'd', 'k', $at130, [[750, 810]]) === null);
assert(timetable_reading($days, 'd', 'k', ['time' => '12.30 to 1.30', 'span' => 1], [[810, 870]]) === 37);
// One filed during the lecture beats an early one.
assert(timetable_reading(['d' => ['k' => ['13:15' => 37, '13:40' => 40]]], 'd', 'k', $at130) === 40);
$lt = timetable_lectures(['rooms' => ['1' => [['day' => 'Monday', 'time' => '1.30 to 2.30', 'span' => 2, 'classes' => ['A', 'B']]]]]);
assert($lt['A']['Monday'] === [[810, 930]] && $lt['B']['Monday'] === [[810, 930]]);

// Deans: a name matches with titles ignored, through every spelling in 'as',
// and a booking taught by two belongs to each.
assert(timetable_person('Adv. Dr.  Abhilasha Mane') === 'abhilasha mane' && timetable_person('Prof Bindiya Naik') === 'bindiya naik');
$ds = timetable_parse_deans([
    ['name' => 'Dr. Deepa Joshi', 'role' => 'Dean', 'as' => ['DAJ']],
    ['name' => 'Bindiya Naik'],
    ['name' => 'Dr. Deepa Joshi'],   // a repeat is dropped
    ['role' => 'no name'],
    'junk',
]);
assert(array_keys($ds) === ['deepa-joshi', 'bindiya-naik'] && $ds['deepa-joshi']['as'] === ['deepa joshi', 'daj'] && $ds['bindiya-naik']['role'] === '');
$p = ['days' => ['Monday'], 'buildings' => ['ULC 1' => ['201', '301']], 'rooms' => [
    '201' => [['faculty' => 'DAJ', 'blocked' => '', 'day' => 'Monday', 'time' => '1.30 to 2.30']],
    '301' => [['faculty' => 'Prof. Bindiya Naik / Prof. Eden Lobo', 'blocked' => '', 'day' => 'Monday', 'time' => '9.00 to 10.00'],
        ['faculty' => '', 'blocked' => 'Bindiya Naik', 'day' => 'Monday', 'time' => '1.30 to 2.30']],
]];
$dj = timetable_teaching($p, $ds['deepa-joshi']);
assert(count($dj) === 1 && $dj[0]['room'] === '201' && $dj[0]['ulc'] === 'ULC 1');
$bn = timetable_teaching($p, $ds['bindiya-naik']);
assert(count($bn) === 1 && $bn[0]['time'] === '9.00 to 10.00');
assert(timetable_teaching($p, ['as' => ['nobody']]) === []);
assert(timetable_parse(json_encode($export))['deans'] === []);

// Finding a class in a school's sheet, in each of the three layouts.
require_once __DIR__ . '/../tools/timetable/sources.php';
// Days across, times down, three rows a period: the period's rows under the day.
$g = [1 => [1 => 'Monday', 2 => 'Tuesday'], 2 => [0 => '09.30 to 10.30', 1 => 'OS', 2 => 'CN'], 3 => [1 => 'Dr. A'], 4 => [1 => '202'],
    5 => [0 => '10.30 to 11.30', 1 => 'DBMS']];
assert(source_cell($g, [], 'Tuesday', 570) === [2, 2, 4, 2] && source_cell($g, [], 'Monday', 630) === [5, 1, 8, 1]);
// a two-hour lab merged over both periods is taken whole
assert(source_cell($g, [[2, 1, 5, 1]], 'Monday', 570) === [2, 1, 5, 1]);
assert(source_cell($g, [], 'Friday', 570) === null && source_cell($g, [], 'Monday', 900) === null);
// Days down, times across, two classes a day: the row naming the subject.
$g = [0 => [2 => '10.30-11.30', 3 => '11.30-12.30'], 1 => [0 => 'MON', 2 => 'MOS(DAJ)'], 2 => [2 => 'T1-SD (AV)'], 3 => [0 => 'TUE']];
assert(source_cell($g, [], 'Monday', 630, '', ['SD']) === [2, 2, 2, 2]);
assert(source_cell($g, [], 'Monday', 630, '', ['MOS']) === [1, 2, 1, 2]);
// Days across with periods under each, rooms down; the later days unlabelled.
$g = [0 => [3 => 'MONDAY'], 1 => [0 => 'Room No.', 3 => '1 09:30–10:30', 4 => '2 10:30–11:30', 5 => '1 09:30–10:30', 6 => '2 10:30–11:30',
    7 => '3 11:30-12:30', 8 => '4 12:30-1:30', 9 => '5 1:30-2:30', 10 => '6 2:30-3:30', 11 => '7 3:30-4:30', 12 => '8 4:30-5:30'],
    2 => [0 => 'ULC 1 603', 6 => 'ECA-KK'], 3 => [0 => 'ULC-1 Computer Lab', 5 => 'DBMS']];
assert(source_cell($g, [], 'Tuesday', 630, '603') === [2, 6, 2, 6]);
assert(source_cell($g, [], 'Tuesday', 570, 'Computer Lab') === [3, 5, 3, 5]);

echo "OK\n";
