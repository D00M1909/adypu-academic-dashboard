<?php
// Run: php tests/test_attendance_parser.php
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';

// --- Four-column rows (hand-maintained sheet / DB export shape) -------------
// Note the strength column: every value here is deliberately wrong. Strength
// comes from structure.php, never from the row, so these must be ignored.
$csv = "school,year,branch,division,strength,present,date\n" .
       "eng,2nd Year,CSE,A,7,25,2026-08-26\n" .
       "eng,2nd Year,CSE,B,7,50,2026-08-26\n" .
       "eng,2nd Year,CSE,A,7,28,2026-08-26\n" . // resubmission for CSE div A, should win
       "eng,2nd Year,AIDS,A,7,20,2026-08-26\n" . // same division letter, different branch
       "mgmt,1st Year,,A,7,25,2026-08-26\n" .   // no branch structure -> branch key ''
       "eng,2nd Year,CSE,ZZ,7,99,2026-08-26\n"; // division that doesn't exist -> dropped

$rows = parse_attendance_csv($csv);
assert(count($rows) === 5, 'expected 5 parsed rows, got ' . count($rows));
assert($rows[0]['strength'] === 70, 'strength must come from structure, not the row');

$tree = aggregate_attendance($rows);

// The tree always holds every class in the structure, reported or not, so these
// counts come from structure.php rather than from what was submitted.
assert(count($tree['eng']['2nd Year']['CSE']) === 5, 'CSE should list all 5 divisions');
assert(count($tree['eng']['2nd Year']['AIDS']) === 4, 'AIDS div A should not merge with CSE div A');
assert(count($tree['mgmt']['1st Year']['']) === 2, 'branchless school should key under empty branch');

function find_div(array $tree, string $school, string $year, string $branch, string $division): ?array {
    foreach ($tree[$school][$year][$branch] as $d) {
        if ($d['division'] === $division) return $d;
    }
    return null;
}

$divA = find_div($tree, 'eng', '2nd Year', 'CSE', 'A');
assert($divA !== null, 'CSE division A missing');
assert($divA['present'] === 28, 'latest submission should win, got ' . $divA['present']);
assert($divA['reported'] === true, 'submitted division should be flagged reported');
assert(find_div($tree, 'eng', '2nd Year', 'AIDS', 'A')['present'] === 20, 'AIDS div A took CSE div A value');

// A CSE division nobody submitted still exists, at zero and unreported.
$divC = find_div($tree, 'eng', '2nd Year', 'CSE', 'C');
assert($divC['reported'] === false && $divC['present'] === 0, 'unsubmitted division should be zero/unreported');

$totals = attendance_totals($tree);
assert($totals['strength'] === 4874, 'denominator is the whole university: ' . $totals['strength']);
assert($totals['present'] === 123, 'total present wrong: ' . $totals['present']);
assert($totals['reported'] === 4, 'four distinct classes were submitted, got ' . $totals['reported']);

// --- The structure leads, submissions only fill it in -----------------------
// Two Engineering submissions and nothing else must still yield all 9 schools,
// every year and branch, and the full 4874 denominator. Before this, the tree
// was built from submitted rows alone: unreported schools showed 0/0 and their
// years and branches vanished from the drill-down entirely.
$partial = "timestamp,class (school of engineering — 4th year),present today\n" .
           '"2026-08-27","School of Engineering / 4th Year / CS / A","47"' . "\n" .
           '"2026-08-27","School of Engineering / 1st Year / CS / M","48"' . "\n";
$pTree = aggregate_attendance(parse_attendance_csv($partial));
assert(count($pTree) === 9, 'all 9 schools must exist, got ' . count($pTree));
assert(count($pTree['eng']) === 4, 'all 4 Engineering years must exist');
assert(count($pTree['eng']['2nd Year']) === 8, 'unreported year must keep its branches');
assert(isset($pTree['law']['5th Year']), 'unreported school must keep its years');

$pt = attendance_totals($pTree);
assert($pt['strength'] === 4874, 'denominator must be the whole university: ' . $pt['strength']);
assert($pt['present'] === 95, 'present should count only submissions: ' . $pt['present']);
assert($pt['reported'] === 2 && $pt['classes'] === 136,
    "reported count wrong: {$pt['reported']}/{$pt['classes']}");

