<?php
// Sign in, or sign up. One page with two panels rather than two pages, because
// a faculty member arriving from a link does not know which of the two they
// need, and a wrong guess should be a tap rather than a navigation.
//
// The dashboard itself stays open to everyone. This gate is only on writing.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/page.php';

header('Cache-Control: no-store');
auth_boot();

if (isset($_GET['logout'])) {
    auth_logout();
    header('Location: login.php?bye=1');
    exit;
}

// Only ever a path on this site. An open redirect here would let a phishing
// link wear our domain all the way to the password field.
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'mark.php');
if (!preg_match('#^/?[a-z0-9_.\-]+\.php(\?[^\s]*)?$#i', $next)) $next = 'mark.php';

$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'in') === 'up' ? 'up' : 'in';
$error = '';
$ok = isset($_GET['bye']) ? 'You are signed out.' : '';

if (auth_user() && $_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['bye'])) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'That form expired. Please try again.';
    } elseif ($mode === 'up') {
        $error = auth_signup(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['school'] ?? ''),
            (string) ($_POST['code'] ?? '')
        );
        if ($error === '') {
            // Signing them in either way: an approved account goes straight to
            // marking, and a pending one sees the waiting page rather than a
            // login form that would look like the signup had failed.
            auth_login((string) $_POST['email'], (string) $_POST['password']);
            header('Location: ' . $next);
            exit;
        }
    } else {
        $error = auth_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($error === '') {
            header('Location: ' . $next);
            exit;
        }
    }
}

page_head($mode === 'up' ? 'Create an account' : 'Faculty sign in', 'page-narrow');
?>
<div class="card auth-card">
  <nav class="auth-switch" role="tablist">
    <a class="auth-tab<?= $mode === 'in' ? ' is-active' : '' ?>" href="?mode=in&amp;next=<?= urlencode($next) ?>">Sign in</a>
    <a class="auth-tab<?= $mode === 'up' ? ' is-active' : '' ?>" href="?mode=up&amp;next=<?= urlencode($next) ?>">Create account</a>
  </nav>

  <?php page_notice('warn', $error); page_notice('ok', $ok); ?>

  <form method="post" class="auth-form">
    <?= csrf_field() ?>
    <input type="hidden" name="mode" value="<?= $mode ?>">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

    <?php if ($mode === 'up'): ?>
      <label for="f-name">Your name</label>
      <input id="f-name" name="name" type="text" autocomplete="name" required
             value="<?= htmlspecialchars((string) ($_POST['name'] ?? '')) ?>">

      <label for="f-school">School</label>
      <select id="f-school" name="school" required>
        <option value="">Choose your school</option>
        <?php foreach (SCHOOLS as $id => $s): ?>
          <option value="<?= htmlspecialchars($id) ?>"<?= ($_POST['school'] ?? '') === $id ? ' selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <label for="f-email">University email</label>
    <input id="f-email" name="email" type="email" inputmode="email" autocomplete="username"
           autocapitalize="off" spellcheck="false" required
           value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">

    <label for="f-password">Password</label>
    <input id="f-password" name="password" type="password" required
           autocomplete="<?= $mode === 'up' ? 'new-password' : 'current-password' ?>"
           <?= $mode === 'up' ? 'minlength="' . AUTH_MIN_PASSWORD . '"' : '' ?>>

    <?php if ($mode === 'up'): ?>
      <label for="f-code">School join code <span class="label-hint">optional</span></label>
      <input id="f-code" name="code" type="text" autocapitalize="characters" autocomplete="off"
             spellcheck="false" placeholder="ABCD-2345"
             value="<?= htmlspecialchars((string) ($_POST['code'] ?? '')) ?>">
      <p class="field-help">
        Your school office has this. Enter it and you can start marking straight away.
        Leave it blank and we will approve your account by hand, usually the same day.
      </p>
    <?php endif; ?>

    <button class="btn-primary" type="submit"><?= $mode === 'up' ? 'Create account' : 'Sign in' ?></button>
  </form>

  <p class="field-help">
    Only marking attendance needs an account.
    <a href="index.php">The dashboard is open to everyone.</a>
  </p>
</div>
<?php page_foot(); ?>
