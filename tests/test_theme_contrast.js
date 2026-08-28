// Reads the real tokens out of dashboard.css and asserts every foreground the
// page actually paints clears WCAG AA against the surface behind it, in both
// themes. Colour is the one thing a parser test cannot catch and eyeballing
// misses: --ink-muted looks fine on a dark card right up until it is 3.9:1.
//
// Add a pair here whenever a rule starts painting a token on a new surface.
const fs = require('fs');
const assert = require('assert');

const css = fs.readFileSync(__dirname + '/../css/dashboard.css', 'utf8');

// The light palette is bare :root; dark overrides a subset of the same names.
function block(selector) {
  const at = css.indexOf(selector + ' {');
  assert.notStrictEqual(at, -1, 'no ' + selector + ' block in dashboard.css');
  const body = css.slice(at, css.indexOf('}', at));
  const vars = {};
  for (const [, name, value] of body.matchAll(/(--[\w-]+):\s*([^;]+);/g)) vars[name] = value.trim();
  return vars;
}
const light = block(':root');
const dark = Object.assign({}, light, block(':root[data-theme="dark"]'));

// --red-ink is `var(--red-primary)` in light, a literal in dark.
function resolve(vars, name, seen) {
  const value = vars[name];
  assert.ok(value, 'undefined token ' + name);
  const ref = /^var\((--[\w-]+)\)$/.exec(value);
  if (!ref) return value;
  assert.ok(!(seen || []).includes(name), 'circular token ' + name);
  return resolve(vars, ref[1], (seen || []).concat(name));
}

function rgb(value) {
  const hex = /^#([0-9a-f]{6})$/i.exec(value);
  if (hex) return [0, 2, 4].map(i => parseInt(hex[1].slice(i, i + 2), 16)).concat(1);
  const fn = /^rgba?\(([^)]+)\)$/.exec(value);
  assert.ok(fn, 'unparseable colour ' + value);
  const parts = fn[1].split(',').map(Number);
  return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
}

// A translucent tint is only ever seen over the surface it sits on, so flatten
// it there rather than pretending it is opaque.
function over(fg, bg) {
  return [0, 1, 2].map(i => fg[i] * fg[3] + bg[i] * (1 - fg[3])).concat(1);
}

function luminance(c) {
  const [r, g, b] = c.slice(0, 3).map(v => {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(fg, bg) {
  const [a, b] = [luminance(fg), luminance(bg)].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}

// [foreground, background, background it is layered over, minimum]
// 4.5 is the AA floor for body text. --ink-faint is decorative-plus-small-print
// and has never cleared it (2.56:1 on white); 2.5 just holds that line so dark
// mode cannot quietly go below what light mode already ships.
const pairs = [
  ['--ink', '--surface', null, 4.5],
  ['--ink', '--surface-raised', null, 4.5],
  ['--ink-muted', '--surface', null, 4.5],
  ['--ink-muted', '--surface-raised', null, 4.5],
  ['--ink-faint', '--surface-raised', null, 2.5],
  ['--red-ink', '--surface', null, 4.5],
  ['--red-ink', '--surface-raised', null, 4.5],
  ['--red-ink', '--red-tint', '--surface-raised', 4.5],   // .tile.active
  ['--att-good', '--surface-raised', null, 4.5],
  ['--att-warn', '--surface-raised', null, 4.5],
  ['--total-text', '--total-bg', null, 4.5],
  ['#ffffff', '--red-primary', null, 4.5],                // .range-apply, .range-preset.is-active
  ['#ffffff', '--header-from', null, 4.5],                // .app-header h1
  ['#ffffff', '--header-to', null, 4.5],
  ['--red-primary', '#ffffff', null, 4.5],                // .tab.active
];

let checked = 0;
for (const [name, vars] of [['light', light], ['dark', dark]]) {
  for (const [fg, bg, under, min] of pairs) {
    const colour = c => rgb(c.startsWith('--') ? resolve(vars, c) : c);
    let back = colour(bg);
    if (back[3] < 1) back = over(back, colour(under));
    const ratio = contrast(over(colour(fg), back), back);
    assert.ok(
      ratio >= min,
      name + ': ' + fg + ' on ' + bg + ' is ' + ratio.toFixed(2) + ':1, needs ' + min
    );
    checked++;
  }
}

// Every var() the stylesheet reads has to be a token one of the blocks defines,
// or a dark-only rule silently paints nothing.
for (const [, name] of css.matchAll(/var\((--[\w-]+)\)/g)) {
  assert.ok(name in light, 'dashboard.css reads undefined token ' + name);
}

console.log('OK (' + checked + ' contrast pairs, both themes)');
