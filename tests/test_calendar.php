<?php
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/test_calendar.php
//
// Holidays and events. The one thing a holiday may change is whether a class
// that filed nothing is at fault, so most of this is about exactly who a
// holiday covers: the wrong school or year would hide a class that really did
// not file.

define('STORE_DIR', sys_get_temp_dir() . '/adypu-test-cal-' . getmypid());
require_once __DIR__ . '/../includes/calendar.php';

register_shutdown_function(function () {
    foreach (glob(STORE_DIR . '/*') ?: [] as $f) unlink($f);
    @rmdir(STORE_DIR);
});

$entry = fn(array $over): array => $over + ['id' => 'x', 'title' => 'Diwali', 'type' => 'holiday',
    'from' => '2026-11-09', 'to' => '2026-11-11', 'schools' => [], 'years' => []];

// --- The form ---------------------------------------------------------------
[$e, $err] = calendar_clean(['title' => '  Diwali   break ', 'type' => 'holiday', 'from' => '2026-11-09', 'to' => '2026-11-11', 'for' => 'all', 'for_years' => 'all']);
assert($err === '' && $e['title'] === 'Diwali break', 'a name is tidied');
assert($e['schools'] === [] && $e['years'] === [], 'all means an empty list');

[$e] = calendar_clean(['title' => 'Visit', 'type' => 'event', 'from' => '2026-11-09', 'to' => '', 'for' => 'some', 'schools' => ['eng', 'nope'], 'for_years' => 'some', 'years' => ['2nd Year', '9th Year']]);
assert($e['to'] === '2026-11-09', 'no last day means one day');
assert($e['schools'] === ['eng'] && $e['years'] === ['2nd Year'], 'only real schools and years survive');

// Choices left over from Pick schools are ignored once All is chosen.
[$e] = calendar_clean(['title' => 'A', 'type' => 'event', 'from' => '2026-11-09', 'for' => 'all', 'schools' => ['eng'], 'for_years' => 'all', 'years' => ['1st Year']]);
assert($e['schools'] === [] && $e['years'] === [], 'All overrides stale ticks');

foreach ([
    ['title' => '', 'type' => 'holiday', 'from' => '2026-11-09'],
    ['title' => 'A', 'type' => 'party', 'from' => '2026-11-09'],
    ['title' => 'A', 'type' => 'holiday', 'from' => ''],
    ['title' => 'A', 'type' => 'holiday', 'from' => '2026-11-09', 'to' => '2026-11-08'],
    ['title' => 'A', 'type' => 'holiday', 'from' => '2026-11-09', 'for' => 'some'],
    ['title' => 'A', 'type' => 'holiday', 'from' => '2026-11-09', 'for_years' => 'some', 'years' => ['9th Year']],
] as $bad) {
    assert(calendar_clean($bad)[0] === null, 'refused: ' . json_encode($bad));
}

// --- Who it covers ----------------------------------------------------------
$eng2 = $entry(['schools' => ['eng'], 'years' => ['2nd Year']]);
assert(calendar_is_off([$eng2], '2026-11-10', 'eng', '2nd Year'), 'its own class is off');
assert(!calendar_is_off([$eng2], '2026-11-10', 'eng', '1st Year'), 'another year is not');
assert(!calendar_is_off([$eng2], '2026-11-10', 'law', '2nd Year'), 'another school is not');
assert(!calendar_is_off([$eng2], '2026-11-12', 'eng', '2nd Year'), 'the day after is not');
assert(calendar_is_off([$entry([])], '2026-11-09', 'law', '1st Year'), 'a whole university holiday covers everyone');
assert(!calendar_is_off([$entry(['type' => 'event'])], '2026-11-10', 'eng', '1st Year'), 'an event never takes a class off');

assert(calendar_applies($eng2) && calendar_applies($eng2, 'eng'), 'a filter left on All still lists it');
assert(!calendar_covers($eng2) && !calendar_covers($eng2, 'eng'), 'but it does not cover all of them');
assert(calendar_covers($eng2, 'eng', '2nd Year'), 'and does once the filter is that class');

// --- A range ----------------------------------------------------------------
// 7 to 11 Nov 2026 is Sat to Wed, so it holds a Sunday.
$long = [$entry(['from' => '2026-11-07', 'to' => '2026-11-11'])];
assert(calendar_off($long, '2026-11-07', '2026-11-11', 'eng', '1st Year'), 'all off');
assert(!calendar_off($long, '2026-11-07', '2026-11-12', 'eng', '1st Year'), 'one working day past it is not');
assert(calendar_off([$entry(['from' => '2026-11-07', 'to' => '2026-11-07']), $entry(['from' => '2026-11-09', 'to' => '2026-11-09'])],
    '2026-11-07', '2026-11-09', 'eng', '1st Year'), 'a Sunday between holidays does not count against them');
assert(!calendar_off([], '2026-11-08', '2026-11-08', 'eng', '1st Year'), 'a plain Sunday is not a holiday');

// --- Saving -----------------------------------------------------------------
$a = calendar_save(['title' => 'Late', 'type' => 'event', 'from' => '2026-12-01', 'to' => '2026-12-01', 'schools' => [], 'years' => []]);
$b = calendar_save(['title' => 'Early', 'type' => 'holiday', 'from' => '2026-11-01', 'to' => '2026-11-01', 'schools' => [], 'years' => []]);
assert(array_column(calendar_all(), 'title') === ['Early', 'Late'], 'earliest first');
assert(calendar_save(['title' => 'Later', 'type' => 'event', 'from' => '2026-12-01', 'to' => '2026-12-01', 'schools' => [], 'years' => []], $a) === $a, 'an edit keeps its id');
assert(count(calendar_all()) === 2, 'an edit adds nothing');
assert(calendar_save(['title' => 'Gone', 'type' => 'event', 'from' => '2026-12-01', 'to' => '2026-12-01', 'schools' => [], 'years' => []], 'nope') === '', 'editing a deleted entry saves nothing');
calendar_delete($b);
assert(array_column(calendar_all(), 'title') === ['Later'], 'deleted');
assert(str_starts_with(file_get_contents(store_path('calendar')), '<?php exit;'), 'the file is guarded');

// --- What the pages draw ----------------------------------------------------
$chart = calendar_chart_days([$entry(['from' => '2026-11-07', 'to' => '2026-11-11'])], '2026-11-09', '2026-11-10');
assert(array_keys($chart) === ['2026-11-09', '2026-11-10'], 'the chart only gets the days it draws');

$html = calendar_strips([$entry(['title' => 'A & B', 'schools' => ['eng']])], '2026-11-09', '2026-11-09', 'note');
assert(str_contains($html, 'A &amp; B') && str_contains($html, 'data-schools="eng"') && str_contains($html, 'note'), 'a strip is escaped and carries its schools');
$many = array_fill(0, CALENDAR_STRIPS + 2, $entry([]));
assert(str_contains(calendar_strips($many, '2026-11-09', '2026-11-09'), '2 more on the Events tab'), 'a busy range points at the tab');

echo "OK\n";
