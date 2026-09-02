(function () {
  var state = { school: null, year: null, branch: null, division: null };
  var modal = document.getElementById('division-modal');
  var breadcrumb = document.getElementById('breadcrumb');

  // Every other string built into innerHTML here comes from structure.php and
  // is ours. Faculty names are typed by a human into a public Google Form, so
  // they are the one untrusted value on the page and get escaped on the way in.
  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  document.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.tab').forEach(function (t) {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('active');
      tab.setAttribute('aria-selected', 'true');
      var target = tab.dataset.tab;
      document.getElementById('tab-adypu').hidden = target !== 'adypu';
      document.getElementById('tab-partners').hidden = target !== 'partners';
    });
  });

  // The theme itself is applied by the inline script in index.php's head, so
  // this only has to flip and remember it. Nothing else reads the value: every
  // colour on the page comes from a token in dashboard.css.
  var themeToggle = document.getElementById('theme-toggle');
  function paintToggle(theme) {
    themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    themeToggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
  }
  paintToggle(document.documentElement.dataset.theme);
  themeToggle.addEventListener('click', function () {
    var theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    paintToggle(theme);
    try { localStorage.setItem('adypu-theme', theme); } catch (e) {}
  });

  // Mirrors att_class() in index.php and --att-* in dashboard.css.
  function attClass(pct) {
    if (pct >= 75) return 'att-good';
    if (pct >= 70) return 'att-warn';
    return 'att-low';
  }

  function pct(present, strength) {
    return strength ? Math.round((present / strength) * 100) : 0;
  }

  // Totals for whatever the user currently has selected. state.branch is '' for
  // a branchless school (a real key in the tree) and null when no branch is
  // selected — the two must not be conflated or a branchless school would sum
  // to nothing.
  function scopeTotals() {
    var data = window.ATTENDANCE_DATA;
    var present = 0, classes = 0, reported = 0, strengthReported = 0;

    var schools = state.school ? [state.school] : Object.keys(data);

    schools.forEach(function (s) {
      var yearMap = data[s] || {};
      var years = state.year ? [state.year] : Object.keys(yearMap);

      years.forEach(function (y) {
        var branchMap = yearMap[y] || {};
        var branches = state.branch !== null ? [state.branch] : Object.keys(branchMap);

        branches.forEach(function (b) {
          var divisions = branchMap[b] || [];
          divisions.forEach(function (d) {
            if (state.division !== null && d.division !== state.division) return;
            present += d.present;
            classes++;
            // Mirrors attendance_totals() in includes/attendance.php: the
            // percentage divides by the classes that actually reported, or a
            // morning with six forms in reads as an empty university.
            if (d.reported) { reported++; strengthReported += d.strength; }
          });
        });
      });
    });

    return {
      present: present, strengthReported: strengthReported,
      classes: classes, reported: reported
    };
  }

  // The stat row is server-rendered for the whole university, then rescoped
  // here on every selection change — otherwise drilling into one branch still
  // reads as 5190 students, which is the wrong number for what's on screen.
  function updateStats() {
    var t = scopeTotals();
    var p = pct(t.present, t.strengthReported);

    document.getElementById('stat-present').textContent = t.present;
    document.getElementById('stat-strength').textContent = t.strengthReported;
    document.getElementById('stat-reported').textContent = t.reported;
    document.getElementById('stat-classes').textContent = t.classes;

    var pctEl = document.getElementById('stat-pct');
    pctEl.textContent = t.reported ? p + '%' : '--';
    pctEl.className = 'att-pct ' + (t.reported ? attClass(p) : '');

    var parts = [];
    // "School of " on the front of a four-part path is what pushed the
    // division off the end of the card; the crumb trail spells it out anyway.
    if (state.school) parts.push(window.SCHOOLS[state.school].name.replace(/^School of /, ''));
    if (state.year) parts.push(state.year);
    if (state.branch) parts.push(state.branch);
    if (state.division) parts.push('Division ' + state.division);
    // Drilled in, the path is the useful label; the range stays visible in the
    // date bar. At root there is no path, so the range takes its place.
    document.getElementById('stat-scope').textContent = parts.length
      ? parts.join(' \u00b7 ')
      : 'Present \u00b7 ' + window.ATTENDANCE_RANGE.label;

    // Both Chrome's "Save as PDF" and the Windows print driver take the
    // suggested filename from the document title, so the export names itself
    // after the breadcrumb and the range instead of "index".
    document.title = 'ADYPU Attendance - ' +
      (parts.length ? parts.join(' ') : 'All schools') +
      ' - ' + window.ATTENDANCE_RANGE.label;

    window.Charts.render(state, t);
  }

  function crumbSep() {
    return '<svg class="crumb-sep"><use href="#icon-chevron"/></svg>';
  }

  function renderBreadcrumb() {
    var crumbs = [{ key: 'schools', label: 'Schools' }];
    if (state.school) {
      crumbs.push({ key: 'school', label: window.SCHOOLS[state.school].name });
    }
    if (state.year) {
      crumbs.push({ key: 'year', label: state.year });
    }
    if (state.branch) {
      crumbs.push({ key: 'branch', label: state.branch });
    }
    if (state.division) {
      crumbs.push({ key: 'division', label: 'Division ' + state.division });
    }
    breadcrumb.innerHTML = crumbs.map(function (crumb, index) {
      var current = index === crumbs.length - 1;
      var item = current
        ? '<span class="crumb crumb-current" aria-current="page">' + crumb.label + '</span>'
        : '<button class="crumb crumb-button" type="button" data-crumb="' + crumb.key + '">' + crumb.label + '</button>';
      return (index ? crumbSep() : '') + item;
    }).join('');
    // Only earns its place once you've drilled past the school grid.
    breadcrumb.hidden = !state.school;
    updateStats();
  }

  // Populates the Branches section for a chosen school+year. Schools with no
  // confirmed branch data use the single branch key '' — for those, skip the
  // branch step entirely and go straight to that key's divisions, same as
  // before branches existed.
  function updateBranches(schoolId, year, selectedBranch, selectedDivision) {
    var branches = Object.keys(window.ATTENDANCE_DATA[schoolId][year] || {});
    var section = document.getElementById('branches-section');
    var grid = document.getElementById('branches-grid');
    var hasRealBranches = !(branches.length === 1 && branches[0] === '');

    if (!hasRealBranches) {
      section.hidden = true;
      grid.innerHTML = '';
      state.branch = '';
      renderDivisions(schoolId, year, '', selectedDivision);
      return;
    }

    state.branch = branches.indexOf(selectedBranch) !== -1 ? selectedBranch : null;
    grid.innerHTML = '';
    branches.forEach(function (branch) {
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'tile branch-tile';
      tile.textContent = branch;
      tile.addEventListener('click', function () {
        state.branch = branch;
        grid.querySelectorAll('.branch-tile').forEach(function (t) { t.classList.remove('active'); });
        tile.classList.add('active');
        renderDivisions(schoolId, year, branch, null);
      });
      if (branch === state.branch) tile.classList.add('active');
      grid.appendChild(tile);
    });

    document.getElementById('branches-meta').textContent = branches.length + (branches.length === 1 ? ' branch' : ' branches');
    section.hidden = false;
    if (state.branch !== null) {
      renderDivisions(schoolId, year, state.branch, selectedDivision);
    } else {
      hideDivisions();
      renderBreadcrumb();
    }
  }

  function hideDivisions() {
    state.division = null;
    document.getElementById('divisions-section').hidden = true;
    document.getElementById('divisions-grid').innerHTML = '';
  }

  // The leaf of the drill-down. Selecting one narrows the summary card, the
  // breadcrumb and every chart to that single class, which is what the date
  // range makes worth doing: one division's week, on its own.
  function renderDivisions(schoolId, year, branch, selectedDivision) {
    var divisions = (window.ATTENDANCE_DATA[schoolId][year] || {})[branch] || [];
    var section = document.getElementById('divisions-section');
    var grid = document.getElementById('divisions-grid');

    state.division = null;
    grid.innerHTML = '';
    divisions.forEach(function (d) {
      var divPct = pct(d.present, d.strength);
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'tile division-tile';
      tile.dataset.division = d.division;
      tile.innerHTML =
        '<span class="tile-label">Division ' + d.division + '</span>' +
        (d.reported
          ? '<span class="tile-stat">' + d.present + '<span class="tile-stat-sep">/</span>' +
            d.strength + ' \u00b7 <span class="att-pct ' + attClass(divPct) + '">' + divPct + '%</span></span>' +
            '<span class="division-bar"><span class="division-bar-fill ' + attClass(divPct) +
              '" style="width:' + divPct + '%"></span></span>'
          : '<span class="tile-stat tile-unreported">Not reported</span>' +
            '<span class="division-bar"><span class="division-bar-fill" style="width:0"></span></span>');
      tile.addEventListener('click', function () {
        var selecting = state.division !== d.division;
        state.division = selecting ? d.division : null;
        grid.querySelectorAll('.division-tile').forEach(function (t) {
          t.classList.toggle('active', t.dataset.division === state.division);
        });
        renderBreadcrumb();
        // The same breakdown the summary card opens: picking a division is
        // exactly when you want its branch's numbers side by side.
        if (selecting) openDivisionModal();
      });
      grid.appendChild(tile);
    });

    if (divisions.length && selectedDivision) {
      var match = grid.querySelector('[data-division="' + selectedDivision + '"]');
      if (match) {
        state.division = selectedDivision;
        match.classList.add('active');
      }
    }

    document.getElementById('divisions-meta').textContent =
      divisions.length + (divisions.length === 1 ? ' division' : ' divisions');
    section.hidden = divisions.length === 0;
    renderBreadcrumb();
  }

  function renderYears(schoolId, selectedYear, selectedBranch, selectedDivision) {
    var years = Object.keys(window.ATTENDANCE_DATA[schoolId] || {});
    var grid = document.getElementById('years-grid');
    grid.innerHTML = '';
    years.forEach(function (year) {
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'tile year-tile';
      tile.dataset.year = year;
      tile.textContent = year;
      tile.addEventListener('click', function () {
        state.year = year;
        grid.querySelectorAll('.year-tile').forEach(function (t) { t.classList.remove('active'); });
        tile.classList.add('active');
        state.branch = null;
        hideDivisions();
        updateBranches(schoolId, year, null, null);
      });
      grid.appendChild(tile);
    });

    document.getElementById('years-section').hidden = years.length === 0;
    document.getElementById('years-meta').textContent = years.length + (years.length === 1 ? ' year' : ' years');

    if (years.length) {
      state.year = years.indexOf(selectedYear) !== -1 ? selectedYear : years[0];
      Array.prototype.forEach.call(grid.children, function (tile) {
        if (tile.dataset.year === state.year) tile.classList.add('active');
      });
      updateBranches(schoolId, state.year, selectedBranch, selectedDivision);
    }
    renderBreadcrumb();
  }

  function selectSchool(schoolId, selectedYear, selectedBranch, selectedDivision) {
    markSchool(schoolId);
    state.school = schoolId;
    state.year = null;
    state.branch = null;
    state.division = null;
    renderYears(schoolId, selectedYear, selectedBranch, selectedDivision);
  }

  // Which school tile is lit, and whether the grid is in drill-down mode. The
  // print stylesheet hides the tiles that are not lit: on paper the eight
  // schools you did not pick are noise, however useful they are as navigation.
  function markSchool(schoolId) {
    document.querySelectorAll('.school-tile').forEach(function (t) {
      t.classList.toggle('active', t.dataset.school === schoolId);
    });
    // Drives the print rules: once you have drilled in, the navigation grids
    // are just a picture of the breadcrumb and waste a page.
    document.body.classList.toggle('is-drilled', !!schoolId);
    var grid = document.getElementById('schools-grid');
    grid.classList.toggle('is-drilled', !!schoolId);
    var total = document.querySelectorAll('.school-tile').length;
    document.getElementById('schools-meta').textContent =
      schoolId ? '1 of ' + total + ' schools' : total + ' schools';
  }

  function showSchoolLevel(schoolId) {
    markSchool(schoolId);
    state.school = schoolId;
    state.year = null;
    state.branch = null;
    hideDivisions();
    document.getElementById('branches-section').hidden = true;
    document.getElementById('branches-grid').innerHTML = '';

    var years = Object.keys(window.ATTENDANCE_DATA[schoolId] || {});
    var grid = document.getElementById('years-grid');
    grid.innerHTML = '';
    years.forEach(function (year) {
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'tile year-tile';
      tile.dataset.year = year;
      tile.textContent = year;
      tile.addEventListener('click', function () {
        state.year = year;
        grid.querySelectorAll('.year-tile').forEach(function (t) { t.classList.remove('active'); });
        tile.classList.add('active');
        state.branch = null;
        hideDivisions();
        updateBranches(schoolId, year, null, null);
      });
      grid.appendChild(tile);
    });
    document.getElementById('years-section').hidden = years.length === 0;
    document.getElementById('years-meta').textContent = years.length + (years.length === 1 ? ' year' : ' years');
    renderBreadcrumb();
  }

  document.querySelectorAll('.school-tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
      selectSchool(tile.dataset.school);
    });
  });

  breadcrumb.addEventListener('click', function (e) {
    var crumb = e.target.closest('[data-crumb]');
    if (!crumb) return;

    if (crumb.dataset.crumb === 'schools') {
      state.school = null;
      state.year = null;
      state.branch = null;
      markSchool(null);
      hideDivisions();
      document.getElementById('years-section').hidden = true;
      document.getElementById('branches-section').hidden = true;
      renderBreadcrumb();
    } else if (crumb.dataset.crumb === 'school' && state.school) {
      showSchoolLevel(state.school);
    } else if (crumb.dataset.crumb === 'year' && state.school && state.year) {
      state.branch = null;
      updateBranches(state.school, state.year, null, null);
    } else if (crumb.dataset.crumb === 'branch' && state.school && state.year) {
      renderDivisions(state.school, state.year, state.branch, null);
    }
  });

  function firstAvailableSelection() {
    var schoolId = state.school || Object.keys(window.ATTENDANCE_DATA)[0];
    var year = state.year;
    if (schoolId && !year) {
      year = Object.keys(window.ATTENDANCE_DATA[schoolId] || {})[0];
    }
    var branch = state.branch;
    if (schoolId && year && branch === null) {
      branch = Object.keys(window.ATTENDANCE_DATA[schoolId][year] || {})[0] || '';
    }
    return { schoolId: schoolId, year: year, branch: branch };
  }

  function showModal() {
    modal.hidden = false;
    requestAnimationFrame(function () { modal.classList.add('is-open'); });
    document.addEventListener('keydown', onKeydown);
  }

  function hideModal() {
    modal.classList.remove('is-open');
    document.removeEventListener('keydown', onKeydown);
    setTimeout(function () { modal.hidden = true; }, 180);
  }

  function onKeydown(e) {
    if (e.key === 'Escape') hideModal();
  }

  function openDivisionModal() {
    var pick = firstAvailableSelection();
    if (!pick.schoolId || !pick.year) return;

    var url = 'api/division.php?school=' + encodeURIComponent(pick.schoolId) +
      '&year=' + encodeURIComponent(pick.year) +
      '&branch=' + encodeURIComponent(pick.branch || '') +
      '&from=' + encodeURIComponent(window.ATTENDANCE_RANGE.from) +
      '&to=' + encodeURIComponent(window.ATTENDANCE_RANGE.to);

    fetch(url).then(function (r) { return r.json(); }).then(function (data) {
      document.getElementById('modal-subtitle').textContent =
        data.schoolName + ' · ' + data.year + (data.branch ? ' · ' + data.branch : '') +
        ' · ' + data.rangeLabel;

      var grid = document.getElementById('division-grid');
      grid.innerHTML = '';
      // Two columns leaves a row about 215px, which a lecture list does not fit
      // in: the slot wraps over two lines and the faculty name is cut to
      // "Advait Bhat...". One column whenever any division carries such a list.
      grid.classList.toggle('has-readings', data.divisions.some(function (d) {
        return (d.readings || []).length > 1;
      }));
      data.divisions.forEach(function (d) {
        var divPct = pct(d.present, d.strength);
        var row = document.createElement('div');
        row.className = 'division-row';
        // An unreported class has present = 0, which would otherwise render as
        // a full-red 0% bar — indistinguishable from a class where nobody came.
        var readout = d.reported
          ? d.present + '<span class="division-count-sep">/</span>' + d.strength +
            ' <span class="att-pct ' + attClass(divPct) + '">' + divPct + '%</span>'
          : '<span class="tile-unreported">Not reported</span>';
        // Every chip below is derived from the one readings list, so nothing on
        // the row can disagree with the entries listed under it.
        var readings = d.readings || [];
        var dates = [], times = [], names = [];
        readings.forEach(function (r) {
          if (dates.indexOf(r.date) === -1) dates.push(r.date);
          if (r.time && times.indexOf(r.time) === -1) times.push(r.time);
          if (r.faculty && names.indexOf(r.faculty) === -1) names.push(r.faculty);
        });
        times.sort();

        // Over a range the count is an average of the days, so the days behind
        // it have to be on screen: two days out of seven must not read as the
        // week. Within one day the count is the latest lecture rather than an
        // average, which is why a single-day range says nothing here.
        var days = dates.length > 1
          ? '<span class="division-days" title="Reported on ' + dates.join(', ') + '">avg of ' +
              dates.length + ' days</span>'
          : '';
        // The lecture and the name only earn a chip when there is no list under
        // the row to carry them. With one, they said the same thing twice and
        // pushed the count onto a second line.
        var lone = readings.length < 2;
        // Machine-generated HH:MM like the dates above, so it needs no
        // escaping; rows filed before the sheet carried a clock have none.
        var at = lone && times.length
          ? '<span class="division-times">at ' + window.Charts.slotLabel(times[0]) + '</span>'
          : '';
        // Names only exist from the day the Form started asking, so an older
        // range shows the count with no name rather than an "unknown" that
        // would read as a fault.
        var by = lone && names.length
          ? '<span class="division-by" title="' + esc(names[0]) + '">by ' + esc(names[0]) + '</span>'
          : '';
        // The entries behind the number: one line per lecture, so a class its
        // faculty reported three times shows all three. Only worth listing when
        // there are several — a single entry is the readout above, restated.
        var multiDay = window.ATTENDANCE_RANGE.from !== window.ATTENDANCE_RANGE.to;
        var entries = readings.length < 2 ? '' :
          '<ul class="division-readings">' + readings.map(function (r) {
            var rp = pct(r.present, d.strength);
            return '<li>' +
              '<span class="reading-when">' +
                (multiDay ? window.Charts.shortDate(r.date) + ' · ' : '') +
                (r.time ? window.Charts.slotLabel(r.time) : 'no time') +
              '</span>' +
              '<span class="reading-count">' + r.present +
                '<span class="division-count-sep">/</span>' + d.strength +
                ' <span class="att-pct ' + attClass(rp) + '">' + rp + '%</span></span>' +
              (r.faculty ? '<span class="reading-by">' + esc(r.faculty) + '</span>' : '') +
            '</li>';
          }).join('') + '</ul>';
        row.innerHTML =
          '<div class="division-row-top">' +
            '<span class="division-name">Division ' + d.division + days + at + by + '</span>' +
            '<span class="division-count">' + readout + '</span>' +
          '</div>' +
          '<div class="division-bar"><div class="division-bar-fill ' +
            (d.reported ? attClass(divPct) : '') + '" style="width:' +
            (d.reported ? divPct : 0) + '%"></div></div>' +
          entries;
        grid.appendChild(row);
      });

      var totalPct = data.pct;
      document.getElementById('division-total').innerHTML =
        '<span>Total present <small>(' + data.total.reported + ' of ' + data.total.classes +
        ' reported)</small></span><span class="division-count">' + data.total.present +
        '<span class="division-count-sep">/</span>' + data.total.strength_reported + ' · ' + totalPct + '%</span>';

      showModal();
    });
  }

  // Presets fill the two native date inputs and submit the form: the page
  // re-renders server-side for the new range, which keeps one set of range
  // maths in PHP instead of a second copy here. They anchor on the newest day
  // that has data, not on today, so "7 days" is never a week of empty ones.
  function isoDaysBefore(iso, n) {
    var bits = iso.split('-').map(Number);
    // A YYYY-MM-DD is a calendar day, not a moment in the visitor's timezone.
    // UTC arithmetic keeps an inclusive 7-day range six days wide everywhere.
    var d = new Date(Date.UTC(bits[0], bits[1] - 1, bits[2]));
    d.setUTCDate(d.getUTCDate() - n);
    return d.toISOString().slice(0, 10);
  }

  function dashboardToday() {
    var parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date());
    var fields = {};
    parts.forEach(function (part) { fields[part.type] = part.value; });
    return fields.year + '-' + fields.month + '-' + fields.day;
  }

  // Changing the range is a real navigation, so the browser drops you back at
  // the top of the page. Remember where you were and return there once the
  // charts have laid out.
  function rememberScroll() {
    try {
      sessionStorage.setItem('adypu-scroll', String(window.scrollY));
      if (state.school) {
        sessionStorage.setItem('adypu-drilldown', JSON.stringify(state));
      } else {
        sessionStorage.removeItem('adypu-drilldown');
      }
    } catch (e) {}
  }

  document.getElementById('range-form').addEventListener('submit', rememberScroll);

  document.querySelectorAll('.range-preset').forEach(function (button) {
    button.addEventListener('click', function () {
      var preset = button.dataset.preset;
      var form = document.getElementById('range-form');
      var presetInput = document.getElementById('range-preset');
      var today = dashboardToday();
      // form.submit() does not fire the submit event, so this cannot ride on
      // the listener above.
      rememberScroll();

      if (preset === 'latest') {
        // No parameters at all: the server picks the newest day with data.
        window.location.search = '';
        return;
      }
      var latest = window.ATTENDANCE_RANGE.latest;
      var to = preset === 'today' ? today : latest;
      var from = preset === 'today' ? today : isoDaysBefore(latest, Number(preset) - 1);
      // When today's data is also the newest data, the dates alone cannot say
      // whether the user chose Today or Latest day. Carry the selected intent.
      presetInput.value = preset;
      form.querySelector('#range-from').value = from;
      form.querySelector('#range-to').value = to;
      form.submit();
    });
  });

  // Editing either native date input turns the selection into a custom range.
  // The server can still light a matching preset as a fallback, but a stale
  // intent from an earlier click must never force the wrong chip active.
  document.querySelectorAll('#range-from, #range-to').forEach(function (input) {
    function clearPresetIntent() {
      document.getElementById('range-preset').value = '';
    }
    input.addEventListener('input', clearPresetIntent);
    input.addEventListener('change', clearPresetIntent);
  });

  document.getElementById('stat-summary').addEventListener('click', function () { openDivisionModal(); });
  // Ctrl+P bypasses the button, and some print paths read the title later than
  // the click, so the name is set again on the way into the dialog. It is
  // deliberately not restored afterwards: the file is saved after afterprint
  // fires, and putting the old title back first is what leaves a PDF called
  // "ADYPU Academic Dashboard".
  window.addEventListener('beforeprint', updateStats);
  document.getElementById('range-export').addEventListener('click', function () {
    updateStats();
    window.print();
  });
  document.getElementById('modal-refresh').addEventListener('click', function () { openDivisionModal(); });
  document.getElementById('modal-close').addEventListener('click', hideModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) hideModal();
  });

  // First paint: Insights would sit empty until the first drill-down without
  // this, since updateStats() is otherwise only reached from a click.
  try {
    var savedDrilldown = JSON.parse(sessionStorage.getItem('adypu-drilldown'));
    sessionStorage.removeItem('adypu-drilldown');
    if (savedDrilldown && window.ATTENDANCE_DATA[savedDrilldown.school]) {
      selectSchool(savedDrilldown.school, savedDrilldown.year, savedDrilldown.branch, savedDrilldown.division);
    } else {
      updateStats();
    }
  } catch (e) {
    updateStats();
  }

  // After the charts, so the page is its full height and the scroll lands.
  try {
    var y = sessionStorage.getItem('adypu-scroll');
    if (y !== null) {
      sessionStorage.removeItem('adypu-scroll');
      window.scrollTo(0, Number(y));
    }
  } catch (e) {}
})();
