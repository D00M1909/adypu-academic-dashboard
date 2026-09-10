<?php
// Changing your own password.
//
// The only self-service account screen there is, and for most people the only
// way a password here will ever change: this host has no mail(), so there is no
// reset link, and an admin's reset hands out a random string its owner would
// otherwise be stuck with for good.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';

header('Cache-Control: no-store');
auth_boot();
$me = auth_require();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string) ($_POST['new'] ?? '');
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
        // Checked here, not in auth_change_password(): a mistyped confirmation
        // is a slip on this form, not a rule about what a password may be.
        $error = 'The two new passwords do not match.';
    } else {
        $error = auth_change_password($me['email'], (string) ($_POST['current'] ?? ''), $new);
        if ($error === '') {
            $ok = 'Password changed. Any other device you were signed in on has been signed out.';
            // Re-read: the bootstrap admin becomes a stored account on its first
            // change, so what auth_user() answers with is different afterwards.
            $me = auth_user() ?? $me;
        }
    }
}

page_head('Your account', 'page-narrow');
?>
<?php page_notice('warn', $error); page_notice('ok', $ok); ?>

<section class="card">
  <h2>Signed in as</h2>
  <dl class="account-facts">
    <dt>Name</dt><dd><?= htmlspecialchars($me['name'] ?? '') ?></dd>
    <dt>Email</dt><dd><?= htmlspecialchars($me['email'] ?? '') ?></dd>
    <?php if (!empty($me['school']) && isset(SCHOOLS[$me['school']])): ?>
      <dt>School</dt><dd><?= htmlspecialchars(SCHOOLS[$me['school']]['name']) ?></dd>
    <?php endif; ?>
    <dt>Role</dt><dd><?= !empty($me['admin']) ? 'Administrator' : 'Faculty' ?></dd>
  </dl>
</section>

<section class="card">
  <h2>Change your password</h2>
  <p class="field-help">
    There is no email on this server, so there is no reset link. If you forget this,
    an administrator has to issue you a temporary one in person.
  </p>
  <form method="post" class="auth-form">
    <?= csrf_field() ?>
    <label for="a-current">Current password</label>
    <input id="a-current" name="current" type="password" autocomplete="current-password" required>

    <label for="a-new">New password</label>
    <input id="a-new" name="new" type="password" autocomplete="new-password"
           minlength="<?= AUTH_MIN_PASSWORD ?>" required>
    <p class="field-help">At least <?= AUTH_MIN_PASSWORD ?> characters.</p>

    <label for="a-confirm">New password again</label>
    <input id="a-confirm" name="confirm" type="password" autocomplete="new-password"
           minlength="<?= AUTH_MIN_PASSWORD ?>" required>

    <button class="btn-primary" type="submit">Change password</button>
  </form>
</section>
<?php page_foot(); ?>
