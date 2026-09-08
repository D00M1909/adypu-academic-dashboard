<?php
// Who may write attendance. Approving the queue, disabling an account, and
// rotating the join codes that let a school onboard itself without any of this.
//
// There is no email on this host, so the reset button showing a temporary
// password once, on screen, is the entire password recovery story.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';

header('Cache-Control: no-store');
auth_boot();
$me = auth_require(true);

$error = '';
$ok = '';
$temp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = auth_key((string) ($_POST['email'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
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
            case 'reset':
                $temp = auth_reset_password($email);
                $ok = 'Read this to them now. It is not shown again.';
                break;
        }
    }
}

$data = auth_data();
$users = $data['users'];
uasort($users, fn($a, $b) => [$a['status'] ?? '', $b['created'] ?? ''] <=> [$b['status'] ?? '', $a['created'] ?? '']);
$pending = array_filter($users, fn($u) => ($u['status'] ?? '') === 'pending');

page_head('Faculty accounts', 'page-narrow');
?>
<?php page_notice('warn', $error); page_notice('ok', $ok); ?>
<?php if ($temp !== ''): ?>
  <p class="temp-password">Temporary password: <code><?= htmlspecialchars($temp) ?></code></p>
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

<section class="card">
  <h2>School join codes</h2>
  <p class="field-help">
    Give a school its code and its faculty activate themselves on signup, with nothing for you to approve.
    Rotating a code takes the old one out of use at once; accounts already created keep working.
  </p>
  <ul class="code-list">
    <?php foreach (SCHOOLS as $id => $s): ?>
      <li class="code-row">
        <span><?= htmlspecialchars($s['name']) ?></span>
        <code><?= htmlspecialchars($data['codes'][$id] ?? 'none yet') ?></code>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="school" value="<?= htmlspecialchars($id) ?>">
          <button class="btn-quiet" name="action" value="rotate" type="submit"><?= isset($data['codes'][$id]) ? 'Rotate' : 'Create' ?></button></form>
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
          <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button class="btn-quiet" name="action" value="reset" type="submit">Reset password</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button class="btn-quiet" name="action" value="<?= empty($u['admin']) ? 'promote' : 'demote' ?>" type="submit">
              <?= empty($u['admin']) ? 'Make admin' : 'Remove admin' ?></button></form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php page_foot(); ?>
