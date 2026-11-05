<?php
// The Audit tab: course files for the academic audit (includes/audit.php).
// Anyone may look, signed in or not; only a course's own faculty member
// uploads and submits, only a program lead of its school reviews, only the
// main dean sets the deadline. Three views:
//   My subjects  every course the timetable lists for this account's person,
//                each opening its 17 items to upload and, once, submit.
//   Review       one school's files: a program lead ticks and remarks them
//                here, a school dean reads them.
//   Summary      the view the tab opens on: the deadline, the tables, the
//                export, and the insights below them.
// ?c=<course> is one course file, drawn for its owner to fill in or for a lead
// to review, whichever the account is. Every view prints as its own report.

require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/audit-insights.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('audit');
$me = auth_user() ?? [];
$role = $me ? audit_role($me) : 'visitor';

$t = timetable_read();
$faculty = $t ? timetable_faculty($t) : [];
$courses = $t ? audit_courses($t, $faculty) : [];
$person = $me ? audit_person_for($me, $faculty) : '';
$files = audit_all();
$deadline = audit_deadline();
$today = date('Y-m-d');

$canReview = in_array($role, ['lead', 'admin'], true);
$seesSchool = true;
$canDeadline = audit_can_set_deadline($me);
// Every school is open to everyone; a lead and a school dean start on their own.
$schoolsShown = array_keys(SCHOOLS);

$cid = (string) ($_GET['c'] ?? $_POST['c'] ?? '');
$course = $courses[$cid] ?? null;
if ($cid !== '' && $course === null) {
    http_response_code(404);
    $course = null;
}

$school = isset($_GET['school']) ? (string) $_GET['school'] : (in_array($role, ['lead', 'school'], true) ? (string) ($me['school'] ?? '') : '');
if (!in_array($school, $schoolsShown, true)) $school = '';
$when = isset(AUDIT_TIMING_LABEL[$_GET['when'] ?? '']) ? (string) $_GET['when'] : '';

$e = fn($v): string => htmlspecialchars((string) $v);
$accounts = auth_data()['users'];
$nameOf = fn(string $email): string => $email === '' ? '' : (string) ($accounts[auth_key($email)]['name'] ?? $email);

// --- Changes, all back to a GET ---------------------------------------------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$me) {
    $error = 'Please sign in to change anything here.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deadline') {
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif (!$canDeadline) {
        $error = 'Only the main dean can set the deadline.';
    } else {
        $error = audit_set_deadline(isset($_POST['clear']) ? '' : (string) ($_POST['deadline'] ?? ''), $me['email']);
    }
    if ($error === '') {
        header('Location: audit.php?view=summary&done=deadline');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $course !== null) {
    $action = (string) ($_POST['action'] ?? '');
    $item = (string) ($_POST['item'] ?? '');
    $mine = $course['person'] === $person;
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif (in_array($action, ['upload', 'link', 'remove', 'submit'], true) && !$mine) {
        $error = 'Only the faculty member who teaches this course can change its file.';
    } elseif (in_array($action, ['review', 'reopen'], true) && !audit_can_review($me, $course)) {
        $error = 'Only a program lead of this school can review it.';
    } else {
        $error = match ($action) {
            'upload' => audit_upload($course, $item, $_FILES['file'] ?? [], $me['email']),
            'link' => audit_set_link($course, $item, (string) ($_POST['link'] ?? ''), $me['email']),
            'remove' => audit_remove($course, $item, $me['email']),
            'submit' => audit_submit($course, ($_POST['sure'] ?? '') === 'yes', $me['email']),
            'review' => audit_review($course, (array) ($_POST['ok'] ?? []), (array) ($_POST['remark'] ?? []), (string) ($_POST['note'] ?? ''), $me['email']),
            'reopen' => audit_reopen($course, $me['email']),
            default => 'Unknown action.',
        };
    }
    if ($error === '') {
        header('Location: audit.php?' . http_build_query(['c' => $course['id'], 'done' => $action]) . ($item !== '' ? '#item-' . rawurlencode($item) : ''));
        exit;
    }
    $files = audit_all();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // PHP drops the whole post, csrf and course included, when it is over
    // post_max_size, so the only thing left to say is why.
    $error = 'That file is too big to upload. Please keep each file under 10 MB.';
}

// --- The export: one row per course file, as the summary has it filtered -----
if (($_GET['export'] ?? '') === 'csv' && $seesSchool) {
    $rows = array_filter(audit_rows($courses, $files, $faculty, $school !== '' ? [$school] : $schoolsShown, $deadline),
        fn($r) => $when === '' || $r['timing'] === $when);
    $name = 'academic-audit-' . ($school !== '' ? $school : 'all-schools') . ($when !== '' ? "-$when" : '') . "-$today.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    // Excel reads a CSV as the local code page unless it starts with a BOM.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['School', 'Faculty', 'Subject', 'Class', 'Divisions', 'Year', 'Semester', 'Required uploaded', 'Status',
        'First submitted', 'Submitted by', 'Deadline', 'Against deadline', 'Reviewed', 'Reviewed by', 'Items approved', 'Remarks']);
    foreach ($rows as $r) {
        $c = $r['course'];
        $f = $r['file'];
        fputcsv($out, [SCHOOLS[$c['school']]['name'] ?? '', $r['faculty'], $c['subject'], $c['class'], implode(', ', $c['divs']),
            $c['year'] ?: '', $c['sem'] ?: '', "{$r['have']}/{$r['req']}", AUDIT_STATUS_LABEL[$r['status']],
            $f['first'] !== '' ? $f['first'] : $f['submitted'], $f['submitted'] === '' ? '' : ($nameOf($f['by']) ?: $r['faculty']), $deadline, AUDIT_TIMING_LABEL[$r['timing']],
            $f['reviewed'], $nameOf($f['reviewer']), $r['approved'], $r['remarks']]);
    }
    fclose($out);
    exit;
}

