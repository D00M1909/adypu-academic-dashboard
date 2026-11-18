<?php
// What the Exams tab's one Export menu hands out, for whichever view is open:
// a branded document (shown on the page and printed as the PDF), the same
// thing as an Excel sheet, and as plain text for email or WhatsApp. One
// builder per view, each returning
//   title, scope   the heading and the line under it
//   body           the document's HTML, between the shared head and footer
//   text           the plain-text version
//   sheet, file    rows for write_xlsx() (a cell may be [style, value]) and a file name
//   email, subject who a mail goes to ('' = the sender picks) and its subject

require_once __DIR__ . '/exams.php';

function exam_doc_e(string $s): string {
    return htmlspecialchars($s);
}

function exam_doc_day(string $d): string {
    return date('D j M Y', strtotime($d));
}

function exam_doc_span(array $x): string {
    return exam_clock($x['start']) . ' - ' . exam_clock($x['end']);
}

function exam_doc_pill(string $s): string {
    return '<span class="status exm-st-' . $s . '">' . exam_doc_e(EXAM_STATUS_LABEL[$s] ?? $s) . '</span>';
}

function exam_doc_school(string $id): string {
    return preg_replace('/^School of /', '', SCHOOLS[$id]['name'] ?? $id);
}

// One exam as a line of text: "Mon 16 Nov 2026, 10.00 am - 1.00 pm (report by 9.30 am): Subject, Class, Room 101".
function exam_doc_line(array $x): string {
    return exam_doc_day($x['date']) . ', ' . exam_doc_span($x) . ' (report by ' . exam_clock($x['report']) . '): '
        . $x['subject'] . ', ' . $x['class'] . ($x['room'] !== '' ? ', Room ' . $x['room'] : '');
}

// The coloured status cell of a sheet row.
function exam_doc_cell(string $s): array {
    return [['ontime' => 'g', 'late' => 'y', 'absent' => 'r'][$s] ?? '', $s !== '' ? EXAM_STATUS_LABEL[$s] : 'No invigilator'];
}

// The sheet every per-duty export shares: one row per duty.
function exam_doc_duty_sheet(array $rows): array {
    $out = [array_map(fn($h) => ['b', $h], ['Date', 'Time', 'Reporting', 'School', 'Class', 'Subject', 'Room', 'Faculty', 'Role', 'Status', 'Arrived', 'Remark'])];
    foreach ($rows as $r) {
        $x = $r['exam'];
        $d = $r['duty'] ?? [];
        $out[] = [exam_doc_day($x['date']), exam_doc_span($x), exam_clock($x['report']), SCHOOLS[$x['school']]['name'] ?? $x['school'],
            $x['class'], $x['subject'], $x['room'], $d['name'] ?? '', $d['role'] ?? '', exam_doc_cell($d['status'] ?? ''),
            ($d['arrived'] ?? '') !== '' ? exam_clock($d['arrived']) : '', $d['remark'] ?? ''];
    }
    return $out;
}

const EXAM_DOC_SIGN = "Exam Cell\nAjeenkya D Y Patil University";

// The whole document: the university's head, the body, the key or signature.
function exam_doc_html(array $doc): string {
    return '<article class="card exm-doc"><header class="exm-doc-head"><img src="img/logo.png" alt="" width="56" height="56"><div>'
        . '<p class="exm-doc-org">Ajeenkya D Y Patil University &middot; Exam Cell</p>'
        . '<h2>' . exam_doc_e($doc['title']) . '</h2>'
        . '<p class="exm-doc-scope">' . exam_doc_e($doc['scope']) . ' &middot; issued ' . date('j M Y') . '</p></div></header>'
        . $doc['body'] . '</article>';
}

const EXAM_DOC_KEY = '<p class="exm-doc-key"><span class="exm-chip exm-st-ontime">On time</span><span class="exm-chip exm-st-late">Late</span>'
    . '<span class="exm-chip exm-st-absent">Absent</span><span class="exm-chip exm-st-allocated">Not marked</span></p>';

