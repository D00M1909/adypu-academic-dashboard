<?php
// One room on one day (or its whole week): which class, subject and teacher is
// in it each period. Picked by school, then date, then ULC, then classroom.
// Or one dean's week instead: every class they teach, in whichever room.
// Or Free slots: the same room grid with every free stretch highlighted.
// Either can show every room at once (all=1): one day, a row per room.
// The same view as By Room on the timetable generator, drawn from
// timetable/rooms.php (see includes/timetable.php for why it is a file).
// Open to everyone and read-only, like the dashboard.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/timetable.php';
require_once __DIR__ . '/includes/structure.php';

auth_boot();
traffic_hit('rooms');

$t = timetable_read();
// The school picker lists only schools the timetable has classes for, in
// SCHOOLS order. Picking one narrows the ULCs and rooms to where that school
// teaches; no school (the default) is every room.
$schools = [];
foreach ($t['rooms'] ?? [] as $entries) {
    foreach ($entries as $x) foreach (timetable_schools($x) as $k) $schools[$k] = true;
}
foreach ($t['calendars'] ?? [] as $c) foreach (timetable_schools(['blocked' => '', 'class' => $c['class']]) as $k) $schools[$k] = true;
$schools = array_filter(SCHOOLS, fn($k) => isset($schools[$k]), ARRAY_FILTER_USE_KEY);
$school = (string) ($_GET['school'] ?? '');
if (!isset($schools[$school])) $school = '';

// No date means today in India, which is what a phone opened at a classroom
// door wants. Most of the timetable repeats weekly, so only the weekday
// matters to it; a dated booking (Design's) shows only in the weeks it runs.
$tz = new DateTimeZone('Asia/Kolkata');
$date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['date'] ?? ''), $tz) ?: new DateTimeImmutable('today', $tz);
$monday = $date->modify('monday this week');
// Every class's lectures across every room, taken before a school is picked
// (which hides other schools' classes), for matching attendance to lectures.
$fullWeek = $t ? timetable_for_week($t, $monday) : [];
$lectures = $t ? timetable_lectures($fullWeek) : [];
// A dean is a person, not a place: their week spans rooms and schools, so the
// school, ULC and room pickers stand aside while one is picked. A dean with
// no classes in the timetable is still a choice and shows an empty week.
$deans = $t['deans'] ?? [];
$deanId = (string) ($_GET['dean'] ?? '');
$dean = $deans[$deanId] ?? null;
if (!$dean) $deanId = '';
if ($dean) $school = '';
$view = $dean ? '' : (string) ($_GET['view'] ?? '');
// Faculty workload is the dean's week for anyone who teaches, picked from
// everyone the timetable names. The school only narrows that list: the
// person's week still spans every school they teach in.
$facultyView = $t && $view === 'faculty';
$faculty = $facultyView ? timetable_faculty($t) : [];
$shownFaculty = $school === '' ? $faculty : array_filter($faculty, fn($p) => in_array($school, $p['schools'], true));
if ($facultyView && !$shownFaculty) {
    $school = '';
    $shownFaculty = $faculty;
}
$facultyId = (string) ($_GET['faculty'] ?? '');
if (!isset($shownFaculty[$facultyId])) $facultyId = (string) (array_key_first($shownFaculty) ?? '');
// Whose week is drawn: a dean's or a faculty member's, or nobody's (a room).
$person = $dean ?? ($facultyView ? $shownFaculty[$facultyId] ?? null : null);
$facultyView = $facultyView && $person;
// Free slots is the room view with its free time marked, for a teacher
// looking for somewhere to hold an extra class.
$free = !$person && $view === 'free';
// Overlapping slots is the room view with every double booking marked: two
// classes in one room at once, or one teacher in two rooms at once, either of
// which someone has to move.
$overlap = !$person && $view === 'overlap';
// All rooms: every room's day side by side, a row each, for seeing the whole
// campus (or one building) at a glance. One day at a time: a week of ninety
// rooms is too long a page to read.
$all = !$person && ($_GET['all'] ?? '') === '1';
// A school's rooms are the ones it teaches in at all, so they are picked
// before the week drops the bookings that do not run in it.
if ($t && $school !== '' && !$person) $t = timetable_for_school($t, $school);
if ($t) $t = timetable_for_week($t, $monday);
$teaching = $person ? timetable_teaching($t, $person) : [];
$buildings = $t['buildings'] ?? [];
// Pick order is the one the request asked for: date, then ULC, then room. Each
// falls back to the first valid choice, so changing the ULC with a room from
// the old one still selected lands on the new ULC's first room.
$ulc = (string) ($_GET['ulc'] ?? '');

// The whole week unless week=0 asks for one day: a room's week is what most
// people open it for.
$week = !$all && ($_GET['week'] ?? '1') !== '0';
$day = $t ? timetable_day($t, $date) : '';
// On Overlapping slots, each room's double bookings over the days shown, and
// every teacher down for two rooms at once: the room picker counts both, and
// opening the view with no building or room picked lands on the first that
// has one rather than on a clean room. Teachers are found across the whole
// timetable, since picking a school hides other schools' classes and a
// teacher's other room may be one of theirs; a school keeps the clashes
// that involve one of its own classes.
$roomOverlaps = [];
$teacherOverlaps = [];
$teacherAt = [];
$roomHits = [];
if ($overlap) {
    $shownDays = $week ? $t['days'] : ($day !== '' ? [$day] : []);
    foreach (timetable_teacher_overlaps($fullWeek) as $o) {
        if (!in_array($o['day'], $shownDays, true)) continue;
        if ($school !== '' && !array_filter($o['entries'], fn($x) => in_array($school, timetable_schools($x['entry']), true))) continue;
        $teacherOverlaps[] = $o;
        foreach (array_unique(array_column($o['entries'], 'room')) as $r) $teacherAt[$r][] = $o;
    }
    foreach ($buildings as $list) {
        foreach ($list as $r) {
            $roomOverlaps[$r] = [];
            foreach ($week ? $t['days'] : ($day !== '' ? [$day] : []) as $d) {
                foreach (timetable_overlaps($t, $r, $d) as $o) $roomOverlaps[$r][] = ['day' => $d] + $o;
            }
            $roomHits[$r] = count($roomOverlaps[$r]) + count($teacherAt[$r] ?? []);
        }
    }
    if (!isset($buildings[$ulc]) && !$all) {
        foreach ($buildings as $b => $list) if (array_filter($list, fn($r) => $roomHits[$r])) { $ulc = (string) $b; break; }
    }
}
// All rooms can span every building: no ULC means all of them.
if (!isset($buildings[$ulc])) $ulc = $all ? '' : (string) (array_key_first($buildings) ?? '');
$rooms = $buildings[$ulc] ?? [];
$room = (string) ($_GET['room'] ?? '');
if ($overlap && !in_array($room, $rooms, true)) {
    foreach ($rooms as $r) if ($roomHits[$r]) { $room = $r; break; }
}
if (!in_array($room, $rooms, true)) $room = $rooms[0] ?? '';

