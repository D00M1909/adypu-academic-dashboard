<?php
// One room on one day (or its whole week): which class, subject and teacher is
// in it each period. Picked by date, then ULC, then classroom.
// The same view as By Room on the timetable generator, drawn from
// timetable/rooms.php (see includes/timetable.php for why it is a file).
// Open to everyone and read-only, like the dashboard.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/timetable.php';

auth_boot();
traffic_hit('rooms');

$t = timetable_read();
$buildings = $t['buildings'] ?? [];
// Pick order is the one the request asked for: date, then ULC, then room. Each
// falls back to the first valid choice, so changing the ULC with a room from
// the old one still selected lands on the new ULC's first room.
$ulc = (string) ($_GET['ulc'] ?? '');
if (!isset($buildings[$ulc])) $ulc = (string) (array_key_first($buildings) ?? '');
$rooms = $buildings[$ulc] ?? [];
$room = (string) ($_GET['room'] ?? '');
if (!in_array($room, $rooms, true)) $room = $rooms[0] ?? '';

// No date means today in India, which is what a phone opened at a classroom
// door wants. The timetable repeats weekly, so only the weekday is used.
$tz = new DateTimeZone('Asia/Kolkata');
$date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['date'] ?? ''), $tz) ?: new DateTimeImmutable('today', $tz);
// The whole week unless week=0 asks for one day: a room's week is what most
// people open it for.
$week = ($_GET['week'] ?? '1') !== '0';
$day = $t ? timetable_day($t, $date) : '';

$e = fn(string $v): string => htmlspecialchars($v);
$when = function (string $stamp): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $stamp);
    return $d ? $d->format('j M Y') : $stamp;
};
$link = fn(array $over): string => 'rooms.php?' . http_build_query($over + ['date' => $date->format('Y-m-d'), 'ulc' => $ulc, 'room' => $room]);

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
    $shown = ['days' => $days, 'slots' => timetable_room_slots($t, $room)] + $t;
    $grid = timetable_grid($shown, $room);
    [$used, $total] = timetable_usage($shown, $grid);
    $hasClash = false;
    $hasBlocked = false;
