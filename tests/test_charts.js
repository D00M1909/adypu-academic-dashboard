// Run: node tests/test_charts.js
// The charts are the only real logic in the browser: which class keys are in
// scope, and what a day's numbers add up to. Everything below feeds charts.js
// a known week and checks what it draws.
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const els = {};
global.document = {
  getElementById(id) {
    return els[id] || (els[id] = { id, innerHTML: '', textContent: '' });
  },
};

// Two schools, four classes, two days. eng division B reported once, law
// division B never — the two cases that a naive average gets wrong.
global.window = {
  SCHOOLS: { eng: { name: 'School of Engineering' }, law: { name: 'School of Law' } },
  ATTENDANCE_RANGE: { from: '2026-08-27', to: '2026-08-28', label: '27 Aug to 28 Aug 2026' },
  CLASS_STRENGTH: {
    'eng|1st Year|Core|A': 100, 'eng|1st Year|Core|B': 100,
    'law|1st Year||A': 100, 'law|1st Year||B': 100,
  },
  // One entry per lecture, as the cache stores them. eng division A is reported
  // twice a day, and the day's number is the LATEST of the two (50, then 90),
  // never their mean — dayPresent() mirrors day_present() in PHP.
  ATTENDANCE_DAYS: {
    '2026-08-27': {
      'eng|1st Year|Core|A': { '09:30': 40, '11:30': 50 },
      'law|1st Year||A': { '09:30': 90 },
    },
    '2026-08-28': {
      'eng|1st Year|Core|A': { '09:30': 95, '14:15': 90 },
      'eng|1st Year|Core|B': { '10:30': 60 },
      'law|1st Year||A': { '09:30': 80 },
    },
  },
  ATTENDANCE_DATA: {
    eng: { '1st Year': { Core: [
      { division: 'A', strength: 100, present: 70, reported: true, days: 2 },
      { division: 'B', strength: 100, present: 60, reported: true, days: 1 },
    ] } },
    law: { '1st Year': { '': [
      { division: 'A', strength: 100, present: 85, reported: true, days: 2 },
      { division: 'B', strength: 100, present: 0, reported: false, days: 0 },
    ] } },
  },
};

eval(fs.readFileSync(path.join(__dirname, '../js/charts.js'), 'utf8'));

// --- Root: every school in scope -------------------------------------------
window.Charts.render({ school: null, year: null, branch: null },
  { present: 215, strengthReported: 300, reported: 3, classes: 4 });

assert(els['chart-donut'].innerHTML.includes('72%'), 'donut should show 215/300');
assert(els['chart-donut'].innerHTML.includes('>85<'), 'donut legend should show 85 absent');

// 27 Aug is 140/200, 28 Aug is 230/300. A day is a percentage of the classes
// that reported THAT day, not of the whole university.
assert(els['chart-trend'].innerHTML.includes('70% (2 classes)'), 'trend day 1 wrong');
assert(els['chart-trend'].innerHTML.includes('77% (3 classes)'), 'trend day 2 wrong');

// Ranked, so the school in front comes first.
const bars = els['chart-bars'].innerHTML;
assert(bars.indexOf('Law') < bars.indexOf('Engineering'), 'bars should be ranked, best first');
assert(bars.includes('85%') && bars.includes('65%'), 'bar percentages wrong: ' + bars);
assert(els['chart-bars-caption'].textContent === 'Attendance by school', 'bar caption wrong');

assert(els['chart-compliance'].innerHTML.includes('3 of 4 classes reported'),
  'compliance should count the classes in scope');

// --- Drilled into one school ------------------------------------------------
// The prefix is what scopes the per-day charts: law's numbers must not leak
// into engineering's trend.
window.Charts.render({ school: 'eng', year: null, branch: null },
  { present: 130, strengthReported: 200, reported: 2, classes: 2 });
assert(els['chart-trend'].innerHTML.includes('50% (1 classes)'), 'eng day 1 should be 50/100');
assert(els['chart-trend'].innerHTML.includes('75% (2 classes)'), 'eng day 2 should be 150/200');
assert(els['chart-compliance'].innerHTML.includes('2 of 2 classes reported'), 'eng has 2 classes, not 4');
assert(els['chart-bars-caption'].textContent === 'Attendance by year', 'drilling should change the ranking');

