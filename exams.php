<?php
// The Exams tab: the exam schedule, who is on invigilation duty at each paper,
// and whether they reported by the reporting time (includes/exams.php).
// Anyone may look; only an admin changes anything for now (exam_can_edit(),
// with exam_can_mark() the hook for a future exam cell coordinator). Views:
//   By exam         the schedule by date, with who is allocated (the default)
//   Faculty duties  every faculty member and the duties they hold
//   Report          attendance over a date and time window, printable
// ?e=<id> is one exam: its duties, and the attendance form.

require_once __DIR__ . '/includes/exams.php';
require_once __DIR__ . '/includes/exams-doc.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('exams');
$me = auth_user() ?? [];
$canEdit = $me && exam_can_edit($me);
$canMark = $me && exam_can_mark($me);

$t = timetable_read();
$faculty = $t ? timetable_faculty($t) : [];
$data = exam_data();
$emails = exam_emails(auth_data()['users'], $faculty);
$today = date('Y-m-d');

$e = fn($v): string => htmlspecialchars((string) $v);
$validDate = fn(string $d): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
$validTime = fn(string $v): string => preg_match('/^\d{2}:\d{2}$/', $v) && exam_min($v) >= 0 ? $v : '';
$schoolName = fn(string $id): string => SCHOOLS[$id]['name'] ?? $id;
$short = fn(string $id): string => preg_replace('/^School of /', '', $schoolName($id));
$day = fn(string $d): string => $d === '' ? '' : date('D j M Y', strtotime($d));

$eid = (string) ($_GET['e'] ?? $_POST['e'] ?? '');
$exam = $data['exams'][$eid] ?? null;
if ($eid !== '' && $exam === null) http_response_code(404);

$school = (string) ($_GET['school'] ?? '');
if (!isset(SCHOOLS[$school])) $school = '';
$from = $validDate((string) ($_GET['from'] ?? ''));
$to = $validDate((string) ($_GET['to'] ?? ''));
$tFrom = $validTime((string) ($_GET['tfrom'] ?? ''));
$tTo = $validTime((string) ($_GET['tto'] ?? ''));
$alloc = in_array($_GET['alloc'] ?? '', ['full', 'short'], true) ? (string) $_GET['alloc'] : '';
// The status every view can be narrowed to: Not marked, On time, Late, Absent.
$st = isset(EXAM_STATUS_LABEL[$_GET['st'] ?? '']) ? (string) $_GET['st'] : '';

$tabs = ['exams' => 'By exam', 'faculty' => 'Faculty duties', 'report' => 'Report', 'roster' => 'Duty roster'];
$view = (string) ($_GET['view'] ?? '');
$view = isset($tabs[$view]) || $view === 'letter' ? $view : 'exams';

// The filters every view carries, as a query string.
$keep = fn(array $more = []): string => http_build_query(array_filter($more + ['school' => $school, 'from' => $from, 'to' => $to, 'alloc' => $alloc,
    'st' => $st, 'tfrom' => $tFrom, 'tto' => $tTo], fn($v) => $v !== ''));
$hasStatus = fn(array $rows): array => $st === '' ? $rows : array_values(array_filter($rows, fn($r) => $r['duty']['status'] === $st));

// --- Changes, all back to a GET ---------------------------------------------
$error = '';
$info = [];
$here = fn(array $q): string => 'exams.php?' . http_build_query($q);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $back = $exam ? ['e' => $eid] : [];
    $needsMark = $action === 'mark';
    $back += array_filter(['st' => (string) ($_POST['st'] ?? '')], fn($v) => isset(EXAM_STATUS_LABEL[$v]));
    if (!$me) {
        $error = 'Please sign in to change anything here.';
    } elseif (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP drops the whole post, csrf included, when it is over post_max_size.
        $error = 'That file is too big to upload. Please keep it under 2 MB.';
    } elseif (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif ($needsMark ? !$canMark : !$canEdit) {
        $error = 'Only an admin can change exams.';
    } else {
        $by = (string) $me['email'];
        $done = $action;
        switch ($action) {
            case 'add':
                $error = exam_add($_POST + ['need' => (int) ($_POST['need'] ?? 0)], $by);
                break;
            case 'import':
                $f = $_FILES['file'] ?? [];
                if (empty($f['tmp_name']) || ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    $error = 'Please choose a .csv or .xlsx file to upload.';
                } elseif (!preg_match('/\.(csv|xlsx)$/i', (string) $f['name'])) {
                    $error = 'The schedule must be a .csv or .xlsx file.';
                } else {
                    try {
                        [$added, $updated, $problems] = exam_import(exam_read_rows($f['tmp_name'], (string) $f['name']), $faculty, $by);
                        $info = ['added' => $added, 'updated' => $updated, 'problems' => $problems];
                        $data = exam_data();
                    } catch (Throwable $x) {
                        $error = 'That file could not be read. Download the format and try again.';
                    }
                }
                break;
            case 'auto':
                $ids = array_keys(exam_list($data, $school, $from, $to));
                if ($exam) $ids = [$eid];
                $n = exam_auto_allocate($t ?: [], $faculty, $ids, $by);
                $back += ['n' => $n];
                break;
            case 'sample':
                if ($data['exams']) { $error = 'Sample data is only loaded onto an empty schedule.'; break; }
                $back += ['n' => exam_sample($t ?: [], $faculty, $by)];
                break;
            case 'clear_sample':
                exam_clear_sample();
                break;
            case 'update':
                if (!$exam) { $error = 'That exam is gone.'; break; }
                $error = exam_update($eid, $_POST + ['need' => (int) ($_POST['need'] ?? 0)]);
                break;
            case 'delete':
                if (!$exam) { $error = 'That exam is gone.'; break; }
                exam_delete($eid);
                $back = [];
                break;
            case 'assign':
                if (!$exam) { $error = 'That exam is gone.'; break; }
                $error = exam_assign($eid, (string) ($_POST['person'] ?? ''), $faculty, (string) ($_POST['role'] ?? ''), (string) ($_POST['email'] ?? ''), $by);
                break;
            case 'mark':
                if (!$exam) { $error = 'That exam is gone.'; break; }
                if (isset($_POST['remove'])) {
                    $rid = (string) $_POST['remove'];
                    if (($data['duties'][$rid]['exam'] ?? '') === $eid) exam_unassign($rid);
                    $done = 'remove';
                } elseif (isset($_POST['quick'])) {
                    // One-click buttons: "<duty id>:<status>".
                    [$qid, $qs] = explode(':', (string) $_POST['quick'], 2) + ['', ''];
                    $back += ['n' => exam_set_status($eid, $qid, $qs, $emails, $by)];
                    $done = 'quick';
                } else {
                    $back += ['n' => exam_mark($eid, (array) ($_POST['status'] ?? []), (array) ($_POST['arrived'] ?? []), (array) ($_POST['remark'] ?? []), $emails, $by)];
                }
                break;
            default:
                $error = 'Unknown action.';
        }
        if ($error === '' && $action !== 'import') {
            header('Location: ' . $here($back + ['done' => $done]));
            exit;
        }
    }
    $data = exam_data();
    $exam = $data['exams'][$eid] ?? null;
}

