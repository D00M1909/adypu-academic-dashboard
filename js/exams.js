// The Exams tab (exams.php). On the attendance form, an arrival time sets the
// row's status (late when after the reporting time, otherwise on time), and
// choosing Absent clears the time.
(function () {
  'use strict';

  var form = document.getElementById('exm-mark');
  if (!form) return;

  function statusOf(input) {
    return input.closest('tr').querySelector('select[name^="status["]');
  }

  form.querySelectorAll('input[type="time"][data-report]').forEach(function (input) {
    input.addEventListener('change', function () {
      var select = statusOf(input);
      if (!select || !input.value) return;
      // "HH:MM" strings compare correctly as text.
      select.value = input.value > input.dataset.report ? 'late' : 'ontime';
    });
  });

  form.querySelectorAll('select[name^="status["]').forEach(function (select) {
    select.addEventListener('change', function () {
      if (select.value !== 'absent') return;
      var time = select.closest('tr').querySelector('input[type="time"]');
      if (time) time.value = '';
    });
  });
}());
