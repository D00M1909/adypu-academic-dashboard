// The Exams tab (exams.php). On the attendance form, an arrival time sets the
// row's status (late when after the reporting time, otherwise on time), and
// choosing Absent clears the time, and the row's colour follows the status.
// On the roster and duty letter, "Copy as text" copies the plain version.
(function () {
  'use strict';

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var area = document.getElementById(button.dataset.copy);
      var label = button.textContent;
      var done = function () { button.textContent = 'Copied'; setTimeout(function () { button.textContent = label; }, 1800); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(area.value).then(done);
      } else {
        area.classList.remove('sr-only');
        area.select();
        document.execCommand('copy');
        area.classList.add('sr-only');
        done();
      }
    });
  });

  var form = document.getElementById('exm-mark');
  if (!form) return;

  function paint(select) {
    var row = select.closest('tr');
    row.className = row.className.replace(/\bexm-row-\w+/g, '') + ' exm-row-' + select.value;
  }

  function statusOf(input) {
    return input.closest('tr').querySelector('select[name^="status["]');
  }

  form.querySelectorAll('input[type="time"][data-report]').forEach(function (input) {
    input.addEventListener('change', function () {
      var select = statusOf(input);
      if (!select || !input.value) return;
      // "HH:MM" strings compare correctly as text.
      select.value = input.value > input.dataset.report ? 'late' : 'ontime';
      paint(select);
    });
  });

  form.querySelectorAll('select[name^="status["]').forEach(function (select) {
    select.addEventListener('change', function () {
      paint(select);
      if (select.value !== 'absent') return;
      var time = select.closest('tr').querySelector('input[type="time"]');
      if (time) time.value = '';
    });
  });
}());
