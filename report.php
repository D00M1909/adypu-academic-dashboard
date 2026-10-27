<?php
// The attendance report: pick schools, years and which classes to list, read
// it on screen, save it as a PDF. Every class in the range is drawn once, and
// js/report.js only hides rows and recounts, so changing a filter is instant
// and the page printed is always exactly the page on screen.
require_once __DIR__ . '/includes/report.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('report');

$days = get_attendance_days();
[$from, $to] = resolve_range($days, row_date($_GET['from'] ?? ''), row_date($_GET['to'] ?? ''));
$classRows = class_rows();
$tree = aggregate_days($days, $from, $to, $classRows);
$rows = report_rows($classRows, $tree, $days, get_attendance_faculty(), $from, $to,
    report_due_faculty(timetable_read(), $classRows, $from, $to));
$single = $from === $to;
$rangeLabel = range_label($from, $to);
$latest = $days ? max(array_keys($days)) : date('Y-m-d');

// The filters as the URL gives them. js/report.js keeps the URL in step, so a
// link copied from the address bar opens the same report.
$schoolIds = array_keys(array_filter(SCHOOLS, fn($k) => (bool) array_filter($rows, fn($r) => $r['school'] === $k), ARRAY_FILTER_USE_KEY));
$years = array_values(array_unique(array_column($rows, 'year')));
$pickedSchools = array_values(array_intersect((array) ($_GET['school'] ?? []), $schoolIds));
$university = ($_GET['scope'] ?? '') !== 'schools' || !$pickedSchools;
if ($university) $pickedSchools = $schoolIds;
$pickedYears = array_values(array_intersect((array) ($_GET['year'] ?? []), $years)) ?: $years;
$show = in_array($_GET['show'] ?? '', ['filled', 'missing'], true) ? $_GET['show'] : 'all';

$e = fn($v): string => htmlspecialchars((string) $v);
$short = fn(string $id): string => preg_replace('/^School of /', '', SCHOOLS[$id]['name']);
// A lecture slot as the timetable writes it: "09:30" is 9.30, "13:15" 1.15.
$clock = function (string $slot): string {
    $slot = preg_replace('/~\d+$/', '', $slot);
    if ($slot === WHOLE_DAY) return 'Whole day';
    return preg_replace_callback('/(\d{2}):(\d{2})/', fn($m) => ((int) $m[1] > 12 ? (int) $m[1] - 12 : (int) $m[1]) . '.' . $m[2],
        str_replace('-', ' to ', $slot));
};
$pct = fn(int $p, int $s): int => $s ? (int) round(min($p, $s) / $s * 100) : 0;
$band = fn(int $p): string => $p >= 75 ? 'att-good' : ($p >= 70 ? 'att-warn' : 'att-low');

// The date chips: the same presets as the dashboard, as links that keep the
// filters (js/report.js rewrites them as filters change).
$latestDay = new DateTimeImmutable($latest);
$presets = [
    'Latest day' => [$latest, $latest],
    '7 days' => [$latestDay->modify('-6 days')->format('Y-m-d'), $latest],
    '30 days' => [$latestDay->modify('-29 days')->format('Y-m-d'), $latest],
];
$query = fn(array $range): string => '?' . http_build_query(array_filter([
    'from' => $range[0], 'to' => $range[1],
    'scope' => $university ? null : 'schools', 'school' => $university ? null : $pickedSchools,
    'year' => count($pickedYears) === count($years) ? null : $pickedYears,
    'show' => $show === 'all' ? null : $show,
]));

page_head('Attendance report', 'page-wide page-report', 'attendance');
?>
<svg style="display:none" aria-hidden="true"><defs>
  <symbol id="icon-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></symbol>
  <symbol id="icon-dash" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-dasharray="3 3"/></symbol>
</defs></svg>

