<?php
// The shell the three faculty pages share: login, mark, admin. index.php keeps
// its own, because it carries the icon sprite and the tab machinery and this
// exists to avoid triplicating a <head>, not to refactor the dashboard.
//
// Mobile first, because that is where this is used: a phone, standing up, in a
// classroom, between lectures.

require_once __DIR__ . '/attendance.php';

// The account control in the top right of every header. Signed out, it is the
// one way in for faculty; signed in, an initial that opens the account panel.
// Mark attendance is not in the panel: the header carries it as its own button.
function account_menu(): string {
    $u = function_exists('auth_user') ? auth_user() : null;

    if ($u === null) {
        return '<a class="header-link no-print" href="login.php">'
             . '<svg aria-hidden="true"><use href="#icon-user"/></svg><span>Faculty sign in</span></a>';
    }

    $name = trim((string) ($u['name'] ?? '')) ?: 'Account';
    $email = (string) ($u['email'] ?? '');
    $e = fn(string $v): string => htmlspecialchars($v);
    $initial = mb_strtoupper(mb_substr($name, 0, 1));

    $links = '';
    if (!empty($u['admin'])) $links .= '<a href="admin.php">Faculty accounts</a><a href="stats.php">Usage</a>';
    $links .= '<a href="account.php">Your account</a>'
            . '<a class="account-panel-out" href="login.php?logout=1">Sign out</a>';

    return '<details class="account-menu no-print">'
         . '<summary class="account-trigger" aria-label="Account: ' . $e($name) . '">'
         . '<span class="account-avatar" aria-hidden="true">' . $e($initial) . '</span>'
         . '<svg class="account-caret" aria-hidden="true"><use href="#icon-caret"/></svg>'
         . '</summary>'
         . '<div class="account-panel">'
         . '<p class="account-panel-who"><strong>' . $e($name) . '</strong><span>' . $e($email) . '</span></p>'
         . $links
         . '</div></details>';
}

// The header every page shares. Where to go on the left (Attendance and
// Timetable, the two pages anyone opens), then the one action, the theme and
// the account on the right. $current names the page so its link is marked;
// 'mark' hides the Mark attendance button on the page it would lead to.
// The icons it uses must be in each page's sprite: chart, rooms, mark, user,
// caret, sun, moon.
function site_header(string $title, string $current = ''): void {
    $signedIn = function_exists('auth_user') && auth_user() !== null;
    $nav = ['attendance' => ['index.php', 'chart', 'Attendance'], 'timetable' => ['rooms.php', 'rooms', 'Timetable']];
    ?>
<header class="app-header">
  <div class="header-inner">
    <a class="brand" href="index.php">
      <span class="brand-logo"><img src="img/logo.png" alt="Ajeenkya D Y Patil University"></span>
      <h1><?= htmlspecialchars($title) ?></h1>
    </a>
    <nav class="site-nav no-print" aria-label="Pages">
      <?php foreach ($nav as $id => [$href, $icon, $label]): ?>
      <a href="<?= $href ?>"<?= $current === $id ? ' aria-current="page"' : '' ?>><svg aria-hidden="true"><use href="#icon-<?= $icon ?>"/></svg><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="header-controls">
      <?php if ($signedIn && $current !== 'mark'): ?>
      <a class="header-cta no-print" href="mark.php"><svg aria-hidden="true"><use href="#icon-mark"/></svg><span>Mark attendance</span></a>
      <?php endif; ?>
      <button class="theme-toggle no-print" id="theme-toggle" type="button" aria-label="Switch theme" aria-pressed="false" title="Switch theme">
        <svg class="theme-icon-moon" aria-hidden="true"><use href="#icon-moon"/></svg>
        <svg class="theme-icon-sun" aria-hidden="true"><use href="#icon-sun"/></svg>
      </button>
      <?= account_menu() ?>
    </div>
  </div>
</header>
<?php
}

// $nav is the header link this page is ('timetable'), or '' for a page with no
// link of its own, which then keeps its own name as the header's title.
function page_head(string $title, string $bodyClass = '', string $nav = ''): void {
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
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
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
  <symbol id="icon-caret" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
  <symbol id="icon-user" viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
  <symbol id="icon-copy" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
  <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
  <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></symbol>
  <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
  <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></symbol>
  <symbol id="icon-rooms" viewBox="0 0 24 24"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M10 10v11"/></symbol>
  <symbol id="icon-mark" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="m9 11 3 3L22 4"/></symbol>
</defs></svg>
<?php site_header($nav ? SITE_NAME : $title, $nav ?: (str_contains($bodyClass, 'page-marking') ? 'mark' : '')); ?>
<main class="page-main">
<?php
}

function page_foot(): void {
    $v = filemtime(__DIR__ . '/../js/theme.js');
    echo "</main>\n";
    site_footer();
    echo "<script src=\"js/theme.js?v=$v\"></script>\n</body>\n</html>\n";
}

// One line at the foot of every page. The GitHub mark is inline rather than in
// a sprite because the dashboard and the faculty pages carry different sprites,
// and this is the one icon both need.
const SITE_NAME = 'ADYPU Academic Dashboard';
const SITE_REPO = 'https://github.com/D00M1909/adypu-academic-dashboard';

function site_footer(): void {
    ?>
<footer class="site-footer no-print">
  <div class="footer-inner">
    <span class="footer-brand"><?= SITE_NAME ?></span>
    <span class="footer-links">
      <span>Built by <a href="https://github.com/D00M1909" data-link="author" target="_blank" rel="noopener noreferrer">Advait</a></span>
      <a class="footer-gh" href="<?= SITE_REPO ?>" data-link="repo" target="_blank" rel="noopener noreferrer">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.52-1.33-1.28-1.69-1.28-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.39-5.25 5.67.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .5Z"/></svg>
        <span>GitHub</span>
      </a>
      <a href="<?= SITE_REPO ?>/issues/new" data-link="issues" target="_blank" rel="noopener noreferrer">Report a problem</a>
    </span>
  </div>
</footer>
<script>
// Counts footer link clicks (api/click.php). A beacon, so the new tab opens as
// normal and a failure costs nothing.
document.querySelector('.site-footer').addEventListener('mouseup', function (e) {
  var a = e.target.closest('a[data-link]');
  if (a && e.button < 2 && navigator.sendBeacon) navigator.sendBeacon('api/click.php', new URLSearchParams({l: a.dataset.link}));
});
</script>
<?php
}

// One place that decides how a message looks, so a success and a failure can
// never be styled the same by accident.
function page_notice(string $kind, string $text): void {
    if ($text === '') return;
    $icon = $kind === 'ok' ? 'check' : 'warn';
    printf('<p class="notice notice-%s"><svg aria-hidden="true"><use href="#icon-%s"/></svg><span>%s</span></p>',
        htmlspecialchars($kind), $icon, htmlspecialchars($text));
}
