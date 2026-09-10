// The two header controls every page shares: the light/dark toggle, and closing
// the account menu.
//
// The theme itself is applied by the inline script in each page's <head>, so
// there is no flash of the wrong one on a full page load. This only wires the
// button, and is null-safe because not every page that stores a theme has to
// offer a control for it.
(function () {
  var toggle = document.getElementById('theme-toggle');
  if (!toggle) return;

  function paint(theme) {
    toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    toggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
  }

  paint(document.documentElement.dataset.theme);
  toggle.addEventListener('click', function () {
    var theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    paint(theme);
    try { localStorage.setItem('adypu-theme', theme); } catch (e) { /* private mode */ }
  });
})();

// A <details> menu is the whole dropdown for free — open, close, keyboard — but
// it has one behaviour nobody expects: clicking anywhere else on the page leaves
// it open. Every other menu on the web closes. This is the only reason the
// account menu needs any script at all.
(function () {
  function closeMenus(except) {
    Array.prototype.forEach.call(document.querySelectorAll('details.account-menu[open]'), function (menu) {
      if (menu !== except) menu.open = false;
    });
  }

  document.addEventListener('click', function (e) {
    var inside = e.target.closest ? e.target.closest('details.account-menu') : null;
    closeMenus(inside);
  });

  // Escape closes it and puts the focus back where it started, which is what a
  // keyboard user expects and what leaving it to <details> alone does not do.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('details.account-menu[open]');
    if (!open) return;
    open.open = false;
    var trigger = open.querySelector('summary');
    if (trigger) trigger.focus();
  });
})();