<form class="card rep-filters no-print" id="rep-form" method="get">
  <div class="rep-filter-row">
    <div class="rep-field">
      <span class="rep-label">Report for</span>
      <div class="tt-seg rep-seg" role="radiogroup" aria-label="Report for">
        <label><input type="radio" name="scope" value="university"<?= $university ? ' checked' : '' ?>><span>University</span></label>
        <label><input type="radio" name="scope" value="schools"<?= $university ? '' : ' checked' ?>><span>Pick schools</span></label>
      </div>
    </div>
    <div class="rep-field">
      <span class="rep-label">Dates</span>
      <div class="rep-dates">
        <nav class="tt-seg rep-seg" aria-label="Date range">
          <?php foreach ($presets as $label => $range): ?>
          <a href="<?= $e($query($range)) ?>" data-from="<?= $e($range[0]) ?>" data-to="<?= $e($range[1]) ?>"<?= [$from, $to] === $range ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <input type="date" name="from" value="<?= $e($from) ?>" max="<?= $e(date('Y-m-d')) ?>" aria-label="From">
        <span class="rep-to">to</span>
        <input type="date" name="to" value="<?= $e($to) ?>" max="<?= $e(date('Y-m-d')) ?>" aria-label="To">
      </div>
    </div>
  </div>

  <fieldset class="rep-field rep-schools" id="rep-schools"<?= $university ? ' hidden' : '' ?>>
    <legend class="rep-label">Schools <button class="rep-link" type="button" data-all="school">All</button><button class="rep-link" type="button" data-none="school">None</button></legend>
    <div class="rep-chips">
      <?php foreach ($schoolIds as $id): ?>
      <label class="rep-chip"><input type="checkbox" name="school[]" value="<?= $e($id) ?>"<?= in_array($id, $pickedSchools, true) ? ' checked' : '' ?>><span><?= $e($short($id)) ?></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="rep-filter-row">
    <fieldset class="rep-field">
      <legend class="rep-label">Years</legend>
      <div class="rep-chips">
        <?php foreach ($years as $y): ?>
        <label class="rep-chip"><input type="checkbox" name="year[]" value="<?= $e($y) ?>"<?= in_array($y, $pickedYears, true) ? ' checked' : '' ?>><span><?= $e($y) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="rep-field">
      <span class="rep-label">Show</span>
      <div class="tt-seg rep-seg" role="radiogroup" aria-label="Show">
        <?php foreach (['all' => 'All classes', 'filled' => 'Filled', 'missing' => 'Not filled'] as $v => $l): ?>
        <label><input type="radio" name="show" value="<?= $v ?>"<?= $show === $v ? ' checked' : '' ?>><span><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="rep-actions">
      <noscript><button class="range-export" type="submit">Apply</button></noscript>
      <button class="rep-export" type="button" id="rep-export"><svg aria-hidden="true"><use href="#icon-print"/></svg>Export PDF</button>
    </div>
  </div>
</form>

<article class="rep-doc" id="rep-doc" data-single="<?= $single ? '1' : '0' ?>">
  <header class="rep-head">
    <div>
      <h2 class="rep-title" id="rep-title">University attendance report</h2>
      <p class="rep-sub" id="rep-sub"></p>
    </div>
    <p class="rep-when"><?= $e($rangeLabel) ?><?= $single ? '' : '<span>Each class is the average of the days it filed</span>' ?></p>
  </header>

  <div class="rep-stats">
    <div class="rep-stat"><span>Classes filled</span><strong><b id="st-filled">0</b><small> of <b id="st-classes">0</b></small></strong></div>
    <div class="rep-stat"><span>Not filled</span><strong class="rep-miss" id="st-missing">0</strong></div>
    <div class="rep-stat"><span>Present</span><strong><b id="st-present">0</b><small> of <b id="st-strength">0</b></small></strong></div>
    <div class="rep-stat"><span>Attendance</span><strong id="st-pct">0%</strong></div>
  </div>

  <section class="rep-section rep-insights" id="rep-insights">
    <div class="section-title"><h2>Insights</h2><span class="section-meta no-print">Hover for detail, click to narrow the report</span></div>
    <div class="chart-grid">
      <figure class="chart-card">
        <figcaption>Attendance by day <span class="chart-caption-note">30 days to <?= $e(date('j M', strtotime($to))) ?></span></figcaption>
        <div class="chart-body" id="chart-trend"></div>
      </figure>
      <figure class="chart-card">
        <figcaption>Classes filing each day <span class="chart-caption-note">30 days to <?= $e(date('j M', strtotime($to))) ?></span></figcaption>
        <div class="chart-body" id="chart-compliance"></div>
      </figure>
      <figure class="chart-card">
        <figcaption>Attendance by lecture hour</figcaption>
        <div class="chart-body" id="chart-hours"></div>
      </figure>
      <figure class="chart-card chart-wide">
        <figcaption><span id="chart-compare-title">Compare schools</span></figcaption>
        <div class="chart-body" id="chart-compare"></div>
      </figure>
    </div>
  </section>

  <section class="rep-section" id="rep-summary">
    <div class="section-title"><h2>By school</h2></div>
    <table class="report-table rep-table">
      <thead><tr><th scope="col">School</th><th scope="col">Filled</th><th scope="col">Not filled</th><th scope="col">Present</th><th scope="col">Attendance</th></tr></thead>
      <tbody>
        <?php foreach ($schoolIds as $id): ?>
        <tr data-sum-school="<?= $e($id) ?>"><th scope="row"><?= $e($short($id)) ?></th><td data-f></td><td data-m></td><td data-p></td><td data-a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <?php foreach ($years as $y): ?>
  <section class="rep-section rep-year" data-year="<?= $e($y) ?>">
    <div class="section-title">
      <h2><?= $e($y) ?></h2>
      <span class="section-meta rep-year-meta"><span data-ymeta></span><span class="rep-bar" aria-hidden="true"><i data-ybar></i></span></span>
    </div>
    <table class="report-table rep-table">
      <thead><tr><th scope="col">Class</th><th scope="col">Present</th><th scope="col">Attendance</th><th scope="col">Faculty</th></tr></thead>
      <?php foreach (['filled' => true, 'missing' => false] as $status => $filed): ?>
      <tbody class="rep-group" data-group="<?= $status ?>">
        <tr class="rep-group-head"><th scope="rowgroup" colspan="4"><?= $filed ? 'Filled' : 'Not filled' ?> <span data-gcount></span><?= $filed ? '' : '<span class="rep-group-note">Faculty from the timetable</span>' ?></th></tr>
        <?php foreach ($rows as $r):
          if ($r['year'] !== $y || $r['reported'] !== $filed) continue;
          $label = implode(' / ', array_filter([$short($r['school']), $r['branch'], $r['division']], 'strlen'));
          $p = $pct($r['present'], $r['strength']);
        ?>
        <tr class="rep-row" data-school="<?= $e($r['school']) ?>" data-present="<?= $r['present'] ?>" data-strength="<?= $r['strength'] ?>">
          <th scope="row"><span class="rep-dot <?= $filed ? 'is-filled' : 'is-missing' ?>" aria-hidden="true"></span><?= $e($label) ?></th>
          <?php if ($filed): ?>
          <td><?php if ($r['estimated']): ?><?= $r['present'] ?> present<?php else: ?><?= $r['present'] ?><span class="report-table-sep">/</span><?= $r['strength'] ?><?php endif; ?>
            <?php if ($single && count($r['readings']) > 1): ?><span class="rep-note"><?= implode(' ', array_map(fn($x) => '<span>' . $e($clock($x['time'])) . ': ' . $x['present'] . '</span>', $r['readings'])) ?></span>
            <?php elseif (!$single): ?><span class="rep-note">avg of <?= $r['days'] ?> day<?= $r['days'] === 1 ? '' : 's' ?></span><?php endif; ?></td>
          <td><?= $r['estimated'] ? '<span class="rep-muted">Not known</span>' : '<span class="att-pct ' . $band($p) . '">' . $p . '%</span>' ?></td>
          <td class="rep-fac"><?= $r['filed_by'] ? $e(implode(', ', $r['filed_by'])) : '<span class="rep-muted">Name not recorded</span>' ?></td>
          <?php else: ?>
          <td colspan="2"><span class="rep-miss">Not filled</span></td>
          <td class="rep-fac"><?php if ($r['due']): ?><?= implode('', array_map(fn($d) => '<span class="rep-person">' . ($single && $d['time'] < 1440 ? '<span class="rep-time">' . $e(timetable_clock($d['time'])) . '</span>' : '') . $e($d['name']) . '</span>', $r['due'])) ?><?php else: ?><span class="rep-muted">Not on the timetable</span><?php endif; ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
  </section>
  <?php endforeach; ?>

  <p class="rep-empty" id="rep-empty" hidden>Nothing matches these filters. Tick a school or a year.</p>
  <p class="rep-foot">ADYPU Academic Dashboard &middot; <?= $e($rangeLabel) ?> &middot; Faculty for classes not filled are the ones the timetable lists for <?= $single ? 'that day' : 'those days' ?>.</p>
