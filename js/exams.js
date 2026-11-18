// The Exams tab (exams.php): the Export menu's "Copy as text". Attendance
// needs no script: Present is stamped by the server's clock (exam_set_status()).
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
}());
