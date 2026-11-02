<?php
// The Events tab: every holiday and event as a month, for any school and year.
// Open to everyone, like the dashboard; only an admin sees Add a day, and a
// post from anyone else stops at auth_require().

require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('events');
$admin = auth_is_admin();

$years = calendar_years();
$pick = function (array $in) use ($years): array {
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($in['month'] ?? '')) ? $in['month'] : date('Y-m');
    $school = isset(SCHOOLS[$in['school'] ?? '']) ? $in['school'] : '';
    $year = in_array($in['year'] ?? '', $years, true) ? $in['year'] : '';
    return [$month, $school, $year];
};

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $me = auth_require(true);
    [$month, $school, $year] = $pick($_POST);
    $action = (string) ($_POST['action'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $done = '';
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif ($action === 'delete') {
        foreach (calendar_all() as $e) {
            if ($e['id'] !== $id) continue;
            calendar_delete($id);
            activity_log('admin', $me['email'], ['action' => 'calendar', 'target' => $id, 'label' => "Deleted {$e['title']} from the calendar"]);
            $done = 'deleted';
        }
    } else {
        [$entry, $error] = calendar_clean($_POST);
        if ($entry !== null) {
            $saved = calendar_save($entry, $id);
            if ($saved === '') {
                $error = 'That entry has been deleted.';
            } else {
                activity_log('admin', $me['email'], ['action' => 'calendar', 'target' => $saved,
                    'label' => ($id === '' ? 'Added ' : 'Changed ') . strtolower(CALENDAR_TYPES[$entry['type']]) . " {$entry['title']} on the calendar"]);
                $done = 'saved';
                $month = substr($entry['from'], 0, 7);
            }
        }
    }
    // Back to a GET, so a refresh never saves twice.
    if ($error === '') {
        header('Location: events.php?' . http_build_query(array_filter(compact('month', 'school', 'year', 'done'))));
        exit;
    }
} else {
    [$month, $school, $year] = $pick($_GET);
}

$e = fn($v): string => htmlspecialchars((string) $v);
$first = new DateTimeImmutable("$month-01");
$last = $first->modify('last day of this month');
$today = date('Y-m-d');
$link = fn(array $over): string => 'events.php?' . http_build_query(array_filter(array_merge(compact('month', 'school', 'year'), $over)));

$shown = array_values(array_filter(calendar_all(), fn($x) => calendar_applies($x, $school, $year)));
$inMonth = calendar_between($shown, $first->format('Y-m-d'), $last->format('Y-m-d'));

page_head('Events', 'page-wide page-events', 'events');
?>
<?php page_notice('warn', $error); ?>
<?php if (($_GET['done'] ?? '') === 'saved') page_notice('ok', 'Saved.'); ?>
<?php if (($_GET['done'] ?? '') === 'deleted') page_notice('ok', 'Deleted.'); ?>

<div class="cal-head">
  <h2><?= $e($first->format('F Y')) ?></h2>
  <nav class="cal-nav" aria-label="Month">
    <a class="tt-step" href="<?= $e($link(['month' => $first->modify('-1 month')->format('Y-m')])) ?>" aria-label="Previous month" title="Previous month">&#8249;</a>
    <a class="tt-step" href="<?= $e($link(['month' => $first->modify('+1 month')->format('Y-m')])) ?>" aria-label="Next month" title="Next month">&#8250;</a>
    <a class="tt-today" href="<?= $e($link(['month' => null])) ?>">Today</a>
  </nav>
  <form class="cal-filters" method="get">
    <input type="hidden" name="month" value="<?= $e($month) ?>">
    <label><span class="sr-only">School</span>
      <select name="school" onchange="this.form.submit()">
        <option value="">All schools</option>
        <?php foreach (SCHOOLS as $id => $s): ?>
        <option value="<?= $e($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= $e($s['name']) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label><span class="sr-only">Year</span>
      <select name="year" onchange="this.form.submit()">
        <option value="">All years</option>
        <?php foreach ($years as $y): ?>
        <option value="<?= $e($y) ?>"<?= $y === $year ? ' selected' : '' ?>><?= $e($y) ?></option>
        <?php endforeach; ?>
      </select></label>
    <noscript><button class="btn-quiet" type="submit">Show</button></noscript>
  </form>
  <?php if ($admin): ?><button class="rep-export cal-add" id="cal-new" type="button">Add a day</button><?php endif; ?>
</div>

<div class="cal-layout">
  <div class="cal-grid">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
    <?php for ($n = (int) $first->format('N') - 1; $n > 0; $n--): ?><div class="cal-day is-out" aria-hidden="true"></div><?php endfor; ?>
    <?php for ($day = $first; $day <= $last; $day = $day->modify('+1 day')):
      $date = $day->format('Y-m-d');
      $here = calendar_between($shown, $date, $date);
      $off = (bool) array_filter($here, fn($x) => $x['type'] === 'holiday' && calendar_covers($x, $school, $year));
      $class = 'cal-day' . ($day->format('N') === '7' ? ' is-sun' : '') . ($date === $today ? ' is-today' : '') . ($off ? ' is-holiday' : '');
      $inner = '<span class="cal-num">' . $day->format('j') . '</span>' . implode('', array_map('calendar_tag', $here))
             . '<span class="cal-dots">' . implode('', array_map(fn($x) => '<i class="is-' . $e($x['type']) . '"></i>', $here)) . '</span>';
      $label = $day->format('D j M') . ($here ? ': ' . implode(', ', array_column($here, 'title')) : '');
    ?>
    <?php if ($here || $admin): ?>
    <button type="button" class="<?= $class ?>" data-date="<?= $date ?>" aria-label="<?= $e($label) ?>"><?= $inner ?></button>
    <?php else: ?>
    <div class="<?= $class ?>"><?= $inner ?></div>
    <?php endif; ?>
    <?php endfor; ?>
    <?php for ($n = 7 - (int) $last->format('N'); $n > 0; $n--): ?><div class="cal-day is-out" aria-hidden="true"></div><?php endfor; ?>
  </div>

  <aside class="card cal-side">
    <h3>This month</h3>
    <?php foreach ($inMonth as $x): ?>
    <div class="cal-item">
      <?= calendar_tag(['title' => CALENDAR_TYPES[$x['type']]] + $x) ?>
      <strong><?= $e($x['title']) ?></strong>
      <small><?= $e(calendar_when($x)) ?> &middot; <?= $e(calendar_scope($x)) ?></small>
      <?php if ($admin): ?><button class="rep-link" type="button" data-edit="<?= $e($x['id']) ?>">Edit</button><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$inMonth): ?><p class="cal-none">Nothing marked this month.</p><?php endif; ?>
  </aside>
