<?php
// The room timetable, built from the official classwise timetable workbook.
// This host has no outbound HTTP and no upload for it, so a build script kept
// outside the repo reads the workbook and writes the By Room view to
// timetable/rooms.php, which is uploaded with the code. The official timetable
// changes a few times a term.
//
// The file carries faculty names and the repo is public, so timetable/ is
// gitignored, and the file is JSON behind store.php's guard line, so asking the
// server for /timetable/rooms.php returns a blank page.
//
// The export is checked and rebuilt field by field rather than stored as sent,
// so a wrong file is refused with a reason instead of drawing a broken page.

require_once __DIR__ . '/store.php';

const TIMETABLE_FORMAT = 'adypu-room-timetable';
defined('TIMETABLE_FILE') || define('TIMETABLE_FILE', __DIR__ . '/../timetable/rooms.php');

function timetable_read(): array {
    $raw = @file_get_contents(TIMETABLE_FILE);
    return $raw === false ? [] : (store_decode($raw) ?? []);
}

// Returns the cleaned timetable, or a message saying what is wrong with the file.
function timetable_parse(string $json): array|string {
    $raw = json_decode($json, true);
    if (!is_array($raw)) return 'That is not JSON.';
    if (($raw['format'] ?? '') !== TIMETABLE_FORMAT) return 'That is not a room timetable.';
    if ((int) ($raw['version'] ?? 0) !== 1) return 'That timetable is a newer version than this page reads. Update the dashboard first.';

    $str = fn($v): string => is_scalar($v) ? trim((string) $v) : '';

    $days = array_values(array_filter(array_map($str, (array) ($raw['days'] ?? []))));
    $slots = [];
    foreach ((array) ($raw['slots'] ?? []) as $s) {
        $time = $str($s['time'] ?? '');
        if ($time !== '' && !isset($slots[$time])) $slots[$time] = ['time' => $time, 'break' => $str($s['break'] ?? '')];
    }
    if (!$days || !$slots) return 'That timetable has no days or no time slots in it.';

    $rooms = [];
    foreach ((array) ($raw['rooms'] ?? []) as $room => $entries) {
        $room = $str($room);
        if ($room === '' || !is_array($entries)) continue;
        $rooms[$room] = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $day = $str($e['day'] ?? '');
            $time = $str($e['time'] ?? '');
            // An entry on a day or time the grid does not draw would vanish
            // without a trace, so it is dropped here, where it can be counted.
            if (!in_array($day, $days, true) || !isset($slots[$time])) continue;
            $rooms[$room][] = [
                'day' => $day,
                'time' => $time,
                'span' => max(1, min(4, (int) ($e['span'] ?? 1))),
                'code' => $str($e['code'] ?? ''),
                'class' => $str($e['class'] ?? ''),
                'faculty' => $str($e['faculty'] ?? ''),
                'lab' => !empty($e['lab']),
                'blocked' => $str($e['blocked'] ?? ''),
            ];
        }
    }
    if (!$rooms) return 'That timetable has no rooms in it.';
    uksort($rooms, fn($a, $b) => strnatcasecmp((string) $a, (string) $b));

    // building => [room, ...], which is the ULC picker. The workbook only names
    // a building in a handful of cells, so the build script supplies this. A
    // room it misses still has to be reachable, so it lands under "Other rooms"
    // rather than vanishing from the page.
    $known = array_map('strval', array_keys($rooms));
    $buildings = [];
    $placed = [];
    foreach ((array) ($raw['buildings'] ?? []) as $name => $list) {
        $name = $str($name);
        if ($name === '' || !is_array($list)) continue;
        // Kept even when empty: a ULC named before its rooms are known is
        // still a choice, and the page says it has no rooms yet.
        $in = array_values(array_intersect($known, array_diff(array_map($str, $list), $placed)));
        $buildings[$name] = $in;
        $placed = array_merge($placed, $in);
    }
    $rest = array_values(array_diff($known, $placed));
    if ($rest) $buildings[$buildings ? 'Other rooms' : 'All rooms'] = $rest;

    return [
        'exported_at' => $str($raw['exported_at'] ?? ''),
        'source' => $str($raw['source'] ?? ''),
        'days' => $days,
        'slots' => array_values($slots),
        'buildings' => $buildings,
        'rooms' => $rooms,
    ];
}