// A school nobody reported has a real denominator, not 0/0.
$law = attendance_totals(['law' => $pTree['law']]);
assert($law['strength'] === 450 && $law['present'] === 0 && $law['reported'] === 0,
    "unreported school wrong: {$law['present']}/{$law['strength']} reported={$law['reported']}");

// Every division carries a reported flag so the UI can tell "nobody came" from
// "nobody submitted".
$reportedDiv = null;
foreach ($pTree['eng']['4th Year']['CS'] as $d) if ($d['division'] === 'A') $reportedDiv = $d;
assert($reportedDiv['reported'] === true && $reportedDiv['present'] === 47, 'submitted division wrong');
foreach ($pTree['eng']['3rd Year']['CS'] as $d) {
    assert($d['reported'] === false && $d['present'] === 0, 'unreported division should be flagged');
}

// --- Multi-day sheets: one reading per class, never one per day -------------
// The Apps Script pushes the entire sheet on every trigger, so from day two it
// carries every earlier day's rows. Keying by date would file one division
// under several dates and count its strength once per day.
$multiDay = "date,school,year,branch,division,strength,present\n" .
            "law,,,,,,\n" . // malformed row, must be ignored
            "2026-08-26,law,2nd Year,,A,7,20\n" .
            "2026-08-27,law,2nd Year,,A,7,25\n" .
            "2026-08-27,law,2nd Year,,A,7,27\n";  // same-day resubmission wins
$multiTree = aggregate_attendance(parse_attendance_csv($multiDay));
$lawA = find_div($multiTree, 'law', '2nd Year', '', 'A');
assert($lawA['present'] === 27, 'latest reading should win, got ' . $lawA['present']);
$mt = attendance_totals(['law' => ['2nd Year' => $multiTree['law']['2nd Year']]]);
// Law 2nd Year is divisions A (30) and B (60); A reported three times across
// two days and must still contribute its 30 exactly once.
assert($mt['strength'] === 90, 'strength counted more than once: ' . $mt['strength']);
assert($mt['reported'] === 1, 'only division A was submitted, got ' . $mt['reported']);

// Order must not matter: an older row arriving after a newer one loses.
$outOfOrder = "date,school,year,branch,division,strength,present\n" .
              "2026-08-27,law,2nd Year,,A,7,27\n" .
              "2026-08-26,law,2nd Year,,A,7,20\n";
$ooTree = aggregate_attendance(parse_attendance_csv($outOfOrder));
assert(find_div($ooTree, 'law', '2nd Year', '', 'A')['present'] === 27,
    'an older row overwrote a newer one');

// --- Flat "Class" rows (what the Google Form actually submits) --------------
$formCsv = "timestamp,class,present\n" .
           "26/08/2026 09:14:02,School of Engineering / 2nd Year / CSE / A,52\n" .
           "26/08/2026 09:15:41,School of Law / 1st Year / B,44\n" .           // branchless: 3 segments
           "26/08/2026 09:16:03,School of Nowhere / 1st Year / A,10\n" .       // unknown school -> dropped
           "26/08/2026 09:17:20,School of Engineering / 9th Year / CSE / A,5\n"; // unknown year -> dropped

$formRows = parse_attendance_csv($formCsv);
assert(count($formRows) === 2, 'expected 2 valid form rows, got ' . count($formRows));
assert($formRows[0]['school'] === 'eng', 'display name should resolve to school id');
assert($formRows[0]['branch'] === 'CSE', 'branch segment lost');
assert($formRows[0]['strength'] === 70, 'form row strength should come from structure');
assert($formRows[1]['school'] === 'law' && $formRows[1]['branch'] === '', 'branchless label mis-parsed');
assert($formRows[1]['strength'] === 60, 'law 1st Year div B strength wrong: ' . $formRows[1]['strength']);
assert($formRows[0]['date'] !== '', 'missing date column should default to today');

