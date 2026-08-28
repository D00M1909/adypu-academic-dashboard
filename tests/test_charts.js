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
  ATTENDANCE_DAYS: {
    '2026-08-27': { 'eng|1st Year|Core|A': 50, 'law|1st Year||A': 90 },
    '2026-08-28': { 'eng|1st Year|Core|A': 90, 'eng|1st Year|Core|B': 60, 'law|1st Year||A': 80 },
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

// Nothing selected that has data at all.
window.Charts.render({ school: 'eng', year: '1st Year', branch: 'Core' },
  { present: 0, strengthReported: 0, reported: 0, classes: 2 });
assert(els['chart-donut'].innerHTML.includes('has reported'), 'empty donut should explain itself');

console.log('OK');
