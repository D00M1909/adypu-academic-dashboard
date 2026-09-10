// Run: node tests/test_marking_ui.js
// The marking screen's browser logic: the running present count, and the filter
// that hides rows in a sixty-name roster.
//
// Both bugs this locks down are the same shape as every other bug this project
// has had — a count that is quietly wrong rather than an error anyone sees. The
// first shipped: render() ran with no checkboxes at all and wrote "0" into the
// bar of a class that has no roster, directly above the number the teacher had
// just typed. The second is the risk the filter introduces: a student ticked
// absent and then filtered out of view must still be counted and still post.
//
// The DOM here is a stub, not a browser. It answers exactly the selectors
// js/mark.js asks for; adding a selector there means adding it here.
const assert = require('assert');
const fs = require('fs');
const path = require('path');

function el(props) {
  const classes = [];
  return Object.assign({
    checked: false, hidden: false, value: '', textContent: '', disabled: false,
    attrs: {}, listeners: {}, dataset: {},
    classList: {
      add(c) { if (classes.indexOf(c) === -1) classes.push(c); },
      remove(c) { const i = classes.indexOf(c); if (i !== -1) classes.splice(i, 1); },
      contains(c) { return classes.indexOf(c) !== -1; },
    },
    getAttribute(n) { return this.attrs[n] === undefined ? null : this.attrs[n]; },
    setAttribute(n, v) { this.attrs[n] = v; },
    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); },
    fire(t, ev) { (this.listeners[t] || []).forEach(function (fn) { fn(ev || {}); }); },
  }, props || {});
}

// A roster of `size` students, or no roster at all when size is 0 — in which
// case mark.php renders no count element, exactly as the live page does.
const timers = [];
function build(size, opts) {
  opts = opts || {};
  timers.length = 0;
  const boxes = [];
  const rows = [];
  for (let i = 1; i <= size; i++) {
    const roll = '24BHM' + (1000 + i);
    const box = el({ value: roll, attrs: { name: 'absent[]' } });
    boxes.push(box);
    rows.push(el({ textContent: 'Student ' + i + ' ' + roll, box: box, querySelector: () => box }));
  }

  const ids = {
    // `legacyCount` reproduces the markup as it shipped: mark.php emitted the
    // count element for a class with no roster too, leaving it empty for the
    // script to fill. That is the state the guard in render() exists for.
    'mark-present': (size || opts.legacyCount) ? el({}) : null,
    'mark-absent': (size || opts.legacyCount) ? el({}) : null,
    'roster-filter': opts.filter ? el({}) : null,
    'roster-empty': el({}),
    'roster-filter-count': el({}),
    picker: null,
  };

  const allPresent = el({ textContent: 'All present', attrs: { 'data-all': 'present' } });
  const allAbsent = el({ textContent: 'All absent', attrs: { 'data-all': 'absent', 'data-confirm': 'Tap again to confirm' } });
  const submit = el({});

  const form = el({
    querySelectorAll(sel) {
      if (sel === 'input[name="absent[]"]') return boxes;
      if (sel === '[data-all]') return [allPresent, allAbsent];
      return [];
    },
    querySelector(sel) {
      if (sel === 'button[type="submit"]') return submit;
      if (sel.indexOf('input[name="') === 0) return el({ value: 'x' });
      return null;
    },
  });
  ids.marking = form;

  global.document = {
    getElementById(id) { return ids[id] || null; },
    querySelectorAll(sel) { return sel === '#roster > li' ? rows : []; },
    createElement() { return el({ style: {}, select() {} }); },
  };
  const store = {};
  global.localStorage = {
    getItem(k) { return k in store ? store[k] : null; },
    setItem(k, v) { store[k] = v; },
    removeItem(k) { delete store[k]; },
  };
  global.location = { search: '' };
  // Nothing may call this any more. A suppressed native dialog returns false,
  // which is what made "All absent" permanently dead in a real browser.
  global.window = {
    confirm() { throw new Error('mark.js must not use window.confirm()'); },
    localStorage: global.localStorage,
  };
  global.setTimeout = (fn) => { timers.push(fn); return timers.length; };
  global.clearTimeout = () => {};

  // Fresh evaluation per case: mark.js is an IIFE that binds to whatever
  // globals exist when it runs.
  new Function(fs.readFileSync(path.join(__dirname, '..', 'js', 'mark.js'), 'utf8'))();

  return { boxes, rows, ids, allPresent, allAbsent, submit, form };
}

// --- The count ---------------------------------------------------------------

let d = build(11, {});
assert.strictEqual(d.ids['mark-present'].textContent, '11', 'a fresh roster is everyone present');
assert.strictEqual(d.ids['mark-absent'].textContent, '', 'nobody absent says nothing');

d.boxes[0].checked = true;
d.boxes[2].checked = true;
d.boxes[0].fire('change');
assert.strictEqual(d.ids['mark-present'].textContent, '9', 'two ticks take two off the present count');
assert.strictEqual(d.ids['mark-absent'].textContent, ' \u00b7 2 absent', 'and the absent count is spelled out');