$e = fn(string $v): string => htmlspecialchars($v);
$when = function (string $stamp): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $stamp);
    return $d ? $d->format('j M Y') : $stamp;
};
// Every link keeps the view it came from: the room (or the person), the date,
// and the one-day view when that is what is showing. An $over value of null
// drops that key.
$here = match (true) {
    (bool) $dean => ['dean' => $deanId],
    $facultyView => ['view' => 'faculty', 'school' => $school, 'faculty' => $facultyId],
    default => ['view' => $free ? 'free' : ($overlap ? 'overlap' : null), 'all' => $all ? '1' : null, 'school' => $school, 'ulc' => $ulc, 'room' => $all ? null : $room],
};
$link = fn(array $over): string => 'rooms.php?' . http_build_query(array_filter($over + $here
    + ['date' => $date->format('Y-m-d')] + ($week || $all ? [] : ['week' => '0']), fn($v) => $v !== null && $v !== ''));
// A room view's tab, from wherever the page is: a person's week opens the
// room view on the same school.
$roomTab = fn(?string $v): string => $link(['dean' => null, 'faculty' => null, 'view' => $v] + ($person ? ['school' => $school, 'ulc' => $ulc, 'room' => $room] : []));
// The step arrows move a week at a time, or a day on the one-day view.
$step = $week ? '7 days' : '1 day';
$prev = $date->modify("-$step")->format('Y-m-d');
$next = $date->modify("+$step")->format('Y-m-d');
$today = new DateTimeImmutable('today', $tz);
$weekLabel = $week ? 'Week of ' . $monday->format('j M') : $date->format('l j M');
$weekSub = $week ? $monday->format('j') . ' to ' . $monday->modify('+4 days')->format('j M Y') : $date->format('Y');
// "105 (Digital Library / Digital Lab)" heads the page as "105", with the name
// beside it in plain text; a room with no number is its name.
$roomParts = function (string $r): array {
    $r = timetable_room_label($r);
    return preg_match('/^(\S+) \((.+)\)$/', $r, $m) ? [$m[1], $m[2]] : [$r, ''];
};

page_head('Room timetable', 'page-wide', 'timetable');
?>
<?php if (empty($t['rooms'])): ?>
  <section class="card tt-empty">
    <h2>No timetable yet</h2>
    <p class="field-help">The room timetable appears here once the official timetable has been added.</p>
  </section>