// A branchless school keys on an empty branch segment: 'law|1st Year|' has to
// match 'law|1st Year||A' or the whole school vanishes from its own charts.
window.Charts.render({ school: 'law', year: '1st Year', branch: null },
  { present: 85, strengthReported: 100, reported: 1, classes: 2 });
assert(els['chart-trend'].innerHTML.includes('90% (1 classes)'), 'branchless scope lost its classes');
assert(els['chart-compliance'].innerHTML.includes('1 of 2 classes reported'), 'law scope wrong');

// A class nobody reported must not be drawn as a zero, which reads as total
// absence rather than a missing form.
window.Charts.render({ school: 'law', year: '1st Year', branch: '' },
  { present: 85, strengthReported: 100, reported: 1, classes: 2 });
assert(els['chart-bars'].innerHTML.includes('Not reported'), 'unreported division should say so');
assert(!els['chart-bars'].innerHTML.includes('>0%<'), 'unreported division drawn as a 0% reading');

// One division selected: the scope is a whole class key, and a trailing
// separator on it would match nothing and empty every chart.
window.Charts.render({ school: 'eng', year: '1st Year', branch: 'Core', division: 'A' },
  { present: 70, strengthReported: 100, reported: 1, classes: 1 });
assert(els['chart-trend'].innerHTML.includes('50% (1 classes)'), 'division scope lost day 1');
assert(els['chart-trend'].innerHTML.includes('90% (1 classes)'), 'division scope lost day 2');
assert(els['chart-compliance'].innerHTML.includes('1 of 1 classes reported'), 'division scope wrong');

// --- Class breakdown: the export's school report ----------------------------
// A bigger tree than the shared fixture, swapped in just for this block: the
// table's whole point is a school with several years and branches, and growing
// the fixture above to suit it would rewrite every count already asserted.
const sharedData = window.ATTENDANCE_DATA;
window.ATTENDANCE_DATA = {
  eng: {
    '1st Year': { Core: [
      { division: 'A', strength: 60, present: 45, reported: true, days: 2 },
      { division: 'B', strength: 60, present: 0, reported: false, days: 0 },
    ] },
    '2nd Year': {
      CSE: [{ division: 'A', strength: 60, present: 48, reported: true, days: 2 }],
      ECE: [{ division: 'A', strength: 60, present: 30, reported: true, days: 1 }],
    },
  },
  law: { '1st Year': { '': [
    { division: 'A', strength: 50, present: 40, reported: true, days: 2 },
  ] } },
};

// A whole school: every class, each labelled with the year and branch the
// selection has not already fixed.
window.Charts.render({ school: 'eng', year: null, branch: null },
  { present: 123, strengthReported: 180, reported: 3, classes: 4 });
const breakdown = els['breakdown-rows'].innerHTML;
assert(els['breakdown-section'].hidden === false, 'a school should show its class breakdown');
assert(els['breakdown-meta'].textContent === 'Engineering · 4 classes',
  'breakdown scope label wrong: ' + els['breakdown-meta'].textContent);
assert(breakdown.includes('1st Year / Core / Division A'), 'row label should carry year and branch');
assert(breakdown.includes('2nd Year / ECE / Division A'), 'later years missing from the breakdown');
assert(breakdown.includes('75%') && breakdown.includes('80%') && breakdown.includes('50%'),
  'per-class percentages wrong: ' + breakdown);
// An unreported class is part of the school whether or not anyone filed for
// it, and saying so is most of why a head of school wants this page.
assert(breakdown.includes('Not reported'), 'unreported class dropped from the breakdown');
assert(!breakdown.includes('>0%<'), 'unreported class drawn as a 0% reading');

// Every entry behind a class's number is printed under it, so the report states
// the readings it was read from rather than only their headline.
assert((breakdown.match(/report-subrow/g) || []).length === 4,
  'a class reported twice a day over two days should list all four readings');
