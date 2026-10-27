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
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';

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
// == not ===: the file writes 'rooms' last, so only the key order differs.
assert(timetable_read() == $t, 'reads back exactly what was written');
assert(timetable_read()['rooms']['001'][0]['blocked'] === 'BBA Seminar');

// The split write: the server's file carries no sources at all; they go to the
// side file, read back only where TIMETABLE_SOURCES is on (off in this test).
$withSrc = $t;
$withSrc['docs'] = ['law' => ['name' => 'Law.xlsx', 'file' => 'docs/law.xlsx', 'kind' => 'sheet', 'how' => 'direct']];
$withSrc['rooms']['102'][0]['src'] = [['doc' => 'law', 'tab' => 'A', 'page' => 0, 'cell' => [1, 1, 1, 1], 'fix' => '']];
$side = dirname(TIMETABLE_FILE) . '/sources.php';
assert(timetable_write_split($withSrc, TIMETABLE_FILE, $side));
$main = file_get_contents(TIMETABLE_FILE);
assert(!str_contains($main, '"src"') && !str_contains($main, 'Law.xlsx'), 'no sources in the uploaded file');
assert(store_decode(file_get_contents($side))['src']['102'][0][0]['tab'] === 'A');
assert(timetable_read()['rooms']['102'][0]['src'] === [] && timetable_read()['docs'] === []);
@unlink($side);

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
// Drawn over every row, the lab stretches over the rows that end by 3.30 (its
// own 2.30, another clock's 1.30 to 3.30) and no further: another clock's 3.00
// to 4.00 is a tail, busy until 3.30 but not part of the cell, so the cell is
// never taller than the class.
$g = timetable_grid($mixed, 'L');
assert($g['cells']['1.30 to 2.30']['Monday']['rows'] === 3);
assert(empty($g['covered']['3.00 to 4.00']) && $g['tails']['3.00 to 4.00']['Monday']['until'] === '3.30');
assert(isset($g['cells']['3.30 to 4.30']['Monday']) && empty($g['tails']['3.30 to 4.30']));
assert(timetable_usage($mixed, $g) === [5, 5], 'a tail counts as booked');
// The case found on a dean's week: a lab 2.30 to 4.30 and another class's
// 3.45 row. Two rows tall, the 3.45 row its tail, and a class booked in the
// tail's row keeps its own cell.
$thu = ['days' => ['Thursday'], 'slots' => array_map(fn($x) => ['time' => $x, 'break' => ''],
    ['2.30 to 3.30', '3.30 to 4.30', '3.45 to 4.45']),
    'rooms' => ['P' => [['day' => 'Thursday', 'time' => '2.30 to 3.30', 'span' => 2, 'lab' => true] + $e('Thursday', '')]]] + $t;
$g = timetable_grid($thu, 'P');
assert($g['cells']['2.30 to 3.30']['Thursday']['rows'] === 2 && $g['tails']['3.45 to 4.45']['Thursday'] === ['until' => '4.30', 'lab' => true]);
$thu['rooms']['P'][] = ['day' => 'Thursday', 'time' => '3.45 to 4.45', 'span' => 1] + $e('Thursday', '');
$g = timetable_grid($thu, 'P');
assert(empty($g['tails']['3.45 to 4.45']) && isset($g['cells']['3.45 to 4.45']['Thursday']));
assert(timetable_span_label(['time' => '2.30 to 3.30', 'span' => 2]) === '2.30 to 4.30' && timetable_clock(990) === '4.30');
assert(timetable_minutes('12.30 to 1.30') === [750, 810] && timetable_minutes('Room no.') === null);

// A room number in two ULCs is stored apart but shown plain.
assert(timetable_room_label('105 (ULC 1)') === '105' && timetable_room_label('IT Lab (S2)') === 'IT Lab (S2)' && timetable_room_label('402') === '402');

assert(timetable_grid($t, 'nope') === ['cells' => [], 'covered' => [], 'tails' => []]);