<?php else:
    // The day view is the week view with one column: the grid, the spans and
    // the usage count all work per day already.
    $days = $week ? $t['days'] : ($day !== '' ? [$day] : []);
    // A dean's week is drawn as one room holding all their classes, so the
    // grid, the spans and the clash outline (two classes at once) all apply.
    $key = $person ? '@person' : $room;
    $from = $person ? ['rooms' => [$key => $teaching]] + $t : $t;
    $slots = timetable_room_slots($from, $key);
    // Free slots draws the whole teaching day, 8.00 to 5.45, so the hours
    // before the room's first class and between its clocks show too.
    if ($free) $slots = timetable_slots_with_gaps($slots);
    $shown = ['days' => $days, 'slots' => $slots] + $from;
    $grid = timetable_grid($shown, $key);
    // Free time by the clock rather than by row: an empty row can still be
    // half taken by a class on another school's clock. Stretches under half an
    // hour are left out, as they are no use to anyone.
    $gaps = [];
    $freeMins = 0;
    foreach ($free ? $days : [] as $d) {
        $gaps[$d] = array_values(array_filter(timetable_free($shown, $key, $d)['free'], fn($g) => $g[1] - $g[0] >= 30));
        foreach ($gaps[$d] as $g) $freeMins += $g[1] - $g[0];
    }
    $freeHours = fn(int $m): string => $m % 60 ? number_format($m / 60, 1) : (string) intdiv($m, 60);
    // What an empty cell says: Free when the whole row is, the part that is
    // when only some of it is, nothing when none of it is.
    $openIn = function (string $time, string $d) use ($gaps): ?array {
        $m = timetable_minutes($time);
        $best = null;
        foreach ($m ? $gaps[$d] ?? [] : [] as $g) {
            $s = max($g[0], $m[0]);
            $end = min($g[1], $m[1]);
            if ($end > $s && (!$best || $end - $s > $best[1] - $best[0])) $best = [$s, $end];
        }
        if (!$best) return null;
        return ['whole' => $best == $m, 'label' => $best == $m ? 'Free' : 'Free ' . timetable_clock($best[0]) . ' to ' . timetable_clock($best[1])];
    };
    $hasPart = false;
    [$used, $total] = timetable_usage($shown, $grid);
    $classesShown = count(array_filter($teaching, fn($x) => in_array($x['day'], $days, true)));
    // A dean's workload is always the whole week's teaching hours, even on
    // the one-day view: it is a weekly figure, and a day's would read as one.
    $load = timetable_hours($teaching, $person['count_each_group'] ?? false);
    $hours = $load == round($load) ? (string) (int) $load : number_format($load, 1);
    $hasClash = false;
    $hasBlocked = false;
    $clashes = 0;
    $attShown = false;
    foreach ($grid['cells'] as $byDay) foreach ($byDay as $c) if (count($c['entries']) > 1) $clashes++;
    // A small building's rooms are chips, one tap each, with how full each
    // is this week; a big one (ULC 1 has 50) keeps the dropdown.
    $chips = [];
    if (!$person && !$all && count($rooms) <= 16) {
        foreach ($rooms as $r) {
            $v = ['days' => $days, 'slots' => timetable_room_slots($t, $r)] + $t;
            [$u, $n] = timetable_usage($v, timetable_grid($v, $r));
            $chips[$r] = $n ? $u / $n : 0;
        }
    }
    // On Free slots every room in the picker says how much free time it has,
    // so the emptiest can be found without opening each one.
    $roomFree = [];
    foreach ($free ? $rooms : [] as $r) {
        $m = 0;
        foreach ($days as $d) foreach (timetable_free($t, $r, $d)['free'] as $g) if ($g[1] - $g[0] >= 30) $m += $g[1] - $g[0];
        $roomFree[$r] = $m;
    }
    $dayMins = count($days) * (TIMETABLE_CLOSE - TIMETABLE_OPEN);
    // On Overlapping slots, the overlaps a grid cell's bookings are part of,
    // so the cell can say when the overlap runs (it may start mid-period).
    $mine = [];
    foreach ($roomOverlaps[$room] ?? [] as $o) $mine[$o['day']][] = $o;
    // What a cell's bookings clash with, one line per stretch: the bookings
    // drawn in other rows (another school's clock) by name, since the cell
    // does not show them, and a plain "Double-booked" when the clash is
    // between bookings the cell already shows. Only the minutes this cell's
    // own bookings are in, never the room's whole double-booked stretch.
    $clashLines = function (array $entries, string $d) use ($t, $room): array {
        $busy = timetable_busy($t, $room, $d);
        $mine = array_values(array_filter($busy, fn($b) => in_array($b[2], $entries)));
        $with = [];
        $inside = null;
        foreach ($busy as [$s, $z, $y]) {
            foreach ($mine as [$s1, $z1, $x]) {
                if ($x == $y || min($z, $z1) <= max($s, $s1)) continue;
                $range = [max($s, $s1), min($z, $z1)];
                if (in_array($y, $entries)) {
                    $inside = $inside ? [min($inside[0], $range[0]), max($inside[1], $range[1])] : $range;
                    continue;
                }
                $name = $y['blocked'] !== '' ? $y['blocked'] : trim($y['code'] . ($y['class'] !== '' ? " ({$y['class']})" : ''));
                $key = implode('-', $range);
                $with[$key]['range'] = $range;
                if (!in_array($name, $with[$key]['names'] ?? [], true)) $with[$key]['names'][] = $name;
                break;
            }
        }
        ksort($with);
        $clock = fn(array $r): string => timetable_clock($r[0]) . ' to ' . timetable_clock($r[1]);
        $lines = array_map(fn($w) => 'Clashes ' . $clock($w['range']) . ' with ' . implode(' and ', $w['names']), array_values($with));
        if ($inside) array_unshift($lines, 'Double-booked ' . $clock($inside));
        return $lines;
    };
    $overlapsOf = fn(array $entries, string $d): array => array_values(array_filter($mine[$d] ?? [],
        fn($o) => (bool) array_filter($entries, fn($x) => in_array($x, $o['entries']))));
    // And the teacher clashes a room's bookings are part of, with where else
    // the teacher is meant to be: "Also in 208, ULC 1".
    $teacherClash = fn(string $r, array $entries, string $d): array => array_values(array_filter($teacherAt[$r] ?? [],
        fn($o) => $o['day'] === $d && array_filter($o['entries'], fn($y) => $y['room'] === $r && in_array($y['entry'], $entries))));
    $fullUlc = [];
    foreach ($fullWeek['buildings'] ?? [] as $b => $list) foreach ($list as $r) $fullUlc[(string) $r] = (string) $b;
    $whereElse = fn(array $o, string $r): string => implode(' and ', array_unique(array_map(fn($y) => timetable_room_label($y['room']) . ', ' . ($fullUlc[$y['room']] ?? ''),
        array_filter($o['entries'], fn($y) => $y['room'] !== $r))));
    $clashNote = fn(array $o, string $r): string => $o['person'] . ' also in ' . $whereElse($o, $r) . ', ' . timetable_clock($o['from']) . ' to ' . timetable_clock($o['to']);
    $mostOverlaps = max([1, ...array_values($roomHits)]);
    $plural = fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    // Which column is which date, for the day headings: the week's dates, or
    // the one day picked.
    $dateOf = fn(string $d): DateTimeImmutable => $week ? $monday->modify('+' . (int) array_search($d, $t['days'], true) . ' days') : $date;

    // Attendance for each class in each cell, on the real date that column
    // stands for: the week's Monday plus the weekday, or the one day picked.
    // Only classes timetable_attendance_classes() can name get a line, and a
    // lecture that has not started yet gets none, so "Not marked" always means
    // the lecture happened and nobody filed it.
    $attDays = get_attendance_days();
    $attRows = class_rows();
    $now = new DateTimeImmutable('now', $tz);
    $attendance = function (array $x, string $d) use ($attDays, $attRows, $week, $monday, $date, $now, $t, $lectures): ?array {
        $on = $week ? $monday->modify('+' . (int) array_search($d, $t['days'], true) . ' days') : $date;
        $m = timetable_minutes($x['time']);
        if (!$m || $on->setTime(intdiv($m[0], 60), $m[0] % 60) > $now) return null;
        $list = [];
        foreach ($x['classes'] as $label) {
            foreach (timetable_attendance_classes($label, $attRows) as $r) {
                $key = class_key($r);
                $list[$key] ??= [
                    'name' => trim("{$r['year']} {$r['branch']} {$r['division']}"),
                    'present' => timetable_reading($attDays, $on->format('Y-m-d'), $key, $x, $lectures[$label][$d] ?? []),
                    'strength' => (int) $r['strength'],
                ];
            }
        }
        if (!$list) return null;
        $in = array_filter($list, fn($c) => $c['present'] !== null);
        return [
            'date' => $on->format('l j M Y'),
            'classes' => array_values($list),
            'present' => array_sum(array_column($in, 'present')),
            'strength' => array_sum(array_column($in, 'strength')),
            'marked' => count($in),
        ];
    };

    // What the panel opened from a class shows, one entry per booking drawn:
    // the class, its attendance, and where in which document it is written.
    $how = [
        'direct' => 'Read straight from this sheet when the timetable was built.',
        'script' => 'Read from this sheet by a converter script, then checked.',
        'hand' => 'Typed in by hand from this document, then checked.',
    ];
    $panel = [];
    // The Source part only where TIMETABLE_SOURCES is on (config.php): null
    // tells js/rooms.js to leave it out, where [] says no document is on file.
    $describe = function (array $x, string $d, ?array $att) use (&$panel, $t, $how, $room, $ulc): int {
        $src = TIMETABLE_SOURCES ? [] : null;
        foreach (TIMETABLE_SOURCES ? $x['src'] : [] as $s) {
            $doc = $t['docs'][$s['doc']];
            $pdf = $doc['kind'] === 'pdf';
            $src[] = [
                'name' => $doc['name'],
                'where' => $pdf ? "Page {$s['page']}" : ($s['tab'] !== '' ? "Tab \"{$s['tab']}\"" . ($s['cell'] ? ', cell ' . timetable_cell_name($s['cell']) : '') : ''),
                'how' => $how[$doc['how']],
                'fix' => $s['fix'],
                'open' => 'source.php?' . http_build_query(['doc' => $s['doc']] + ($pdf ? ['page' => $s['page']] : ['tab' => $s['tab']] + ($s['cell'] ? ['cell' => implode(',', $s['cell'])] : []))),
                'file' => 'timetable/' . $doc['file'],
            ];
        }
        $panel[] = [
            'title' => $x['blocked'] !== '' ? $x['blocked'] . ' (booked)' : $x['code'],
            'class' => $x['class'],
            'faculty' => $x['faculty'],
            'when' => "$d, {$x['time']}" . ($x['span'] > 1 ? " ({$x['span']} hours)" : '') . ' in ' . timetable_room_label($x['room'] ?? $room) . ', ' . ($x['ulc'] ?? $ulc),
            'att' => $att,
            'src' => $src,
        ];
        return count($panel) - 1;
    };
