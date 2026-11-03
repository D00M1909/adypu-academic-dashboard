<?php
// The Events tab's report (events.php?view=report): every holiday and event in
// a range, for the schools, years and types ticked, saved as a PDF by printing
// it. Like the attendance report, every entry in the range is drawn once and
// js/events-report.js only hides rows and recounts, so only a date change
// reloads. Expects events.php's $years and $e.

$from = row_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-01');
$to = row_date((string) ($_GET['to'] ?? '')) ?? date('Y-m-t', strtotime($from));
if ($to < $from) [$from, $to] = [$to, $from];

$schoolIds = array_keys(SCHOOLS);
$pickedSchools = array_values(array_intersect((array) ($_GET['school'] ?? []), $schoolIds));
$university = ($_GET['scope'] ?? '') !== 'schools' || !$pickedSchools;
if ($university) $pickedSchools = $schoolIds;
$pickedYears = array_values(array_intersect((array) ($_GET['year'] ?? []), $years)) ?: $years;
$pickedTypes = array_values(array_intersect((array) ($_GET['type'] ?? []), array_keys(CALENDAR_TYPES))) ?: array_keys(CALENDAR_TYPES);

$short = fn(string $id): string => preg_replace('/^School of /', '', SCHOOLS[$id]['name']);
$entries = calendar_between(calendar_all(), $from, $to);
// date => the entries on it, in the range only.
$byDay = [];
foreach ($entries as $x) {
    $last = min($x['to'], $to);
    for ($d = max($x['from'], $from); $d <= $last; $d = date('Y-m-d', strtotime("$d +1 day"))) $byDay[$d][] = $x;
}
ksort($byDay);
$inRange = fn(array $x): int => (int) round((strtotime(min($x['to'], $to)) - strtotime(max($x['from'], $from))) / 86400) + 1;
$attrs = fn(array $x): string => ' data-type="' . $e($x['type']) . '" data-schools="' . $e(implode(' ', $x['schools'])) . '" data-years="' . $e(implode('|', $x['years'])) . '"';

$rangeLabel = range_label($from, $to);
$today = new DateTimeImmutable('today');
$termStart = $today->modify((int) $today->format('n') >= 7 ? 'first day of july this year' : 'first day of july last year');
$presets = [
    'This month' => [$today->format('Y-m-01'), $today->format('Y-m-t')],
    'Last month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')],
    'Academic year' => [$termStart->format('Y-m-d'), $termStart->modify('+1 year -1 day')->format('Y-m-d')],
];
$query = fn(array $range): string => '?' . http_build_query(array_filter([
    'view' => 'report', 'from' => $range[0], 'to' => $range[1],
    'scope' => $university ? null : 'schools', 'school' => $university ? null : $pickedSchools,
    'year' => count($pickedYears) === count($years) ? null : $pickedYears,
    'type' => count($pickedTypes) === count(CALENDAR_TYPES) ? null : $pickedTypes,
]));

page_head('Events report', 'page-wide page-events page-report', 'events');
?>
<svg style="display:none" aria-hidden="true"><defs>
  <symbol id="icon-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></symbol>
</defs></svg>

<p class="evr-back no-print"><a href="events.php?<?= $e(http_build_query(['month' => substr($from, 0, 7)])) ?>"><svg aria-hidden="true"><use href="#icon-back"/></svg>Back to the calendar</a></p>

