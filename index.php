<?php
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/structure.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';

// The numbers change whenever Google pushes, which is any time. Without this
// the host's edge serves a page rendered before the last push.
header('Cache-Control: no-store');

// The dashboard stays open to everyone; this only reads an existing session so
// the header can show whose it is. Nothing here requires a login.
auth_boot();

// Which tree the page draws. The Knowledge Partner tab is this same page over
// partner_rows() instead of class_rows(): its own tiles, totals, charts and
// reported pill, and not one partner class inside a school number.
$isPartners = ($_GET['view'] ?? '') === 'partners';
$groups = $isPartners ? partner_groups() : SCHOOLS;
$groupRows = $isPartners ? partner_rows() : class_rows();

// The range the page is showing. Both ends missing means the newest day that
// has data (resolve_range), so the landing view is one real, labelled day and
// never the empty page "today" gives every morning before the first form.
$days = get_attendance_days();
[$from, $to] = resolve_range($days, row_date($_GET['from'] ?? ''), row_date($_GET['to'] ?? ''));
$tree = $days || $isPartners ? aggregate_days($days, $from, $to, $groupRows) : get_attendance($from, $to);
$totals = attendance_totals($tree);
$rangeLabel = range_label($from, $to);
$overallPct = attendance_pct($totals);

// Only the days on screen go to the client, for the charts. A month is about
// 60KB of JSON inline; a year would be 600KB, which is the ceiling on picking
// a very long range (see the ponytail note on day_map()).
// Sent whole, one entry per lecture, because the printed report lists them.
// charts.js collapses a day to its latest reading with the same rule
// day_present() uses, so the trend line and the tiles cannot drift apart.
$classStrength = [];
foreach ($groupRows as $c) $classStrength[class_key($c)] = $c['strength'];
// Only this view's classes: the charts scope by key prefix, and at the top of
// the page the prefix is '', which would sweep the other tree's readings in.
$rangeDays = [];
foreach ($days as $d => $classes) {
    if ($d < $from || $d > $to) continue;
    $mine = array_intersect_key($classes, $classStrength);
    if ($mine) $rangeDays[$d] = $mine;
}
$dataDates = $days ? [min(array_keys($days)), max(array_keys($days))] : [$from, $to];

// Which preset chip the current range is, so the bar shows what you are
// looking at. Date-only strings must stay date-only: parsing them in the
// browser's local timezone used to make a 7-day click become an 8-day range.
// PHP uses the dashboard's Asia/Kolkata timezone (set in attendance.php), and
// DateTimeImmutable avoids strtotime treating "-6" like a timezone offset.
$presetRanges = [
    'latest' => [$dataDates[1], $dataDates[1]],
    'today' => [date('Y-m-d'), date('Y-m-d')],
    '7' => [(new DateTimeImmutable($dataDates[1]))->modify('-6 days')->format('Y-m-d'), $dataDates[1]],
    '30' => [(new DateTimeImmutable($dataDates[1]))->modify('-29 days')->format('Y-m-d'), $dataDates[1]],
];
$requestedPreset = $_GET['preset'] ?? '';
if (!isset($presetRanges[$requestedPreset])) $requestedPreset = '';

// `today` and `latest` can resolve to exactly the same dates. Keep the
// explicit intent from a chip click so the control the user selected lights
// up, while direct/custom ranges still fall back to their matching chip.
$activePreset = '';
if ($requestedPreset && [$from, $to] === $presetRanges[$requestedPreset]) {
    $activePreset = $requestedPreset;
} else {
    foreach (['latest', 'today', '7', '30'] as $preset) {
        if ([$from, $to] === $presetRanges[$preset]) {
            $activePreset = $preset;
            break;
        }
    }
}
$presetClass = fn(string $p): string => 'range-preset' . ($activePreset === $p ? ' is-active' : '');

// Attendance status band. Mirrored by attClass() in js/dashboard.js — change
// both together, and --att-* in dashboard.css with them.
function att_class(int $pct): string {
    if ($pct >= 75) return 'att-good';
    if ($pct >= 70) return 'att-warn';
    return 'att-low';
}