// --- By exam / Duty roster: every exam with its invigilators ----------------------

function exam_doc_roster(array $rows, string $scope, string $fileBit): array {
    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['exam']['id']]['exam'] = $r['exam'];
        $groups[$r['exam']['id']]['duties'] ??= [];
        if (!empty($r['duty'])) $groups[$r['exam']['id']]['duties'][] = $r['duty'];
    }
    $html = '';
    $text = "Invigilation duty roster\n$scope\n";
    $last = '';
    foreach ($groups as $g) {
        $x = $g['exam'];
        if ($x['date'] !== $last) {
            $last = $x['date'];
            $html .= '<tr class="exm-doc-date"><th colspan="5">' . exam_doc_e(exam_doc_day($x['date'])) . '</th></tr>';
            $text .= "\n" . strtoupper(exam_doc_day($x['date'])) . "\n";
        }
        $who = $g['duties'] ? implode('', array_map(fn($d) => '<span class="exm-chip exm-st-' . $d['status'] . '">' . exam_doc_e($d['name'])
            . ($d['role'] !== EXAM_ROLES[0] ? ' <small>' . exam_doc_e($d['role']) . '</small>' : '') . '</span>', $g['duties']))
            : '<span class="status exm-short">Not allocated</span>';
        $html .= '<tr><td>' . exam_doc_e(exam_doc_span($x)) . '</td><td><strong>' . exam_doc_e(exam_clock($x['report'])) . '</strong></td>'
            . '<td>' . exam_doc_e($x['subject']) . '<small>' . exam_doc_e($x['class'] . ' · ' . exam_doc_school($x['school'])) . '</small></td>'
            . '<td>' . exam_doc_e($x['room'] ?: '-') . '</td><td>' . $who . '</td></tr>';
        $text .= exam_doc_span($x) . ' (report ' . exam_clock($x['report']) . ') | ' . $x['subject'] . ' - ' . $x['class'] . ($x['room'] !== '' ? ' | Room ' . $x['room'] : '') . "\n"
            . '   Invigilators: ' . ($g['duties'] ? implode(', ', array_map(fn($d) => $d['name'] . ($d['role'] !== EXAM_ROLES[0] ? " ({$d['role']})" : ''), $g['duties'])) : 'not allocated') . "\n";
    }
    $body = $groups ? '<table class="exm-doc-table"><thead><tr><th>Time</th><th>Report by</th><th>Exam</th><th>Room</th><th>Invigilators</th></tr></thead><tbody>'
        . $html . '</tbody></table>' . EXAM_DOC_KEY : '<p class="field-help">No exams match these filters.</p>';
    return ['title' => 'Invigilation duty roster', 'scope' => $scope, 'body' => $body, 'text' => $text . "\n" . str_replace("\n", ', ', EXAM_DOC_SIGN),
        'sheet' => exam_doc_duty_sheet($rows), 'file' => "exam-duty-roster-$fileBit.xlsx", 'email' => '', 'subject' => "Invigilation duty roster - $scope"];
}

// --- Faculty duties: one row per person ----------------------------------------------

