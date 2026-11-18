<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_exams.php
//
// The exam duty logic: parsing what a schedule sheet holds, the late rule and
// the mail it queues, the hand-off to the Apps Script, and the upload.

// Before store.php is loaded, so nothing here touches the live data directory.
define('STORE_DIR', __DIR__ . '/../cache/test-exams-' . getmypid());
// Pinned, so the dates below stay ahead of "today" whenever this runs.
define('EXAM_TODAY', '2026-11-18');
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

// Present stamps the clock at the click: after the 09:30 reporting time is
// late, and queues one mail when an email is known.
assert(exam_set_status($id, $duty['pa'], 'present', $emails, 'admin@x.in', '2026-11-24 09:50') === 1);
assert(exam_set_status($id, $duty['pb'], 'present', $emails, 'admin@x.in', '2026-11-24 09:20') === 0);
$d = exam_data();
assert($d['duties'][$duty['pa']]['status'] === 'late' && $d['duties'][$duty['pa']]['arrived'] === '09:50', 'a click after the reporting time is late');
assert($d['duties'][$duty['pb']]['status'] === 'ontime' && $d['duties'][$duty['pb']]['arrived'] === '09:20');
assert(count($d['mail']) === 1 && $d['mail'][$duty['pa']]['to'] === 'asha@adypu.edu.in', 'only the late duty with an email is mailed');
assert(exam_set_status($id, $duty['pa'], 'ontime', $emails, 'admin@x.in') === 0 && exam_data()['duties'][$duty['pa']]['status'] === 'late', 'On time cannot be chosen');
assert(exam_set_status($id, $duty['pa'], 'late', $emails, 'admin@x.in') === 0, 'nor Late');

assert(exam_arrived(exam_data()['duties'][$duty['pa']], exam_data()['exams'][$id]) === exam_clock('09:50'), 'a click on the day shows its time alone');

// Present again keeps the first stamp, so the time cannot be redone.
exam_set_status($id, $duty['pa'], 'present', $emails, 'admin@x.in', '2026-11-24 09:00');
assert(exam_data()['duties'][$duty['pa']]['arrived'] === '09:50' && count(exam_data()['mail']) === 1, 'and does not mail twice');

// Absent clears the time; Present after it stamps afresh. No address, no mail.
exam_set_status($id, $duty['pb'], 'absent', [], 'admin@x.in');
assert(exam_data()['duties'][$duty['pb']]['status'] === 'absent' && exam_data()['duties'][$duty['pb']]['arrived'] === '');
exam_set_status($id, $duty['pb'], 'present', [], 'admin@x.in', '2026-11-24 09:45');
assert(exam_data()['duties'][$duty['pb']]['status'] === 'late' && count(exam_data()['mail']) === 1, 'no address, no mail');

// A Present clicked on another day keeps its time, and says which day.
exam_set_status($id, $duty['pb'], 'absent', [], 'admin@x.in');
exam_set_status($id, $duty['pb'], 'present', [], 'admin@x.in', '2026-11-18 15:26');
$pbDuty = exam_data()['duties'][$duty['pb']];
assert($pbDuty['status'] === 'ontime' && exam_arrived($pbDuty, exam_data()['exams'][$id]) === exam_clock('15:26') . ', 18 Nov', 'before the exam day: ' . exam_arrived($pbDuty, exam_data()['exams'][$id]));
// A mark from before the time was always kept still shows when it was clicked.
assert(exam_arrived(['status' => 'ontime', 'arrived' => '', 'marked_at' => '2026-11-18 11:05'], ['date' => '2026-11-24']) === exam_clock('11:05') . ', 18 Nov');
assert(exam_arrived(['status' => 'absent', 'arrived' => '', 'marked_at' => '2026-11-18 11:05'], ['date' => '2026-11-24']) === '');

// Not needed: a reserve who was not called on, out of the attendance counts.
exam_set_status($id, $duty['pb'], 'na', [], 'admin@x.in');
assert(exam_data()['duties'][$duty['pb']]['status'] === 'na' && exam_data()['duties'][$duty['pb']]['arrived'] === '');

