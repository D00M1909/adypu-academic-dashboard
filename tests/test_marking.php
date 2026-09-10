<?php
// Run: php tests/test_marking.php
//
// The store, the accounts, and the merge that lets an app submission and a Form
// row describe the same university without either erasing the other. Every bug
// this suite exists to catch is a reading that silently stops counting, or an
// account that can write attendance when it should not.

// Before store.php is loaded, so nothing here touches the live data directory.
define('STORE_DIR', sys_get_temp_dir() . '/adypu-test-' . getmypid());
require_once __DIR__ . '/../includes/store.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';

register_shutdown_function(function () {
    foreach (glob(STORE_DIR . '/*/*.php') ?: [] as $f) unlink($f);
    foreach (glob(STORE_DIR . '/*') ?: [] as $f) is_dir($f) ? rmdir($f) : unlink($f);
    @rmdir(STORE_DIR);
});

// --- The guard line ---------------------------------------------------------
// The whole reason these files are .php and not .json. If this assert ever
// fails, student names and password hashes are one URL away from the public.
store_update('faculty', fn($d) => ['users' => ['a@b.c' => ['hash' => 'secret-hash']]]);
$raw = file_get_contents(store_path('faculty'));
assert(str_starts_with($raw, '<?php exit; ?>'), 'a store file must open with the PHP guard');
assert(store_read('faculty')['users']['a@b.c']['hash'] === 'secret-hash', 'guarded file must read back');

// What a browser actually gets: the host runs the file as a script. In a
// subprocess, because the guard's exit would take this test down with it —
// which is the point of the guard, and the reason it cannot be include()d.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(store_path('faculty')) . ' 2>&1', $out);
assert(implode('', $out) === '', 'requesting a store file over HTTP must output nothing, got: ' . implode('', $out));

// A file with no guard still reads, so a hand-edited one is not silently empty.
file_put_contents(store_path('faculty'), '{"users":{}}');
assert(store_read('faculty') === ['users' => []], 'an unguarded file must still decode');
assert(store_read('nothing-here') === [], 'a missing file is empty, not an error');

// --- merge_readings ---------------------------------------------------------
$pushed = ['2026-09-08' => ['eng|2nd Year|CSE|A' => ['09:00' => 40, '14:00' => 55]]];

// Same class, same day, same lecture: the app counted students, the Form row is
// a typed total, so the app wins.
$app = ['2026-09-08' => ['eng|2nd Year|CSE|A' => ['09:00' => 61]]];
$m = merge_readings($pushed, $app);
assert($m['2026-09-08']['eng|2nd Year|CSE|A'] === ['09:00' => 61, '14:00' => 55], 'app must win its own slot');

// The re-sort. day_present() takes the LAST reading, trusting the times to be
// in order; an unsorted merge makes a morning submission outrank the afternoon.
$late = ['2026-09-08' => ['eng|2nd Year|CSE|A' => ['08:00' => 12]]];
$m = merge_readings($pushed, $late);
assert(array_keys($m['2026-09-08']['eng|2nd Year|CSE|A']) === ['08:00', '09:00', '14:00'], 'slots must re-sort');
assert(day_present($m['2026-09-08']['eng|2nd Year|CSE|A']) === 55, 'the latest lecture is still the day');

// A class or a day the app never touched must come through untouched.
$m = merge_readings($pushed, ['2026-09-09' => ['eng|3rd Year|CS|A' => ['10:00' => 20]]]);
assert($m['2026-09-08']['eng|2nd Year|CSE|A'] === ['09:00' => 40, '14:00' => 55], 'pushed days must survive');
assert(count($m) === 2 && array_keys($m) === ['2026-09-08', '2026-09-09'], 'days must merge and sort');

// A cache written before lecture times holds a bare int for the whole day, and
// the faculty map a bare string. Both must survive being merged into.
$legacy = ['2026-09-08' => ['eng|2nd Year|CSE|A' => 41]];
$m = merge_readings($legacy, ['2026-09-08' => ['eng|2nd Year|CSE|A' => ['14:00' => 50]]]);
assert(day_present($m['2026-09-08']['eng|2nd Year|CSE|A']) === 50, 'a pre-times day must accept a new lecture');
$mf = merge_readings(['2026-09-08' => ['eng|2nd Year|CSE|A' => 'Dr Rao']],
                     ['2026-09-08' => ['eng|2nd Year|CSE|A' => ['14:00' => 'Dr Iyer']]]);
assert($mf['2026-09-08']['eng|2nd Year|CSE|A'][0] === 'Dr Rao', 'a pre-times name must survive');

// --- record_attendance ------------------------------------------------------
$cse = parse_class_label('School of Engineering / 2nd Year / CSE / A');
assert($cse['strength'] === 70, 'fixture class strength');