function exam_doc_faculty(array $people, string $scope, string $fileBit): array {
    $html = '';
    $text = "Faculty invigilation summary\n$scope\n\n";
    $sheet = [array_map(fn($h) => ['b', $h], ['Faculty', 'School', 'Duties', 'On time', 'Late', 'Absent', 'Not marked', 'Exams'])];
    foreach ($people as $p) {
        $c = exam_counts($p['rows']);
        $schools = implode(', ', array_map('exam_doc_school', array_values(array_unique($p['schools']))));
        $chips = implode('', array_map(fn($r) => '<span class="exm-chip exm-st-' . $r['duty']['status'] . '">' . exam_doc_e($r['exam']['subject'])
            . ' <small>' . date('j M', strtotime($r['exam']['date'])) . ', ' . exam_doc_e(exam_clock($r['exam']['start'])) . '</small></span>', $p['rows']));
        $row = $c['absent'] ? 'absent' : ($c['late'] ? 'late' : ($c['ontime'] === $c['duties'] ? 'ontime' : 'allocated'));
        $html .= '<tr class="exm-row-' . $row . '"><td><strong>' . exam_doc_e($p['name']) . '</strong><small>' . exam_doc_e($schools) . '</small></td>'
            . '<td class="exm-num">' . $c['duties'] . '</td><td class="exm-num">' . $c['ontime'] . '</td><td class="exm-num">' . $c['late'] . '</td><td class="exm-num">' . $c['absent'] . '</td><td>' . $chips . '</td></tr>';
        $exams = array_map(fn($r) => $r['exam']['subject'] . ' (' . date('j M', strtotime($r['exam']['date'])) . ', ' . exam_clock($r['exam']['start']) . ')', $p['rows']);
        $text .= $p['name'] . ': ' . $c['duties'] . ' ' . ($c['duties'] === 1 ? 'duty' : 'duties')
            . ($c['late'] ? ", {$c['late']} late" : '') . ($c['absent'] ? ", {$c['absent']} absent" : '') . "\n   " . implode('; ', $exams) . "\n";
        $sheet[] = [$p['name'], $schools, $c['duties'], [$c['ontime'] ? 'g' : '', $c['ontime']], [$c['late'] ? 'y' : '', $c['late']], [$c['absent'] ? 'r' : '', $c['absent']], $c['allocated'], implode('; ', $exams)];
    }
    $body = $people ? '<table class="exm-doc-table"><thead><tr><th>Faculty</th><th class="exm-num">Duties</th><th class="exm-num">On time</th><th class="exm-num">Late</th><th class="exm-num">Absent</th><th>Exams</th></tr></thead><tbody>'
        . $html . '</tbody></table>' . EXAM_DOC_KEY : '<p class="field-help">Nobody on duty matches these filters.</p>';
    return ['title' => 'Faculty invigilation summary', 'scope' => $scope, 'body' => $body, 'text' => $text . "\n" . str_replace("\n", ', ', EXAM_DOC_SIGN),
        'sheet' => $sheet, 'file' => "exam-faculty-summary-$fileBit.xlsx", 'email' => '', 'subject' => "Faculty invigilation summary - $scope"];
}

// --- Report: totals, then who missed, who was late, who attended ----------------------