// Remarks are saved apart from status.
exam_remarks($id, [$duty['pb'] => '  reserve  '], 'admin@x.in');
assert(exam_data()['duties'][$duty['pb']]['remark'] === 'reserve' && exam_data()['duties'][$duty['pb']]['status'] === 'na');
exam_set_status($id, $duty['pb'], 'absent', [], 'admin@x.in');

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

// --- An upload marked as test data ---------------------------------------------------------
$test = [EXAM_COLUMNS, ['2026-12-03', '15:00', '16:00', '', 'School of Engineering', 'B.Tech First Year', 'Unit Test', '', '1', 'Asha Rao']];
[$added] = exam_import($test, $faculty, 'admin@x.in', true);
$ut = array_values(array_filter(exam_data()['exams'], fn($x) => $x['subject'] === 'Unit Test'))[0];
$utDuty = (string) array_key_first(exam_duties_of(exam_data(), $ut['id']));
assert($added === 1 && $ut['sample'] && !empty(exam_data()['duties'][$utDuty]['sample']), 'a test-data upload flags its exams and duties');
assert(exam_set_status($ut['id'], $utDuty, 'present', ['pa' => 'a@x.in'], 'admin@x.in', '2026-12-03 15:00') === 0 && exam_data()['duties'][$utDuty]['status'] === 'late', 'test data never mails');
exam_clear_sample();
assert(!isset(exam_data()['exams'][$ut['id']]) && !isset(exam_data()['duties'][$utDuty]), 'and Remove sample data clears it');

// --- One-click status and editing an exam -------------------------------------------------------
$pd = (string) array_key_first(array_filter(exam_duties_of(exam_data(), $new['id']), fn($d) => $d['person'] === 'pa'));
assert(exam_set_status($new['id'], $pd, 'present', ['pa' => 'a@x.in'], 'admin@x.in', '2026-11-25 14:05') === 1, 'a late Present queues the email');
assert(exam_set_status($new['id'], $pd, 'present', ['pa' => 'a@x.in'], 'admin@x.in', '2026-11-25 14:06') === 0, 'and only once');
exam_set_status($new['id'], $pd, 'absent', [], 'admin@x.in');
assert(exam_data()['duties'][$pd]['status'] === 'absent' && exam_data()['duties'][$pd]['arrived'] === '');
assert(exam_set_status($id, $pd, 'present', [], 'admin@x.in') === 0 && exam_data()['duties'][$pd]['status'] === 'absent', 'a duty of another exam is left alone');
assert(exam_update($new['id'], ['date' => '2026-11-26', 'start' => '10:00', 'end' => '13:00', 'school' => 'eng', 'class' => 'B.Tech CSE Sem V', 'subject' => 'Physics', 'room' => '202', 'need' => 3]) === '');
$moved = exam_data()['exams'][$new['id']];
assert($moved['date'] === '2026-11-26' && $moved['room'] === '202' && $moved['report'] === '09:30' && count(exam_duties_of(exam_data(), $new['id'])) === 2, 'an edit keeps the id and the duties');

