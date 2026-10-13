<?php
// Turns dump-grid.py's output for the Mechanical (AE) and Robotics workbook
// into the format build.php reads.
//
//   python dump-grid.py "<workbook>.xlsx" mech.grid.json
//   C:\xampp\php\php.exe convert-mechanical.php mech.grid.json > mechanical-2026-09-30.json
//
// Only the six class tabs. The room tabs (503, 106, 103, ULC 6 006) are still
// last year's (2025-26), so they are not used.
//
// A cell is one or more "SUBJECT(INITIALS) [B1|B1 & B2] [LAB] ULC<n> <room>"
// parts: "ET(SAS) | ULC1 503", "PP B1 LAB ULC5 106 | ET B2 LAB ULC5 103" (two
// batches side by side). Each room ends a booking. Initials and course names
// come from the tab's own course list ("Strength of Materials(SOM)",
// "Dr. Name Surname [SA]"); a cell without initials takes the course's
// teacher. A part naming one batch is a lab for that batch; "B1 & B2" is the
// whole class. Cells with no room (Sports, Library, Hands on with Arduino,
// BAJA SUPRA STATICS, PROJECT WORK, ROS) are left out and printed to stderr.

$tabs = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($tabs)) { fwrite(STDERR, "Could not read {$argv[1]}\n"); exit(1); }

// tab => class. "MEAE" is the Mechanical (AE) programme, "RO" Robotics.
const CLASSES = [
    'MEAE III' => 'B.Tech ME-AE Sem III', 'MEAE V' => 'B.Tech ME-AE Sem V', 'ME AE VII' => 'B.Tech ME-AE Sem VII',
    'RO III' => 'B.Tech Robotics Sem III', 'RO V' => 'B.Tech Robotics Sem V', 'RO VII' => 'B.Tech Robotics Sem VII',
];
// Cell codes the course lists spell differently, or not at all.
const ALIAS = ['OPI' => 'OP', 'CADCAM' => 'CAD'];
const NAMED = ['PLC' => 'Industrial Automation: PLC & SCADA', 'SOM' => 'Strength of Materials'];

// Every course and teacher across the tabs: a code's name is the same in each.
$subjects = NAMED;
$faculty = [];
$teaches = [];   // tab => code => initials
foreach ($tabs as $t) {
    foreach ($t['courses'] as $k) {
        $who = '';
        if (preg_match('/^(.*?)\s*[\[(]\s*([A-Z][A-Z0-9]*)\s*[\])]\s*$/', trim($k['faculty']), $fm)) {
            $faculty[$fm[2]] = trim($fm[1]);
            $who = $fm[2];
        }
        if (preg_match('/^(.*?)\s*\(\s*([A-Za-z]+)\s*\)\s*$/', trim($k['course']), $cm)) {
            $code = strtoupper($cm[2]);
            // RO III lists Strength of Materials as "(SA)", its teacher's initials.
            if (!isset($subjects[$code]) && $code !== $who) $subjects[$code] = trim($cm[1]);
            $teaches[$t['tab']][$code] = $who;
        }
    }
}

$out = ['source' => 'Mechanical AE Robitics TT AY 25-26 Odd Sem (2).xlsx', 'building' => 'ULC 1',
    'note' => 'Converted by dump-grid.py and convert-mechanical.php from the six class tabs (version 4, RO III version 1; W.E.F 30/09/2026). The room tabs are 2025-26 and not used. Periods with no room are left out.',
    'faculty' => $faculty + ['' => ''], 'subjects' => [], 'classes' => []];
$skipped = [];

foreach ($tabs as $t) {
    if (!isset(CLASSES[$t['tab']])) continue;
    $rowOf = [];
    foreach ($t['periods'] as $i => $p) foreach ($p['rows'] as $r) $rowOf[$r] = $i;
    $entries = [];
    foreach ($t['cells'] as $c) {
        $text = trim(preg_replace('/\s+/', ' ', str_replace('|', ' ', $c['text'])));
        if (!preg_match_all('/(.*?)\bULC\s*(\d)\s*(MINI\s*AUDI\w*|\d{3})/i', $text, $parts, PREG_SET_ORDER)) {
            if (!preg_match('/^LUNCH/i', $text)) $skipped[] = "{$t['tab']} {$c['day']} {$t['periods'][$rowOf[$c['row']]]['label']}: $text";
            continue;
        }
        $first = $rowOf[$c['row']];
        $hours = $rowOf[$c['last_row']] - $first + 1;
        $start = explode(' ', $t['periods'][$first]['label'])[0];
        foreach ($parts as [, $left, $ulc, $room]) {
            $left = trim($left);
            if (!preg_match('/^([A-Z&]+)/', $left, $km)) { $skipped[] = "{$t['tab']} {$c['day']}: no subject in \"$left\""; continue; }
            $code = ALIAS[$km[1]] ?? $km[1];
            $who = preg_match('/\(\s*([A-Z][A-Z0-9]*)\s*\)/', $left, $wm) ? $wm[1] : ($teaches[$t['tab']][$code] ?? '');
            $both = (bool) preg_match('/\bB1\s*&\s*B2\b/', $left);
            $batch = !$both && preg_match('/\b(B\d)\b/', $left, $bm) ? $bm[1] : '';
            $lab = $batch !== '' || $both || (bool) preg_match('/\bLAB\b/i', $left);
            $room = preg_match('/^MINI/i', $room) ? 'Mini Auditorium' : $room;
            $out['subjects'][$code] = $subjects[$code] ?? $code;
            $entries[] = [$c['day'], $start, $hours, $code, "ULC $ulc $room", $who, $lab, $batch !== '' ? "Batch $batch" : ''];
        }
    }
    $out['classes'][] = ['class' => CLASSES[$t['tab']], 'page' => $t['tab'], 'entries' => $entries];
}

foreach ($skipped as $s) fwrite(STDERR, "left out: $s\n");
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
