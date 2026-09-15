<?php
// Run: php tests/test_partner_divisions.php
//
// The partner divisions request lists every program-year a partner has
// students in. The failures that matter are quiet ones: a year left off, so
// nobody is ever asked for its division, or a row asking about a year that has
// no students.

require_once __DIR__ . '/../tools/partner-divisions.php';

// --- Which student years exist -------------------------------------------------
// Year 1 joined in 2026-27 (column 22), year 4 in 2023-24 (column 19).
$p = [19 => '0', 20 => '60', 21 => '-', 22 => '60', 23 => '120'];
assert(pd_cohort($p, 1), 'year 1 has a 2026-27 intake');
assert(!pd_cohort($p, 2), 'a "-" intake is no cohort');
assert(pd_cohort($p, 3), 'year 3 has a 2024-25 intake');
assert(!pd_cohort($p, 4), 'a 0 intake is no cohort');
assert(!pd_cohort($p, 5) && pd_cohort([19 => '30'], 5), 'year 5 has no intake column, so it follows year 4');
assert(!pd_cohort([22 => '', 23 => '60'], 1), 'a blank intake beside a filled total did not run');
assert(pd_cohort([22 => ''], 1), 'a blank intake with no total is unknown, so ask');

assert(pd_value('4.0') === '4' && pd_value('-') === '-', 'whole numbers lose .0, text stays');

// --- A partner tab ---------------------------------------------------------------
// Shaped the way read_xlsx returns the template: headers on row 4, data from 5.
$g = [
    3 => [1 => 'Name of Faculty'],
    4 => [17 => 'B TECH X', 18 => '4', 19 => '0', 20 => '60', 21 => '60', 22 => '60', 23 => '180',
          27 => 'B TECH X', 28 => '2.0', 29 => '55'],
    5 => [17 => 'BCA Y', 18 => '3', 21 => '30', 22 => '30', 23 => '60',
          28 => '3', 29 => '40'],
    6 => [16 => 'Total Intake', 19 => '0', 20 => '60', 27 => 'B.Sc Z', 28 => '1', 29 => '9'],
    7 => [28 => '4', 29 => '0'],
    8 => [28 => '5'],
    9 => [26 => 'Total', 29 => '104'],
];
assert(pd_students($g) === [
    ['B TECH X', '2', '55'],
    ['B TECH X', '3', '40'],   // blank name carries the one above
    ['B.Sc Z', '1', '9'],      // kept though it names no listed program
    ['B TECH X', '1', ''],     // 2026-27 intake, no row written: added
    ['BCA Y', '1', ''],
    ['BCA Y', '2', ''],
], 'rows as written, missing cohorts added; no year 4 of B TECH X (0 intake), no year 3 of BCA Y '
 . '(blank beside a total), no 0-student row, no pre-typed year alone, Total Intake not a program');

// --- The sheet -------------------------------------------------------------------
$book = pd_workbook([
    'Dont Change' => [0 => [4 => 'Partner Name']],
    'Aero' => $g,
    'Sunstone' => [3 => [1 => 'Name of Faculty']],
]);
$owe = ['y', ''];
$data = array_slice($book['Divisions']['rows'], 9);
assert(array_keys($book) === ['Divisions'], 'one sheet');
assert(count($data) === 6 + 3, 'the contacts tab is not a partner; a partner with no students gets 3 blank rows');
assert($data[0][4] === 'School of Engineering', 'a partner with one dashboard school has it filled in');
assert($data[0][6] === $owe && $data[0][7] === $owe && $data[0][5] === '', 'division and enrolled owed, branch optional');
assert($data[6][0] === 'Sunstone' && $data[6][1] === $owe && $data[6][4] === $owe,
    'a blank row owes the program, and a two-school partner owes the school');

echo "OK\n";