// --- Sectioned form rows: one class column per section, one filled ----------
// Headers are deliberately duplicated ("class (...)" twice over) because Google
// repeats a question title across sections; array_combine collapses those, so
// the value must be resolved off the raw header/field arrays instead.
$sectioned =
    "timestamp,school,class (school of engineering — 2nd year),class (school of law),class (school of law),present today\n" .
    '"26/08/2026 09:14:02","School of Engineering","School of Engineering / 2nd Year / CSE / B","","","52"' . "\n" .
    '"26/08/2026 09:15:10","School of Law","","School of Law / 2nd Year / A","","44"' . "\n" .
    '"26/08/2026 09:16:44","School of Law","","","School of Law / 3rd Year / B","39"' . "\n";

$secRows = parse_attendance_csv($sectioned);
assert(count($secRows) === 3, 'expected 3 sectioned rows, got ' . count($secRows));
assert($secRows[0]['school'] === 'eng' && $secRows[0]['division'] === 'B', 'eng section row mis-parsed');
assert($secRows[1]['school'] === 'law' && $secRows[1]['year'] === '2nd Year', 'law section row mis-parsed');
assert($secRows[0]['present'] === 52 && $secRows[1]['present'] === 44, 'present lost across sectioned columns');
// The last row's value sits in the SECOND duplicate column — the one
// array_combine keeps. The first duplicate is blank and must not win.
assert($secRows[2]['year'] === '3rd Year' && $secRows[2]['division'] === 'B', 'duplicate class header collapsed');

// A row with every class column blank names no class and must be dropped, not
// silently attributed to whatever the school column says.
$blank = "timestamp,school,class (school of law),present\n" . '"26/08/2026 09:17:00","School of Law","","31"' . "\n";
assert(count(parse_attendance_csv($blank)) === 0, 'row with no class selected should be dropped');

// A Present question placed inside each section instead of a shared one repeats
// that column too. array_combine keeps the blank last copy, which parsed as
// present=0 — a silent total-absence reading, not a visible error.
$dupPresent =
    "timestamp,school,class (school of law),present,class (school of design),present\n" .
    '"26/08/2026 09:15:10","School of Law","School of Law / 2nd Year / A","44","",""' . "\n";
$dup = parse_attendance_csv($dupPresent);
assert(count($dup) === 1, 'expected 1 row from duplicated-present sheet');
assert($dup[0]['present'] === 44, 'duplicated present column zeroed the count: ' . $dup[0]['present']);
assert($dup[0]['strength'] === 30, 'strength wrong: ' . $dup[0]['strength']);

// The Present question may be renamed for clarity as long as it still starts
// with "Present" — that prefix is the contract between the form and the parser.
$renamed = "timestamp,class (school of law),present today\n" .
           '"26/08/2026 09:15:10","School of Law / 2nd Year / A","44"' . "\n";
$ren = parse_attendance_csv($renamed);
assert(count($ren) === 1 && $ren[0]['present'] === 44, 'a "Present ..." suffix should still match');

// A sheet with no Present column at all is rejected outright. Parsing it as
// zeros would read as a university-wide absence instead of a broken form.
$noPresent = "timestamp,class (school of law),students present\n" .
             '"26/08/2026 09:15:10","School of Law / 2nd Year / A","44"' . "\n";
assert(parse_attendance_csv($noPresent) === [], 'sheet without a Present column must be rejected');

// A row naming a class the structure doesn't have is still dropped, but it must
// now come back in $skipped — silently vanishing is how a stale Form option
// stops being counted for weeks without anyone noticing. A blank row is an
// empty response, not a mismatch, so it must NOT be reported.
$mixed = "timestamp,class (school of engineering),present\n" .
    '"26/08/2026 09:15:10","School of Engineering / 2nd Year / CSE / A","45"' . "\n" .
    '"26/08/2026 09:16:10","School of Engineering / 2nd Year / CSE / ","41"' . "\n" .
    '"26/08/2026 09:17:10","","0"' . "\n";
$kept = parse_attendance_csv($mixed, $skipped);
assert(count($kept) === 1, 'expected 1 usable row, got ' . count($kept));
assert($skipped === ['School of Engineering / 2nd Year / CSE /'],
    'skipped rows not reported: ' . json_encode($skipped));

// A section whose Class question is titled so it doesn't start with "Class"
// still holds a perfectly good label. Real case, 28 Aug 2026: eng 2nd Year went
// uncounted for it. The label's shape must win over the column's name.
$oddHeader = "timestamp,school,year,which section are you reporting for?,present\n" .
    '"28/08/2026 10:20:15","School of Engineering","2nd Year","School of Engineering / 2nd Year / CSE / A","45"' . "\n";