record_attendance($cse, '2026-09-08', '09:00', 61, 'Dr Rao', ['21BCE1001', '21BCE1002']);
$store = store_read('submissions');
assert($store['days']['2026-09-08']['eng|2nd Year|CSE|A']['09:00'] === 61, 'the count must land');
assert($store['faculty']['2026-09-08']['eng|2nd Year|CSE|A']['09:00'] === 'Dr Rao', 'the name must land beside it');

// Absentees go to their own per-day file, never into the one the dashboard
// reads on every request.
assert(!isset($store['absent']), 'roll numbers must stay off the hot path');
assert(store_read('absent/2026-09-08')['eng|2nd Year|CSE|A']['09:00'] === ['21BCE1001', '21BCE1002'],
    'absentees must be kept per day');

// Resubmitting the same slot is a correction, and overwrites.
record_attendance($cse, '2026-09-08', '09:00', 58, 'Dr Rao');
assert(store_read('submissions')['days']['2026-09-08']['eng|2nd Year|CSE|A']['09:00'] === 58, 'a correction overwrites');

// The trust boundary: the no-roster path takes a number a phone posted.
record_attendance($cse, '2026-09-08', '11:00', 999, 'Dr Rao');
record_attendance($cse, '2026-09-08', '12:00', -5, 'Dr Rao');
$d = store_read('submissions')['days']['2026-09-08']['eng|2nd Year|CSE|A'];
assert($d['11:00'] === 70, 'present must clamp to the class strength: ' . $d['11:00']);
assert($d['12:00'] === 0, 'present must not go negative: ' . $d['12:00']);

// An anonymous submission stores no name rather than an empty one, matching how
// every row from before the Form asked for one behaves.
record_attendance($cse, '2026-09-07', '09:00', 60, '');
assert(!isset(store_read('submissions')['faculty']['2026-09-07']), 'a missing name is absent, not empty');

// --- The whole way through --------------------------------------------------
// No cache file in a test checkout, so get_attendance_days() must serve the
// submissions rather than fall back to the sample rows.
$days = get_attendance_days();
assert(isset($days['2026-09-08']['eng|2nd Year|CSE|A']), 'submissions must reach the dashboard');
$tree = aggregate_days($days, '2026-09-08', '2026-09-08');
$cseA = null;
foreach ($tree['eng']['2nd Year']['CSE'] as $div) if ($div['division'] === 'A') $cseA = $div;
// 12:00 is the day's latest reading, and it clamped to 0.
assert($cseA['reported'] === true, 'the class must count as reported');
assert($cseA['present'] === 0, 'the tile takes the latest lecture: ' . $cseA['present']);

// --- Accounts ---------------------------------------------------------------
// The config-admin path (ADMIN_EMAIL / ADMIN_HASH) is deliberately not exercised
// here: it comes from includes/config.local.php, which exists only on a real
// server, and a test that passes or fails on whether a machine has one is worse
// than no test.
require_once __DIR__ . '/../includes/auth.php';
session_start();

store_update('faculty', fn() => ['codes' => ['eng' => 'ABCD-2345'], 'users' => []]);

// No code and a wrong code both queue rather than refuse: a teacher who missed
// the memo must end up somewhere an admin can see them, not at a dead end.
assert(auth_signup('No Code', 'a@adypu.edu.in', 'hunter2hunter2', 'eng', '') === '', 'signup with no code must succeed');
assert(auth_find('a@adypu.edu.in')['status'] === 'pending', 'no code means pending');
assert(auth_signup('Bad Code', 'b@adypu.edu.in', 'hunter2hunter2', 'eng', 'ZZZZ-9999') === '', 'a wrong code must not refuse');
assert(auth_find('b@adypu.edu.in')['status'] === 'pending', 'a wrong code means pending');

// The right code, however it was copied off a whiteboard.
assert(auth_signup('Good Code', 'c@adypu.edu.in', 'hunter2hunter2', 'eng', 'abcd2345') === '', 'signup with the code');
assert(auth_find('c@adypu.edu.in')['status'] === 'active', 'the right code activates on the spot');

assert(auth_signup('Dupe', 'C@ADYPU.edu.in', 'hunter2hunter2', 'eng', '') !== '', 'emails are case-insensitively unique');
assert(auth_signup('Short', 'd@adypu.edu.in', 'short', 'eng', '') !== '', 'a short password must be refused');
assert(auth_signup('No School', 'e@adypu.edu.in', 'hunter2hunter2', 'nope', '') !== '', 'the school must be a real one');
assert(!password_verify('hunter2hunter2', 'hunter2hunter2'), 'sanity: passwords are not stored in the clear');
assert(str_starts_with(auth_find('c@adypu.edu.in')['hash'], '$2y$'), 'passwords must be bcrypt hashed');

