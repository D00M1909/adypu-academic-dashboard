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

// The page-load bar (.nav-progress). Every range change, tab, room pick and
// header link is a full page load, and this host takes a second or two to
// answer, so the bar starts the moment one is asked for and creeps towards the
// end until the next page replaces this one. It never reaches the end on its
// own: only the new page arriving finishes it.
(function () {
  var bar = document.createElement('div');
  bar.className = 'nav-progress';
  bar.setAttribute('aria-hidden', 'true');
  document.body.appendChild(bar);

  var at = 0;
  var creep = null;
  var giveUp = null;

  function grow(to) {
    at = to;
    bar.style.transform = 'scaleX(' + to + ')';
  }

  function start() {
    if (creep) return;
    bar.classList.remove('is-done');
    bar.classList.add('is-active');
    // A visible jump first, so the click reads as heard, then ever smaller
    // steps that slow down near the end without stopping.
    grow(0.15);
    creep = setInterval(function () { grow(at + (0.9 - at) * 0.07); }, 200);
    // A click that never leaves the page (a download, a server that answers
    // "nothing to show") must not leave the bar hanging there.
    giveUp = setTimeout(finish, 20000);
  }

  function finish() {
    clearInterval(creep);
    clearTimeout(giveUp);
    creep = null;
    bar.classList.add('is-done');
    grow(1);
    setTimeout(function () {
      bar.classList.remove('is-active', 'is-done');
      grow(0);
    }, 500);
  }

  // A link that loads a new page here: not a new tab, not a download, not a
  // jump within this page, and not one a script already handled.
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var link = e.target.closest ? e.target.closest('a[href]') : null;
    if (!link || link.hasAttribute('download')) return;
    if (link.target && link.target !== '_self') return;
    if (!/^https?:$/.test(link.protocol)) return;
    if (link.hash && link.href.split('#')[0] === location.href.split('#')[0]) return;
    start();
  });

  // A form sent by its button, and a form sent from script: form.submit()
  // fires no submit event, and it is how the date range and the room picker's
  // dropdowns send themselves.
  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented && (!e.target.target || e.target.target === '_self')) start();
  });
  var submit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if (!this.target || this.target === '_self') start();
    return submit.apply(this, arguments);
  };

  // Back to a page the browser kept in memory: it comes back as it was left,
  // bar and all, so clear it.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted && creep) finish();
  });
})();