assert(breakdown.includes('27 Aug · 09:30') && breakdown.includes('28 Aug · 14:15'),
  'reading sub-rows should name the day and the lecture: ' + breakdown);
// A class with a single reading would only repeat its own row.
assert(!breakdown.includes('10:30'), 'a lone reading should not get a sub-row of its own');

// One year of a school that has branches: the year is fixed, so it leaves the
// labels, and the branches are what the table is comparing.
window.Charts.render({ school: 'eng', year: '2nd Year', branch: null },
  { present: 78, strengthReported: 120, reported: 2, classes: 2 });
assert(els['breakdown-section'].hidden === false, 'a year with branches should show the breakdown');
assert(els['breakdown-rows'].innerHTML.includes('CSE / Division A'), 'branch missing from the row label');
assert(!els['breakdown-rows'].innerHTML.includes('2nd Year /'), 'the selected year should not repeat in every row');

// Drilled to a branch, the divisions grid on the page already IS this table.
window.Charts.render({ school: 'eng', year: '2nd Year', branch: 'CSE' },
  { present: 48, strengthReported: 60, reported: 1, classes: 1 });
assert(els['breakdown-section'].hidden === true, 'a branch should not print its divisions twice');

// Same for a branchless school's year: selecting it lands straight on the
// divisions grid.
window.Charts.render({ school: 'law', year: '1st Year', branch: null },
  { present: 40, strengthReported: 50, reported: 1, classes: 1 });
assert(els['breakdown-section'].hidden === true, 'a branchless year should not print its divisions twice');

// Root: 103 classes is not a breakdown, it is the whole database.
window.Charts.render({ school: null, year: null, branch: null },
  { present: 163, strengthReported: 230, reported: 4, classes: 5 });
assert(els['breakdown-section'].hidden === true, 'no school selected should show no breakdown');

window.ATTENDANCE_DATA = sharedData;

// --- Day by day: the same series as the trend, stated as numbers ------------
// Root scope, two days: 27 Aug is 140/200 (70%), 28 Aug is 230/300 (77%). The
// table has to agree with the trend chart exactly, since a printed report is
// checked against it.
window.Charts.render({ school: null, year: null, branch: null },
  { present: 215, strengthReported: 300, reported: 3, classes: 4 });
const dayRows = els['daybyday-rows'].innerHTML;
assert(els['daybyday-section'].hidden === false, 'a two-day range should show the breakdown');
assert(els['daybyday-meta'].textContent === '2 days with data', 'day count wrong: ' + els['daybyday-meta'].textContent);
assert(dayRows.includes('70%') && dayRows.includes('77%'), 'day percentages wrong: ' + dayRows);
assert(dayRows.indexOf('70%') < dayRows.indexOf('77%'), 'days should be in date order');
assert(dayRows.includes('>140<') && dayRows.includes('>200<'), 'a day should state present and strength');
assert(dayRows.includes('Thu, 27 Aug 2026'), 'date label wrong: ' + dayRows);

// Scoped to one division, the same table follows the drill-down.
window.Charts.render({ school: 'eng', year: '1st Year', branch: 'Core', division: 'A' },
  { present: 70, strengthReported: 100, reported: 1, classes: 1 });
assert(els['daybyday-rows'].innerHTML.includes('50%'), 'breakdown did not rescope to the division');

// A day nobody reported inside the scope is left out, not drawn as zero: eng
// division B reported only on 28 Aug.
window.Charts.render({ school: 'eng', year: '1st Year', branch: 'Core', division: 'B' },
  { present: 60, strengthReported: 100, reported: 1, classes: 1 });
assert(els['daybyday-section'].hidden === true, 'one reporting day is not a breakdown');

// Nothing selected that has data at all.
window.Charts.render({ school: 'eng', year: '1st Year', branch: 'Core' },
  { present: 0, strengthReported: 0, reported: 0, classes: 2 });
assert(els['chart-donut'].innerHTML.includes('has reported'), 'empty donut should explain itself');

console.log('OK');