?>
  <section class="card tt-card">
    <form class="tt-bar no-print" method="get">
      <div class="tt-bar-row">
        <?php // Rooms or one person's week: a mode, so each shows only its own picker. ?>
        <nav class="tt-seg" aria-label="View">
          <a href="<?= $e($roomTab(null)) ?>"<?= $person || $free || $overlap ? '' : ' aria-current="page"' ?>>Rooms</a>
          <a href="<?= $e($roomTab('free')) ?>"<?= $free ? ' aria-current="page"' : '' ?>>Free slots</a>
          <?php // Overlapping slots opens wherever the first overlap is, so it carries no room over. ?>
          <a href="<?= $e($overlap ? $link([]) : $link(['dean' => null, 'faculty' => null, 'view' => 'overlap', 'ulc' => null, 'room' => null, 'school' => $school])) ?>"<?= $overlap ? ' aria-current="page"' : '' ?>>Overlapping slots</a>
          <?php if ($deans): ?><a href="<?= $e($dean ? $link([]) : 'rooms.php?' . http_build_query(['dean' => array_key_first($deans), 'date' => $date->format('Y-m-d')] + ($week ? [] : ['week' => '0']))) ?>"<?= $dean ? ' aria-current="page"' : '' ?>>Dean workload</a><?php endif; ?>
          <a href="<?= $e($facultyView ? $link([]) : 'rooms.php?' . http_build_query(['view' => 'faculty'] + ($school !== '' ? ['school' => $school] : []) + ['date' => $date->format('Y-m-d')] + ($week ? [] : ['week' => '0']))) ?>"<?= $facultyView ? ' aria-current="page"' : '' ?>>Faculty workload</a>
        </nav>
        <div class="tt-week">
          <a class="tt-step" href="<?= $e($link(['date' => $prev])) ?>" title="<?= $week ? 'Previous week' : 'Previous day' ?>" aria-label="<?= $week ? 'Previous week' : 'Previous day' ?>">&#8249;</a>
          <?php // The label opens the date picker for jumping further than a step. ?>
          <label class="tt-week-label" for="tt-date" title="Pick a date"><?= $e($weekLabel) ?><small><?= $e($weekSub) ?></small>
            <input id="tt-date" type="date" name="date" value="<?= $date->format('Y-m-d') ?>" onchange="this.form.submit()" tabindex="-1">
          </label>
          <a class="tt-step" href="<?= $e($link(['date' => $next])) ?>" title="<?= $week ? 'Next week' : 'Next day' ?>" aria-label="<?= $week ? 'Next week' : 'Next day' ?>">&#8250;</a>
          <a class="tt-today" href="<?= $e($link(['date' => $today->format('Y-m-d')])) ?>">Today</a>
          <?php if ($all): ?>
          <?php // All rooms shows one day: the week's days are a tap each instead of Week / Day. ?>
          <nav class="tt-seg" aria-label="Day">
            <?php foreach ($t['days'] as $i => $d): ?>
              <a href="<?= $e($link(['date' => $monday->modify("+$i days")->format('Y-m-d')])) ?>"<?= $d === $day ? ' aria-current="page"' : '' ?> title="<?= $e($d . ' ' . $monday->modify("+$i days")->format('j M')) ?>"><?= $e(substr($d, 0, 3)) ?></a>
            <?php endforeach; ?>
          </nav>
          <?php else: ?>
          <nav class="tt-seg" aria-label="Span">
            <a href="<?= $e($link(['week' => null])) ?>"<?= $week ? ' aria-current="page"' : '' ?>>Week</a>
            <a href="<?= $e($link(['week' => '0'])) ?>"<?= $week ? '' : ' aria-current="page"' ?>>Day</a>
          </nav>
          <?php endif; ?>
        </div>
      </div>
      <div class="tt-bar-row">
      <?php if ($dean): ?>
        <div class="tt-pick">
          <label for="tt-dean">Dean</label>
          <select id="tt-dean" name="dean" onchange="this.form.submit()" data-combo>
            <?php foreach ($deans as $id => $p): ?>
              <option value="<?= $e($id) ?>"<?= $id === $deanId ? ' selected' : '' ?>><?= $e($p['name']) ?><?= $p['role'] !== '' ? ', ' . $e($p['role']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php elseif ($facultyView): ?>
        <input type="hidden" name="view" value="faculty">
        <div class="tt-pick">
          <label for="tt-school">School</label>
          <select id="tt-school" name="school" onchange="this.form.submit()">
            <option value="">All schools</option>
            <?php foreach ($schools as $k => $s): ?>
              <option value="<?= $e($k) ?>"<?= $k === $school ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tt-pick">
          <label for="tt-faculty">Faculty</label>
          <select id="tt-faculty" name="faculty" onchange="this.form.submit()" data-combo>
            <?php foreach ($shownFaculty as $id => $p): ?>
              <option value="<?= $e($id) ?>"<?= $id === $facultyId ? ' selected' : '' ?>><?= $e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <div class="tt-pick">
          <label for="tt-school">School</label>
          <select id="tt-school" name="school" onchange="this.form.submit()">
            <option value="">All schools</option>
            <?php foreach ($schools as $k => $s): ?>
              <option value="<?= $e($k) ?>"<?= $k === $school ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tt-pick">
          <label for="tt-ulc">Building</label>
          <select id="tt-ulc" name="ulc" onchange="this.form.submit()">
            <?php if ($all): ?><option value="">All buildings</option><?php endif; ?>
            <?php foreach (array_keys($buildings) as $b): $b = (string) $b; ?>
              <option value="<?= $e($b) ?>"<?= $b === $ulc ? ' selected' : '' ?>><?= $e($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($free || $overlap): ?><input type="hidden" name="view" value="<?= $free ? 'free' : 'overlap' ?>"><?php endif; ?>
        <?php if ($all): ?>
        <?php elseif (!$chips): ?>
        <div class="tt-pick">
          <label for="tt-room">Room</label>
          <select id="tt-room" name="room" onchange="this.form.submit()" data-combo<?= $rooms ? '' : ' disabled' ?>>
            <?php if (!$rooms): ?><option>None yet</option><?php endif; ?>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= $e($r) ?>"<?= $r === $room ? ' selected' : '' ?>><?= $e(timetable_room_label($r)) ?><?= $free ? ' &middot; ' . $freeHours($roomFree[$r]) . ' h free' : '' ?><?= $overlap && $roomHits[$r] ? ' &middot; ' . $plural($roomHits[$r], 'overlap', 'overlaps') : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="room" value="<?= $e($room) ?>">
        <?php endif; ?>
        <?php // Turning it on opens every building; the Building picker narrows it again. ?>
        <label class="tt-switch">
          <input type="checkbox" name="all" value="1" onchange="if (this.checked) this.form.ulc.selectedIndex = -1; this.form.submit()"<?= $all ? ' checked' : '' ?>>
          <span class="tt-switch-track" aria-hidden="true"></span>All rooms
        </label>
      <?php endif; ?>
      </div>
      <?php if ($chips): ?>
      <ul class="tt-chips" aria-label="Rooms in <?= $e($ulc) ?>">
        <?php foreach ($chips as $r => $full): [$num, $name] = $roomParts($r);
            if ($free) $full = $dayMins ? $roomFree[$r] / $dayMins : 0;
            if ($overlap) $full = $roomHits[$r] / $mostOverlaps; ?>
          <li><a href="<?= $e($link(['room' => $r])) ?>"<?= $r === $room ? ' aria-current="page"' : '' ?> title="<?= $overlap ? $plural($roomHits[$r], 'overlap', 'overlaps') : ($free ? $freeHours($roomFree[$r]) . ' hours free' : round($full * 100) . '% booked') ?> <?= $week ? 'this week' : 'this day' ?>"><?= $e($num) ?><?php if ($name !== ''): ?><small><?= $e($name) ?></small><?php endif; ?><span class="tt-chip-bar<?= $free ? ' tt-chip-free' : ($overlap ? ' tt-chip-overlap' : '') ?>"><i style="width:<?= round($full * 100) ?>%"></i></span></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if (!$week && !$all): ?><input type="hidden" name="week" value="0"><?php endif; ?>
      <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
    </form>

<?php if ($all):
    // Every room's day as one row. The columns are quarter hours from 8.00 to
    // 5.45, so a class sits where it really starts and ends whatever clock
    // its school keeps, and spans as many columns as it lasts.
    $slice = 15;
    $cols = intdiv(TIMETABLE_CLOSE - TIMETABLE_OPEN, $slice);
    $col = fn(int $m): int => (int) round(($m - TIMETABLE_OPEN) / $slice);
    $board = [];
    $bookedMins = 0;
    $openMins = 0;
    $roomCount = 0;
    $overlapsShown = 0;
    $teachersShown = [];
    foreach ($day === '' ? [] : ($ulc === '' ? $buildings : [$ulc => $rooms]) as $b => $list) {
        foreach ($list as $r) {
            // Overlapping slots lists only the rooms with an overlap that day.
            if ($overlap && !$roomHits[$r]) continue;
            $overlapsShown += count($roomOverlaps[$r] ?? []);
            foreach ($teacherAt[$r] ?? [] as $o) $teachersShown[$o['person'] . $o['day'] . $o['from']] = true;
            $runs = timetable_day_runs($t, $r, $day);
            $board[(string) $b][$r] = $runs;
            $roomCount++;
            foreach ($runs as $x) {
                if ($x['entries']) $bookedMins += $x['to'] - $x['from'];
                elseif ($x['to'] - $x['from'] >= 30) $openMins += $x['to'] - $x['from'];
            }
        }
    }
    $dayAll = $roomCount * (TIMETABLE_CLOSE - TIMETABLE_OPEN);
    $who = fn(array $x): string => $x['blocked'] !== '' ? $x['blocked'] . ' (booked)' : trim("{$x['code']}, {$x['class']}" . ($x['faculty'] !== '' ? ", {$x['faculty']}" : ''), ', ');
?>
    <div class="tt-head">
      <h2 class="tt-room">All rooms <span><?= $e($ulc === '' ? 'Every building' : $ulc) ?><?= $day !== '' ? ' &middot; ' . $e($date->format('l')) : '' ?></span></h2>
      <?php if ($roomCount): ?>
      <?php if ($overlap): ?>
      <span class="tt-usage"><b><?= $overlapsShown ?></b> room overlap<?= $overlapsShown === 1 ? '' : 's' ?> and <b><?= count($teachersShown) ?></b> teacher clash<?= count($teachersShown) === 1 ? '' : 'es' ?> across <?= $plural($roomCount, 'room', 'rooms') ?></span>
      <?php elseif ($free): ?>
      <span class="tt-usage"><span class="tt-usage-bar tt-usage-free" aria-hidden="true"><i style="width:<?= $dayAll ? round(100 * $openMins / $dayAll) : 0 ?>%"></i></span><b><?= $freeHours($openMins) ?></b> room hours free across <?= $roomCount ?> rooms</span>
      <?php else: ?>
      <span class="tt-usage"><span class="tt-usage-bar" aria-hidden="true"><i style="width:<?= $dayAll ? round(100 * $bookedMins / $dayAll) : 0 ?>%"></i></span><b><?= $freeHours($bookedMins) ?></b> room hours booked across <?= $roomCount ?> rooms</span>
      <?php endif; ?>
      <?php endif; ?>
      <span class="tt-when-print"><?= $e($date->format('l j M Y')) ?></span>
      <span class="tt-actions no-print">
        <button class="btn-quiet tt-print" type="button" onclick="window.print()">Export PDF</button>
      </span>
    </div>
  <?php if ($day === ''): ?>
    <p class="tt-none">No classes on <?= $e($date->format('l')) ?>s. The timetable runs <?= $e($t['days'][0]) ?> to <?= $e(end($t['days'])) ?>.</p>
  <?php elseif (!$board && $overlap): ?>
    <p class="tt-none">No room is double-booked on <?= $e($date->format('l')) ?>.</p>
  <?php elseif (!$board): ?>
    <p class="tt-none">No classrooms listed here yet. They come with the next timetable update.</p>
  <?php else: ?>
    <div class="tt-wrap">
      <table class="tt-grid tt-all">
        <colgroup><col class="tt-all-room"><?php for ($i = 0; $i < $cols; $i++): ?><col><?php endfor; ?></colgroup>
        <thead>
          <tr>
            <th class="tt-time" scope="col">Room</th>
            <?php for ($h = intdiv(TIMETABLE_OPEN, 60); $h * 60 < TIMETABLE_CLOSE; $h++): $a = max(0, $col($h * 60)); $z = min($cols, $col(($h + 1) * 60)); ?>
            <th scope="col" colspan="<?= $z - $a ?>"><?= timetable_clock($h * 60) ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($board as $b => $list): ?>
          <?php if ($ulc === ''): ?><tr class="tt-all-group"><th colspan="<?= $cols + 1 ?>" scope="rowgroup"><?= $e($b) ?></th></tr><?php endif; ?>
          <?php foreach ($list as $r => $runs): [$num, $name] = $roomParts($r); ?>
          <tr>
            <th class="tt-time" scope="row"><a href="<?= $e($link(['all' => null, 'ulc' => $b, 'room' => $r])) ?>" title="Open this room's week"><?= $e($num) ?><?php if ($name !== ''): ?><small><?= $e($name) ?></small><?php endif; ?></a></th>
            <?php foreach ($runs as $x):
                $a = $col($x['from']);
                $z = $col($x['to']);
                if ($z <= $a) continue;
                $cs = ' colspan="' . ($z - $a) . '"';
                $stretch = timetable_clock($x['from']) . ' to ' . timetable_clock($x['to']);
                $tc = $overlap && $x['entries'] ? $teacherClash($r, $x['entries'], $day) : [];
                if ($tc && count($x['entries']) === 1): ?>
            <td class="tt-overlap"<?= $cs ?> title="<?= $e(implode('; ', array_map(fn($o) => $clashNote($o, $r), $tc)) . ': ' . $who($x['entries'][0])) ?>">
              <div class="tt-all-cell">
                <strong>Teacher clash</strong>
                <span><?= $e(implode('; ', array_map(fn($o) => 'also in ' . $whereElse($o, $r), $tc))) ?></span>
              </div>
            </td>
            <?php elseif (count($x['entries']) > 1 && $overlap):
                    $overlapAt = implode(', ', array_map(fn($o) => timetable_clock($o['from']) . ' to ' . timetable_clock($o['to']), array_filter($roomOverlaps[$r], fn($o) => $o['from'] < $x['to'] && $o['to'] > $x['from']))); ?>
            <td class="tt-overlap"<?= $cs ?> title="<?= $e("Overlap $overlapAt: " . implode('; ', array_map($who, $x['entries']))) ?>">
              <div class="tt-all-cell">
                <strong><?= $plural(count($x['entries']), 'booking', 'bookings') ?></strong>
                <span><?= $e($overlapAt) ?></span>
              </div>
            </td>
            <?php elseif ($x['entries'] && ($free || $overlap)): ?>
            <td class="tt-taken"<?= $cs ?> title="<?= $e("Booked $stretch: " . implode('; ', array_map($who, $x['entries']))) ?>"><span class="sr-only">Booked</span></td>
            <?php elseif ($x['entries']):
                $first = $x['entries'][0];
                $cls = $first['blocked'] !== '' ? 'tt-blocked' : ($first['lab'] ? 'tt-lab' : 'tt-lecture');
                if (count($x['entries']) > 1) { $cls .= ' tt-clash'; $hasClash = true; }
                foreach ($x['entries'] as $y) if ($y['blocked'] !== '') $hasBlocked = true; ?>
            <td class="<?= $cls ?>"<?= $cs ?> title="<?= $e("$stretch: " . implode('; ', array_map($who, $x['entries']))) ?>">
              <div class="tt-all-cell">
                <strong><?= $e($first['blocked'] !== '' ? $first['blocked'] : $first['code']) ?><?= count($x['entries']) > 1 ? ' +' . (count($x['entries']) - 1) : '' ?></strong>
                <?php if ($first['blocked'] === ''): ?><span><?= $e($first['class']) ?></span><?php endif; ?>
              </div>
            </td>
            <?php elseif ($free && $x['to'] - $x['from'] >= 30): ?>
            <td class="tt-open"<?= $cs ?> title="<?= $e("Free $stretch") ?>"><span class="tt-open-label">Free</span></td>
            <?php else: ?>
            <td class="tt-free"<?= $cs ?>><span class="sr-only"><?= $free ? 'Under half an hour' : 'Free' ?></span></td>
            <?php endif; endforeach; ?>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <ul class="tt-legend">
      <?php if ($free): ?>
      <li><i class="tt-key tt-open"></i>Free</li>
      <li><i class="tt-key tt-taken"></i>Booked</li>
      <?php elseif ($overlap): ?>
      <li><i class="tt-key tt-overlap"></i>Overlap: a room or a teacher booked twice at once</li>
      <li><i class="tt-key tt-taken"></i>Booked</li>
      <li><i class="tt-key tt-free"></i>Free</li>
      <?php else: ?>
      <li><i class="tt-key tt-lecture"></i>Lecture</li>
      <li><i class="tt-key tt-lab"></i>Lab</li>
      <?php if ($hasBlocked): ?><li><i class="tt-key tt-blocked"></i>Booked by another department</li><?php endif; ?>
      <li><i class="tt-key tt-free"></i>Free</li>
      <?php if ($hasClash): ?><li><i class="tt-key tt-clash"></i>Clash: two classes booked at once</li><?php endif; ?>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
<?php elseif (!$rooms && !$person): ?>
    <p class="tt-none">No classrooms listed for <?= $e($ulc) ?> yet. They come with the next timetable update.</p>
<?php else: ?>
    <div class="tt-head">
      <?php if ($person): ?>
      <?php // A faculty member has no role on file, so the schools they teach in say who they are.
          $role = $person['role'] !== '' ? $person['role'] : implode(', ', array_map(fn($k) => SCHOOLS[$k]['name'] ?? $k, $person['schools'] ?? [])); ?>
      <h2 class="tt-room"><?= $e($person['name']) ?><?php if ($role !== ''): ?> <span><?= $e($role) ?></span><?php endif; ?></h2>
      <?php if ($days && !$week): ?><span class="tt-usage"><?= $classesShown ?> class<?= $classesShown === 1 ? '' : 'es' ?> on <?= $e($date->format('l')) ?></span><?php endif; ?>
      <?php else: [$num, $name] = $roomParts($room); ?>
      <h2 class="tt-room"><?= $e($num) ?> <span><?= $name !== '' ? $e($name) . ' &middot; ' : '' ?><?= $e($ulc) ?></span></h2>
      <?php if ($days && $overlap): $n = $roomHits[$room] ?? 0; ?>
      <span class="tt-usage"><?php if ($n): ?><b><?= $n ?></b> overlap<?= $n === 1 ? '' : 's' ?> <?= $week ? 'this week' : 'this day' ?><?php else: ?>No overlaps in this room <?= $week ? 'this week' : 'this day' ?><?php endif; ?></span>
      <?php elseif ($days && $free): ?>
      <span class="tt-usage"><span class="tt-usage-bar tt-usage-free" aria-hidden="true"><i style="width:<?= $dayMins ? round(100 * $freeMins / $dayMins) : 0 ?>%"></i></span><b><?= $freeHours($freeMins) ?></b> hour<?= $freeMins === 60 ? '' : 's' ?> free <?= $week ? 'this week' : 'this day' ?></span>
      <?php elseif ($days): ?>
      <span class="tt-usage"><span class="tt-usage-bar" aria-hidden="true"><i style="width:<?= $total ? round(100 * $used / $total) : 0 ?>%"></i></span><b><?= $used ?></b> of <?= $total ?> periods booked</span>
      <?php if ($clashes): ?><span class="tt-usage"><b><?= $clashes ?></b> clash<?= $clashes === 1 ? '' : 'es' ?></span><?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
      <?php // The pickers do not print, so the paper says which week it is. ?>
      <span class="tt-when-print"><?= $week ? 'Week of ' . $e($monday->format('j M Y')) : $e($date->format('l j M Y')) ?></span>
      <span class="tt-actions no-print">
        <button class="btn-quiet tt-print" type="button" onclick="window.print()">Export PDF</button>
      </span>
    </div>
    <?php if ($person): ?>
    <?php // The dean's week in figures, the way the Usage page shows its own: label over number. ?>
    <dl class="tt-stats">
      <div title="Every class they teach in the week, by its length: a two-hour lab counts as two"><dt>Weekly workload</dt><dd><?= $hours ?> <small>hour<?= $load == 1 ? '' : 's' ?></small></dd></div>
      <div><dt>Classes a week</dt><dd><?= count($teaching) ?></dd></div>
      <div><dt>Rooms</dt><dd><?= count(array_unique(array_column($teaching, 'room'))) ?></dd></div>
    </dl>
    <?php endif; ?>

<?php if (!$days): ?>
    <p class="tt-none">No classes on <?= $e($date->format('l')) ?>s. The timetable runs <?= $e($t['days'][0]) ?> to <?= $e(end($t['days'])) ?>.</p>
<?php elseif ($person && !$teaching): ?>
    <p class="tt-none">Timetable not available yet.</p>
<?php elseif ($person && !$classesShown): ?>
    <p class="tt-none">No classes for <?= $e($person['name']) ?> on <?= $e($date->format('l')) ?>s.</p>
<?php else: ?>
    <div class="tt-wrap">
      <table class="tt-grid<?= $week ? '' : ' tt-grid-day' ?>">
        <thead>
          <tr><th class="tt-time" scope="col">Time</th><?php foreach ($days as $d): ?><th scope="col"<?= $dateOf($d) == $today ? ' class="tt-on"' : '' ?>><?= $e($d) ?><span class="tt-date"><?= $e($dateOf($d)->format('j M')) ?></span></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($shown['slots'] as $s):
            $time = $s['time'];
            $kind = $s['break'] === '' ? '' : (stripos($s['break'], 'lunch') !== false ? 'tt-lunch' : 'tt-break');
        ?>
          <tr class="<?= $kind ?>">
            <?php $ends = preg_split('/\s+to\s+/', $time); ?>
            <th class="tt-time" scope="row"><?= $e($ends[0]) ?><?php if (isset($ends[1])): ?><small>to <?= $e($ends[1]) ?></small><?php endif; ?><?php if ($kind): ?><span><?= $e(ucwords(strtolower($s['break']))) ?></span><?php endif; ?></th>
            <?php if ($kind): ?>
              <td class="tt-break-cell" colspan="<?= count($days) ?>"><?= $e(ucwords(strtolower($s['break']))) ?></td>
            <?php else: foreach ($days as $d):
                if (!empty($grid['covered'][$time][$d])) continue;
                // The end of a class that runs into this row without filling
                // it: left blank in the class's colour, so it reads as taken,
                // with the end time only on hover (the user's call).
                if ($tail = $grid['tails'][$time][$d] ?? null): ?>
              <td class="tt-cont <?= $free || $overlap ? 'tt-taken' : ($tail['lab'] ? 'tt-lab' : 'tt-lecture') ?>" title="Continues until <?= $e($tail['until']) ?>"><span class="sr-only">Continues until <?= $e($tail['until']) ?></span></td>
            <?php continue; endif;
                $cell = $grid['cells'][$time][$d] ?? null;
                if (!$cell && $free && ($open = $openIn($time, $d))):
                    if (!$open['whole']) $hasPart = true; ?>
              <td class="tt-open<?= $open['whole'] ? '' : ' tt-open-part' ?>"><span class="tt-open-label"><?= $e($open['label']) ?></span></td>
            <?php elseif (!$cell): ?>
              <td class="<?= $free ? 'tt-taken' : 'tt-free' . ($dateOf($d) == $today ? ' tt-today' : '') ?>"><span class="sr-only"><?= $free ? 'Booked' : 'Free' ?></span></td>
            <?php elseif ($free || ($overlap && !$overlapsOf($cell['entries'], $d) && !$teacherClash($room, $cell['entries'], $d))):
                // Free slots says only that a slot is taken, not by what: the
                // free cells are the point, so a booked one is a quiet texture,
                // with the class on hover for anyone who wants it. Overlapping
                // slots draws a booking that overlaps nothing the same way.
                $what = implode('; ', array_map(fn($x) => $x['blocked'] !== '' ? $x['blocked'] : trim("{$x['code']}, {$x['class']}", ', '), $cell['entries'])); ?>
              <td class="tt-taken" title="Booked: <?= $e($what) ?>"<?= $cell['rows'] > 1 ? ' rowspan="' . $cell['rows'] . '"' : '' ?>><span class="sr-only">Booked</span></td>
            <?php else:
                $first = $cell['entries'][0];
                $cls = $first['blocked'] !== '' ? 'tt-blocked' : ($first['lab'] ? 'tt-lab' : 'tt-lecture');
                $clash = count($cell['entries']) > 1;
                if ($clash) { $cls .= ' tt-clash'; $hasClash = true; }
                foreach ($cell['entries'] as $x) if ($x['blocked'] !== '') $hasBlocked = true;
                // On Overlapping slots the cell says when the overlap runs,
                // which can be less than the period (another clock's 10.00
                // lecture against a 9.30 lab).
                $overlapAt = $overlap ? $clashLines($cell['entries'], $d) : [];
                if ($overlap) $cls = 'tt-overlap';
            ?>
              <td class="<?= $cls ?>"<?= $clash && !$overlap ? ' title="Clash: two bookings in this slot"' : '' ?><?= $cell['rows'] > 1 ? ' rowspan="' . $cell['rows'] . '"' : '' ?>>
                <?php foreach ($overlapAt as $line): ?><span class="tt-overlap-when"><?= $e($line) ?></span><?php endforeach; ?>
                <?php foreach ($overlap ? $teacherClash($room, $cell['entries'], $d) : [] as $o): ?><span class="tt-overlap-when">Teacher clash: <?= $e($clashNote($o, $room)) ?></span><?php endforeach; ?>
                <?php foreach ($cell['entries'] as $x):
                    // A class opens its panel when there is something to show
                    // in it: its attendance, or (where enabled) its sources.
                    $att = $x['blocked'] === '' ? $attendance($x, $d) : null;
                    if ($att && !$att['marked']) $attShown = true;
                    $open = $att || (TIMETABLE_SOURCES && $x['src']);
                    $tag = $open ? 'button' : 'div';
                ?>
                  <<?= $tag ?> class="tt-entry"<?= $open ? ' type="button" data-i="' . $describe($x, $d, $att) . '" aria-haspopup="dialog"' : '' ?>>
                    <?php if ($x['blocked'] !== ''): ?>
                      <strong><?= $e($x['blocked']) ?></strong>
                      <span class="tt-tag">Booked</span>
                    <?php else: ?>
                      <strong><?= $e($x['code']) ?><?php if ($x['lab']): ?> <span class="tt-tag tt-tag-lab">Lab</span><?php endif; ?></strong>
                      <?php if ($cell['rows'] > 1): ?><span class="tt-span"><?= $e(timetable_span_label($x)) ?></span><?php endif; ?>
                      <span class="tt-class"><?= $e($x['class']) ?></span>
                      <?php if ($person): ?><span class="tt-faculty">Room <?= $e(timetable_room_label($x['room'])) ?>, <?= $e($x['ulc']) ?></span><?php elseif ($x['faculty'] !== ''): ?><span class="tt-faculty"><?= $e($x['faculty']) ?></span><?php endif; ?>
                      <?php if ($att): ?>
                        <span class="tt-att<?= $att['marked'] ? '' : ' tt-att-none' ?>"><?= $att['marked'] ? $att['present'] . '/' . $att['strength'] . ' present' . ($att['marked'] < count($att['classes']) ? " &middot; {$att['marked']} of " . count($att['classes']) . ' marked' : '') : 'Not marked' ?></span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </<?= $tag ?>>
                <?php endforeach; ?>
              </td>
            <?php endif; endforeach; endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <ul class="tt-legend">
      <?php if ($overlap): ?>
      <li><i class="tt-key tt-overlap"></i>Overlap: a room or a teacher booked twice at once</li>
      <?php endif; ?>
      <?php if ($free || $overlap): ?>
      <li><i class="tt-key tt-taken"></i>Booked</li>
      <?php else: ?>
      <li><i class="tt-key tt-lecture"></i>Lecture</li>
      <li><i class="tt-key tt-lab"></i>Lab</li>
      <?php endif; ?>
      <?php if ($hasBlocked): ?><li><i class="tt-key tt-blocked"></i>Booked by <?= $school === '' ? 'another department' : 'another school or department' ?></li><?php endif; ?>
      <?php if ($free): ?>
      <li><i class="tt-key tt-open"></i>Free</li>
      <?php if ($hasPart): ?><li><i class="tt-key tt-open tt-open-part"></i>Free for part of the period</li><?php endif; ?>
      <?php else: ?>
      <li><i class="tt-key tt-free"></i>Free</li>
      <?php endif; ?>
      <?php if ($hasClash && !$overlap): ?><li><i class="tt-key tt-clash"></i>Clash: two classes booked at once</li><?php endif; ?>
      <?php if ($attShown): ?><li><span class="tt-att tt-att-none tt-att-key">Not marked yet</span></li><?php endif; ?>
    </ul>
<?php endif; endif; ?>
<?php if ($overlap):
    // Every overlap in view, room by room, so none is missed for not being
    // in the room picked: the school's rooms (or every room) in every
    // building, over the days shown.
    $ulcOf = [];
    foreach ($buildings as $b => $list) foreach ($list as $r) $ulcOf[(string) $r] = (string) $b;
    $every = [];
    foreach ($roomOverlaps as $r => $list) foreach ($list as $o) $every[] = ['room' => (string) $r] + $o;
    usort($every, fn($a, $b) => [array_search($a['day'], $t['days'], true), $a['from'], $ulcOf[$a['room']], $a['room']] <=> [array_search($b['day'], $t['days'], true), $b['from'], $ulcOf[$b['room']], $b['room']]);
    $what = fn(array $x): string => $x['blocked'] !== '' ? $x['blocked'] . ' (booked)' : trim("{$x['code']}, {$x['class']}", ', ');
?>
    <?php $span = $all || !$week ? 'on ' . $e($date->format('l')) : 'this week'; ?>
    <section class="tt-overlaps" aria-labelledby="tt-overlaps-title">
      <h3 id="tt-overlaps-title">Rooms double-booked <?= $span ?><?= $school !== '' ? ', ' . $e($schools[$school]['name']) . ' rooms' : '' ?> <span><?= count($every) ?></span></h3>
      <?php if (!$every): ?>
      <p class="tt-none">No room is double-booked.</p>
      <?php else: ?>
      <div class="tt-wrap">
        <table class="tt-overlap-list">
          <thead><tr><th scope="col">Day</th><th scope="col">Time</th><th scope="col">Room</th><th scope="col">Bookings</th></tr></thead>
          <tbody>
          <?php foreach ($every as $o): [$num, $name] = $roomParts($o['room']); ?>
            <tr<?= !$all && $o['room'] === $room ? ' class="tt-here"' : '' ?>>
              <td><?= $e($o['day']) ?></td>
              <td class="tt-nowrap"><?= timetable_clock($o['from']) ?> to <?= timetable_clock($o['to']) ?></td>
              <td><a href="<?= $e($link(['all' => null, 'ulc' => $ulcOf[$o['room']], 'room' => $o['room']])) ?>"><?= $e($num) ?></a> <small><?= $e($ulcOf[$o['room']]) ?></small></td>
              <td><?php foreach ($o['entries'] as $x): ?><span class="tt-overlap-entry"><?= $e($what($x)) ?><?php if ($x['faculty'] !== ''): ?> <small><?= $e($x['faculty']) ?></small><?php endif; ?> <small><?= $e(timetable_span_label($x)) ?></small></span><?php endforeach; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
<?php endif; ?>
    <p class="tt-hint no-print"><?= $all ? 'Tap a room to open its week; hover a class for its details. ' . ($free ? 'A gap under half an hour is left out.' : '') : '' ?><?= $overlap ? 'An overlap is two or more bookings in one room at the same time, or one teacher down for two rooms at the same time, by the clock. ' : '' ?><?= $all || $overlap ? '' : ($free ? 'Free time runs 8.00 to 5.45; a gap under half an hour is left out. Hover a booked slot to see the class.' : (TIMETABLE_SOURCES ? 'Tap a class to see its attendance and the document it comes from.' : 'Tap a class with attendance to see it division by division.')) ?></p>
    <p class="tt-source">Official timetable<?= $t['exported_at'] !== '' ? ' &middot; updated ' . $e($when($t['exported_at'])) : '' ?></p>
  </section>
<?php endif; ?>

<?php if (!empty($panel)): ?>
<dialog class="tt-panel" id="tt-panel" aria-labelledby="tt-panel-title">
  <div class="tt-panel-body"></div>
  <form method="dialog"><button class="btn-quiet tt-panel-close">Close</button></form>
</dialog>
<script type="application/json" id="tt-panel-data"><?= json_encode($panel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="js/rooms.js?v=<?= filemtime(__DIR__ . '/js/rooms.js') ?>"></script>
<?php endif; ?>
<?php if ($room !== '' || $person || $all): ?>
<script>
  // The saved PDF is named after document.title, so name it after the room (or the dean) and day.
  document.title = <?= json_encode($all ? ($free ? 'Free slots ' : ($overlap ? 'Overlaps ' : 'Timetable ')) . ($ulc === '' ? 'all rooms' : $ulc) . ' ' . $date->format('l j M Y') : ($person ? 'Timetable ' . $person['name'] : ($free ? 'Free slots room ' : ($overlap ? 'Overlaps room ' : 'Room ')) . timetable_room_label($room) . ' ' . $ulc) . ' ' . ($week ? 'week of ' . $date->modify('monday this week')->format('j M Y') : $date->format('l j M Y')), JSON_HEX_TAG) ?>;
</script>
<?php endif; ?>
<?php // The Room and Dean pickers become searchable; without it they stay plain selects. ?>
<script src="js/combo.js?v=<?= filemtime(__DIR__ . '/js/combo.js') ?>"></script>
<?php page_foot(); ?>