// --- The exam cell's duty chart layout -------------------------------------------------------
assert(exam_chart_time('12:30 pm to 1:30 pm') === ['12:30', '13:30']);
assert(exam_chart_time('3:00-4:00PM') === ['15:00', '16:00']);
assert(exam_chart_time('10:30-11:30AM') === ['10:30', '11:30']);
assert(exam_chart_time('11:30-12:30 pm') === ['11:30', '12:30'], 'a morning start keeps its am');
assert(exam_chart_date('29th October (E)', 2026) === '2026-10-29');
assert(exam_chart_date('26-10-2026', 2000) === '2026-10-26');
assert(exam_chart_date('Duties', 2026) === '');
$chart = [
    ['Unit Test Duty Chart (Lateral Entry Students) - December 2026', '', '', '', ''],
    ['S. No', 'Name of Faculty', '7th December (M)', '', '8-12-2026'],
    ['', '', '10:30-11:30AM', '3:00-4:00PM', '12:30 pm to 1:30 pm'],
    ['1', 'Dr. Asha Rao', '✓', '', '√'],
    ['2', 'Prof. Bimal Das', '', 'Y', '✓'],
    ['3', 'Someone Else', '', '', ''],
    ['', 'ECR In-charge', '', '', ''],
];
$c = exam_chart_parse($chart);
assert($c['subject'] === 'Unit Test' && $c['class'] === 'Lateral Entry Students', 'subject and class from the title');
assert(count($c['sessions']) === 3 && $c['sessions'][1]['date'] === '2026-12-07' && $c['sessions'][1]['start'] === '15:00', 'a merged date repeats over the next column');
assert($c['sessions'][2]['names'] === ['Dr. Asha Rao', 'Prof. Bimal Das']);
$noSchool = exam_import($chart, $faculty, 'admin@x.in');
assert($noSchool[0] === 0 && str_contains($noSchool[2][0], 'school'), 'a chart needs a school');
[$added, $updated, $problems] = exam_import($chart, $faculty, 'admin@x.in', true, 'eng');
assert($added === 3 && $updated === 0 && !$problems, "chart: $added added, " . implode(' / ', $problems));
$s3 = array_values(array_filter(exam_data()['exams'], fn($x) => $x['date'] === '2026-12-08'))[0];
assert($s3['need'] === 2 && $s3['subject'] === 'Unit Test' && $s3['school'] === 'eng' && count(exam_duties_of(exam_data(), $s3['id'])) === 2);
assert(exam_import($chart, $faculty, 'admin@x.in', true, 'eng')[1] === 3, 'the same chart again updates its sessions');
assert(count(exam_duties_of(exam_data(), $s3['id'])) === 2, 'and adds nobody twice');

// The chart as the page exports it uploads straight back.
require_once __DIR__ . '/../includes/exams-doc.php';
$s3Duty = (string) array_key_first(exam_duties_of(exam_data(), $s3['id']));
exam_set_status($s3['id'], $s3Duty, 'present', [], 'admin@x.in', '2026-12-08 12:10');
$ids = array_keys(array_filter(exam_data()['exams'], fn($x) => $x['subject'] === 'Unit Test'));
$doc = exam_doc_chart(exam_data(), array_intersect_key(exam_data()['exams'], array_flip($ids)), '', 'School of Engineering', 'x');
assert($doc['title'] === 'Unit Test Duty Chart (Lateral Entry Students)', $doc['title']);
$flat = array_map(fn($r) => array_map(fn($v) => is_array($v) ? $v[1] : (string) $v, $r), $doc['sheet']);
assert(in_array('✓ ' . exam_clock('12:10'), array_merge(...$flat), true) && str_contains($doc['body'], '<small>' . exam_clock('12:10') . '</small>'), 'a tick carries the time Present was clicked');
assert(exam_import($flat, $faculty, 'admin@x.in', true, 'eng')[1] === 3, 'a downloaded chart uploads back as updates');
exam_clear_sample();

// --- Past exams are kept as they were ----------------------------------------------------------
assert(exam_is_past(['date' => '2026-11-17']) && !exam_is_past(['date' => '2026-11-18']), "the exam's own day is still open");
$old = [EXAM_COLUMNS, ['2026-10-29', '15:00', '16:00', '', 'School of Engineering', 'B.Tech First Year', 'Old paper', '', '1', '']];
[$added, , $problems] = exam_import($old, $faculty, 'admin@x.in');
assert($added === 0 && str_contains($problems[0], 'past'), 'an upload never writes a past exam');

// --- Signatories ----------------------------------------------------------------------------------
assert(exam_signatories(exam_data(), 'eng') === EXAM_SIGN_DEFAULT);
exam_set_signatories('', ['ECR In-charge', 'Dean SOE'], ['', 'Dr. Dean']);
assert(exam_signatories(exam_data(), 'eng') === [['ECR In-charge', ''], ['Dean SOE', 'Dr. Dean']], 'every school falls back to the shared list');
exam_set_signatories('eng', ['Head First Year'], ['']);
assert(exam_signatories(exam_data(), 'eng') === [['Head First Year', '']] && count(exam_signatories(exam_data(), 'law')) === 2);
exam_set_signatories('eng', [''], ['']);
assert(count(exam_signatories(exam_data(), 'eng')) === 2, 'an empty list goes back to the shared one');

echo "OK\n";
