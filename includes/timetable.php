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
    $calSrc = [];
    if (defined('TIMETABLE_SOURCES') && TIMETABLE_SOURCES) {
        $s = store_decode((string) @file_get_contents(TIMETABLE_SOURCES_FILE)) ?? [];
        $t['docs'] = $s['docs'] ?? [];
        $src = $s['src'] ?? [];
        $calSrc = $s['calendars'] ?? [];
    }
    $t['docs'] ??= [];
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($entries as $i => $e) $t['rooms'][$room][$i]['src'] = $src[$room][$i] ?? $e['src'] ?? [];
    }
    $t['calendars'] ??= [];
    foreach ($t['calendars'] as $i => $c) $t['calendars'][$i]['src'] = $calSrc[$i] ?? $c['src'] ?? [];
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
    $calSrc = [];
    foreach ($t['calendars'] ?? [] as $i => $c) {
        if (!empty($c['src'])) $calSrc[$i] = $c['src'];
        unset($t['calendars'][$i]['src']);
    }
    $side = ['docs' => $t['docs'] ?? [], 'src' => $src, 'calendars' => $calSrc];
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

    // Semester calendars (Design): a class whose subject changes by the week,
    // so it is kept as dates rather than as bookings, and timetable_for_week()
    // draws the week asked for. Each date maps to one subject per session, by
    // its place in 'subjects', or -1 for a session the class is not in its room.
    $calendars = [];
    foreach ((array) ($raw['calendars'] ?? []) as $c) {
        if (!is_array($c) || ($class = $str($c['class'] ?? '')) === '') continue;
        $in = array_values(array_filter(array_map($str, (array) ($c['rooms'] ?? [])), fn($r) => isset($rooms[$r])));
        $sessions = [];
        foreach ((array) ($c['sessions'] ?? []) as $s) {
            $time = is_array($s) ? $str($s[0] ?? '') : '';
            $sessions[] = isset($slots[$time]) ? [$time, max(1, min(4, (int) ($s[1] ?? 1)))] : null;
        }
        $subjects = [];
        foreach ((array) ($c['subjects'] ?? []) as $s) $subjects[] = is_array($s) ? [$str($s[0] ?? ''), $str($s[1] ?? '')] : ['', ''];
        $dates = [];
        foreach ((array) ($c['dates'] ?? []) as $date => $per) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || !is_array($per)) continue;
            $dates[(string) $date] = array_map(fn($i) => is_int($i) && isset($subjects[$i]) ? $i : -1, array_values($per));
        }
        if (!$in || !$dates) continue;
        $src = (array) ($c['src'] ?? []);
        $calendars[] = [
            'class' => $class, 'rooms' => $in, 'sessions' => $sessions, 'subjects' => $subjects, 'dates' => $dates,
            'src' => isset($docs[$src['doc'] ?? '']) ? ['doc' => (string) $src['doc'], 'tab' => $str($src['tab'] ?? ''), 'cells' => (array) ($src['cells'] ?? [])] : [],
        ];
    }

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
        'calendars' => $calendars,
        'docs' => $docs,
        'deans' => timetable_parse_deans($raw['deans'] ?? []),
        'extra' => timetable_parse_extra($raw['extra'] ?? []),
    ];
}

// Teaching a person has that no timetable places at a time and room (an MDMC
// course whose hours the sheet leaves to the minor's teachers): name as the
// timetable spells it, hours a week, and what for. Added to their workload.
function timetable_parse_extra(mixed $list): array {
    $out = [];
    foreach ((array) $list as $x) {
        $name = is_array($x) && is_scalar($x['name'] ?? null) ? trim((string) $x['name']) : '';
        $hours = is_numeric($x['hours'] ?? null) ? (float) $x['hours'] : 0.0;
        if ($name === '' || $hours <= 0) continue;
        $out[] = ['person' => timetable_person($name), 'hours' => $hours, 'what' => is_scalar($x['what'] ?? null) ? trim((string) $x['what']) : ''];
    }
    return $out;
}

