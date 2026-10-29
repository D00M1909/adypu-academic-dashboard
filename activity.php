<?php
// Activity, for admins: what each faculty account has done on the site, from
// the log includes/activity.php writes. Who signed in, from where and on what,
// every class they marked and how long after the lecture, whose reading a save
// replaced, failed sign-ins, admin actions, and which accounts have not marked
// anything at all.
//
// Under the filters, the picked account's own record (when one is picked),
// then one of three tabs: one day's events, app and Google Form, newest first;
// a leaderboard of who files the most attendance, Form and app together; and
// every account over the last N days.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/structure.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('activity');
auth_require(true);

$e = fn($v): string => htmlspecialchars((string) $v);
$today = date('Y-m-d');
$day = row_date((string) ($_GET['day'] ?? '')) ?? $today;
if ($day > $today) $day = $today;
$span = in_array((int) ($_GET['span'] ?? 7), [1, 7, 30, 90], true) ? (int) ($_GET['span'] ?? 7) : 7;
// The leaderboard, the accounts table and an account's record always cover
// the last $span days up to today; the Day picker moves only the day's log.
// Tying both to one date made the leaderboard jump whenever a day was picked.
$from = date('Y-m-d', strtotime("$today -" . ($span - 1) . ' days'));

// Everyone with an account, plus the config admin, who has no stored record
// until their first password change.
$users = auth_data()['users'];
if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '' && !isset($users[auth_key(ADMIN_EMAIL)])) {
    $users[auth_key(ADMIN_EMAIL)] = ['name' => 'Administrator', 'school' => '', 'status' => 'active', 'admin' => true];
}
$who = auth_key((string) ($_GET['who'] ?? ''));
if ($who !== '' && !isset($users[$who])) $who = '';
// What the day's list shows: everything but page views by default, since a
// morning of views buries the saves the page is mostly opened for.
$kinds = [
    'work' => 'Everything but page views',
    'all' => 'Everything',
    'mark' => 'Attendance, app and Form',
    'form' => 'Google Form entries',
    'sign' => 'Sign-ins and sign-outs',
    'signin_failed' => 'Failed sign-ins',
    'admin' => 'Admin actions',
];
$kind = isset($kinds[(string) ($_GET['kind'] ?? '')]) ? (string) $_GET['kind'] : 'work';
// One section at a time, so the leaderboard is not a scroll past the log.
$tabs = ['log' => 'Log', 'board' => 'Leaderboard', 'accounts' => 'Accounts'];
$tab = isset($tabs[(string) ($_GET['tab'] ?? '')]) ? (string) $_GET['tab'] : 'log';
// The leaderboard for one school: only lectures filed for that school's
// classes count, so a person who files for two schools is ranked in each on
// what they filed there. Checked against the schools on the board below.
$school = (string) ($_GET['school'] ?? '');

$events = activity_range(min($from, $day), $today);
$summary = activity_summary(array_filter($events, fn($x) => $x['day'] >= $from));
// Which of the day's events the log lists, for the account and Show picked.
$keep = fn(array $x): bool => ($who === '' || $x['who'] === $who)
    && match ($kind) {
        'all' => true,
        'work' => $x['type'] !== 'view',
        'mark' => in_array($x['type'], ['mark', 'mark_failed', 'form'], true),
        'sign' => in_array($x['type'], ['signin', 'resume', 'signout', 'signup', 'password'], true),
        default => $x['type'] === $kind,
    };
$dayEvents = array_filter($events, fn($x) => $x['day'] === $day && $keep($x));
$daySummary = activity_summary(array_filter($events, fn($x) => $x['day'] === $day));
$failedToday = count(array_filter($events, fn($x) => $x['day'] === $day && $x['type'] === 'signin_failed'));

// Accounts that can mark and have saved nothing in the window: the list an
// admin chases.