// The school a booking belongs to is read off its class label: Law before BA
// and BBA, a shared lecture belongs to every class's school, and a hold names
// its department.
$x = fn($class, $blocked = '') => ['class' => $class, 'blocked' => $blocked];
assert(timetable_schools($x('BA LLB Sem III')) === ['law'] && timetable_schools($x('BBA LLB Sem I')) === ['law']);
assert(timetable_schools($x('BBA Sem I Div A')) === ['mgmt'] && timetable_schools($x('MBA Sem III')) === ['mgmt']);
assert(timetable_schools($x('BA Economics Sem V')) === ['lib'] && timetable_schools($x('LLM Corporate')) === ['law']);
assert(timetable_schools($x('B.Tech First Year Div F')) === ['eng'] && timetable_schools($x('M.Tech BT Sem I')) === ['eng']);
assert(timetable_schools($x('MA Industrial Psychology; BBA Sem I')) === ['lib', 'mgmt']);
// Hospitality's B.Sc is not Science's.
assert(timetable_schools($x('B.Sc HHA Sem V Culinary; M.Sc HHA Sem III')) === ['hosp'] && timetable_schools($x('B.Sc Sem III')) === ['science']);
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
// CS is Cyber Security: its own branch, never CSE's or SE's.
assert($which('B.Tech CS Sem V Div A') === ['3rd Year/CS/A']);
// A Management specialisation is its year's programme; Law's programmes are
// its branches, an LLM its one class; Liberal Arts' BA is the whole year
// (placeholder A/B) and each MA its one first-year class.
assert($which('BBA Sem V Finance') === ['3rd Year/BBA/A'] && $which('BBA Sem I') === ['1st Year/BBA/A', '1st Year/BBA/B']);
$lawRows = [...$rows, ['school' => 'lib', 'year' => '4th Year', 'branch' => 'BA', 'division' => 'B', 'strength' => 60],
    ['school' => 'lib', 'year' => '1st Year', 'branch' => 'MA Politics & International Relations', 'division' => 'A', 'strength' => 30]];
$whichLaw = fn($label) => array_map(fn($r) => "{$r['school']}/{$r['year']}/{$r['division']}", timetable_attendance_classes($label, $lawRows));
$whichCourse = fn($label) => array_map(fn($r) => "{$r['year']}/{$r['branch']}/{$r['division']}", timetable_attendance_classes($label, class_rows()));
assert($whichCourse('BA LLB Sem I') === ['1st Year/BA LLB/A', '1st Year/BA LLB/B'] && $whichCourse('BBA LLB Sem III') === ['2nd Year/BBA LLB/A']);
assert($whichCourse('LLB Sem V') === ['3rd Year/LLB/A'] && $whichCourse('LLM Constitution A') === ['1st Year/LLM Constitutional Law/A']);
assert($whichCourse('LLM Corporate') === ['1st Year/LLM Corporate & Commercial Law/A'] && $whichCourse('LLM Criminal') === ['1st Year/LLM Criminal Law/A']);
assert($whichLaw('BA Psychology Sem VII') === ['lib/4th Year/B'] && $whichLaw('BA Sem VII: Psychology, Politics & IR') === ['lib/4th Year/B']);
assert($whichLaw('MA Politics & IR') === ['lib/1st Year/A']);
assert($whichCourse('MA Industrial Psychology') === ['1st Year/MA Psychology (Industrial-Organizational)/A']
    && $whichCourse('MA Counselling Psychology') === ['1st Year/MA Psychology (Counselling)/A']);
// An old branchless Liberal Arts label lands on the BA.
assert(parse_class_label('School of Liberal Arts / 2nd Year / A')['branch'] === 'BA');

// Classes no timetable puts in a room (an MDMC course): on the person's own
// week with no room, never in a room's grid. A period on a slot the grid does
// not draw is dropped.
$slotsX = ['9.30 to 10.30' => ['time' => '9.30 to 10.30', 'break' => ''], '4.30 to 5.30' => ['time' => '4.30 to 5.30', 'break' => '']];
$extra = timetable_parse_extra([['name' => 'Ms. Asha Rao', 'what' => 'Ethical Hacking', 'class' => 'MDMC III Year',
    'slots' => [['Wednesday', '9.30 to 10.30', 2, true], ['Friday', '4.30 to 5.30', 1, false], ['Friday', '7.00 to 8.00', 1, false]]], ['name' => '']],
    ['Monday', 'Wednesday', 'Friday'], $slotsX);