$odd = parse_attendance_csv($oddHeader, $oddSkipped);
assert(count($odd) === 1, 'unnamed class column dropped the row: ' . json_encode($oddSkipped));
assert($odd[0]['branch'] === 'CSE' && $odd[0]['division'] === 'A' && $odd[0]['present'] === 45,
    'label found by shape mis-parsed: ' . json_encode($odd[0]));

// --- Every section's options are real, unique classes -----------------------
$sections = form_sections();
assert(count($sections) === 12, 'expected 12 form sections, got ' . count($sections));
$seen = [];
foreach ($sections as $title => $labels) {
    assert($labels !== [], "section '$title' has no options");
    foreach ($labels as $label) {
        assert(parse_class_label($label) !== null, "section option is not a real class: $label");
        assert(!isset($seen[$label]), "class appears in two sections: $label");
        $seen[$label] = true;
    }
}
// No class may be missing from the Form, or it can never be submitted.
assert(count($seen) === count(class_rows()),
    'sections cover ' . count($seen) . ' classes but ' . count(class_rows()) . ' exist');

// --- Label round-trips ------------------------------------------------------
foreach (class_rows() as $c) {
    $label = class_label($c['school'], $c['year'], $c['branch'], $c['division']);
    $back = parse_class_label($label);
    assert($back !== null, "label did not round-trip: $label");
    assert($back['school'] === $c['school'] && $back['year'] === $c['year']
        && $back['branch'] === $c['branch'] && $back['division'] === $c['division'],
        "label round-tripped to the wrong class: $label");
}

// A branch name with a slash in it must not be read as the label's separator:
// CLASS_SEP has spaces around its slash, "B Des UI/UX" does not.
assert(parse_class_label('School of Design / 1st Year / B Des UI/UX / B') !== null,
    'a branch containing a slash broke its label');

// Every class the Form can offer must be unique, or two divisions collide on
// one dropdown entry and one of them can never be submitted.
$labels = array_map(fn($c) => class_label($c['school'], $c['year'], $c['branch'], $c['division']), class_rows());
assert(count($labels) === count(array_unique($labels)), 'duplicate class labels in the dropdown');


// --- The date a class met, which is not the date the form was filled in -----
// The Form's own Date question wins. The submission timestamp is the fallback,
// so rows submitted before that question existed keep the day they were sent
// instead of collapsing onto the day of the push that re-read them.
$dated = "timestamp,date of class,class,present\n" .
    '"2026-08-27","2026-08-25","School of Law / 2nd Year / A","21"' . "\n" .   // date question wins
    '"26/08/2026 09:14:02","","School of Law / 2nd Year / B","22"' . "\n" .    // d/m/Y timestamp fallback
    '"2026-08-24","2099-01-01","School of Law / 3rd Year / A","23"' . "\n";    // future typo ignored
$datedRows = parse_attendance_csv($dated);
assert($datedRows[0]['date'] === '2026-08-25', 'date question should beat the timestamp: ' . $datedRows[0]['date']);
assert($datedRows[1]['date'] === '2026-08-26', 'd/m/Y timestamp mis-parsed: ' . $datedRows[1]['date']);
assert($datedRows[2]['date'] === '2026-08-24', 'a future date must not be taken: ' . $datedRows[2]['date']);
assert(row_date('not a date') === null, 'junk must not parse as a date');

// A Date question sitting inside each section repeats the column, exactly like
// Class and Present. The blank copies must not win.
$dupDate = "timestamp,date (law),date (design),class,present\n" .
    '"2026-08-27","","2026-08-23","School of Design / 1st Year / PGDM / A","19"' . "\n";
assert(parse_attendance_csv($dupDate)[0]['date'] === '2026-08-23', 'duplicate date column collapsed');

// --- Ranges: an average over the days reported, never a sum -----------------
$week = "date,class,present\n" .
    "2026-08-24,School of Law / 2nd Year / A,20\n" .
    "2026-08-25,School of Law / 2nd Year / A,30\n" .
    "2026-08-26,School of Law / 2nd Year / A,25\n" .
    "2026-08-26,School of Law / 2nd Year / A,28\n" . // same-day correction wins
    "2026-08-26,School of Law / 2nd Year / B,40\n";