// The leaderboard, over the same window as the accounts table. The Form's
// names come straight from the cache (not get_attendance_faculty(), which
// would hide which way a lecture came in), the app's from its own store.
$cache = read_attendance_cache() ?? [];
$formNames = remap_legacy_days(is_array($cache['faculty'] ?? null) ? $cache['faculty'] : []);
$appNames = remap_legacy_days(submitted('faculty'));
[$board, $unnamed] = activity_leaderboard($formNames, $appNames, $from, $today);
// The schools anyone filed for in the window, in SCHOOLS order, partners last.
$boardSchools = [];
foreach ($board as $r) $boardSchools += $r['schools'];
$boardSchools = array_keys(array_intersect_key(array_flip([...array_keys(SCHOOLS), ...array_keys($boardSchools)]), $boardSchools));
if (!in_array($school, $boardSchools, true)) $school = '';
// date => class key => lecture => name, keeping only $school's classes.
$inSchool = fn(array $names): array => array_filter(array_map(
    fn($classes) => array_filter((array) $classes, fn($k) => explode('|', (string) $k)[0] === $school, ARRAY_FILTER_USE_KEY), $names));
$shown = $school === '' ? $board : activity_leaderboard($inSchool($formNames), $inSchool($appNames), $from, $today)[0];
// A name that is also an account's links to that account's record.
$accountOf = [];
foreach ($users as $k => $u) $accountOf[activity_person((string) ($u['name'] ?? ''))] = $k;
$topLectures = max([1, ...array_column($shown, 'lectures')]);
// The leaderboard shows its top rows and folds the rest behind a button,
// unless the account picked is further down, when it opens whole.
const ACTIVITY_BOARD_TOP = 10;
// Each account's own leaderboard row: what it has marked, read from the
// attendance itself, so saves from before the log began still count. The log
// adds only what attendance cannot say (devices, pages, sign-ins, replaced).
$boardOf = [];
foreach ($board as $r) if (isset($accountOf[$r['key']])) $boardOf[$accountOf[$r['key']]] = $r;
$quiet = array_filter($users, fn($u, $k) => ($u['status'] ?? '') === 'active' && empty($u['admin']) && empty($boardOf[$k]), ARRAY_FILTER_USE_BOTH);
// "eng|2nd Year|CSE|A" as "2nd Year / CSE / A": the school is on the card already.
$keyLabel = fn(string $k): string => implode(' / ', array_filter(array_slice(explode('|', $k), 1), 'strlen'));
$logFrom = activity_first_day();
// The day's Google Form entries join the app's events in the one log, newest
// first, from the row-by-row record each push now leaves (form_log()).
$formLog = store_read('formlog');
$formDay = activity_form_day($formLog, $day, $users, $accountOf);
$dayEvents = array_merge($dayEvents, array_filter($formDay, $keep));
usort($dayEvents, fn($a, $b) => $b['t'] <=> $a['t']);

$nameOf = fn(string $k): string => (string) ($users[$k]['name'] ?? $k);
$short = fn(string $label): string => preg_replace('/^(School of [^\/]+|[^\/]+) \/ /', '', $label);
$ago = function (string $at) use ($today): string {
    if ($at === '') return 'never';
    [$d, $t] = explode(' ', $at);
    if ($d === $today) return 'today ' . substr($t, 0, 5);
    if ($d === date('Y-m-d', strtotime("$today -1 day"))) return 'yesterday ' . substr($t, 0, 5);
    return date('j M', strtotime($d)) . ' ' . substr($t, 0, 5);
};
$lag = function (int $m): string {
    if ($m < 0) return 'before the lecture';
    if ($m < 60) return "$m min after";
    if ($m < 24 * 60) return round($m / 60) . ' h after';
    return round($m / 1440) . ' days after';
};
$link = fn(array $over): string => 'activity.php?' . http_build_query(array_filter($over + ['tab' => $tab === 'log' ? '' : $tab, 'who' => $who, 'day' => $day === $today ? '' : $day, 'span' => $span === 7 ? '' : $span, 'kind' => $kind === 'work' ? '' : $kind, 'school' => $school], fn($v) => $v !== '' && $v !== null));