assert(count($extra) === 2 && $extra[0]['person'] === 'asha rao' && $extra[0]['span'] === 2 && $extra[0]['lab'] === true);
$mine = timetable_teaching(['rooms' => ['101' => []], 'buildings' => ['ULC 1' => ['101']], 'extra' => $extra], ['as' => ['asha rao']]);
assert(count($mine) === 2 && $mine[0]['room'] === '' && !isset($mine[0]['person']) && timetable_hours($mine) == 3);
assert(timetable_teaching(['rooms' => [], 'buildings' => [], 'extra' => $extra], ['as' => ['ravi rao']]) === []);
// A group ('names') credits each member with the same periods, and a period
// overlapping one the person already has (another minor at 4.45 against
// 4.30, or a room booking) counts once.
$slotsX['4.45 to 5.45'] = ['time' => '4.45 to 5.45', 'break' => ''];
$group = timetable_parse_extra([
    ['names' => ['Dr. A One', 'Dr. B Two'], 'what' => 'MDMC Minor', 'class' => 'B.Tech CSE Sem III (MDMC)', 'slots' => [['Monday', '4.30 to 5.30', 1, false]]],
    ['names' => ['Dr. A One'], 'what' => 'MDMC Minor', 'class' => 'B.Tech AIDS Sem III (MDMC)', 'slots' => [['Monday', '4.45 to 5.45', 1, false], ['Wednesday', '4.45 to 5.45', 1, false]]],
], ['Monday', 'Wednesday'], $slotsX);
assert(count($group) === 4);
$tg = ['rooms' => ['101' => [['day' => 'Wednesday', 'time' => '4.30 to 5.30', 'span' => 1, 'code' => 'X', 'class' => 'B.Tech CSE Sem V Div A', 'classes' => [], 'faculty' => 'Dr. A One', 'lab' => false, 'blocked' => '', 'src' => []]]],
    'buildings' => ['ULC 1' => ['101']], 'extra' => $group];
$one = timetable_teaching($tg, ['as' => ['a one']]);
assert(count($one) === 2 && timetable_hours($one) == 2 && $one[1]['time'] === '4.30 to 5.30' && $one[1]['day'] === 'Monday');
assert(timetable_hours(timetable_teaching($tg, ['as' => ['b two']])) == 1);
// Someone with only roomless classes is still in the Faculty picker.
assert(isset(timetable_faculty($tg + ['calendars' => []])['b-two']));
assert($which('B.Tech BT-FT Sem V') === ['3rd Year/Biotechnology/Food Technology'] && $which('B.Tech BT Sem III') === ['2nd Year/Biotechnology/A']);
assert($which('B.Tech ME-AE Sem III') === ['2nd Year/Mechanical/A'] && $which('B.Tech Civil Sem III (SE-A)') === ['2nd Year/Civil Engineering/A']);
assert($which('M.Tech BT Sem I') === ['1st Year/M. Tech/Biotechnology']);
assert($which('BBA Sem I') === ['1st Year/BBA/A', '1st Year/BBA/B']);
// ECE has no structure class to match, so nothing rather than a guess.
assert($which('B.Tech ECE') === []);

