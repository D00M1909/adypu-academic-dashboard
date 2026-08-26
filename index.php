<?php
require_once __DIR__ . '/includes/attendance.php';

$tree = get_attendance();
$totals = attendance_totals($tree);
$today = date('d M Y');

$knowledgePartners = [
    ['name' => 'Partner Institute of Technology', 'tag' => 'Engineering'],
    ['name' => 'Global Skills Academy',           'tag' => 'Management'],
    ['name' => 'Horizon Career Institute',        'tag' => 'Design'],
    ['name' => 'Sunrise Polytechnic',             'tag' => 'Engineering'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ADYPU Academic Dashboard</title>
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
  </defs>
</svg>

<header class="app-header">
  <h1>ADYPU Academic Dashboard</h1>
  <nav class="tabs">
    <button class="tab active" data-tab="adypu">ADYPU</button>
    <button class="tab" data-tab="partners">Knowledge Partner</button>
  </nav>
</header>

<main>
  <section class="stat-cards">
    <div class="stat-card">
      <div class="stat-label">Total Schools</div>
      <div class="stat-value"><?= count(SCHOOLS) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Strength</div>
      <div class="stat-value"><?= $totals['strength'] ?></div>
    </div>
    <div class="stat-card" id="present-today-card" title="Click for division-wise breakdown">
      <div class="stat-label">Present Today</div>
      <div class="stat-value"><?= $totals['present'] ?>/<?= $totals['strength'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Attendance Date</div>
      <div class="stat-value stat-value-date"><?= htmlspecialchars($today) ?></div>
    </div>
  </section>

  <div id="tab-adypu" class="tab-panel">
    <section class="tile-section">
      <div class="section-title">
        <span>Schools</span>
        <span class="section-meta"><?= count(SCHOOLS) ?> schools</span>
      </div>
      <div class="tile-grid schools-grid">
        <?php foreach (SCHOOLS as $id => $school): ?>
        <div class="tile school-tile" data-school="<?= htmlspecialchars($id) ?>">
          <div class="tile-icon"><svg class="tile-icon-svg"><use href="#icon-<?= htmlspecialchars($id) ?>"/></svg></div>
          <div class="tile-label"><?= htmlspecialchars($school['name']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="tile-section" id="years-section" hidden>
      <div class="section-title">
        <span id="years-title">Years</span>
        <span class="section-meta" id="years-meta"></span>
      </div>
      <div class="tile-grid years-grid" id="years-grid"></div>
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
          <div class="tile-tag"><?= htmlspecialchars($p['tag']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>

<div id="division-modal" class="modal-backdrop" hidden>
  <div class="modal">
    <div class="modal-header">
      <h2>Present Students Today</h2>
      <div class="modal-actions">
        <button id="modal-refresh" class="icon-btn" title="Refresh">&#8635;</button>
        <button id="modal-close" class="icon-btn" title="Close">&times;</button>
      </div>
    </div>
    <p class="modal-subtitle" id="modal-subtitle"></p>
    <div class="division-grid" id="division-grid"></div>
    <div class="division-total" id="division-total"></div>
  </div>
</div>

<script>
  window.ATTENDANCE_DATA = <?= json_encode($tree) ?>;
  window.SCHOOLS = <?= json_encode(SCHOOLS) ?>;
</script>
<script src="js/dashboard.js"></script>
</body>
</html>
