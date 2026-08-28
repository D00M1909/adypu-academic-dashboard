<?php
require_once __DIR__ . '/includes/attendance.php';

// The numbers change whenever Google pushes, which is any time. Without this
// the host's edge serves a page rendered before the last push.
header('Cache-Control: no-store');

$tree = get_attendance();
$totals = attendance_totals($tree);
$today = date('d M Y');
$overallPct = $totals['strength'] ? (int) round($totals['present'] / $totals['strength'] * 100) : 0;

// Read from the 'Partners Admission MIS' sheet of
// ../adypuacademicreport/ADYPU_Master_Dashboard_Data_July_2026.xlsx on
// 27 Aug 2026 — column B (Partner) with its blank rows forward-filled, and
// column A (School) collected per partner. Admission numbers deliberately not
// copied: they belong to the report project and would go stale here.
$knowledgePartners = [
    ['name' => 'Aero',      'schools' => ['eng']],
    ['name' => 'Newton',    'schools' => ['eng']],
    ['name' => 'Sunstone',  'schools' => ['eng', 'mgmt']],
    ['name' => 'NxtWave',   'schools' => ['eng']],
    ['name' => 'Emversity', 'schools' => ['science']],
    ['name' => 'Veloces',   'schools' => ['eng']],
    ['name' => 'SeamEdu',   'schools' => ['eng', 'mgmt', 'film', 'design']],
    ['name' => 'Upgrad',    'schools' => ['eng']],
    ['name' => 'PixelPop',  'schools' => ['eng']],
    ['name' => 'Flyglam',   'schools' => ['mgmt']],
];

// Attendance status band. Mirrored by attClass() in js/dashboard.js — change
// both together, and --att-* in dashboard.css with them.
function att_class(int $pct): string {
    if ($pct >= 85) return 'att-good';
    if ($pct >= 70) return 'att-warn';
    return 'att-low';
}

// "School of Engineering" -> "Engineering"; the tile has no room for the prefix.
$shortSchool = fn(string $id): string => preg_replace('/^School of /', '', SCHOOLS[$id]['name'] ?? $id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ADYPU Academic Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css?v=<?= filemtime(__DIR__ . '/css/dashboard.css') ?>">
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
  </defs>
</svg>

<header class="app-header">
  <div class="header-inner">
    <div class="brand">
      <div class="brand-logo"><img src="img/logo.png" alt="Ajeenkya D Y Patil University"></div>
      <h1>ADYPU: Academic Dashboard</h1>
    </div>
    <nav class="tabs" role="tablist">
      <button class="tab active" data-tab="adypu" role="tab" aria-selected="true">ADYPU</button>
      <button class="tab" data-tab="partners" role="tab" aria-selected="false">Knowledge Partner</button>
    </nav>
  </div>
</header>

<div class="accred-strip">
  <img src="img/accreditation-badges.jpeg" alt="NAAC A Grade Accreditation, NBA Accredited, NIRF Ranked, Great Place To Work Certified, Times Higher Education Sustainability Impact Rating, and QS I-Gauge Diamond Rating">
</div>

<main>

  <section class="stat-row">
    <button class="stat-hero" id="present-today-card" type="button">
      <svg class="stat-hero-icon"><use href="#icon-user-check"/></svg>
      <span class="stat-hero-text">
        <span class="stat-hero-label" id="stat-scope">Present Today &middot; <?= htmlspecialchars($today) ?></span>
        <span class="stat-hero-value"><span id="stat-present"><?= $totals['present'] ?></span><span class="stat-hero-sep">/</span><span id="stat-strength"><?= $totals['strength'] ?></span></span>
      </span>
      <span class="stat-hero-cta">View breakdown<svg class="stat-hero-cta-icon"><use href="#icon-chevron"/></svg></span>
    </button>

    <div class="stat-strip">
      <div class="stat-pill">
        <span class="stat-pill-value" id="stat-units"><?= count(SCHOOLS) ?></span>
        <span class="stat-pill-label" id="stat-units-label">Schools</span>
      </div>
      <div class="stat-pill">
        <span class="stat-pill-value att-pct <?= att_class($overallPct) ?>" id="stat-pct"><?= $overallPct ?>%</span>
        <span class="stat-pill-label">Attendance</span>
      </div>
      <div class="stat-pill">
        <span class="stat-pill-value" id="stat-reported"><?= $totals['reported'] ?><span class="stat-pill-sep">/</span><?= $totals['classes'] ?></span>
        <span class="stat-pill-label">Classes reported</span>
      </div>
    </div>
  </section>

  <div id="tab-adypu" class="tab-panel">
    <nav class="breadcrumb" id="breadcrumb" aria-label="Drill-down path" hidden></nav>

    <section class="tile-section">
      <div class="section-title">
        <h2>Schools</h2>
        <span class="section-meta"><?= count(SCHOOLS) ?> schools</span>
      </div>
      <div class="tile-grid schools-grid">
        <?php foreach (SCHOOLS as $id => $school):
          $st = attendance_totals([$id => $tree[$id] ?? []]);
          $stPct = $st['strength'] ? (int) round($st['present'] / $st['strength'] * 100) : 0;
        ?>
        <button class="tile school-tile" type="button" data-school="<?= htmlspecialchars($id) ?>">
          <svg class="tile-icon-svg"><use href="#icon-<?= htmlspecialchars($id) ?>"/></svg>
          <span class="tile-label"><?= htmlspecialchars($school['name']) ?></span>
          <?php if ($st['reported'] === 0): ?>
          <span class="tile-stat tile-unreported">Not reported</span>
          <span class="division-bar"><span class="division-bar-fill" style="width:0"></span></span>
          <?php else: ?>
          <span class="tile-stat"><?= $st['present'] ?><span class="tile-stat-sep">/</span><?= $st['strength'] ?> &middot; <span class="att-pct <?= att_class($stPct) ?>"><?= $stPct ?>%</span></span>
          <span class="division-bar"><span class="division-bar-fill <?= att_class($stPct) ?>" style="width:<?= $stPct ?>%"></span></span>
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
        <h2>Branches</h2>
        <span class="section-meta" id="branches-meta"></span>
      </div>
      <div class="tile-grid branches-grid" id="branches-grid"></div>
    </section>
  </div>

  <div id="tab-partners" class="tab-panel" hidden>
    <section class="tile-section">
      <div class="section-title">
        <span>Knowledge Partners</span>
        <span class="section-meta"><?= count($knowledgePartners) ?> partners</span>
      </div>
      <div class="tile-grid partners-grid">
        <?php foreach ($knowledgePartners as $p): ?>
        <div class="tile partner-tile">
          <div class="tile-label"><?= htmlspecialchars($p['name']) ?></div>
          <div class="tile-tag"><?= htmlspecialchars(implode(', ', array_map($shortSchool, $p['schools']))) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
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
  window.SCHOOLS = <?= json_encode(SCHOOLS) ?>;
  window.ATTENDANCE_DATE = <?= json_encode($today) ?>;
</script>
<script src="js/dashboard.js"></script>
</body>
</html>