// The bug, at the JS layer. Given a count element and no checkboxes — which is
// exactly what a class with no roster used to render — render() computed
// 0 - 0 and wrote "0" into the bar, directly above the number box in which the
// teacher had just typed 28. It must leave the element alone instead.
d = build(0, { legacyCount: true });
assert.strictEqual(d.ids['mark-present'].textContent, '',
  'with no checkboxes there is nothing to count, so the bar must not say 0');

// And at the markup layer: mark.php now omits the element entirely, so render()
// must also cope with it being absent.
d = build(0, {});
assert.strictEqual(d.ids['mark-present'], null, 'no roster renders no count element');

// --- All present / all absent ------------------------------------------------

// "All absent" arms on the first tap and commits on the second, using no native
// dialog. The bug this replaces: window.confirm() is suppressed by plenty of
// browsers, a suppressed confirm() returns false, and the button was therefore
// dead forever with nothing on screen to explain it.
d = build(5, {});
d.allAbsent.fire('click');
assert.strictEqual(d.ids['mark-present'].textContent, '5', 'the first tap only arms it');
assert.strictEqual(d.allAbsent.textContent, 'Tap again to confirm', 'and says so on the button');
assert.strictEqual(d.allAbsent.classList.contains('is-armed'), true, 'and looks armed');

d.allAbsent.fire('click');
assert.strictEqual(d.ids['mark-present'].textContent, '0', 'the second tap empties the room');
assert.strictEqual(d.allAbsent.textContent, 'All absent', 'and the label goes back');

d.allPresent.fire('click');
assert.strictEqual(d.ids['mark-present'].textContent, '5', 'all present fills it again in one tap');

// An armed button must not stay armed: a stray tap cannot sit there waiting to
// wipe the roll on whatever gets tapped next.
d = build(5, {});
d.allAbsent.fire('click');
assert.strictEqual(d.allAbsent.dataset.armed, '1', 'armed');
timers.forEach(fn => fn());
assert.strictEqual(d.allAbsent.dataset.armed, undefined, 'and disarms itself on the timeout');
assert.strictEqual(d.allAbsent.textContent, 'All absent', 'restoring its label');

// --- Buttons that would do nothing ------------------------------------------
//
// A bulk button whose work is already done was silent when tapped, which is
// exactly what a broken button looks like.
d = build(5, {});
assert.strictEqual(d.allPresent.disabled, true, 'nobody absent: "all present" has nothing to do');
assert.strictEqual(d.allAbsent.disabled, false, '"all absent" does');

d.boxes[0].checked = true;
d.boxes[0].fire('change');
assert.strictEqual(d.allPresent.disabled, false, 'one absentee gives "all present" something to do');

d.allAbsent.fire('click');
d.allAbsent.fire('click');
assert.strictEqual(d.allAbsent.disabled, true, 'everyone absent: "all absent" has nothing left to do');
assert.strictEqual(d.allPresent.disabled, false, 'and "all present" does');

// --- The filter --------------------------------------------------------------

d = build(20, { filter: true });
const filter = d.ids['roster-filter'];

filter.value = '1003';
filter.fire('input');
assert.strictEqual(d.rows.filter(r => !r.hidden).length, 1, 'a roll number narrows to one row');
assert.strictEqual(d.ids['roster-filter-count'].textContent, '1 of 20', 'and the count says so');
assert.strictEqual(d.ids['roster-empty'].hidden, true, 'a match hides the empty message');

filter.value = 'Student 7';
filter.fire('input');
assert.strictEqual(d.rows.filter(r => !r.hidden).length, 1, 'a name narrows too');

filter.value = 'nobody';
filter.fire('input');
assert.strictEqual(d.ids['roster-empty'].hidden, false, 'no match shows the empty message');
assert.strictEqual(d.ids['roster-filter-count'].textContent, '0 of 20', 'and reports zero');

filter.value = '';
filter.fire('input');
assert.strictEqual(d.rows.filter(r => !r.hidden).length, 20, 'clearing restores every row');
assert.strictEqual(d.ids['roster-filter-count'].textContent, '', 'and drops the count');

// The property that makes the filter safe to ship: hiding a row must never
// take a student out of the submission or out of the count.
d = build(20, { filter: true });
d.boxes[4].checked = true;
d.boxes[4].fire('change');
assert.strictEqual(d.ids['mark-present'].textContent, '19', 'one absentee before filtering');

d.ids['roster-filter'].value = '1012';
d.ids['roster-filter'].fire('input');
assert.strictEqual(d.rows[4].hidden, true, 'the absentee is now filtered out of sight');
assert.strictEqual(d.boxes[4].checked, true, 'but still ticked, so it still posts');
assert.strictEqual(d.ids['mark-present'].textContent, '19', 'and the bar still counts every box, not the visible ones');

console.log('OK');
