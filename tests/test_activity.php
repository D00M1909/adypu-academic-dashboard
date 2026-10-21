<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_activity.php
//
// The account activity log (includes/activity.php) and the leaderboard: events
// land behind the guard line and read back in order, a save that replaced
// someone else's is counted as such, old days are pruned, and the leaderboard
// counts each lecture once, Form and app together, one person per name.

define('STORE_DIR', sys_get_temp_dir() . '/adypu-test-activity-' . getmypid());
require_once __DIR__ . '/../includes/activity.php';

register_shutdown_function(function () {
    foreach (glob(STORE_DIR . '/*/*.php') ?: [] as $f) unlink($f);
    foreach (glob(STORE_DIR . '/*') ?: [] as $f) is_dir($f) ? rmdir($f) : unlink($f);
    @rmdir(STORE_DIR);
});

$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36';
$at = strtotime('2026-10-21 10:05:00');

// --- Writing and reading back ------------------------------------------------
activity_log('signin', 'asha@adypu.edu.in', ['how' => 'password'], $at);
activity_log('mark', 'asha@adypu.edu.in', ['class' => 'School of Law / 2nd Year / A', 'date' => '2026-10-21', 'time' => '09:00', 'present' => 50, 'strength' => 60, 'lag' => 65], $at + 60);
activity_log('mark', 'asha@adypu.edu.in', ['class' => 'School of Law / 2nd Year / A', 'date' => '2026-10-19', 'time' => '09:00', 'present' => 51, 'lag' => 3000,
    'replaced' => ['present' => 40, 'faculty' => 'Ben']], $at + 120);
activity_log('signin_failed', 'nobody@adypu.edu.in', ['why' => 'no such account'], $at + 180);

$raw = file_get_contents(activity_path('2026-10-21'));
assert(str_starts_with($raw, STORE_GUARD) && substr_count($raw, STORE_GUARD) === 1, 'one guard line, first');
$day = activity_day('2026-10-21');
assert(count($day) === 4 && array_column($day, 'type') === ['signin', 'mark', 'mark', 'signin_failed'], json_encode($day));
assert($day[0]['ip'] === '10.0.0.7' && $day[0]['device'] === 'Android, Chrome' && $day[0]['t'] === '10:05:00');
assert(activity_day('2026-10-20') === [] && activity_day('../faculty') === [], 'a missing or malformed day is empty');

// --- The per-account summary -------------------------------------------------
$s = activity_summary($day);
assert(array_keys($s) === ['asha@adypu.edu.in', 'nobody@adypu.edu.in']);
$a = $s['asha@adypu.edu.in'];
assert($a['marks'] === 2 && $a['signins'] === 1 && $a['overwrites'] === 1 && $a['late'] === 1 && $a['backdated'] === 1, json_encode($a));
assert(count($a['classes']) === 1 && $a['last'] === '2026-10-21 10:07:00');
assert($s['nobody@adypu.edu.in']['failed'] === 1 && $s['nobody@adypu.edu.in']['days'] === [], 'a failed sign-in is not a day active');

// --- Devices -----------------------------------------------------------------
assert(activity_device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit Version/17.0 Mobile Safari/604.1') === 'iPhone, Safari');
assert(activity_device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0 Safari/537.36 Edg/129.0') === 'Windows, Edge');
assert(activity_device('') === 'Unknown, Other');

// --- Pruning ------------------------------------------------------------------
activity_log('signin', 'asha@adypu.edu.in', [], strtotime('2025-01-01 09:00'));
assert(is_file(activity_path('2025-01-01')));
activity_log('signin', 'asha@adypu.edu.in', [], strtotime('2026-10-22 09:00'));   // a new day prunes
assert(!is_file(activity_path('2025-01-01')) && is_file(activity_path('2026-10-21')), 'only days past the year go');
assert(activity_first_day() === '2026-10-21');
assert(count(activity_range('2026-10-21', '2026-10-22')) === 5);

// --- The leaderboard ---------------------------------------------------------
assert(activity_person('Dr. Asha  Rao.') === 'asha rao' && activity_person('prof asha rao') === 'asha rao');
$form = [
    '2026-10-20' => ['law|2nd Year||A' => ['09:00' => 'Dr. Asha Rao', '10:00' => 'asha rao'], 'law|3rd Year||A' => ['09:00' => 'Ben Das']],
    '2026-10-21' => ['law|2nd Year||A' => ['09:00' => 'Asha Rao', '11:00' => '']],
    '2026-09-01' => ['law|2nd Year||A' => ['09:00' => 'Old Name']],
];
$app = [
    // The same lecture as the Form's 9.00: counted once, for the app.
    '2026-10-21' => ['law|2nd Year||A' => ['09:00' => 'Ben Das'], 'eng|1st Year|Core|A' => ['14:00' => 'Prof. Asha Rao']],
];
[$rows, $unnamed] = activity_leaderboard($form, $app, '2026-10-15', '2026-10-21');
assert(array_column($rows, 'key') === ['asha rao', 'ben das'], json_encode($rows));
assert($rows[0]['lectures'] === 3 && $rows[0]['form'] === 2 && $rows[0]['app'] === 1 && $rows[0]['days'] === 2 && $rows[0]['classes'] === 2, json_encode($rows[0]));
assert($rows[0]['school'] === 'law' && $rows[0]['last'] === '2026-10-21');
assert($rows[1]['lectures'] === 2 && $rows[1]['app'] === 1 && $rows[1]['form'] === 1);
assert($unnamed === 1, 'a lecture with no name counts for nobody, and is counted as such');

echo "OK\n";
