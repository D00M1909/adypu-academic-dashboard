// The four charts under "Insights". No chart library: three of these are a
// handful of divs and the fourth is one <circle>, and a CDN dependency is not
// worth the page weight on a host that already fights to serve PHP.
//
// Everything is drawn from what index.php already put on the page:
//   ATTENDANCE_DATA  the range's tree, for the donut and the ranking
//   ATTENDANCE_DAYS  date -> class key -> present, for the two per-day charts
//   CLASS_STRENGTH   class key -> strength, the denominator for those days
// so a drill-down rescopes every chart without another request.
window.Charts = (function () {
  // A class key is "school|year|branch|division", so the current selection is
  // a prefix of every key inside it. Branchless schools key on an empty branch
  // segment, which is why the trailing '|' matters: 'law|2nd Year|' must match
  // 'law|2nd Year||A'.
  function scopePrefix(state) {
    var parts = [];
    if (state.school) parts.push(state.school);
    if (state.year) parts.push(state.year);
    if (state.branch !== null && state.branch !== undefined) parts.push(state.branch);
    if (!parts.length) return '';
    // A division is the whole key, so it takes no trailing separator: with one
    // it would match nothing and every chart would empty out.
    if (state.division) return parts.join('|') + '|' + state.division;
    return parts.join('|') + '|';
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
  function dailySeries(prefix) {
    var days = window.ATTENDANCE_DAYS || {};
    var strengths = window.CLASS_STRENGTH || {};
    return Object.keys(days).sort().map(function (date) {
      var present = 0, strength = 0, reported = 0;
      Object.keys(days[date]).forEach(function (key) {
        if (key.indexOf(prefix) !== 0) return;
        present += days[date][key];
        strength += strengths[key] || 0;
        reported++;
      });
      return { date: date, present: present, strength: strength, reported: reported };
    }).filter(function (d) { return d.reported > 0; });
  }

  function classesInScope(prefix) {
    return Object.keys(window.CLASS_STRENGTH || {}).filter(function (key) {
      return key.indexOf(prefix) === 0;
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

  // Attendance percentage per day. The one chart the date range exists for:
  // a single number can only say where a school is, this says which way it is
  // going.
  function renderTrend(el, prefix) {
    var series = dailySeries(prefix);
    if (!series.length) {
      empty(el, 'No days reported in this range.');
      return;
    }
    if (series.length === 1) {
      var only = pct(series[0].present, series[0].strength);
      el.innerHTML = '<p class="chart-single"><b class="att-pct ' + attClass(only) + '">' +
        only + '%</b>' + esc(shortDate(series[0].date)) +
        '<span>Pick a wider range to see a trend.</span></p>';
      return;
    }

    var w = 300, h = 120, pad = 8;
    var pts = series.map(function (d, i) {
      var x = pad + (i * (w - pad * 2)) / (series.length - 1);
      var y = h - pad - (pct(d.present, d.strength) / 100) * (h - pad * 2);
      return { x: x, y: y, d: d };
    });
    var line = pts.map(function (p) { return p.x.toFixed(1) + ',' + p.y.toFixed(1); }).join(' ');
    var area = pad + ',' + (h - pad) + ' ' + line + ' ' + (w - pad) + ',' + (h - pad);

    el.innerHTML =
      '<svg class="trend" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="Attendance percentage by day">' +
        '<polygon class="trend-area" points="' + area + '"/>' +
        '<polyline class="trend-line" points="' + line + '"/>' +
        // After the area, not before it: SVG paints in document order, so a
        // 75% line drawn first is buried by the fill for every day at or above
        // 75% — exactly the days you are checking the rule against.
        '<line class="trend-grid" x1="' + pad + '" x2="' + (w - pad) + '" y1="' + (h - pad - 0.75 * (h - pad * 2)) + '" y2="' + (h - pad - 0.75 * (h - pad * 2)) + '"/>' +
        pts.map(function (p) {
          var v = pct(p.d.present, p.d.strength);
          return '<circle class="trend-dot" cx="' + p.x.toFixed(1) + '" cy="' + p.y.toFixed(1) + '" r="3">' +
            '<title>' + esc(shortDate(p.d.date)) + ': ' + v + '% (' + p.d.reported + ' classes)</title></circle>';
        }).join('') +
      '</svg>' +
      '<div class="chart-axis"><span>' + esc(shortDate(series[0].date)) + '</span>' +
        '<span class="chart-axis-note">dashed line is the 75% rule</span>' +
        '<span>' + esc(shortDate(series[series.length - 1].date)) + '</span></div>';
  }

  // Whatever sits one level below the current selection, ranked. At the top
  // that is the nine schools; inside a school it becomes years, then branches,
  // then divisions, which is the comparison you actually want at each step.
  function childTotals(state) {
    var data = window.ATTENDANCE_DATA || {};
    var out = [];

    function totalsOf(divisions) {
      var t = { present: 0, strengthReported: 0, reported: 0, classes: 0 };
      divisions.forEach(function (d) {
        t.classes++;
        if (d.reported) {
          t.reported++;
          t.present += d.present;
          t.strengthReported += d.strength;
        }
      });
      return t;
    }

    function walk(node, acc) {
      if (Array.isArray(node)) return totalsOf(node);
      Object.keys(node).forEach(function (k) { walk(node[k], acc); });
      return acc;
    }

    function sum(node) {
      if (Array.isArray(node)) return totalsOf(node);
      var t = { present: 0, strengthReported: 0, reported: 0, classes: 0 };
      Object.keys(node).forEach(function (k) {
        var s = sum(node[k]);
        t.present += s.present;
        t.strengthReported += s.strengthReported;
        t.reported += s.reported;
        t.classes += s.classes;
      });
      return t;
    }

    var node = data, label = 'school';
    if (state.school) { node = (data[state.school] || {}); label = 'year'; }
    if (state.school && state.year) { node = (node[state.year] || {}); label = 'branch'; }
    if (state.school && state.year && state.branch !== null && state.branch !== undefined) {
      node = node[state.branch] || [];
      label = 'division';
    }

    if (Array.isArray(node)) {
      node.forEach(function (d) {
        out.push({
          name: 'Division ' + d.division,
          totals: totalsOf([d])
        });
      });
    } else {
      Object.keys(node).forEach(function (k) {
        out.push({
          name: label === 'school' ? (window.SCHOOLS[k] ? window.SCHOOLS[k].name.replace(/^School of /, '') : k) : (k || 'General'),
          totals: sum(node[k])
        });
      });
    }
    return { rows: out, label: label };
  }

  function renderBars(el, caption, state) {
    var children = childTotals(state);
    var rows = children.rows.filter(function (r) { return r.totals.classes > 0; });
    caption.textContent = 'Attendance by ' + children.label;
    if (!rows.length) {
      empty(el, 'Nothing to compare here.');
      return;
    }

    rows.sort(function (a, b) {
      return pct(b.totals.present, b.totals.strengthReported) - pct(a.totals.present, a.totals.strengthReported);
    });

    el.innerHTML = '<div class="bar-rows">' + rows.map(function (r) {
      var v = pct(r.totals.present, r.totals.strengthReported);
      var readout = r.totals.reported
        ? '<span class="att-pct ' + attClass(v) + '">' + v + '%</span>'
        : '<span class="tile-unreported">Not reported</span>';
      return '<div class="bar-row">' +
        '<span class="bar-name">' + esc(r.name) + '</span>' +
        '<span class="division-bar"><span class="division-bar-fill ' +
          (r.totals.reported ? attClass(v) : '') + '" style="width:' + (r.totals.reported ? v : 0) + '%"></span></span>' +
        '<span class="bar-value">' + readout + '</span>' +
      '</div>';
    }).join('') + '</div>';
  }

  // How many of the scope's classes filled the form in, per day. The honesty
  // chart: a 95% attendance figure resting on 6 of 103 classes should be
  // visible as exactly that.
  function renderCompliance(el, prefix) {
    // Every day in the range, not just the ones with submissions: a day nobody
    // reported is the whole point of this chart, and dropping it let a class
    // that reported twice in a week look like perfect compliance.
    var days = window.ATTENDANCE_DAYS || {};
    var total = classesInScope(prefix);
    var series = Object.keys(days).sort().map(function (date) {
      var reported = 0;
      Object.keys(days[date]).forEach(function (key) {
        if (key.indexOf(prefix) === 0) reported++;
      });
      return { date: date, reported: reported };
    });
    if (!series.length || !total) {
      empty(el, 'No submissions in this range.');
      return;
    }

    el.innerHTML = '<div class="compliance">' + series.map(function (d) {
      var share = Math.round((d.reported / total) * 100);
      return '<div class="compliance-day" title="' + esc(shortDate(d.date)) + ': ' +
        d.reported + ' of ' + total + ' classes reported">' +
        '<div class="compliance-bar"><div class="compliance-fill" style="height:' + share + '%"></div></div>' +
        '<span class="compliance-label">' + esc(shortDate(d.date).split(' ')[0]) + '</span>' +
      '</div>';
    }).join('') + '</div>' +
    '<p class="chart-note">' + series[series.length - 1].reported + ' of ' + total +
      ' classes reported on ' + esc(shortDate(series[series.length - 1].date)) + '</p>';
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
  function renderDays(section, meta, tbody, prefix) {
    var series = dailySeries(prefix);
    // One day is not a breakdown: the tiles above already say it, twice.
    section.hidden = series.length < 2;
    if (section.hidden) return;

    meta.textContent = series.length + ' days with data';
    tbody.innerHTML = series.map(function (d) {
      var p = pct(d.present, d.strength);
      return '<tr>' +
        '<th scope="row">' + esc(longDate(d.date)) + '</th>' +
        '<td>' + d.reported + '</td>' +
        '<td>' + d.present + '<span class="day-table-sep">/</span>' + d.strength + '</td>' +
        '<td><span class="att-pct ' + attClass(p) + '">' + p + '%</span></td>' +
      '</tr>';
    }).join('');
  }

  // Called by dashboard.js on every selection change. totals comes from
  // scopeTotals() there rather than being recomputed here.
  function render(state, totals) {
    var prefix = scopePrefix(state);
    renderDonut(document.getElementById('chart-donut'), totals);
    renderTrend(document.getElementById('chart-trend'), prefix);
    renderBars(document.getElementById('chart-bars'), document.getElementById('chart-bars-caption'), state);
    renderCompliance(document.getElementById('chart-compliance'), prefix);
    renderDays(
      document.getElementById('daybyday-section'),
      document.getElementById('daybyday-meta'),
      document.getElementById('daybyday-rows'),
      prefix
    );
    document.getElementById('charts-scope').textContent = window.ATTENDANCE_RANGE.label;
  }

  return { render: render };
})();
