<?php
// Turns the AIDS tabs of the department workbook into the format
// build.php reads. The workbook is first imported by the timetable
// generator's own import_excel_timetables.ps1 (outputs redirected here, so the
// generator's data/ is untouched), and this reads that JSON through the
// generator's tt_load(), so subjects, teachers and lab lengths are resolved by
// the generator's parser.
//
//   powershell ...\import_excel_timetables.ps1 -WorkbookPath "<workbook>.xlsx" -OutputPath aids-imported.json -FacultyCourseOutputPath aids-faculty-courses.md
//   C:\xampp\php\php.exe convert-aids.php aids-imported.json > aids-2026-08-03.json
//
// The room and its ULC come from the cell text ("DS(APG) ULC_1 DL Lab",
// "ULC_5C 16"): ULC 1 and ULC 5 rooms are both written out, and the build
// decides which ULCs the page shows.

// The timetable generator repo, beside this one; read only, never changed.
require_once dirname(__DIR__, 3) . '/automatic-timetable-generator/includes/timetable_data.php';

$schedules = tt_load($argv[1]);
$year = ['SY' => 'III', 'TY' => 'V', 'BE' => 'VII'];
$out = ['source' => '2-3-4 years_SOE_Odd term_AY_26-27_CSE_AIDS AND CS (1) 2.xlsx', 'building' => 'ULC 1',
    'note' => 'Imported with the timetable generator\'s import_excel_timetables.ps1 and read through its tt_load(); converted by convert-aids.php. Only the AIDS class tabs (the MASTER CLASSROOM tabs are room views of the same data). Rooms from the cell text: ULC 1 rooms, Mini Auditorium (taken as ULC 1), and ULC 5 rooms by letter and number ("ULC_5C 16" is C-16).',
    'faculty' => ['' => ''], 'subjects' => [], 'classes' => []];

foreach ($schedules as $s) {
    if (!preg_match('/^(SY|TY|BE)\s*-?\s*AIDS\s*-?\s*([A-D])$/i', trim($s['class_name']), $m)) {
        fwrite(STDERR, "  {$s['class_name']}: not a class tab, skipped\n");
        continue;
    }
    $class = 'B.Tech AIDS Sem ' . $year[strtoupper($m[1])] . ' Div ' . strtoupper($m[2]);
    $entries = [];
    foreach ($s['sessions'] as $x) {
        if (!empty($x['is_break'])) continue;
        $entry = trim(preg_replace('/\s+/', ' ', (string) $x['entry']));
        $room = '';
        if (preg_match('/ULC\s*[_-]?\s*(\d)\s*([A-C])?\s*[_-]?\s*(\d{2,3}(?:\/\d{3})?|DL\s*Lab)\b(\s*LAB)?/i', $entry, $r)) {
            $n = $r[3];
            if (preg_match('/^DL\s*Lab$/i', $n)) $n = 'DL Lab';
            if (!empty($r[4])) $n .= ' Lab';
            // "ULC_5C 16" -> room C-16 in ULC 5: the letter is part of how the
            // room is known, so it is kept. A cell without it ("ULC_5 15") is
            // matched to the lettered room by the build.
            $room = "ULC {$r[1]} " . ($r[2] !== '' ? strtoupper($r[2]) . '-' : '') . $n;
        } elseif (preg_match('/mini\s*audi/i', $entry)) {
            $room = 'ULC 1 Mini Auditorium';
        }
        if ($room === '') { fwrite(STDERR, "  $class {$x['day']} {$x['time']}: \"$entry\" has no room, left out\n"); continue; }
        // This sheet writes "CV (MD) ULC_1 DL Lab", not the "CV-MD-402" the
        // generator's parser expects: split off the code and initials, and let
        // tt_resolve() match them against this tab's course list as usual.
        $courses = $courses_of[$s['class_name']] ??= tt_prepare_courses($s['course_faculty'] ?? []);
        $lab = !empty($x['is_lab']);
        if (preg_match('/^(.+?)\s*\(\s*([A-Z]{2,4})\s*\)/', $entry, $cm)) {
            $y = tt_resolve(trim($cm[1]) . '-' . $cm[2], $courses);
            $subject = $y['subject'];
            $fac = $y['faculty'];
            $lab = $lab || $y['is_lab'];
        } else {
            // "Expert Session (Mini Audi)" -> "Expert Session"
            $subject = trim(preg_replace('/\s*\((mini\s*audi[^)]*)\)\s*$|\s*ULC.*$/i', '', $entry));
            $fac = '';
        }
        $x['is_lab'] = $lab;
        // "Computer Vision(CV)" -> "Computer Vision", "Ms. First Surname(FS)" -> "Ms. First Surname"
        $subject = trim(preg_replace('/\s*\([A-Z&]{2,6}\)\s*$/', '', $subject));
        $fac = tt_canonical_faculty(trim(preg_replace('/\s*\([A-Z]{2,4}\)\s*$/', '', $fac)));
        if (stripos($fac, 'industry') !== false) $fac = 'Industry faculty';
        // DS is Advanced Data Structure in every AIDS course list; the parser
        // cannot get there from two letters.
        $fixed = ['DS' => 'Advanced Data Structure', 'APPTITUDE' => 'Aptitude', 'MINI PROJECT' => 'Mini Project', 'IOT&ES' => 'IoT & ES'];
        $subject = $fixed[strtoupper($subject)] ?? $subject;
        $key = "AIDS|$subject" . (!empty($x['is_lab']) ? ' Lab' : '');
        $out['subjects'][$key] = $subject;
        $out['faculty'][$fac] = $fac;
        $start = explode(' to ', preg_replace('/^0/', '', trim($x['time'])))[0];
        $entries[] = [$x['day'], $start, max(1, (int) ($x['duration_slots'] ?? 1)), $key, $room, $fac, !empty($x['is_lab']), ''];
    }
    $out['classes'][] = ['class' => $class, 'page' => $s['class_name'], 'entries' => $entries];
    fwrite(STDERR, "$class: " . count($entries) . " bookings\n");
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