$mineList = array_filter($courses, fn($c) => $c['person'] === $person && $person !== '');
$tabs = [];
$tabs['summary'] = 'Summary';
$tabs['review'] = $canReview ? 'Review' : 'Course files';
if ($mineList || $role === 'faculty') $tabs['mine'] = 'My subjects';
$view = isset($tabs[(string) ($_GET['view'] ?? '')]) ? (string) $_GET['view'] : array_key_first($tabs);
if ($course !== null) $view = $course['person'] === $person ? 'mine' : ($seesSchool ? 'review' : 'summary');

$statusPill = fn(string $s): string => '<span class="status aud-status-' . $s . '">' . AUDIT_STATUS_LABEL[$s] . '</span>';
$timingPill = fn(string $s): string => $deadline === '' || $s === 'pending' ? '' : '<span class="status aud-when-' . $s . '">' . ($s === 'late' ? 'Late' : 'On time') . '</span>';
$size = fn(int $b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
$who = fn(string $p): string => $faculty[$p]['name'] ?? $p;
$pct = fn(int $a, int $b): string => $b ? round(100 * $a / $b) . '%' : '-';
$day = fn(string $d): string => $d === '' ? '' : date('j M Y', strtotime($d));
$scope = $school !== '' ? [$school] : $schoolsShown;

// The deadline's four numbers for whatever this account oversees: its school,
// every school, or its own courses.
$dueRows = $seesSchool ? audit_rows($courses, $files, $faculty, $scope, $deadline)
    : audit_rows($mineList, $files, $faculty, array_keys(SCHOOLS) + [99 => ''], $deadline);
$insightsFor = fn(string $v): string => audit_insights_html($dueRows, ['view' => $v, 'school' => $school, 'schools' => $schoolsShown, 'deadline' => $deadline, 'today' => $today, 'when' => $when]);
$due = array_count_values(array_column($dueRows, 'timing')) + ['ontime' => 0, 'late' => 0, 'pending' => 0];
$dueTotal = count($dueRows);

// The All / On time / Late / Not submitted switch every table carries.
$whenTabs = function (array $keep) use ($when, $e): string {
    $html = '<nav class="tt-seg aud-when no-print" aria-label="Against the deadline">';
    foreach (['' => 'All'] + AUDIT_TIMING_LABEL as $k => $l) {
        $html .= '<a href="audit.php?' . $e(http_build_query(array_filter($keep + ['when' => $k]))) . '"' . ($k === $when ? ' aria-current="page"' : '') . '>' . $e($l) . '</a>';
    }
    return $html . '</nav>';
};

$titleBits = ['Academic audit', $course['subject'] ?? ($tabs[$view] ?? ''), $school !== '' && $seesSchool && $course === null ? preg_replace('/^School of /', '', SCHOOLS[$school]['name']) : '', $when !== '' ? AUDIT_TIMING_LABEL[$when] : '', date('j M Y')];
page_head(implode(' - ', array_filter($titleBits)), 'page-wide page-audit', 'audit');
page_notice('warn', $error);
$done = (string) ($_GET['done'] ?? '');
if ($error === '' && $done !== '') page_notice('ok', match ($done) {
    'upload' => 'Uploaded and saved.', 'link' => 'Link saved.', 'remove' => 'Removed.',
    'submit' => 'Submitted. This course file is now final.', 'review' => 'Review saved.',
    'reopen' => 'Opened for editing. The faculty member can change it and submit again.',
    'deadline' => $deadline === '' ? 'Deadline cleared.' : 'Deadline set to ' . $day($deadline) . '. Every view counts against it now.',
    default => 'Saved.',
});
?>
<svg style="display:none" aria-hidden="true"><defs>
  <symbol id="icon-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></symbol>
  <symbol id="icon-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></symbol>
  <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
</defs></svg>
<div class="aud-head">
  <div>
    <h2>Academic audit</h2>
    <p class="aud-sub"><?= $e(AUDIT_TERM) ?> &middot; Course File Index &middot; as of <?= $e(date('D j M Y')) ?></p>
  </div>
  <div class="aud-head-end">
    <?php if (count($tabs) > 1): ?>
    <nav class="tt-seg page-tabs no-print" aria-label="Section">
      <?php foreach ($tabs as $k => $l): ?>
      <a href="audit.php?view=<?= $k ?>"<?= $k === $view && $course === null ? ' aria-current="page"' : '' ?>><?= $l ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <button class="range-export no-print" type="button" onclick="window.print()" title="Print this view, or save it as a PDF"><svg aria-hidden="true"><use href="#icon-print"/></svg>Report / PDF</button>
  </div>
</div>

<?php if ($t && $seesSchool): ?>
<a class="aud-due<?= $deadline !== '' && audit_days_left($deadline, $today) < 0 ? ' is-past' : '' ?>" href="audit.php?<?= $e(http_build_query(array_filter(['view' => 'summary', 'school' => count($schoolsShown) > 1 ? $school : '']))) ?>" title="Open the summary">
  <span class="aud-due-cell aud-due-date"><svg aria-hidden="true"><use href="#icon-clock"/></svg><span><small>Deadline<?= $school !== '' && count($schoolsShown) > 1 ? ' &middot; ' . $e(preg_replace('/^School of /', '', SCHOOLS[$school]['name'])) : '' ?></small><strong><?= $deadline !== '' ? $e($day($deadline)) : 'Not set' ?></strong><em><?= $e(audit_due_label($deadline, $today)) ?></em></span></span>
  <span class="aud-due-cell is-ontime"><small>On time</small><strong><?= $due['ontime'] ?></strong><em><?= $pct($due['ontime'], $dueTotal) ?></em></span>
  <span class="aud-due-cell is-late"><small>Late</small><strong><?= $due['late'] ?></strong><em><?= $pct($due['late'], $dueTotal) ?></em></span>
  <span class="aud-due-cell is-pending"><small>Not submitted</small><strong><?= $due['pending'] ?></strong><em>of <?= $dueTotal ?> course files</em></span>
</a>
<?php elseif ($t && $deadline !== ''): ?>
<p class="aud-due-strip<?= audit_days_left($deadline, $today) < 0 ? ' is-past' : '' ?>"><svg aria-hidden="true"><use href="#icon-clock"/></svg>
  <span>Audit due by <strong><?= $e($day($deadline)) ?></strong> &middot; <?= $e(audit_due_label($deadline, $today)) ?> &middot; <?= $due['ontime'] + $due['late'] ?> of <?= $dueTotal ?> course files submitted</span></p>
<?php endif; ?>

<?php if (!$t): ?>
  <p class="card">The timetable is not loaded on this site, so there are no courses to audit yet.</p>
<?php elseif ($course !== null):
  // ---------------------------------------------------------------- one course
  $f = audit_file($files, $course['id']);
  $status = audit_status($f);
  $mine = $course['person'] === $person;
  $editable = $mine && !audit_locked($f);
  $reviewing = !$mine && audit_can_review($me, $course) && audit_locked($f);
  [$have, $req] = audit_progress($f);
  $missing = audit_missing($f);
  $back = $mine ? 'audit.php?view=mine' : 'audit.php?' . http_build_query(['view' => $view, 'school' => $course['school']]);
  $users = auth_data()['users'];
  $leads = audit_staff($users, $course['school'], 'lead');
  $deans = audit_staff($users, $course['school'], 'school');
?>
  <p class="evr-back no-print"><a href="<?= $e($back) ?>"><svg aria-hidden="true"><use href="#icon-back"/></svg><?= $mine ? 'My subjects' : 'All course files' ?></a></p>
  <section class="card aud-course">
    <div class="aud-course-head">
      <div>
        <h2><?= $e($course['subject']) ?> <?= $statusPill($status) ?> <?= $timingPill(audit_timing($f, $deadline)) ?></h2>
        <p class="aud-meta"><?= $e(audit_course_label($course)) ?></p>
        <p class="aud-meta"><?= $e(audit_term_label($course)) ?> &middot; <?= $e(SCHOOLS[$course['school']]['name'] ?? '') ?><?php if (!$mine): ?> &middot; <strong><?= $e($who($course['person'])) ?></strong><?php endif; ?></p>
        <p class="aud-meta aud-staff">Program lead: <strong><?= $e($leads ? implode(', ', $leads) : 'Not assigned yet') ?></strong>
          <?php if ($deans): ?> &middot; School dean: <strong><?= $e(implode(', ', $deans)) ?></strong><?php endif; ?></p>
      </div>
      <div class="aud-count"><strong><?= $have ?>/<?= $req ?></strong><span>required uploaded</span></div>
    </div>
    <div class="aud-bar" aria-hidden="true"><i style="width:<?= $req ? round(100 * $have / $req) : 0 ?>%"></i></div>
    <?php if ($status === 'submitted'): ?>
      <p class="field-help">Submitted by <strong><?= $e($nameOf($f['by']) ?: $who($course['person'])) ?></strong> on <?= $e($f['submitted']) ?>. Locked: nothing can change unless a program lead opens it for editing.</p>
    <?php elseif ($status === 'approved'): ?>
      <p class="field-help">Submitted by <strong><?= $e($nameOf($f['by']) ?: $who($course['person'])) ?></strong> on <?= $e($f['submitted']) ?>. Approved by <strong><?= $e($nameOf($f['reviewer']) ?: 'a program lead') ?></strong> on <?= $e($f['reviewed']) ?>.</p>
    <?php elseif ($f['reopened'] !== ''): ?>
      <p class="field-help">A program lead opened this for editing on <?= $e($f['reopened']) ?>. Fix what the remarks ask for, then submit again.</p>
    <?php elseif ($editable): ?>
      <p class="field-help">Every upload is saved as you go. When all required items are in, submit once at the bottom. After that the file is final.</p>
    <?php endif; ?>
    <?php if ($f['note'] !== '' && ($status === 'approved' || $f['reopened'] !== '' || !$mine)): ?>
      <p class="aud-note"><strong>Lead's remark:</strong> <?= $e($f['note']) ?></p>
    <?php endif; ?>
  </section>

  <?php if ($reviewing): ?>
  <form method="post" class="card aud-items" id="aud-review">
    <?= csrf_field() ?><input type="hidden" name="c" value="<?= $e($course['id']) ?>">
    <div class="aud-all-row"><span>Course File Index</span><label class="aud-all">Approve all uploaded items <input type="checkbox" id="aud-select-all"></label></div>
  <?php else: ?>
  <section class="card aud-items">
  <?php endif; ?>
    <?php $group = ''; foreach (AUDIT_ITEMS as $k => $def):
      [$title, $required] = $def;
      $isLink = ($def[2] ?? '') === 'link';
      $it = $f['items'][$k] ?? [];
      $filled = audit_filled($it);
      $g = preg_match('/^(\d+)[A-Z]$/', $k, $m) ? $m[1] : '';
      if ($g !== '' && $g !== $group): $group = $g; ?>
      <div class="aud-group"><span class="aud-num"><?= $e($g) ?></span><?= $e(AUDIT_GROUPS[$g] ?? '') ?></div>
      <?php endif; ?>
    <div class="aud-item<?= $g !== '' ? ' is-sub' : '' ?><?= $filled ? ' is-filled' : '' ?>" id="item-<?= $e($k) ?>">
      <span class="aud-num"><?= $e($g !== '' ? substr($k, -1) : $k) ?></span>
      <div class="aud-what">
        <strong><?= $e($title) ?></strong>
        <?php if (!$required): ?><span class="aud-opt">Optional</span><?php endif; ?>
        <?php if ($filled): ?>
          <span class="aud-file">
            <?php if ($isLink): ?>
              <a href="<?= $e($it['link']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($it['link']) ?></a>
            <?php else: ?>
              <a href="audit-file.php?<?= $e(http_build_query(['c' => $course['id'], 'i' => $k])) ?>" target="_blank"><?= $e($it['name']) ?></a>
              <small><?= $size((int) $it['size']) ?></small>
            <?php endif; ?>
            <small><?= $e($it['at']) ?></small>
            <?php if (!empty($it['ok']) && !$reviewing): ?><span class="status status-active">Approved</span><?php endif; ?>
          </span>
        <?php elseif (!$editable): ?>
          <span class="aud-empty">Not uploaded</span>
        <?php endif; ?>
        <?php if (($it['remark'] ?? '') !== '' && !$reviewing && ($status === 'approved' || $f['reopened'] !== '' || !$mine)): ?>
          <p class="aud-remark"><strong>Remark:</strong> <?= $e($it['remark']) ?></p>
        <?php endif; ?>
        <?php if ($reviewing): ?>
          <input class="aud-remark-input" type="text" name="remark[<?= $e($k) ?>]" value="<?= $e($it['remark'] ?? '') ?>" maxlength="500" placeholder="Remark (optional)" aria-label="Remark on item <?= $e($k) ?>">
        <?php endif; ?>
      </div>
      <div class="aud-do no-print">
        <?php if ($reviewing): ?>
          <label class="aud-tick"><input type="checkbox" name="ok[]" value="<?= $e($k) ?>"<?= !empty($it['ok']) ? ' checked' : '' ?><?= $filled ? '' : ' disabled' ?>> Approve</label>
        <?php elseif ($editable && $isLink): ?>
          <form method="post" class="aud-link-form"><?= csrf_field() ?>
            <input type="hidden" name="c" value="<?= $e($course['id']) ?>"><input type="hidden" name="item" value="<?= $e($k) ?>">
            <input type="url" name="link" value="<?= $e($it['link'] ?? '') ?>" placeholder="https://" aria-label="Link for item <?= $e($k) ?>" required>
            <button class="btn-quiet" name="action" value="link" type="submit">Save</button>
          </form>
        <?php elseif ($editable): ?>
          <form method="post" enctype="multipart/form-data" class="aud-up"><?= csrf_field() ?>
            <input type="hidden" name="c" value="<?= $e($course['id']) ?>"><input type="hidden" name="item" value="<?= $e($k) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= AUDIT_MAX_BYTES ?>">
            <label class="btn-quiet aud-pick"><input type="file" name="file" accept="<?= $e('.' . implode(',.', array_keys(AUDIT_TYPES))) ?>" required><?= $filled ? 'Replace' : 'Upload' ?></label>
            <noscript><button class="btn-quiet" type="submit">Send</button></noscript>
          </form>
        <?php endif; ?>
        <?php if ($editable && $filled): ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="c" value="<?= $e($course['id']) ?>"><input type="hidden" name="item" value="<?= $e($k) ?>">
            <button class="btn-quiet aud-remove" name="action" value="remove" type="submit" aria-label="Remove item <?= $e($k) ?>">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php if ($reviewing): ?>
    <label for="aud-note">Overall remark <span class="label-hint">(optional, seen by the faculty member and the deans)</span></label>
    <textarea id="aud-note" name="note" maxlength="1000" rows="3"><?= $e($f['note']) ?></textarea>
    <button class="btn-primary no-print" name="action" value="review" type="submit">Save review</button>
  </form>
  <form method="post" class="aud-reopen no-print" onsubmit="return confirm('Open this course file for editing? The faculty member will have to submit it again.')">
    <?= csrf_field() ?><input type="hidden" name="c" value="<?= $e($course['id']) ?>">
    <button class="btn-quiet" name="action" value="reopen" type="submit">Allow editing</button>
    <span class="field-help">Unlocks the file so its faculty member can replace uploads and submit again.</span>
  </form>
  <?php else: ?>
  </section>
  <?php endif; ?>

  <?php if ($editable): ?>
  <section class="card aud-submit no-print">
    <h2>Final submit</h2>
    <?php if ($missing): ?>
      <p class="field-help">Still to upload: item <?= $e(implode(', ', $missing)) ?>. Submit opens once every required item is in.</p>
    <?php else: ?>
      <p class="field-help">Everything required is in. Once submitted, these files cannot be changed or removed.</p>
      <button class="btn-primary" id="aud-submit-open" type="button">Submit course file</button>
    <?php endif; ?>
  </section>
  <?php if (!$missing): ?>
  <div class="modal-backdrop aud-backdrop" id="aud-confirm" hidden>
    <form class="modal aud-modal" method="post" role="dialog" aria-modal="true" aria-labelledby="aud-confirm-title">
      <?= csrf_field() ?><input type="hidden" name="c" value="<?= $e($course['id']) ?>"><input type="hidden" name="sure" value="yes">
      <h2 id="aud-confirm-title">Submit this course file?</h2>
      <p><?= $e($course['subject']) ?> &middot; <?= $e(audit_course_label($course)) ?></p>
      <p>Once you submit, the files are final and cannot be changed or removed. If something needs fixing later, your program lead can open it again.</p>
      <div class="aud-modal-actions">
        <button class="btn-quiet" type="button" data-close>Cancel</button>
        <button class="btn-primary" name="action" value="submit" type="submit">Yes, submit</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>

<?php elseif ($view === 'mine'):
  // ---------------------------------------------------------------- my subjects
?>
  <?php if ($person === ''): ?>
    <p class="card">Your account is not linked to a name on the timetable yet, so your subjects cannot be listed. Ask an admin to link it on Faculty accounts.</p>
  <?php elseif (!$mineList): ?>
    <p class="card">The timetable lists no subjects for <?= $e($who($person)) ?>.</p>
  <?php else: ?>
    <p class="aud-sub aud-who"><?= $e($who($person)) ?> &middot; <?= count($mineList) ?> subject<?= count($mineList) === 1 ? '' : 's' ?></p>
    <div class="aud-grid">
      <?php foreach ($mineList as $c): $f = audit_file($files, $c['id']); [$have, $req] = audit_progress($f); ?>
      <a class="card aud-card" href="audit.php?c=<?= $e($c['id']) ?>">
        <strong><?= $e($c['subject']) ?></strong>
        <span class="aud-meta"><?= $e(audit_course_label($c)) ?></span>
        <span class="aud-meta"><?= $e(audit_term_label($c)) ?></span>
        <span class="aud-card-foot"><span><?= $statusPill(audit_status($f)) ?> <?= $timingPill(audit_timing($f, $deadline)) ?></span><small><?= $have ?>/<?= $req ?> required</small></span>
        <span class="aud-bar" aria-hidden="true"><i style="width:<?= $req ? round(100 * $have / $req) : 0 ?>%"></i></span>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'review'):
  // ---------------------------------------------------------------- a school's files
  $want = isset(AUDIT_STATUS_LABEL[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '';
  $byPerson = [];
  if ($school !== '') {
      foreach (audit_rows($courses, $files, $faculty, [$school], $deadline) as $r) {
          if ($want !== '' && $r['status'] !== $want) continue;
          if ($when !== '' && $r['timing'] !== $when) continue;
          $byPerson[$r['course']['person']][] = $r;
      }
  }
  uksort($byPerson, fn($a, $b) => strnatcasecmp(timetable_person($who($a)), timetable_person($who($b))));
?>
  <div class="aud-tools no-print">
    <form class="aud-filters" method="get"><input type="hidden" name="view" value="review"><?php if ($when !== ''): ?><input type="hidden" name="when" value="<?= $e($when) ?>"><?php endif; ?>
      <?php if (count($schoolsShown) > 1): ?>
      <label><span class="sr-only">School</span><select name="school" onchange="this.form.submit()">
        <option value="">Pick a school</option>
        <?php foreach ($schoolsShown as $id): ?><option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e(SCHOOLS[$id]['name']) ?></option><?php endforeach; ?>
      </select></label>
      <?php endif; ?>
      <label><span class="sr-only">Status</span><select name="status" onchange="this.form.submit()">
        <option value="">Every status</option>
        <?php foreach (AUDIT_STATUS_LABEL as $k => $l): ?><option value="<?= $k ?>"<?= $k === $want ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></label>
      <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
    </form>
    <?= $whenTabs(['view' => 'review', 'school' => count($schoolsShown) > 1 ? $school : '', 'status' => $want]) ?>
  </div>
  <?php if ($school === ''): ?>
    <p class="card">Pick a school to see its course files.</p>
  <?php elseif (!$byPerson): ?>
    <p class="card">No course files match.</p>
  <?php else: ?>
    <?php foreach ($byPerson as $p => $list):
      $n = array_count_values(array_column($list, 'timing')); ?>
    <details class="card aud-person" id="p-<?= $e($p) ?>"<?= in_array('submitted', array_column($list, 'status'), true) || $when !== '' ? ' open' : '' ?>>
      <summary><strong><?= $e($who($p)) ?></strong>
        <span class="aud-counts"><?php foreach (AUDIT_TIMING_LABEL as $k => $l): if (!empty($n[$k])): ?><span class="status aud-when-<?= $k ?>"><?= $n[$k] ?> <?= $e(strtolower($l)) ?></span><?php endif; endforeach; ?></span></summary>
      <ul class="aud-list">
        <?php foreach ($list as $r): $c = $r['course']; $f = $r['file']; ?>
        <li><a href="audit.php?c=<?= $e($c['id']) ?>">
          <span><strong><?= $e($c['subject']) ?></strong><small><?= $e(audit_course_label($c)) ?> &middot; <?= $e(audit_term_label($c)) ?><?= $f['submitted'] !== '' ? ' &middot; submitted by ' . $e($nameOf($f['by']) ?: $who($p)) . ' ' . $e($day($f['first'] ?: $f['submitted'])) : '' ?><?= $f['reviewed'] !== '' ? ' &middot; approved by ' . $e($nameOf($f['reviewer'])) . ' ' . $e($day($f['reviewed'])) : '' ?></small></span>
          <span class="aud-list-end"><small><?= $r['have'] ?>/<?= $r['req'] ?></small><?= $timingPill($r['timing']) ?><?= $statusPill($r['status']) ?></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endforeach; ?>
  <?php endif; ?>
  <?= $insightsFor('review') ?>

<?php else:
  // ---------------------------------------------------------------- summary
  $sum = audit_summary($courses, $files, $faculty, $scope, $deadline);
  $tot = fn(array $people): array => array_reduce($people, function ($a, $r) {
      foreach (['courses', 'started', 'submitted', 'reviewed', 'ontime', 'late', 'pending', 'filled', 'approved'] as $k) $a[$k] += $r[$k];
      $a['people']++;
      $a['remarks'] += count($r['remarks']);
      return $a;
  }, ['people' => 0, 'courses' => 0, 'started' => 0, 'submitted' => 0, 'reviewed' => 0, 'ontime' => 0, 'late' => 0, 'pending' => 0, 'filled' => 0, 'approved' => 0, 'remarks' => 0]);
  $everyone = [];
  foreach ($sum as $sid => $people) foreach ($people as $r) $everyone[] = $r + ['school' => $sid];
  $shownPeople = array_values(array_filter($everyone, fn($r) => $when === '' || audit_person_timing($r) === $when));
  $all = $tot($everyone);
  $rows = audit_rows($courses, $files, $faculty, $scope, $deadline);
  $exportUrl = 'audit.php?' . http_build_query(array_filter(['view' => 'summary', 'school' => count($schoolsShown) > 1 ? $school : '', 'when' => $when, 'export' => 'csv']));
  $personPill = fn(array $r): string => '<span class="status aud-when-' . audit_person_timing($r) . '">' . (audit_person_timing($r) === 'pending' ? 'Not submitted' : (audit_person_timing($r) === 'late' ? 'Late' : 'All on time')) . '</span>';
?>
  <div class="aud-tools no-print">
    <?php if (count($schoolsShown) > 1): ?>
    <form class="aud-filters" method="get"><input type="hidden" name="view" value="summary"><?php if ($when !== ''): ?><input type="hidden" name="when" value="<?= $e($when) ?>"><?php endif; ?>
      <label><span class="sr-only">School</span><select name="school" onchange="this.form.submit()">
        <option value="">Every school</option>
        <?php foreach ($schoolsShown as $id): ?><option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e(SCHOOLS[$id]['name']) ?></option><?php endforeach; ?>
      </select></label>
    </form>
    <?php endif; ?>
    <?= $whenTabs(['view' => 'summary', 'school' => count($schoolsShown) > 1 ? $school : '']) ?>
    <a class="range-export" href="<?= $e($exportUrl) ?>" title="Every course file in this view, as a spreadsheet"><svg aria-hidden="true"><use href="#icon-download"/></svg>Export</a>
  </div>

  <?php if ($canDeadline): ?>
  <form class="card aud-deadline no-print" method="post"><?= csrf_field() ?>
    <label for="aud-deadline-date">Audit deadline <span class="label-hint">(every school, shown on every audit view)</span></label>
    <div class="aud-deadline-row">
      <input type="date" id="aud-deadline-date" name="deadline" value="<?= $e($deadline) ?>" required>
      <button class="btn-quiet" name="action" value="deadline" type="submit"><?= $deadline === '' ? 'Set deadline' : 'Change' ?></button>
      <?php if ($deadline !== ''): ?><button class="btn-quiet aud-remove" name="action" value="deadline" type="submit" formnovalidate onclick="this.form.appendChild(Object.assign(document.createElement('input'),{type:'hidden',name:'clear',value:'1'}))">Clear</button><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($school === ''): ?>
  <section class="card">
    <h2>By school</h2>
    <div class="stat-table-wrap"><table class="stat-table aud-table">
      <thead><tr><th>School</th><th>Faculty</th><th>Course files</th><th>On time</th><th>Late</th><th>Not submitted</th><th>Reviewed</th><th>Items approved</th><th>Remarks</th></tr></thead>
      <tbody>
        <?php foreach ($sum as $sid => $people): $x = $tot($people); ?>
        <tr><th><a href="audit.php?<?= $e(http_build_query(array_filter(['view' => 'summary', 'school' => $sid, 'when' => $when]))) ?>"><?= $e(SCHOOLS[$sid]['name'] ?? 'No school') ?></a></th>
          <td><?= $x['people'] ?></td><td><?= $x['courses'] ?></td>
          <td><?= $x['ontime'] ?> <small><?= $pct($x['ontime'], $x['courses']) ?></small></td>
          <td><?= $x['late'] ?></td>
          <td><?= $x['pending'] ?> <small><?= $pct($x['pending'], $x['courses']) ?></small></td>
          <td><?= $x['reviewed'] ?></td><td><?= $x['approved'] ?>/<?= $x['filled'] ?></td><td><?= $x['remarks'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="field-help no-print">Pick a school to see each faculty member.</p>
  </section>
  <?php else: /* Faculty one by one only inside a school (the VC's call). */ ?>

  <section class="card">
    <h2><?= $school !== '' ? $e(SCHOOLS[$school]['name']) . ': every faculty member' : 'Every faculty member' ?>
      <span class="count-pill"><?= count($shownPeople) ?><?= $when !== '' ? ' ' . $e(strtolower(AUDIT_TIMING_LABEL[$when])) : '' ?></span></h2>
    <?php if (!$shownPeople): ?><p class="field-help">Nobody here matches.</p><?php else: ?>
    <div class="stat-table-wrap"><table class="stat-table aud-table">
      <thead><tr><th>Faculty</th><?php if ($school === ''): ?><th class="aud-left">School</th><?php endif; ?><th>Course files</th><th>On time</th><th>Late</th><th>Not submitted</th><th class="aud-left">Last submitted</th><th>Reviewed</th><th class="aud-left">Approved by</th><th>Items approved</th><th>Remarks</th><th class="aud-left">Against deadline</th></tr></thead>
      <tbody>
        <?php foreach ($shownPeople as $r): ?>
        <tr><th><?= $e($r['name']) ?></th><?php if ($school === ''): ?><td class="aud-left"><?= $e(preg_replace('/^School of /', '', SCHOOLS[$r['school']]['name'] ?? '')) ?></td><?php endif; ?>
          <td><?= $r['courses'] ?></td><td><?= $r['ontime'] ?></td><td><?= $r['late'] ?></td><td><?= $r['pending'] ?></td>
          <td class="aud-left"><?= $r['last'] !== '' ? $e($day($r['last'])) : '-' ?></td>
          <td><?= $r['reviewed'] ?></td><td class="aud-left"><?= $r['reviewers'] ? $e(implode(', ', array_map($nameOf, array_keys($r['reviewers'])))) : '-' ?></td>
          <td><?= $r['approved'] ?>/<?= $r['filled'] ?></td><td><?= count($r['remarks']) ?></td><td class="aud-left"><?= $personPill($r) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Remarks <span class="count-pill"><?= $all['remarks'] ?></span></h2>
    <?php if (!$all['remarks']): ?><p class="field-help">No program lead has written a remark yet.</p><?php endif; ?>
    <ul class="aud-remarks">
      <?php foreach ($everyone as $r): foreach ($r['remarks'] as $m): ?>
      <li><a href="audit.php?c=<?= $e($m['course']) ?><?= $m['item'] !== '' ? '#item-' . $e($m['item']) : '' ?>"><strong><?= $e($r['name']) ?></strong> &middot; <?= $e($m['subject']) ?> &middot; <?= $m['item'] !== '' ? 'Item ' . $e($m['item']) . ', ' : '' ?><?= $e($m['title']) ?></a>
        <p><?= $e($m['text']) ?></p></li>
      <?php endforeach; endforeach; ?>
    </ul>
  </section>

  <?= $insightsFor('summary') ?>
<?php endif; ?>

<script src="js/audit.js?v=<?= filemtime(__DIR__ . '/js/audit.js') ?>"></script>
<?php page_foot(); ?>
