// The events report's filters. includes/events-report.php draws every entry in
// the range once; this hides what the ticks leave out and recounts from what
// is still showing. Only a date change reloads.
(function () {
  var form = document.getElementById('rep-form');
  var doc = document.getElementById('rep-doc');
  var R = window.EVENTS_REPORT;

  function checked(name) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + name + '"]:checked'), function (i) { return i.value; });
  }
  function list(names) {
    return names.length < 3 ? names.join(' and ') : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1];
  }
  function state() {
    var scope = form.querySelector('input[name="scope"]:checked').value;
    return {
      university: scope === 'university',
      schools: scope === 'university' ? Object.keys(R.schools) : checked('school[]'),
      years: checked('year[]'),
      types: checked('type[]'),
    };
  }
  // The same test as calendar_applies(): an entry for no school in particular
  // is for every school, so it shows whatever is ticked.
  function shows(row, s) {
    var sc = row.dataset.schools ? row.dataset.schools.split(' ') : [];
    var yr = row.dataset.years ? row.dataset.years.split('|') : [];
    return s.types.indexOf(row.dataset.type) !== -1 &&
      s.schools.length > 0 && s.years.length > 0 &&
      (!sc.length || sc.some(function (x) { return s.schools.indexOf(x) !== -1; })) &&
      (!yr.length || yr.some(function (x) { return s.years.indexOf(x) !== -1; }));
  }

  function apply() {
    var s = state();
    document.getElementById('rep-schools').hidden = s.university;
    var count = { holiday: 0, event: 0 };
    var days = { holiday: {}, event: {} };

    doc.querySelectorAll('#evr-list .evr-row').forEach(function (r) {
      r.hidden = !shows(r, s);
      if (!r.hidden) count[r.dataset.type]++;
    });
    doc.querySelectorAll('.evr-day').forEach(function (g) {
      var n = 0;
      g.querySelectorAll('.evr-row').forEach(function (r) {
        r.hidden = !shows(r, s);
        if (r.hidden) return;
        n++;
        days[r.dataset.type][g.dataset.date] = true;
      });
      g.hidden = !n;
    });

    var hDays = Object.keys(days.holiday).length, eDays = Object.keys(days.event).length;
    document.getElementById('st-holidays').textContent = count.holiday;
    document.getElementById('st-holiday-days').textContent = hDays;
    document.getElementById('st-events').textContent = count.event;
    document.getElementById('st-event-days').textContent = eDays;
    var total = count.holiday + count.event;
    document.getElementById('evr-list-meta').textContent = total + ' marked';
    document.getElementById('evr-list').hidden = !total;
    document.getElementById('evr-days').hidden = !total;
    document.getElementById('rep-empty').hidden = !!total;

    // The heading names what is in it, and becomes the PDF's file name.
    var names = s.schools.map(function (id) { return R.schools[id]; });
    var what = s.types.length === 1 ? (s.types[0] === 'holiday' ? 'holidays' : 'events') : 'holidays and events';
    var title = s.university ? 'University ' + what
      : names.length === 1 ? names[0] + ' ' + what : what.charAt(0).toUpperCase() + what.slice(1);
    document.getElementById('rep-title').textContent = title;
    var sub = [];
    if (!s.university && names.length > 1) sub.push(list(names));
    sub.push(s.years.length === R.years.length ? 'All years' : list(s.years));
    document.getElementById('rep-sub').textContent = sub.join(' · ');
    document.title = 'ADYPU ' + title + ' - ' + R.range + (s.years.length === R.years.length ? '' : ' - ' + s.years.join(', '));

    // The address bar is the report: copy it and the same one opens.
    var q = new URLSearchParams();
    q.set('view', 'report');
    q.set('from', R.from);
    q.set('to', R.to);
    if (!s.university) { q.set('scope', 'schools'); s.schools.forEach(function (id) { q.append('school[]', id); }); }
    if (s.years.length !== R.years.length) s.years.forEach(function (y) { q.append('year[]', y); });
    if (s.types.length === 1) q.append('type[]', s.types[0]);
    history.replaceState(null, '', '?' + q.toString());
    form.querySelectorAll('a[data-from]').forEach(function (a) {
      q.set('from', a.dataset.from);
      q.set('to', a.dataset.to);
      a.href = '?' + q.toString();
    });
  }

  form.addEventListener('change', function (e) {
    if (e.target.type === 'date') {
      if (e.target.value) form.submit();
      return;
    }
    apply();
  });
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-all], [data-none]');
    if (!b) return;
    var on = b.hasAttribute('data-all');
    form.querySelectorAll('input[name="' + (b.dataset.all || b.dataset.none) + '[]"]').forEach(function (i) { i.checked = on; });
    apply();
  });
  document.getElementById('rep-export').addEventListener('click', function () { window.print(); });
  apply();
})();
