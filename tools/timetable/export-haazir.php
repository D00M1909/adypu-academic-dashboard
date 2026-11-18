<?php
// Writes the room timetable as Haazir's three import files, for its admin
// Import page, in this order: classes.csv, subjects.csv, class_timetable.csv.
//
//   php tools/timetable/export-haazir.php [out-dir]
//
// out-dir defaults to tools/timetable/data/export/haazir/ (gitignored, like
// all of data/: the files carry teachers' emails). Also read there and kept:
//   subject-codes.json  every subject code given so far, so a code never
//                       changes once Haazir has it. Commit nothing from here.
// and, if present, tools/timetable/data/faculty-emails.json: {"name": "email"}
// for professor_email. Anyone without an email is listed in report.txt.

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/haazir.php';

$out = rtrim($argv[1] ?? __DIR__ . '/data/export/haazir', '/\\');
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}

$t = timetable_read();
if (!$t) {
    fwrite(STDERR, "No timetable: run tools/timetable/build.php first.\n");
    exit(1);
}
$rows = class_rows();

$skipped = [];
$classes = haazir_classes($rows, $skipped);
[$lectures, $unmatched] = haazir_lectures($t, $rows);

$codesFile = "$out/subject-codes.json";
$known = is_file($codesFile) ? (json_decode(file_get_contents($codesFile), true) ?: []) : [];
$subjects = haazir_subjects(array_column($lectures, 'subject'), $known);
$emailFile = __DIR__ . '/data/faculty-emails.json';
$emails = [];
foreach ((is_file($emailFile) ? json_decode(file_get_contents($emailFile), true) : []) ?: [] as $name => $email) {
    $emails[timetable_person($name)] = $email;
}
$noEmail = [];
$timetable = haazir_timetable($lectures, $subjects, $emails, $noEmail);

$write = function (string $file, array $header, array $list) use ($out) {
    $h = fopen("$out/$file", 'w');
    fputcsv($h, $header, ',', '"', '');
    foreach ($list as $r) fputcsv($h, array_map(fn($c) => $r[$c] ?? '', $header), ',', '"', '');
    fclose($h);
};
$write('classes.csv', ['school_code', 'school_name', 'branch_code', 'branch_name', 'year', 'division'], $classes);
$write('subjects.csv', ['code', 'name'], array_map(fn($s) => ['code' => $s[0], 'name' => $s[1]], array_values($subjects)));
$write('class_timetable.csv', ['subject_code', 'year', 'group', 'weekday', 'start', 'end', 'room', 'professor_email',
    'term'], $timetable);
$codes = array_map(fn($s) => $s[0], $subjects);
file_put_contents($codesFile, json_encode($known + $codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$report = [];
$report[] = sprintf('%d divisions, %d subjects, %d weekly slots (from %d lectures)', count($classes), count($subjects),
    count($timetable), count($lectures));
foreach ($skipped as $school => $branches) {
    $report[] = "Left out of classes: $school (" . implode(', ', array_map(fn($b) => $b === '' ? '(no branch)' : $b,
        array_keys($branches))) . '): no branch code in HAAZIR_BRANCHES';
}
if ($unmatched) {
    arsort($unmatched);
    $report[] = 'Bookings whose class is not in the structure (not exported):';
    foreach ($unmatched as $label => $n) $report[] = "  $label ($n)";
}
$calendars = count($t['calendars'] ?? []);
if ($calendars) $report[] = "Not exported: $calendars dated calendars (School of Design), which are not weekly";
ksort($noEmail);
$report[] = count($noEmail) . ' teachers without an email in data/faculty-emails.json (left out of professor_email):';
foreach ($noEmail as $spelling) $report[] = "  $spelling";
file_put_contents("$out/report.txt", implode("\n", $report) . "\n");

echo $report[0], "\n";
echo count($unmatched), ' class labels not exported, ', count($noEmail), " teachers without an email\n";
echo "Wrote $out/{classes,subjects,class_timetable}.csv and report.txt\n";
