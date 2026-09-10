<?php
// Marking attendance from a phone. The whole point of the login.
//
// Two modes, one screen. With a roster for the class, a tick box per student
// and a ticked box means ABSENT — the default state is everyone present, which
// is the common case and the one that should need no taps. Without a roster,
// the same screen shows a single present count, exactly what the Google Form
// asks for today, so all 136 classes work from the day this ships and each one
// upgrades itself the moment its school returns a list.
//
// Selection is a GET form so the URL carries the class, date and lecture: a
// teacher marking the same class daily can bookmark it. Saving is a POST, then
// a redirect, so a refresh never files attendance twice.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/roster.php';
require_once __DIR__ . '/includes/page.php';

header('Cache-Control: no-store');
auth_boot();
$me = auth_require();

const LAST_CLASS_COOKIE = 'adypu_last_class';

// Above this many students, scrolling to find one costs more than the filter
// box costs in screen space. Engineering divisions run to 60.
const ROSTER_FILTER_AT = 15;

// --- Saving -----------------------------------------------------------------

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class = parse_class_label((string) ($_POST['class'] ?? ''));
    $date  = row_date((string) ($_POST['date'] ?? ''));
    $time  = row_time((string) ($_POST['time'] ?? ''));

    if (($me['status'] ?? '') !== 'active') {
        $error = 'Your account is still waiting for approval.';
    } elseif (!csrf_ok()) {
        $error = 'That form expired. Please check the list and submit again.';
    } elseif ($class === null) {
        $error = 'That class is not one we know. Please pick it from the list again.';
    } elseif ($date === null || $date > date('Y-m-d')) {
        // A future date parks a reading past every real one, and the dashboard
        // opens on the newest day it holds, so it would sit there forever.
        $error = 'Please choose the date the class actually met.';
    } else {
        $roster = roster_for($class);
        if ($roster) {
            // Present is counted here, never sent by the phone: only roll
            // numbers that are actually on this class's list may mark anyone
            // absent, so a stale or edited form cannot invent absentees.
            $rolls = array_column($roster, 'roll');
            $absent = array_values(array_intersect($rolls, (array) ($_POST['absent'] ?? [])));
            $present = count($rolls) - count($absent);
        } else {
            $absent = [];
            $present = (int) ($_POST['present'] ?? 0);
        }

        if (record_attendance($class, $date, $time ?: date('H:00'), $present, (string) $me['name'], $absent)) {
            setcookie(LAST_CLASS_COOKIE, (string) $_POST['class'], [
                'expires' => time() + 180 * 86400, 'path' => '/', 'samesite' => 'Lax',
            ]);
            header('Location: mark.php?' . http_build_query([
                'class' => $_POST['class'], 'date' => $date, 'time' => $time,
                'saved' => $present,
            ]));
            exit;
        }
        $error = 'Could not save that. Please try again, and tell us if it keeps happening.';
    }
}

// --- What we are looking at -------------------------------------------------

// No class in the URL means the last one this phone marked: a teacher taking
// the same division every morning should arrive with nothing to choose.
$label = (string) ($_GET['class'] ?? $_COOKIE[LAST_CLASS_COOKIE] ?? '');
$class = $label === '' ? null : parse_class_label($label);
if ($class === null) $label = '';

$school = $class['school'] ?? (string) ($_GET['school'] ?? $me['school'] ?? '');
if (!isset(SCHOOLS[$school])) $school = array_key_first(SCHOOLS);

$date = row_date((string) ($_GET['date'] ?? '')) ?? date('Y-m-d');
// Defaulting to the hour, not the minute, so a correction filed a few minutes
// later lands on the same lecture and replaces it rather than inventing a
// second one — the same rule the Form's own fallback follows.
$time = row_time((string) ($_GET['time'] ?? '')) ?: date('H:00');

$roster = $class ? roster_for($class) : [];
// The sticky bar repeats the selection, because the picker card scrolls away
// above a long roster and filing the 9am roll into the 2pm slot is the
// expensive mistake on this screen. The school is dropped: a faculty member
// knows which school they are in, and the bar has one line.
$shortLabel = $label === '' ? '' : preg_replace('#^School of [^/]+ / #', '', $label);
$saved = isset($_GET['saved']) ? (int) $_GET['saved'] : null;

// Every class in the chosen school, for the second dropdown.
$classesHere = [];
foreach (class_rows() as $c) {
    if ($c['school'] === $school) $classesHere[] = class_label($c['school'], $c['year'], $c['branch'], $c['division']);
}

page_head('Mark attendance', 'page-narrow page-marking');

if (($me['status'] ?? '') !== 'active') {
    ?>
    <div class="card">
      <h2>Waiting for approval</h2>
      <p>Thanks <?= htmlspecialchars($me['name']) ?>. Your account exists, but someone has to confirm
         you before you can file attendance. That is usually the same day.</p>
      <p class="field-help">If your school gave you a join code, sign out and create your account again with it
         and you will not have to wait. <a href="index.php">The dashboard is open to you in the meantime.</a></p>
    </div>
    <?php
    page_foot();
    exit;
}
?>

<?php page_notice('warn', $error); ?>
<?php if ($saved !== null): ?>
  <?php page_notice('ok', "Saved. $saved present in " . ($class ? class_label($class['school'], $class['year'], $class['branch'], $class['division']) : 'that class') . " at $time."); ?>
