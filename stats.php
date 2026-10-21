<?php
// Usage, for admins: how much the app is being used, in a handful of big
// numbers. Today, then the last 7 days against the 7 before, then 30 days of
// attendance as bars (Form and app stacked), then who is signed up, and the
// detail underneath for when a number needs explaining.
//
// "Lectures marked" are readings: one class, one day, one lecture. A lecture
// filed both by the Form and in the app counts once, the same rule the
// dashboard itself uses, so this page and the tiles can never disagree.
// Visitors come from includes/traffic.php, accounts and sign-ins from
// includes/activity.php.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/structure.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
auth_require(true);

const STATS_DAYS = 30;

// Straight from the two stores, not get_attendance_days(): that falls back to
// sample rows on an empty install, and sample rows are not updates anyone sent.
$cache = read_attendance_cache() ?? [];
$form = remap_legacy_days(is_array($cache['days'] ?? null) ? $cache['days'] : []);
$app = remap_legacy_days(submitted('days'));
$appNames = remap_legacy_days(submitted('faculty'));
$merged = merge_readings($form, $app);
$traffic = traffic_days();
$users = auth_data()['users'];

// The distinct people on the app's saves over some days, by name.
$teachersIn = function (array $days) use ($appNames): int {
    $names = [];
    foreach ($days as $d) foreach ((array) ($appNames[$d] ?? []) as $times) foreach ((array) $times as $n) $names[activity_person((string) $n)] = true;
    unset($names['']);
    return count($names);
};
$schoolKeys = array_flip(array_map('class_key', class_rows()));
$count = fn(array $classes): int => array_sum(array_map(fn($r) => count((array) $r), $classes));

$today = date('Y-m-d');
$dates = [];
for ($i = STATS_DAYS - 1; $i >= 0; $i--) $dates[] = date('Y-m-d', strtotime("-$i day"));

// One row a day: what the numbers and the bars are made of.
$rows = [];
foreach ($dates as $d) {
    $views = $traffic[$d]['views'] ?? [];
    $all = $count($merged[$d] ?? []);
    $inApp = $count($app[$d] ?? []);
    $rows[$d] = [
        'lectures' => $all,
        'app' => $inApp,
        'form' => $all - $inApp,
        'teachers' => $teachersIn([$d]),
        'classes' => count(array_intersect_key($merged[$d] ?? [], $schoolKeys)),
        'visitors' => (int) ($traffic[$d]['visitors'] ?? 0),
        'views' => array_sum($views) - (int) ($views['push'] ?? 0),
        'pages' => array_diff_key($views, ['push' => 1]),
    ];
}

// Sums over a run of days, and the change from the run before it.
$sum = fn(array $days, string $k): int => array_sum(array_map(fn($d) => $rows[$d][$k] ?? 0, $days));
$week = array_slice($dates, -7);
$before = array_slice($dates, -14, 7);
$change = function (int $now, int $then): string {
    if ($then === 0) return $now > 0 ? 'new this week' : '';
    $p = (int) round(100 * ($now - $then) / $then);
    return ($p > 0 ? '+' : '') . $p . '% on the week before';
};
$share = fn(int $part, int $whole): int => $whole ? (int) round(100 * $part / $whole) : 0;

// Accounts: who exists, who can mark, who has actually used it.
$active = array_filter($users, fn($u) => ($u['status'] ?? '') === 'active' && empty($u['admin']));
$pending = array_filter($users, fn($u) => ($u['status'] ?? '') === 'pending');
$todayLog = activity_summary(activity_day($today));
$signedInToday = count(array_filter(array_keys($todayLog), fn($k) => isset($active[$k]) && ($todayLog[$k]['signins'] + $todayLog[$k]['views'] + $todayLog[$k]['marks']) > 0));
// Accounts that have filed in the app at all, by the name on their saves.
$everNames = [];
foreach ($appNames as $classes) foreach ((array) $classes as $times) foreach ((array) $times as $nm) $everNames[activity_person((string) $nm)] = true;
$usedIt = count(array_filter($active, fn($u) => isset($everNames[activity_person((string) ($u['name'] ?? ''))])));