</div>

<div class="modal-backdrop cal-backdrop" id="cal-day" hidden>
  <div class="modal cal-modal" role="dialog" aria-modal="true" aria-labelledby="cal-day-title">
    <div class="modal-header">
      <div class="modal-heading"><h2 id="cal-day-title"></h2></div>
      <div class="modal-actions"><button class="icon-btn" type="button" data-close aria-label="Close"><svg aria-hidden="true"><use href="#icon-close"/></svg></button></div>
    </div>
    <div id="cal-day-list"></div>
    <?php if ($admin): ?><button class="btn-quiet" id="cal-day-add" type="button">Add to this day</button><?php endif; ?>
  </div>
</div>

<?php if ($admin): ?>
<div class="modal-backdrop cal-backdrop" id="cal-edit" hidden>
  <form class="modal cal-modal" id="cal-form" method="post" role="dialog" aria-modal="true" aria-labelledby="cal-form-title">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="cal-id">
    <input type="hidden" name="month" value="<?= $e($month) ?>">
    <input type="hidden" name="school" value="<?= $e($school) ?>">
    <input type="hidden" name="year" value="<?= $e($year) ?>">
    <div class="modal-header">
      <div class="modal-heading"><h2 id="cal-form-title">Add a day</h2></div>
      <div class="modal-actions"><button class="icon-btn" type="button" data-close aria-label="Close"><svg aria-hidden="true"><use href="#icon-close"/></svg></button></div>
    </div>
    <div class="cal-field">
      <span class="rep-label">Type</span>
      <div class="tt-seg rep-seg" role="radiogroup" aria-label="Type">
        <?php foreach (CALENDAR_TYPES as $v => $l): ?>
        <label><input type="radio" name="type" value="<?= $v ?>"<?= $v === 'holiday' ? ' checked' : '' ?>><span><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="cal-hint" id="cal-type-hint"></p>
    </div>
    <div class="cal-field">
      <label class="rep-label" for="cal-title">Name</label>
      <input type="text" id="cal-title" name="title" maxlength="60" required>
    </div>
    <div class="cal-dates cal-field">
      <div><label class="rep-label" for="cal-from">From</label><input type="date" id="cal-from" name="from" required></div>
      <div><label class="rep-label" for="cal-to">To</label><input type="date" id="cal-to" name="to" required></div>
    </div>
    <div class="cal-field">
      <span class="rep-label">Schools</span>
      <div class="tt-seg rep-seg" role="radiogroup" aria-label="Schools">
        <label><input type="radio" name="for" value="all" checked><span>All schools</span></label>
        <label><input type="radio" name="for" value="some"><span>Pick schools</span></label>
      </div>
      <div class="rep-chips cal-chips" id="cal-schools" hidden>
        <?php foreach (SCHOOLS as $id => $s): ?>
        <label class="rep-chip"><input type="checkbox" name="schools[]" value="<?= $e($id) ?>"><span><?= $e(preg_replace('/^School of /', '', $s['name'])) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="cal-field">
      <span class="rep-label">Years</span>
      <div class="tt-seg rep-seg" role="radiogroup" aria-label="Years">
        <label><input type="radio" name="for_years" value="all" checked><span>All years</span></label>
        <label><input type="radio" name="for_years" value="some"><span>Pick years</span></label>
      </div>
      <div class="rep-chips cal-chips" id="cal-years" hidden>
        <?php foreach ($years as $y): ?>
        <label class="rep-chip"><input type="checkbox" name="years[]" value="<?= $e($y) ?>"><span><?= $e($y) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="notice notice-warn cal-form-error" id="cal-error" hidden></p>
    <div class="cal-actions">
      <button class="btn-quiet" id="cal-delete" type="submit" name="action" value="delete" formnovalidate hidden>Delete</button>
      <button class="btn-primary" type="submit" name="action" value="save">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
  window.EVENTS = <?= json_encode([
      'today' => $today, 'admin' => $admin,
      'entries' => array_map(fn($x) => $x + ['when' => calendar_when($x), 'scope' => calendar_scope($x)], $inMonth),
  ]) ?>;
</script>
<script src="js/events.js?v=<?= filemtime(__DIR__ . '/js/events.js') ?>"></script>
<?php page_foot(); ?>
