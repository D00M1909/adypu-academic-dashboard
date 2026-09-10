// The light/dark toggle, shared by the dashboard and the three faculty pages.
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
