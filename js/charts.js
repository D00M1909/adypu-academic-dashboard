// The three charts under "Insights". No chart library: each is one small SVG,
// and a CDN dependency is not worth the page weight on a host that already
// fights to serve PHP.
//
// Everything is drawn from what index.php already put on the page:
//   ATTENDANCE_DATA  the range's tree, for the donut
//   ATTENDANCE_DAYS  date -> class key -> time -> present, per-day and per-lecture
//   TREND_DAYS       the same, for the 30 days ending on the range's last day,
//                    which the two daily charts draw whatever the range is
//   CLASS_STRENGTH   class key -> strength, the denominator for those days
// so a drill-down rescopes every chart without another request.
// A division is normally a letter, and reads best as "Division A". Some schools
// name theirs after the stream instead ("Medical Biotechnology"), where the
// prefix is noise. Global on purpose: dashboard.js loads after this and labels
// divisions in four more places.
function divisionLabel(name) {
  return String(name).length > 2 ? String(name) : 'Division ' + name;
}

// Whether a school's strengths are invented rather than counted. Such a school
// may not state an enrolment as fact, so its unreported tiles and rows say only
// that nobody reported. Mirrors is_placeholder_school() in structure.php, whose
// answer index.php ships on window.SCHOOLS. Global on purpose: dashboard.js
// draws the division tiles and modal rows the same way.
function placeholderSchool(id) {
  var s = window.SCHOOLS && window.SCHOOLS[id];
  return !!(s && s.placeholder);
}

// A school the drill-down walks program first (school > program > year), and a
// year it lists every division of at once, as structure.php declares them
// (PROGRAM_FIRST_SCHOOLS, FLAT_YEARS). Global for the same reason as the two
// above: dashboard.js draws the drill-down, this file ranks it.
function programFirstSchool(id) {
  return ((window.DASHBOARD_VIEW || {}).programFirst || []).indexOf(id) !== -1;
}
function flatYear(school, year) {
  return (((window.DASHBOARD_VIEW || {}).flatYears || {})[school] || []).indexOf(year) !== -1;
}