$weekRows = parse_attendance_csv($week);
$days = day_map($weekRows);
assert(count($days) === 3, 'expected 3 distinct days, got ' . count($days));
assert($days['2026-08-26']['law|2nd Year||A'] === ['' => 28],
    'a sheet with no clock keys one reading a day, the last winning');

$rangeTree = aggregate_days($days, '2026-08-24', '2026-08-26');
$lawA = find_div($rangeTree, 'law', '2nd Year', '', 'A');
assert($lawA['present'] === 26, 'average of 20/30/28 should be 26, got ' . $lawA['present']);
assert($lawA['days'] === 3, 'reported-day count wrong: ' . $lawA['days']);
$lawB = find_div($rangeTree, 'law', '2nd Year', '', 'B');
assert($lawB['present'] === 40 && $lawB['days'] === 1, 'a class reporting one day of three must say so');

// A range is comparable to a single day: strength is counted once, not once
// per day, or three days of one division would read as 90 students.
$rt = attendance_totals(['law' => ['2nd Year' => $rangeTree['law']['2nd Year']]]);
assert($rt['strength'] === 90, 'strength counted per day: ' . $rt['strength']);
assert($rt['strength_reported'] === 90 && $rt['reported'] === 2, 'both divisions reported in range');

// Narrowing the range narrows the data, and a day outside it is not counted.
$oneDay = aggregate_days($days, '2026-08-24', '2026-08-24');
assert(find_div($oneDay, 'law', '2nd Year', '', 'A')['present'] === 20, 'range end not honoured');
assert(find_div($oneDay, 'law', '2nd Year', '', 'B')['reported'] === false, 'a day outside the range leaked in');

// No range means the newest day that has data — a real, labelled day rather
// than "today", which is empty every morning until the first form arrives.
assert(resolve_range($days) === ['2026-08-26', '2026-08-26'], 'default range should be the latest day');
assert(resolve_range($days, '2026-08-26', '2026-08-24') === ['2026-08-24', '2026-08-26'],
    'a backwards range should be swapped, not returned empty');
assert(find_div(aggregate_days($days), 'law', '2nd Year', '', 'A')['present'] === 28,
    'the default view should be the latest day, not an average of everything');

// The readings behind an average, so the modal can name the days it rests on.
$aReadings = class_readings($days, [], 'law|2nd Year||A', '2026-08-24', '2026-08-26');
assert(array_column($aReadings, 'date') === ['2026-08-24', '2026-08-25', '2026-08-26'], 'reported dates wrong');
assert(array_column($aReadings, 'present') === [20, 30, 28], 'reading counts wrong: ' . json_encode($aReadings));
assert(class_readings($days, [], 'law|2nd Year||B', '2026-08-24', '2026-08-25') === [],
    'readings outside the range leaked in');

// --- Faculty names: attribution only, never a count -------------------------
// The Form grew a name question in Aug 2026, so the sheet carries rows with a
// name and rows without, and both must count exactly the same.
$named = "timestamp,faculty name,class,present,date\n" .
         '"2026-08-27","Dr. Meera Rao","School of Law / 2nd Year / A","20","2026-08-24"' . "\n" .
         '"2026-08-27","","School of Law / 2nd Year / B","40","2026-08-24"' . "\n" .        // no name given
         '"2026-08-27","Prof. S. Iyer","School of Law / 2nd Year / A","30","2026-08-25"' . "\n" . // cover for the same class
         '"2026-08-27","Dr. Meera Rao","School of Law / 2nd Year / A","28","2026-08-26"' . "\n";

$nRows = parse_attendance_csv($named);
assert(count($nRows) === 4, 'a name column must not drop rows: ' . count($nRows));
assert($nRows[0]['faculty'] === 'Dr. Meera Rao', 'faculty name not captured: ' . $nRows[0]['faculty']);
assert($nRows[1]['faculty'] === '', 'a blank name must stay blank, not inherit the row above');