function exam_doc_report(array $rows, string $scope, string $fileBit): array {
    $c = exam_counts($rows);
    $pct = fn(int $n): string => $c['duties'] ? round(100 * $n / $c['duties']) . '%' : '-';
    $tiles = '<div class="exm-doc-stats">';
    foreach ([['Duties', $c['duties'], ''], ['On time', $c['ontime'], 'ontime'], ['Late', $c['late'], 'late'], ['Absent', $c['absent'], 'absent'], ['Not marked', $c['allocated'], 'allocated']] as [$l, $n, $k]) {
        $tiles .= '<span class="exm-doc-stat' . ($k ? " is-$k" : '') . '"><small>' . $l . '</small><strong>' . $n . '</strong>' . ($k ? '<em>' . $pct($n) . '</em>' : '') . '</span>';
    }
    $tiles .= '</div>';
    $text = "Invigilation attendance report\n$scope\n\n{$c['duties']} duties: {$c['ontime']} on time, {$c['late']} late, {$c['absent']} absent, {$c['allocated']} not marked.\n";
    $sections = '';
    foreach ([['absent', 'Missed'], ['late', 'Late'], ['ontime', 'Attended on time'], ['allocated', 'Not marked yet']] as [$k, $label]) {
        $set = array_values(array_filter($rows, fn($r) => $r['duty']['status'] === $k));
        if (!$set) continue;
        $timed = $k === 'late' || $k === 'ontime';
        $sections .= '<h3 class="exm-doc-sub exm-sub-' . $k . '">' . $label . ' <span>' . count($set) . '</span></h3><table class="exm-doc-table"><thead><tr><th>Date and time</th><th>Exam</th><th>Faculty</th>'
            . ($timed ? '<th>Report by</th><th>Arrived</th>' : '') . '<th>Remark</th></tr></thead><tbody>';
        $text .= "\n" . strtoupper($label) . ' (' . count($set) . ")\n";
        foreach ($set as $r) {
            $x = $r['exam'];
            $d = $r['duty'];
            $sections .= '<tr class="exm-row-' . $k . '"><td>' . exam_doc_e(exam_doc_day($x['date'])) . '<small>' . exam_doc_e(exam_doc_span($x)) . '</small></td>'
                . '<td>' . exam_doc_e($x['subject']) . '<small>' . exam_doc_e($x['class']) . '</small></td><td>' . exam_doc_e($d['name']) . '</td>'
                . ($timed ? '<td>' . exam_doc_e(exam_clock($x['report'])) . '</td><td>' . ($d['arrived'] !== '' ? exam_doc_e(exam_clock($d['arrived'])) : '-') . '</td>' : '')
                . '<td>' . exam_doc_e($d['remark'] ?: '-') . '</td></tr>';
            if ($k !== 'ontime') {
                $text .= '- ' . $d['name'] . ': ' . $x['subject'] . ', ' . exam_doc_day($x['date']) . ' ' . exam_clock($x['start'])
                    . ($k === 'late' && $d['arrived'] !== '' ? ' (arrived ' . exam_clock($d['arrived']) . ', report by ' . exam_clock($x['report']) . ')' : '')
                    . ($d['remark'] !== '' ? ' - ' . $d['remark'] : '') . "\n";
            }
        }
        if ($k === 'ontime') $text .= count($set) . " duties attended on time.\n";
        $sections .= '</tbody></table>';
    }
    $body = $rows ? $tiles . $sections : '<p class="field-help">No duties match this report.</p>';
    return ['title' => 'Invigilation attendance report', 'scope' => $scope, 'body' => $body, 'text' => $text . "\n" . str_replace("\n", ', ', EXAM_DOC_SIGN),
        'sheet' => exam_doc_duty_sheet($rows), 'file' => "exam-attendance-report-$fileBit.xlsx", 'email' => '', 'subject' => "Invigilation attendance report - $scope"];
}

// --- One exam: the sheet that goes to the exam room ------------------------------------

function exam_doc_sheet(array $x, array $duties): array {
    $rows = '';
    $text = "Invigilation duty sheet\n" . exam_doc_line($x) . "\n\nInvigilators:\n";
    $i = 0;
    foreach ($duties as $d) {
        $i++;
        $in = $d['arrived'] !== '' ? exam_clock($d['arrived']) : '';
        $rows .= '<tr class="exm-row-' . $d['status'] . '"><td class="exm-num">' . $i . '</td><td><strong>' . exam_doc_e($d['name']) . '</strong></td><td>' . exam_doc_e($d['role']) . '</td>'
            . '<td class="exm-doc-fill">' . exam_doc_e($in) . '</td><td class="exm-doc-fill">' . ($d['status'] !== 'allocated' ? exam_doc_pill($d['status']) : '') . '</td><td class="exm-doc-fill exm-doc-signbox"></td></tr>';
        $text .= "$i. {$d['name']} ({$d['role']})" . ($d['status'] !== 'allocated' ? ' - ' . EXAM_STATUS_LABEL[$d['status']] . ($in !== '' ? " at $in" : '') : '') . "\n";
    }
    $facts = '<dl class="exm-doc-facts">'
        . '<div><dt>Date</dt><dd>' . exam_doc_e(exam_doc_day($x['date'])) . '</dd></div>'
        . '<div><dt>Time</dt><dd>' . exam_doc_e(exam_doc_span($x)) . '</dd></div>'
        . '<div><dt>Report by</dt><dd><strong>' . exam_doc_e(exam_clock($x['report'])) . '</strong></dd></div>'
        . '<div><dt>Room</dt><dd>' . exam_doc_e($x['room'] ?: '-') . '</dd></div>'
        . '<div><dt>Class</dt><dd>' . exam_doc_e($x['class']) . '</dd></div>'
        . '<div><dt>School</dt><dd>' . exam_doc_e(SCHOOLS[$x['school']]['name'] ?? $x['school']) . '</dd></div></dl>';
    $body = $facts . ($duties ? '<table class="exm-doc-table"><thead><tr><th class="exm-num">#</th><th>Invigilator</th><th>Role</th><th>Time in</th><th>Status</th><th>Signature</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="field-help">Nobody is allocated to this exam yet.</p>')
        . '<p class="exm-doc-note">Invigilators report by ' . exam_doc_e(exam_clock($x['report'])) . '. Arrival after it is recorded as late.</p>';
    return ['title' => 'Duty sheet: ' . $x['subject'], 'scope' => $x['class'], 'body' => $body, 'text' => $text . "\n" . str_replace("\n", ', ', EXAM_DOC_SIGN),
        'sheet' => exam_doc_duty_sheet(array_map(fn($d) => ['exam' => $x, 'duty' => $d], array_values($duties)) ?: [['exam' => $x, 'duty' => []]]),
        'file' => 'exam-duty-sheet-' . $x['date'] . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($x['subject'])) . '.xlsx',
        'email' => '', 'subject' => 'Invigilation duty sheet: ' . $x['subject'] . ', ' . exam_doc_day($x['date'])];
}

