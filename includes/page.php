<?php
// The shell the three faculty pages share: login, mark, admin. index.php keeps
// its own, because it carries the icon sprite and the tab machinery and this
// exists to avoid triplicating a <head>, not to refactor the dashboard.
//
// Mobile first, because that is where this is used: a phone, standing up, in a
// classroom, between lectures.

require_once __DIR__ . '/attendance.php';

function page_head(string $title, string $bodyClass = ''): void {
    $root = __DIR__ . '/..';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> &middot; ADYPU</title>
<link rel="icon" href="img/favicon.png?v=<?= filemtime("$root/img/favicon.png") ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css?v=<?= filemtime("$root/css/dashboard.css") ?>">
<meta name="theme-color" content="#7f1420">
<script>
// Same inline theme resolve as index.php, and inline for the same reason: these
// are full page loads, and a deferred script would flash white on every one.
try {
  document.documentElement.dataset.theme = localStorage.getItem('adypu-theme')
    || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
} catch (e) {
  document.documentElement.dataset.theme = 'light';
}
</script>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
<svg style="display:none" aria-hidden="true"><defs>
  <symbol id="icon-back" viewBox="0 0 24 24"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></symbol>
  <symbol id="icon-out" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></symbol>
  <symbol id="icon-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
  <symbol id="icon-warn" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></symbol>
  <symbol id="icon-copy" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
  <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
  <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></symbol>
  <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
</defs></svg>
<header class="app-header">
  <div class="header-inner">
    <div class="brand">
      <div class="brand-logo"><img src="img/logo.png" alt="Ajeenkya D Y Patil University"></div>
      <h1><?= htmlspecialchars($title) ?></h1>
    </div>
    <div class="header-controls">
      <?php $u = function_exists('auth_user') ? auth_user() : null; ?>
      <a class="header-link is-compact" href="index.php"><svg aria-hidden="true"><use href="#icon-back"/></svg><span>Dashboard</span></a>
      <?php if ($u): ?>
        <?php if (!empty($u['admin'])): ?>
          <a class="header-link" href="admin.php"><span>Admin</span></a>
        <?php endif; ?>
        <a class="header-link is-compact" href="login.php?logout=1" title="Signed in as <?= htmlspecialchars($u['name']) ?>">
          <svg aria-hidden="true"><use href="#icon-out"/></svg><span>Sign out</span>
        </a>
      <?php endif; ?>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Switch theme" aria-pressed="false" title="Switch theme">
        <svg class="theme-icon-moon" aria-hidden="true"><use href="#icon-moon"/></svg>
        <svg class="theme-icon-sun" aria-hidden="true"><use href="#icon-sun"/></svg>
      </button>
    </div>
  </div>
</header>
<main class="page-main">
<?php
}

function page_foot(): void {
    $v = filemtime(__DIR__ . '/../js/theme.js');
    echo "</main>\n<script src=\"js/theme.js?v=$v\"></script>\n</body>\n</html>\n";
}

// One place that decides how a message looks, so a success and a failure can
// never be styled the same by accident.
function page_notice(string $kind, string $text): void {
    if ($text === '') return;
    $icon = $kind === 'ok' ? 'check' : 'warn';
    printf('<p class="notice notice-%s"><svg aria-hidden="true"><use href="#icon-%s"/></svg><span>%s</span></p>',
        htmlspecialchars($kind), $icon, htmlspecialchars($text));
}
