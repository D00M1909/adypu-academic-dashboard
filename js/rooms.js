// The panel a class in the room timetable opens: its attendance for that
// lecture, and where in which of the schools' documents it is written, with a
// link that opens the document at that spot. rooms.php prints everything the
// panel needs as JSON beside it; this only draws it. Every string goes in as
// text, never as HTML: the documents and the class labels are typed by hand.
(function () {
  var dialog = document.getElementById('tt-panel');
  var data = JSON.parse(document.getElementById('tt-panel-data').textContent);
  var body = dialog.querySelector('.tt-panel-body');

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function link(href, text, cls, download) {
    var a = el('a', cls, text);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener';
    if (download) a.setAttribute('download', '');
    return a;
  }

  function show(x) {
    body.textContent = '';
    var h = el('h2', 'tt-panel-title', x.title);
    h.id = 'tt-panel-title';
    body.appendChild(h);
    if (x.class) body.appendChild(el('p', 'tt-panel-class', x.class));
    if (x.faculty) body.appendChild(el('p', 'tt-panel-meta', x.faculty));
    body.appendChild(el('p', 'tt-panel-meta', x.when));

    if (x.att) {
      var att = el('section', 'tt-panel-part');
      att.appendChild(el('h3', null, 'Attendance, ' + x.att.date));
      var ul = el('ul', 'tt-panel-att');
      x.att.classes.forEach(function (c) {
        var li = el('li');
        li.appendChild(el('span', null, c.name));
        li.appendChild(c.present === null
          ? el('span', 'tt-att-none', 'Not marked')
          : el('strong', null, c.present + ' of ' + c.strength + ' present'));
        ul.appendChild(li);
      });
      att.appendChild(ul);
      body.appendChild(att);
    }

    // null: sources are switched off on this site (TIMETABLE_SOURCES).
    if (!x.src) {
      if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
      return;
    }
    var src = el('section', 'tt-panel-part');
    src.appendChild(el('h3', null, x.src.length > 1 ? 'Sources' : 'Source'));
    if (!x.src.length) src.appendChild(el('p', 'tt-panel-meta', 'No source document is on file for this class.'));
    x.src.forEach(function (s) {
      var box = el('div', 'tt-panel-src');
      box.appendChild(el('strong', 'tt-panel-doc', s.name));
      if (s.where) box.appendChild(el('span', 'tt-panel-where', s.where));
      box.appendChild(el('span', 'tt-panel-meta', s.how));
      if (s.fix) box.appendChild(el('span', 'tt-panel-fix', 'Changed by the build: ' + s.fix));
      var acts = el('div', 'tt-panel-acts');
      acts.appendChild(link(s.open, 'Open at this class', 'btn-primary'));
      acts.appendChild(link(s.file, 'Download original', 'btn-quiet', true));
      box.appendChild(acts);
      src.appendChild(box);
    });
    body.appendChild(src);

    if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('.tt-grid [data-i]');
    if (b) show(data[+b.getAttribute('data-i')]);
  });
  // A click on the backdrop (the dialog itself, outside its box) closes it.
  dialog.addEventListener('click', function (e) {
    if (e.target === dialog) dialog.close();
  });
})();