<?php endif; ?>

<form class="card picker" method="get" id="picker">
  <label for="p-school">School</label>
  <select id="p-school" name="school">
    <?php foreach (SCHOOLS as $id => $s): ?>
      <option value="<?= htmlspecialchars($id) ?>"<?= $id === $school ? ' selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="p-class">Class</label>
  <select id="p-class" name="class">
    <option value="">Choose a class</option>
    <?php foreach ($classesHere as $option): ?>
      <option value="<?= htmlspecialchars($option) ?>"<?= $option === $label ? ' selected' : '' ?>>
        <?= htmlspecialchars(preg_replace('#^School of [^/]+ / #', '', $option)) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <div class="picker-when">
    <span><label for="p-date">Date</label>
      <input id="p-date" name="date" type="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>"></span>
    <span><label for="p-time">Lecture</label>
      <input id="p-time" name="time" type="time" value="<?= htmlspecialchars($time) ?>"></span>
  </div>

  <button class="btn-quiet" type="submit" id="picker-go">Load class list</button>
</form>

<?php if ($class === null): ?>
  <p class="field-help">Pick a class to see its list.</p>
<?php else: ?>
<form class="marking" method="post" id="marking">
  <?= csrf_field() ?>
  <input type="hidden" name="class" value="<?= htmlspecialchars($label) ?>">
  <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
  <input type="hidden" name="time" value="<?= htmlspecialchars($time) ?>">

  <?php if (!$roster): ?>
    <div class="card">
      <h2>No student list yet</h2>
      <p class="field-help">
        This class has <?= (int) $class['strength'] ?> students enrolled. Its school has not sent us the names
        yet, so enter the number present, the same as the Google Form asks for.
        The tick list appears here automatically once the list arrives.
      </p>
      <label for="p-present">Students present</label>
      <input id="p-present" name="present" type="number" inputmode="numeric" min="0"
             max="<?= (int) $class['strength'] ?>" value="<?= (int) $class['strength'] ?>" required>
    </div>
  <?php else: ?>
    <?php /* Ticking for ABSENT is the opposite of what most people expect, and
             getting it backwards inverts the whole submission. It was 11px of
             muted help text; it is the loudest thing above the list now. */ ?>
    <p class="mark-lead">
      <svg aria-hidden="true"><use href="#icon-warn"/></svg>
      <span>Everyone starts <strong>present</strong>. Tick only the students who are <strong>absent</strong>.</span>
    </p>

    <div class="mark-tools">
      <button class="btn-quiet" type="button" data-all="present">All present</button>
      <?php /* Not styled like its neighbour on purpose: one is the normal
               opening move, the other throws away a finished roll. */ ?>
      <?php /* data-confirm is the label the button wears once armed, not a
               dialog message: the first tap arms it, the second commits. */ ?>
      <button class="btn-danger" type="button" data-all="absent"
              data-confirm="Tap again to confirm">All absent</button>
    </div>

    <?php if (count($roster) >= ROSTER_FILTER_AT): ?>
      <div class="roster-filter">
        <svg aria-hidden="true"><use href="#icon-search"/></svg>
        <label class="sr-only" for="roster-filter">Find a student</label>
        <input id="roster-filter" type="search" autocomplete="off" spellcheck="false"
               placeholder="Find a name or roll number">
        <span class="roster-filter-count" id="roster-filter-count" role="status"></span>
      </div>
    <?php endif; ?>

    <ol class="roster" id="roster">
      <?php foreach ($roster as $s): ?>
        <li>
          <label class="student">
            <input type="checkbox" name="absent[]" value="<?= htmlspecialchars($s['roll'] ?? '') ?>">
            <span class="student-name"><?= htmlspecialchars($s['name'] ?? '') ?></span>
            <span class="student-roll"><?= htmlspecialchars($s['roll'] ?? '') ?></span>
          </label>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="roster-empty" id="roster-empty" hidden>No student matches that.</p>
  <?php endif; ?>

  <?php /* Last in the form on purpose. `position: sticky; bottom: 0` holds an
           element up at the viewport bottom as content scrolls past it; it does
           not drag one down from the top. Placed above the list, the bar simply
           scrolled away with everything else. */ ?>
  <div class="mark-bar" id="mark-bar">
    <p class="mark-context">
      <span class="mark-context-class"><?= htmlspecialchars($shortLabel) ?></span>
      <span class="mark-context-when"><?= htmlspecialchars(date('j M', strtotime($date)) . ' at ' . date('g:i a', strtotime($time))) ?></span>
    </p>
    <div class="mark-bar-row">
      <?php /* No count without a roster: there is nothing to count, and the
               number the teacher typed is already on screen in its own box.
               This used to render an empty slot that js/mark.js then filled
               with a meaningless 0. */ ?>
      <?php if ($roster): ?>
        <div class="mark-count">
          <strong><span id="mark-present"><?= count($roster) ?></span><span class="mark-count-total"> / <?= count($roster) ?></span></strong>
          <span>present<span id="mark-absent"></span></span>
        </div>
      <?php endif; ?>
      <button class="btn-primary" type="submit">Submit</button>
    </div>
  </div>
</form>
<?php endif; ?>

<script src="js/mark.js?v=<?= filemtime(__DIR__ . '/js/mark.js') ?>"></script>
<?php page_foot(); ?>
