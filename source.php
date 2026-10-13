<?php
// One of the documents the room timetable was built from, opened where a
// class is written: a workbook drawn as its tab with the class's cells marked,
// a PDF at the class's page. Reached from the panel a class opens on
// rooms.php, so anyone reading the timetable can check it against what the
// school sent. Open to everyone and read-only, like the timetable.
//
// The workbook is not read here: the build saves each one's cells beside its
// copy (timetable/docs/<id>.php, behind store.php's guard line), because
// unzipping a workbook on every visit is the kind of slow request this host
// punishes. The PDF is drawn by pdf.js, so it opens at its page on a phone too,
// where the browser would otherwise only offer to download it.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/timetable.php';

auth_boot();
traffic_hit('source');

$t = timetable_read();
$id = (string) ($_GET['doc'] ?? '');
// Off on the live site (TIMETABLE_SOURCES, config.php): every document is
// then "not found", the same answer as a wrong id.
$doc = TIMETABLE_SOURCES ? ($t['docs'][$id] ?? null) : null;
$e = fn(string $v): string => htmlspecialchars($v);

if (!$doc) {
    http_response_code(404);
    page_head('Source document', 'page-wide', 'timetable');
    echo '<section class="card tt-empty"><h2>Document not found</h2><p class="field-help">That document is not on file. Open the timetable again and pick the class.</p></section>';
    page_foot();
    exit;
}

$file = 'timetable/' . $doc['file'];
$how = [
    'direct' => 'The timetable was read straight from this sheet.',
    'script' => 'The timetable was read from this sheet by a converter script.',
    'hand' => 'The timetable was typed in by hand from this document.',
][$doc['how']];

page_head($doc['name'], 'page-wide', 'timetable');
?>
<section class="card tt-card">
  <div class="src-head">
    <h2 class="src-name"><?= $e($doc['name']) ?></h2>
    <span class="src-how"><?= $e($how) ?></span>
    <a class="btn-quiet" href="<?= $e($file) ?>" download>Download original</a>
  </div>
<?php if ($doc['kind'] === 'pdf'):
    $page = max(1, (int) ($_GET['page'] ?? 1));
?>
  <div class="src-pdf" id="src-pdf" data-file="<?= $e($file) ?>" data-page="<?= $page ?>">
    <div class="src-pager">
      <button class="btn-quiet" type="button" data-step="-1">Previous</button>
      <span>Page <span id="src-at"><?= $page ?></span> of <span id="src-of">...</span></span>
      <button class="btn-quiet" type="button" data-step="1">Next</button>
    </div>
    <canvas id="src-canvas" aria-label="Page of <?= $e($doc['name']) ?>"></canvas>
    <p class="tt-none" id="src-fail" hidden>This PDF could not be drawn here. <a href="<?= $e($file) ?>">Open it in the browser</a> and go to page <?= $page ?>.</p>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script>
  (function () {
    var box = document.getElementById('src-pdf');
    var canvas = document.getElementById('src-canvas');
    var at = +box.dataset.page, pdf = null, busy = false;
    function fail() { canvas.hidden = true; document.getElementById('src-fail').hidden = false; }
    if (!window.pdfjsLib) return fail();
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    // Drawn at the box's width times the screen's density, so it stays sharp.
    function draw() {
      if (busy) return;
      busy = true;
      pdf.getPage(at).then(function (p) {
        // The box can still be unlaid-out on the first draw: fall back to the window.
        var width = box.clientWidth || document.documentElement.clientWidth - 64;
        var fit = Math.min(Math.max(width, 280), 1100) / p.getViewport({ scale: 1 }).width;
        var view = p.getViewport({ scale: fit * (window.devicePixelRatio || 1) });
        canvas.width = view.width;
        canvas.height = view.height;
        canvas.style.width = (view.width / (window.devicePixelRatio || 1)) + 'px';
        document.getElementById('src-at').textContent = at;
        return p.render({ canvasContext: canvas.getContext('2d'), viewport: view }).promise;
      }).then(function () { busy = false; }, function () { busy = false; fail(); });
    }
    pdfjsLib.getDocument(box.dataset.file).promise.then(function (d) {
      pdf = d;
      at = Math.min(at, d.numPages);
      document.getElementById('src-of').textContent = d.numPages;
      draw();
    }, fail);
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-step]');
      if (!b || !pdf) return;
      var next = at + +b.dataset.step;
      if (next < 1 || next > pdf.numPages) return;
      at = next;
      draw();
    });
    var resized;
    window.addEventListener('resize', function () {
      clearTimeout(resized);
      resized = setTimeout(function () { if (pdf) draw(); }, 200);
    });
  })();
  </script>