// A person's extra hours (timetable_parse_extra()), matched by any of their spellings.
function timetable_extra(array $t, array $person): array {
    return array_values(array_filter($t['extra'] ?? [], fn($x) => in_array($x['person'], $person['as'] ?? [], true)));
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
            'count_each_group' => !empty($d['count_each_group']),
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

// "2.30 to 4.30": when a booking actually runs, start to end, in the sheets'
// own 12-hour style. A cell that stretches over several rows (a lab across
// another class's 3.45 row) says this so it cannot be read as longer.
function timetable_span_label(array $e): string {
    $m = timetable_minutes($e['time']);
    $end = timetable_end($e);
    if (!$m || $end === null) return $e['time'];
    return timetable_clock($m[0]) . ' to ' . timetable_clock($end);
}

// Minutes from midnight as the sheets write a time: 990 is "4.30".
function timetable_clock(int $t): string {
    $h = intdiv($t, 60);
    return ($h > 12 ? $h - 12 : $h) . '.' . sprintf('%02d', $t % 60);
}

// Teaching hours in a list of bookings: each one's length by the clock, so a
// two-hour lab is two and a 45-minute slot three quarters. A booking with no
// readable time counts its span in hours. $eachGroup counts a lecture shared
// by several classes once per class: a dean whose deans.json entry says
// count_each_group (the user's call for one dean, 14 Oct 2026, whose two
// groups are written into the same lecture hour).
function timetable_hours(array $entries, bool $eachGroup = false): float {
    $min = 0;
    foreach ($entries as $e) {
        $m = timetable_minutes($e['time']);
        $end = timetable_end($e);
        $times = $eachGroup ? max(1, count($e['classes'] ?? [])) : 1;
        $min += $times * ($m && $end !== null ? max(0, $end - $m[0]) : 60 * ($e['span'] ?? 1));
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

// Everyone who teaches, for the Faculty workload picker: id => the shape of a
// dean (name, role, as, count_each_group) plus 'schools', the SCHOOLS keys of
// the classes they teach. One person is every spelling timetable_person()
// reads as the same name, shown as the spelling the sheets use most. A dean
// keeps their own entry, spellings and role, so they appear once, under their
// full name. Read from the weekly bookings and the calendars both, so the list
// does not change from week to week.
function timetable_faculty(array $t): array {
    $seen = [];
    $add = function (string $faculty, array $schools) use (&$seen) {
        foreach (preg_split('#\s*/\s*#', $faculty) as $f) {
            $f = trim(preg_replace('/\s+/', ' ', $f));
            $p = timetable_person($f);
            // "Industry faculty" is a seat to be filled, not a person.
            if (!preg_match('/[a-z]/', $p) || preg_match('/\bfaculty\b/', $p)) continue;
            $seen[$p]['spellings'][$f] = ($seen[$p]['spellings'][$f] ?? 0) + 1;
            $seen[$p]['schools'] = array_merge($seen[$p]['schools'] ?? [], $schools);
        }
    };
    foreach ($t['rooms'] as $entries) {
        foreach ($entries as $e) if ($e['blocked'] === '') $add($e['faculty'], timetable_schools($e));
    }
    foreach ($t['calendars'] ?? [] as $c) {
        foreach ($c['subjects'] as [, $f]) $add($f, timetable_schools(['blocked' => '', 'class' => $c['class']]));
    }
    $deanOf = [];
    foreach ($t['deans'] ?? [] as $id => $d) foreach ($d['as'] as $a) $deanOf[$a] = $id;
    $out = [];
    foreach ($seen as $p => $s) {
        if (isset($deanOf[$p])) {
            $id = $deanOf[$p];
            $out[$id] ??= $t['deans'][$id] + ['schools' => []];
        } else {
            $id = trim(preg_replace('/[^a-z0-9]+/', '-', $p), '-');
            arsort($s['spellings']);
            $out[$id] ??= ['name' => (string) array_key_first($s['spellings']), 'role' => '', 'as' => [], 'count_each_group' => false, 'schools' => []];
            $out[$id]['as'][] = $p;
        }
        $out[$id]['schools'] = array_values(array_unique([...$out[$id]['schools'], ...$s['schools']]));
    }
    uasort($out, fn($a, $b) => strnatcasecmp(timetable_person($a['name']), timetable_person($b['name'])));
    return $out;
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

// What a room is called on the page. A room number used in two buildings (105
// in ULC 1 and ULC 6, 201 in ULC 1 and the School of Design) is stored as
// "105 (ULC 1)" so the two stay apart, but the page has already picked the
// building, so the bracket would only repeat it.
function timetable_room_label(string $room): string {
    return preg_replace('/\s*\((ULC \d+|School of [A-Za-z ]+|Dental Building)\)$/', '', $room);
}

// The timetable as it stands in the week starting $monday: each calendar's
// sessions on that week's dates become ordinary bookings in its rooms, and the
// calendars are gone, so everything after this reads one plain week. A
// calendar timetable_for_school() turned into another school's hold is drawn
// as one. Every room stays, booked that week or not, so the pickers do not
// change from week to week (Diwali week leaves Design's rooms empty).
function timetable_for_week(array $t, DateTimeImmutable $monday): array {
    foreach ($t['calendars'] ?? [] as $c) {
        foreach ($t['days'] as $i => $day) {
            $date = $monday->modify("+$i days")->format('Y-m-d');
            foreach ($c['dates'][$date] ?? [] as $k => $s) {
                if ($s < 0 || empty($c['sessions'][$k])) continue;
                [$time, $span] = $c['sessions'][$k];
                [$code, $faculty] = $c['subjects'][$s];
                $cell = $c['src']['cells'][$date][$k] ?? null;
                $src = $c['src'] && is_array($cell) && count($cell) === 4
                    ? [['doc' => $c['src']['doc'], 'tab' => $c['src']['tab'], 'page' => 0, 'cell' => array_map('intval', $cell), 'fix' => '']]
                    : [];
                $e = ['day' => $day, 'time' => $time, 'span' => $span, 'code' => $code, 'class' => $c['class'],
                    'classes' => [$c['class']], 'faculty' => $faculty, 'lab' => false, 'blocked' => '', 'src' => $src];
                if (($c['blocked'] ?? '') !== '') $e = ['code' => '', 'class' => '', 'classes' => [], 'faculty' => '', 'blocked' => $c['blocked'], 'src' => []] + $e;
                foreach ($c['rooms'] as $room) if (isset($t['rooms'][$room])) $t['rooms'][$room][] = $e;
            }
        }
    }
    $t['calendars'] = [];
    return $t;
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
// a multi-slot entry covers, so the table can skip them, and its 'tails'. A
// span only stretches over teaching slots: a lab written across lunch ends at
// the break rather than painting over it.
//
// A long booking stretches only over rows that end by the time it does, so a
// cell is never taller than the class. A row it only partly overlaps (a lab
// to 4.30 against another clock's 3.45 to 4.45 row) is a tail: drawn as its
// own small "continues until 4.30" cell, because the room is busy then and
// must not read as free, but stretching the class over it made a two-hour lab
// look like three (found on a dean's week, 13 Oct 2026).
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
    $tails = [];
    foreach ($times as $at => $time) {
        foreach ($byCell[$time] ?? [] as $day => $entries) {
            // Two bookings in one cell (a clash in the source) share the shorter span.
            $span = min(array_column($entries, 'span'));
            // Labels that are not times fall back to counting rows.
            $ends = array_map('timetable_end', $entries);
            $end = in_array(null, $ends, true) ? null : min($ends);
            $rows = 1;
            $tail = false;   // past the first row it only partly overlaps
            for ($i = $at + 1; ($span > 1 || $end === null) && $i < count($times) && $breaks[$times[$i]] === ''; $i++) {
                $m = timetable_minutes($times[$i]);
                if ($end === null ? $rows >= $span : ($m === null || $m[0] >= $end)) break;
                // Something else booked in a slot this one claims: show both
                // rather than hide the second under a tall cell.
                if (!empty($byCell[$times[$i]][$day])) {
                    if ($tail) continue;
                    break;
                }
                // Once one row runs past the end, every later row it reaches
                // is a tail too: the tall cell cannot skip a row and go on.
                if ($tail || ($end !== null && $m[1] > $end)) {
                    $tail = true;
                    $tails[$times[$i]][$day] = ['until' => timetable_clock($end), 'lab' => $entries[0]['lab']];
                    continue;
                }
                $covered[$times[$i]][$day] = true;
                $rows++;
            }
            $cells[$time][$day] = ['entries' => $entries, 'rows' => $rows];
        }
    }
    return ['cells' => $cells, 'covered' => $covered, 'tails' => $tails];
}

// Teaching periods in use against teaching periods in the week, for the line
// under the room name.
function timetable_usage(array $t, array $grid): array {
    $teaching = array_filter($t['slots'], fn($s) => $s['break'] === '');
    $total = count($teaching) * count($t['days']);
    $used = 0;
    foreach ($teaching as $s) {
        foreach ($t['days'] as $d) {
            if (!empty($grid['cells'][$s['time']][$d]) || !empty($grid['covered'][$s['time']][$d]) || !empty($grid['tails'][$s['time']][$d])) $used++;
        }
    }
    return [$used, $total];
}

// The teaching day the Free rooms view measures, minutes from midnight: the
// earliest class starts at 8.00 and the latest ends at 5.45 (Oct 2026 data).
const TIMETABLE_OPEN = 480;
const TIMETABLE_CLOSE = 1065;

// One room's day, $open to $close, as the pieces it falls into, in order:
// ['from', 'to', 'entries'], where a piece with entries is a class (several
// when they overlap: a clash, or one lecture written for two groups) and one
// without is a gap. Worked out by the clock rather than by grid row, since
// the schools keep nine different clocks. A booking with no readable time
// cannot be placed and is left out; a long one stops at a break, as on the
// grid. Back-to-back classes stay separate pieces.
function timetable_day_runs(array $t, string $room, string $day, int $open = TIMETABLE_OPEN, int $close = TIMETABLE_CLOSE): array {
    $busy = timetable_busy($t, $room, $day, $open, $close);
    $runs = [];
    $at = $open;
    foreach ($busy as [$s, $end, $e]) {
        $last = count($runs) - 1;
        if ($last >= 0 && $runs[$last]['entries'] && $s < $runs[$last]['to']) {
            $runs[$last]['to'] = max($runs[$last]['to'], $end);
            $runs[$last]['entries'][] = $e;
        } else {
            if ($s > $at) $runs[] = ['from' => $at, 'to' => $s, 'entries' => []];
            $runs[] = ['from' => $s, 'to' => $end, 'entries' => [$e]];
        }
        $at = max($at, $runs[count($runs) - 1]['to']);
    }
    if ($at < $close) $runs[] = ['from' => $at, 'to' => $close, 'entries' => []];
    return $runs;
}

// Each booking in one room on one day as [start, end, booking] in minutes,
// sorted by start: clipped to $open and $close, and stopped at a break.
function timetable_busy(array $t, string $room, string $day, int $open = TIMETABLE_OPEN, int $close = TIMETABLE_CLOSE): array {
    $breaks = [];
    foreach ($t['slots'] as $s) if ($s['break'] !== '' && ($m = timetable_minutes($s['time']))) $breaks[] = $m[0];
    $busy = [];
    foreach ($t['rooms'][$room] ?? [] as $e) {
        if ($e['day'] !== $day || !($m = timetable_minutes($e['time']))) continue;
        $end = timetable_end($e);
        foreach ($breaks as $b) if ($b > $m[0] && $b < $end) $end = $b;
        $s = max($m[0], $open);
        $end = min($end, $close);
        if ($end > $s) $busy[] = [$s, $end, $e];
    }
    usort($busy, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return $busy;
}

// Where one room is double-booked on a day, for the Overlapping slots view:
// each stretch when two or more bookings run in it at once, as ['from', 'to',
// 'entries'] with every booking involved. By the clock, so a 9.30 lab and a
// 10.00 lecture on another school's clock overlap from 10.00 to their first
// end even though the grid draws them in different rows. A lecture written
// separately for two groups in one hour counts, as on the grid's clash outline.
function timetable_overlaps(array $t, string $room, string $day): array {
    $busy = timetable_busy($t, $room, $day);
    $pairs = [];
    foreach ($busy as $i => [$s1, $e1, $x]) {
        foreach (array_slice($busy, $i + 1) as [$s2, $e2, $y]) {
            if (min($e1, $e2) > max($s1, $s2)) $pairs[] = ['from' => max($s1, $s2), 'to' => min($e1, $e2), 'entries' => [$x, $y]];
        }
    }
    usort($pairs, fn($a, $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
    $out = [];
    foreach ($pairs as $p) {
        $last = count($out) - 1;
        // Stretches that touch are one: three classes handing over at 11.15
        // and 12.00 are one double booking from 11.00 to 12.30.
        if ($last >= 0 && $p['from'] <= $out[$last]['to']) {
            $out[$last]['to'] = max($out[$last]['to'], $p['to']);
            foreach ($p['entries'] as $e) if (!in_array($e, $out[$last]['entries'])) $out[$last]['entries'][] = $e;
        } else {
            $out[] = $p;
        }
    }
    return $out;
}

// Where one teacher is down for two rooms at once, for the Overlapping slots
// view: per person and day, each stretch they are in two places, as ['day',
// 'from', 'to', 'person' (the name as the sheets spell it most), 'entries'],
// each entry ['room' => ..., 'entry' => the booking]. One booking held in two
// rooms (AIDS's "104/105 Lab", a Design calendar across two studios) is the
// same class and subject in both, so it is not a clash.
function timetable_teacher_overlaps(array $t): array {
    $by = [];
    $spell = [];
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($t['days'] as $d) {
            foreach (timetable_busy($t, (string) $room, $d) as [$s, $z, $e]) {
                if ($e['blocked'] === '') foreach (preg_split('#\s*/\s*#', $e['faculty']) as $f) {
                    $f = trim(preg_replace('/\s+/', ' ', $f));
                    $p = timetable_person($f);
                    if (!preg_match('/[a-z]/', $p) || preg_match('/\bfaculty\b/', $p)) continue;
                    $spell[$p][$f] = ($spell[$p][$f] ?? 0) + 1;
                    $by[$p][$d][] = [$s, $z, ['room' => (string) $room, 'entry' => $e]];
                }
            }
        }
    }
    $out = [];
    foreach ($by as $p => $days) {
        arsort($spell[$p]);
        $name = (string) array_key_first($spell[$p]);
        foreach ($days as $d => $list) {
            $pairs = [];
            foreach ($list as $i => [$s1, $e1, $x]) {
                foreach (array_slice($list, $i + 1) as [$s2, $e2, $y]) {
                    if ($x['room'] === $y['room'] || min($e1, $e2) <= max($s1, $s2)) continue;
                    if ($x['entry']['class'] === $y['entry']['class'] && $x['entry']['code'] === $y['entry']['code']) continue;
                    $pairs[] = ['from' => max($s1, $s2), 'to' => min($e1, $e2), 'entries' => [$x, $y]];
                }
            }
            usort($pairs, fn($a, $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
            $mine = [];
            foreach ($pairs as $q) {
                $last = count($mine) - 1;
                if ($last >= 0 && $q['from'] <= $mine[$last]['to']) {
                    $mine[$last]['to'] = max($mine[$last]['to'], $q['to']);
                    foreach ($q['entries'] as $x) if (!in_array($x, $mine[$last]['entries'])) $mine[$last]['entries'][] = $x;
                } else {
                    $mine[] = ['day' => $d, 'person' => $name] + $q;
                }
            }
            array_push($out, ...$mine);
        }
    }
    usort($out, fn($a, $b) => [array_search($a['day'], $t['days'], true), $a['from'], $a['person']] <=> [array_search($b['day'], $t['days'], true), $b['from'], $b['person']]);
    return $out;
}

// One room's day as minutes: when it is booked (back-to-back and overlapping
// classes merged into one stretch) and the gaps between.
function timetable_free(array $t, string $room, string $day, int $open = TIMETABLE_OPEN, int $close = TIMETABLE_CLOSE): array {
    $busy = [];
    $free = [];
    foreach (timetable_day_runs($t, $room, $day, $open, $close) as $r) {
        if (!$r['entries']) { $free[] = [$r['from'], $r['to']]; continue; }
        $last = count($busy) - 1;
        if ($last >= 0 && $busy[$last][1] === $r['from']) $busy[$last][1] = $r['to'];
        else $busy[] = [$r['from'], $r['to']];
    }
    return ['busy' => $busy, 'free' => $free];
}

// A room's grid rows with the rest of the teaching day filled in, for the Free
// rooms view: timetable_room_slots() draws only the hours the room is booked
// in, so 8.00 to 9.30 in a room whose first class is at 9.30 had no row to
// show it free. Each stretch no row covers, $least minutes or longer, becomes
// a row of its own, and the rows come back in time order.
function timetable_slots_with_gaps(array $slots, int $least = 30, int $open = TIMETABLE_OPEN, int $close = TIMETABLE_CLOSE): array {
    $spans = [];
    foreach ($slots as $s) if ($m = timetable_minutes($s['time'])) $spans[] = $m;
    sort($spans);
    $at = $open;
    $gaps = [];
    foreach ([...$spans, [$close, $close]] as [$s, $end]) {
        if ($s - $at >= $least) $gaps[] = ['time' => timetable_clock($at) . ' to ' . timetable_clock($s), 'break' => ''];
        $at = max($at, $end);
    }
    $rows = [...$slots, ...$gaps];
    usort($rows, fn($a, $b) => (timetable_minutes($a['time']) ?? [0, 0]) <=> (timetable_minutes($b['time']) ?? [0, 0]));
    return $rows;
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
            (bool) preg_match('/^[BM]\.\s?Des\b/i', $c) => 'design',
            (bool) preg_match('/^[BM]\.\s?Sc\b/i', $c) => 'science',
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
    // A calendar's rooms are the school's whether or not this week has a
    // session in them; another school's calendar becomes its hold.
    $calRooms = [];
    foreach ($t['calendars'] ?? [] as $i => $c) {
        if (in_array($school, timetable_schools(['blocked' => '', 'class' => $c['class']]), true)) {
            foreach ($c['rooms'] as $r) $calRooms[$r] = true;
        } else {
            $t['calendars'][$i]['blocked'] = 'Other school';
        }
    }
    $rooms = [];
    foreach ($t['rooms'] as $room => $entries) {
        $mine = isset($calRooms[$room]);
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
// Engineering, Management, Design and Science. Law and Liberal Arts timetable by programme
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
    } elseif (preg_match('/^B\.Tech (AIDS|CSE|CS) Sem ([IVX]+) Div (\w+)$/', $label, $m)) {
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
    } elseif (preg_match('/^B\.Sc Sem ([IVX]+)$/', $label, $m)) {
        // Science's lab divisions (Div A to C) are batches of one B.Sc. year,
        // which the structure keeps as a single division.
        $want = ['science', $yearOf($m[1]), ['B.Sc.'], null];
    } elseif ($label === 'B.Des Foundation Sem I') {
        // Every B.Des programme's first year sits the common foundation.
        $want = ['design', '1st Year', ['B Des Fashion Design', 'B Des Interior Design', 'B Des Product Design',
            'B Des Transportation Design', 'B Des Visual Communication Design', 'B Des UI/UX'], null];
    } elseif (preg_match('/^([BM])\.Des (.+?)(?: Design)? Sem ([IVX]+)$/', $label, $m)) {
        // "B.Des UI/UX Design Sem V" is "B Des UI/UX" and "M.Des
        // Transportation Design" is "M Des Transportation"; every other
        // programme keeps its "Design".
        $branch = "{$m[1]} Des " . ($m[2] === 'UI/UX' || $m[1] === 'M' ? $m[2] : "{$m[2]} Design");
        $want = ['design', $yearOf($m[3]), [$branch], null];
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
// minutes before its start. Null when none did. $days is get_attendance_days():
// date => class key => time => present.
//
// One reading never shows on two lectures. When another of the class's
// lectures that day ($busy: [start, end) minutes, this one may be among them)
// could claim it too, it goes to the lecture its hour overlaps most: the
// Form's "13:15 - 14:15" is the 1.30 lecture, not the 12.30 one it starts in
// (found 22 Oct 2026, a dean's 1.30 and 2.30 lectures read as not marked while
// the lectures before them carried his numbers). A tie stays with the lecture
// the reading starts in.
function timetable_reading(array $days, string $date, string $key, array $entry, array $busy = []): ?int {
    $m = timetable_minutes($entry['time']);
    $end = timetable_end($entry);
    if (!$m || $end === null) return null;
    $overlap = fn(int $at, int $a, int $z): int => max(0, min($at + 60, $z) - max($at, $a));
    $found = null;
    $during = null;
    $allDay = null;
    foreach ((array) ($days[$date][$key] ?? []) as $time => $present) {
        // WHOLE_DAY in includes/attendance.php, which this file does not load.
        if ((string) $time === 'day') $allDay = (int) $present;
        if (!preg_match('/^(\d{2}):(\d{2})$/', (string) $time, $t)) continue;
        $at = (int) $t[1] * 60 + (int) $t[2];
        if ($at < $m[0] - TIMETABLE_EARLY || $at >= $end) continue;
        $in = $at >= $m[0];
        $mine = $overlap($at, $m[0], $end);
        $taken = false;
        foreach ($busy as [$a, $z]) {
            if (($a === $m[0] && $z === $end) || $at < $a - TIMETABLE_EARLY || $at >= $z) continue;
            $theirs = $overlap($at, $a, $z);
            if ($theirs > $mine || ($theirs === $mine && $at >= $a && !$in)) $taken = true;
        }
        if ($taken) continue;
        if ($in) $during = (int) $present;
        else $found = (int) $present;
    }
    // One filed during the lecture beats one filed before it, and either beats
    // a reading for the whole day, which stands for every lecture that day.
    return $during ?? $found ?? $allDay;
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