// The name rides beside the day map, keyed identically, and never reaches the
// numbers: same rows, same counts, with or without names.
$nDays = day_map($nRows);
$nNames = faculty_map($nRows);
assert(array_keys($nNames) === ['2026-08-24', '2026-08-25', '2026-08-26'], 'faculty map should be keyed by day');
assert($nNames['2026-08-24']['law|2nd Year||A'] === ['' => 'Dr. Meera Rao'], 'faculty name lost its class key');
assert(!isset($nNames['2026-08-24']['law|2nd Year||B']), 'a nameless row must not be stored empty');
assert(find_div(aggregate_days($nDays, '2026-08-24', '2026-08-26'), 'law', '2nd Year', '', 'A')['present'] === 26,
    'names must not disturb the average of 20/30/28');

// What api/division.php hands the modal: the distinct names over the days the
// class actually reported, in date order, so a substitution is visible.
$aEntries = class_readings($nDays, $nNames, 'law|2nd Year||A', '2026-08-24', '2026-08-26');
$aNames = array_values(array_unique(array_filter(array_column($aEntries, 'faculty'))));
assert($aNames === ['Dr. Meera Rao', 'Prof. S. Iyer'], 'expected both faculty once: ' . implode(', ', $aNames));

// --- Lecture times: one reading per lecture, not one per day ----------------
// Each faculty member takes attendance after their own lecture, so a class
// reports several times a day. Keyed by day alone, the 2pm submission simply
// replaced the 9am one and the day showed a single lecture's number.
$lectures = "timestamp,time of class,class,present,date\n" .
    '"2026-08-26 09:12","09:00","School of Law / 2nd Year / A","20","2026-08-26"' . "\n" .
    '"2026-08-26 11:40","11:00","School of Law / 2nd Year / A","30","2026-08-26"' . "\n" .
    '"2026-08-26 14:05","14:00","School of Law / 2nd Year / A","28","2026-08-26"' . "\n" .
    '"2026-08-26 14:06","14:00","School of Law / 2nd Year / A","26","2026-08-26"' . "\n";
$lecRows = parse_attendance_csv($lectures);
assert($lecRows[0]['time'] === '09:00', 'the Time question should beat the timestamp: ' . $lecRows[0]['time']);
$lecDays = day_map($lecRows);
// Three lectures kept, and the fourth row corrects the 2pm one rather than
// becoming a fourth lecture.
assert($lecDays['2026-08-26']['law|2nd Year||A'] === ['09:00' => 20, '11:00' => 30, '14:00' => 26],
    'lecture readings lost: ' . json_encode($lecDays['2026-08-26']['law|2nd Year||A']));

// The day's headline number is its LATEST lecture, not the mean of the three:
// what the tile has to say is the state of the class as of the most recent
// lecture, before anyone opens the breakdown. Mean would give 25 here.
$lecA = $lecDays['2026-08-26']['law|2nd Year||A'];
assert(day_present($lecA) === 26, 'a day should show its latest lecture: ' . day_present($lecA));
assert(find_div(aggregate_days($lecDays), 'law', '2nd Year', '', 'A')['present'] === 26,
    'the tree lost the latest lecture');
$lecTotals = attendance_totals(['law' => ['2nd Year' => aggregate_days($lecDays)['law']['2nd Year']]]);
assert($lecTotals['strength_reported'] === 30, 'strength counted once per lecture: ' . $lecTotals['strength_reported']);

// Every entry, which is what the modal lists under the division and the report
// prints under the class. The chips on screen are derived from exactly this.
$lecEntries = class_readings($lecDays, faculty_map($lecRows), 'law|2nd Year||A', '2026-08-26', '2026-08-26');
assert(array_column($lecEntries, 'time') === ['09:00', '11:00', '14:00'],
    'lecture times wrong: ' . json_encode($lecEntries));
assert(array_column($lecEntries, 'present') === [20, 30, 26], 'the earlier lectures were lost, not just hidden');
assert(class_readings($lecDays, [], 'law|2nd Year||B', '2026-08-26', '2026-08-26') === [],
    'readings invented for an unreported class');

// No Time question: the submission timestamp stands in, rounded down to the
// hour, so a correction filed a minute later overwrites its original while the
// next lecture gets a slot of its own.
$stamped = "timestamp,class,present,date\n" .
    '"2026-08-26 09:12","School of Law / 2nd Year / A","20","2026-08-26"' . "\n" .
    '"2026-08-26 09:13","School of Law / 2nd Year / A","22","2026-08-26"' . "\n" .
    '"2026-08-26 11:40","School of Law / 2nd Year / A","30","2026-08-26"' . "\n";