// The reading for a lecture is the latest filed between its start and end.
$days = ['2026-10-12' => ['k' => ['09:30' => 50, '10:15' => 52, '10:30' => 40], 'old' => 30]];
$lec = ['time' => '9.30 to 10.30', 'span' => 1];
assert(timetable_reading($days, '2026-10-12', 'k', $lec) === 52);
assert(timetable_reading($days, '2026-10-12', 'k', ['time' => '10.30 to 11.30', 'span' => 1]) === 40);
assert(timetable_reading($days, '2026-10-12', 'k', ['time' => '8.30 to 9.30', 'span' => 1]) === null);
assert(timetable_reading($days, '2026-10-13', 'k', $lec) === null && timetable_reading($days, '2026-10-12', 'old', $lec) === null);
// A second person's reading for the same hour ("10:15~2") is still that lecture's.
assert(timetable_reading(['d' => ['k' => ['10:15' => 33, '10:15~2' => 30]]], 'd', 'k', $lec) === 30);
assert(timetable_reading(['d' => ['k' => ['day' => 12, 'day~2' => 14]]], 'd', 'k', $lec) === 14);
// A reading that says it ran 9:30 to 11:30 counts for both lectures it covers,
// and for a two-hour lab, but not for a lecture it only grazes.
$labDays = ['d' => ['k' => ['09:30-11:30' => 41]]];
assert(timetable_reading($labDays, 'd', 'k', $lec) === 41 && timetable_reading($labDays, 'd', 'k', ['time' => '10.30 to 11.30', 'span' => 1]) === 41);
assert(timetable_reading($labDays, 'd', 'k', ['time' => '9.30 to 11.30', 'span' => 2]) === 41);
assert(timetable_reading($labDays, 'd', 'k', ['time' => '11.15 to 12.15', 'span' => 1]) === null);
// Up to 30 minutes early counts (the Form's 13:15 for First Year's 1.30)...
$days = ['d' => ['k' => ['13:15' => 37]]];
$at130 = ['time' => '1.30 to 2.30', 'span' => 1];
assert(timetable_reading($days, 'd', 'k', $at130) === 37);
assert(timetable_reading($days, 'd', 'k', ['time' => '1.45 to 2.45', 'span' => 1]) === 37);
assert(timetable_reading($days, 'd', 'k', ['time' => '2.00 to 3.00', 'span' => 1]) === null);
// ...even with the class in another lecture then: the Form's "13:15 - 14:15"
// covers 45 minutes of the 1.30 lecture and 15 of the 12.30 one, so it is the
// 1.30's (a CSE division's 12.30 and 1.30, read wrong until 22 Oct 2026).
assert(timetable_reading($days, 'd', 'k', $at130, [[750, 810], [810, 870]]) === 37);
assert(timetable_reading($days, 'd', 'k', ['time' => '12.30 to 1.30', 'span' => 1], [[750, 810], [810, 870]]) === null);
// "14:15 - 15:15" likewise belongs to 2.30, not the 1.30 it starts in.
$days215 = ['d' => ['k' => ['14:15' => 39]]];
$both = [[810, 870], [870, 930]];
assert(timetable_reading($days215, 'd', 'k', ['time' => '2.30 to 3.30', 'span' => 1], $both) === 39);
assert(timetable_reading($days215, 'd', 'k', $at130, $both) === null);
// With no lecture after it, a reading filed late in one still counts for it.
assert(timetable_reading($days, 'd', 'k', ['time' => '12.30 to 1.30', 'span' => 1], [[750, 810]]) === 37);
// A tie stays with the lecture the reading starts in: 13:00 is half of each.
$days1300 = ['d' => ['k' => ['13:00' => 30]]];
assert(timetable_reading($days1300, 'd', 'k', ['time' => '12.30 to 1.30', 'span' => 1], [[750, 810], [810, 870]]) === 30);
assert(timetable_reading($days1300, 'd', 'k', $at130, [[750, 810], [810, 870]]) === null);
// One filed during the lecture beats an early one.
assert(timetable_reading(['d' => ['k' => ['13:15' => 37, '13:40' => 40]]], 'd', 'k', $at130) === 40);
// A whole-day reading stands for every lecture that day, but one filed for
// the lecture itself beats it.
assert(timetable_reading(['d' => ['k' => ['day' => 22]]], 'd', 'k', $at130) === 22);
assert(timetable_reading(['d' => ['k' => ['day' => 22]]], 'd', 'k', ['time' => '9.30 to 12.30', 'span' => 3]) === 22);
assert(timetable_reading(['d' => ['k' => ['13:40' => 40, 'day' => 22]]], 'd', 'k', $at130) === 40);
$lt = timetable_lectures(['rooms' => ['1' => [['day' => 'Monday', 'time' => '1.30 to 2.30', 'span' => 2, 'classes' => ['A', 'B']]]]]);
assert($lt['A']['Monday'] === [[810, 930]] && $lt['B']['Monday'] === [[810, 930]]);

