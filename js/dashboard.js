(function () {
  var state = { school: null, year: null, branch: null };
  var modal = document.getElementById('division-modal');
  var breadcrumb = document.getElementById('breadcrumb');

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

  // Mirrors att_class() in index.php and --att-* in dashboard.css.
  function attClass(pct) {
    if (pct >= 85) return 'att-good';
    if (pct >= 70) return 'att-warn';
    return 'att-low';
  }

  function pct(present, strength) {
    return strength ? Math.round((present / strength) * 100) : 0;
  }

  // Totals for whatever the user currently has selected, plus how many things
  // sit one level below it. state.branch is '' for a branchless school (a real
  // key in the tree) and null when no branch is selected — the two must not be
  // conflated or a branchless school would sum to nothing.
  function scopeTotals() {
    var data = window.ATTENDANCE_DATA;
    var present = 0, strength = 0, units = 0, unitLabel = 'Schools';

    var schools = state.school ? [state.school] : Object.keys(data);
    if (!state.school) units = schools.length;

    schools.forEach(function (s) {
      var yearMap = data[s] || {};
      var years = state.year ? [state.year] : Object.keys(yearMap);
      if (state.school && !state.year) { units = years.length; unitLabel = 'Years'; }

      years.forEach(function (y) {
        var branchMap = yearMap[y] || {};
        var branches = state.branch !== null ? [state.branch] : Object.keys(branchMap);
        if (state.year && state.branch === null) { units = branches.length; unitLabel = 'Branches'; }

        branches.forEach(function (b) {
          var divisions = branchMap[b] || [];
          if (state.branch !== null) { units = divisions.length; unitLabel = 'Divisions'; }
          divisions.forEach(function (d) {
            present += d.present;
            strength += d.strength;
          });
        });
      });
    });

    return { present: present, strength: strength, units: units, unitLabel: unitLabel };
  }

  // The stat row is server-rendered for the whole university, then rescoped
  // here on every selection change — otherwise drilling into one branch still
  // reads as 5190 students, which is the wrong number for what's on screen.
  function updateStats() {
    var t = scopeTotals();
    var p = pct(t.present, t.strength);

    document.getElementById('stat-present').textContent = t.present;
    document.getElementById('stat-strength').textContent = t.strength;
    document.getElementById('stat-units').textContent = t.units;
    document.getElementById('stat-units-label').textContent = t.unitLabel;
    document.getElementById('stat-absent').textContent = t.strength - t.present;

    var pctEl = document.getElementById('stat-pct');
    pctEl.textContent = p + '%';
    pctEl.className = 'stat-pill-value att-pct ' + attClass(p);

    var parts = [];
    if (state.school) parts.push(window.SCHOOLS[state.school].name);
    if (state.year) parts.push(state.year);
    if (state.branch) parts.push(state.branch);
    // The date only rides along at root. Appending it to a drilled path wraps
    // the label to two lines and shoves the value out of the card.
    document.getElementById('stat-scope').textContent = parts.length
      ? parts.join(' \u00b7 ')
      : 'Present Today \u00b7 ' + window.ATTENDANCE_DATE;
  }

  function crumbSep() {
    return '<svg class="crumb-sep"><use href="#icon-chevron"/></svg>';
  }

  function renderBreadcrumb() {
    var crumbs = ['<span class="crumb" data-crumb="schools">Schools</span>'];
    if (state.school) {
      crumbs.push(crumbSep());
      crumbs.push('<span class="crumb" data-crumb="school">' + window.SCHOOLS[state.school].name + '</span>');
    }
    if (state.year) {
      crumbs.push(crumbSep());
      crumbs.push('<span class="crumb" data-crumb="year">' + state.year + '</span>');
    }
    if (state.branch) {
      crumbs.push(crumbSep());
      crumbs.push('<span class="crumb" data-crumb="branch">' + state.branch + '</span>');
    }
    var last = crumbs.length - 1;
    crumbs[last] = crumbs[last].replace('class="crumb"', 'class="crumb crumb-current"');
    breadcrumb.innerHTML = crumbs.join('');
    // Only earns its place once you've drilled past the school grid.
    breadcrumb.hidden = !state.school;
    updateStats();
  }

  // Populates the Branches section for a chosen school+year. Schools with no
  // confirmed branch data use the single branch key '' — for those, skip the
  // branch step entirely and go straight to the division modal, same as
  // before branches existed. autoOpen controls whether that skip-through also
  // opens the modal (true on an explicit year click, false on auto-selection).
  function updateBranches(schoolId, year, autoOpen) {
    var branches = Object.keys(window.ATTENDANCE_DATA[schoolId][year] || {});
    var section = document.getElementById('branches-section');
    var grid = document.getElementById('branches-grid');
    var hasRealBranches = !(branches.length === 1 && branches[0] === '');

    if (!hasRealBranches) {
      section.hidden = true;
      grid.innerHTML = '';
      state.branch = '';
      renderBreadcrumb();
      if (autoOpen) openDivisionModal(false);
      return;
    }

    state.branch = null;
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
        renderBreadcrumb();
        openDivisionModal(false);
      });
      grid.appendChild(tile);
    });

    document.getElementById('branches-meta').textContent = branches.length + (branches.length === 1 ? ' branch' : ' branches');
    section.hidden = false;
    renderBreadcrumb();
  }

  function renderYears(schoolId) {
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
        renderBreadcrumb();
        updateBranches(schoolId, year, true);
      });
      grid.appendChild(tile);
    });

    document.getElementById('years-section').hidden = years.length === 0;
    document.getElementById('years-meta').textContent = years.length + (years.length === 1 ? ' year' : ' years');

    if (years.length) {
      state.year = years[0];
      grid.firstChild.classList.add('active');
      updateBranches(schoolId, years[0], false);
    }
    renderBreadcrumb();
  }

  document.querySelectorAll('.school-tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
      document.querySelectorAll('.school-tile').forEach(function (t) { t.classList.remove('active'); });
      tile.classList.add('active');
      state.school = tile.dataset.school;
      state.year = null;
      state.branch = null;
      renderYears(state.school);
    });
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

  function openDivisionModal(refresh) {
    var pick = firstAvailableSelection();
    if (!pick.schoolId || !pick.year) return;

    var url = 'api/division.php?school=' + encodeURIComponent(pick.schoolId) +
      '&year=' + encodeURIComponent(pick.year) +
      '&branch=' + encodeURIComponent(pick.branch || '') +
      (refresh ? '&refresh=1' : '');

    fetch(url).then(function (r) { return r.json(); }).then(function (data) {
      document.getElementById('modal-subtitle').textContent =
        data.schoolName + ' · ' + data.year + (data.branch ? ' · ' + data.branch : '') + ' · ' + data.date;

      var grid = document.getElementById('division-grid');
      grid.innerHTML = '';
      data.divisions.forEach(function (d) {
        var divPct = pct(d.present, d.strength);
        var row = document.createElement('div');
        row.className = 'division-row';
        row.innerHTML =
          '<div class="division-row-top">' +
            '<span class="division-name">Division ' + d.division + '</span>' +
            '<span class="division-count">' + d.present + '<span class="division-count-sep">/</span>' + d.strength +
              ' <span class="att-pct ' + attClass(divPct) + '">' + divPct + '%</span></span>' +
          '</div>' +
          '<div class="division-bar"><div class="division-bar-fill ' + attClass(divPct) +
            '" style="width:' + divPct + '%"></div></div>';
        grid.appendChild(row);
      });

      var totalPct = pct(data.total.present, data.total.strength);
      document.getElementById('division-total').innerHTML =
        '<span>Total present</span><span class="division-count">' + data.total.present +
        '<span class="division-count-sep">/</span>' + data.total.strength + ' · ' + totalPct + '%</span>';

      showModal();
    });
  }

  document.getElementById('present-today-card').addEventListener('click', function () { openDivisionModal(false); });
  document.getElementById('modal-refresh').addEventListener('click', function () { openDivisionModal(true); });
  document.getElementById('modal-close').addEventListener('click', hideModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) hideModal();
  });
})();