?>
  <section class="card tt-card">
    <form class="tt-bar no-print" method="get">
      <div class="tt-pick">
        <label for="tt-date">Date</label>
        <input id="tt-date" type="date" name="date" value="<?= $date->format('Y-m-d') ?>" onchange="this.form.submit()">
      </div>
      <div class="tt-pick">
        <label for="tt-ulc">ULC</label>
        <select id="tt-ulc" name="ulc" onchange="this.form.submit()">
          <?php foreach (array_keys($buildings) as $b): $b = (string) $b; ?>
            <option value="<?= $e($b) ?>"<?= $b === $ulc ? ' selected' : '' ?>><?= $e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="tt-pick">
        <label for="tt-room">Classroom</label>
        <select id="tt-room" name="room" onchange="this.form.submit()"<?= $rooms ? '' : ' disabled' ?>>
          <?php if (!$rooms): ?><option>None yet</option><?php endif; ?>
          <?php foreach ($rooms as $r): ?>
            <option value="<?= $e($r) ?>"<?= $r === $room ? ' selected' : '' ?>><?= $e(timetable_room_label($r)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="week" value="<?= $week ? '1' : '0' ?>">
      <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
      <button class="btn-quiet tt-print" type="button" onclick="window.print()">Export PDF</button>
    </form>

<?php if (!$rooms): ?>
    <p class="tt-none">No classrooms listed for <?= $e($ulc) ?> yet. They come with the next timetable update.</p>
<?php else: ?>
    <div class="tt-head">
      <h2 class="tt-room"><?= $e(timetable_room_label($room)) ?></h2>
      <span class="tt-when"><?= $e($ulc) ?> &middot; <?= $week ? 'Week of ' . $e($date->modify('monday this week')->format('j M Y')) : $e($date->format('l j M Y')) ?></span>
      <?php if ($days): ?><span class="tt-usage"><?= $used ?> of <?= $total ?> periods booked</span><?php endif; ?>
      <a class="tt-toggle no-print" href="<?= $e($week ? $link(['week' => 0]) : $link([])) ?>"><?= $week ? 'Show one day' : 'Show whole week' ?></a>
    </div>

<?php if (!$days): ?>
    <p class="tt-none">No classes on <?= $e($date->format('l')) ?>s. The timetable runs <?= $e($t['days'][0]) ?> to <?= $e(end($t['days'])) ?>.</p>
<?php else: ?>
    <div class="tt-wrap">
      <table class="tt-grid<?= $week ? '' : ' tt-grid-day' ?>">
        <thead>
          <tr><th class="tt-time" scope="col">Time</th><?php foreach ($days as $d): ?><th scope="col"<?= $week && $d === $day ? ' class="tt-on"' : '' ?>><?= $e($d) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($shown['slots'] as $s):
            $time = $s['time'];
            $kind = $s['break'] === '' ? '' : (stripos($s['break'], 'lunch') !== false ? 'tt-lunch' : 'tt-break');
        ?>
          <tr class="<?= $kind ?>">
            <th class="tt-time" scope="row"><?= $e($time) ?><?php if ($kind): ?><span><?= $e(ucwords(strtolower($s['break']))) ?></span><?php endif; ?></th>
            <?php if ($kind): ?>
              <td class="tt-break-cell" colspan="<?= count($days) ?>"><?= $e(ucwords(strtolower($s['break']))) ?></td>
            <?php else: foreach ($days as $d):
                if (!empty($grid['covered'][$time][$d])) continue;
                $cell = $grid['cells'][$time][$d] ?? null;
                if (!$cell): ?>
              <td class="tt-free"><span class="sr-only">Free</span></td>
            <?php else:
                $first = $cell['entries'][0];
                $cls = $first['blocked'] !== '' ? 'tt-blocked' : ($first['lab'] ? 'tt-lab' : 'tt-lecture');
                $clash = count($cell['entries']) > 1;
                if ($clash) { $cls .= ' tt-clash'; $hasClash = true; }
                foreach ($cell['entries'] as $x) if ($x['blocked'] !== '') $hasBlocked = true;
            ?>
              <td class="<?= $cls ?>"<?= $clash ? ' title="Two bookings in this slot"' : '' ?><?= $cell['rows'] > 1 ? ' rowspan="' . $cell['rows'] . '"' : '' ?>>
                <?php foreach ($cell['entries'] as $x): ?>
                  <div class="tt-entry">
                    <?php if ($x['blocked'] !== ''): ?>
                      <strong><?= $e($x['blocked']) ?></strong>
                      <span class="tt-tag">Booked</span>
                    <?php else: ?>
                      <strong><?= $e($x['code']) ?><?php if ($x['lab']): ?> <span class="tt-tag">Lab</span><?php endif; ?></strong>
                      <span class="tt-class"><?= $e($x['class']) ?></span>
                      <?php if ($x['faculty'] !== ''): ?><span class="tt-faculty"><?= $e($x['faculty']) ?></span><?php endif; ?>
                    <?php endif; ?>
                  </div>
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
      <?php if ($hasBlocked): ?><li><i class="tt-key tt-blocked"></i>Booked by another department</li><?php endif; ?>
      <li><i class="tt-key tt-free"></i>Free</li>
      <?php if ($hasClash): ?><li><i class="tt-key tt-clash"></i>Two bookings in one slot</li><?php endif; ?>
    </ul>
<?php endif; endif; ?>
    <p class="tt-source">Official timetable<?= $t['exported_at'] !== '' ? ' &middot; updated ' . $e($when($t['exported_at'])) : '' ?></p>
  </section>
<?php endif; ?>

<?php if ($room !== ''): ?>
<script>
  // The saved PDF is named after document.title, so name it after the room and day.
  document.title = <?= json_encode('Room ' . timetable_room_label($room) . ' ' . $ulc . ' ' . ($week ? 'week of ' . $date->modify('monday this week')->format('j M Y') : $date->format('l j M Y')), JSON_HEX_TAG) ?>;
</script>
<?php endif; ?>
<?php page_foot(); ?>
