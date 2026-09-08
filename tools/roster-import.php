<?php
// Turns one school's returned student list into data/roster/<school>.php.
//
//   php tools/roster-import.php eng Engineering.csv
//   php tools/roster-import.php eng Engineering.csv --dry-run
//
// CSV, not xlsx. tools/xlsx.php writes workbooks and does not read them, and a
// reader is eighty lines for something LibreOffice does in one menu click:
//   libreoffice --headless --convert-to csv --outdir . Engineering.xlsx
// Write the reader when a third school has sent a third workbook, not before.
//
// What this prints matters as much as what it writes. Every row that names no
// real class is listed, because a student silently landing in no class is
// exactly the failure mode this project keeps hitting, and every division whose
// roster size disagrees with structure.php is listed too — those disagreements
// are the real enrolment figures finally arriving, and structure.php should be
// corrected to match before anyone trusts a percentage again.

require_once __DIR__ . '/../includes/structure.php';
require_once __DIR__ . '/../includes/store.php';

$school = $argv[1] ?? '';
$file   = $argv[2] ?? '';
$dryRun = in_array('--dry-run', $argv, true);

if (!isset(SCHOOLS[$school]) || $file === '' || !is_file($file)) {
    fwrite(STDERR, "usage: php tools/roster-import.php <school> <file.csv> [--dry-run]\n");
    fwrite(STDERR, "schools: " . implode(' ', array_keys(SCHOOLS)) . "\n");
    exit(1);
}

$fh = fopen($file, 'r');
$roster = [];
$skipped = [];
$dupes = [];
$started = false;
$line = 0;

while (($row = fgetcsv($fh)) !== false) {
    $line++;
    $cells = array_map(fn($c) => trim((string) $c), array_pad($row, 5, ''));

    // The workbook puts a title, a blank and the header above the data. Anything
    // before the header row is preamble, whatever it happens to say.
    if (!$started) {
        if (strcasecmp($cells[0], 'Year') === 0) $started = true;
        continue;
    }

    [$year, $branch, $division, $roll, $name] = $cells;
    // A pre-filled row nobody typed into. Not an error: the request deliberately
    // ships more rows than some divisions need.
    if ($roll === '' && $name === '') continue;

    $class = parse_class_label(class_label($school, $year, $branch, $division));
    if ($class === null) {
        $skipped[] = "line $line: " . trim("$year / $branch / $division", ' /') . ' (' . ($roll ?: $name) . ')';
        continue;
    }

    $key = class_key($class);
    // A duplicate roll number would let one student be ticked absent twice and
    // make the present count wrong in a way nobody could see on screen.
    if (isset($roster[$key]) && in_array($roll, array_column($roster[$key], 'roll'), true)) {
        $dupes[] = "line $line: $roll already in " . $class['division'];
        continue;
    }
    $roster[$key][] = ['roll' => $roll, 'name' => $name];
}
fclose($fh);

if (!$started) {
    fwrite(STDERR, "no header row found: expected a row whose first cell is \"Year\"\n");
    exit(1);
}

$students = array_sum(array_map('count', $roster));
echo SCHOOLS[$school]['name'] . ": " . count($roster) . " divisions, $students students\n";

foreach ($skipped as $s) echo "  IGNORED $s\n";
foreach ($dupes as $d) echo "  DUPLICATE $d\n";

// The point of the whole exercise. A roster is a headcount somebody actually
// counted; structure.php's number is whatever the last request managed to get.
$mismatched = 0;
foreach (class_rows() as $c) {
    if ($c['school'] !== $school) continue;
    $have = count($roster[class_key($c)] ?? []);
    if ($have === 0 || $have === $c['strength']) continue;
    $mismatched++;
    printf("  ENROLMENT %s: structure.php says %d, the list has %d\n",
        trim("{$c['year']} / {$c['branch']} / {$c['division']}", ' /'), $c['strength'], $have);
}
if ($mismatched) {
    echo "  -> $mismatched division(s) disagree. Correct includes/structure.php: the list is the count somebody made.\n";
}

if ($dryRun) {
    echo "dry run, nothing written\n";
    exit;
}

if (!$roster) {
    fwrite(STDERR, "nothing to write - no row named a class that exists\n");
    exit(1);
}

store_update('roster/' . $school, fn() => $roster);
echo "wrote " . store_path('roster/' . $school) . "\n";
