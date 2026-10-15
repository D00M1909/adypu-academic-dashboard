// A long <select> that can be searched: typing narrows the list to options
// whose label contains what was typed, anywhere in it ("digital" finds "105
// (Digital Library / Digital Lab)", "science" finds a dean by their role).
//
// The page still renders a plain <select data-combo>, which keeps working if
// this never runs. This lays a text box over it and hides it; picking an
// option sets the select and fires its change event, so the form submits
// exactly as before and the server sees no difference.
//
// The box is an ARIA combobox: arrow keys move through the list, Enter picks,
// Esc closes and puts the current choice back.
(function () {
  var norm = function (s) { return s.toLowerCase().replace(/\s+/g, ' ').trim(); };

  function build(select, n) {
    var options = Array.prototype.filter.call(select.options, function (o) { return !o.disabled; });
    var current = select.options[select.selectedIndex];
    var listId = 'combo-list-' + n;

    var wrap = document.createElement('div');
    wrap.className = 'combo';
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'combo-input';
    input.id = select.id;          // the <label for> now names the box
    select.id = select.id + '-select';
    input.value = current ? current.text : '';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    var list = document.createElement('ul');
    list.className = 'combo-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    select.hidden = true;
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(input);
    wrap.appendChild(list);
    wrap.appendChild(select);

    // As wide as the longest option, the way a select sizes itself, so the
    // chosen name shows in full (CSS still caps it at the card's width).
    // Measured again once the web font has loaded, since the fallback font
    // it may be measured in first is narrower.
    function fit() {
      var cs = getComputedStyle(input);
      var ctx = document.createElement('canvas').getContext('2d');
      ctx.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
      var widest = options.reduce(function (w, o) { return Math.max(w, ctx.measureText(o.text).width); }, 0);
      input.style.width = Math.ceil(widest + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight)
          + parseFloat(cs.borderLeftWidth) + parseFloat(cs.borderRightWidth) + 2) + 'px';
    }
    fit();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);

    var shown = [];   // the options in the list now, in order
    var active = -1;

    // One row: the label with the typed part marked. Built from text nodes,
    // never HTML, since the labels come from hand-typed timetables.
    function row(o, i, q) {
      var li = document.createElement('li');
      li.id = listId + '-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', o === current ? 'true' : 'false');
      var text = o.text;
      var at = q ? norm(text).indexOf(q) : -1;
      // norm() only folds case and spaces, so the position holds for labels
      // with single spaces, which is all of these; otherwise show it unmarked.
      if (at >= 0 && norm(text) === text.toLowerCase()) {
        li.appendChild(document.createTextNode(text.slice(0, at)));
        var mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + q.length);
        li.appendChild(mark);
        li.appendChild(document.createTextNode(text.slice(at + q.length)));
      } else {
        li.textContent = text;
      }
      li.addEventListener('mousedown', function (e) {
        e.preventDefault();   // keep focus in the box until the pick lands
        pick(o);
      });
      return li;
    }

    function draw(q) {
      q = norm(q);
      shown = options.filter(function (o) { return !q || norm(o.text).indexOf(q) >= 0; });
      list.textContent = '';
      shown.forEach(function (o, i) { list.appendChild(row(o, i, q)); });
      if (!shown.length) {
        var none = document.createElement('li');
        none.className = 'combo-none';
        none.textContent = 'No match';
        list.appendChild(none);
      }
      move(q ? 0 : shown.indexOf(current));
    }

    function move(i) {
      if (active >= 0 && list.children[active]) list.children[active].classList.remove('is-active');
      active = shown.length ? Math.max(0, Math.min(i, shown.length - 1)) : -1;
      if (active >= 0) {
        var li = list.children[active];
        li.classList.add('is-active');
        input.setAttribute('aria-activedescendant', li.id);
        li.scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function open() {
      if (!list.hidden) return;
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      draw('');
    }

    function close(restore) {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      if (restore) input.value = current ? current.text : '';
    }

    function pick(o) {
      close(false);
      input.value = o.text;
      if (o === current) return;
      current = o;
      select.value = o.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Opening selects the whole label, so the first key typed replaces it.
    input.addEventListener('focus', function () { open(); input.select(); });
    input.addEventListener('click', open);
    input.addEventListener('input', function () { list.hidden = false; input.setAttribute('aria-expanded', 'true'); draw(input.value); });
    input.addEventListener('blur', function () { close(true); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (list.hidden) { open(); return; }
        move(active + (e.key === 'ArrowDown' ? 1 : -1));
      } else if (e.key === 'Enter') {
        e.preventDefault();   // never submit the form with half a word in the box
        if (!list.hidden && shown[active]) pick(shown[active]);
      } else if (e.key === 'Escape') {
        if (!list.hidden) { e.preventDefault(); close(true); input.select(); }
      } else if (e.key === 'Tab') {
        close(true);
      }
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('select[data-combo]'), function (s, i) {
    if (!s.disabled) build(s, i);
  });
})();
