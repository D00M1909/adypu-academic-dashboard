<?php
// Usage, for admins: how many people open the dashboard (includes/traffic.php)
// and how many attendance updates are arriving, from both the Form and the app.
//
// "Updates" are readings: one class, one day, one lecture. A Form row filed
// twice for the same lecture is a correction and counts once, the same rule the
// dashboard itself uses, so this page and the tiles can never disagree.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/structure.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
auth_require(true);

// How many days the table lists: 14 unless a longer range is picked. Traffic
// is kept for TRAFFIC_KEEP_DAYS, so that is the longest worth offering.
const STATS_SPANS = [14, 30, 60, 90, TRAFFIC_KEEP_DAYS];
$span = (int) ($_GET['span'] ?? 14);
if (!in_array($span, STATS_SPANS, true)) $span = 14;

// Straight from the two stores, not get_attendance_days(): that falls back to
// sample rows on an empty install, and sample rows are not updates anyone sent.
$cache = read_attendance_cache() ?? [];
$form = remap_legacy_days(is_array($cache['days'] ?? null) ? $cache['days'] : []);
$app = remap_legacy_days(submitted('days'));
$merged = merge_readings($form, $app);
$faculty = get_attendance_faculty();
$appFaculty = remap_legacy_days(submitted('faculty'));
$traffic = traffic_days();

$schoolKeys = array_flip(array_map('class_key', class_rows()));
$partnerKeys = array_flip(array_map('class_key', partner_rows()));

$readings = fn(array $classes): int => array_sum(array_map(fn($r) => count((array) $r), $classes));

$today = date('Y-m-d');
$dates = [];
for ($i = 0; $i < $span; $i++) $dates[] = date('Y-m-d', strtotime("-$i day"));

$rows = [];
foreach ($dates as $d) {
    $t = $traffic[$d] ?? [];
    $views = $t['views'] ?? [];
    $classes = $merged[$d] ?? [];
    // One person per name, titles and spacing aside (activity_person()), so
    // "Dr. A Rao" on the Form and "a rao" in the app are one faculty member.
    $people = function (array $byClass): int {
        $names = [];
        foreach ($byClass as $times) foreach ((array) $times as $n) $names[activity_person((string) $n)] = true;
        unset($names['']);
        return count($names);
    };
    $all = $readings($classes);
    $inApp = $readings($app[$d] ?? []);
    $rows[$d] = [
        'visitors' => (int) ($t['visitors'] ?? 0),
        'views'    => array_sum($views) - (int) ($views['push'] ?? 0),
        'pushes'   => (int) ($views['push'] ?? 0),
        // Form and app add up to the day's lectures: one filed both ways
        // counts once, for the app, as on the dashboard.
        'form'     => $all - $inApp,
        'app'      => $inApp,
        'schools'  => count(array_intersect_key($classes, $schoolKeys)),
        'partners' => count(array_intersect_key($classes, $partnerKeys)),
        'faculty'  => $people($faculty[$d] ?? []),
        'teachers' => $people($appFaculty[$d] ?? []),
    ];
}

// The day the per-school table describes: asked for, or today.
$day = row_date($_GET['date'] ?? '') ?? $today;
$dayClasses = $merged[$day] ?? [];
$groups = [];
foreach ([[class_rows(), 'Schools'], [partner_rows(), 'Knowledge partners']] as [$classRows, $section]) {
    foreach ($classRows as $c) {
        $g = &$groups[$section][$c['school']];
        $g ??= ['classes' => 0, 'reported' => 0, 'readings' => 0];
        $g['classes']++;
        $key = class_key($c);
        if (isset($dayClasses[$key])) {
            $g['reported']++;
            $g['readings'] += count((array) $dayClasses[$key]);
        }
        unset($g);
    }
}

$push = is_array($cache['push'] ?? null) ? $cache['push'] : [];
$pushedAt = $push['at'] ?? (is_file(ATTENDANCE_CACHE_FILE) ? date('Y-m-d H:i:s', filemtime(ATTENDANCE_CACHE_FILE)) : '');
$pushAge = $pushedAt !== '' ? time() - strtotime($pushedAt) : null;
// Form rows the last push could not match to a class (a stale dropdown option).
$dropped = array_sum(array_map('intval', (array) ($push['skipped'] ?? [])));

$firstCounted = $traffic ? array_key_first($traffic) : null;
$fmtDay = fn(string $d): string => $d === $today ? 'Today' : date('D j M', strtotime($d));
$e = fn($v): string => htmlspecialchars((string) $v);

page_head('Usage', 'page-narrow');
$now = $rows[$today];
?>
<?php if ($pushAge !== null && $pushAge > 3 * 3600): ?>
  <?php page_notice('warn', 'No Form push has landed for ' . round($pushAge / 3600) . ' hours. Run pushStatus() in the Apps Script.'); ?>
<?php endif; ?>

