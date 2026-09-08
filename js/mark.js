(function () {
  var picker = document.getElementById('picker');
  var form = document.getElementById('marking');

  // Changing school, class, date or lecture reloads with the right list. The
  // button below stays in the markup and is only hidden here, so the page still
  // works if this script never runs.
  if (picker) {
    var go = document.getElementById('picker-go');
    if (go) go.hidden = true;
    Array.prototype.forEach.call(picker.querySelectorAll('select, input'), function (el) {
      el.addEventListener('change', function () {
        // Switching school makes the remembered class meaningless: it belongs
        // to the school being navigated away from, and posting it back would
        // reload the same class every time.
        if (el.id === 'p-school') picker.querySelector('#p-class').value = '';
        picker.submit();
      });
    });
  }

  if (!form) return;

  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="absent[]"]'));
  var out = document.getElementById('mark-present');

  // One draft per class, day and lecture, so marking a second lecture does not
  // inherit the first one's absentees.
  var key = 'adypu-draft:' + ['class', 'date', 'time'].map(function (n) {
    var el = form.querySelector('input[name="' + n + '"]');
    return el ? el.value : '';
  }).join('|');

  function render() {
    if (!out) return;
    var absent = boxes.filter(function (b) { return b.checked; }).length;
    out.textContent = String(boxes.length - absent);
  }

  // A dropped signal in a classroom must not cost seventy taps. Kept per
  // browser, never sent anywhere, and cleared the moment the server confirms.
  function save() {
    try {
      var absent = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
      if (absent.length) localStorage.setItem(key, JSON.stringify(absent));
      else localStorage.removeItem(key);
    } catch (e) { /* private mode, or storage disabled: the form still works */ }
  }

  function restore() {
    try {
      // The redirect after a successful save carries ?saved=, so that draft is
      // spent. Clearing it here rather than before the redirect means a save
      // that never reached the server keeps its ticks.
      if (/[?&]saved=/.test(location.search)) { localStorage.removeItem(key); return; }
      var absent = JSON.parse(localStorage.getItem(key) || '[]');
      if (!absent.length) return;
      boxes.forEach(function (b) { if (absent.indexOf(b.value) !== -1) b.checked = true; });
    } catch (e) { /* nothing to restore */ }
  }

  boxes.forEach(function (b) {
    b.addEventListener('change', function () { render(); save(); });
  });

  Array.prototype.forEach.call(form.querySelectorAll('[data-all]'), function (button) {
    button.addEventListener('click', function () {
      var absent = button.getAttribute('data-all') === 'absent';
      boxes.forEach(function (b) { b.checked = absent; });
      render();
      save();
    });
  });

  form.addEventListener('submit', function () {
    // Not cleared here: the request can still fail, and the redirect clears it
    // on the way back in.
    var button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Saving...'; }
  });

  restore();
  render();
})();