// When the numbers on screen were last written. The edge caches pages and the
// push can silently stop (see the debugging order in CLAUDE.md), so "is this
// stale?" is the first question anyone asks of this page and it had no answer
// on it. Both writers count: the hourly Google push replaces the cache file,
// and mark.php appends to its own store.
$writeTimes = [];
foreach ([ATTENDANCE_CACHE_FILE, function_exists('store_path') ? store_path('submissions') : null] as $f) {
    if ($f !== null && is_file($f)) $writeTimes[] = filemtime($f);
}
$lastWrite = $writeTimes ? max($writeTimes) : null;
$freshness = $lastWrite === null
    ? 'No data yet'
    : 'Updated ' . (date('Y-m-d', $lastWrite) === date('Y-m-d')
        ? date('H:i', $lastWrite)
        : date('j M, H:i', $lastWrite));

// Precomputed so the section heading can say how many schools actually
// reported before the grid that proves it is rendered.
$schoolTotals = [];
$reportingSchools = 0;
foreach ($groups as $sid => $sch) {
    $schoolTotals[$sid] = attendance_totals([$sid => $tree[$sid] ?? []]);
    if ($schoolTotals[$sid]['reported'] > 0) $reportingSchools++;
}

// "School of Engineering" -> "Engineering"; the tile has no room for the prefix.
$groupNoun = $isPartners ? 'partner' : 'school';