// Design: its classes are the design school's, and a Design room's number is
// shown plain like a ULC's.
assert(timetable_schools($x('B.Des Fashion Design Sem III')) === ['design'] && timetable_schools($x('M.Des UI/UX Design Sem I')) === ['design']);
assert(timetable_room_label('201 (School of Design)') === '201' && timetable_room_label('107 (Cintiq Lab)') === '107 (Cintiq Lab)');
$rows = [];
foreach ([['1st Year', 'B Des Fashion Design', 'A'], ['1st Year', 'B Des UI/UX', 'B'], ['1st Year', 'M Des UI/UX', 'B'],
    ['2nd Year', 'B Des UI/UX', 'A'], ['3rd Year', 'B Des Visual Communication Design', 'A'], ['2nd Year', 'M Des Transportation', 'A'],
    ['2nd Year', 'M Des UI/UX', 'B']] as [$y, $b, $d]) $rows[] = ['school' => 'design', 'year' => $y, 'branch' => $b, 'division' => $d, 'strength' => 5];
// $which keeps the rows it was made with, so Design's get their own.
$which = fn($label) => array_map(fn($r) => "{$r['year']}/{$r['branch']}/{$r['division']}", timetable_attendance_classes($label, $rows));
assert($which('B.Des UI/UX Design Sem III') === ['2nd Year/B Des UI/UX/A'] && $which('B.Des Visual Communication Design Sem V') === ['3rd Year/B Des Visual Communication Design/A']);
assert($which('M.Des Transportation Design Sem III') === ['2nd Year/M Des Transportation/A'] && $which('M.Des UI/UX Design Sem I') === ['1st Year/M Des UI/UX/B']);
// The foundation is every B.Des programme's first year, never the M.Des's.
assert($which('B.Des Foundation Sem I') === ['1st Year/B Des Fashion Design/A', '1st Year/B Des UI/UX/B']);

// Science: B.Sc is the science school, a Dental Building room shows its plain
// number, and a year's lab divisions are all its one structure division.
assert(timetable_schools($x('B.Sc Sem I')) === ['science'] && timetable_room_label('203 (Dental Building)') === '203');
$rows = [['school' => 'science', 'year' => '1st Year', 'branch' => 'B.Sc.', 'division' => 'A', 'strength' => 100],
    ['school' => 'science', 'year' => '2nd Year', 'branch' => 'B.Sc.', 'division' => 'A', 'strength' => 30]];
$which = fn($label) => array_map(fn($r) => "{$r['year']}/{$r['branch']}/{$r['division']}", timetable_attendance_classes($label, $rows));
assert($which('B.Sc Sem I') === ['1st Year/B.Sc./A'] && $which('B.Sc Sem III') === ['2nd Year/B.Sc./A']);