<?php else:
    $book = store_decode((string) @file_get_contents(__DIR__ . "/timetable/docs/$id.php")) ?? ['tabs' => []];
    $tabs = $book['tabs'];
    $names = array_column($tabs, 'name');
    $want = (string) ($_GET['tab'] ?? '');
    $at = array_search($want, $names, true);
    if ($at === false) $at = 0;
    $tab = $tabs[$at] ?? ['name' => '', 'rows' => [], 'merges' => []];
    // The marked cells, only on the tab the class was found on.
    $hit = null;
    if ($tab['name'] === $want && preg_match('/^(\d+),(\d+),(\d+),(\d+)$/', (string) ($_GET['cell'] ?? ''), $m)) {
        $hit = array_map('intval', array_slice($m, 1));
    }

    // The tab's extent, its merged cells as spans, and the cells they cover.
    // Capped, so a stray value far down a sheet cannot draw a million cells.
    $rows = $tab['rows'];
    $lastRow = $rows ? max(array_map('intval', array_keys($rows))) : 0;
    $lastCol = 0;
    foreach ($rows as $cols) if ($cols) $lastCol = max($lastCol, max(array_map('intval', array_keys($cols))));
    $span = [];
    $covered = [];
    foreach ($tab['merges'] as [$r1, $c1, $r2, $c2]) {
        $span["$r1,$c1"] = [$r2 - $r1 + 1, $c2 - $c1 + 1];
        for ($r = $r1; $r <= $r2; $r++) for ($c = $c1; $c <= $c2; $c++) if ($r !== $r1 || $c !== $c1) $covered["$r,$c"] = true;
        $lastRow = max($lastRow, $r2);
        $lastCol = max($lastCol, $c2);
    }
    $lastRow = min($lastRow, 400);
    $lastCol = min($lastCol, 60);
    $inHit = fn(int $r, int $c, int $rs, int $cs): bool => $hit !== null
        && $r <= $hit[2] && $r + $rs - 1 >= $hit[0] && $c <= $hit[3] && $c + $cs - 1 >= $hit[1];
    $first = true;
?>
  <?php if (count($tabs) > 1): ?>
  <ul class="src-tabs" aria-label="Tabs in this workbook">
    <?php foreach ($tabs as $i => $x): ?>
      <li><a href="source.php?<?= $e(http_build_query(['doc' => $id, 'tab' => $x['name']] + ($i === $at && $hit ? ['cell' => implode(',', $hit)] : []))) ?>"<?= $i === $at ? ' aria-current="page"' : '' ?>><?= $e($x['name']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($hit): ?><p class="tt-hint">The class is marked on tab "<?= $e($tab['name']) ?>", cell <?= $e(timetable_cell_name($hit)) ?>.</p><?php endif; ?>
  <div class="src-wrap">
    <table class="src-sheet">
      <thead><tr><th scope="col"></th><?php for ($c = 0; $c <= $lastCol; $c++): ?><th scope="col"><?= $e(substr(timetable_cell_name([0, $c, 0, $c]), 0, -1)) ?></th><?php endfor; ?></tr></thead>
      <tbody>
      <?php for ($r = 0; $r <= $lastRow; $r++): ?>
        <tr><th scope="row"><?= $r + 1 ?></th><?php for ($c = 0; $c <= $lastCol; $c++):
            if (isset($covered["$r,$c"])) continue;
            [$rs, $cs] = $span["$r,$c"] ?? [1, 1];
            $v = preg_replace('/^(-?\d+)\.0$/', '$1', (string) ($rows[(string) $r][(string) $c] ?? ''));
            $mark = $inHit($r, $c, $rs, $cs);
            $id_ = $mark && $first ? ' id="src-hit"' : '';
            if ($mark) $first = false;
        ?><td<?= $rs > 1 ? ' rowspan="' . $rs . '"' : '' ?><?= $cs > 1 ? ' colspan="' . $cs . '"' : '' ?><?= $mark ? ' class="src-hit"' : '' ?><?= $id_ ?>><?= $e($v) ?></td><?php endfor; ?></tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <script>
    // Bring the marked class into the middle of the sheet's own scroller,
    // leaving the page itself where it is, under the document's name.
    // A page opened in a background tab has no size yet, so it is done again
    // the first time the window gets one.
    (function () {
      var hit = document.getElementById('src-hit');
      if (!hit) return;
      var wrap = hit.closest('.src-wrap');
      function centre() {
        var a = hit.getBoundingClientRect(), b = wrap.getBoundingClientRect();
        if (!b.height) return false;
        wrap.scrollTop += a.top - b.top - Math.max(0, (b.height - a.height) / 2);
        wrap.scrollLeft += a.left - b.left - Math.max(0, (b.width - a.width) / 2);
        return true;
      }
      if (!centre()) window.addEventListener('resize', function again() {
        if (centre()) window.removeEventListener('resize', again);
      });
    })();
  </script>
<?php endif; ?>
</section>
<?php page_foot(); ?>
