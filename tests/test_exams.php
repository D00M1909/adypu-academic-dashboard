<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_exams.php
//
// The exam duty logic: parsing what a schedule sheet holds, the late rule and
// the mail it queues, the hand-off to the Apps Script, and the upload.

// Before store.php is loaded, so nothing here touches the live data directory.
define('STORE_DIR', __DIR__ . '/../cache/test-exams-' . getmypid());
require_once __DIR__ . '/../includes/exams.php';

register_shutdown_function(function () {
    foreach (glob(STORE_DIR . '/*/*') ?: [] as $f) unlink($f);
    foreach (glob(STORE_DIR . '/*') ?: [] as $f) is_dir($f) ? rmdir($f) : unlink($f);
    @rmdir(STORE_DIR);
});

// --- Parsing -------------------------------------------------------------------
assert(exam_parse_time('10:00') === '10:00');
assert(exam_parse_time('10.30 am') === '10:30');
assert(exam_parse_time('2:00 PM') === '14:00');
assert(exam_parse_time('2.00') === '14:00', 'an unmarked 2.00 is an afternoon paper');
assert(exam_parse_time('0.5') === '12:00', "Excel's half a day is noon");
assert(exam_parse_time('12:00 am') === '00:00');
assert(exam_parse_time('nonsense') === '');
assert(exam_parse_date('24/11/2026') === '2026-11-24');
assert(exam_parse_date('2026-11-24') === '2026-11-24');
assert(exam_parse_date('46350') === '2026-11-24', 'an Excel serial: ' . exam_parse_date('46350'));
assert(exam_parse_date('31/02/2026') === '', 'an impossible date is refused');

// --- Making an exam --------------------------------------------------------------
[$x, $why] = exam_make(['date' => '24/11/2026', 'start' => '10:00', 'end' => '13:00', 'school' => 'engineering', 'class' => 'B.Tech CSE Sem III', 'subject' => 'Maths']);
assert($x !== null, "exam_make failed: $why");
assert($x['report'] === '09:30', 'reporting defaults to half an hour before the start');
assert($x['need'] === EXAM_DEFAULT_NEED);
[$bad] = exam_make(['date' => '24/11/2026', 'start' => '13:00', 'end' => '10:00', 'school' => 'engineering', 'class' => 'c', 'subject' => 's']);
assert($bad === null, 'an end before the start is refused');

// --- Attendance, the late rule and the mail -----------------------------------------
$faculty = ['pa' => ['name' => 'Dr. Asha Rao', 'schools' => ['engineering'], 'as' => ['asha rao']],
            'pb' => ['name' => 'Prof. Bimal Das', 'schools' => ['engineering'], 'as' => ['bimal das']]];
$in = ['date' => '2026-11-24', 'start' => '10:00', 'end' => '13:00', 'school' => 'engineering', 'class' => 'B.Tech CSE Sem III', 'subject' => 'Maths', 'need' => 3];
assert(exam_add($in, 'admin@x.in') === '');
assert(exam_add($in, 'admin@x.in') !== '', 'the same exam twice is refused');
$id = array_key_first(exam_data()['exams']);
assert(exam_short(exam_data(), $id) === 3);
assert(exam_assign($id, 'pa', $faculty, 'Invigilator', '', 'admin@x.in') === '');
assert(exam_assign($id, 'pa', $faculty, 'Invigilator', '', 'admin@x.in') !== '', 'the same person twice is refused');
assert(exam_assign($id, 'pb', $faculty, 'Squad', '', 'admin@x.in') === '');
assert(exam_assign($id, 'zz', $faculty, 'Squad', '', 'admin@x.in') !== '', 'an unknown person is refused');
$duty = [];
foreach (exam_data()['duties'] as $did => $d) $duty[$d['person']] = $did;
$emails = ['pa' => 'asha@adypu.edu.in'];