// A semester calendar: kept as dates, drawn a week at a time. A date's
// session -1 is a day out of the room, and a date not listed (a holiday) has
// nothing in it.
$cal = $export;
$cal['days'] = ['Monday', 'Tuesday'];
$cal['rooms'] += ['205 (School of Design)' => [], '305' => []];
$cal['calendars'] = [
    ['class' => 'B.Des Fashion Design Sem III', 'rooms' => ['205 (School of Design)', 'nowhere'],
        'sessions' => [['09.30 to 10.30', 3], ['1.15 to 2.15', 2]],
        'subjects' => [['Fabric Study', 'Prof. A'], ['Garment studio 1', 'Prof. B']],
        'dates' => ['2026-10-12' => [0, 1], '2026-10-13' => [1, -1], '2026-10-19' => [0, 0], 'Mon' => [0, 0]],
        'src' => ['doc' => 'nope', 'tab' => 'x', 'cells' => []]],
    ['class' => 'Empty', 'rooms' => ['305'], 'sessions' => [['09.30 to 10.30', 3]], 'subjects' => [], 'dates' => []],
];
$c = timetable_parse(json_encode($cal));
assert(count($c['calendars']) === 1, 'a calendar with no dates is dropped');
assert($c['calendars'][0]['rooms'] === ['205 (School of Design)'] && array_keys($c['calendars'][0]['dates']) === ['2026-10-12', '2026-10-13', '2026-10-19']);
assert($c['calendars'][0]['src'] === [], 'a citation of an unlisted document is dropped');
$wk = timetable_for_week($c, new DateTimeImmutable('2026-10-12'));
$in = $wk['rooms']['205 (School of Design)'];
assert($wk['calendars'] === [] && count($in) === 3);
assert($in[0]['day'] === 'Monday' && $in[0]['time'] === '09.30 to 10.30' && $in[0]['span'] === 3 && $in[0]['code'] === 'Fabric Study' && $in[0]['faculty'] === 'Prof. A');
assert($in[1]['time'] === '1.15 to 2.15' && $in[1]['code'] === 'Garment studio 1' && $in[1]['classes'] === ['B.Des Fashion Design Sem III']);
assert($in[2]['day'] === 'Tuesday' && $in[2]['code'] === 'Garment studio 1');
assert(count(timetable_for_week($c, new DateTimeImmutable('2026-10-19'))['rooms']['205 (School of Design)']) === 2);
assert(timetable_for_week($c, new DateTimeImmutable('2026-11-09'))['rooms']['205 (School of Design)'] === [], 'a week off leaves the room, empty');
assert(count(timetable_for_week($c, new DateTimeImmutable('2026-10-12'))['rooms']['102']) === 4, 'weekly bookings are untouched');
// A school keeps its calendar's rooms in a week with nothing in them, and
// another school sees that calendar as a hold.
$des = timetable_for_school($c, 'design');
assert(isset($des['rooms']['205 (School of Design)']) && !isset($des['rooms']['102']));
assert(timetable_for_week($des, new DateTimeImmutable('2026-11-09'))['rooms']['205 (School of Design)'] === []);
$other = timetable_for_week(['rooms' => ['205 (School of Design)' => []]] + timetable_for_school($c + ['buildings' => []], 'eng'), new DateTimeImmutable('2026-10-12'));
assert($other['rooms']['205 (School of Design)'][0]['blocked'] === 'Other school' && $other['rooms']['205 (School of Design)'][0]['code'] === '');
// The split write sends a calendar's cells to the side file, like any source.
$c['docs'] = ['des' => ['name' => 'D.xlsx', 'file' => 'docs/des.xlsx', 'kind' => 'sheet', 'how' => 'script']];
$c['calendars'][0]['src'] = ['doc' => 'des', 'tab' => 'FD', 'cells' => ['2026-10-12' => [[8, 4, 9, 4], null]]];
$side = dirname(TIMETABLE_FILE) . '/sources-cal.php';
assert(timetable_write_split($c, TIMETABLE_FILE, $side));
$back = timetable_read();
assert(!isset($back['calendars'][0]['src']['cells']) || $back['calendars'][0]['src'] === [], 'no cells on the server');
$wk = timetable_for_week(['calendars' => [$c['calendars'][0]]] + $c, new DateTimeImmutable('2026-10-12'));
assert($wk['rooms']['205 (School of Design)'][0]['src'][0]['cell'] === [8, 4, 9, 4] && $wk['rooms']['205 (School of Design)'][1]['src'] === []);
@unlink($side);

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
// Workload is hours by the clock: a two-hour lab is two, a 45-minute slot 0.75.
assert(timetable_hours([['time' => '9.30 to 10.30', 'span' => 1], ['time' => '1.30 to 2.30', 'span' => 2],
    ['time' => '12.30 to 1.15', 'span' => 1]]) === 3.75);
assert(timetable_hours([]) === 0.0 && timetable_hours([['time' => 'Lec 1', 'span' => 2]]) === 2.0);
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

// A lecture written for two groups counts once, unless the dean's entry says
// count_each_group: then once per group.
$two = [['time' => '4.30 to 5.30', 'span' => 1, 'classes' => ['A', 'B']], ['time' => '9.30 to 10.30', 'span' => 2, 'classes' => ['A']]];
assert(timetable_hours($two) === 3.0 && timetable_hours($two, true) === 4.0);
assert(timetable_parse_deans([['name' => 'Dr. X', 'count_each_group' => true]])['x']['count_each_group'] === true);
assert(timetable_parse_deans([['name' => 'Dr. Y']])['y']['count_each_group'] === false);

