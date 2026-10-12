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

assert(timetable_grid($t, 'nope') === ['cells' => [], 'covered' => []]);

echo "OK\n";
