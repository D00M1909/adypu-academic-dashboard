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
  var tools = Array.prototype.slice.call(form.querySelectorAll('[data-all]'));
  var out = document.getElementById('mark-present');
  var absentOut = document.getElementById('mark-absent');

  // One draft per class, day and lecture, so marking a second lecture does not
  // inherit the first one's absentees.
  var key = 'adypu-draft:' + ['class', 'date', 'time'].map(function (n) {
    var el = form.querySelector('input[name="' + n + '"]');
    return el ? el.value : '';
  }).join('|');

  // Nothing to count without a roster. This used to run anyway and write
  // String(0 - 0) into the bar, so a teacher who had just typed 28 into the
  // number box read "0" directly above the Submit button.
  function render() {
    if (!out || !boxes.length) return;
    var absent = boxes.filter(function (b) { return b.checked; }).length;
    out.textContent = String(boxes.length - absent);
    // The absent count is what gets read back to the room, so say it rather
    // than leaving it to be worked out from two numbers.
    if (absentOut) absentOut.textContent = absent ? ' \u00b7 ' + absent + ' absent' : '';
    syncTools(absent);
  }

  // A bulk button whose work is already done changes nothing when tapped, which
  // is indistinguishable from a broken one. Say so rather than stay silent.
  function syncTools(absentCount) {
    tools.forEach(function (button) {
      var target = button.getAttribute('data-all') === 'absent' ? boxes.length : 0;
      var noop = absentCount === target;
      button.disabled = noop;
      if (noop) disarm(button);
    });
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

  // "All absent" throws away a roll that may have taken sixty taps to build, and
  // sits next to the button that starts one, so it asks first — but NOT with
  // window.confirm(). Plenty of browsers and embedded webviews suppress a native
  // dialog outright, and a suppressed confirm() returns false, which left this
  // button permanently dead with nothing on screen to say why. Arming it in
  // place needs no dialog at all, and is the better gesture on a phone anyway.
  var armTimer = null;
  var ARM_MS = 4000;

  function disarm(button) {
    if (!button.dataset.armed) return;
    button.textContent = button.dataset.label;
    delete button.dataset.armed;
    button.classList.remove('is-armed');
  }

  function disarmAll() {
    clearTimeout(armTimer);
    tools.forEach(disarm);
  }

  tools.forEach(function (button) {
    button.dataset.label = (button.textContent || '').trim();
    button.addEventListener('click', function () {
      var armedLabel = button.getAttribute('data-confirm');
      if (armedLabel && !button.dataset.armed) {
        disarmAll();
        button.dataset.armed = '1';
        button.classList.add('is-armed');
        button.textContent = armedLabel;
        // Never left armed indefinitely: a stray tap must not sit there waiting
        // to wipe the roll on whatever gets tapped next.
        armTimer = setTimeout(function () { disarm(button); }, ARM_MS);
        return;
      }
      disarmAll();
      var absent = button.getAttribute('data-all') === 'absent';
      boxes.forEach(function (b) { b.checked = absent; });
      render();
      save();
    });
  });

  // --- Finding one student in sixty ------------------------------------------
  //
  // Rows are hidden with the `hidden` attribute, never removed, so a student
  // ticked absent and then filtered out of view still posts. The bar's count is
  // the check on that: it counts every box, not the visible ones.
  var filter = document.getElementById('roster-filter');
  if (filter) {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#roster > li'));
    var empty = document.getElementById('roster-empty');
    var count = document.getElementById('roster-filter-count');

    var haystacks = rows.map(function (li) {
      return (li.textContent || '').toLowerCase().replace(/\s+/g, ' ').trim();
    });

    function applyFilter() {
      var q = filter.value.toLowerCase().replace(/\s+/g, ' ').trim();
      var shown = 0;
      rows.forEach(function (li, i) {
        var hit = q === '' || haystacks[i].indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
      if (count) count.textContent = q === '' ? '' : shown + ' of ' + rows.length;
    }

    filter.addEventListener('input', applyFilter);
    // Enter in a search field would otherwise submit the roll.
    filter.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); filter.blur(); }
    });
    applyFilter();
  }

  form.addEventListener('submit', function () {
    // Not cleared here: the request can still fail, and the redirect clears it
    // on the way back in.
    var button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Saving...'; }
  });

  restore();
  render();
})();
