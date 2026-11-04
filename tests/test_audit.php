<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_audit.php
//
// The academic audit. Two things matter most: every subject a person teaches
// becomes one course file (no lab split off, no division counted twice), and
// once submitted a file cannot change unless a lead hands it back.

define('STORE_DIR', sys_get_temp_dir() . '/adypu-test-audit-' . getmypid());
require_once __DIR__ . '/../includes/audit.php';

register_shutdown_function(function () {
    foreach (glob(STORE_DIR . '/{,*/}*', GLOB_BRACE) ?: [] as $f) is_file($f) && unlink($f);
    foreach (glob(STORE_DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) @rmdir($d);
    @rmdir(STORE_DIR);
});

// --- Year and semester off a class label --------------------------------------
assert(audit_year_sem('B.Tech CSE Sem VII Div A') === [4, 7], 'Sem VII is 4th year');
assert(audit_year_sem('B.Tech First Year Div AB') === [1, 1], 'a year alone is its odd semester');
assert(audit_year_sem('MDMC III Year') === [3, 5], 'roman year');
assert(audit_year_sem('MA Politics & IR') === [0, 0], 'neither');

// --- Divisions share a course file -------------------------------------------
assert(audit_class_group('B.Tech First Year Div F') === ['B.Tech First Year', ['Div F']]);
assert(audit_class_group('B.Sc Sem I (Div A)') === ['B.Sc Sem I', ['Div A']]);
assert(audit_class_group('B.Tech AIDS Sem V Div A; B.Tech AIDS Sem V Div B') === ['B.Tech AIDS Sem V', ['Div A', 'Div B']]);

$t = [
    'rooms' => ['101' => [
        ['faculty' => 'Dr. A Rao', 'code' => 'Data Structures', 'class' => 'B.Tech CSE Sem III Div A', 'lab' => false, 'blocked' => ''],
        ['faculty' => 'Prof. A Rao', 'code' => 'Data Structures Lab', 'class' => 'B.Tech CSE Sem III Div B', 'lab' => true, 'blocked' => ''],
        ['faculty' => 'A Rao / B Sen', 'code' => 'Front Office I / Retail', 'class' => 'B.Sc HHA Sem I; M.Sc HHA Sem III', 'lab' => false, 'blocked' => ''],
        ['faculty' => '', 'code' => '', 'class' => '', 'lab' => false, 'blocked' => 'Booked'],
    ]],
    'calendars' => [], 'extra' => [],
];
$faculty = ['a-rao' => ['name' => 'Dr. A Rao', 'as' => ['a rao']], 'b-sen' => ['name' => 'B Sen', 'as' => ['b sen']]];
$courses = audit_courses($t, $faculty);
$rao = array_values(array_filter($courses, fn($c) => $c['person'] === 'a-rao'));
$ds = array_values(array_filter($rao, fn($c) => $c['subject'] === 'Data Structures'));
assert(count($ds) === 1, 'theory and lab, two divisions: one course file');
assert($ds[0]['divs'] === ['Div A', 'Div B'] && $ds[0]['theory'] && $ds[0]['lab'] && $ds[0]['school'] === 'eng');
assert(count(array_filter($rao, fn($c) => $c['subject'] === 'Front Office I' && $c['class'] === 'B.Sc HHA Sem I')) === 1, 'a shared slot splits by subject and class');
assert(count(array_filter($courses, fn($c) => $c['person'] === 'b-sen')) === 2, 'both teachers of a shared slot get it');

// --- Accounts to people, and who sees what -----------------------------------
assert(audit_person_for(['name' => 'A Rao'], $faculty) === 'a-rao', 'matched by name');
assert(audit_person_for(['name' => 'Someone', 'tt' => 'b-sen'], $faculty) === 'b-sen', 'an admin link wins');
assert(audit_role(['role' => 'lead']) === 'lead' && audit_role(['role' => 'x']) === 'faculty' && audit_role(['admin' => true, 'role' => 'lead']) === 'admin');
$c = $ds[0];
assert(audit_can_view(['role' => 'lead', 'school' => 'eng'], '', $c) && !audit_can_view(['role' => 'lead', 'school' => 'law'], '', $c), 'a lead sees their school only');
assert(audit_can_view(['role' => 'dean'], '', $c) && !audit_can_view([], 'b-sen', $c) && audit_can_view([], 'a-rao', $c));
assert(audit_can_review(['role' => 'lead', 'school' => 'eng'], $c) && !audit_can_review(['role' => 'dean'], $c), 'the main dean reads, a lead reviews');
assert(audit_can_view(['role' => 'school', 'school' => 'eng'], '', $c) && !audit_can_view(['role' => 'school', 'school' => 'law'], '', $c), 'a school dean sees their school');
assert(!audit_can_review(['role' => 'school', 'school' => 'eng'], $c), 'a school dean only reads');
assert(audit_can_set_deadline(['role' => 'dean']) && !audit_can_set_deadline(['role' => 'school']) && audit_can_set_deadline(['admin' => true]));

// --- Submit once ---------------------------------------------------------------
assert(str_starts_with(audit_submit($c, true, 'a@x'), 'Still to upload'), 'nothing in, nothing submitted');
// Every required item filled straight into the store, as an upload would.
store_update('audit', function ($d) use ($c) {
    foreach (AUDIT_ITEMS as $k => [, $req]) if ($req) $d['files'][$c['id']]['items'][$k] = ['blob' => str_repeat('a', 32), 'name' => 'x.pdf', 'ext' => 'pdf', 'size' => 1, 'at' => ''];
    return $d;
});
assert(audit_set_link($c, '12C', 'not a link', 'a@x') !== '');
assert(audit_set_link($c, '12C', 'https://nptel.ac.in/x', 'a@x') === '');
assert(audit_submit($c, false, 'a@x') !== '', 'the pop-up must say yes');
assert(audit_review($c, ['1'], [], '', 'l@x') !== '', 'nothing to review before submit');
assert(audit_submit($c, true, 'a@x') === '');
assert(audit_status(audit_file(audit_all(), $c['id'])) === 'submitted');
assert(audit_set_link($c, '12C', 'https://other.example/', 'a@x') !== '', 'locked after submit');
assert(audit_remove($c, '1', 'a@x') !== '', 'nothing removed after submit');

assert(audit_review($c, ['1', '2', '16'], ['7' => 'Add the ICT column'], 'Fine', 'l@x') === '');
$f = audit_file(audit_all(), $c['id']);
assert(audit_status($f) === 'approved' && $f['items']['1']['ok'] && !$f['items']['3']['ok']);
assert(!isset($f['items']['16']), 'an item never uploaded cannot be approved');
assert($f['items']['7']['remark'] === 'Add the ICT column' && $f['note'] === 'Fine');
$sum = audit_summary($courses, audit_all(), $faculty, ['eng']);
assert($sum['eng']['a-rao']['reviewed'] === 1 && $sum['eng']['a-rao']['approved'] === 2 && count($sum['eng']['a-rao']['remarks']) === 2);

// The deadline: on time by the first submission's day, late after it.
$f = audit_file(audit_all(), $c['id']);
$day = substr($f['first'], 0, 10);
assert(audit_timing($f, '') === 'ontime' && audit_timing($f, $day) === 'ontime');
assert(audit_timing($f, date('Y-m-d', strtotime("$day -1 day"))) === 'late');
assert(audit_timing(audit_file([], 'none'), $day) === 'pending');
assert(audit_set_deadline('nope', 'd@x') !== '' && audit_set_deadline('2026-11-20', 'd@x') === '' && audit_deadline() === '2026-11-20');
assert(audit_due_label('2026-11-20', '2026-11-04') === '16 days left' && audit_due_label('2026-11-20', '2026-11-20') === 'Due today' && audit_due_label('2026-11-20', '2026-11-23') === 'Passed 3 days ago');
$rows = audit_rows($courses, audit_all(), $faculty, ['eng'], '2026-11-20');
assert(count(array_filter($rows, fn($r) => $r['timing'] === 'ontime')) === 1);
assert(audit_person_timing(['pending' => 1, 'late' => 0]) === 'pending' && audit_person_timing(['pending' => 0, 'late' => 2]) === 'late');

assert(audit_reopen($c, 'l@x') === '');
$f = audit_file(audit_all(), $c['id']);
assert(audit_status($f) === 'draft' && $f['reopened'] !== '' && $f['items']['7']['remark'] !== '', 'reopened keeps the remarks');
assert(audit_remove($c, '12C', 'a@x') === '', 'and can change again');
assert(audit_submit($c, true, 'a@x') !== '', 'missing 12C again');
assert(audit_set_link($c, '12C', 'https://nptel.ac.in/y', 'a@x') === '' && audit_submit($c, true, 'a@x') === '');
assert(audit_file(audit_all(), $c['id'])['first'] === $f['first'], 'a resubmit keeps the first submission, so it stays on time');

echo "OK\n";