// One event's details as a line of plain text.
$when = fn(string $time): string => $time === WHOLE_DAY ? 'whole day' : str_replace('-', ' to ', $time);
$describe = function (array $x) use ($short, $lag, $nameOf, $when): string {
    $d = $x['d'] ?? [];
    return match ($x['type']) {
        'mark' => $short($d['class'] ?? '') . ', ' . date('j M', strtotime($d['date'] ?? $x['day'])) . ' ' . $when($d['time'] ?? '')
            . ': ' . ($d['present'] ?? '?') . '/' . ($d['strength'] ?? '?') . ' present'
            . (($d['how'] ?? '') === 'roster' ? ' (' . ($d['absent'] ?? 0) . ' ticked absent)' : ' (number typed)')
            . (isset($d['lag']) ? ', filed ' . $lag((int) $d['lag']) : '')
            . (isset($d['replaced']) ? '. Replaced ' . $d['replaced']['present'] . ' present' . (($d['replaced']['faculty'] ?? '') !== '' ? ' by ' . $d['replaced']['faculty'] : '') : ''),
        'mark_failed' => ($d['why'] ?? '') . ($d['class'] ?? '') !== '' ? ($d['why'] ?? '') . ' (' . $short($d['class'] ?? '') . ')' : ($d['why'] ?? ''),
        'signin', 'resume' => ucfirst($d['how'] ?? ''),
        'signin_failed' => ucfirst($d['why'] ?? ''),
        'signout' => ucfirst($d['why'] ?? ''),
        'signup' => (SCHOOLS[$d['school'] ?? '']['name'] ?? '') . ', ' . ($d['status'] ?? '') . ', join code ' . ($d['code'] ?? ''),
        'password' => !empty($d['ok']) ? 'Changed' : 'Refused: ' . ($d['why'] ?? ''),
        'admin' => ucfirst($d['action'] ?? '') . ' ' . (isset(SCHOOLS[$d['target'] ?? '']) ? SCHOOLS[$d['target']]['name'] . ' join code' : $nameOf((string) ($d['target'] ?? '')) . ' (' . ($d['target'] ?? '') . ')'),
        'view' => ($d['url'] ?? $d['page'] ?? ''),
        'form' => $short($d['class'] ?? '') . ', ' . date('j M', strtotime($d['date'] ?? $x['day'])) . ($d['time'] !== '' ? ' ' . $when($d['time']) : '')
            . ': ' . $d['present'] . ($d['strength'] ? '/' . $d['strength'] : '') . ' present'
            . (isset($d['lag']) ? ', submitted ' . $lag((int) $d['lag']) : ''),
        default => '',
    };
};