// The tabs are links, so switching keeps the range on screen.
$tabHref = fn(bool $partners): string => '?' . http_build_query(array_filter([
    'view' => $partners ? 'partners' : null,
    'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'preset' => $_GET['preset'] ?? null,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ADYPU Academic Dashboard</title>
<link rel="icon" href="img/favicon.png?v=<?= filemtime(__DIR__ . '/img/favicon.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>">
<meta name="theme-color" content="#7f1420">
<script>
// Inline and before the body on purpose: every range change is a full page
// load, and resolving the theme from a deferred script would flash white each
// time. data-theme is always set to a concrete value, so the stylesheet needs
// one selector rather than a media query saying the same thing again.
try {
  document.documentElement.dataset.theme = localStorage.getItem('adypu-theme')
    || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
} catch (e) {
  document.documentElement.dataset.theme = 'light';
}
</script>
</head>
<body>

<svg style="display:none" aria-hidden="true">
  <defs>
    <symbol id="icon-eng" viewBox="0 0 24 24">
      <path d="M11 10.27 7 3.34" />
      <path d="m11 13.73-4 6.93" />
      <path d="M12 22v-2" />
      <path d="M12 2v2" />
      <path d="M14 12h8" />
      <path d="m17 20.66-1-1.73" />
      <path d="m17 3.34-1 1.73" />
      <path d="M2 12h2" />
      <path d="m20.66 17-1.73-1" />
      <path d="m20.66 7-1.73 1" />
      <path d="m3.34 17 1.73-1" />
      <path d="m3.34 7 1.73 1" />
      <circle cx="12" cy="12" r="2" />
      <circle cx="12" cy="12" r="8" />
    </symbol>
    <symbol id="icon-mark" viewBox="0 0 24 24">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
      <path d="m9 11 3 3L22 4" />
    </symbol>
    <symbol id="icon-mgmt" viewBox="0 0 24 24">
      <path d="M3 3v16a2 2 0 0 0 2 2h16" />
      <path d="M18 17V9" />
      <path d="M13 17V5" />
      <path d="M8 17v-3" />
    </symbol>
    <symbol id="icon-law" viewBox="0 0 24 24">
      <path d="M12 3v18" />
      <path d="m19 8 3 8a5 5 0 0 1-6 0zV7" />
      <path d="M3 7h1a17 17 0 0 0 8-2 17 17 0 0 0 8 2h1" />
      <path d="m5 8 3 8a5 5 0 0 1-6 0zV7" />
      <path d="M7 21h10" />
    </symbol>
    <symbol id="icon-design" viewBox="0 0 24 24">
      <path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z" />
      <circle cx="13.5" cy="6.5" r=".5" fill="currentColor" />
      <circle cx="17.5" cy="10.5" r=".5" fill="currentColor" />
      <circle cx="6.5" cy="12.5" r=".5" fill="currentColor" />
      <circle cx="8.5" cy="7.5" r=".5" fill="currentColor" />
    </symbol>
    <symbol id="icon-science" viewBox="0 0 24 24">
      <path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2" />
      <path d="M6.453 15h11.094" />
      <path d="M8.5 2h7" />
    </symbol>
    <symbol id="icon-arch" viewBox="0 0 24 24">
      <path d="M10 18v-7" />
      <path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z" />
      <path d="M14 18v-7" />
      <path d="M18 18v-7" />
      <path d="M3 22h18" />
      <path d="M6 18v-7" />
    </symbol>
    <symbol id="icon-hosp" viewBox="0 0 24 24">
      <path d="M3 20a1 1 0 0 1-1-1v-1a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v1a1 1 0 0 1-1 1Z" />
      <path d="M20 16a8 8 0 1 0-16 0" />
      <path d="M12 4v4" />
      <path d="M10 4h4" />
    </symbol>
    <symbol id="icon-lib" viewBox="0 0 24 24">
      <path d="M12 5v16" />
      <path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z" />
    </symbol>
    <symbol id="icon-film" viewBox="0 0 24 24">
      <path d="m12.296 3.464 3.02 3.956" />
      <path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3z" />
      <path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
      <path d="m6.18 5.276 3.1 3.899" />
    </symbol>
    <symbol id="icon-partner" viewBox="0 0 24 24">
      <path d="m11 17 2 2a1 1 0 1 0 3-3" />
      <path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4" />
      <path d="m21 3 1 11h-2" />
      <path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3" />
      <path d="M3 4h8" />
    </symbol>
    <symbol id="icon-user" viewBox="0 0 24 24">
      <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
      <circle cx="12" cy="7" r="4" />
    </symbol>
    <symbol id="icon-out" viewBox="0 0 24 24">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <path d="m16 17 5-5-5-5" />
      <path d="M21 12H9" />
    </symbol>
    <symbol id="icon-user-check" viewBox="0 0 24 24">
      <path d="m16 11 2 2 4-4" />
      <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
    </symbol>
    <symbol id="icon-refresh" viewBox="0 0 24 24">
      <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" />
      <path d="M21 3v5h-5" />
      <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" />
      <path d="M8 16H3v5" />
    </symbol>
    <symbol id="icon-x" viewBox="0 0 24 24">
      <path d="M18 6 6 18" />
      <path d="m6 6 12 12" />
    </symbol>
    <symbol id="icon-chevron" viewBox="0 0 24 24">
      <path d="m9 18 6-6-6-6" />
    </symbol>
    <symbol id="icon-caret" viewBox="0 0 24 24">
      <path d="m6 9 6 6 6-6" />
    </symbol>
    <symbol id="icon-sun" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2" />
      <path d="M12 20v2" />
      <path d="m4.93 4.93 1.41 1.41" />
      <path d="m17.66 17.66 1.41 1.41" />
      <path d="M2 12h2" />
      <path d="M20 12h2" />
      <path d="m6.34 17.66-1.41 1.41" />
      <path d="m19.07 4.93-1.41 1.41" />
    </symbol>
    <symbol id="icon-moon" viewBox="0 0 24 24">
      <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />
    </symbol>
  </defs>
</svg>

<header class="app-header">
  <div class="header-inner">
    <div class="brand">
      <div class="brand-logo"><img src="img/logo.png" alt="Ajeenkya D Y Patil University"></div>
      <h1>ADYPU: Academic Dashboard</h1>
    </div>
    <div class="header-controls">
      <nav class="tabs" aria-label="View">
        <a class="tab<?= $isPartners ? '' : ' active' ?>" href="<?= htmlspecialchars($tabHref(false)) ?>"<?= $isPartners ? '' : ' aria-current="page"' ?>>ADYPU</a>
        <a class="tab<?= $isPartners ? ' active' : '' ?>" href="<?= htmlspecialchars($tabHref(true)) ?>"<?= $isPartners ? ' aria-current="page"' : '' ?>>Knowledge Partner</a>
      </nav>
      <button class="theme-toggle no-print" id="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false" title="Switch theme">
        <svg class="theme-icon-moon" aria-hidden="true"><use href="#icon-moon"/></svg>
        <svg class="theme-icon-sun" aria-hidden="true"><use href="#icon-sun"/></svg>
      </button>
      <a class="header-link no-print" href="mark.php">
        <svg aria-hidden="true"><use href="#icon-mark"/></svg><span>Mark attendance</span>
      </a>
      <?= account_menu() ?>
    </div>
  </div>
</header>

<main>

  <form class="range-bar" method="get" id="range-form">
    <input type="hidden" name="preset" id="range-preset" value="<?= htmlspecialchars($activePreset) ?>">
    <?php if ($isPartners): ?><input type="hidden" name="view" value="partners"><?php endif; ?>
    <div class="range-presets">
      <button class="<?= $presetClass('latest') ?>" type="button" data-preset="latest" aria-pressed="<?= $activePreset === 'latest' ? 'true' : 'false' ?>"><?php /* Five chips have to fit one row on a 375px phone, or the dock grows a
             whole line. Only this label is long enough to matter. */ ?><span class="chip-wide">Latest day</span><span class="chip-narrow">Latest</span></button>
      <button class="<?= $presetClass('today') ?>" type="button" data-preset="today" aria-pressed="<?= $activePreset === 'today' ? 'true' : 'false' ?>">Today</button>
      <button class="<?= $presetClass('7') ?>" type="button" data-preset="7" aria-pressed="<?= $activePreset === '7' ? 'true' : 'false' ?>">7 days</button>
      <button class="<?= $presetClass('30') ?>" type="button" data-preset="30" aria-pressed="<?= $activePreset === '30' ? 'true' : 'false' ?>">30 days</button>
      <?php /* A fifth chip on a phone, and nothing at all on a desktop that has
               room for the inputs themselves. */ ?>
      <button class="range-preset range-more no-print" id="range-more" type="button" aria-expanded="false" aria-controls="range-dates">Dates</button>
    </div>
    <button class="range-summary" id="stat-summary" type="button">
      <span class="range-summary-top">
        <span class="range-summary-scope" id="stat-scope">Present &middot; <?= htmlspecialchars($rangeLabel) ?></span>
        <span class="range-updated" title="When the data behind this page was last written"><?= htmlspecialchars($freshness) ?></span>
      </span>
      <span class="range-summary-figures">
        <span class="range-summary-count"><span id="stat-present"><?= $totals['present'] ?></span><span class="range-summary-sep">/</span><span id="stat-strength"><?= $totals['strength_reported'] ?></span></span>
        <span class="att-pct <?= att_class($overallPct) ?>" id="stat-pct"><?= $overallPct ?>%</span>
        <span class="range-summary-coverage"><span id="stat-reported"><?= $totals['reported'] ?></span> of <span id="stat-classes"><?= $totals['classes'] ?></span> reported</span>
        <svg class="range-summary-icon" aria-hidden="true"><use href="#icon-chevron"/></svg>
      </span>
    </button>
    <div class="range-dates" id="range-dates">
      <label for="range-from">From</label>
      <input type="date" id="range-from" name="from" value="<?= htmlspecialchars($from) ?>" min="<?= htmlspecialchars($dataDates[0]) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>">
      <label for="range-to">To</label>
      <input type="date" id="range-to" name="to" value="<?= htmlspecialchars($to) ?>" min="<?= htmlspecialchars($dataDates[0]) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>">
      <button class="range-apply" type="submit">Apply</button>
      <button class="range-export no-print" id="range-export" type="button" title="In the print dialog, choose Save as PDF: the Windows Print to PDF driver ignores the page orientation and the file name.">Export PDF</button>
    </div>
    <span class="print-range">Date range: <?= htmlspecialchars($rangeLabel) ?> &middot; <?= htmlspecialchars($freshness) ?></span>
  </form>

  <div id="tab-adypu" class="tab-panel">
    <nav class="breadcrumb" id="breadcrumb" aria-label="Drill-down path" hidden></nav>

    <section class="tile-section" id="schools-section">
      <div class="section-title">
        <h2><?= $isPartners ? 'Knowledge Partners' : 'Schools' ?></h2>
        <?php /* How many schools filed anything, not just how many exist. Eight
                 "Not reported" tiles beside one real number made the page read
                 as empty when it was not; the heading now says which it is. */ ?>
        <span class="section-meta" id="schools-meta"><?= $reportingSchools ?> of <?= count($groups) ?> reporting</span>
      </div>
      <div class="tile-grid schools-grid" id="schools-grid" data-reporting="<?= $reportingSchools ?>">
        <?php foreach ($groups as $id => $school):
          $st = $schoolTotals[$id];
          $stPct = attendance_pct($st);
        ?>
        <button class="tile school-tile<?= $st['reported'] === 0 ? ' tile-quiet' : '' ?>" type="button" data-school="<?= htmlspecialchars($id) ?>">
          <svg class="tile-icon-svg"><use href="#icon-<?= $isPartners ? 'partner' : htmlspecialchars($id) ?>"/></svg>
          <span class="tile-label"><?= htmlspecialchars($school['name']) ?></span>
          <?php if ($st['classes'] === 0): ?>
          <span class="tile-stat tile-unreported">No programs yet</span>
          <span class="division-bar"><span class="division-bar-fill" style="width:0"></span></span>
          <?php elseif ($st['reported'] === 0 && ($school['placeholder'] ?? is_placeholder_school($id))): ?>
          <span class="tile-stat tile-unreported">Not reported</span>
          <span class="division-bar"><span class="division-bar-fill" style="width:0"></span></span>
          <?php elseif ($st['reported'] === 0): ?>
          <span class="tile-stat tile-unreported"><?= $st['strength'] ?> students</span>
          <span class="division-bar"><span class="division-bar-fill" style="width:0"></span></span>
          <span class="tile-meta">Not reported</span>
          <?php else: ?>
          <span class="tile-stat"><?= $st['present'] ?><span class="tile-stat-sep">/</span><?= $st['strength_reported'] ?> &middot; <span class="att-pct <?= att_class($stPct) ?>"><?= $stPct ?>%</span></span>
          <span class="division-bar"><span class="division-bar-fill <?= att_class($stPct) ?>" style="width:<?= $stPct ?>%"></span></span>
          <span class="tile-meta<?= $st['reported'] < $st['classes'] ? ' tile-meta-gap' : '' ?>"><?= $st['reported'] ?> of <?= $st['classes'] ?> classes reported</span>
          <?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="tile-section" id="years-section" hidden>
      <div class="section-title">
        <h2>Years</h2>
        <span class="section-meta" id="years-meta"></span>
      </div>
      <div class="tile-grid years-grid" id="years-grid"></div>
    </section>

    <section class="tile-section" id="branches-section" hidden>
      <div class="section-title">
        <h2><?= $isPartners ? 'Programs' : 'Branches' ?></h2>
        <span class="section-meta" id="branches-meta"></span>
      </div>
      <div class="tile-grid branches-grid" id="branches-grid"></div>
    </section>

    <section class="tile-section" id="divisions-section" hidden>
      <div class="section-title">
        <h2>Divisions</h2>
        <span class="section-meta" id="divisions-meta"></span>
      </div>
      <div class="tile-grid divisions-grid" id="divisions-grid"></div>
    </section>

    <section class="tile-section">
      <div class="section-title">
        <h2>Insights</h2>
        <span class="section-meta" id="charts-scope"></span>
      </div>
      <div class="chart-grid">
        <figure class="chart-card">
          <figcaption>Present vs absent</figcaption>
          <div class="chart-body" id="chart-donut"></div>
        </figure>
        <figure class="chart-card">
          <figcaption>Attendance by day</figcaption>
          <div class="chart-body" id="chart-trend"></div>
        </figure>
        <figure class="chart-card">
          <figcaption id="chart-bars-caption">Attendance by school</figcaption>
          <div class="chart-body" id="chart-bars"></div>
        </figure>
        <figure class="chart-card">
          <figcaption>Classes reporting each day</figcaption>
          <div class="chart-body" id="chart-compliance"></div>
        </figure>
      </div>
    </section>

    <!-- Every class under the current selection, filled by js/charts.js. Shown
         only when the selection still has more than one group under it (a
         school, or a year of a school that has branches): drill as far as a
         branch and the divisions grid above already is this table, so printing
         both would say everything twice. -->
    <section class="tile-section" id="breakdown-section" hidden>
      <div class="section-title">
        <h2>Class breakdown</h2>
        <span class="section-meta" id="breakdown-meta"></span>
      </div>
      <table class="report-table">
        <thead>
          <tr>
            <th scope="col">Class</th>
            <th scope="col">Days reported</th>
            <th scope="col">Present</th>
            <th scope="col">Attendance</th>
          </tr>
        </thead>
        <tbody id="breakdown-rows"></tbody>
      </table>
    </section>

    <!-- The range, day by day. Filled by js/charts.js from the same scoped
         series the trend chart draws, so it follows the drill-down; hidden on a
         single-day range, where it would only repeat the stat tiles. Its real
         job is the PDF: a chart shows the shape of a fortnight, but a report
         has to state the numbers it was read from. -->
    <section class="tile-section" id="daybyday-section" hidden>
      <div class="section-title">
        <h2>Day by day</h2>
        <span class="section-meta" id="daybyday-meta"></span>
      </div>
      <table class="report-table">
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Classes reported</th>
            <th scope="col">Present</th>
            <th scope="col">Attendance</th>
          </tr>
        </thead>
        <tbody id="daybyday-rows"></tbody>
      </table>
    </section>
  </div>

</main>

<div id="division-modal" class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header">
      <div class="modal-heading">
        <h2 id="modal-title">Division-wise Attendance</h2>
        <p class="modal-subtitle" id="modal-subtitle"></p>
      </div>
      <div class="modal-actions">
        <button id="modal-refresh" class="icon-btn" type="button" aria-label="Refresh"><svg><use href="#icon-refresh"/></svg></button>
        <button id="modal-close" class="icon-btn" type="button" aria-label="Close"><svg><use href="#icon-x"/></svg></button>
      </div>
    </div>
    <div class="division-grid" id="division-grid"></div>
    <div class="division-total" id="division-total"></div>
  </div>
</div>

<script>
  window.ATTENDANCE_DATA = <?= json_encode($tree) ?>;
  <?php
  // The placeholder flag rides along with the name so every tile, bar and table
  // in JS can tell an invented strength from a counted one off one lookup.
  // On the partner view these are the partners, which is all the scripts need.
  $schoolsJs = [];
  foreach ($groups as $sid => $s) $schoolsJs[$sid] = $s + ['placeholder' => is_placeholder_school($sid)];
  ?>
  window.SCHOOLS = <?= json_encode($schoolsJs) ?>;
  window.DASHBOARD_VIEW = <?= json_encode(['partners' => $isPartners, 'noun' => $groupNoun]) ?>;
  window.ATTENDANCE_RANGE = <?= json_encode([
      'from' => $from, 'to' => $to, 'label' => $rangeLabel,
      'latest' => $dataDates[1], 'earliest' => $dataDates[0],
  ]) ?>;
  // Per-day, per-class present counts for the range on screen, and every
  // class's strength: between them the charts can rescope to any drill-down
  // without another request.
  window.ATTENDANCE_DAYS = <?= json_encode((object) $rangeDays) ?>;
  window.CLASS_STRENGTH = <?= json_encode($classStrength) ?>;
</script>
<script src="js/theme.js?v=<?= filemtime(__DIR__ . '/js/theme.js') ?>"></script>
<script src="js/charts.js?v=<?= filemtime(__DIR__ . '/js/charts.js') ?>"></script>
<script src="js/dashboard.js?v=<?= filemtime(__DIR__ . '/js/dashboard.js') ?>"></script>
</body>
</html>