$stampDays = day_map(parse_attendance_csv($stamped));
assert($stampDays['2026-08-26']['law|2nd Year||A'] === ['09:00' => 22, '11:00' => 30],
    'timestamp fallback wrong: ' . json_encode($stampDays['2026-08-26']['law|2nd Year||A']));

// Two faculty covering the same class on the same day both keep their reading,
// for the same reason a substitution across days is worth seeing.
$twoNames = faculty_map(parse_attendance_csv(
    "timestamp,faculty name,time,class,present,date\n" .
    '"2026-08-26 09:12","Dr. Meera Rao","09:00","School of Law / 2nd Year / A","20","2026-08-26"' . "\n" .
    '"2026-08-26 14:05","Prof. S. Iyer","14:00","School of Law / 2nd Year / A","28","2026-08-26"' . "\n"
));
assert($twoNames['2026-08-26']['law|2nd Year||A'] === ['09:00' => 'Dr. Meera Rao', '14:00' => 'Prof. S. Iyer'],
    'one lecture overwrote the other faculty: ' . json_encode($twoNames['2026-08-26']['law|2nd Year||A']));

// A cache written before lecture times holds a bare int for the whole day. The
// next push replaces it; until then it must still read, not fatal.
assert(day_present(41) === 41, 'a pre-times cache entry must still read');
assert(find_div(aggregate_days(['2026-08-26' => ['law|2nd Year||A' => 41]]), 'law', '2nd Year', '', 'A')['present'] === 41,
    'a pre-times cache must still aggregate');
// A bare int and a bare name both cast to a one-element array keyed on 0, which
// is what still pairs the reading with whoever filed it.
assert(class_readings(
    ['2026-08-26' => ['law|2nd Year||A' => 41]],
    ['2026-08-26' => ['law|2nd Year||A' => 'Dr. Meera Rao']],
    'law|2nd Year||A', '2026-08-26', '2026-08-26'
) === [['date' => '2026-08-26', 'time' => '', 'present' => 41, 'faculty' => 'Dr. Meera Rao']],
    'a pre-times cache must read back as one nameless-slot reading');

assert(row_time('26/08/2026 14:05:02') === '14:05', 'd/m/Y timestamp time wrong: ' . row_time('26/08/2026 14:05:02'));
assert(row_time('1899-12-30 09:30:00') === '09:30', 'a Form time cell wrong: ' . row_time('1899-12-30 09:30:00'));
assert(row_time('2:15 PM') === '14:15', '12-hour time wrong: ' . row_time('2:15 PM'));
assert(row_time('12:05 AM') === '00:05', 'midnight wrong: ' . row_time('12:05 AM'));
assert(row_time('2026-08-26') === '' && row_time('99:99') === '', 'a bare date or junk must have no time');

// --- Percentages divide by the classes that reported ------------------------
// Diluting by classes that never filled the form in reads as an empty
// university at 9am rather than as a missing form. attendance_pct pairs with
// reported/classes, which is what keeps it honest.
$partialDay = aggregate_days(day_map(parse_attendance_csv(
    "date,class,present\n2026-08-26,School of Law / 2nd Year / A,15\n"
)));
$pd = attendance_totals($partialDay);
assert($pd['strength'] === 4874, 'full strength should still be reported: ' . $pd['strength']);
assert($pd['strength_reported'] === 30, 'reported strength wrong: ' . $pd['strength_reported']);
assert(attendance_pct($pd) === 50, 'percentage should be over reported classes only: ' . attendance_pct($pd));
assert(attendance_pct(attendance_totals(aggregate_days([]))) === 0, 'no data must not divide by zero');

// --- Range labels -----------------------------------------------------------
assert(range_label('2026-08-26', '2026-08-26') === '26 Aug 2026', range_label('2026-08-26', '2026-08-26'));
assert(range_label('2026-08-24', '2026-08-26') === '24 Aug to 26 Aug 2026', range_label('2026-08-24', '2026-08-26'));
assert(range_label('2025-12-30', '2026-01-02') === '30 Dec 2025 to 02 Jan 2026', range_label('2025-12-30', '2026-01-02'));

echo "OK\n";