// --- One person's duty letter ------------------------------------------------------------

function exam_doc_letter(string $name, array $rows, string $email, string $scope): array {
    $n = count($rows);
    $text = "Dear $name,\n\nYou have been allotted the following invigilation " . ($n === 1 ? 'duty' : 'duties') . ":\n\n";
    $html = '';
    foreach ($rows as $i => $r) {
        $x = $r['exam'];
        $d = $r['duty'];
        $text .= ($i + 1) . '. ' . exam_doc_line($x) . ' - ' . $d['role'] . "\n";
        $html .= '<tr class="exm-row-' . $d['status'] . '"><td>' . exam_doc_e(exam_doc_day($x['date'])) . '</td><td>' . exam_doc_e(exam_doc_span($x)) . '</td><td><strong>' . exam_doc_e(exam_clock($x['report'])) . '</strong></td>'
            . '<td>' . exam_doc_e($x['subject']) . '<small>' . exam_doc_e($x['class']) . '</small></td><td>' . exam_doc_e($x['room'] ?: '-') . '</td><td>' . exam_doc_e($d['role']) . '</td><td>' . exam_doc_pill($d['status']) . '</td></tr>';
    }
    $text .= "\nPlease report to the exam room by the reporting time. Arrival after it is recorded as late.\n\n" . EXAM_DOC_SIGN;
    $body = $rows ? '<p>Dear ' . exam_doc_e($name) . ',</p><p>You have been allotted the following invigilation ' . ($n === 1 ? 'duty' : 'duties')
        . '. Please report to the exam room by the reporting time; arrival after it is recorded as late.</p>'
        . '<table class="exm-doc-table"><thead><tr><th>Date</th><th>Time</th><th>Report by</th><th>Exam</th><th>Room</th><th>Role</th><th>Status</th></tr></thead><tbody>' . $html . '</tbody></table>'
        . '<p class="exm-doc-sign">' . nl2br(exam_doc_e(EXAM_DOC_SIGN)) . '</p>'
        : '<p class="field-help">No duties for this person in these filters.</p>';
    return ['title' => 'Invigilation duties: ' . $name, 'scope' => $scope, 'body' => $body, 'text' => $text,
        'sheet' => exam_doc_duty_sheet($rows), 'file' => 'exam-duties-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) . '-' . date('Y-m-d') . '.xlsx',
        'email' => $email, 'subject' => 'Your invigilation duties'];
}
