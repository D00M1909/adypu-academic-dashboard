<?php
// Who may write attendance. Approving the queue, disabling an account, and
// rotating the join codes that let a school onboard itself without any of this.
//
// There is no email on this host, so the reset button showing a temporary
// password once, on screen, is the entire password recovery story.

require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';
require_once __DIR__ . '/includes/traffic.php';

header('Cache-Control: no-store');
auth_boot();
traffic_hit('admin');
$me = auth_require(true);

$error = '';
$ok = '';
$temp = '';

$tt = timetable_read();
$faculty = $tt ? timetable_faculty($tt) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = auth_key((string) ($_POST['email'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
    // Every admin action is logged under the admin who took it, once it has
    // run ($ok set), so a refused one is never recorded as done.
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif ($action === 'rotate') {
        $school = (string) ($_POST['school'] ?? '');
        if (isset(SCHOOLS[$school])) {
            $code = auth_new_code();
            store_update('faculty', function (array $d) use ($school, $code) {
                $d['codes'][$school] = $code;
                return $d;
            });
            $ok = SCHOOLS[$school]['name'] . ' now has the code ' . $code . '. The old one stops working immediately.';
            activity_log('admin', $me['email'], ['action' => 'rotate', 'target' => $school]);
        }
    } elseif ($email !== '' && auth_find($email)) {
        // An admin locking themselves out of the only admin account is a trip
        // to the FTP client to hand-edit a file. Refuse it instead.
        $isSelf = $email === auth_key($me['email']);
        switch ($action) {
            case 'approve':
                auth_put($email, ['status' => 'active']);
                $ok = 'Approved. They can mark attendance now.';
                break;
            case 'disable':
                if ($isSelf) { $error = 'You cannot disable your own account.'; break; }
                auth_put($email, ['status' => 'disabled', 'remember' => '']);
                $ok = 'Disabled. They are signed out everywhere.';
                break;
            case 'enable':
                auth_put($email, ['status' => 'active']);
                $ok = 'Re-enabled.';
                break;
            case 'promote':
                auth_put($email, ['admin' => true]);
                $ok = 'They can now approve accounts too.';
                break;
            case 'demote':
                if ($isSelf) { $error = 'You cannot remove your own admin access.'; break; }
                auth_put($email, ['admin' => false]);
                $ok = 'Admin access removed.';
                break;
            case 'delete':
                // Disabled first, so one misclick cannot remove a working account.
                if ((auth_find($email)['status'] ?? '') !== 'disabled') break;
                store_update('faculty', function (array $d) use ($email) {
                    unset($d['users'][$email]);
                    return $d;
                });
                $ok = 'Deleted.';
                break;
            case 'audit':
                // The audit's level and which timetable name the account is.
                // A blank name goes back to matching the account's own name.
                $role = (string) ($_POST['role'] ?? '');
                $tt = (string) ($_POST['tt'] ?? '');
                $tt = isset($faculty[$tt]) ? $tt : '';
                auth_put($email, ['role' => isset(AUDIT_ROLES[$role]) ? $role : 'faculty', 'tt' => $tt]);
                $ok = 'Saved: ' . AUDIT_ROLES[isset(AUDIT_ROLES[$role]) ? $role : 'faculty'] . ($tt !== '' ? ', on the timetable as ' . $faculty[$tt]['name'] : '') . '.';
                break;
            case 'reset':
                $temp = auth_reset_password($email);
                $ok = 'Read this to them now. It is not shown again.';
                break;
        }
        if ($ok !== '') activity_log('admin', $me['email'], ['action' => $action, 'target' => $email]);
    }
}

$data = auth_data();
$users = $data['users'];
uasort($users, fn($a, $b) => [$a['status'] ?? '', $b['created'] ?? ''] <=> [$b['status'] ?? '', $a['created'] ?? '']);
$pending = array_filter($users, fn($u) => ($u['status'] ?? '') === 'pending');

page_head('Faculty accounts', 'page-narrow');
?>
<?php page_notice('warn', $error); page_notice('ok', $ok); ?>
<?php /* Spoken confirmation for the copy buttons, which otherwise only
         change colour for a second and a half. */ ?>
<p class="sr-only" id="copy-status" role="status"></p>
<?php if ($temp !== ''): ?>
  <div class="temp-password">
    <p class="temp-password-label">Temporary password &mdash; read it out now. It is not shown again.</p>
    <div class="temp-password-value">
      <code><?= htmlspecialchars($temp) ?></code>
      <button class="copy-btn" type="button" data-copy="<?= htmlspecialchars($temp) ?>"
              aria-label="Copy the temporary password"><svg aria-hidden="true"><use href="#icon-copy"/></svg></button>
    </div>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Waiting for approval <span class="count-pill"><?= count($pending) ?></span></h2>
  <?php if (!$pending): ?>
    <p class="field-help">Nobody is waiting. Anyone who signs up without a join code appears here.</p>
  <?php else: ?>
    <ul class="account-list">
      <?php foreach ($pending as $email => $u): ?>
        <li class="account">
          <div class="account-who">
            <strong><?= htmlspecialchars($u['name'] ?? $email) ?></strong>
            <span><?= htmlspecialchars($email) ?></span>
            <span><?= htmlspecialchars(SCHOOLS[$u['school'] ?? '']['name'] ?? 'No school') ?> &middot; asked <?= htmlspecialchars($u['created'] ?? '') ?></span>
          </div>
          <div class="account-actions">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
              <button class="btn-primary" name="action" value="approve" type="submit">Approve</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
              <button class="btn-quiet" name="action" value="disable" type="submit">Reject</button></form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php
// "School of " on every one of nine rows, on a phone, costs the width that was
// making the code wrap and the name ellipsize. The heading says school already.
$shortSchool = fn(string $id): string => preg_replace('/^School of /', '', SCHOOLS[$id]['name'] ?? $id);
?>
<section class="card">
  <h2>School join codes</h2>
  <p class="field-help">
    Give a school its code and its faculty activate themselves on signup, with nothing for you to approve.
    Rotating a code takes the old one out of use at once; accounts already created keep working.
  </p>
  <ul class="code-list">
    <?php foreach (SCHOOLS as $id => $s): $code = (string) ($data['codes'][$id] ?? ''); ?>
      <li class="code-row">
        <span class="code-school"><?= htmlspecialchars($shortSchool($id)) ?></span>
        <?php /* An absence must not be dressed as a value: "none yet" set in the
                 same monospace box as a real code read like one. */ ?>
        <?php if ($code === ''): ?>
          <span class="code-none">Not created</span>
        <?php else: ?>
          <code><?= htmlspecialchars($code) ?></code>
          <button class="copy-btn" type="button" data-copy="<?= htmlspecialchars($code) ?>"
                  aria-label="Copy the join code for <?= htmlspecialchars($s['name']) ?>"><svg aria-hidden="true"><use href="#icon-copy"/></svg></button>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="school" value="<?= htmlspecialchars($id) ?>">
          <button class="btn-quiet" name="action" value="rotate" type="submit"><?= $code !== '' ? 'Rotate' : 'Create' ?></button></form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="card">
  <h2>All accounts <span class="count-pill"><?= count($users) ?></span></h2>
  <?php if (!$users): ?>
    <p class="field-help">No accounts yet. You are signed in from includes/config.local.php.</p>
  <?php endif; ?>
  <ul class="account-list">
    <?php foreach ($users as $email => $u): $status = $u['status'] ?? 'pending'; ?>
      <li class="account">
        <div class="account-who">
          <strong><?= htmlspecialchars($u['name'] ?? $email) ?>
            <span class="status status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
            <?php if (!empty($u['admin'])): ?><span class="status status-admin">admin</span><?php endif; ?>
            <?php if (in_array(audit_role($u), ['lead', 'school', 'dean'], true)): ?><span class="status status-admin"><?= htmlspecialchars(AUDIT_ROLES[$u['role']]) ?></span><?php endif; ?>
          </strong>
          <span><?= htmlspecialchars($email) ?></span>
          <span><?= htmlspecialchars(SCHOOLS[$u['school'] ?? '']['name'] ?? 'No school') ?>
            <?php if (!empty($u['seen'])): ?>&middot; last in <?= htmlspecialchars($u['seen']) ?><?php endif; ?></span>
        </div>
        <div class="account-actions">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <?php if ($status === 'disabled'): ?>
              <button class="btn-quiet" name="action" value="enable" type="submit">Enable</button>
            <?php else: ?>
              <button class="btn-quiet" name="action" value="disable" type="submit">Disable</button>
            <?php endif; ?>
          </form>
          <?php if ($status === 'disabled'): ?>
          <form method="post" onsubmit="return confirm('Delete this account for good?')"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button class="btn-quiet" name="action" value="delete" type="submit">Delete</button></form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button class="btn-quiet" name="action" value="reset" type="submit">Reset password</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button class="btn-quiet" name="action" value="<?= empty($u['admin']) ? 'promote' : 'demote' ?>" type="submit">
              <?= empty($u['admin']) ? 'Make admin' : 'Remove admin' ?></button></form>
        </div>
        <?php if ($faculty): $linked = audit_person_for($u + ['email' => $email], $faculty);
          // The account's own school first, so the right name is near the top.
          $own = array_filter($faculty, fn($f) => in_array($u['school'] ?? '', $f['schools'], true)); ?>
        <form method="post" class="account-audit"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
          <label>Audit level<select name="role">
            <?php foreach (AUDIT_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= ($u['role'] ?? 'faculty') === $k ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
          </select></label>
          <label>On the timetable as<select name="tt">
            <option value=""><?= $linked !== '' && empty($u['tt']) ? htmlspecialchars($faculty[$linked]['name']) . ' (matched by name)' : 'Not linked' ?></option>
            <?php foreach ([$own, array_diff_key($faculty, $own)] as $i => $list): if (!$list) continue; ?>
            <optgroup label="<?= $i === 0 ? htmlspecialchars(SCHOOLS[$u['school'] ?? '']['name'] ?? 'Their school') : 'Other schools' ?>">
              <?php foreach ($list as $id => $f): ?><option value="<?= htmlspecialchars($id) ?>"<?= ($u['tt'] ?? '') === $id ? ' selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select></label>
          <button class="btn-quiet" name="action" value="audit" type="submit">Save</button>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<script src="js/admin.js?v=<?= filemtime(__DIR__ . '/js/admin.js') ?>"></script>
<?php page_foot(); ?>