// Free rooms: a room's gaps by the clock, 8.00 to 5.45. Two clocks overlapping
// merge into one busy stretch; a two-hour lab is busy for both hours; a
// booking on another day or outside the day's edges does not count beyond them.
$ft = ['slots' => [], 'rooms' => ['R' => [
    $e('Monday', '9.30 to 10.30'),
    $e('Monday', '10.15 to 11.15'),                       // another school's clock
    $e('Monday', '1.30 to 2.30', ['span' => 2, 'lab' => true]),
    $e('Monday', '4.45 to 5.45'),
    $e('Tuesday', '8.00 to 9.00'),
    $e('Monday', '7.30 to 8.30'),                         // reads as 7.30 pm: the sheets' clock
]]];
$f = timetable_free($ft, 'R', 'Monday');
assert($f['busy'] === [[570, 675], [810, 930], [1005, 1065]], json_encode($f['busy']));
assert($f['free'] === [[480, 570], [675, 810], [930, 1005]], json_encode($f['free']));
assert(timetable_free($ft, 'R', 'Tuesday')['free'] === [[540, 1065]], 'a booking at the opening edge');
assert(timetable_free($ft, 'R', 'Friday') === ['busy' => [], 'free' => [[480, 1065]]], 'an empty day is free all day');
assert(timetable_free($ft, 'nowhere', 'Monday')['free'] === [[480, 1065]]);
// A lab written across a break stops at it, as on the grid.
$ft['slots'][] = ['time' => '12.30 to 1.15', 'break' => 'LUNCH'];
$ft['rooms']['L'] = [$e('Monday', '11.30 to 12.30', ['span' => 2])];
assert(timetable_free($ft, 'L', 'Monday')['busy'] === [[690, 750]]);

// The same day as pieces for the all-rooms grid: the two clocks' overlapping
// lectures are one piece holding both, a back-to-back class is its own piece,
// and the gaps between are pieces with no entries.
$runs = array_map(fn($r) => [$r['from'], $r['to'], count($r['entries'])], timetable_day_runs($ft, 'R', 'Monday'));
assert($runs === [[480, 570, 0], [570, 675, 2], [675, 810, 0], [810, 930, 1], [930, 1005, 0], [1005, 1065, 1]], json_encode($runs));
$ft['rooms']['B'] = [$e('Monday', '9.30 to 10.30'), $e('Monday', '10.30 to 11.30', ['code' => 'CN'])];
assert(count(timetable_day_runs($ft, 'B', 'Monday')) === 4, 'back to back stays two pieces');
assert(timetable_free($ft, 'B', 'Monday')['busy'] === [[570, 690]], 'but one busy stretch');

// The free grid's rows: the room's own, plus a row for each stretch of 30
// minutes or more none of them covers. 5.30 to 5.45 is too short for one.
$rows = array_column(timetable_slots_with_gaps([
    ['time' => '9.30 to 10.30', 'break' => ''], ['time' => '10.15 to 11.15', 'break' => ''],
    ['time' => '12.30 to 1.30', 'break' => 'LUNCH'], ['time' => '3.30 to 5.30', 'break' => ''],
]), 'time');
assert($rows === ['8.00 to 9.30', '9.30 to 10.30', '10.15 to 11.15', '11.15 to 12.30', '12.30 to 1.30', '1.30 to 3.30', '3.30 to 5.30'], json_encode($rows));
assert(array_column(timetable_slots_with_gaps([]), 'time') === ['8.00 to 5.45'], 'a room never booked is one free row');

