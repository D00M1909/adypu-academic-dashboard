// The report's filters. report.php draws every class in the range once; this
// hides the rows the filters leave out and recounts everything from the rows
// still showing, so a tick is instant and the totals can never disagree with
// the table under them. Only a date change reloads, since the numbers
// themselves depend on it.
(function () {
  var form = document.getElementById('rep-form');
  var doc = document.getElementById('rep-doc');
  var R = window.REPORT;

  function checked(name) {
    return Array.prototype.map.call(form.querySelectorAll('input[name="' + name + '"]:checked'), function (i) { return i.value; });
  }
  function pct(p, s) { return s ? Math.round(Math.min(p, s) / s * 100) : 0; }
  function band(p) { return p >= 75 ? 'att-good' : p >= 70 ? 'att-warn' : 'att-low'; }
  function tally(rows) {
    var t = { classes: 0, filled: 0, present: 0, strength: 0 };
    rows.forEach(function (r) {
      t.classes++;
      if (r.closest('[data-group="filled"]')) {
        t.filled++;
        t.present += +r.dataset.present;
        t.strength += +r.dataset.strength;
      }
    });
    return t;
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
      show: form.querySelector('input[name="show"]:checked').value,
    };
  }

  function apply() {
    var s = state();
    document.getElementById('rep-schools').hidden = s.university;
    var shown = [];
    var any = false;

    doc.querySelectorAll('.rep-year').forEach(function (sec) {
      var inYear = s.years.indexOf(sec.dataset.year) !== -1;
      var yearRows = [];
      sec.querySelectorAll('.rep-group').forEach(function (g) {
        var groupOn = s.show === 'all' || s.show === g.dataset.group;
        var n = 0;
        g.querySelectorAll('.rep-row').forEach(function (r) {
          var inScope = inYear && s.schools.indexOf(r.dataset.school) !== -1;
          if (inScope) yearRows.push(r);
          r.hidden = !(inScope && groupOn);
          if (!r.hidden) n++;
        });
        g.hidden = !n;
        g.querySelector('[data-gcount]').textContent = '(' + n + ')';
      });
      // The year's header counts the whole year in scope, filled or not, so
      // "Not filled" alone still says 5 of 20 filled.
      var t = tally(yearRows);
      sec.hidden = !inYear || !sec.querySelector('.rep-group:not([hidden])');
      if (!sec.hidden) any = true;
      var p = pct(t.present, t.strength);
      sec.querySelector('[data-ymeta]').textContent = t.filled + ' of ' + t.classes + ' filled' +
        (t.filled ? ' · ' + p + '% present' : '');
      var bar = sec.querySelector('[data-ybar]');
      bar.style.width = (t.classes ? t.filled / t.classes * 100 : 0) + '%';
      bar.className = t.classes && t.filled / t.classes >= 0.75 ? 'att-good' : t.filled / t.classes >= 0.5 ? 'att-warn' : 'att-low';
      if (inYear) shown = shown.concat(yearRows);
    });

    var t = tally(shown);
    var p = pct(t.present, t.strength);
    document.getElementById('st-filled').textContent = t.filled;
    document.getElementById('st-classes').textContent = t.classes;
    document.getElementById('st-missing').textContent = t.classes - t.filled;
    document.getElementById('st-present').textContent = t.present;
    document.getElementById('st-strength').textContent = t.strength;
    var pctEl = document.getElementById('st-pct');
    pctEl.textContent = t.filled ? p + '%' : 'None filed';
    pctEl.className = t.filled ? 'att-pct ' + band(p) : 'rep-muted';
    document.getElementById('rep-empty').hidden = any;
    document.getElementById('rep-insights').hidden = !any;
    if (any) window.Charts.renderReport(s.schools, s.years);

    // By school: whenever more than one school is in the report.
    var summary = document.getElementById('rep-summary');
    summary.hidden = s.schools.length < 2 || !any;
    summary.querySelectorAll('[data-sum-school]').forEach(function (tr) {
      var id = tr.dataset.sumSchool;
      var on = s.schools.indexOf(id) !== -1;
      tr.hidden = !on;
      if (!on) return;
      var st = tally(shown.filter(function (r) { return r.dataset.school === id; }));
      var sp = pct(st.present, st.strength);
      tr.querySelector('[data-f]').textContent = st.filled + ' of ' + st.classes;
      tr.querySelector('[data-m]').textContent = st.classes - st.filled;
      tr.querySelector('[data-p]').textContent = st.filled ? st.present + ' / ' + st.strength : '';
      tr.querySelector('[data-a]').innerHTML = st.filled
        ? '<span class="att-pct ' + band(sp) + '">' + sp + '%</span>' : '<span class="rep-muted">None filed</span>';
    });

    // The heading names what is in it, and becomes the PDF's file name.
    var names = s.schools.map(function (id) { return R.schools[id]; });
    var title = s.university ? 'University attendance report'
      : names.length === 1 ? names[0] + ' attendance report' : 'Attendance report';
    document.getElementById('rep-title').textContent = title;
    var yearsLabel = s.years.length === R.years.length ? 'All years' : list(s.years);
    var sub = [];
    if (!s.university && names.length > 1) sub.push(list(names));
    sub.push(yearsLabel);
    if (s.show !== 'all') sub.push(s.show === 'filled' ? 'Filled classes only' : 'Classes not filled only');
    document.getElementById('rep-sub').textContent = sub.join(' · ');
    document.title = 'ADYPU ' + title + ' - ' + R.range + (s.years.length === R.years.length ? '' : ' - ' + s.years.join(', '));

    // The address bar is the report: copy it and the same one opens.
    var q = new URLSearchParams();
    q.set('from', R.from);
    q.set('to', R.to);
    if (!s.university) { q.set('scope', 'schools'); s.schools.forEach(function (id) { q.append('school[]', id); }); }
    if (s.years.length !== R.years.length) s.years.forEach(function (y) { q.append('year[]', y); });
    if (s.show !== 'all') q.set('show', s.show);
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
  // A day on either daily chart opens the report for that day; a school or a
  // year in Compare narrows the report to it.
  window.Charts.bind({
    day: function (date) {
      form.elements.from.value = date;
      form.elements.to.value = date;
      form.submit();
    },
    drill: function (to) {
      if (to.school) {
        form.querySelector('input[name="scope"][value="schools"]').checked = true;
        form.querySelectorAll('input[name="school[]"]').forEach(function (i) { i.checked = i.value === to.school; });
      }
      if (to.year) form.querySelectorAll('input[name="year[]"]').forEach(function (i) { i.checked = i.value === to.year; });
      apply();
      doc.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
  });
  apply();
})();