// Signing in. The same message for a wrong password and an unknown email, so
// the form cannot be used to enumerate who works here.
assert(auth_login('c@adypu.edu.in', 'hunter2hunter2') === '', 'the right password signs in');
assert(auth_user()['name'] === 'Good Code', 'the session must resolve to the account');
$wrongPassword = auth_login('c@adypu.edu.in', 'nope-nope-nope');
$noSuchUser = auth_login('nobody@adypu.edu.in', 'nope-nope-nope');
assert($wrongPassword !== '' && $wrongPassword === $noSuchUser, 'a wrong password and an unknown email must read the same');

// Lockout, so the password is not simply enumerable.
for ($i = 0; $i < AUTH_MAX_FAILS; $i++) auth_login('c@adypu.edu.in', 'wrong-guess-' . $i);
assert(str_contains(auth_login('c@adypu.edu.in', 'hunter2hunter2'), 'Too many attempts'),
    'the right password must be refused while locked out');

// Disabling takes effect on the account's next click, not its next sign-in:
// auth_user() re-reads the record every request rather than trusting the session.
auth_login('a@adypu.edu.in', 'hunter2hunter2');
assert(auth_user() !== null, 'a pending account may sign in, to be told it is pending');
auth_put('a@adypu.edu.in', ['status' => 'disabled']);
assert(auth_user() === null, 'a disabled account must lose an already-open session');

// A pending account has an account but no permission to write attendance, which
// is a distinction mark.php makes before it records anything.
auth_login('b@adypu.edu.in', 'hunter2hunter2');
assert((auth_user()['status'] ?? '') === 'pending', 'pending is visible to the page that gates on it');

// CSRF.
$_POST['csrf'] = csrf_token();
assert(csrf_ok(), 'the real token must pass');
$_POST['csrf'] = 'not-the-token';
assert(!csrf_ok(), 'a wrong token must fail');
$_POST['csrf'] = '';
assert(!csrf_ok(), 'an empty token must fail');

// --- The bootstrap admin, and changing your own password --------------------
// A subprocess with ADMIN_EMAIL / ADMIN_HASH of its own. Those come from
// includes/config.local.php on a real server, and a test that passes or fails
// on whether this machine happens to have one is worse than no test. Defining
// them before config.php loads makes ours win: define() keeps the first value.
//
// What is actually under test is the promotion. The bootstrap admin is virtual
// and has no stored record, so it could never change its own password; letting
// it write one has to ALSO retire the config hash, or that hash stays live as a
// second door into an admin account that nobody can close without FTP.
$root = strtr(dirname(__DIR__), DIRECTORY_SEPARATOR, '/');
$bootstrapScript = STORE_DIR . '/bootstrap-admin-test.php';
file_put_contents($bootstrapScript, <<<PHP
<?php
// config.local.php defines these too on a machine that has one. Ours are set by
// the time it loads, so its define() is a duplicate-constant warning and
// nothing more — and not one worth printing.
error_reporting(E_ALL & ~E_WARNING);
define('ADMIN_EMAIL', 'boss@adypu.edu.in');
define('ADMIN_HASH', password_hash('first-password', PASSWORD_DEFAULT));
define('STORE_DIR', '{$root}/../adypu-bootstrap-' . getmypid());
@mkdir(STORE_DIR, 0775, true);
require '{$root}/includes/auth.php';
session_start();

\$fail = function (\$why) { fwrite(STDERR, \$why . PHP_EOL); exit(1); };

// Signs in from the config file alone, with nothing in the store.
if (auth_login('boss@adypu.edu.in', 'first-password') !== '') \$fail('bootstrap admin cannot sign in');
\$me = auth_user();
if (empty(\$me['admin'])) \$fail('bootstrap admin is not an admin');
if (auth_find('boss@adypu.edu.in') !== null) \$fail('bootstrap admin must not be stored yet');

// Rejections come before anything is written.
if (auth_change_password('boss@adypu.edu.in', 'wrong', 'a-good-password') === '') \$fail('a wrong current password was accepted');
if (auth_change_password('boss@adypu.edu.in', 'first-password', 'short') === '') \$fail('a too-short password was accepted');
if (auth_change_password('boss@adypu.edu.in', 'first-password', 'first-password') === '') \$fail('the unchanged password was accepted');
if (auth_find('boss@adypu.edu.in') !== null) \$fail('a rejected change still wrote a record');

// The change itself promotes it into the store.
if (auth_change_password('boss@adypu.edu.in', 'first-password', 'second-password') !== '') \$fail('the change was refused');
\$stored = auth_find('boss@adypu.edu.in');
if (\$stored === null) \$fail('the change did not create a stored record');
if (empty(\$stored['admin'])) \$fail('the promoted record lost its admin rights');
if ((\$stored['status'] ?? '') !== 'active') \$fail('the promoted record is not active');

// And the config hash is retired: the stored record wins from here on, or the
// old password would remain a way in that no one could take away.
auth_logout();
session_start();
if (auth_login('boss@adypu.edu.in', 'first-password') === '') \$fail('the old config password still signs in');
if (auth_login('boss@adypu.edu.in', 'second-password') !== '') \$fail('the new password does not sign in');
if (!empty(\$_SESSION['is_config_admin'])) \$fail('still signing in by the config path after promotion');
if (empty(auth_user()['admin'])) \$fail('the promoted admin lost its rights on sign-in');

// An ordinary account changes its password by the same call.
auth_put('teacher@adypu.edu.in', ['name' => 'T', 'school' => 'eng', 'status' => 'active',
    'hash' => password_hash('old-password', PASSWORD_DEFAULT), 'admin' => false]);
if (auth_change_password('teacher@adypu.edu.in', 'old-password', 'new-password') !== '') \$fail('a faculty change was refused');
auth_logout();
session_start();
if (auth_login('teacher@adypu.edu.in', 'old-password') === '') \$fail('a faculty old password still works');
if (auth_login('teacher@adypu.edu.in', 'new-password') !== '') \$fail('a faculty new password does not work');
if (!empty(auth_find('teacher@adypu.edu.in')['admin'])) \$fail('a faculty change granted admin');

foreach (glob(STORE_DIR . '/*.php') ?: [] as \$f) unlink(\$f);
@rmdir(STORE_DIR);
echo 'bootstrap-ok';
PHP);