// Overlapping slots: only the time two bookings share, by the clock (another
// school's 10.15 lecture against 9.30 overlaps 10.15 to 10.30), every booking
// involved, and back to back is no overlap at all.
$o = timetable_overlaps($ft, 'R', 'Monday');
assert(count($o) === 1 && [$o[0]['from'], $o[0]['to']] === [615, 630] && count($o[0]['entries']) === 2, json_encode($o));
assert(timetable_overlaps($ft, 'B', 'Monday') === [], 'back to back is not an overlap');
assert(timetable_overlaps($ft, 'R', 'Tuesday') === [] && timetable_overlaps($ft, 'nowhere', 'Monday') === []);
// Three classes handing over at 11.15 and 12.00 are one stretch with all three.
$ft['rooms']['C'] = [
    $e('Monday', '11.00 to 12.00'),
    $e('Monday', '11.15 to 12.15', ['code' => 'CN']),
    $e('Monday', '12.00 to 1.00', ['code' => 'DB']),
];
$o = timetable_overlaps($ft, 'C', 'Monday');
assert(count($o) === 1 && [$o[0]['from'], $o[0]['to']] === [675, 735] && count($o[0]['entries']) === 3, json_encode($o));

// A teacher down for two rooms at once is a clash, whatever the spelling, for
// the time the two share; one booking held in two rooms (same class, same
// subject) is not, and nor is the same teacher back to back.
$tx = ['days' => ['Monday'], 'slots' => [], 'rooms' => [
    '107' => [$e('Monday', '9.30 to 10.30', ['faculty' => 'Dr. Asha Rao']), $e('Monday', '11.30 to 12.30', ['faculty' => 'Asha Rao', 'code' => 'DB'])],
    '208' => [$e('Monday', '10.00 to 11.00', ['faculty' => 'Prof. Asha Rao / Mr. Ben', 'code' => 'CN', 'class' => 'SY CSE B'])],
    '104 Lab' => [$e('Monday', '11.30 to 12.30', ['faculty' => 'Asha Rao', 'code' => 'DB'])],
    '105 Lab' => [$e('Monday', '12.30 to 1.30', ['faculty' => 'Asha Rao', 'code' => 'ML'])],
]];
$to = timetable_teacher_overlaps($tx);
assert(count($to) === 1, json_encode($to));
assert($to[0]['person'] === 'Asha Rao' && [$to[0]['from'], $to[0]['to']] === [600, 630], json_encode($to[0]));
assert(array_column($to[0]['entries'], 'room') === ['107', '208']);

// Faculty workload's picker: one entry per person whatever the spelling or
// title, shared lectures counted for each teacher, a dean under their own
// entry, the schools read off the classes, and a vacancy is nobody.
$fx = ['rooms' => ['1' => [
    $e('Monday', '9.30 to 10.30', ['faculty' => 'Dr. Asha Rao', 'class' => 'B.Tech CSE Sem III']),
    $e('Monday', '10.30 to 11.30', ['faculty' => 'Prof. Asha  Rao', 'class' => 'BBA Sem I']),
    $e('Monday', '11.30 to 12.30', ['faculty' => 'Dr. Asha Rao / Dr. Deepa Joshi', 'class' => 'B.Tech Civil Sem III (T-A)']),
    $e('Monday', '1.15 to 2.15', ['faculty' => 'Industry faculty', 'class' => 'B.Tech CSE Sem III']),
    $e('Tuesday', '9.30 to 10.30', ['faculty' => 'Someone', 'blocked' => 'BBA Seminar']),
]], 'calendars' => [['class' => 'B.Des Fashion Design Sem III', 'subjects' => [['Draping', 'Ms. Lina Roy']]]],
    'deans' => timetable_parse_deans([['name' => 'Dr. Deepa A. Joshi', 'role' => 'Dean', 'as' => ['Dr. Deepa Joshi']]])];
$fl = timetable_faculty($fx);
assert(array_keys($fl) === ['asha-rao', 'deepa-a-joshi', 'lina-roy'], json_encode(array_keys($fl)));
assert($fl['asha-rao']['name'] === 'Dr. Asha Rao' && $fl['asha-rao']['schools'] === ['eng', 'mgmt'], json_encode($fl['asha-rao']));
assert($fl['deepa-a-joshi']['role'] === 'Dean' && $fl['lina-roy']['schools'] === ['design']);
$fx['buildings'] = ['ULC 1' => ['1']];
assert(count(timetable_teaching($fx, $fl['asha-rao'])) === 3, 'every spelling is the same person');

echo "OK\n";