<form class="card rep-filters no-print" id="rep-form" method="get">
  <input type="hidden" name="view" value="report">
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
        <input type="date" name="from" value="<?= $e($from) ?>" aria-label="From">
        <span class="rep-to">to</span>
        <input type="date" name="to" value="<?= $e($to) ?>" aria-label="To">
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
      <legend class="rep-label">Years <button class="rep-link" type="button" data-all="year">All</button><button class="rep-link" type="button" data-none="year">None</button></legend>
      <div class="rep-chips">
        <?php foreach ($years as $y): ?>
        <label class="rep-chip"><input type="checkbox" name="year[]" value="<?= $e($y) ?>"<?= in_array($y, $pickedYears, true) ? ' checked' : '' ?>><span><?= $e($y) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <fieldset class="rep-field">
      <legend class="rep-label">Show</legend>
      <div class="rep-chips">
        <?php foreach (['holiday' => 'Holidays', 'event' => 'Events'] as $v => $l): ?>
        <label class="rep-chip"><input type="checkbox" name="type[]" value="<?= $v ?>"<?= in_array($v, $pickedTypes, true) ? ' checked' : '' ?>><span><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="rep-actions">
      <noscript><button class="range-export" type="submit">Apply</button></noscript>
      <button class="rep-export" type="button" id="rep-export"><svg aria-hidden="true"><use href="#icon-print"/></svg>Export PDF</button>
    </div>
  </div>
</form>

<article class="rep-doc" id="rep-doc">
  <header class="rep-head">
    <div>
      <h2 class="rep-title" id="rep-title">University holidays and events</h2>
      <p class="rep-sub" id="rep-sub"></p>
    </div>
    <p class="rep-when"><?= $e($rangeLabel) ?><?php $span = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1; ?><span><?= $span ?> day<?= $span === 1 ? '' : 's' ?></span></p>
  </header>

  <div class="rep-stats evr-stats">
    <div class="rep-stat"><span>Holidays</span><strong id="st-holidays">0</strong></div>
    <div class="rep-stat"><span>Days off</span><strong><b id="st-holiday-days">0</b><small> days</small></strong></div>
    <div class="rep-stat"><span>Events</span><strong id="st-events">0</strong></div>
    <div class="rep-stat"><span>Days with an event</span><strong><b id="st-event-days">0</b><small> days</small></strong></div>
  </div>

  <section class="rep-section" id="evr-list">
    <div class="section-title"><h2>Holidays and events</h2><span class="section-meta" id="evr-list-meta"></span></div>
    <table class="report-table rep-table evr-table">
      <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Dates</th><th scope="col">Days</th><th scope="col">For</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $x): $n = $inRange($x); ?>
        <tr class="evr-row" data-days="<?= $n ?>"<?= $attrs($x) ?>>
          <th scope="row"><?= $e($x['title']) ?></th>
          <td><?= calendar_tag(['title' => CALENDAR_TYPES[$x['type']]] + $x) ?></td>
          <td><?= $e(calendar_when($x)) ?></td>
          <td><?= $n ?></td>
          <td><?= $e(calendar_scope($x)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="rep-section" id="evr-days">
    <div class="section-title"><h2>Day by day</h2></div>
    <table class="report-table rep-table evr-table">
      <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Name</th><th scope="col">For</th></tr></thead>
      <?php foreach ($byDay as $d => $list): ?>
      <tbody class="evr-day" data-date="<?= $e($d) ?>">
        <?php foreach ($list as $x): ?>
        <tr class="evr-row"<?= $attrs($x) ?>>
          <th scope="row"><span class="evr-date"><?= $e(date('D j M Y', strtotime($d))) ?></span></th>
          <td><?= calendar_tag(['title' => CALENDAR_TYPES[$x['type']]] + $x) ?></td>
          <td><?= $e($x['title']) ?></td>
          <td><?= $e(calendar_scope($x)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endforeach; ?>
    </table>
  </section>

  <p class="rep-empty" id="rep-empty" hidden>No holidays or events in these dates for what is ticked.</p>
  <p class="rep-foot">ADYPU Academic Dashboard &middot; <?= $e($rangeLabel) ?> &middot; An entry for all schools or all years counts for every school and year ticked.</p>
</article>

<script>
  window.EVENTS_REPORT = <?= json_encode([
      'schools' => array_map($short, array_combine($schoolIds, $schoolIds)),
      'years' => $years, 'from' => $from, 'to' => $to, 'range' => $rangeLabel,
  ]) ?>;
</script>
<script src="js/events-report.js?v=<?= filemtime(__DIR__ . '/../js/events-report.js') ?>"></script>
<?php page_foot(); ?>