$push = is_array($cache['push'] ?? null) ? $cache['push'] : [];
$pushedAt = $push['at'] ?? (is_file(ATTENDANCE_CACHE_FILE) ? date('Y-m-d H:i:s', filemtime(ATTENDANCE_CACHE_FILE)) : '');
$pushAge = $pushedAt !== '' ? time() - strtotime($pushedAt) : null;
$dropped = array_sum(array_map('intval', (array) ($push['skipped'] ?? [])));

$t = $rows[$today];
$peak = max([1, ...array_column($rows, 'lectures')]);
$peakVisitors = max([1, ...array_column($rows, 'visitors')]);
$e = fn($v): string => htmlspecialchars((string) $v);
$n = fn(int $v): string => number_format($v);

// The pages people open, over the 30 days, most first.
$pageNames = ['dashboard' => 'Dashboard', 'rooms' => 'Timetable', 'mark' => 'Mark attendance', 'class' => 'Class details',
    'login' => 'Sign in', 'account' => 'Your account', 'admin' => 'Faculty accounts', 'activity' => 'Activity', 'source' => 'Timetable sources'];
$pages = [];
foreach ($rows as $r) foreach ($r['pages'] as $p => $v) $pages[$p] = ($pages[$p] ?? 0) + $v;
arsort($pages);
$pagesTotal = max(1, array_sum($pages));

page_head('Usage', 'page-wide');
admin_tabs('usage');
?>
<?php if ($pushAge !== null && $pushAge > 3 * 3600): ?>
  <?php page_notice('warn', 'No Form push has landed for ' . round($pushAge / 3600) . ' hours. Run pushStatus() in the Apps Script.'); ?>
<?php endif; ?>

<section class="card use-card">
  <h2>Today <span class="use-date"><?= $e(date('l j M')) ?></span></h2>
  <div class="use-hero">
    <div class="use-num use-num-lead">
      <span class="use-value"><?= $n($t['lectures']) ?></span>
      <span class="use-label">Lectures marked</span>
      <span class="use-note"><?= $n($t['app']) ?> in the app, <?= $n($t['form']) ?> by the Form</span>
    </div>
    <div class="use-num">
      <span class="use-value"><?= $share($t['app'], $t['lectures']) ?><small>%</small></span>
      <span class="use-label">Marked in the app</span>
      <span class="use-bar" aria-hidden="true"><i style="width:<?= $share($t['app'], $t['lectures']) ?>%"></i></span>
    </div>
    <div class="use-num">
      <span class="use-value"><?= $n($t['teachers']) ?></span>
      <span class="use-label">Teachers marking in the app</span>
      <span class="use-note"><?= $n($signedInToday) ?> of <?= $n(count($active)) ?> accounts signed in today</span>
    </div>
    <div class="use-num">
      <span class="use-value"><?= $n($t['visitors']) ?></span>
      <span class="use-label">People on the site</span>
      <span class="use-note"><?= $n($t['views']) ?> pages opened</span>
    </div>
  </div>
</section>