// --- The lists ---------------------------------------------------------------
$list = exam_list($data, $school, $from, $to);
if ($alloc !== '') $list = array_filter($list, fn($x) => ($alloc === 'short') === (exam_short($data, $x['id']) > 0));
if ($st !== '') $list = array_filter($list, fn($x) => in_array($st, array_column(exam_duties_of($data, $x['id']), 'status'), true));
$scopeRows = exam_report_rows($data, $school, $from, $to, '', '');
// A faculty member's key in every per-person list: their timetable id, or
// their name as written when it matched no one.
$personKey = fn(array $duty): string => $duty['person'] !== '' ? $duty['person'] : 'name:' . strtolower($duty['name']);
$counts = exam_counts($scopeRows);
$hasSample = (bool) array_filter($data['exams'], fn($x) => !empty($x['sample']));

// --- Exports -------------------------------------------------------------------
$csv = function (string $name, array $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    // Excel reads a CSV as the local code page unless it starts with a BOM.
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
    fclose($out);
    exit;
};
// An Excel file, header bold and each status cell coloured the way the page
// colours it, so it can be forwarded as it is. Built in cache/ (the one
// folder this host lets us write and is not data/) and deleted once sent.
$xlsx = function (string $name, array $rows) use ($csv) {
    $cols = count($rows[0] ?? []) === 8 ? [30, 24, 8, 9, 8, 8, 11, 90] : [16, 20, 10, 24, 26, 34, 14, 26, 16, 12, 10, 30];
    if (!class_exists('ZipArchive')) $csv(preg_replace('/\.xlsx$/', '.csv', $name), array_map(fn($r) => array_map(fn($c) => is_array($c) ? $c[1] : $c, $r), $rows));
    require_once __DIR__ . '/includes/xlsx-write.php';
    $tmp = __DIR__ . '/cache/export-' . bin2hex(random_bytes(6)) . '.xlsx';
    write_xlsx($tmp, ['Exam duties' => ['cols' => $cols, 'rows' => $rows]]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
};
// The roster: every exam in the list, one row per duty (an exam nobody is on
// yet still gets its row).
$rosterRows = [];
foreach ($list as $id => $x) {
    $ds = exam_duties_of($data, $id);
    if ($st !== '') $ds = array_filter($ds, fn($d) => $d['status'] === $st);
    if (!$ds) $rosterRows[] = ['exam' => $x, 'duty' => []];
    foreach ($ds as $did => $d) $rosterRows[] = ['id' => $did, 'exam' => $x, 'duty' => $d];
}
// Faculty duties: only people who actually hold a duty in this window. The
// school filter is the exam's school; a status keeps those with one such duty.
$facPeople = [];
foreach (exam_report_rows($data, $school, $from, $to, '', '') as $r) {
    $k = $personKey($r['duty']);
    $facPeople[$k] ??= ['name' => $r['duty']['name'], 'schools' => [], 'rows' => []];
    $facPeople[$k]['schools'][] = $r['exam']['school'];
    $facPeople[$k]['rows'][] = $r;
}
if ($st !== '') $facPeople = array_filter($facPeople, fn($p) => in_array($st, array_map(fn($r) => $r['duty']['status'], $p['rows']), true));
uasort($facPeople, fn($a, $b) => [-count($a['rows']), timetable_person($a['name'])] <=> [-count($b['rows']), timetable_person($b['name'])]);
$repRows = $hasStatus(exam_report_rows($data, $school, $from, $to, $tFrom, $tTo));
$letterKey = (string) ($_GET['p'] ?? '');
$letterRows = $view === 'letter' ? array_values(array_filter($hasStatus(exam_report_rows($data, $school, $from, $to, '', '')), fn($r) => $personKey($r['duty']) === $letterKey)) : [];

// The one document the Export menu hands out for this view (includes/exams-doc.php).
$rangeLabel = $from !== '' || $to !== '' ? ($from !== '' ? $day($from) : 'start') . ' to ' . ($to !== '' ? $day($to) : 'end') : '';
$scope = implode(', ', array_filter([$school !== '' ? $schoolName($school) : 'All schools', $rangeLabel,
    $view === 'report' && ($tFrom !== '' || $tTo !== '') ? 'starting ' . exam_clock($tFrom ?: '00:00') . ' to ' . exam_clock($tTo ?: '23:59') : '',
    $st !== '' ? EXAM_STATUS_LABEL[$st] : '']));
$fileBit = ($school ?: 'all-schools') . ($st !== '' ? "-$st" : '') . "-$today";
if ($exam !== null) {
    $doc = exam_doc_sheet($exam, exam_duties_of($data, $eid));
} elseif ($view === 'faculty') {
    $doc = exam_doc_faculty($facPeople, $scope, $fileBit);
} elseif ($view === 'report') {
    $doc = exam_doc_report($repRows, $scope, $fileBit);
} elseif ($view === 'letter') {
    $d0 = $letterRows[0]['duty'] ?? ['name' => '', 'email' => '', 'person' => ''];
    $doc = exam_doc_letter($d0['name'], $letterRows, $d0['email'] !== '' ? $d0['email'] : (string) ($emails[$d0['person']] ?? ''), $scope);
} else {
    $doc = exam_doc_roster($rosterRows, $scope, $fileBit);
}
$export = (string) ($_GET['export'] ?? '');
if ($export === 'xlsx') $xlsx($doc['file'], $doc['sheet']);
// The upload's own format, for an admin to fill in or re-upload.
if ($export === 'template') $csv('exam-schedule-' . ($school ?: 'all-schools') . "-$today.csv", exam_export_rows($data, $list));
$printDoc = !empty($_GET['doc']);

// --- Page ----------------------------------------------------------------------
$pill = fn(string $s): string => '<span class="status exm-st-' . $s . '">' . $e(EXAM_STATUS_LABEL[$s] ?? $s) . '</span>';
$span = fn(array $x): string => exam_clock($x['start']) . ' - ' . exam_clock($x['end']);
$titleBits = $exam ? ['Exam', $exam['subject'], $exam['class']] : ['Exam duties', $view === 'letter' ? ($letterRows[0]['duty']['name'] ?? 'Duty letter') : $tabs[$view], $st !== '' ? EXAM_STATUS_LABEL[$st] : '', $school !== '' ? $short($school) : '', $rangeLabel,
    $view === 'report' && ($tFrom !== '' || $tTo !== '') ? trim("$tFrom to $tTo") : '', date('j M Y')];
// The title is the saved PDF's file name, so the document's own when printing one.
page_head($printDoc ? $doc['title'] . ' - ' . $doc['scope'] . ' - ' . date('j M Y') : implode(' - ', array_filter($titleBits)), 'page-wide page-audit page-exams' . ($printDoc ? ' exm-print-doc' : ''), 'exams');
page_notice('warn', $error);
if ($info) {
    page_notice('ok', "Schedule uploaded: {$info['added']} added, {$info['updated']} updated.");
    foreach (array_slice($info['problems'], 0, 20) as $p) page_notice('warn', $p);
    if (count($info['problems']) > 20) page_notice('warn', 'And ' . (count($info['problems']) - 20) . ' more.');
}
$done = (string) ($_GET['done'] ?? '');
$n = (int) ($_GET['n'] ?? 0);
if ($error === '' && !$info && $done !== '') page_notice('ok', match ($done) {
    'add' => 'Exam added.', 'auto' => $n ? "Allocated $n invigilator" . ($n === 1 ? '' : 's') . '.' : 'Nobody free was found to allocate.',
    'sample' => "Loaded $n sample exams. They can be removed again.", 'clear_sample' => 'Sample data removed.',
    'quick' => 'Status updated.' . ($n ? ' A late email is queued; it is sent within a few minutes.' : ''), 'update' => 'Exam details saved.',
    'delete' => 'Exam deleted.', 'assign' => 'Invigilator added.', 'remove' => 'Invigilator removed.',
    'mark' => 'Attendance saved.' . ($n ? " $n late email" . ($n === 1 ? '' : 's') . ' queued; they are sent within a few minutes.' : ''),
    default => 'Saved.',
});
// The one Export menu: the same five ways out on every view, each for exactly
// what the view shows with its filters.
$query = $_GET;
unset($query['done'], $query['n'], $query['export'], $query['doc']);
$selfUrl = fn(array $more): string => 'exams.php?' . http_build_query($query + $more);
$exportMenu = '<details class="exm-export no-print"><summary class="range-export"><svg aria-hidden="true"><use href="#icon-download"/></svg>Export</summary><div class="exm-export-menu">'
    . '<a href="' . $e($selfUrl(['doc' => 1])) . '" target="_blank" rel="noopener"><strong>PDF</strong><small>A clean printable page, opens in a new tab</small></a>'
    . '<a href="' . $e($selfUrl(['export' => 'xlsx'])) . '"><strong>Excel</strong><small>The same, as a spreadsheet</small></a>'
    . '<button type="button" data-copy="exm-share-text"><strong>Copy as text</strong><small>To paste into an email or chat</small></button>'
    . '<a href="' . $e('mailto:' . rawurlencode($doc['email']) . '?subject=' . rawurlencode($doc['subject']) . '&body=' . rawurlencode($doc['text'])) . '"><strong>Email</strong><small>' . ($doc['email'] !== '' ? 'To ' . $e($doc['email']) : 'Opens your mail app') . '</small></a>'
    . '<a href="' . $e('https://wa.me/?text=' . rawurlencode($doc['text'])) . '" target="_blank" rel="noopener"><strong>WhatsApp</strong><small>Send as a message</small></a>'
    . '</div><textarea id="exm-share-text" class="sr-only" readonly aria-hidden="true" tabindex="-1">' . $e($doc['text']) . '</textarea></details>';

if ($printDoc): ?>
<div class="exm-print-bar no-print">
  <a class="btn-quiet" href="<?= $e($selfUrl([])) ?>">Back to the page</a>
  <button class="btn-primary" type="button" onclick="window.print()">Print / save as PDF</button>
  <?= $exportMenu ?>
</div>
<?= exam_doc_html($doc) ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<script src="js/exams.js?v=<?= filemtime(__DIR__ . '/js/exams.js') ?>"></script>
<?php page_foot(); exit; endif;
?>
<svg style="display:none" aria-hidden="true"><defs>
  <symbol id="icon-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></symbol>
  <symbol id="icon-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></symbol>
</defs></svg>
<div class="aud-head">
  <div>
    <h2>Exam duties</h2>
    <p class="aud-sub">Invigilation allocation and attendance &middot; as of <?= $e(date('D j M Y')) ?></p>
  </div>
  <div class="aud-head-end">
    <nav class="tt-seg page-tabs no-print" aria-label="Section">
      <?php foreach ($tabs as $k => $l): ?>
      <a href="exams.php?<?= $e($keep(['view' => $k === 'exams' ? '' : $k])) ?>"<?= $k === $view && $exam === null ? ' aria-current="page"' : '' ?>><?= $l ?></a>
      <?php endforeach; ?>
    </nav>
    <?= $exportMenu ?>
  </div>
</div>

<?php
$tileCounts = $exam ? exam_counts(array_map(fn($did) => ['duty' => $data['duties'][$did], 'exam' => $exam], array_keys(exam_duties_of($data, $eid)))) : $counts;
?>
<div class="aud-due exm-due<?= in_array($view, ['roster', 'letter'], true) && !$exam ? ' no-print' : '' ?>">
  <span class="aud-due-cell"><small>Exams</small><strong><?= $exam ? 1 : count(exam_list($data, $school, $from, $to)) ?></strong><em><?= $exam ? $e($exam['subject']) : 'on the schedule' ?></em></span>
  <span class="aud-due-cell"><small>Duties allocated</small><strong><?= $tileCounts['duties'] ?></strong><em><?= $tileCounts['allocated'] ?> not marked</em></span>
  <span class="aud-due-cell is-ontime"><small>On time</small><strong><?= $tileCounts['ontime'] ?></strong><em><?= $tileCounts['duties'] ? round(100 * $tileCounts['ontime'] / $tileCounts['duties']) . '%' : '-' ?></em></span>
  <span class="aud-due-cell is-late"><small>Late</small><strong><?= $tileCounts['late'] ?></strong><em><?= $tileCounts['duties'] ? round(100 * $tileCounts['late'] / $tileCounts['duties']) . '%' : '-' ?></em></span>
  <span class="aud-due-cell is-pending"><small>Absent</small><strong><?= $tileCounts['absent'] ?></strong><em><?= $tileCounts['duties'] ? round(100 * $tileCounts['absent'] / $tileCounts['duties']) . '%' : '-' ?></em></span>
</div>

<?php
// The filter form every list view carries. $with picks the extra controls.
$filterForm = function (string $viewKey, array $with) use ($e, $school, $from, $to, $tFrom, $tTo, $alloc, $st, $schoolName): void { ?>
  <form class="aud-filters exm-filters" method="get"><input type="hidden" name="view" value="<?= $e($viewKey) ?>">
    <label><span class="sr-only">School</span><select name="school" onchange="this.form.submit()">
      <option value="">Every school</option>
      <?php foreach (SCHOOLS as $id => $sc): ?><option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e($sc['name']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="exm-date"><span class="sr-only">From date</span><input type="date" name="from" value="<?= $e($from) ?>" aria-label="From date" onchange="this.form.submit()"></label>
    <label class="exm-date"><span class="sr-only">To date</span><input type="date" name="to" value="<?= $e($to) ?>" aria-label="To date" onchange="this.form.submit()"></label>
    <?php if (in_array('time', $with, true)): ?>
    <label class="exm-date"><span class="sr-only">From time</span><input type="time" name="tfrom" value="<?= $e($tFrom) ?>" aria-label="From time" onchange="this.form.submit()"></label>
    <label class="exm-date"><span class="sr-only">To time</span><input type="time" name="tto" value="<?= $e($tTo) ?>" aria-label="To time" onchange="this.form.submit()"></label>
    <?php endif; ?>
    <?php if (in_array('alloc', $with, true)): ?>
    <label><span class="sr-only">Allocation</span><select name="alloc" onchange="this.form.submit()">
      <option value="">All exams</option>
      <option value="full"<?= $alloc === 'full' ? ' selected' : '' ?>>Fully allocated</option>
      <option value="short"<?= $alloc === 'short' ? ' selected' : '' ?>>Not allocated (short of invigilators)</option>
    </select></label>
    <?php endif; ?>
    <label><span class="sr-only">Status</span><select name="st" onchange="this.form.submit()" class="exm-st-pick<?= $st !== '' ? ' is-' . $e($st) : '' ?>">
      <option value="">Every status</option>
      <?php foreach (EXAM_STATUS_LABEL as $k => $l): ?><option value="<?= $k ?>"<?= $k === $st ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
    </select></label>
    <?php if (!empty($with['p'])): ?><input type="hidden" name="p" value="<?= $e($with['p']) ?>"><?php endif; ?>
    <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
  </form>
<?php };
?>

<?php if ($eid !== '' && $exam === null): ?>
  <p class="evr-back no-print"><a href="exams.php"><svg aria-hidden="true"><use href="#icon-back"/></svg>All exams</a></p>
  <p class="card">That exam is not on the schedule any more.</p>

<?php elseif ($exam !== null):
  // ---------------------------------------------------------------- one exam
  $allDuties = exam_duties_of($data, $eid);
  $duties = $st === '' ? $allDuties : array_filter($allDuties, fn($d) => $d['status'] === $st);
  $need = (int) $exam['need'];
  $gap = exam_short($data, $eid);
  $cands = $canEdit ? exam_candidates($t ?: [], $faculty, $data, $exam) : [];
?>
  <p class="evr-back no-print"><a href="exams.php?<?= $e($keep()) ?>"><svg aria-hidden="true"><use href="#icon-back"/></svg>All exams</a></p>
  <section class="card aud-course">
    <div class="aud-course-head">
      <div>
        <h2><?= $e($exam['subject']) ?> <span class="status <?= $gap ? 'exm-short' : 'exm-st-ontime' ?>"><?= count($allDuties) ?>/<?= $need ?> invigilators</span></h2>
        <p class="aud-meta"><?= $e($exam['class']) ?> &middot; <?= $e($schoolName($exam['school'])) ?></p>
        <p class="aud-meta"><?= $e($day($exam['date'])) ?> &middot; <?= $e($span($exam)) ?> &middot; report by <strong><?= $e(exam_clock($exam['report'])) ?></strong><?= $exam['room'] !== '' ? ' &middot; Room ' . $e($exam['room']) : '' ?></p>
        <?php if (!empty($exam['sample'])): ?><p class="aud-meta">Sample data</p><?php endif; ?>
      </div>
      <?php if ($canEdit): ?>
      <div class="exm-actions no-print">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="e" value="<?= $e($eid) ?>">
          <button class="btn-quiet" name="action" value="auto" type="submit"<?= $gap ? '' : ' disabled' ?>>Auto-fill</button></form>
        <form method="post" onsubmit="return confirm('Delete this exam and its duties?')"><?= csrf_field() ?><input type="hidden" name="e" value="<?= $e($eid) ?>">
          <button class="btn-quiet aud-remove" name="action" value="delete" type="submit">Delete exam</button></form>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <details class="exm-edit no-print">
      <summary>Edit exam details</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="e" value="<?= $e($eid) ?>"><input type="hidden" name="action" value="update">
        <div class="exm-grid">
          <label>Date<input type="date" name="date" value="<?= $e($exam['date']) ?>" required></label>
          <label>Start<input type="time" name="start" value="<?= $e($exam['start']) ?>" required></label>
          <label>End<input type="time" name="end" value="<?= $e($exam['end']) ?>" required></label>
          <label>Reporting<input type="time" name="report" value="<?= $e($exam['report']) ?>"></label>
          <label>School<select name="school" required><?php foreach (SCHOOLS as $id => $sc): ?><option value="<?= $e($id) ?>"<?= $id === $exam['school'] ? ' selected' : '' ?>><?= $e($sc['name']) ?></option><?php endforeach; ?></select></label>
          <label>Class<input type="text" name="class" value="<?= $e($exam['class']) ?>" maxlength="80" required></label>
          <label>Subject<input type="text" name="subject" value="<?= $e($exam['subject']) ?>" maxlength="120" required></label>
          <label>Room<input type="text" name="room" value="<?= $e($exam['room']) ?>" maxlength="40"></label>
          <label>Invigilators needed<input type="number" name="need" min="1" max="40" value="<?= (int) $exam['need'] ?>"></label>
        </div>
        <button class="btn-quiet" type="submit">Save details</button>
      </form>
    </details>
    <?php endif; ?>
  </section>

  <div class="aud-tools no-print">
    <form class="aud-filters" method="get"><input type="hidden" name="e" value="<?= $e($eid) ?>">
      <label><span class="sr-only">Status</span><select name="st" onchange="this.form.submit()">
        <option value="">Every status</option>
        <?php foreach (EXAM_STATUS_LABEL as $k => $l): ?><option value="<?= $k ?>"<?= $k === $st ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
      </select></label>
    </form>
  </div>

  <?php if ($canMark && $duties): ?><form method="post" class="exm-mark" id="exm-mark"><?= csrf_field() ?><input type="hidden" name="e" value="<?= $e($eid) ?>"><input type="hidden" name="st" value="<?= $e($st) ?>"><input type="hidden" name="action" value="mark"><button class="sr-only" type="submit" tabindex="-1" aria-hidden="true">Save attendance</button><?php endif; ?>
  <section class="card">
    <h2>Invigilators <span class="count-pill"><?= count($duties) ?><?= $st !== '' ? ' ' . $e(strtolower(EXAM_STATUS_LABEL[$st])) : '' ?></span></h2>
    <?php if (!$duties): ?><p class="field-help"><?= $allDuties ? 'Nobody here has this status.' : 'Nobody is allocated yet.' . ($canEdit ? ' Add someone below or use Auto-fill.' : '') ?></p><?php else: ?>
    <div class="stat-table-wrap"><table class="stat-table aud-table exm-table">
      <thead><tr><th>Name</th><?php if ($canMark): ?><th class="aud-left no-print">Update</th><?php endif; ?><th class="aud-left">Role</th><th class="aud-left">Status</th><th class="aud-left">Arrived</th><th class="aud-left">Remark</th><th class="aud-left">Email</th><?php if ($canMark): ?><th class="no-print"></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($duties as $did => $d):
          $mail = $data['mail'][$did] ?? null;
          $addr = $d['email'] !== '' ? $d['email'] : (string) ($emails[$d['person']] ?? ''); ?>
        <tr class="exm-row-<?= $e($d['status']) ?>">
          <th><?= $e($d['name']) ?></th>
          <?php if ($canMark): ?>
          <td class="aud-left no-print"><span class="exm-quick">
            <?php foreach (['ontime' => 'On time', 'late' => 'Late', 'absent' => 'Absent'] as $k => $l): ?>
            <button class="exm-q exm-q-<?= $k ?>" name="quick" value="<?= $e("$did:$k") ?>" type="submit" formnovalidate<?= $d['status'] === $k ? ' aria-pressed="true"' : '' ?>><?= $l ?></button>
            <?php endforeach; ?>
          </span></td>
          <?php endif; ?>
          <td class="aud-left"><?= $e($d['role']) ?></td>
          <?php if ($canMark): ?>
          <td class="aud-left"><select name="status[<?= $e($did) ?>]" aria-label="Status for <?= $e($d['name']) ?>">
            <?php foreach (EXAM_STATUS_LABEL as $k => $l): ?><option value="<?= $k ?>"<?= $k === $d['status'] ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
          </select></td>
          <td class="aud-left"><input type="time" name="arrived[<?= $e($did) ?>]" value="<?= $e($d['arrived']) ?>" data-report="<?= $e($exam['report']) ?>" aria-label="Arrival time for <?= $e($d['name']) ?>"></td>
          <td class="aud-left"><input type="text" name="remark[<?= $e($did) ?>]" value="<?= $e($d['remark']) ?>" maxlength="500" placeholder="Remark" aria-label="Remark for <?= $e($d['name']) ?>"></td>
          <?php else: ?>
          <td class="aud-left"><?= $pill($d['status']) ?></td>
          <td class="aud-left"><?= $d['arrived'] !== '' ? $e(exam_clock($d['arrived'])) : '-' ?></td>
          <td class="aud-left"><?= $d['remark'] !== '' ? $e($d['remark']) : '-' ?></td>
          <?php endif; ?>
          <td class="aud-left exm-mail">
            <?php if ($mail): ?><?= $mail['sent'] !== '' ? 'Late email sent ' . $e($mail['sent']) : 'Late email queued' ?>
            <?php elseif ($addr !== ''): ?><?= $canEdit ? $e($addr) : 'Email on file' ?>
            <?php else: ?>No email on file<?php endif; ?>
          </td>
          <?php if ($canMark): ?><td class="no-print"><button class="btn-quiet aud-remove" name="remove" value="<?= $e($did) ?>" type="submit" formnovalidate onclick="return confirm('Remove <?= $e(addslashes($d['name'])) ?> from this exam?')">Remove</button></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($canMark && $duties): ?>
    <p class="field-help no-print">Reporting time is <?= $e(exam_clock($exam['report'])) ?>. An arrival after it is recorded as late, and a late duty with an email on file is sent a notice.</p>
    <button class="btn-primary no-print exm-save" type="submit">Save attendance</button>
    <?php endif; ?>
  </section>
  <?php if ($canMark && $duties): ?></form><?php endif; ?>

  <?php if ($canEdit): ?>
  <form class="card exm-add no-print" method="post"><?= csrf_field() ?><input type="hidden" name="e" value="<?= $e($eid) ?>"><input type="hidden" name="action" value="assign">
    <h2>Add an invigilator</h2>
    <div class="exm-grid">
      <label>Faculty<select name="person" required>
        <option value="">Pick someone</option>
        <?php foreach ($cands as $pid => $c): ?><option value="<?= $e($pid) ?>"><?= $e($c['name'] . (!$c['own'] ? ' (other school)' : '') . ($c['busy'] !== '' ? ' - busy: ' . $c['busy'] : '') . ($c['load'] ? ' - ' . $c['load'] . ' duties' : '')) ?></option><?php endforeach; ?>
      </select></label>
      <label>Role<select name="role"><?php foreach (EXAM_ROLES as $r): ?><option><?= $e($r) ?></option><?php endforeach; ?></select></label>
      <label>Email <span class="label-hint">(optional)</span><input type="email" name="email" placeholder="name@adypu.edu.in"></label>
    </div>
    <button class="btn-quiet" type="submit">Add invigilator</button>
  </form>
  <?php endif; ?>

<?php elseif ($view === 'faculty'):
  // ---------------------------------------------------------------- faculty duties
  // Only people who actually hold a duty in this window: the school filter is
  // the exam's school, and a status shows those with at least one such duty.
  $people = $facPeople;
?>
  <div class="aud-tools no-print"><?php $filterForm('faculty', []); ?></div>
  <section class="card">
    <h2>Faculty on duty <span class="count-pill"><?= count($people) ?></span></h2>
    <?php if (!$people): ?><p class="field-help">Nobody on duty matches.</p><?php else: ?>
    <div class="stat-table-wrap"><table class="stat-table aud-table exm-table">
      <thead><tr><th>Faculty</th><th class="aud-left">School</th><th>Duties</th><th>On time</th><th>Late</th><th>Absent</th><th class="aud-left">Exams</th><th class="no-print"></th></tr></thead>
      <tbody>
        <?php foreach ($people as $k => $p): $c = exam_counts($p['rows']); ?>
        <tr class="<?= $c['absent'] ? 'exm-row-absent' : ($c['late'] ? 'exm-row-late' : ($c['ontime'] === $c['duties'] ? 'exm-row-ontime' : '')) ?>"><th><?= $e($p['name']) ?></th>
          <td class="aud-left"><?= $e(implode(', ', array_map($short, array_values(array_unique($p['schools']))))) ?></td>
          <td><?= $c['duties'] ?></td><td><?= $c['ontime'] ?></td><td class="<?= $c['late'] ? 'exm-n-late' : '' ?>"><?= $c['late'] ?></td><td class="<?= $c['absent'] ? 'exm-n-absent' : '' ?>"><?= $c['absent'] ?></td>
          <td class="aud-left exm-exams"><?php foreach ($p['rows'] as $r): ?><a class="exm-chip exm-st-<?= $e($r['duty']['status']) ?>" href="exams.php?e=<?= $e($r['exam']['id']) ?>" title="<?= $e(EXAM_STATUS_LABEL[$r['duty']['status']]) ?>"><?= $e($r['exam']['subject']) ?> <small><?= $e(date('j M', strtotime($r['exam']['date']))) ?></small></a><?php endforeach; ?></td>
          <td class="no-print"><a class="btn-quiet exm-letter-link" href="exams.php?<?= $e($keep(['view' => 'letter', 'p' => $k, 'st' => ''])) ?>">Duty letter</a></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

<?php elseif ($view === 'report'):
  // ---------------------------------------------------------------- report
  $rows = $repRows;
  $rc = exam_counts($rows);
  $people = exam_report_people($rows);
  $by = fn(string $s): array => array_filter($rows, fn($r) => $r['duty']['status'] === $s);
  $when = fn(array $r): string => $day($r['exam']['date']) . ', ' . exam_clock($r['exam']['start']);
?>
  <div class="aud-tools no-print">
    <?php $filterForm('report', ['time']); ?>
  </div>
  <p class="aud-sub aud-who exm-scope"><?= $school !== '' ? $e($schoolName($school)) : 'Every school' ?><?= $rangeLabel !== '' ? ' &middot; ' . $e($rangeLabel) : '' ?><?= $tFrom !== '' || $tTo !== '' ? ' &middot; starting ' . $e($tFrom ?: '00:00') . ' to ' . $e($tTo ?: '23:59') : '' ?></p>
  <?php if (!$rows): ?><p class="card">No duties match this report.</p><?php else: ?>
  <section class="card">
    <h2>By faculty <span class="count-pill"><?= count($people) ?></span></h2>
    <div class="stat-table-wrap"><table class="stat-table aud-table exm-table">
      <thead><tr><th>Faculty</th><th>Duties</th><th>Attended</th><th>Late</th><th>Missed</th><th>Not marked</th></tr></thead>
      <tbody>
        <?php foreach ($people as $p): ?>
        <tr class="<?= $p['absent'] ? 'exm-row-absent' : ($p['late'] ? 'exm-row-late' : ($p['ontime'] === $p['duties'] ? 'exm-row-ontime' : '')) ?>"><th><?= $e($p['name']) ?></th><td><?= $p['duties'] ?></td><td><?= $p['ontime'] + $p['late'] ?></td><td class="<?= $p['late'] ? 'exm-n-late' : '' ?>"><?= $p['late'] ?></td><td class="<?= $p['absent'] ? 'exm-n-absent' : '' ?>"><?= $p['absent'] ?></td><td><?= $p['allocated'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <?php foreach ([['absent', 'Missed'], ['late', 'Late'], ['ontime', 'Attended on time'], ['allocated', 'Not marked yet']] as [$k, $label]): $set = $by($k);
    if (($st !== '' && $st !== $k) || ($k === 'allocated' && !$set)) continue; ?>
  <section class="card exm-sec-<?= $k ?>">
    <h2><?= $e($label) ?> <span class="count-pill"><?= count($set) ?></span></h2>
    <?php if (!$set): ?><p class="field-help">None.</p><?php else: ?>
    <div class="stat-table-wrap"><table class="stat-table aud-table exm-table">
      <thead><tr><th>Date and time</th><th class="aud-left">Exam</th><th class="aud-left">Faculty</th><?php if ($k !== 'absent'): ?><th class="aud-left">Reporting</th><th class="aud-left">Arrived</th><?php endif; ?><th class="aud-left">Remark</th></tr></thead>
      <tbody>
        <?php foreach ($set as $r): ?>
        <tr class="exm-row-<?= $e($k) ?>"><td class="aud-left"><?= $e($when($r)) ?></td>
          <td class="aud-left"><a href="exams.php?e=<?= $e($r['exam']['id']) ?>"><?= $e(exam_label($r['exam'])) ?></a></td>
          <td class="aud-left"><?= $e($r['duty']['name']) ?></td>
          <?php if ($k !== 'absent'): ?><td class="aud-left"><?= $e(exam_clock($r['exam']['report'])) ?></td><td class="aud-left"><?= $r['duty']['arrived'] !== '' ? $e(exam_clock($r['duty']['arrived'])) : '-' ?></td><?php endif; ?>
          <td class="aud-left"><?= $r['duty']['remark'] !== '' ? $e($r['duty']['remark']) : '-' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>

<?php elseif ($view === 'roster' || $view === 'letter'):
  // ---------------------------------------------------------------- the documents
  // The roster and one person's duty letter, drawn as they print. Export in
  // the header sends them on.
?>
  <?php if ($view === 'letter'): ?><p class="evr-back no-print"><a href="exams.php?<?= $e($keep(['view' => 'faculty'])) ?>"><svg aria-hidden="true"><use href="#icon-back"/></svg>Faculty duties</a></p><?php endif; ?>
  <div class="aud-tools no-print">
    <?php $filterForm($view, $view === 'letter' ? ['p' => $letterKey] : ['alloc']); ?>
  </div>
  <?= exam_doc_html($doc) ?>

<?php else:
  // ---------------------------------------------------------------- by exam
  $byDate = [];
  foreach ($list as $id => $x) $byDate[$x['date']][$id] = $x;
?>
  <div class="aud-tools no-print">
    <?php $filterForm('exams', ['alloc']); ?>
  </div>

  <?php if ($canEdit): ?>
  <details class="card exm-admin no-print">
    <summary><strong>Add an exam</strong></summary>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="exm-grid">
        <label>Date<input type="date" name="date" required></label>
        <label>Start<input type="time" name="start" required></label>
        <label>End<input type="time" name="end" required></label>
        <label>Reporting <span class="label-hint">(optional, 30 min before)</span><input type="time" name="report"></label>
        <label>School<select name="school" required><option value="">Pick a school</option>
          <?php foreach (SCHOOLS as $id => $sc): ?><option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e($sc['name']) ?></option><?php endforeach; ?></select></label>
        <label>Class<input type="text" name="class" maxlength="80" required placeholder="B.Tech CSE Sem III"></label>
        <label>Subject<input type="text" name="subject" maxlength="120" required></label>
        <label>Room<input type="text" name="room" maxlength="40"></label>
        <label>Invigilators needed<input type="number" name="need" min="1" max="40" value="<?= EXAM_DEFAULT_NEED ?>"></label>
      </div>
      <button class="btn-quiet" type="submit">Add exam</button>
    </form>
  </details>
  <details class="card exm-admin no-print">
    <summary><strong>Upload schedule</strong></summary>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
      <p class="field-help">A .csv or .xlsx with a header row: <?= $e(implode(', ', EXAM_COLUMNS)) ?>. Only Date and Subject are needed to find the header; the rest are read by name, in any order. A row already on the schedule is updated and keeps its duties. </p>
      <p class="field-help"><a href="exams.php?<?= $e($keep(['export' => 'template'])) ?>">Download template</a>: the schedule below in this format, to fill in or edit and upload again.</p>
      <input type="file" name="file" accept=".csv,.xlsx" required>
      <button class="btn-quiet" type="submit">Upload</button>
    </form>
  </details>
  <div class="exm-bar no-print">
    <form method="post"><?= csrf_field() ?><button class="btn-quiet" name="action" value="auto" type="submit" title="Fill every short exam in the list below from free faculty">Auto-allocate all short exams</button></form>
    <?php if (!$data['exams']): ?><form method="post"><?= csrf_field() ?><button class="btn-quiet" name="action" value="sample" type="submit">Load sample week</button></form><?php endif; ?>
    <?php if ($hasSample): ?><form method="post" onsubmit="return confirm('Remove every sample exam and its duties?')"><?= csrf_field() ?><button class="btn-quiet aud-remove" name="action" value="clear_sample" type="submit">Remove sample data</button></form><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$list): ?>
    <p class="card"><?= $data['exams'] ? 'No exams match these filters.' : 'No exams are on the schedule yet.' . ($canEdit ? ' Add one, upload a schedule, or load the sample week.' : '') ?></p>
  <?php endif; ?>
  <?php foreach ($byDate as $date => $items): ?>
  <section class="card exm-day">
    <h2 class="exm-date-head"><?= $e($day($date)) ?> <span class="count-pill"><?= count($items) ?></span></h2>
    <ul class="aud-list">
      <?php foreach ($items as $id => $x):
        $c = exam_counts(array_map(fn($did) => ['duty' => $data['duties'][$did], 'exam' => $x], array_keys(exam_duties_of($data, $id))));
        $gap = exam_short($data, $id); $have = $c['duties']; ?>
      <li class="<?= $c['absent'] ? 'exm-row-absent' : ($c['late'] ? 'exm-row-late' : '') ?>"><a href="exams.php?<?= $e(http_build_query(array_filter(['e' => $id, 'st' => $st]))) ?>">
        <span><strong><?= $e($x['subject']) ?></strong><small><?= $e($span($x)) ?> (report <?= $e(exam_clock($x['report'])) ?>) &middot; <?= $e($x['class']) ?> &middot; <?= $e($short($x['school'])) ?><?= $x['room'] !== '' ? ' &middot; Room ' . $e($x['room']) : '' ?></small></span>
        <span class="aud-list-end">
          <?php foreach (['ontime' => 'on time', 'late' => 'late', 'absent' => 'absent'] as $k => $l) if ($c[$k]): ?><span class="status exm-st-<?= $k ?>"><?= $c[$k] ?> <?= $l ?></span><?php endif; ?>
          <span class="status <?= $gap ? 'exm-short' : 'exm-st-ontime' ?>"><?= $have ?>/<?= (int) $x['need'] ?> invigilators</span>
        </span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>
<?php endif; ?>

<script src="js/exams.js?v=<?= filemtime(__DIR__ . '/js/exams.js') ?>"></script>
<?php page_foot(); ?>
