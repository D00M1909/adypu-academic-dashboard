<?php
// Turns dump-grid.py's output for the CSE timetables into the format
// build.php reads, reading each cell with the timetable generator's own
// tt_resolve() ("ADS-AK-202" -> Advanced Data Structure, the teacher AK stands for, 202),
// the same parser the generator's By Room view uses.
//
//   C:\xampp\php\php.exe convert-cse.php sy-cse.grid.json "SY CSE-TimeTable.xlsx" "B.Tech CSE Sem III" > sy-cse-2026-09-28.json
//
// A cell whose room sits after a "|" ("OOP Lab-PDP- | Mini Auditorium") gets
// that room, which tt_resolve() does not read. ULC 5 periods are left out, and
// so is anything with no room (Sports, Library, Mini Project, MDMC, Seminar).

// The timetable generator repo, beside this one; read only, never changed.
require_once dirname(__DIR__, 3) . '/automatic-timetable-generator/includes/timetable_data.php';

[, $grid, $source, $prefix] = $argv;
$tabs = json_decode((string) file_get_contents($grid), true);
$out = ['source' => $source, 'building' => 'ULC 1',
    'note' => 'Converted by dump-grid.py and convert-cse.php, with the timetable generator\'s tt_resolve() reading each cell. ULC 5 periods and periods with no room are left out. Building not stated beyond "ULC 1" on the labs: taken as ULC 1.',
    'faculty' => ['' => ''], 'subjects' => [], 'classes' => []];
$skip = '/^(lunch|sports|library|mini project|spcr|workshop|seminar|mdmc|rpw)/i';

foreach ($tabs as $t) {
    // "CSE A", "TY SE A", "BTECH SE C" -> the division letter
    $div = preg_match('/([A-E])\s*$/', $t['tab'], $m) ? $m[1] : $t['tab'];
    $class = "$prefix Div $div";
    $courses = tt_prepare_courses($t['courses']);
    $rowOf = [];
    foreach ($t['periods'] as $i => $p) foreach ($p['rows'] as $r) $rowOf[$r] = $i;
    $entries = [];
    foreach ($t['cells'] as $c) {
        $text = trim($c['text']);
        if (preg_match($skip, $text)) continue;
        // ULC 5 cells write the room every way: "DAR-VS-ULC 5 C- | 12",
        // "SR-SI-ULC 5 | Room No-12", "SR Lab-LS-ULC | 5(C) Room No 12". The room
        // is the last number; the subject and teacher are what comes before ULC.
        if (preg_match('/ULC\s*\|?\s*5/i', $text)) {
            $left = trim(preg_replace('/\s*-?\s*ULC.*$/i', '', $text), " -");
            $x = tt_resolve($left, $courses);
            preg_match_all('/\d+/', preg_replace('/^.*?ULC\s*\|?\s*5/i', '', $text), $nums);
            // "ULC 5 C- 12", "ULC 5(C) Room No 12" -> C-12; the letter is kept
            $letter = preg_match('/ULC\s*\|?\s*5\s*\(?\s*([A-C])\b/i', $text, $lm) ? strtoupper($lm[1]) . '-' : '';
            $room = $nums[0] ? 'ULC 5 ' . $letter . end($nums[0]) : '';
        } else {
            [$left, $right] = array_pad(array_map('trim', explode('|', $text, 2)), 2, '');
            $x = tt_resolve($left, $courses);
            $room = $x['room'];
            if ($right !== '') $room = trim(preg_replace('/\bULC\s*1\b/i', '', $right));
        }
        if ($room === '' || strcasecmp($room, 'NF') === 0) { fwrite(STDERR, "  {$t['tab']} {$c['day']}: \"$text\" has no room, left out\n"); continue; }
        $subject = $x['subject'];
        $fac = $x['faculty'] === 'NF' ? '' : $x['faculty'];
        if (preg_match('/^(Mr\.|Ms\.|Dr\.)?\s*I[PE]$/', $fac)) $fac = 'Industry faculty';   // "IP": industry people
        // An acronym the course list does not spell out (DMGT for Discrete
        // Mathematics): the one lecture course its teacher has in this list.
        if ($subject === $x['subject_code'] && $fac !== '') {
            $mine = array_values(array_filter($t['courses'], fn($k) => trim($k['faculty']) !== '' && tt_canonical_faculty($k['faculty']) === $fac && !preg_match('/lab/i', $k['course'])));
            if (count($mine) === 1) $subject = trim($mine[0]['course']);
        }
        // A teacher the parser could not place because the list shares one
        // course between two people ("First Surname/ Other Name"): match the
        // cell's initials ("QA- AT-701") against each name on its own.
        if ($fac === '' && preg_match('/^[^-]+-\s*([A-Z]{2,4})\s*-/', $left, $im)) {
            foreach ($t['courses'] as $k) {
                foreach (array_map('trim', explode('/', $k['faculty'])) as $name) {
                    $bare = preg_replace('/^(Dr|Prof|Mr|Mrs|Ms)\.?\s*/i', '', $name);
                    $ini = implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), preg_split('/\s+/', $bare)));
                    if ($ini === $im[1]) { $fac = $name; if ($subject === $x['subject_code']) $subject = trim($k['course']); break 2; }
                }
            }
        }
        // "SOFTWARE RELIABILITY" -> "Software Reliability", "Cloud Computing(ICC)" -> "Cloud Computing".
        $subject = trim(preg_replace('/\s*\([A-Z]{2,6}\)\s*$/', '', $subject));
        if ($subject === strtoupper($subject) && strlen($subject) > 6) $subject = ucwords(strtolower($subject));

        $first = $rowOf[$c['row']] ?? null;
        if ($first === null) continue;
        $last = $rowOf[$c['last_row']] ?? $first;
        $label = $t['periods'][$first]['label'];
        $key = "$prefix|$subject" . ($x['is_lab'] ? ' Lab' : '');
        $out['subjects'][$key] = $subject;
        $out['faculty'][$fac] = $fac;
        $entries[] = [$c['day'], explode(' to ', $label)[0], $last - $first + 1, $key, $room, $fac, $x['is_lab'], ''];
    }
    $out['classes'][] = ['class' => $class, 'page' => $t['tab'], 'entries' => $entries];
    fwrite(STDERR, "$class: " . count($entries) . " bookings\n");
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
