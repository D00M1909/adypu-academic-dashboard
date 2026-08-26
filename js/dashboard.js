(function () {
  var state = { school: null, year: null };

  document.querySelectorAll('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      var target = tab.dataset.tab;
      document.getElementById('tab-adypu').hidden = target !== 'adypu';
      document.getElementById('tab-partners').hidden = target !== 'partners';
    });
  });

  function renderYears(schoolId) {
    var years = Object.keys(window.ATTENDANCE_DATA[schoolId] || {});
    var grid = document.getElementById('years-grid');
    grid.innerHTML = '';
    years.forEach(function (year) {
      var tile = document.createElement('div');
      tile.className = 'tile year-tile';
      tile.dataset.year = year;
      tile.textContent = year;
      if (year === state.year) tile.classList.add('active');
      tile.addEventListener('click', function () {
        state.year = year;
        grid.querySelectorAll('.year-tile').forEach(function (t) { t.classList.remove('active'); });
        tile.classList.add('active');
        document.getElementById('years-meta').textContent = years.length + ' years · ' + year + ' selected';
        openDivisionModal(false);
      });
      grid.appendChild(tile);
    });

    document.getElementById('years-section').hidden = years.length === 0;
    document.getElementById('years-title').textContent = window.SCHOOLS[schoolId].name + ' — Years';
    document.getElementById('years-meta').textContent = years.length + ' years';

    if (years.length && !state.year) {
      state.year = years[0];
      grid.firstChild.classList.add('active');
      document.getElementById('years-meta').textContent = years.length + ' years · ' + years[0] + ' selected';
    }
  }

  document.querySelectorAll('.school-tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
      document.querySelectorAll('.school-tile').forEach(function (t) { t.classList.remove('active'); });
      tile.classList.add('active');
      state.school = tile.dataset.school;
      state.year = null;
      renderYears(state.school);
    });
  });

  function firstAvailableSchoolYear() {
    var schoolId = state.school || Object.keys(window.ATTENDANCE_DATA)[0];
    var year = state.year;
    if (schoolId && !year) {
      var years = Object.keys(window.ATTENDANCE_DATA[schoolId] || {});
      year = years[0];
    }
    return { schoolId: schoolId, year: year };
  }

  function openDivisionModal(refresh) {
    var pick = firstAvailableSchoolYear();
    if (!pick.schoolId || !pick.year) return;

    var url = 'api/division.php?school=' + encodeURIComponent(pick.schoolId) +
      '&year=' + encodeURIComponent(pick.year) +
      (refresh ? '&refresh=1' : '');

    fetch(url).then(function (r) { return r.json(); }).then(function (data) {
      document.getElementById('modal-subtitle').textContent =
        data.schoolName + ' — Division-wise Present Count (' + data.year + ')';

      var grid = document.getElementById('division-grid');
      grid.innerHTML = '';
      data.divisions.forEach(function (d) {
        var row = document.createElement('div');
        row.className = 'division-row';
        row.innerHTML = '<span>Div ' + d.division + '</span><span>' + d.present + '/' + d.strength + '</span>';
        grid.appendChild(row);
      });

      document.getElementById('division-total').innerHTML =
        '<span>Total</span><span>' + data.total.present + '/' + data.total.strength + ' · ' + data.date + '</span>';

      document.getElementById('division-modal').hidden = false;
    });
  }

  document.getElementById('present-today-card').addEventListener('click', function () { openDivisionModal(false); });
  document.getElementById('modal-refresh').addEventListener('click', function () { openDivisionModal(true); });
  document.getElementById('modal-close').addEventListener('click', function () {
    document.getElementById('division-modal').hidden = true;
  });
})();