$bootstrapOut = [];
$bootstrapCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootstrapScript) . ' 2>&1', $bootstrapOut, $bootstrapCode);
assert($bootstrapCode === 0 && in_array('bootstrap-ok', $bootstrapOut, true),
    "the bootstrap admin / change-password path failed:\n  " . implode("\n  ", $bootstrapOut));

// --- Importing a returned student list --------------------------------------
// Run as a subprocess against the test data directory, because that is how the
// tool is actually used, and because its exit code and its warnings are half of
// what it is for: a row that names no real class must be reported, never
// silently dropped, which is the single failure mode this project keeps hitting.
$csv = STORE_DIR . '/import-fixture.csv';
file_put_contents($csv, implode("\n", [
    'SCHOOL OF HOSPITALITY',
    '',
    'Year,Branch (blank if none),Division,ROLL NUMBER,STUDENT NAME',
    '1st Year,MSc Hospitality and Hotel Administration,A,24MHM1001,Priya N',
    '1st Year,MSc Hospitality and Hotel Administration,A,24MHM1002,Rahul K',
    '1st Year,MSc Hospitality and Hotel Administration,A,24MHM1002,Duplicate Roll',
    '1st Year,Nonexistent Branch,Z,24XXX0001,Wrong Class',
    '2nd Year,MSc Hospitality and Hotel Administration,A,,',
]) . "\n");

// putenv rather than a VAR=value shell prefix: the child inherits it either
// way, and cmd.exe does not understand the prefix.
putenv('ADYPU_DATA_DIR=' . STORE_DIR);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/roster-import.php')
     . ' hosp ' . escapeshellarg($csv) . ' 2>&1';
exec($cmd, $lines, $status);
$report = implode("\n", $lines);
assert($status === 0, "the import must succeed:\n$report");
assert(str_contains($report, 'IGNORED'), "a row naming no real class must be reported:\n$report");
assert(str_contains($report, 'DUPLICATE'), "a repeated roll number must be reported:\n$report");

$class = parse_class_label('School of Hospitality / 1st Year / MSc Hospitality and Hotel Administration / A');
$imported = store_read('roster/hosp')[class_key($class)];
assert(count($imported) === 2, 'the duplicate and the bad row must not be stored: ' . count($imported));
assert($imported[0] === ['roll' => '24MHM1001', 'name' => 'Priya N'], 'roll and name must round-trip');
// An unfilled pre-printed row is not an error: the request ships more rows than
// some divisions need, and every one of them comes back blank.
assert(!isset(store_read('roster/hosp')['hosp|2nd Year|MSc Hospitality and Hotel Administration|A']),
    'a row with no student on it must not become a student');

// The marking screen reads exactly what the import wrote.
require_once __DIR__ . '/../includes/roster.php';
assert(count(roster_for($class)) === 2, 'the roster must reach the marking screen');

echo "OK\n";
