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
// Where each booking came from (the source panel) is kept in a second file,
// local only: carrying it, rooms.php grew to 1.3MB, and the host deleted that
// file from the server twice within the hour on 13 Oct 2026. The live site has
// the source panel off, so it never needs this file and never gets it.
defined('TIMETABLE_SOURCES_FILE') || define('TIMETABLE_SOURCES_FILE', __DIR__ . '/../timetable/sources.php');

function timetable_read(): array {
    $raw = @file_get_contents(TIMETABLE_FILE);
    $t = $raw === false ? [] : (store_decode($raw) ?? []);
    if (!$t) return [];
    // Every entry has a 'src' and the timetable a 'docs', filled from the
    // sources file only where the source panel is on (TIMETABLE_SOURCES).
    $src = [];
    if (defined('TIMETABLE_SOURCES') && TIMETABLE_SOURCES) {
        $s = store_decode((string) @file_get_contents(TIMETABLE_SOURCES_FILE)) ?? [];
        $t['docs'] = $s['docs'] ?? [];
        $src = $s['src'] ?? [];
    }
    $t['docs'] ??= [];
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($entries as $i => $e) $t['rooms'][$room][$i]['src'] = $src[$room][$i] ?? $e['src'] ?? [];
    }
    return $t;
}

// Splits a parsed timetable into what the server needs and what only the
// source panel does, and writes both: rooms.php without any 'src' or 'docs',
// sources.php with them, indexed by room and position so timetable_read()
// can put them back.
function timetable_write_split(array $t, string $path = TIMETABLE_FILE, string $sources = TIMETABLE_SOURCES_FILE): bool {
    $src = [];
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($entries as $i => $e) {
            if (!empty($e['src'])) $src[$room][$i] = $e['src'];
            unset($t['rooms'][$room][$i]['src']);
        }
    }
    $side = ['docs' => $t['docs'] ?? [], 'src' => $src];
    unset($t['docs']);
    $json = json_encode($side, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return timetable_write($t, $path) && $json !== false
        && file_put_contents($sources, STORE_GUARD . $json . "\n", LOCK_EX) !== false;
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

    // The documents the timetable was built from, for the source panel: an id,
    // the school's file name, where its copy sits under timetable/, whether it
    // is a workbook or a PDF, and how it was read (straight from the sheet, by
    // a converter script, or typed in by hand). The path is checked here
    // because source.php serves it.
    $docs = [];
    foreach ((array) ($raw['docs'] ?? []) as $id => $d) {
        $id = $str($id);
        $file = $str($d['file'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $id) || !preg_match('#^docs/' . $id . '\.(pdf|xlsx)$#', $file)) continue;
        $docs[$id] = [
            'name' => $str($d['name'] ?? '') ?: basename($file),
            'file' => $file,
            'kind' => ($d['kind'] ?? '') === 'pdf' ? 'pdf' : 'sheet',
            'how' => in_array($d['how'] ?? '', ['direct', 'script', 'hand'], true) ? $d['how'] : 'direct',
        ];
    }

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
                // Each class on its own ("B.Tech CSE Sem III Div A"), where
                // 'class' joins them for display. Attendance is looked up by these.
                'classes' => array_values(array_filter(array_map($str, (array) ($e['classes'] ?? [])), 'strlen')),
                'faculty' => $str($e['faculty'] ?? ''),
                'lab' => !empty($e['lab']),
                'blocked' => $str($e['blocked'] ?? ''),
                'src' => timetable_parse_src($e['src'] ?? [], $docs),
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
        'docs' => $docs,
        'deans' => timetable_parse_deans($raw['deans'] ?? []),
    ];
}