<section class="card use-card">
  <h2>Last 7 days</h2>
  <div class="use-hero use-hero-week">
    <?php
      $wk = [
          ['Lectures marked', $sum($week, 'lectures'), $sum($before, 'lectures')],
          ['Marked in the app', $sum($week, 'app'), $sum($before, 'app')],
          ['Teachers using the app', $teachersIn($week), $teachersIn($before)],
          ['People on the site', $sum($week, 'visitors'), $sum($before, 'visitors')],
      ];
      foreach ($wk as [$label, $now, $then]): $c = $change($now, $then); ?>
      <div class="use-num">
        <span class="use-value"><?= $n($now) ?></span>
        <span class="use-label"><?= $e($label) ?></span>
        <?php if ($c !== ''): ?><span class="use-change<?= $now < $then ? ' is-down' : '' ?>"><?= $e($c) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card use-card">
  <h2>Lectures marked, last <?= STATS_DAYS ?> days</h2>
  <div class="use-chart" role="img" aria-label="Lectures marked each day for <?= STATS_DAYS ?> days, app and Form">
    <?php foreach ($rows as $d => $r): ?>
      <a class="use-col<?= $d === $today ? ' is-today' : '' ?>" href="activity.php?day=<?= $e($d) ?>&amp;span=1"
         title="<?= $e(date('D j M', strtotime($d)) . ": {$r['lectures']} lecture" . ($r['lectures'] === 1 ? '' : 's') . ", {$r['app']} in the app, {$r['form']} by the Form, {$r['visitors']} visitors") ?>">
        <span class="use-stack" style="height:<?= round(100 * $r['lectures'] / $peak) ?>%">
          <i class="use-form" style="flex-grow:<?= $r['form'] ?>"></i>
          <i class="use-app" style="flex-grow:<?= $r['app'] ?>"></i>
        </span>
        <span class="use-tick"><?= date('N', strtotime($d)) === '1' || $d === $today ? $e(date('j M', strtotime($d))) : '' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <ul class="tt-legend">
    <li><i class="tt-key use-app"></i>In the app</li>
    <li><i class="tt-key use-form"></i>By the Google Form</li>
    <li>Tap a day for its activity.</li>
  </ul>
</section>

<div class="use-pair">
  <section class="card use-card">
    <h2>Accounts</h2>
    <div class="use-hero use-hero-small">
      <div class="use-num"><span class="use-value"><?= $n(count($active)) ?></span><span class="use-label">Can mark</span></div>
      <div class="use-num"><span class="use-value"><?= $n($usedIt) ?></span><span class="use-label">Have marked in the app</span></div>
      <div class="use-num"><a class="use-value" href="admin.php"><?= $n(count($pending)) ?></a><span class="use-label">Waiting for approval</span></div>
    </div>
    <?php if (count($active) > $usedIt): ?>
      <p class="field-help"><?= $n(count($active) - $usedIt) ?> approved account<?= count($active) - $usedIt === 1 ? ' has' : 's have' ?> never saved attendance. <a href="activity.php?span=30">See who on Activity.</a></p>
    <?php endif; ?>
  </section>

  <section class="card use-card">
    <h2>People on the site, last <?= STATS_DAYS ?> days</h2>
    <div class="use-chart use-chart-small" role="img" aria-label="Visitors each day for <?= STATS_DAYS ?> days">
      <?php foreach ($rows as $d => $r): ?>
        <span class="use-col<?= $d === $today ? ' is-today' : '' ?>" title="<?= $e(date('D j M', strtotime($d)) . ": {$r['visitors']} visitors, {$r['views']} pages") ?>">
          <span class="use-stack" style="height:<?= round(100 * $r['visitors'] / $peakVisitors) ?>%"><i class="use-visit"></i></span>
        </span>
      <?php endforeach; ?>
    </div>
    <?php if ($pages): ?>
    <table class="stat-table stat-table-groups use-pages">
      <tbody>
        <?php foreach (array_slice($pages, 0, 5, true) as $p => $v): ?>
          <tr><th scope="row"><?= $e($pageNames[$p] ?? ucfirst($p)) ?></th><td><?= $n($v) ?></td><td class="stat-sub"><?= $share($v, $pagesTotal) ?>%</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</div>

<p class="field-help use-foot">
  <?php if ($pushedAt !== ''): ?>Last Google Form push <?= $e(date('j M, H:i', strtotime($pushedAt))) ?><?php if (isset($push['rows'])): ?>, <?= (int) $push['rows'] ?> rows<?php endif; ?><?= $dropped ? ", $dropped dropped for naming no known class" : '' ?>.<?php else: ?>No Google Form push has landed yet.<?php endif; ?>
  People are distinct browsers in a day, not counting bots or the Form pushes. Teachers in the app are counted by the name on their saves.
</p>
<?php page_foot(); ?>
