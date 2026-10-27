// The charts under "Insights", on the dashboard and in the report. No chart
// library: each is one small SVG or list, and a CDN dependency is not worth
// the page weight on a host that already fights to serve PHP. Every mark
// carries its hover text (data-tip) and what a click on it does (data-day,
// data-drill, data-act); bind() reads both.
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

// A school the drill-down walks program first (school > program > year), and a
// year it lists every division of at once, as structure.php declares them
// (PROGRAM_FIRST_SCHOOLS, FLAT_YEARS). Global for the same reason as
// divisionLabel(): dashboard.js draws the drill-down, this file ranks it.
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
  // one hour unless the reading says when it ended ("09:30-11:30", from the
  // Form's Till question or the app's), so the end is derived otherwise.
  //
  // The meridiem is printed once when both ends share it ("9:30 to 10:30 AM")
  // and twice when they don't, or the 11:30 lecture would read as 11:30 PM.
  function slotLabel(time) {
    // "14:15~2" is a second person's reading for the same slot (reading_slots()).
    time = String(time).replace(/~\d+$/, '');
    // WHOLE_DAY in includes/attendance.php: one reading for a class that met all day.
    if (time === 'day') return 'Whole day';
    // "09:30-11:30" is a reading that said when it ended (a two-hour lab).
    var bits = /^(\d{1,2}):(\d{2})(?:-(\d{1,2}):(\d{2}))?$/.exec(time);
    if (!bits) return time;
    var start = Number(bits[1]), end = bits[3] ? Number(bits[3]) : (start + 1) % 24;
    var endMin = bits[3] ? bits[4] : bits[2];
    function clock(h, m) { return (h % 12 || 12) + ':' + m; }
    function half(h) { return h < 12 ? 'AM' : 'PM'; }
    return clock(start, bits[2]) + (half(start) === half(end) ? '' : ' ' + half(start)) +
      ' to ' + clock(end, endMin) + ' ' + half(end);
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

  // Hover text for a chart mark. The tooltip (bind()) shows it as HTML, so
  // every piece of it is escaped once here and again as an attribute.
  function tip(html) {
    return ' data-tip="' + esc(html) + '"';
  }

  // The classes in the range's tree, flat, with their keys: what the compare
  // chart and both class lists rank.
  function classList() {
    var data = window.ATTENDANCE_DATA || {};
    var out = [];
    Object.keys(data).forEach(function (school) {
      Object.keys(data[school]).forEach(function (year) {
        Object.keys(data[school][year]).forEach(function (branch) {
          data[school][year][branch].forEach(function (d) {
            out.push({
              key: [school, year, branch, d.division].join('|'),
              school: school, year: year, branch: branch, division: d.division,
              strength: d.strength, present: d.present, reported: !!d.reported,
              estimated: !!d.estimated, days: d.days,
            });
          });
        });
      });
    });
    return out;
  }

  function schoolShort(id) {
    var s = (window.SCHOOLS || {})[id];
    return s ? s.name.replace(/^School of /, '') : id;
  }

  // A class's name with whatever the selection already fixes left off, so a
  // list inside Engineering 2nd Year reads "CSE / Division A".
  function classLabel(c, state) {
    state = state || {};
    var parts = [];
    if (!state.school) parts.push(schoolShort(c.school));
    if (!state.year) parts.push(c.year);
    if ((state.branch === null || state.branch === undefined) && c.branch) parts.push(c.branch);
    parts.push(divisionLabel(c.division));
    return parts.join(' / ');
  }

  // Present against absent, for whatever is selected. The hole carries the
  // percentage, so the ring is a picture of a number that is also readable.
  // Clicking it opens every class behind the number (the summary card's modal).
  function renderDonut(el, totals) {
    if (!totals.strengthReported) {
      empty(el, 'No class in this selection has reported yet.');
      return;
    }
    var p = pct(totals.present, totals.strengthReported);
    var absent = totals.strengthReported - totals.present;
    var r = 54, c = 2 * Math.PI * r;
    var dash = (Math.min(p, 100) / 100) * c;
    var detail = '<b>' + p + '% present</b>' + totals.present + ' present, ' + absent + ' absent<br>' +
      totals.reported + ' of ' + totals.classes + ' classes filed<em>Click to see every class</em>';

    el.innerHTML =
      '<svg class="donut chart-click" viewBox="0 0 140 140" role="button" tabindex="0" data-act="details" aria-label="' +
        p + '% present. Open every class"' + tip(detail) + '>' +
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
      '</ul>' +
      '<p class="chart-foot">' + totals.reported + ' of ' + totals.classes + ' classes filed</p>';
  }

  // A chart drawn one unit to a pixel of its card, for the cards whose width
  // changes with what else is on the row: a 400-wide drawing stretched over
  // 600px turned its labels to headlines. 400 where there is no layout (tests).
  function chartWidth(el) {
    var w = el && el.clientWidth;
    return w ? Math.max(280, Math.min(900, Math.round(w))) : 400;
  }

  // A day's column on either daily chart: the whole height is the target, so a
  // thin line or a two-pixel bar is still easy to hover and to tap.
  function dayHit(x, w, top, bottom, date, label, detail) {
    return '<rect class="chart-hitbox" x="' + x.toFixed(1) + '" y="' + top + '" width="' + w.toFixed(1) +
      '" height="' + (bottom - top) + '" data-day="' + date + '" role="button" tabindex="0" aria-label="' + esc(label) + '"' +
      tip(detail + '<em>Click to open this day</em>') + '/>';
  }

  function inRange(date) {
    var r = window.ATTENDANCE_RANGE || {};
    return !!r.from && date >= r.from && date <= r.to;
  }

  // The range on screen, shaded behind both daily charts, so a month of
  // context still says which part of it the rest of the page is about.
  function rangeBand(xs, slot, top, bottom, dates) {
    var first = -1, last = -1;
    dates.forEach(function (d, i) { if (inRange(d)) { if (first < 0) first = i; last = i; } });
    // Nothing to set apart when the range is every day drawn.
    if (first < 0 || (first === 0 && last === dates.length - 1)) return '';
    var x0 = xs[first] - slot / 2, x1 = xs[last] + slot / 2;
    return '<rect class="chart-range" x="' + x0.toFixed(1) + '" y="' + top + '" width="' + (x1 - x0).toFixed(1) + '" height="' + (bottom - top) + '" rx="4"/>';
  }

  // Attendance percentage per day, over the last 30 days. A single number can
  // only say where a school is; this says which way it is going. The dashed
  // line is the average of the seven reporting days up to each one, which is
  // what to read when single days jump about.
  function renderTrend(el, inScope) {
    var series = dailySeries(inScope, trendDays());
    if (!series.length) {
      empty(el, 'No days reported in the last 30 days.');
      return;
    }
    var total = classesInScope(inScope);
    function detail(d) {
      var v = pct(d.present, d.strength);
      return '<b>' + esc(longDate(d.date)) + '</b><span class="tip-big ' + attClass(v) + '">' + v + '%</span> present<br>' +
        d.present + ' of ' + d.strength + ' students<br>' + d.reported + ' of ' + total + ' classes filed';
    }
    if (series.length === 1) {
      var only = pct(series[0].present, series[0].strength);
      el.innerHTML = '<p class="chart-single"><b class="att-pct ' + attClass(only) + ' chart-click" data-day="' + series[0].date + '" role="button" tabindex="0" aria-label="' +
        esc(shortDate(series[0].date)) + ': ' + only + '% (' + series[0].reported + ' classes)"' + tip(detail(series[0]) + '<em>Click to open this day</em>') + '>' +
        only + '%</b>' + esc(shortDate(series[0].date)) + '<span>Only one day has data so far</span></p>';
      return;
    }

    var w = 400, h = 230, padL = 38, padR = 14, padT = 16, padB = 28, n = series.length;
    var slot = (w - padL - padR) / (n - 1);
    function x(i) { return padL + i * slot; }
    function y(v) { return padT + ((100 - Math.min(v, 100)) * (h - padT - padB)) / 100; }
    var pts = series.map(function (d, i) {
      return { x: x(i), y: y(pct(d.present, d.strength)), d: d, v: pct(d.present, d.strength) };
    });
    var line = pts.map(function (p) { return p.x.toFixed(1) + ',' + p.y.toFixed(1); }).join(' ');
    var area = x(0).toFixed(1) + ',' + y(0).toFixed(1) + ' ' + line + ' ' + x(n - 1).toFixed(1) + ',' + y(0).toFixed(1);
    var avg = n >= 7 ? pts.map(function (p, i) {
      var from = Math.max(0, i - 6), pres = 0, str = 0;
      for (var k = from; k <= i; k++) { pres += pts[k].d.present; str += pts[k].d.strength; }
      return p.x.toFixed(1) + ',' + y(pct(pres, str)).toFixed(1);
    }).join(' ') : '';
    // Three dates are enough to place a month: its ends and the middle.
    var labelled = {};
    labelled[0] = 'start';
    labelled[Math.floor((n - 1) / 2)] = 'middle';
    labelled[n - 1] = 'end';
    var last = pts[n - 1];
    var hitW = Math.max(6, slot);

    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Attendance percentage by day, last 30 days">' +
        rangeBand(pts.map(function (p) { return p.x; }), Math.min(hitW, 24), padT, h - padB, series.map(function (d) { return d.date; })) +
        [0, 25, 50, 75, 100].map(function (v) { return gridline(padL, w - padR, y(v), v + '%', v === 75); }).join('') +
        '<polygon class="trend-area" points="' + area + '"/>' +
        '<polyline class="trend-line" points="' + line + '"/>' +
        (avg ? '<polyline class="trend-avg" points="' + avg + '"/>' : '') +
        pts.map(function (p, i) {
          var tick = labelled[i] === undefined ? '' :
            '<text class="chart-tick' + (i === n - 1 ? ' is-now' : '') + '" x="' + p.x.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="' + labelled[i] + '">' + esc(shortDate(p.d.date)) + '</text>';
          return '<g class="chart-day">' +
            '<line class="chart-guide" x1="' + p.x.toFixed(1) + '" x2="' + p.x.toFixed(1) + '" y1="' + padT + '" y2="' + (h - padB) + '"/>' +
            '<circle class="trend-dot' + (i === n - 1 ? ' is-now' : '') + '" cx="' + p.x.toFixed(1) + '" cy="' + p.y.toFixed(1) + '" r="' + (i === n - 1 ? 4.5 : 2.5) + '"/>' +
            dayHit(p.x - hitW / 2, hitW, padT, h - padB, p.d.date, shortDate(p.d.date) + ': ' + p.v + '% (' + p.d.reported + ' classes)', detail(p.d)) +
            '</g>' + tick;
        }).join('') +
        // Above the last point, unless that would run off the top of the chart.
        '<text class="chart-callout" x="' + (last.x - 8).toFixed(1) + '" y="' + (last.y - 10 < padT ? last.y + 18 : last.y - 10).toFixed(1) + '" text-anchor="end">' + last.v + '%</text>' +
      '</svg>' +
      (avg ? '<p class="chart-key"><span class="key-line"></span>Each day<span class="key-line is-avg"></span>7-day average</p>' : '');
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
    var att = {};
    dailySeries(inScope, days).forEach(function (d) { att[d.date] = d; });
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
        rangeBand(series.map(function (d, i) { return padL + i * slot + slot / 2; }), slot, padT, h - padB, series.map(function (d) { return d.date; })) +
        [0, top / 2, top].map(function (v) { return gridline(padL, w - padR, y(v), String(v)); }).join('') +
        series.map(function (d, i) {
          var now = i === n - 1, bx = padL + i * slot + (slot - bw) / 2, cx = (bx + bw / 2).toFixed(1);
          var a = att[d.date], share = Math.round(d.reported / total * 100);
          var detail = '<b>' + esc(longDate(d.date)) + '</b><span class="tip-big">' + d.reported + '</span> of ' + total + ' classes filed (' + share + '%)' +
            (a ? '<br>Attendance ' + pct(a.present, a.strength) + '% of those' : '');
          return '<g class="chart-day">' +
            '<rect class="compliance-bar' + (now ? ' is-now' : '') + (inRange(d.date) ? ' is-range' : '') + '" x="' + bx.toFixed(1) + '" y="' + y(d.reported).toFixed(1) +
              '" width="' + bw.toFixed(1) + '" height="' + (y(0) - y(d.reported)).toFixed(1) + '" rx="3"/>' +
            dayHit(padL + i * slot, slot, padT, h - padB, d.date, shortDate(d.date) + ': ' + d.reported + ' of ' + total + ' classes reported', detail) +
            '</g>' +
            ((i % every === 0 && n - 1 - i >= every / 2) || now
              ? '<text class="chart-tick' + (now ? ' is-now' : '') + '" x="' + cx + '" y="' + (h - 8) + '" text-anchor="middle">' + esc(shortDate(d.date)) + '</text>' : '') +
            (now || i === peakAt
              ? '<text class="chart-callout' + (now ? '' : ' is-quiet') + '" x="' + cx + '" y="' + (y(d.reported) - 6).toFixed(1) + '" text-anchor="middle">' + d.reported + '</text>' : '');
        }).join('') +
      '</svg>' +
      '<p class="chart-foot">Out of ' + total + ' classes</p>';
  }

  // What the compare chart sets side by side: the level under the selection.
  // Each group says how to drill into it, in the shape dashboard.js's
  // selectSchool() takes.
  function compareLevel(state) {
    var s = state || {};
    var fixedBranch = s.branch !== null && s.branch !== undefined;
    var noun = ((window.DASHBOARD_VIEW || {}).noun || 'school');
    if (s.division) return null;
    if (!s.school) return { title: 'Compare ' + noun + 's', key: function (c) { return c.school; },
      label: function (c) { return schoolShort(c.school); }, drill: function (c) { return { school: c.school }; } };
    var byDiv = { title: 'Compare divisions', key: function (c) { return c.branch + '|' + c.division; },
      label: function (c) { return (fixedBranch || !c.branch ? '' : c.branch + ' / ') + divisionLabel(c.division); },
      drill: function (c) { return { school: c.school, year: c.year, branch: c.branch, division: c.division }; } };
    if (programFirstSchool(s.school)) {
      if (!fixedBranch) return { title: 'Compare programs', key: function (c) { return c.branch; },
        label: function (c) { return c.branch; }, drill: function (c) { return { school: c.school, branch: c.branch }; } };
      if (!s.year) return { title: 'Compare years', key: function (c) { return c.year; },
        label: function (c) { return c.year; }, drill: function (c) { return { school: c.school, year: c.year, branch: c.branch }; } };
      return byDiv;
    }
    if (!s.year) return { title: 'Compare years', key: function (c) { return c.year; },
      label: function (c) { return c.year; }, drill: function (c) { return { school: c.school, year: c.year }; } };
    if (!fixedBranch && !flatYear(s.school, s.year)) {
      var branches = classList().filter(function (c) { return c.school === s.school && c.year === s.year && c.branch; });
      if (branches.length) return { title: 'Compare branches', key: function (c) { return c.branch; },
        label: function (c) { return c.branch; }, drill: function (c) { return { school: c.school, year: c.year, branch: c.branch }; } };
    }
    return byDiv;
  }

  // The groups under the selection, best attendance first, the ones nobody
  // filed for last. Each bar is the share present of the classes that filed,
  // with how many filed beside it, the same honesty as the summary card.
  function renderCompare(el, inScope, level, caption) {
    var card = el.closest ? el.closest('.chart-card') : null;
    var groups = {}, order = [];
    if (level) classList().forEach(function (c) {
      if (!inScope(c.key)) return;
      var k = level.key(c);
      if (!groups[k]) { groups[k] = { label: level.label(c), drill: level.drill(c), classes: 0, filed: 0, present: 0, strength: 0 }; order.push(k); }
      var g = groups[k];
      g.classes++;
      if (c.reported) { g.filed++; g.present += Math.min(c.present, c.strength); g.strength += c.strength; }
    });
    if (card) card.hidden = order.length < 2;
    if (caption && level) caption.textContent = level.title;
    if (order.length < 2) { el.innerHTML = ''; return; }
    var list = order.map(function (k) { return groups[k]; });
    list.sort(function (a, b) {
      if (!!a.filed !== !!b.filed) return a.filed ? -1 : 1;
      return pct(b.present, b.strength) - pct(a.present, a.strength);
    });
    el.innerHTML = '<ol class="rank-list">' + list.map(function (g) {
      var v = pct(g.present, g.strength);
      var detail = '<b>' + esc(g.label) + '</b>' + (g.filed
        ? '<span class="tip-big ' + attClass(v) + '">' + v + '%</span> present<br>' + g.present + ' of ' + g.strength + ' students<br>'
        : 'Nothing filed<br>') + g.filed + ' of ' + g.classes + ' classes filed<em>Click to open</em>';
      return '<li><button class="rank-row" type="button" data-drill="' + esc(JSON.stringify(g.drill)) + '"' + tip(detail) + '>' +
        '<span class="rank-label">' + esc(g.label) + '</span>' +
        '<span class="rank-track"><span class="rank-fill ' + (g.filed ? attClass(v) : '') + '" style="width:' + (g.filed ? v : 0) + '%"></span></span>' +
        '<span class="rank-value">' + (g.filed ? '<b class="att-pct ' + attClass(v) + '">' + v + '%</b>' : '<b class="rank-none">None</b>') +
          '<small>' + g.filed + '/' + g.classes + ' filed</small></span>' +
      '</button></li>';
    }).join('') + '</ol>' +
    '<p class="chart-key">Attendance of the classes that filed</p>';
  }

  // When in the day attendance is filed, and how full the room is then, over
  // the range on screen. Every lecture reading counts, not just a day's latest,
  // so this is the one chart that shows the afternoon emptying out.
  function renderHours(el, inScope) {
    var days = window.ATTENDANCE_DAYS || {};
    var strengths = window.CLASS_STRENGTH || {};
    var hours = {};
    Object.keys(days).forEach(function (date) {
      Object.keys(days[date]).forEach(function (key) {
        if (!inScope(key)) return;
        var readings = days[date][key];
        if (typeof readings !== 'object') return;
        Object.keys(readings).forEach(function (time) {
          var m = /^(\d{2}):\d{2}/.exec(time);
          if (!m) return;
          var hr = Number(m[1]), s = strengths[key] || 0;
          // A Form answer of "2:00" means the afternoon; anything still
          // outside the teaching day is a typo and would stretch the axis.
          if (hr >= 1 && hr <= 7) hr += 12;
          if (hr < 7 || hr > 18) return;
          var b = hours[hr] || (hours[hr] = { present: 0, strength: 0, count: 0 });
          b.present += Math.min(readings[time], s || Infinity);
          b.strength += s;
          b.count++;
        });
      });
    });
    var keys = Object.keys(hours).map(Number).sort(function (a, b) { return a - b; });
    if (!keys.length) {
      empty(el, 'No lecture times filed in this range.');
      return;
    }
    var list = [];
    for (var hr = keys[0]; hr <= keys[keys.length - 1]; hr++) list.push({ hr: hr, b: hours[hr] });
    function clock(hh) { return (hh % 12 || 12) + (hh < 12 ? ' AM' : ' PM'); }
    var w = chartWidth(el), h = 230, padL = 38, padR = 6, padT = 22, padB = 28, n = list.length;
    var slot = (w - padL - padR) / n, bw = Math.min(slot * 0.62, 30);
    function y(v) { return padT + ((100 - Math.min(v, 100)) * (h - padT - padB)) / 100; }
    var low = null;
    list.forEach(function (d) {
      if (d.b && (!low || pct(d.b.present, d.b.strength) < pct(low.b.present, low.b.strength))) low = d;
    });
    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Attendance by lecture hour">' +
        [0, 50, 100].map(function (v) { return gridline(padL, w - padR, y(v), v + '%', false); }).join('') +
        gridline(padL, w - padR, y(75), '', true) +
        list.map(function (d, i) {
          var cx = padL + i * slot + slot / 2, label = '<text class="chart-tick" x="' + cx.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="middle">' + (d.hr % 12 || 12) + '</text>';
          if (!d.b) return label;
          var v = pct(d.b.present, d.b.strength);
          var detail = '<b>' + clock(d.hr) + ' to ' + clock(d.hr + 1) + '</b><span class="tip-big ' + attClass(v) + '">' + v + '%</span> present<br>' +
            d.b.count + ' lecture' + (d.b.count === 1 ? '' : 's') + ' filed';
          return '<g class="chart-day" tabindex="0" role="img" aria-label="' + esc(clock(d.hr) + ': ' + v + '% present') + '"' + tip(detail) + '>' +
            '<rect class="chart-hitbox" x="' + (padL + i * slot).toFixed(1) + '" y="' + padT + '" width="' + slot.toFixed(1) + '" height="' + (h - padT - padB) + '"/>' +
            '<rect class="hour-bar ' + attClass(v) + '" x="' + (cx - bw / 2).toFixed(1) + '" y="' + y(v).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + (y(0) - y(v)).toFixed(1) + '" rx="3"/>' +
            (d === low && n > 1 ? '<text class="chart-callout is-quiet" x="' + cx.toFixed(1) + '" y="' + (y(v) - 6).toFixed(1) + '" text-anchor="middle">' + v + '%</text>' : '') +
            '</g>' + label;
        }).join('') +
      '</svg>' +
      '<p class="chart-foot">Hour the lecture started' + (low && n > 1 ? ' · lowest at ' + clock(low.hr) : '') + '</p>';
  }

  // Attendance by day of the week over the last 30 days: each weekday's
  // reporting days pooled, so a Monday dip or an empty Saturday shows up as a
  // pattern rather than as one bad day. A bar opens the latest such day.
  function renderWeekdays(el, inScope) {
    var card = el.closest ? el.closest('.chart-card') : null;
    var names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var by = {};
    dailySeries(inScope, trendDays()).forEach(function (d) {
      var wd = new Date(d.date + 'T00:00:00').getDay();
      var b = by[wd] || (by[wd] = { present: 0, strength: 0, days: 0, classes: 0, latest: d.date });
      b.present += d.present;
      b.strength += d.strength;
      b.days++;
      b.classes += d.reported;
      if (d.date > b.latest) b.latest = d.date;
    });
    // Monday first, as a timetable reads.
    var list = [1, 2, 3, 4, 5, 6, 0].filter(function (wd) { return by[wd]; });
    if (card) card.hidden = list.length < 2;
    if (list.length < 2) { el.innerHTML = ''; return; }
    var w = chartWidth(el), h = 230, padL = 38, padR = 6, padT = 22, padB = 28, n = list.length;
    var slot = (w - padL - padR) / n, bw = Math.min(slot * 0.56, 40);
    function y(v) { return padT + ((100 - Math.min(v, 100)) * (h - padT - padB)) / 100; }
    var best = null, worst = null;
    list.forEach(function (wd) {
      var v = pct(by[wd].present, by[wd].strength);
      if (!best || v > best.v) best = { wd: wd, v: v };
      if (!worst || v < worst.v) worst = { wd: wd, v: v };
    });
    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Attendance by weekday, last 30 days">' +
        [0, 50, 100].map(function (v) { return gridline(padL, w - padR, y(v), v + '%', false); }).join('') +
        gridline(padL, w - padR, y(75), '', true) +
        list.map(function (wd, i) {
          var b = by[wd], v = pct(b.present, b.strength), cx = padL + i * slot + slot / 2;
          var detail = '<b>' + names[wd] + 's</b><span class="tip-big ' + attClass(v) + '">' + v + '%</span> present<br>' +
            b.days + ' day' + (b.days === 1 ? '' : 's') + ' with data, ' + Math.round(b.classes / b.days) + ' classes filed on average';
          return '<g class="chart-day">' +
            '<rect class="hour-bar ' + attClass(v) + '" x="' + (cx - bw / 2).toFixed(1) + '" y="' + y(v).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + (y(0) - y(v)).toFixed(1) + '" rx="3"/>' +
            dayHit(padL + i * slot, slot, padT, h - padB, b.latest, names[wd] + ': ' + v + '% present', detail + '<br>Latest: ' + esc(shortDate(b.latest))) +
            '<text class="chart-callout is-quiet" x="' + cx.toFixed(1) + '" y="' + (y(v) - 6).toFixed(1) + '" text-anchor="middle">' + v + '%</text>' +
            '</g>' +
            '<text class="chart-tick" x="' + cx.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="middle">' + names[wd].slice(0, 3) + '</text>';
        }).join('') +
      '</svg>' +
      '<p class="chart-foot">' + (best.wd === worst.wd ? 'Pooled over each weekday'
        : 'Best ' + names[best.wd] + ' · weakest ' + names[worst.wd]) + '</p>';
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
            estimated: !!d.estimated,
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

    var multiDay = out.rows.some(function (r) {
      return r.entries.some(function (e) { return e.date !== r.entries[0].date; });
    });
    tbody.innerHTML = out.rows.map(function (r) {
      var p = pct(r.present, r.strength);
      var label = '<th scope="row"><span class="bd-class">' + esc(r.label) + '</span>' +
        (r.reported ? '<small class="bd-note">' + (r.days > 1 ? 'Average of ' + r.days + ' days' : '1 day filed') +
          (r.estimated ? ' · class size not confirmed' : '') + '</small>' : '') + '</th>';
      // An unreported class keeps its row: it is part of the school whether or
      // not anyone filed for it, and its absence from a report is exactly the
      // thing a head of school needs to see.
      if (!r.reported) {
        return '<tr class="bd-missing">' + label +
          '<td class="bd-lectures"><span class="tile-unreported">Not reported</span></td>' +
          '<td>' + (r.estimated ? '' : '<span class="tile-unreported">' + r.strength + ' students</span>') + '</td><td></td></tr>';
      }
      // Every lecture behind that number, as a chip with its start time and
      // count, grouped by day when the range has more than one. The class's own
      // figure stays the latest reading, as on the tiles.
      var byDate = [];
      r.entries.forEach(function (e) {
        var g = byDate[byDate.length - 1];
        if (!g || g.date !== e.date) byDate.push(g = { date: e.date, list: [] });
        g.list.push(e);
      });
      var lectures = byDate.map(function (g) {
        return '<span class="bd-day">' + (multiDay ? '<span class="bd-date">' + esc(shortDate(g.date)) + '</span>' : '') +
          g.list.map(function (e) {
            var sp = pct(e.present, r.strength);
            var when = e.time ? slotLabel(e.time) : 'Lecture';
            return '<span class="lec-chip ' + attClass(sp) + '" tabindex="0"' +
              tip('<b>' + esc(shortDate(e.date) + ' · ' + when) + '</b><span class="tip-big ' + attClass(sp) + '">' + sp + '%</span> present<br>' +
                e.present + ' of ' + r.strength + (/~\d+$/.test(e.time) ? '<br>A second faculty filed this lecture' : '')) + '>' +
              '<span class="lec-time">' + esc(e.time ? when.replace(/ to .*/, '').replace(/ [AP]M$/, '') : '·') + '</span><b>' + e.present + '</b></span>';
          }).join('') + '</span>';
      }).join('');
      return '<tr>' + label +
        '<td class="bd-lectures">' + lectures + '</td>' +
        '<td>' + r.present + '<span class="report-table-sep">/</span>' + r.strength + '</td>' +
        '<td><span class="bd-att"><span class="bd-bar"><i class="' + attClass(p) + '" style="width:' + Math.min(100, p) + '%"></i></span>' +
          '<span class="att-pct ' + attClass(p) + '">' + p + '%</span></span></td>' +
      '</tr>';
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
  var redraw = null;
  function render(state, totals) {
    redraw = function () { render(state, totals); };
    var inScope = scopeTest(state);
    var $ = function (id) { return document.getElementById(id); };
    renderDonut($('chart-donut'), totals);
    renderTrend($('chart-trend'), inScope);
    renderCompliance($('chart-compliance'), inScope);
    renderCompare($('chart-compare'), inScope, compareLevel(state), $('chart-compare-title'));
    renderHours($('chart-hours'), inScope);
    renderWeekdays($('chart-weekdays'), inScope);
    renderBreakdown($('breakdown-section'), $('breakdown-meta'), $('breakdown-rows'), state);
    renderDays($('daybyday-section'), $('daybyday-meta'), $('daybyday-rows'), inScope);
    $('charts-scope').textContent = window.ATTENDANCE_RANGE.label;
  }

  // The report's insights (report.php): the same charts over its own picks,
  // several schools and years at once, which no drill-down state can say.
  // The compare chart sets the schools side by side, or with one school
  // picked, its years.
  function renderReport(schools, years) {
    redraw = function () { renderReport(schools, years); };
    var inScope = function (key) {
      var p = key.split('|');
      return schools.indexOf(p[0]) !== -1 && years.indexOf(p[1]) !== -1;
    };
    var $ = function (id) { return document.getElementById(id); };
    var level = schools.length > 1
      ? { title: 'Compare schools', key: function (c) { return c.school; }, label: function (c) { return schoolShort(c.school); },
          drill: function (c) { return { school: c.school }; } }
      : { title: 'Compare years', key: function (c) { return c.year; }, label: function (c) { return c.year; },
          drill: function (c) { return { year: c.year }; } };
    renderTrend($('chart-trend'), inScope);
    renderCompliance($('chart-compliance'), inScope);
    renderCompare($('chart-compare'), inScope, level, $('chart-compare-title'));
    renderHours($('chart-hours'), inScope);
  }

  // The tooltip every chart shares, and what a click on a chart does. A page
  // passes its own actions: day(date) for a day on either daily chart,
  // drill({school, year, branch, division}) for a bar or a class in a list,
  // details() for the donut. Hover shows the tip; on a touch screen the first
  // tap shows it and the second one acts, so nothing fires by accident.
  function bind(actions) {
    var tipEl = document.createElement('div');
    tipEl.className = 'chart-tip';
    tipEl.setAttribute('role', 'tooltip');
    tipEl.hidden = true;
    document.body.appendChild(tipEl);
    var current = null;

    function place(x, y) {
      var r = tipEl.getBoundingClientRect();
      var left = Math.min(window.innerWidth - r.width - 8, Math.max(8, x - r.width / 2));
      var top = y - r.height - 14;
      if (top < 8) top = y + 18;
      tipEl.style.left = left + 'px';
      tipEl.style.top = top + 'px';
    }
    // The mark that lights up: the whole day column of a chart, or the
    // element itself (a lecture chip).
    function hot(t) { return t.closest('.chart-day, .rank-row, svg') || t; }
    function show(target, x, y) {
      if (current !== target) {
        if (current) hot(current).classList.remove('is-hot');
        current = target;
        tipEl.innerHTML = target.getAttribute('data-tip');
        tipEl.hidden = false;
        hot(target).classList.add('is-hot');
      }
      place(x, y);
    }
    function hide() {
      if (current) hot(current).classList.remove('is-hot');
      current = null;
      tipEl.hidden = true;
    }
    function act(t) {
      if (t.dataset.day && actions.day) actions.day(t.dataset.day);
      else if (t.dataset.drill && actions.drill) actions.drill(JSON.parse(t.dataset.drill));
      else if (t.dataset.act === 'details' && actions.details) actions.details();
      hide();
    }

    document.addEventListener('pointermove', function (e) {
      if (e.pointerType === 'touch') return;
      var t = e.target.closest && e.target.closest('[data-tip]');
      if (t) show(t, e.clientX, e.clientY); else if (current) hide();
    });
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-tip]');
      if (!t) { hide(); return; }
      var clickable = t.dataset.day || t.dataset.drill || t.dataset.act;
      // A tap shows the tip first; tapping the same mark again acts on it.
      if (e.pointerType === 'touch' || (!e.pointerType && 'ontouchstart' in window)) {
        if (current !== t) {
          var r = t.getBoundingClientRect();
          show(t, r.left + r.width / 2, r.top);
          e.preventDefault();
          return;
        }
      }
      if (clickable) act(t);
    });
    document.addEventListener('keydown', function (e) {
      var t = e.target.closest && e.target.closest('[data-tip]');
      if (!t) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(t); }
      if (e.key === 'Escape') hide();
    });
    document.addEventListener('focusin', function (e) {
      var t = e.target.closest && e.target.closest('[data-tip]');
      if (!t) { hide(); return; }
      var r = t.getBoundingClientRect();
      show(t, r.left + r.width / 2, r.top);
    });
    window.addEventListener('scroll', hide, { passive: true });
    // The charts drawn to their card's width redraw when it changes.
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { if (redraw) redraw(); }, 200);
    });
  }

  // shortDate rides along because the modal lists readings by date too, and a
  // second copy of it in dashboard.js is a second thing to keep in step.
  return { render: render, renderReport: renderReport, bind: bind, shortDate: shortDate, slotLabel: slotLabel };
})();
