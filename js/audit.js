// The Audit tab (audit.php). It sends an upload as soon as a file is picked,
// asks "are you sure" before the one final submit, and runs the reviewer's
// "approve all" box.
(function () {
  'use strict';

  var MAX = 10 * 1024 * 1024;
  document.querySelectorAll('.aud-up input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f) return;
      // The server refuses it too, but only after the whole file has crossed
      // a phone connection.
      if (f.size > MAX) {
        alert('That file is over 10 MB. Please make it smaller, or split it.');
        input.value = '';
        return;
      }
      input.closest('.aud-pick').classList.add('is-busy');
      input.closest('.aud-pick').lastChild.textContent = 'Uploading...';
      input.form.requestSubmit ? input.form.requestSubmit() : input.form.submit();
    });
  });

  var panel = document.getElementById('aud-confirm');
  var openBtn = document.getElementById('aud-submit-open');
  if (panel && openBtn) {
    var open = function () {
      panel.hidden = false;
      requestAnimationFrame(function () { panel.classList.add('is-open'); });
      panel.querySelector('[data-close]').focus();
    };
    var close = function () {
      panel.classList.remove('is-open');
      setTimeout(function () { panel.hidden = true; }, 180);
      openBtn.focus();
    };
    openBtn.addEventListener('click', open);
    panel.addEventListener('click', function (e) {
      if (e.target === panel || e.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) close();
    });
  }

  var all = document.getElementById('aud-select-all');
  if (all) {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('#aud-review input[name="ok[]"]:not(:disabled)'));
    var sync = function () {
      var on = boxes.filter(function (b) { return b.checked; }).length;
      all.checked = boxes.length > 0 && on === boxes.length;
      all.indeterminate = on > 0 && on < boxes.length;
    };
    all.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = all.checked; });
    });
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
    sync();
  }
  // Insights: the homepage's hover tip (.chart-tip), and a click on a mark
  // with somewhere to go opens it. On a touch screen the first tap shows the
  // tip and the second one opens.
  var tipEl = null, current = null;
  function hot(t) { return t.closest('.chart-day') || t; }
  function show(t, x, y) {
    if (!tipEl) {
      tipEl = document.createElement('div');
      tipEl.className = 'chart-tip';
      tipEl.setAttribute('role', 'tooltip');
      document.body.appendChild(tipEl);
    }
    if (current !== t) {
      if (current) hot(current).classList.remove('is-hot');
      current = t;
      tipEl.innerHTML = t.getAttribute('data-tip');
      tipEl.hidden = false;
      hot(t).classList.add('is-hot');
    }
    var r = tipEl.getBoundingClientRect();
    tipEl.style.left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x - r.width / 2)) + 'px';
    var top = y - r.height - 14;
    tipEl.style.top = (top < 8 ? y + 18 : top) + 'px';
  }
  function hide() {
    if (current) hot(current).classList.remove('is-hot');
    current = null;
    if (tipEl) tipEl.hidden = true;
  }
  function go(t) { if (t.dataset.href) window.location.href = t.dataset.href; }
  if (document.querySelector('.aud-insights [data-tip]')) {
    document.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') return;
      var t = e.target.closest && e.target.closest('.aud-insights [data-tip]');
      if (t) show(t, e.clientX, e.clientY); else if (current) hide();
    });
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('.aud-insights [data-tip]');
      if (!t) { hide(); return; }
      if ((e.pointerType === 'touch' || (!e.pointerType && 'ontouchstart' in window)) && current !== t) {
        var r = t.getBoundingClientRect();
        show(t, r.left + r.width / 2, r.top);
        return;
      }
      go(t);
    });
    document.addEventListener('keydown', function (e) {
      var t = e.target.closest && e.target.closest('.aud-insights [data-tip]');
      if (!t) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(t); }
      if (e.key === 'Escape') hide();
    });
    document.addEventListener('focusin', function (e) {
      var t = e.target.closest && e.target.closest('.aud-insights [data-tip]');
      if (!t) { hide(); return; }
      var r = t.getBoundingClientRect();
      show(t, r.left + r.width / 2, r.top);
    });
    window.addEventListener('scroll', hide, { passive: true });
  }

  // A faculty bar links to #p-<person> on Review: open that person's files.
  if (location.hash.indexOf('#p-') === 0) {
    var d = document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView({ block: 'start' }); }
  }
})();