<section class="card">
  <h2>Today</h2>
  <div class="stat-grid">
    <div class="stat"><span class="stat-value"><?= $now['visitors'] ?></span><span class="stat-label">Visitors</span></div>
    <div class="stat"><span class="stat-value"><?= $now['views'] ?></span><span class="stat-label">Page views</span></div>
    <div class="stat"><span class="stat-value"><?= $now['form'] + $now['app'] ?></span><span class="stat-label">Updates received</span></div>
    <div class="stat"><span class="stat-value"><?= $now['schools'] ?><small>/<?= count($schoolKeys) ?></small></span><span class="stat-label">Classes reported</span></div>
  </div>
  <p class="field-help">
    <?php if ($pushedAt !== ''): ?>Last Form push <?= $e(date('j M, H:i', strtotime($pushedAt))) ?><?php if (isset($push['rows'])): ?>, <?= (int) $push['rows'] ?> rows<?php endif; ?><?php if ($dropped): ?>, <b><?= $dropped ?> dropped</b> for naming no known class<?php endif; ?>.<?php else: ?>No Form push has landed yet.<?php endif; ?>
    <?= $now['partners'] ?> partner classes reported, <?= $now['faculty'] ?> faculty named.
  </p>
  <p class="field-help">
    <?php $total = $now['form'] + $now['app']; ?>
    <?= $now['app'] ?> marked in the app<?= $total ? ' (' . round(100 * $now['app'] / $total) . '%)' : '' ?>,
    <?= $now['teachers'] ?> teacher<?= $now['teachers'] === 1 ? '' : 's' ?> used the app today. <a href="activity.php">Who, on Activity.</a>
  </p>
</section>

<section class="card">
  <div class="stat-head">
    <h2>Last <?= $span ?> days</h2>
    <form method="get" class="stat-span no-print">
      <?php if ($day !== $today): ?><input type="hidden" name="date" value="<?= $e($day) ?>"><?php endif; ?>
      <label class="sr-only" for="stat-span">Days to show</label>
      <select id="stat-span" name="span" onchange="this.form.submit()">
        <?php foreach (STATS_SPANS as $n): ?><option value="<?= $n ?>"<?= $n === $span ? ' selected' : '' ?>>Last <?= $n ?> days</option><?php endforeach; ?>
      </select>
      <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
    </form>
  </div>
  <div class="stat-table-wrap">
    <table class="stat-table">
      <thead>
        <tr>
          <th scope="col">Day</th>
          <th scope="col">Visitors</th>
          <th scope="col">Views</th>
          <th scope="col" title="Lectures marked through the Google Form">Form</th>
          <th scope="col" title="Lectures marked in the app; one filed both ways counts here">App</th>
          <th scope="col">Classes</th>
          <th scope="col">Faculty</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $d => $r): ?>
          <tr<?= $d === $day ? ' class="is-selected"' : '' ?>>
            <th scope="row"><a href="stats.php?<?= $e(http_build_query(['date' => $d] + ($span === 14 ? [] : ['span' => $span]))) ?>"><?= $e($fmtDay($d)) ?></a></th>
            <td><?= $r['visitors'] ?: '<span class="stat-nil">0</span>' ?></td>
            <td><?= $r['views'] ?: '<span class="stat-nil">0</span>' ?></td>
            <td><?= $r['form'] ?: '<span class="stat-nil">0</span>' ?></td>
            <td><?= $r['app'] ?: '<span class="stat-nil">0</span>' ?></td>
            <td><?= ($r['schools'] + $r['partners']) ?: '<span class="stat-nil">0</span>' ?></td>
            <td><?= $r['faculty'] ?: '<span class="stat-nil">0</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="field-help">
    Visitors are distinct browsers in a day; views are pages opened, not counting
    the Form pushes (<?= $now['pushes'] ?> today) or the stylesheet and scripts the host's own
    hit counter includes. Updates are one reading per class per lecture, so a
    corrected Form row counts once. <?= $firstCounted ? 'Traffic counted since ' . $e(date('j M Y', strtotime($firstCounted))) . '.' : 'Traffic counting starts with the next visit.' ?>
  </p>
</section>


<?php foreach ($groups as $section => $list): ?>
<section class="card">
  <h2><?= $e($section) ?>, <?= $e($day === $today ? 'today' : date('j M', strtotime($day))) ?></h2>
  <table class="stat-table stat-table-groups">
    <thead><tr><th scope="col"><?= $section === 'Schools' ? 'School' : 'Partner' ?></th><th scope="col">Classes reported</th><th scope="col">Reported</th><th scope="col">Updates</th></tr></thead>
    <tbody>
      <?php foreach ($list as $id => $g): ?>
        <tr>
          <th scope="row"><?= $e(preg_replace('/^School of /', '', group_name($id))) ?></th>
          <td><?= $g['reported'] ?> <span class="stat-sub">of <?= $g['classes'] ?></span></td>
          <td><?= $g['classes'] ? round(100 * $g['reported'] / $g['classes']) . '%' : '<span class="stat-nil">-</span>' ?></td>
          <td><?= $g['readings'] ?: '<span class="stat-nil">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>
<section class="card">
  <h2>Footer clicks, <?= $e($day === $today ? 'today' : date('j M', strtotime($day))) ?></h2>
  <table class="stat-table stat-table-groups">
    <thead><tr><th scope="col">Link</th><th scope="col">Clicks</th><th scope="col">Browsers</th><th scope="col">Last <?= $span ?> days</th></tr></thead>
    <tbody>
      <?php foreach (TRAFFIC_LINKS as $key => $label):
        $total = 0;
        foreach ($dates as $d) $total += (int) ($traffic[$d]['clicks'][$key] ?? 0); ?>
        <tr>
          <th scope="row"><?= $e($label) ?></th>
          <td><?= ($traffic[$day]['clicks'][$key] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($traffic[$day]['clickers'][$key] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= $total ?: '<span class="stat-nil">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="field-help">Browsers are distinct browsers that clicked the link that day, so ten clicks from one person read as 10 clicks, 1 browser.</p>
</section>
<?php page_foot(); ?>
