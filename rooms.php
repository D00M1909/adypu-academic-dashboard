<?php
// One room on one day (or its whole week): which class, subject and teacher is
// in it each period. Picked by school, then date, then ULC, then classroom.
// Or one dean's week instead: every class they teach, in whichever room.
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
$lectures = $t ? timetable_lectures(timetable_for_week($t, $monday)) : [];
// A dean is a person, not a place: their week spans rooms and schools, so the
// school, ULC and room pickers stand aside while one is picked. A dean with
// no classes in the timetable is still a choice and shows an empty week.
$deans = $t['deans'] ?? [];
$deanId = (string) ($_GET['dean'] ?? '');
$dean = $deans[$deanId] ?? null;
if (!$dean) $deanId = '';
if ($dean) $school = '';
// A school's rooms are the ones it teaches in at all, so they are picked
// before the week drops the bookings that do not run in it.
if ($t && $school !== '') $t = timetable_for_school($t, $school);
if ($t) $t = timetable_for_week($t, $monday);
$teaching = $dean ? timetable_teaching($t, $dean) : [];
$buildings = $t['buildings'] ?? [];
// Pick order is the one the request asked for: date, then ULC, then room. Each
// falls back to the first valid choice, so changing the ULC with a room from
// the old one still selected lands on the new ULC's first room.
$ulc = (string) ($_GET['ulc'] ?? '');
if (!isset($buildings[$ulc])) $ulc = (string) (array_key_first($buildings) ?? '');
$rooms = $buildings[$ulc] ?? [];
$room = (string) ($_GET['room'] ?? '');
if (!in_array($room, $rooms, true)) $room = $rooms[0] ?? '';

// The whole week unless week=0 asks for one day: a room's week is what most
// people open it for.
$week = ($_GET['week'] ?? '1') !== '0';
$day = $t ? timetable_day($t, $date) : '';