window.Charts = (function () {
  // Whether a class key ("school|year|branch|division") is inside the current
  // selection. A prefix match did this until a program-first school made a
  // selection with a branch and no year (Management > BBA), which no prefix of
  // "mgmt|2nd Year|BBA|A" can express. A branchless school keys on an empty
  // branch segment, which '' === '' matches as it should.
  function scopeTest(state) {
    var fixedBranch = state.branch !== null && state.branch !== undefined;
    return function (key) {
      var p = key.split('|');
      return (!state.school || p[0] === state.school) &&
        (!state.year || p[1] === state.year) &&
        (!fixedBranch || p[2] === state.branch) &&
        (!state.division || p[3] === state.division);
    };
  }

  function attClass(pct) {
    if (pct >= 75) return 'att-good';
    if (pct >= 70) return 'att-warn';
    return 'att-low';
  }

  function pct(present, strength) {
    return strength ? Math.round((present / strength) * 100) : 0;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  // A class's number for one day: its latest reading, mirroring day_present()
  // in includes/attendance.php — change both together. The cache holds one
  // reading per lecture; a bare number is a cache written before times existed.
  function dayPresent(readings) {
    if (typeof readings === 'number') return readings;
    var times = Object.keys(readings).sort();
    return times.length ? readings[times[times.length - 1]] : 0;
  }

  // Every reading for one class in the range, oldest first. The same list the
  // modal gets from api/division.php, built here from the days already on the
  // page so the printed report needs no request of its own.
  function classReadings(key) {
    var days = window.ATTENDANCE_DAYS || {};
    var out = [];
    Object.keys(days).sort().forEach(function (date) {
      var readings = days[date][key];
      if (readings === undefined) return;
      if (typeof readings === 'number') {
        out.push({ date: date, time: '', present: readings });
        return;
      }
      Object.keys(readings).sort().forEach(function (time) {
        out.push({ date: date, time: time, present: readings[time] });
      });
    });
    return out;
  }

  // "16:00" -> "4:00 to 5:00 PM". The cache keys a reading by the lecture's
  // START, in 24-hour time, because that is what sorts; this is the only place
  // it turns into something to read. Every teaching slot in the timetable is
  // exactly one hour, in the 1st Year grid and the 2nd/3rd/4th Year one alike,
  // so the end is derived instead of stored.
  //
  // The meridiem is printed once when both ends share it ("9:30 to 10:30 AM")
  // and twice when they don't, or the 11:30 lecture would read as 11:30 PM.
  //
  // ponytail: one-hour slots assumed. Store the end beside the start in
  // day_map() if a two-hour lab ever has to read correctly.
  function slotLabel(time) {
    // WHOLE_DAY in includes/attendance.php: one reading for a class that met all day.
    if (time === 'day') return 'Whole day';
    var bits = /^(\d{1,2}):(\d{2})$/.exec(time);
    if (!bits) return time;
    var start = Number(bits[1]), end = (start + 1) % 24;
    function clock(h) { return (h % 12 || 12) + ':' + bits[2]; }
    function half(h) { return h < 12 ? 'AM' : 'PM'; }
    return clock(start) + (half(start) === half(end) ? '' : ' ' + half(start)) +
      ' to ' + clock(end) + ' ' + half(end);
  }

  function shortDate(iso) {
    var d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
  }

  // The weekday earns its space: a Sunday with three classes reporting is a
  // different story from a Tuesday with three, and only one of them is a
  // problem.
  function longDate(iso) {
    var d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
  }

  function empty(el, message) {
    el.innerHTML = '<p class="chart-empty">' + esc(message) + '</p>';
  }

  // One entry per day in the range that has at least one reporting class
  // inside the scope: present, the strength of just those classes, and how
  // many of the scope's classes they were. A day nobody reported is left out
  // rather than drawn as a zero, which would read as total absence.
  function dailySeries(inScope, days) {
    days = days || window.ATTENDANCE_DAYS || {};
    var strengths = window.CLASS_STRENGTH || {};
    return Object.keys(days).sort().map(function (date) {
      var present = 0, strength = 0, reported = 0;
      Object.keys(days[date]).forEach(function (key) {
        if (!inScope(key)) return;
        // Capped at the class's strength, exactly as aggregate_days() caps it:
        // more present than enrolled is a wrong enrolment, and uncapped it drew
        // a 211% day and a negative absent count. Change both together.
        present += Math.min(dayPresent(days[date][key]), strengths[key] || Infinity);
        strength += strengths[key] || 0;
        reported++;
      });
      return { date: date, present: present, strength: strength, reported: reported };
    }).filter(function (d) { return d.reported > 0; });
  }

  // The month the two daily charts draw. Falls back to the range's own days
  // where a page (or a test) supplies no separate month.
  function trendDays() {
    return window.TREND_DAYS || window.ATTENDANCE_DAYS || {};
  }

  // A y-axis gridline with its label, shared by both daily charts.
  function gridline(x1, x2, y, label, dashed) {
    return '<line class="chart-gridline' + (dashed ? ' is-rule' : '') + '" x1="' + x1 + '" x2="' + x2 + '" y1="' + y.toFixed(1) + '" y2="' + y.toFixed(1) + '"/>' +
      '<text class="chart-tick" x="' + (x1 - 6) + '" y="' + (y + 4).toFixed(1) + '" text-anchor="end">' + label + '</text>';
  }

  function classesInScope(inScope) {
    return Object.keys(window.CLASS_STRENGTH || {}).filter(function (key) {
      return inScope(key);
    }).length;
  }

  // Present against absent, for whatever is selected. The hole carries the
  // percentage, so the ring is a picture of a number that is also readable.
  function renderDonut(el, totals) {
    if (!totals.strengthReported) {
      empty(el, 'No class in this selection has reported yet.');
      return;
    }
    var p = pct(totals.present, totals.strengthReported);
    var absent = totals.strengthReported - totals.present;
    var r = 54, c = 2 * Math.PI * r;
    var dash = (Math.min(p, 100) / 100) * c;

    el.innerHTML =
      '<svg class="donut" viewBox="0 0 140 140" role="img" aria-label="' +
        p + '% present">' +
        '<circle cx="70" cy="70" r="' + r + '" class="donut-track"/>' +
        '<circle cx="70" cy="70" r="' + r + '" class="donut-value ' + attClass(p) + '"' +
          ' stroke-dasharray="' + dash.toFixed(1) + ' ' + (c - dash).toFixed(1) + '"' +
          ' transform="rotate(-90 70 70)"/>' +
        '<text x="70" y="70" class="donut-pct ' + attClass(p) + '">' + p + '%</text>' +
        '<text x="70" y="90" class="donut-sub">present</text>' +
      '</svg>' +
      '<ul class="chart-legend">' +
        '<li><span class="swatch ' + attClass(p) + '"></span>Present<b>' + totals.present + '</b></li>' +
        '<li><span class="swatch swatch-absent"></span>Absent<b>' + absent + '</b></li>' +
      '</ul>';
  }

  // Attendance percentage per day, over the last 30 days. A single number can
  // only say where a school is; this says which way it is going.
  function renderTrend(el, inScope) {
    var series = dailySeries(inScope, trendDays());
    if (!series.length) {
      empty(el, 'No days reported in the last 30 days.');
      return;
    }
    if (series.length === 1) {
      var only = pct(series[0].present, series[0].strength);
      el.innerHTML = '<p class="chart-single"><b class="att-pct ' + attClass(only) + '" title="' +
        esc(shortDate(series[0].date)) + ': ' + only + '% (' + series[0].reported + ' classes)">' +
        only + '%</b>' + esc(shortDate(series[0].date)) + '</p>';
      return;
    }

    var w = 400, h = 230, padL = 38, padR = 14, padT = 16, padB = 28, n = series.length;
    function x(i) { return padL + (i * (w - padL - padR)) / (n - 1); }
    function y(v) { return padT + ((100 - Math.min(v, 100)) * (h - padT - padB)) / 100; }
    var pts = series.map(function (d, i) {
      return { x: x(i), y: y(pct(d.present, d.strength)), d: d };
    });
    var line = pts.map(function (p) { return p.x.toFixed(1) + ',' + p.y.toFixed(1); }).join(' ');
    var area = x(0).toFixed(1) + ',' + y(0).toFixed(1) + ' ' + line + ' ' + x(n - 1).toFixed(1) + ',' + y(0).toFixed(1);
    // Three dates are enough to place a month: its ends and the middle.
    var labelled = {};
    labelled[0] = 'start';
    labelled[Math.floor((n - 1) / 2)] = 'middle';
    labelled[n - 1] = 'end';
    var last = pts[n - 1], lastPct = pct(last.d.present, last.d.strength);

    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Attendance percentage by day, last 30 days">' +
        [0, 25, 50, 75, 100].map(function (v) { return gridline(padL, w - padR, y(v), v + '%', v === 75); }).join('') +
        '<polygon class="trend-area" points="' + area + '"/>' +
        '<polyline class="trend-line" points="' + line + '"/>' +
        pts.map(function (p, i) {
          var v = pct(p.d.present, p.d.strength);
          var tick = labelled[i] === undefined ? '' :
            '<text class="chart-tick' + (i === n - 1 ? ' is-now' : '') + '" x="' + p.x.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="' + labelled[i] + '">' + esc(shortDate(p.d.date)) + '</text>';
          return '<circle class="trend-dot' + (i === n - 1 ? ' is-now' : '') + '" cx="' + p.x.toFixed(1) + '" cy="' + p.y.toFixed(1) + '" r="' + (i === n - 1 ? 4.5 : 2.5) + '">' +
            '<title>' + esc(shortDate(p.d.date)) + ': ' + v + '% (' + p.d.reported + ' classes)</title></circle>' + tick;
        }).join('') +
        // Above the last point, unless that would run off the top of the chart.
        '<text class="chart-callout" x="' + (last.x - 8).toFixed(1) + '" y="' + (last.y - 10 < padT ? last.y + 18 : last.y - 10).toFixed(1) + '" text-anchor="end">' + lastPct + '%</text>' +
      '</svg>';
  }

  // How many of the scope's classes filled the form in, per day, over the last
  // 30 days. While the tool is being rolled out this is the number that moves.
  // A 95% attendance figure resting on 6 of 136 classes should be visible as
  // exactly that; the count for each day is in its tooltip.
  function renderCompliance(el, inScope) {
    // Every day with a submission anywhere in the view, not just in the scope:
    // a day this scope missed is the whole point of the chart, and dropping it
    // let a class that reported twice in a week look like perfect compliance.
    var days = trendDays();
    var total = classesInScope(inScope);
    var series = Object.keys(days).sort().map(function (date) {
      var reported = 0;
      Object.keys(days[date]).forEach(function (key) {
        if (inScope(key)) reported++;
      });
      return { date: date, reported: reported };
    });
    if (!series.length || !total) {
      empty(el, 'No submissions in the last 30 days.');
      return;
    }

    // The axis tops out at a round number just above the busiest day, so the
    // bars use the height instead of hugging the floor of a 143-class scale.
    var peak = 0, peakAt = -1;
    series.forEach(function (d, i) { if (d.reported > peak) { peak = d.reported; peakAt = i; } });
    var step = peak <= 4 ? 1 : peak <= 10 ? 5 : peak <= 40 ? 10 : 20;
    var top = Math.max(step, Math.ceil(peak / step) * step);
    var w = 400, h = 230, padL = 30, padR = 6, padT = 22, padB = 28, n = series.length;
    var slot = (w - padL - padR) / n, bw = Math.min(slot * 0.62, 28);
    function y(v) { return padT + ((top - v) * (h - padT - padB)) / top; }
    // About four dates under the bars; more than that and they run together.
    var every = Math.max(1, Math.ceil(n / 4));

    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Classes reporting each day, last 30 days">' +
        [0, top / 2, top].map(function (v) { return gridline(padL, w - padR, y(v), String(v)); }).join('') +
        series.map(function (d, i) {
          var now = i === n - 1, bx = padL + i * slot + (slot - bw) / 2, cx = (bx + bw / 2).toFixed(1);
          return '<rect class="compliance-bar' + (now ? ' is-now' : '') + '" x="' + bx.toFixed(1) + '" y="' + y(d.reported).toFixed(1) +
              '" width="' + bw.toFixed(1) + '" height="' + (y(0) - y(d.reported)).toFixed(1) + '" rx="3">' +
              '<title>' + esc(shortDate(d.date)) + ': ' + d.reported + ' of ' + total + ' classes reported</title></rect>' +
            ((i % every === 0 && n - 1 - i >= every / 2) || now
              ? '<text class="chart-tick' + (now ? ' is-now' : '') + '" x="' + cx + '" y="' + (h - 8) + '" text-anchor="middle">' + esc(shortDate(d.date)) + '</text>' : '') +
            (now || i === peakAt
              ? '<text class="chart-callout' + (now ? '' : ' is-quiet') + '" x="' + cx + '" y="' + (y(d.reported) - 6).toFixed(1) + '" text-anchor="middle">' + d.reported + '</text>' : '');
        }).join('') +
      '</svg>';
  }

  // Every class under the current selection, flattened to one row each, with
  // the part of its path the selection does not already fix. Grouped by the
  // (year, branch) it came from, which is what decides whether the table is
  // worth showing at all: one group means the divisions grid above is already
  // this table.
  function classBreakdown(state) {
    var school = (window.ATTENDANCE_DATA || {})[state.school] || {};
    var branchFixed = state.branch !== null && state.branch !== undefined;
    var rows = [];
    var groups = {};

    Object.keys(school).forEach(function (year) {
      if (state.year && year !== state.year) return;
      Object.keys(school[year]).forEach(function (branch) {
        if (branchFixed && branch !== state.branch) return;
        groups[year + '|' + branch] = true;
        school[year][branch].forEach(function (d) {
          var path = [];
          if (!state.year) path.push(year);
          if (!branchFixed && branch) path.push(branch);
          path.push(divisionLabel(d.division));
          rows.push({
            label: path.join(' / '),
            key: [state.school, year, branch, d.division].join('|'),
            strength: d.strength,
            present: d.present,
            reported: d.reported,
            days: d.days,
          });
        });
      });
    });

    return { rows: rows, groups: Object.keys(groups).length };
  }

  // The school report. A drill-down is a thing you click; a PDF is not, so a
  // school's export has to name its classes rather than point at a grid of
  // tiles that only exists two clicks away.
  function renderBreakdown(section, meta, tbody, state) {
    var out = state.school ? classBreakdown(state) : { rows: [], groups: 0 };
    out.rows.forEach(function (r) {
      r.entries = r.reported ? classReadings(r.key) : [];
    });

    // One group is the divisions grid, which prints already — UNLESS a class in
    // it was reported more than once IN A DAY. The grid shows a division one
    // number, the latest reading, and those lectures then exist nowhere else on
    // the page; a report of a single division is exactly that case. More
    // entries than days is the test, because a class reported once a day across
    // a range is already spelled out by the Day by day table below. No school
    // selected is the whole university, where the schools grid and the ranking
    // chart are the breakdown.
    var hasLectures = out.rows.some(function (r) {
      var dates = {};
      r.entries.forEach(function (e) { dates[e.date] = true; });
      return r.entries.length > Object.keys(dates).length;
    });
    section.hidden = !out.rows.length || (out.groups < 2 && !hasLectures);
    if (section.hidden) return;

    var scope = [window.SCHOOLS[state.school].name.replace(/^School of /, '')];
    if (state.year) scope.push(state.year);
    meta.textContent = scope.join(' · ') + ' · ' + out.rows.length + ' classes';

    tbody.innerHTML = out.rows.map(function (r) {
      var p = pct(r.present, r.strength);
      // An unreported class keeps its row: it is part of the school whether or
      // not anyone filed for it, and its absence from a report is exactly the
      // thing a head of school needs to see.
      var figures = r.reported
        ? '<td>' + r.days + '</td>' +
          '<td>' + r.present + '<span class="report-table-sep">/</span>' + r.strength + '</td>' +
          '<td><span class="att-pct ' + attClass(p) + '">' + p + '%</span></td>'
        : placeholderSchool(state.school)
          ? '<td>0</td><td colspan="2"><span class="tile-unreported">Not reported</span></td>'
          : '<td>0</td><td><span class="tile-unreported">' + r.strength + '</span></td>' +
            '<td><span class="tile-unreported">Not reported</span></td>';
      // Every entry behind that number, one row per lecture. Skipped when there
      // is only one: it would just repeat the row above it. The class's own row
      // stays the latest reading, so the report reads the same way the screen
      // does before anyone drills in.
      var subs = r.entries.length < 2 ? '' : r.entries.map(function (s) {
        var sp = pct(s.present, r.strength);
        return '<tr class="report-subrow"><th scope="row">' +
          esc(shortDate(s.date) + (s.time ? ' · ' + slotLabel(s.time) : '')) + '</th>' +
          '<td></td>' +
          '<td>' + s.present + '<span class="report-table-sep">/</span>' + r.strength + '</td>' +
          '<td><span class="att-pct ' + attClass(sp) + '">' + sp + '%</span></td>' +
        '</tr>';
      }).join('');
      return '<tr><th scope="row">' + esc(r.label) + '</th>' + figures + '</tr>' + subs;
    }).join('');
  }

  // Every day in the range that reported inside the scope, as numbers rather
  // than as a shape. Same series as the trend chart, so the two can never
  // disagree, and same rule about a day nobody reported: absent from the table
  // rather than a row of zeroes that reads as an empty campus.
  //
  // No total row on purpose. A range averages rather than sums (see
  // aggregate_days in includes/attendance.php), so the only honest total is
  // the one already on the stat tiles above, and a second one computed a
  // different way here would sooner or later disagree with it in public.
  function renderDays(section, meta, tbody, inScope) {
    var series = dailySeries(inScope);
    // One day is not a breakdown: the tiles above already say it, twice.
    section.hidden = series.length < 2;
    if (section.hidden) return;

    meta.textContent = series.length + ' days with data';
    tbody.innerHTML = series.map(function (d) {
      var p = pct(d.present, d.strength);
      return '<tr>' +
        '<th scope="row">' + esc(longDate(d.date)) + '</th>' +
        '<td>' + d.reported + '</td>' +
        '<td>' + d.present + '<span class="report-table-sep">/</span>' + d.strength + '</td>' +
        '<td><span class="att-pct ' + attClass(p) + '">' + p + '%</span></td>' +
      '</tr>';
    }).join('');
  }

  // Called by dashboard.js on every selection change. totals comes from
  // scopeTotals() there rather than being recomputed here.
  function render(state, totals) {
    var inScope = scopeTest(state);
    renderDonut(document.getElementById('chart-donut'), totals);
    renderTrend(document.getElementById('chart-trend'), inScope);
    renderCompliance(document.getElementById('chart-compliance'), inScope);
    renderBreakdown(
      document.getElementById('breakdown-section'),
      document.getElementById('breakdown-meta'),
      document.getElementById('breakdown-rows'),
      state
    );
    renderDays(
      document.getElementById('daybyday-section'),
      document.getElementById('daybyday-meta'),
      document.getElementById('daybyday-rows'),
      inScope
    );
    document.getElementById('charts-scope').textContent = window.ATTENDANCE_RANGE.label;
  }

  // shortDate rides along because the modal lists readings by date too, and a
  // second copy of it in dashboard.js is a second thing to keep in step.
  return { render: render, shortDate: shortDate, slotLabel: slotLabel };
})();
