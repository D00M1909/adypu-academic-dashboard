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
})();