// Late, email known: late, and one mail queued.
$queued = exam_mark($id, [$duty['pa'] => 'ontime', $duty['pb'] => 'ontime'], [$duty['pa'] => '09:50', $duty['pb'] => '09:20'], [], $emails, 'admin@x.in');
assert($queued === 1);
$d = exam_data();
assert($d['duties'][$duty['pa']]['status'] === 'late', 'an arrival after the reporting time is late');
assert($d['duties'][$duty['pb']]['status'] === 'ontime');
assert(count($d['mail']) === 1 && $d['mail'][$duty['pa']]['to'] === 'asha@adypu.edu.in', 'only the late duty with an email is mailed');
assert(!isset($d['mail'][$duty['pb']]), 'an on-time duty queues nothing');

// Saving the same form again does not mail twice.
assert(exam_mark($id, [$duty['pa'] => 'late'], [$duty['pa'] => '09:50'], [], $emails, 'admin@x.in') === 0);

// Late with no email on file: late, nothing queued.
exam_mark($id, [$duty['pb'] => 'ontime'], [$duty['pb'] => '09:45'], [], [], 'admin@x.in');
assert(exam_data()['duties'][$duty['pb']]['status'] === 'late' && count(exam_data()['mail']) === 1, 'no address, no mail');

// Absent clears the arrival time.
exam_mark($id, [$duty['pb'] => 'absent'], [$duty['pb'] => '09:45'], [$duty['pb'] => 'ill'], [], 'admin@x.in');
$d = exam_data()['duties'][$duty['pb']];
assert($d['status'] === 'absent' && $d['arrived'] === '' && $d['remark'] === 'ill');

// --- The hand-off ----------------------------------------------------------------------
$pending = exam_mail_handoff([]);
assert(count($pending) === 1 && $pending[0]['id'] === $duty['pa'] && $pending[0]['to'] === 'asha@adypu.edu.in');
assert(str_contains($pending[0]['body'], 'Maths'));
assert(exam_mail_handoff([$duty['pa']]) === [], 'once the script says it sent it, nothing is pending');
assert(exam_data()['mail'][$duty['pa']]['sent'] !== '');

// --- The report ------------------------------------------------------------------------------
$rows = exam_report_rows(exam_data(), '', '', '', '', '');
$c = exam_counts($rows);
assert($c['duties'] === 2 && $c['late'] === 1 && $c['absent'] === 1);
assert(exam_report_rows(exam_data(), '', '', '', '11:00', '') === [], 'the time window filters on the start');
assert(count(exam_report_people($rows)) === 2);

// --- The upload ------------------------------------------------------------------------------
$sheet = [['Date', 'Start', 'End', 'Reporting', 'School', 'Class', 'Subject', 'Room', 'Invigilators needed', 'Invigilators'],
          ['25/11/2026', '2.00 pm', '5.00 pm', '', 'School of Engineering', 'B.Tech CSE Sem V', 'Physics', '101', '2', 'Asha Rao; Someone New'],
          ['', '', '', '', '', '', '', '', '', '']];
[$added, $updated, $problems] = exam_import($sheet, $faculty, 'admin@x.in');
assert($added === 1 && $updated === 0, "import: $added added, $updated updated");
assert(count($problems) === 1 && str_contains($problems[0], 'Someone New'));
$d = exam_data();
$new = array_values(array_filter($d['exams'], fn($x) => $x['subject'] === 'Physics'))[0];
assert($new['start'] === '14:00' && $new['report'] === '13:30' && $new['date'] === '2026-11-25');
$people = array_column(exam_duties_of($d, $new['id']), 'person', 'name');
assert($people['Dr. Asha Rao'] === 'pa', 'a name on the timetable is matched');
assert($people['Someone New'] === '', 'a name not on it is kept as written');
[$added, $updated] = exam_import($sheet, $faculty, 'admin@x.in');
assert($added === 0 && $updated === 1 && count(exam_duties_of(exam_data(), $new['id'])) === 2, 'uploading again updates and does not duplicate');
$export = exam_export_rows(exam_data(), exam_list(exam_data()));
assert($export[0] === EXAM_COLUMNS && count($export) === 3);
$back = exam_import($export, $faculty, 'admin@x.in');
assert($back[0] === 0 && $back[1] === 2, 'a downloaded schedule uploads back as updates');

echo "OK\n";