// The people the Dean picker offers: name and role as the university lists
// them, an id for the URL, and every spelling the timetable uses for them
// ('as', the name itself included), compared by timetable_person().
function timetable_parse_deans(mixed $list): array {
    $out = [];
    foreach ((array) $list as $d) {
        $name = is_array($d) && is_scalar($d['name'] ?? null) ? trim((string) $d['name']) : '';
        $id = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(timetable_person($name))), '-');
        if ($name === '' || $id === '' || isset($out[$id])) continue;
        $as = array_filter(array_map(fn($a) => is_scalar($a) ? timetable_person((string) $a) : '', (array) ($d['as'] ?? [])), 'strlen');
        $out[$id] = [
            'name' => $name,
            'role' => is_scalar($d['role'] ?? null) ? trim((string) $d['role']) : '',
            'as' => array_values(array_unique([timetable_person($name), ...$as])),
        ];
    }
    return $out;
}

// Who a name is, titles and spacing aside: "Adv. Dr. Abhilasha Mane" and
// "Dr. Abhilasha Mane" are both "abhilasha mane".
function timetable_person(string $name): string {
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    while (preg_match('/^(dr|prof|mr|mrs|ms|adv|ar)\b\.?\s*/', $name, $m)) $name = substr($name, strlen($m[0]));
    return $name;
}

// Teaching hours in a list of bookings: each one's length by the clock, so a
// two-hour lab is two and a 45-minute slot three quarters. A booking with no
// readable time counts its span in hours.
function timetable_hours(array $entries): float {
    $min = 0;
    foreach ($entries as $e) {
        $m = timetable_minutes($e['time']);
        $end = timetable_end($e);
        $min += $m && $end !== null ? max(0, $end - $m[0]) : 60 * ($e['span'] ?? 1);
    }
    return $min / 60;
}

// A person's own week: every booking they teach, from every room, each with
// the room and ULC it is in, ready for timetable_grid() as one "room". A
// booking taught by two ("Prof. A / Prof. B") is each of theirs.
function timetable_teaching(array $t, array $person): array {
    $ulcOf = [];
    foreach ($t['buildings'] as $b => $list) foreach ($list as $r) $ulcOf[(string) $r] = (string) $b;
    $mine = [];
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($entries as $e) {
            if ($e['blocked'] !== '') continue;
            foreach (preg_split('#\s*/\s*#', $e['faculty']) as $f) {
                if (in_array(timetable_person($f), $person['as'], true)) {
                    $mine[] = ['room' => (string) $room, 'ulc' => $ulcOf[(string) $room] ?? ''] + $e;
                    break;
                }
            }
        }
    }
    return $mine;
}

// One booking's citations, each a document it is written in and where: the
// tab and its cells [first row, first col, last row, last col] (0-based) for a
// workbook, the page for a PDF, and any repair the build made. A citation of a
// document the file does not list is dropped.
function timetable_parse_src(mixed $list, array $docs): array {
    $out = [];
    foreach ((array) $list as $s) {
        if (!is_array($s) || !isset($docs[$s['doc'] ?? ''])) continue;
        $cell = $s['cell'] ?? null;
        $cell = is_array($cell) && count($cell) === 4 ? array_map(fn($n) => max(0, (int) $n), array_values($cell)) : null;
        $out[] = [
            'doc' => (string) $s['doc'],
            'tab' => is_scalar($s['tab'] ?? null) ? trim((string) $s['tab']) : '',
            'page' => max(0, (int) ($s['page'] ?? 0)),
            'cell' => $cell,
            'fix' => is_scalar($s['fix'] ?? null) ? trim((string) $s['fix']) : '',
        ];
    }
    return $out;
}

// "1.30 to 3.30" -> [810, 930], minutes from midnight. The sheets write a
// 12-hour clock with no am/pm, so an hour before 8 is afternoon. Null for a
// label that is not a time range.
function timetable_minutes(string $time): ?array {
    if (!preg_match('/(\d{1,2})[.:](\d{2})\s*to\s*(\d{1,2})[.:](\d{2})/i', $time, $m)) return null;
    $h = fn($x) => (int) $x < 8 ? (int) $x + 12 : (int) $x;
    return [$h($m[1]) * 60 + (int) $m[2], $h($m[3]) * 60 + (int) $m[4]];
}

