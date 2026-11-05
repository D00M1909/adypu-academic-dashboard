<?php
// The Insights on the Audit tab (Review and Summary): the homepage's chart
// cards, drawn on the server from audit_rows(). Every mark carries a data-tip
// (shown by js/audit.js in the homepage's .chart-tip) and, where there is
// somewhere to go, a data-href.

require_once __DIR__ . '/audit.php';

// $o: view, school, schools (the account's), deadline, today, when.
function audit_insights_html(array $rows, array $o): string {
    $e = fn($v): string => htmlspecialchars((string) $v);
    $tip = fn(string $html): string => ' data-tip="' . $e($html) . '"';
    $pct = fn(int $a, int $b): int => $b ? (int) round(100 * $a / $b) : 0;
    $deadline = $o['deadline'];
    $today = $o['today'];
    $multi = count($o['schools']) > 1;
    $keep = fn(array $q): string => 'audit.php?' . http_build_query(array_filter($q + ['school' => $multi ? $o['school'] : ''], fn($v) => $v !== ''));
    $short = fn(string $sid): string => preg_replace('/^School of /', '', SCHOOLS[$sid]['name'] ?? $sid);
    $total = count($rows);
    $label = $deadline === '' ? ['ontime' => 'Submitted', 'late' => 'Submitted late', 'pending' => 'Not submitted'] : AUDIT_TIMING_LABEL;

    $n = ['ontime' => 0, 'late' => 0, 'pending' => 0];
    $st = ['none' => 0, 'draft' => 0, 'submitted' => 0, 'approved' => 0];
    foreach ($rows as $r) {
        $n[$r['timing']]++;
        $st[$r['status'] === 'draft' && !$r['any'] ? 'none' : $r['status']]++;
    }
    $in = $n['ontime'] + $n['late'];
    $scopeName = $o['school'] !== '' ? $short($o['school']) : ($multi ? 'Every school' : '');

    ob_start();
?>
<section class="tile-section aud-insights" aria-label="Insights">
  <div class="section-title">
    <h2>Insights</h2>
    <span class="section-meta"><?= $e(trim($scopeName . ' · ' . $total . ' course file' . ($total === 1 ? '' : 's'), ' ·')) ?><?= $deadline !== '' ? ' &middot; deadline ' . $e(date('j M Y', strtotime($deadline))) : '' ?></span>
  </div>
  <?php if (!$total): ?>
  <p class="card chart-empty">No course files here yet.</p>
  <?php else: ?>
  <div class="chart-grid">
    <figure class="chart-card">
      <figcaption>Against the deadline <span class="chart-caption-note">Click to filter</span></figcaption>
      <div class="chart-body">
        <?php
          $r = 54; $c = 2 * M_PI * $r; $at = 0.0;
          $p = $pct($in, $total);
          $cls = $p >= 75 ? 'att-good' : ($p >= 50 ? 'att-warn' : 'att-low');
        ?>
        <svg class="donut aud-donut" viewBox="0 0 140 140" role="img" aria-label="<?= $p ?>% submitted">
          <circle cx="70" cy="70" r="<?= $r ?>" class="donut-track"/>
          <?php foreach (['ontime', 'late', 'pending'] as $k): if (!$n[$k]) continue;
            $len = $c * $n[$k] / $total; ?>
          <circle cx="70" cy="70" r="<?= $r ?>" class="aud-seg is-<?= $k ?> chart-click" role="link" tabindex="0"
            stroke-dasharray="<?= round($len, 2) ?> <?= round($c - $len, 2) ?>" stroke-dashoffset="<?= round(-$at, 2) ?>" transform="rotate(-90 70 70)"
            data-href="<?= $e($keep(['view' => $o['view'], 'when' => $k])) ?>"<?= $tip('<b>' . $label[$k] . '</b><span class="tip-big">' . $n[$k] . '</span>' . $pct($n[$k], $total) . '% of ' . $total . ' course files<em>Click to show only these</em>') ?>/>
          <?php $at += $len; endforeach; ?>
          <text x="70" y="70" class="donut-pct <?= $cls ?>"><?= $p ?>%</text>
          <text x="70" y="90" class="donut-sub">submitted</text>
        </svg>
        <ul class="chart-legend aud-chart-legend">
          <?php foreach (['ontime', 'late', 'pending'] as $k): if ($k === 'late' && $deadline === '') continue; ?>
          <li><span class="swatch aud-sw is-<?= $k ?>"></span><?= $e($label[$k]) ?><b><?= $n[$k] ?></b></li>
          <?php endforeach; ?>
        </ul>
        <p class="chart-foot"><?= $deadline === '' ? 'No deadline set yet' : $e(audit_due_label($deadline, $today)) ?></p>
      </div>
    </figure>

    <figure class="chart-card">
      <figcaption>Where course files stand <span class="chart-caption-note">Click to open</span></figcaption>
      <div class="chart-body aud-rank">
        <?php
          $stLabel = ['none' => 'Not started', 'draft' => 'Uploading, not submitted', 'submitted' => 'Waiting for review', 'approved' => 'Reviewed'];
          $stHref = ['none' => 'draft', 'draft' => 'draft', 'submitted' => 'submitted', 'approved' => 'approved'];
          foreach ($stLabel as $k => $l): ?>
        <div class="aud-rank-row chart-click" role="link" tabindex="0" data-href="<?= $e($keep(['view' => 'review', 'status' => $stHref[$k]])) ?>"<?= $tip('<b>' . $l . '</b><span class="tip-big">' . $st[$k] . '</span>' . $pct($st[$k], $total) . '% of ' . $total . ' course files<em>Click to see them</em>') ?>>
          <span class="aud-rank-name"><?= $e($l) ?></span>
          <span class="aud-hbar"><i class="is-st-<?= $k ?>" style="width:<?= $total ? round(100 * $st[$k] / $total, 1) : 0 ?>%"></i></span>
          <span class="aud-rank-val"><b><?= $st[$k] ?></b><small><?= $pct($st[$k], $total) ?>%</small></span>
        </div>
        <?php endforeach; ?>
      </div>
    </figure>

    <figure class="chart-card">
      <figcaption>Items most often missing <span class="chart-caption-note">In started files</span></figcaption>
      <div class="chart-body aud-rank">
        <?php
          $ins = audit_insights($rows);
          $gaps = [];
          foreach ($ins['items'] as $k => $have) if (AUDIT_ITEMS[$k][1]) $gaps[$k] = $ins['started'] - $have;
          arsort($gaps);
          $gaps = array_slice(array_filter($gaps), 0, 6, true);
        ?>
        <?php if (!$ins['started']): ?>
          <p class="chart-empty">Nobody has uploaded anything here yet.</p>
        <?php elseif (!$gaps): ?>
          <p class="chart-empty">Every started file has every required item.</p>
        <?php else: foreach ($gaps as $k => $miss): ?>
        <div class="aud-rank-row"<?= $tip('<b>' . $e($k . '. ' . AUDIT_ITEMS[$k][0]) . '</b><span class="tip-big">' . $miss . '</span>of ' . $ins['started'] . ' started files still miss it') ?> tabindex="0">
          <span class="aud-rank-name"><?= $e($k . '. ' . AUDIT_ITEMS[$k][0]) ?></span>
          <span class="aud-hbar"><i class="is-pending" style="width:<?= round(100 * $miss / $ins['started'], 1) ?>%"></i></span>
          <span class="aud-rank-val"><b><?= $miss ?></b><small>/<?= $ins['started'] ?></small></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </figure>

    <figure class="chart-card chart-wide">
      <figcaption>Submissions by day <span class="chart-caption-note"><?= $deadline !== '' ? 'Dashed line is the deadline' : 'First submission of each file' ?></span></figcaption>
      <div class="chart-body">
        <?php
          $days = $ins['days'];
          $from = date('Y-m-d', strtotime('-13 days', strtotime($today)));
          if ($days && array_key_first($days) < $from) $from = array_key_first($days);
          $to = max($today, $deadline);
          if ((strtotime($to) - strtotime($from)) / 86400 > 41) $from = date('Y-m-d', strtotime("$to -41 days"));
          $list = [];
          for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $list[] = $d;
          $peak = max(1, ...array_values($days ?: [0]));
          $peak = $peak <= 4 ? 4 : (int) (ceil($peak / 4) * 4);
          $W = 900; $H = 190; $L = 34; $R = 8; $T = 14; $B = 26;
          $slot = ($W - $L - $R) / count($list);
          $y = fn(float $v): float => $H - $B - ($H - $T - $B) * $v / $peak;
          $before = 0;
          foreach ($days as $d => $k) if ($d < $from) $before += $k;
          $run = $before;
          $todayAt = array_search($today, $list, true);
          if ($todayAt === false) $todayAt = -99;
        ?>
        <svg class="trend aud-days" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Course files submitted each day">
          <?php foreach ([0, $peak / 2, $peak] as $g): ?>
          <line class="chart-gridline" x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= round($y($g), 1) ?>" y2="<?= round($y($g), 1) ?>"/>
          <text class="chart-tick" x="<?= $L - 6 ?>" y="<?= round($y($g) + 4, 1) ?>" text-anchor="end"><?= (int) $g ?></text>
          <?php endforeach; ?>
          <?php foreach ($list as $i => $d):
            $k = $days[$d] ?? 0;
            $run += $k;
            $x = $L + $i * $slot;
            $bw = max(2, $slot * 0.62);
            $bx = $x + ($slot - $bw) / 2;
            $cls = $d > $today ? 'is-future' : ($deadline !== '' && $d > $deadline ? 'is-late' : 'is-ontime');
            $tipHtml = '<b>' . date('D j M Y', strtotime($d)) . ($d === $deadline ? ' &middot; deadline' : '') . ($d === $today ? ' &middot; today' : '') . '</b>'
                . ($d > $today ? 'Still to come' : '<span class="tip-big">' . $k . '</span>submitted<br>' . $run . ' of ' . $total . ' in by the end of the day');
          ?>
          <g class="chart-day">
            <line class="chart-guide" x1="<?= round($x + $slot / 2, 1) ?>" x2="<?= round($x + $slot / 2, 1) ?>" y1="<?= $T ?>" y2="<?= $H - $B ?>"/>
            <?php if ($k): ?><rect class="aud-day-bar <?= $cls ?>" x="<?= round($bx, 1) ?>" y="<?= round($y($k), 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($H - $B - $y($k), 1) ?>" rx="2"/><?php endif; ?>
            <rect class="chart-hitbox" x="<?= round($x, 1) ?>" y="<?= $T ?>" width="<?= round($slot, 1) ?>" height="<?= $H - $T - $B ?>" tabindex="0"<?= $tip($tipHtml) ?>/>
          </g>
          <?php if ($d === $deadline): ?>
          <line class="aud-deadline-rule" x1="<?= round($x + $slot, 1) ?>" x2="<?= round($x + $slot, 1) ?>" y1="<?= $T - 6 ?>" y2="<?= $H - $B ?>"/>
          <text class="chart-callout aud-deadline-text" x="<?= round($x + $slot - 4, 1) ?>" y="<?= $T + 2 ?>" text-anchor="end">Deadline</text>
          <?php endif; ?>
          <?php if ($d === $today || (abs($i - $todayAt) > 2 && ($i === 0 || $i === count($list) - 1 || ($i % 7 === 0 && count($list) - $i > 3)))): ?>
          <text class="chart-tick<?= $d === $today ? ' is-now' : '' ?>" x="<?= round($x + $slot / 2, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $d === $today ? 'Today' : date('j M', strtotime($d)) ?></text>
          <?php endif; ?>
          <?php endforeach; ?>
        </svg>
        <p class="chart-key"><span class="key-bar aud-sw is-ontime"></span>On time<?php if ($deadline !== ''): ?> <span class="key-bar aud-sw is-late"></span>After the deadline<?php endif; ?></p>
      </div>
    </figure>

    <?php
      // Compare: schools when none is picked, else its faculty.
      $groups = [];
      foreach ($rows as $r) {
          $g = $o['school'] === '' && $multi ? $r['course']['school'] : $r['course']['person'];
          $groups[$g] ??= ['label' => $o['school'] === '' && $multi ? $short($g) : $r['faculty'], 'ontime' => 0, 'late' => 0, 'pending' => 0, 'reviewed' => 0, 'all' => 0, 'school' => $r['course']['school']];
          $groups[$g][$r['timing']]++;
          $groups[$g]['all']++;
          if ($r['status'] === 'approved') $groups[$g]['reviewed']++;
      }
      uasort($groups, fn($a, $b) => ($b['ontime'] + $b['late']) / $b['all'] <=> ($a['ontime'] + $a['late']) / $a['all'] ?: strnatcasecmp($a['label'], $b['label']));
      $bySchool = $o['school'] === '' && $multi;
    ?>
    <figure class="chart-card chart-wide">
      <figcaption><?= $bySchool ? 'Compare schools' : 'Compare faculty' ?> <span class="chart-caption-note"><?= $bySchool ? 'Click one to open it' : 'Click a name to see their files' ?></span></figcaption>
      <div class="chart-body aud-rank aud-compare">
        <?php foreach ($groups as $id => $g):
          $href = $bySchool ? $keep(['view' => $o['view'], 'when' => $o['when'], 'school' => $id]) : 'audit.php?' . http_build_query(array_filter(['view' => 'review', 'school' => $multi ? $g['school'] : ''])) . '#p-' . $id;
          $sub = $g['ontime'] + $g['late']; ?>
        <div class="aud-rank-row chart-click" role="link" tabindex="0" data-href="<?= $e($href) ?>"<?= $tip('<b>' . $e($g['label']) . '</b><span class="tip-big">' . $pct($sub, $g['all']) . '%</span>submitted<br>'
            . $g['ontime'] . ' ' . strtolower($label['ontime']) . ($deadline !== '' ? ', ' . $g['late'] . ' late' : '') . ', ' . $g['pending'] . ' not submitted<br>' . $g['reviewed'] . ' of ' . $g['all'] . ' reviewed<em>Click to open</em>') ?>>
          <span class="aud-rank-name"><?= $e($g['label']) ?></span>
          <span class="aud-hbar"><i class="is-ontime" style="width:<?= round(100 * $g['ontime'] / $g['all'], 1) ?>%"></i><i class="is-late" style="width:<?= round(100 * $g['late'] / $g['all'], 1) ?>%"></i></span>
          <span class="aud-rank-val"><b><?= $pct($sub, $g['all']) ?>%</b><small><?= $sub ?>/<?= $g['all'] ?></small></span>
        </div>
        <?php endforeach; ?>
      </div>
    </figure>
  </div>
  <?php endif; ?>
</section>
<?php
    return (string) ob_get_clean();
}