// The time rows one room's grid draws: every slot the room is booked in on any
// day of the week, plus the breaks between its first and last. Classes keep
// their own clocks (one runs 12.30 to 1.30, the next 1.00 to 2.00), and drawing
// every class's slot for every room would fill each grid with overlapping
// "free" rows that are not free time at all. Using the whole week rather than
// the day keeps a day's free periods visible.
function timetable_room_slots(array $t, string $room): array {
    $times = array_column($t['slots'], 'time');
    $used = [];
    foreach ($t['rooms'][$room] ?? [] as $e) {
        // A two-hour lab needs its second row even if nothing else uses it,
        // but, as in timetable_grid(), a span never runs into a break.
        $i = array_search($e['time'], $times, true);
        if ($i === false) continue;
        $used[$times[$i]] = true;
        for ($n = 1; $n < ($e['span'] ?? 1) && isset($times[$i + $n]) && $t['slots'][$i + $n]['break'] === ''; $n++) $used[$times[$i + $n]] = true;
    }
    $at = [];
    foreach ($t['slots'] as $i => $s) if (isset($used[$s['time']])) $at[] = $i;
    if (!$at) return [];
    $out = [];
    foreach ($t['slots'] as $i => $s) {
        if ($i < $at[0] || $i > end($at)) continue;
        if (isset($used[$s['time']]) || $s['break'] !== '') $out[] = $s;
    }
    return $out;
}

// The weekday a date falls on, if the timetable teaches that day, else ''. The
// official timetable repeats every week, so a date only ever picks its weekday.
function timetable_day(array $t, DateTimeImmutable $date): string {
    $day = $date->format('l');
    return in_array($day, $t['days'], true) ? $day : '';
}

// Pretty printed, one room's bookings per block, so two builds can be diffed.
function timetable_write(array $t, string $path = TIMETABLE_FILE): bool {
    $json = json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    return file_put_contents($path, STORE_GUARD . $json . "\n", LOCK_EX) !== false;
}

// [time][day] => ['entries' => [...], 'rows' => n] for one room, plus the cells
// a multi-slot entry covers, so the table can skip them. A span only stretches
// over teaching slots: a lab written across lunch ends at the break rather than
// painting over it.
function timetable_grid(array $t, string $room): array {
    $byCell = [];
    foreach ($t['rooms'][$room] ?? [] as $e) {
        // Only the days being drawn: the one-day view passes a single day.
        if (in_array($e['day'], $t['days'], true)) $byCell[$e['time']][$e['day']][] = $e;
    }

    $times = array_column($t['slots'], 'time');
    $breaks = array_column($t['slots'], 'break', 'time');
    $cells = [];
    $covered = [];
    foreach ($times as $at => $time) {
        foreach ($byCell[$time] ?? [] as $day => $entries) {
            // Two bookings in one cell (a clash in the source) share the shorter span.
            $span = min(array_column($entries, 'span'));
            $rows = 1;
            for ($i = $at + 1; $rows < $span && $i < count($times) && $breaks[$times[$i]] === ''; $i++, $rows++) {
                // Something else booked in a slot this one claims: show both
                // rather than hide the second under a tall cell.
                if (!empty($byCell[$times[$i]][$day])) break;
                $covered[$times[$i]][$day] = true;
            }
            $cells[$time][$day] = ['entries' => $entries, 'rows' => $rows];
        }
    }
    return ['cells' => $cells, 'covered' => $covered];
}

// Teaching periods in use against teaching periods in the week, for the line
// under the room name.
function timetable_usage(array $t, array $grid): array {
    $teaching = array_filter($t['slots'], fn($s) => $s['break'] === '');
    $total = count($teaching) * count($t['days']);
    $used = 0;
    foreach ($teaching as $s) {
        foreach ($t['days'] as $d) {
            if (!empty($grid['cells'][$s['time']][$d]) || !empty($grid['covered'][$s['time']][$d])) $used++;
        }
    }
    return [$used, $total];
}