</article>

<?php
// What js/charts.js draws from, in the shapes index.php gives it: the range's
// tree, its days, and the 30 days to the range's end for the two daily charts.
$classStrength = [];
foreach ($classRows as $c) $classStrength[class_key($c)] = $c['strength'];
$trendFrom = (new DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
$rangeDays = $trendDays = [];
foreach ($days as $d => $classes) {
    $mine = array_intersect_key($classes, $classStrength);
    if (!$mine) continue;
    if ($d >= $from && $d <= $to) $rangeDays[$d] = $mine;
    if ($d >= $trendFrom && $d <= $to) $trendDays[$d] = $mine;
}
?>
<script>
  window.REPORT = <?= json_encode([
      'schools' => array_map($short, array_combine($schoolIds, $schoolIds)),
      'years' => $years, 'from' => $from, 'to' => $to, 'range' => $rangeLabel,
  ]) ?>;
  window.SCHOOLS = <?= json_encode(SCHOOLS) ?>;
  window.DASHBOARD_VIEW = <?= json_encode(['partners' => false, 'noun' => 'school',
      'programFirst' => PROGRAM_FIRST_SCHOOLS, 'flatYears' => (object) FLAT_YEARS]) ?>;
  window.ATTENDANCE_RANGE = <?= json_encode(['from' => $from, 'to' => $to, 'label' => $rangeLabel]) ?>;
  window.ATTENDANCE_DATA = <?= json_encode($tree) ?>;
  window.ATTENDANCE_DAYS = <?= json_encode((object) $rangeDays) ?>;
  window.TREND_DAYS = <?= json_encode((object) $trendDays) ?>;
  window.CLASS_STRENGTH = <?= json_encode($classStrength) ?>;
</script>
<script src="js/charts.js?v=<?= filemtime(__DIR__ . '/js/charts.js') ?>"></script>
<script src="js/report.js?v=<?= filemtime(__DIR__ . '/js/report.js') ?>"></script>
<?php page_foot(); ?>