$e = fn(string $v): string => htmlspecialchars($v);
$when = function (string $stamp): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $stamp);
    return $d ? $d->format('j M Y') : $stamp;
};
// Every link keeps the view it came from: the room (or the dean), the date,
// and the one-day view when that is what is showing. An $over value of null
// drops that key.
$link = fn(array $over): string => 'rooms.php?' . http_build_query(array_filter($over + ($dean ? ['dean' => $deanId] : ['school' => $school, 'ulc' => $ulc, 'room' => $room])
    + ['date' => $date->format('Y-m-d')] + ($week ? [] : ['week' => '0']), fn($v) => $v !== null));
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
    $key = $dean ? '@dean' : $room;
    $from = $dean ? ['rooms' => [$key => $teaching]] + $t : $t;
    $shown = ['days' => $days, 'slots' => timetable_room_slots($from, $key)] + $from;
    $grid = timetable_grid($shown, $key);
    [$used, $total] = timetable_usage($shown, $grid);
    $classesShown = count(array_filter($teaching, fn($x) => in_array($x['day'], $days, true)));
    // A dean's workload is always the whole week's teaching hours, even on
    // the one-day view: it is a weekly figure, and a day's would read as one.
    $load = timetable_hours($teaching, $dean['count_each_group'] ?? false);
    $hours = $load == round($load) ? (string) (int) $load : number_format($load, 1);
    $hasClash = false;
    $hasBlocked = false;
    $clashes = 0;
    $attShown = false;
    foreach ($grid['cells'] as $byDay) foreach ($byDay as $c) if (count($c['entries']) > 1) $clashes++;
    // A small building's rooms are chips, one tap each, with how full each
    // is this week; a big one (ULC 1 has 50) keeps the dropdown.
    $chips = [];
    if (!$dean && count($rooms) <= 16) {
        foreach ($rooms as $r) {
            $v = ['days' => $days, 'slots' => timetable_room_slots($t, $r)] + $t;
            [$u, $n] = timetable_usage($v, timetable_grid($v, $r));
            $chips[$r] = $n ? $u / $n : 0;
        }
    }
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
        <?php // Rooms or one dean's week: a mode, so each shows only its own picker. ?>
        <nav class="tt-seg" aria-label="View">
          <a href="<?= $e($link(['dean' => null] + ($dean ? ['school' => $school, 'ulc' => $ulc, 'room' => $room] : []))) ?>"<?= $dean ? '' : ' aria-current="page"' ?>>Rooms</a>
          <?php if ($deans): ?><a href="<?= $e($dean ? $link([]) : 'rooms.php?' . http_build_query(['dean' => array_key_first($deans), 'date' => $date->format('Y-m-d')] + ($week ? [] : ['week' => '0']))) ?>"<?= $dean ? ' aria-current="page"' : '' ?>>Dean workload</a><?php endif; ?>
        </nav>
        <div class="tt-week">
          <a class="tt-step" href="<?= $e($link(['date' => $prev])) ?>" title="<?= $week ? 'Previous week' : 'Previous day' ?>" aria-label="<?= $week ? 'Previous week' : 'Previous day' ?>">&#8249;</a>
          <?php // The label opens the date picker for jumping further than a step. ?>
          <label class="tt-week-label" for="tt-date" title="Pick a date"><?= $e($weekLabel) ?><small><?= $e($weekSub) ?></small>
            <input id="tt-date" type="date" name="date" value="<?= $date->format('Y-m-d') ?>" onchange="this.form.submit()" tabindex="-1">
          </label>
          <a class="tt-step" href="<?= $e($link(['date' => $next])) ?>" title="<?= $week ? 'Next week' : 'Next day' ?>" aria-label="<?= $week ? 'Next week' : 'Next day' ?>">&#8250;</a>
          <a class="tt-today" href="<?= $e($link(['date' => $today->format('Y-m-d')])) ?>">Today</a>
          <nav class="tt-seg" aria-label="Span">
            <a href="<?= $e($link(['week' => null])) ?>"<?= $week ? ' aria-current="page"' : '' ?>>Week</a>
            <a href="<?= $e($link(['week' => '0'])) ?>"<?= $week ? '' : ' aria-current="page"' ?>>Day</a>
          </nav>
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
            <?php foreach (array_keys($buildings) as $b): $b = (string) $b; ?>
              <option value="<?= $e($b) ?>"<?= $b === $ulc ? ' selected' : '' ?>><?= $e($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!$chips): ?>
        <div class="tt-pick">
          <label for="tt-room">Room</label>
          <select id="tt-room" name="room" onchange="this.form.submit()" data-combo<?= $rooms ? '' : ' disabled' ?>>
            <?php if (!$rooms): ?><option>None yet</option><?php endif; ?>
            <?php foreach ($rooms as $r): ?>
              <option value="<?= $e($r) ?>"<?= $r === $room ? ' selected' : '' ?>><?= $e(timetable_room_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="room" value="<?= $e($room) ?>">
        <?php endif; ?>
      <?php endif; ?>
      </div>
      <?php if ($chips): ?>
      <ul class="tt-chips" aria-label="Rooms in <?= $e($ulc) ?>">
        <?php foreach ($chips as $r => $full): [$num, $name] = $roomParts($r); ?>
          <li><a href="<?= $e($link(['room' => $r])) ?>"<?= $r === $room ? ' aria-current="page"' : '' ?> title="<?= round($full * 100) ?>% booked <?= $week ? 'this week' : 'this day' ?>"><?= $e($num) ?><?php if ($name !== ''): ?><small><?= $e($name) ?></small><?php endif; ?><span class="tt-chip-bar"><i style="width:<?= round($full * 100) ?>%"></i></span></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if (!$week): ?><input type="hidden" name="week" value="0"><?php endif; ?>
      <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
    </form>

<?php if (!$rooms && !$dean): ?>
    <p class="tt-none">No classrooms listed for <?= $e($ulc) ?> yet. They come with the next timetable update.</p>
<?php else: ?>
    <div class="tt-head">
      <?php if ($dean): ?>
      <h2 class="tt-room"><?= $e($dean['name']) ?><?php if ($dean['role'] !== ''): ?> <span><?= $e($dean['role']) ?></span><?php endif; ?></h2>
      <?php if ($days && !$week): ?><span class="tt-usage"><?= $classesShown ?> class<?= $classesShown === 1 ? '' : 'es' ?> on <?= $e($date->format('l')) ?></span><?php endif; ?>
      <?php else: [$num, $name] = $roomParts($room); ?>
      <h2 class="tt-room"><?= $e($num) ?> <span><?= $name !== '' ? $e($name) . ' &middot; ' : '' ?><?= $e($ulc) ?></span></h2>
      <?php if ($days): ?>
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
    <?php if ($dean): ?>
    <?php // The dean's week in figures, the way the Usage page shows its own: label over number. ?>
    <dl class="tt-stats">
      <div title="Every class they teach in the week, by its length: a two-hour lab counts as two"><dt>Weekly workload</dt><dd><?= $hours ?> <small>hour<?= $load == 1 ? '' : 's' ?></small></dd></div>
      <div><dt>Classes a week</dt><dd><?= count($teaching) ?></dd></div>
      <div><dt>Rooms</dt><dd><?= count(array_unique(array_column($teaching, 'room'))) ?></dd></div>
    </dl>
    <?php endif; ?>

<?php if (!$days): ?>
    <p class="tt-none">No classes on <?= $e($date->format('l')) ?>s. The timetable runs <?= $e($t['days'][0]) ?> to <?= $e(end($t['days'])) ?>.</p>
<?php elseif ($dean && !$teaching): ?>
    <p class="tt-none">Timetable not available yet.</p>
<?php elseif ($dean && !$classesShown): ?>
    <p class="tt-none">No classes for <?= $e($dean['name']) ?> on <?= $e($date->format('l')) ?>s.</p>
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
              <td class="tt-cont <?= $tail['lab'] ? 'tt-lab' : 'tt-lecture' ?>" title="Continues until <?= $e($tail['until']) ?>"><span class="sr-only">Continues until <?= $e($tail['until']) ?></span></td>
            <?php continue; endif;
                $cell = $grid['cells'][$time][$d] ?? null;
                if (!$cell): ?>
              <td class="tt-free<?= $dateOf($d) == $today ? ' tt-today' : '' ?>"><span class="sr-only">Free</span></td>
            <?php else:
                $first = $cell['entries'][0];
                $cls = $first['blocked'] !== '' ? 'tt-blocked' : ($first['lab'] ? 'tt-lab' : 'tt-lecture');
                $clash = count($cell['entries']) > 1;
                if ($clash) { $cls .= ' tt-clash'; $hasClash = true; }
                foreach ($cell['entries'] as $x) if ($x['blocked'] !== '') $hasBlocked = true;
            ?>
              <td class="<?= $cls ?>"<?= $clash ? ' title="Clash: two bookings in this slot"' : '' ?><?= $cell['rows'] > 1 ? ' rowspan="' . $cell['rows'] . '"' : '' ?>>
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
                      <?php if ($dean): ?><span class="tt-faculty">Room <?= $e(timetable_room_label($x['room'])) ?>, <?= $e($x['ulc']) ?></span><?php elseif ($x['faculty'] !== ''): ?><span class="tt-faculty"><?= $e($x['faculty']) ?></span><?php endif; ?>
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
      <li><i class="tt-key tt-lecture"></i>Lecture</li>
      <li><i class="tt-key tt-lab"></i>Lab</li>
      <?php if ($hasBlocked): ?><li><i class="tt-key tt-blocked"></i>Booked by <?= $school === '' ? 'another department' : 'another school or department' ?></li><?php endif; ?>
      <li><i class="tt-key tt-free"></i>Free</li>
      <?php if ($hasClash): ?><li><i class="tt-key tt-clash"></i>Clash: two classes booked at once</li><?php endif; ?>
      <?php if ($attShown): ?><li><span class="tt-att tt-att-none tt-att-key">Not marked yet</span></li><?php endif; ?>
    </ul>
<?php endif; endif; ?>
    <p class="tt-hint no-print"><?= TIMETABLE_SOURCES ? 'Tap a class to see its attendance and the document it comes from.' : 'Tap a class with attendance to see it division by division.' ?></p>
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
<?php if ($room !== '' || $dean): ?>
<script>
  // The saved PDF is named after document.title, so name it after the room (or the dean) and day.
  document.title = <?= json_encode(($dean ? 'Timetable ' . $dean['name'] : 'Room ' . timetable_room_label($room) . ' ' . $ulc) . ' ' . ($week ? 'week of ' . $date->modify('monday this week')->format('j M Y') : $date->format('l j M Y')), JSON_HEX_TAG) ?>;
</script>
<?php endif; ?>
<?php // The Room and Dean pickers become searchable; without it they stay plain selects. ?>
<script src="js/combo.js?v=<?= filemtime(__DIR__ . '/js/combo.js') ?>"></script>
<?php page_foot(); ?>