// When a booking ends. A long one is written as its first hour plus a span
// ("1.30 to 2.30", span 2), so it ends span hours after it starts.
function timetable_end(array $e): ?int {
    $m = timetable_minutes($e['time']);
    if (!$m) return null;
    $span = $e['span'] ?? 1;
    return $span > 1 ? $m[0] + 60 * $span : $m[1];
}

// The time rows one room's grid draws: every slot the room is booked in on any
// day of the week, the hours a long booking runs on into, and the breaks
// between its first and last. Classes keep their own clocks (one runs 12.30 to
// 1.30, the next 1.00 to 2.00), and drawing every class's slot for every room
// would fill each grid with overlapping "free" rows that are not free time at
// all. Using the whole week rather than the day keeps a day's free periods
// visible.
function timetable_room_slots(array $t, string $room): array {
    $times = array_column($t['slots'], 'time');
    $used = [];
    foreach ($t['rooms'][$room] ?? [] as $e) {
        $i = array_search($e['time'], $times, true);
        if ($i === false) continue;
        $used[$times[$i]] = true;
        if (($e['span'] ?? 1) < 2) continue;
        // A two-hour lab needs its second hour's row even if nothing else
        // uses it. Found by the clock, not by position: with several clocks
        // in one list, the next slot is often another school's.
        $start = timetable_minutes($e['time']);
        $end = timetable_end($e);
        foreach ($t['slots'] as $s) {
            $m = timetable_minutes($s['time']);
            if (!$start || !$m || $s['break'] !== '') continue;
            if ($m[0] > $start[0] && $m[1] <= $end && $m[1] - $m[0] === 60 && ($m[0] - $start[0]) % 60 === 0) $used[$s['time']] = true;
        }
        if (!$start) {
            for ($n = 1; $n < $e['span'] && isset($times[$i + $n]) && $t['slots'][$i + $n]['break'] === ''; $n++) $used[$times[$i + $n]] = true;
        }
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

// What a room is called on the page. A room number used in two ULCs (105 in
// ULC 1 and ULC 6) is stored as "105 (ULC 1)" so the two stay apart, but the
// page has already picked the ULC, so the bracket would only repeat it.
function timetable_room_label(string $room): string {
    return preg_replace('/\s*\(ULC \d+\)$/', '', $room);
}

// The weekday a date falls on, if the timetable teaches that day, else ''. The
// official timetable repeats every week, so a date only ever picks its weekday.
function timetable_day(array $t, DateTimeImmutable $date): string {
    $day = $date->format('l');
    return in_array($day, $t['days'], true) ? $day : '';
}

// One booking per line, so two builds can still be diffed line by line, at
// half the size of pretty printing: a large file here is one the host may
// delete (see TIMETABLE_SOURCES_FILE).
function timetable_write(array $t, string $path = TIMETABLE_FILE): bool {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $head = $t;
    unset($head['rooms']);
    $rooms = [];
    foreach ($t['rooms'] ?? [] as $room => $entries) {
        $lines = array_map(fn($e) => json_encode($e, $flags), $entries);
        if (in_array(false, $lines, true)) return false;
        $rooms[] = json_encode((string) $room, $flags) . ": [\n" . implode(",\n", $lines) . "\n]";
    }
    $top = json_encode($head ?: new stdClass(), $flags);
    if ($top === false) return false;
    $json = substr($top, 0, -1) . ($head ? ',' : '') . "\n\"rooms\": {\n" . implode(",\n", $rooms) . "\n}}";
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
            // A long booking covers every row that starts before it ends,
            // another clock's rows included: the room is busy then too.
            // Labels that are not times fall back to counting rows.
            $ends = array_map('timetable_end', $entries);
            $end = in_array(null, $ends, true) ? null : min($ends);
            $within = function (int $i) use ($end, $times, $span, &$rows): bool {
                if ($end === null) return $rows < $span;
                $m = timetable_minutes($times[$i]);
                return $m !== null && $m[0] < $end;
            };
            $rows = 1;
            for ($i = $at + 1; ($span > 1 || $end === null) && $i < count($times) && $within($i) && $breaks[$times[$i]] === ''; $i++, $rows++) {
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

// Which schools a booking belongs to, as SCHOOLS keys. Read off the class
// label the build writes, since the export carries no school: a lecture shared
// by several classes ("MA Counselling Psychology; MA Politics & IR") belongs to
// each of theirs, and a "Booked" hold names its department ("SOE CSE/AIDS").
// Law before BA and BBA, or "BA LLB" would be Liberal Arts.
function timetable_schools(array $e): array {
    if ($e['blocked'] !== '') return preg_match('/^SOE\b/i', $e['blocked']) ? ['eng'] : [];
    $out = [];
    foreach (explode(';', $e['class']) as $c) {
        $c = trim($c);
        $s = match (true) {
            (bool) preg_match('/^[BM]\.\s?Tech\b/i', $c) => 'eng',
            (bool) preg_match('/^(BA LLB|BBA LLB|LLB|LLM)\b/i', $c) => 'law',
            (bool) preg_match('/^(BBA|MBA)\b/i', $c) => 'mgmt',
            (bool) preg_match('/^(BA|MA)\b/i', $c) => 'lib',
            default => '',
        };
        if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
    }
    return $out;
}

// The timetable as one school sees it: only the rooms that school teaches in,
// and in those rooms another school's class becomes a plain "Booked" hold, so
// the slot reads as taken without listing someone else's class as if it were
// the school's own. Drawing it as free would send a teacher to an occupied room.
function timetable_for_school(array $t, string $school): array {
    $rooms = [];
    foreach ($t['rooms'] as $room => $entries) {
        $mine = false;
        foreach ($entries as $i => $e) {
            if (in_array($school, timetable_schools($e), true)) {
                $mine = $mine || $e['blocked'] === '';
                continue;
            }
            $entries[$i] = ['code' => '', 'class' => '', 'classes' => [], 'faculty' => '', 'lab' => false, 'blocked' => 'Other school', 'src' => []] + $e;
        }
        if ($mine) $rooms[$room] = $entries;
    }
    $buildings = [];
    foreach ($t['buildings'] as $b => $list) {
        $in = array_values(array_filter($list, fn($r) => isset($rooms[$r])));
        if ($in) $buildings[$b] = $in;
    }
    return ['rooms' => $rooms, 'buildings' => $buildings] + $t;
}

// The attendance classes (class_rows() entries) one timetable class is, for
// the schools whose timetable names classes the way the structure does:
// Engineering and Management. Law and Liberal Arts timetable by programme
// ("BA LLB Sem III", "BA Psychology Sem I") while their attendance is kept by
// division, so they match nothing rather than a guess.
//
// A semester is half a year ("Sem V" is 3rd Year). A label with no division
// is every division of its year ("BBA Sem I" is BBA A and B). A specialisation
// ("BBA Sem V Marketing") is part of every division at once, so its reading
// would be the whole year's: it matches nothing. CSE's 3rd and 4th years are
// "SE" in the structure (their tabs are "TY SE A", "BTECH SE A"); 1st Year's
// divisions are spread over its Core and CS halves.
function timetable_attendance_classes(string $label, array $rows): array {
    $roman = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];
    $yearOf = fn(string $sem) => isset($roman[$sem]) ? [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'][intdiv($roman[$sem] + 1, 2)] . ' Year' : '';
    $want = null;   // [school, year, [branches], division or null]
    if (preg_match('/^B\.Tech First Year Div (\w+)$/', $label, $m)) {
        $want = ['eng', '1st Year', ['Core', 'CS'], $m[1]];
    } elseif (preg_match('/^B\.Tech (AIDS|CSE) Sem ([IVX]+) Div (\w+)$/', $label, $m)) {
        $year = $yearOf($m[2]);
        $want = ['eng', $year, [$m[1] === 'CSE' && $year !== '2nd Year' ? 'SE' : $m[1]], $m[3]];
    } elseif (preg_match('/^B\.Tech BT(?:-(MBT|FT|BI))? Sem ([IVX]+)$/', $label, $m)) {
        $spec = ['MBT' => 'Medical Biotechnology', 'FT' => 'Food Technology', 'BI' => 'Bioinformatics'][$m[1]] ?? null;
        $want = ['eng', $yearOf($m[2]), ['Biotechnology'], $spec];
    } elseif (preg_match('/^M\.Tech BT Sem ([IVX]+)$/', $label, $m)) {
        $want = ['eng', $yearOf($m[1]), ['M. Tech'], 'Biotechnology'];
    } elseif (preg_match('/^B\.Tech (Biomedical|ME-AE|Robotics) Sem ([IVX]+)$/', $label, $m)) {
        $want = ['eng', $yearOf($m[2]), [['ME-AE' => 'Mechanical'][$m[1]] ?? $m[1]], null];
    } elseif (preg_match('/^B\.Tech Civil Sem ([IVX]+) \(\w+-(\w)\)$/', $label, $m)) {
        $want = ['eng', $yearOf($m[1]), ['Civil Engineering'], $m[2]];
    } elseif (preg_match('/^(BBA|MBA) Sem ([IVX]+)$/', $label, $m)) {
        $want = ['mgmt', $yearOf($m[2]), [$m[1]], null];
    }
    if (!$want || $want[1] === '') return [];
    [$school, $year, $branches, $division] = $want;
    return array_values(array_filter($rows, fn($r) => $r['school'] === $school && $r['year'] === $year
        && in_array($r['branch'], $branches, true) && ($division === null || $r['division'] === $division)));
}

// How early a reading may be filed and still count for the next lecture. The
// Form offers fixed times (1:15, 2:15) where First Year's divisions start at
// 1.30 and 2.30, so a reading for the 1.30 lecture arrives as 13:15.
const TIMETABLE_EARLY = 30;

// The reading a class filed for one lecture: the latest on that date whose
// time falls between the lecture's start and end, or up to TIMETABLE_EARLY
// minutes before its start. An early one counts only if the class has no other
// lecture running then ($busy: [start, end) minutes of that class's other
// lectures that day), so one reading never shows on two lectures. Null when
// none did. $days is get_attendance_days(): date => class key => time => present.
function timetable_reading(array $days, string $date, string $key, array $entry, array $busy = []): ?int {
    $m = timetable_minutes($entry['time']);
    $end = timetable_end($entry);
    if (!$m || $end === null) return null;
    $found = null;
    $during = null;
    foreach ((array) ($days[$date][$key] ?? []) as $time => $present) {
        if (!preg_match('/^(\d{2}):(\d{2})$/', (string) $time, $t)) continue;
        $at = (int) $t[1] * 60 + (int) $t[2];
        if ($at >= $m[0] && $at < $end) {
            $during = (int) $present;
        } elseif ($at >= $m[0] - TIMETABLE_EARLY && $at < $m[0]) {
            $taken = false;
            foreach ($busy as [$a, $z]) if ($at >= $a && $at < $z) $taken = true;
            if (!$taken) $found = (int) $present;
        }
    }
    // One filed during the lecture beats one filed before it.
    return $during ?? $found;
}

// For timetable_reading()'s $busy: every class's lectures as
// class label => day => [[start, end], ...] in minutes, across all rooms.
function timetable_lectures(array $t): array {
    $out = [];
    foreach ($t['rooms'] as $entries) {
        foreach ($entries as $e) {
            $m = timetable_minutes($e['time']);
            $end = timetable_end($e);
            if (!$m || $end === null) continue;
            foreach ($e['classes'] as $c) $out[$c][$e['day']][] = [$m[0], $end];
        }
    }
    return $out;
}

// "B11:B12", the cells a citation points at as Excel names them.
function timetable_cell_name(array $cell): string {
    $col = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    };
    [$r1, $c1, $r2, $c2] = $cell;
    $a = $col($c1) . ($r1 + 1);
    $b = $col($c2) . ($r2 + 1);
    return $a === $b ? $a : "$a:$b";
}