page_head('Activity', 'page-wide');
?>
<section class="card">
  <form class="tt-bar act-filters" method="get">
    <nav class="tt-seg page-tabs" aria-label="Section">
      <?php foreach ($tabs as $k => $l): ?>
        <a href="<?= $e($link(['tab' => $k === 'log' ? '' : $k])) ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= $l ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($tab !== 'log'): ?><input type="hidden" name="tab" value="<?= $e($tab) ?>"><?php endif; ?>
    <div class="tt-bar-row">
      <div class="tt-pick">
        <label for="act-who">Account</label>
        <select id="act-who" name="who" onchange="this.form.submit()" data-combo>
          <option value="">All accounts</option>
          <?php foreach ($users as $k => $u): ?>
            <option value="<?= $e($k) ?>"<?= $k === $who ? ' selected' : '' ?>><?= $e($u['name'] ?? $k) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php // Each tab shows only the pickers that change it; the others ride along hidden. ?>
      <?php if ($tab === 'log'): ?>
      <div class="tt-pick">
        <label for="act-day">Log for</label>
        <input id="act-day" type="date" name="day" value="<?= $e($day) ?>" max="<?= $today ?>" onchange="this.form.submit()">
      </div>
      <?php elseif ($day !== $today): ?><input type="hidden" name="day" value="<?= $e($day) ?>"><?php endif; ?>
      <?php if ($tab !== 'log' || $who !== ''): ?>
      <div class="tt-pick">
        <label for="act-span"><?= $tab === 'accounts' ? 'Over' : ($tab === 'board' ? 'Leaderboard over' : 'Record over') ?></label>
        <select id="act-span" name="span" onchange="this.form.submit()">
          <?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $n => $l): ?>
            <option value="<?= $n ?>"<?= $n === $span ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($span !== 7): ?><input type="hidden" name="span" value="<?= $span ?>"><?php endif; ?>
      <?php if ($tab === 'board'): ?>
      <div class="tt-pick">
        <label for="act-school">School</label>
        <select id="act-school" name="school" onchange="this.form.submit()">
          <option value="">All schools</option>
          <?php foreach ($boardSchools as $id): ?>
            <option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e(group_name($id)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($school !== ''): ?><input type="hidden" name="school" value="<?= $e($school) ?>"><?php endif; ?>
      <?php if ($tab === 'log'): ?>
      <div class="tt-pick">
        <label for="act-kind">Show</label>
        <select id="act-kind" name="kind" onchange="this.form.submit()">
          <?php foreach ($kinds as $k => $l): ?>
            <option value="<?= $k ?>"<?= $k === $kind ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($kind !== 'work'): ?><input type="hidden" name="kind" value="<?= $e($kind) ?>"><?php endif; ?>
    </div>
    <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
  </form>

  <?php if ($tab === 'log'): ?>
  <div class="stat-grid act-stats">
    <div class="stat"><span class="stat-value"><?= count($daySummary) ?></span><span class="stat-label">Accounts active <?= $day === $today ? 'today' : 'that day' ?></span></div>
    <div class="stat"><span class="stat-value"><?= array_sum(array_column($daySummary, 'marks')) ?> <small>+ <?= count($formDay) ?></small></span><span class="stat-label">Saves in the app + Form entries</span></div>
    <div class="stat"><span class="stat-value"><?= array_sum(array_column($daySummary, 'overwrites')) ?></span><span class="stat-label">Saves that replaced one</span></div>
    <div class="stat"><span class="stat-value"><?= $failedToday ?></span><span class="stat-label">Failed sign-ins</span></div>
  </div>
  <?php // Says where the Form entries come from and how fresh they are, so a "+ 0" can be told apart from a log that was never written. ?>
  <p class="field-help"><?php if (empty($formLog['at'])): ?>No Google Form log yet. It is written by each Form push to api/ingest.php, every 5 minutes; run pushNow() in the Apps Script to send one now.<?php else: ?>Google Form entries from the push at <?= $e(date('j M, H:i', strtotime($formLog['at']))) ?>, <?= count((array) ($formLog['rows'] ?? [])) ?> rows in the sheet. Counted by the day they were submitted.<?php endif; ?></p>
  <?php endif; ?>
</section>

<?php if ($who !== ''):
    $u = $users[$who];
    $s = $summary[$who] ?? null;
    $lb = $boardOf[$who] ?? null;
    $mine = array_filter($events, fn($x) => $x['who'] === $who && $x['day'] >= $from);
    // Saves per day across the window, for the strip of bars.
    $perDay = [];
    for ($d = $from; $d <= $today; $d = date('Y-m-d', strtotime("$d +1 day"))) $perDay[$d] = 0;
    $classes = [];
    $places = [];
    foreach ($mine as $x) {
        if ($x['type'] === 'mark') {
        }
        if ($x['type'] !== 'signin_failed') {
            $p = &$places[$x['device'] . '|' . $x['ip']];
            $p ??= ['device' => $x['device'], 'ip' => $x['ip'], 'first' => $x['day'] . ' ' . $x['t'], 'last' => '', 'n' => 0];
            $p['last'] = $x['day'] . ' ' . $x['t'];
            $p['n']++;
            unset($p);
        }
    }
    foreach ($lb['per_day'] ?? [] as $d => $n) if (isset($perDay[$d])) $perDay[$d] = $n;
    foreach ($lb['by_class'] ?? [] as $c => $n) $classes[$keyLabel($c)] = $n;
    arsort($classes);
    $peak = max([1, ...$perDay]);
?>
<section class="card">
  <h2><?= $e($u['name'] ?? $who) ?> <span class="status status-<?= $e($u['status'] ?? 'pending') ?>"><?= $e($u['status'] ?? 'pending') ?></span><?php if (!empty($u['admin'])): ?> <span class="status status-admin">admin</span><?php endif; ?></h2>
  <p class="field-help act-facts"><?= $e($who) ?> &middot; <?= $e(SCHOOLS[$u['school'] ?? '']['name'] ?? 'No school') ?>
    <?php if (!empty($u['created'])): ?>&middot; account made <?= $e($u['created']) ?><?php endif; ?>
    <?php if (!empty($u['seen'])): ?>&middot; last password sign-in <?= $e($u['seen']) ?><?php endif; ?></p>
  <dl class="tt-stats act-person">
    <div title="From the attendance itself, Form and app, as on the leaderboard"><dt>Lectures marked</dt><dd><?= $lb['lectures'] ?? 0 ?></dd></div>
    <div><dt>Classes</dt><dd><?= $lb['classes'] ?? 0 ?></dd></div>
    <div><dt>Days marking</dt><dd><?= $lb['days'] ?? 0 ?> <small>of <?= $span ?></small></dd></div>
    <div title="Saves filed more than a day after the lecture"><dt>Filed late</dt><dd><?= $s['late'] ?? 0 ?></dd></div>
    <div title="Saves that replaced a reading already there"><dt>Replaced</dt><dd><?= $s['overwrites'] ?? 0 ?></dd></div>
    <div><dt>Pages opened</dt><dd><?= $s['views'] ?? 0 ?></dd></div>
    <div><dt>Failed sign-ins</dt><dd><?= $s['failed'] ?? 0 ?></dd></div>
  </dl>
  <p class="field-help act-note">Lectures, classes and days are from the attendance itself, Form and app. Filed late, replaced, pages and sign-ins are from the activity log, which starts <?= $e(date('j M', strtotime($logFrom ?? $today))) ?>.</p>
  <?php if ($span > 1): ?>
  <h3 class="act-h3">Lectures marked each day, last <?= $span ?> days</h3>
  <?php if (!array_sum($perDay)): ?>
  <p class="field-help">No lectures marked under this name in the last <?= $span ?> days.</p>
  <?php else: ?>
  <?php // One bar a day, today on the right. Tapping one opens that day's log below; it never changes the leaderboard. ?>
  <div class="act-chart">
    <span class="act-peak"><?= $peak ?></span>
    <div class="act-days" role="img" aria-label="Lectures marked each day, <?= $e($from) ?> to <?= $e($today) ?>, most in a day <?= $peak ?>">
      <?php foreach ($perDay as $d => $n): ?>
        <a href="<?= $e($link(['day' => $d, 'tab' => ''])) ?>#act-log" class="act-day<?= $d === $day ? ' is-selected' : '' ?><?= $n ? '' : ' is-zero' ?>" title="<?= $e(date('D j M', strtotime($d)) . ": $n lecture" . ($n === 1 ? '' : 's') . '. Tap for that day\'s log.') ?>"><i style="height:<?= round(100 * $n / $peak) ?>%"></i></a>
      <?php endforeach; ?>
    </div>
    <div class="act-axis"><span><?= $e(date('j M', strtotime($from))) ?></span><span><?= $e(date('j M', strtotime($from . ' +' . intdiv($span, 2) . ' days'))) ?></span><span>Today</span></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <div class="act-cols">
    <div>
      <h3 class="act-h3">Classes marked</h3>
      <?php if (!$classes): ?><p class="field-help">None in this window.</p><?php else: ?>
      <table class="stat-table stat-table-groups"><tbody>
        <?php foreach ($classes as $c => $n): ?><tr><th scope="row"><?= $e($c) ?></th><td><?= $n ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
    <div>
      <h3 class="act-h3">Devices and addresses</h3>
      <?php if (!$places): ?><p class="field-help">None in this window.</p><?php else: ?>
      <table class="stat-table stat-table-groups">
        <thead><tr><th scope="col">Device</th><th scope="col">Address</th><th scope="col">First</th><th scope="col">Last</th></tr></thead>
        <tbody>
        <?php foreach ($places as $p): ?><tr><th scope="row"><?= $e($p['device']) ?></th><td class="act-mono"><?= $e($p['ip']) ?></td><td><?= $e($ago($p['first'])) ?></td><td><?= $e($ago($p['last'])) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($tab === 'log'): ?>
<section class="card" id="act-log">
  <h2><?= $day === $today ? 'Log for today' : 'Log for ' . $e(date('l j M', strtotime($day))) ?><?= $who !== '' ? ', ' . $e($nameOf($who)) : '' ?> <span class="count-pill"><?= count($dayEvents) ?></span></h2>
  <?php if (!$dayEvents): ?>
    <p class="field-help">Nothing logged<?= $kind !== 'all' ? ' of this kind' : '' ?> on this day.</p>
  <?php else: ?>
  <div class="stat-table-wrap">
    <table class="stat-table act-log">
      <thead><tr><th scope="col">Time</th><th scope="col">Account</th><th scope="col">What</th><th scope="col">Details</th><th scope="col">Device</th><th scope="col">Address</th></tr></thead>
      <tbody>
      <?php foreach ($dayEvents as $x): ?>
        <tr class="act-<?= $e($x['type']) ?>">
          <th scope="row" class="act-mono"><?= $e($x['t']) ?></th>
          <td><?php if (isset($users[$x['who']])): ?><a href="<?= $e($link(['who' => $x['who']])) ?>"><?= $e($nameOf($x['who'])) ?></a><?php else: ?><?php if ($x['type'] === 'form' && ($x['name'] ?? '') !== ''): ?><?= $e($x['name']) ?> <span class="stat-sub">no account</span><?php else: ?><span class="stat-sub"><?= $e($x['who'] ?: 'nobody') ?></span><?php endif; ?><?php endif; ?></td>
          <td><span class="act-type"><?= $e(ACTIVITY_TYPES[$x['type']] ?? $x['type']) ?></span></td>
          <td class="act-detail"><?= $e($describe($x)) ?></td>
          <td title="<?= $e($x['ua'] ?? '') ?>"><?= $e($x['device'] ?? '') ?></td>
          <td class="act-mono" title="<?= $e(isset($x['fwd']) ? 'Forwarded for ' . $x['fwd'] : '') ?>"><?= $e($x['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'board'): ?>
<section class="card">
  <h2>Most active faculty<?= $school === '' ? '' : ', ' . $e(preg_replace('/^School of /', '', group_name($school))) ?>, <?= $span === 1 ? 'today' : 'last ' . $span . ' days' ?> <span class="count-pill"><?= count($shown) ?></span></h2>
  <?php if (!$shown): ?>
    <p class="field-help">Nobody's name is on any attendance<?= $school === '' ? '' : ' for ' . $e(group_name($school)) ?> in this window.</p>
  <?php else: ?>
  <div class="stat-table-wrap">
    <?php $pickedAt = $who === '' ? false : array_search(activity_person((string) ($users[$who]['name'] ?? '')), array_column($shown, 'key'), true);
          $folded = count($shown) > ACTIVITY_BOARD_TOP && ($pickedAt === false || $pickedAt < ACTIVITY_BOARD_TOP); ?>
    <table class="stat-table act-board<?= $folded ? ' is-folded' : '' ?>" id="act-board">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Faculty</th>
          <th scope="col" title="One class, one day, one lecture">Lectures marked</th>
          <th scope="col">Classes</th>
          <th scope="col">Days</th>
          <th scope="col">Form</th>
          <th scope="col">App</th>
          <th scope="col">Last</th>
        </tr>
      </thead>
      <tbody>
      <?php $rank = 0; $prev = null; foreach ($shown as $i => $r):
          // Equal counts share a rank: 1, 2, 2, 4.
          if ($prev !== $r['lectures']) $rank = $i + 1;
          $prev = $r['lectures'];
          $acct = $accountOf[$r['key']] ?? null; ?>
        <tr class="<?= $acct !== null && $acct === $who ? 'is-selected' : '' ?><?= $i >= ACTIVITY_BOARD_TOP ? ' act-more' : '' ?>">
          <td class="act-rank"><?= $rank ?></td>
          <th scope="row"><?php if ($acct !== null): ?><a href="<?= $e($link(['who' => $acct])) ?>"><?= $e($r['name']) ?></a><?php else: ?><?= $e($r['name']) ?><?php endif; ?>
            <span class="stat-sub"><?= $e(preg_replace('/^School of /', '', group_name($r['school']))) ?><?= $acct === null ? ' &middot; no account' : '' ?></span></th>
          <td><span class="act-bar-wrap"><span class="act-bar" aria-hidden="true"><i style="width:<?= round(100 * $r['lectures'] / $topLectures) ?>%"></i></span><b><?= $r['lectures'] ?></b></span></td>
          <td><?= $r['classes'] ?></td>
          <td><?= $r['days'] ?></td>
          <td><?= $r['form'] ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= $r['app'] ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= $e($r['last'] === $today ? 'today' : date('j M', strtotime($r['last']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if (count($shown) > ACTIVITY_BOARD_TOP): ?>
  <button class="btn-quiet act-board-toggle no-print" type="button" aria-controls="act-board" aria-expanded="<?= $folded ? 'false' : 'true' ?>"
          data-more="Show all <?= count($shown) ?>" data-less="Show top <?= ACTIVITY_BOARD_TOP ?>"
          onclick="var t = document.getElementById('act-board'), f = t.classList.toggle('is-folded'); this.setAttribute('aria-expanded', f ? 'false' : 'true'); this.textContent = f ? this.dataset.more : this.dataset.less;"><?= $folded ? 'Show all ' . count($shown) : 'Show top ' . ACTIVITY_BOARD_TOP ?></button>
  <noscript><style>.act-board.is-folded tr.act-more { display: table-row; } .act-board-toggle { display: none; }</style></noscript>
  <?php endif; ?>
  <p class="field-help">Counts every lecture with a name on it, from the Google Form's Faculty name and from saves in the app. A lecture filed both ways counts once, for the app. Names are matched ignoring titles and spacing, so "Dr. A Rao" and "a rao" are one person; a misspelt name counts as someone else.<?= $unnamed ? ' ' . $unnamed . ' lecture' . ($unnamed === 1 ? ' has' : 's have') . ' no name on it and count for nobody.' : '' ?></p>
</section>
<?php endif; ?>

<?php if ($tab === 'accounts'): ?>
<section class="card">
  <h2>Accounts, <?= $span === 1 ? 'today' : 'last ' . $span . ' days' ?></h2>
  <div class="stat-table-wrap">
    <table class="stat-table act-accounts">
      <thead>
        <tr>
          <th scope="col">Account</th>
          <th scope="col">Last seen</th>
          <th scope="col" title="Days they marked attendance">Days</th>
          <th scope="col" title="From the attendance itself, Form and app, as on the leaderboard">Lectures</th>
          <th scope="col">Classes</th>
          <th scope="col" title="Saves filed more than a day after the lecture">Late</th>
          <th scope="col" title="Saves that replaced a reading already there">Replaced</th>
          <th scope="col">Pages</th>
          <th scope="col">Failed sign-ins</th>
          <th scope="col">Devices</th>
          <th scope="col">Addresses</th>
        </tr>
      </thead>
      <tbody>
      <?php
        // Most recently active first; accounts with nothing logged at the bottom.
        $order = array_keys($users);
        // Last seen is the later of the log and the last lecture they marked.
        $lastOf = fn(string $k): string => max($summary[$k]['last'] ?? '', isset($boardOf[$k]) ? $boardOf[$k]['last'] . ' 00:00:00' : '');
        usort($order, fn($a, $b) => $lastOf($b) <=> $lastOf($a));
        foreach ($order as $k): $u = $users[$k]; $s = $summary[$k] ?? null; $lb = $boardOf[$k] ?? null; ?>
        <tr<?= $k === $who ? ' class="is-selected"' : '' ?>>
          <th scope="row"><a href="<?= $e($link(['who' => $k])) ?>"><?= $e($u['name'] ?? $k) ?></a>
            <span class="stat-sub"><?= $e(preg_replace('/^School of /', '', SCHOOLS[$u['school'] ?? '']['name'] ?? '')) ?><?= ($u['status'] ?? '') !== 'active' ? ' &middot; ' . $e($u['status'] ?? 'pending') : '' ?></span></th>
          <td><?= $s ? $e($ago($s['last'])) : ($lb ? $e(date('j M', strtotime($lb['last']))) : '<span class="stat-nil">never</span>') ?></td>
          <td><?= ($lb['days'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($lb['lectures'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($lb['classes'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($s['late'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($s['overwrites'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($s['views'] ?? 0) ?: '<span class="stat-nil">0</span>' ?></td>
          <td><?= ($s['failed'] ?? 0) ? '<b class="act-warn">' . $s['failed'] . '</b>' : '<span class="stat-nil">0</span>' ?></td>
          <td title="<?= $e(implode(', ', array_keys($s['devices'] ?? []))) ?>"><?= count($s['devices'] ?? []) ?: '<span class="stat-nil">0</span>' ?></td>
          <td title="<?= $e(implode(', ', array_keys($s['ips'] ?? []))) ?>"><?= count($s['ips'] ?? []) ?: '<span class="stat-nil">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($quiet): ?>
  <p class="field-help"><b><?= count($quiet) ?> active faculty account<?= count($quiet) === 1 ? ' has' : 's have' ?> not saved any attendance <?= $span === 1 ? 'today' : "in the last $span days" ?>:</b>
    <?= $e(implode(', ', array_map(fn($u) => $u['name'] ?? '', $quiet))) ?>.</p>
  <?php endif; ?>
  <p class="field-help">Lectures, classes and days come from the attendance itself, so they go back as far as it does. Late, replaced, pages, sign-ins, devices and addresses come from the activity log, recorded from <?= $e(date('j M Y', strtotime($logFrom ?? $today))) ?> and kept for <?= ACTIVITY_KEEP_DAYS ?> days.</p>
</section>
<?php endif; ?>
<script src="js/combo.js?v=<?= filemtime(__DIR__ . '/js/combo.js') ?>"></script>
<?php page_foot(); ?>
