// The Events tab: a day's entries in a panel for anyone, and for an admin the
// form behind Add a day and Edit. The month itself is drawn by events.php, so
// only these two dialogs live here.
(function () {
  var E = window.EVENTS;
  var $ = function (id) { return document.getElementById(id); };
  var dayPanel = $('cal-day'), editPanel = $('cal-edit'), form = $('cal-form');
  var shownDate = E.today;

  function esc(v) {
    return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }
  function open(panel) {
    panel.hidden = false;
    requestAnimationFrame(function () { panel.classList.add('is-open'); });
  }
  function close(panel) {
    panel.classList.remove('is-open');
    setTimeout(function () { panel.hidden = true; }, 180);
  }
  function entriesOn(date) {
    return E.entries.filter(function (x) { return date >= x.from && date <= x.to; });
  }
  function byId(id) {
    return E.entries.filter(function (x) { return x.id === id; })[0];
  }

  function showDay(date) {
    shownDate = date;
    var here = entriesOn(date);
    if (!here.length) { if (E.admin) showForm(null, date); return; }
    $('cal-day-title').textContent = new Date(date + 'T00:00:00')
      .toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
    $('cal-day-list').innerHTML = here.map(function (x) {
      return '<div class="cal-item">' +
        '<span class="cal-tag is-' + esc(x.type) + '"><svg aria-hidden="true"><use href="#icon-' + esc(x.type) + '"/></svg><span>' + (x.type === 'holiday' ? 'Holiday' : 'Event') + '</span></span>' +
        '<strong>' + esc(x.title) + '</strong><small>' + esc(x.when) + ' · ' + esc(x.scope) + '</small>' +
        (E.admin ? '<button class="rep-link" type="button" data-edit="' + esc(x.id) + '">Edit</button>' : '') +
      '</div>';
    }).join('');
    open(dayPanel);
  }

  function syncForm() {
    var holiday = form.querySelector('[name=type]:checked').value === 'holiday';
    $('cal-type-hint').textContent = holiday
      ? 'No classes expected. Nobody shows as not filled.'
      : 'A normal day with a note. Attendance still counts.';
    $('cal-schools').hidden = form.querySelector('[name=for]:checked').value === 'all';
    $('cal-years').hidden = form.querySelector('[name=for_years]:checked').value === 'all';
  }
  function setChips(box, list) {
    box.querySelectorAll('input').forEach(function (i) { i.checked = list.indexOf(i.value) !== -1; });
  }
  function showForm(entry, date) {
    $('cal-form-title').textContent = entry ? 'Edit this day' : 'Add a day';
    $('cal-delete').hidden = !entry;
    $('cal-error').hidden = true;
    $('cal-id').value = entry ? entry.id : '';
    $('cal-title').value = entry ? entry.title : '';
    $('cal-from').value = entry ? entry.from : date;
    $('cal-to').value = entry ? entry.to : date;
    form.querySelector('[name=type][value=' + (entry ? entry.type : 'holiday') + ']').checked = true;
    form.querySelector('[name=for][value=' + (entry && entry.schools.length ? 'some' : 'all') + ']').checked = true;
    form.querySelector('[name=for_years][value=' + (entry && entry.years.length ? 'some' : 'all') + ']').checked = true;
    setChips($('cal-schools'), entry ? entry.schools : []);
    setChips($('cal-years'), entry ? entry.years : []);
    syncForm();
    open(editPanel);
  }

  document.addEventListener('click', function (e) {
    var day = e.target.closest('[data-date]');
    if (day) { showDay(day.dataset.date); return; }
    var edit = e.target.closest('[data-edit]');
    if (edit) {
      if (!dayPanel.hidden) close(dayPanel);
      showForm(byId(edit.dataset.edit));
      return;
    }
    var panel = e.target.closest('[data-close]') ? e.target.closest('.modal-backdrop')
      : e.target.classList.contains('cal-backdrop') ? e.target : null;
    if (panel) close(panel);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    [dayPanel, editPanel].forEach(function (p) { if (p && !p.hidden) close(p); });
  });
  if (!form) return;

  $('cal-new').addEventListener('click', function () { showForm(null, E.today); });
  $('cal-day-add').addEventListener('click', function () {
    close(dayPanel);
    showForm(null, shownDate);
  });
  form.addEventListener('change', syncForm);
  $('cal-from').addEventListener('change', function () {
    if ($('cal-to').value < this.value) $('cal-to').value = this.value;
  });
  // The same two refusals as calendar_clean(), said here so the form does not
  // come back empty after a reload.
  form.addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'delete') {
      if (!window.confirm('Delete ' + $('cal-title').value + '?')) e.preventDefault();
      return;
    }
    var missing = !$('cal-schools').hidden && !$('cal-schools').querySelector(':checked') ? 'Tick at least one school.'
      : !$('cal-years').hidden && !$('cal-years').querySelector(':checked') ? 'Tick at least one year.' : '';
    $('cal-error').hidden = !missing;
    $('cal-error').textContent = missing;
    if (missing) e.preventDefault();
  });
})();
