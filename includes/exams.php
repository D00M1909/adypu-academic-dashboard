<?php
// The Exams tab: the exam schedule, who is on duty at each paper, and whether
// they turned up by the reporting time. One store file, data/exams.php:
//   exams   id => school, class, subject, date, start, end, report, room, need
//   duties  id => exam, person (timetable id, '' when the name matched no one),
//                 name, email, role, status, arrived, remark, by, at
//   mail    id => to, name, subject, body, queued, sent
// Times are 'HH:MM', 24-hour. Only an admin changes anything (exam_can_edit()).
//
// No mail leaves this host (mail() is off, outbound HTTP is blocked), so a late
// email is queued here and handed to the Apps Script in the reply to its next
// push (api/ingest.php); the script sends it with MailApp and says so on the
// push after (X-Mail-Sent), which is when it is marked sent.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/timetable.php';
require_once __DIR__ . '/xlsx-read.php';

// On time and Late are never chosen, only worked out (exam_set_status()).
const EXAM_STATUS_LABEL = ['allocated' => 'Not marked', 'ontime' => 'On time', 'late' => 'Late', 'absent' => 'Absent', 'na' => 'Not applicable'];
const EXAM_ROLES = ['Invigilator', 'Senior supervisor', 'Squad'];
// Reporting time when a row or the form gives none: half an hour before.
const EXAM_REPORT_BEFORE = 30;
const EXAM_DEFAULT_NEED = 2;
// The upload and the export share these columns, so a downloaded schedule
// goes straight back up. Matched by prefix, case-blind, in any order.
const EXAM_COLUMNS = ['Date', 'Start', 'End', 'Reporting', 'School', 'Class', 'Subject', 'Room', 'Invigilators needed', 'Invigilators'];

// --- Who may do what ---------------------------------------------------------

// Admin only for now. The exam cell coordinator, when there is one, gets
// exam_can_mark() (attendance and remarks) without exam_can_edit().
function exam_can_edit(array $u): bool {
    return !empty($u['admin']);
}

function exam_can_mark(array $u): bool {
    return exam_can_edit($u);
}

// --- Reading -----------------------------------------------------------------

function exam_data(): array {
    $d = store_read('exams');
    return ['exams' => $d['exams'] ?? [], 'duties' => $d['duties'] ?? [], 'mail' => $d['mail'] ?? [], 'sign' => $d['sign'] ?? []];
}

// Today, as the lock sees it. A test pins it with EXAM_TODAY.
function exam_today(): string {
    return defined('EXAM_TODAY') ? EXAM_TODAY : date('Y-m-d');
}

// An exam whose day is over is kept as it was: nothing about it changes any
// more, its duties and attendance included. The exam's own day stays open,
// since that is when attendance is marked.
function exam_is_past(array $x): bool {
    return $x['date'] < exam_today();
}

// Who signs the printed documents: a school's own list, else the one for
// every school, else the exam cell's two defaults. Each is [role, name].
const EXAM_SIGN_DEFAULT = [['ECR In-charge', ''], ['Dean', '']];

function exam_signatories(array $data, string $school): array {
    return $data['sign'][$school] ?? $data['sign'][''] ?? EXAM_SIGN_DEFAULT;
}

function exam_set_signatories(string $school, array $roles, array $names): void {
    $list = [];
    foreach ($roles as $i => $r) {
        $r = trim(preg_replace('/\s+/', ' ', (string) $r));
        $n = trim(preg_replace('/\s+/', ' ', (string) ($names[$i] ?? '')));
        if ($r !== '' || $n !== '') $list[] = [mb_substr($r, 0, 60), mb_substr($n, 0, 60)];
    }
    exam_save(function (array $d) use ($school, $list) {
        if ($list) $d['sign'][$school] = array_slice($list, 0, 4);
        else unset($d['sign'][$school]);
        return $d;
    });
}

function exam_min(string $hhmm): int {
    return preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m) ? 60 * (int) $m[1] + (int) $m[2] : -1;
}

function exam_hhmm(int $min): string {
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

// "10:00" as people write it on a notice: "10.00 am".
function exam_clock(string $hhmm): string {
    $m = exam_min($hhmm);
    if ($m < 0) return $hhmm;
    $h = intdiv($m, 60);
    return (($h + 11) % 12 + 1) . '.' . sprintf('%02d', $m % 60) . ($h < 12 ? ' am' : ' pm');
}

function exam_id(string $date, string $start, string $class, string $subject): string {
    return substr(sha1("$date|$start|" . strtolower($class) . '|' . strtolower($subject)), 0, 10);
}

// Exams in date and start order, optionally one school's and one date range's.
function exam_list(array $data, string $school = '', string $from = '', string $to = ''): array {
    $out = array_filter($data['exams'], fn($x) => ($school === '' || $x['school'] === $school)
        && ($from === '' || $x['date'] >= $from) && ($to === '' || $x['date'] <= $to));
    uasort($out, fn($a, $b) => [$a['date'], $a['start'], $a['class']] <=> [$b['date'], $b['start'], $b['class']]);
    return $out;
}

function exam_duties_of(array $data, string $examId): array {
    $out = array_filter($data['duties'], fn($d) => $d['exam'] === $examId);
    uasort($out, fn($a, $b) => [array_search($a['role'], EXAM_ROLES), $a['name']] <=> [array_search($b['role'], EXAM_ROLES), $b['name']]);
    return $out;
}

function exam_short(array $data, string $examId): int {
    return max(0, (int) $data['exams'][$examId]['need'] - count(exam_duties_of($data, $examId)));
}

function exam_label(array $x): string {
    return $x['subject'] . ' · ' . $x['class'];
}

// --- Clashes -----------------------------------------------------------------

// What a person already has between $start and $end on $date: a lecture on the
// weekly timetable that weekday, or another exam duty. '' when free.
function exam_busy(array $t, array $faculty, array $data, string $person, string $date, string $start, string $end, string $skipExam = ''): string {
    $s = exam_min($start);
    $e = exam_min($end);
    foreach ($data['duties'] as $d) {
        if ($d['person'] !== $person || $d['exam'] === $skipExam) continue;
        $x = $data['exams'][$d['exam']] ?? null;
        if ($x && $x['date'] === $date && exam_min($x['start']) < $e && $s < exam_min($x['end'])) return 'on duty at ' . $x['subject'];
    }
    if (!isset($faculty[$person])) return '';
    static $teach = [];
    $teach[$person] ??= timetable_teaching($t, $faculty[$person]);
    $day = date('l', strtotime($date));
    foreach ($teach[$person] as $b) {
        if ($b['day'] !== $day || !($m = timetable_minutes($b['time']))) continue;
        $bEnd = timetable_end($b) ?? $m[0] + 60;
        if ($m[0] < $e && $s < $bEnd) return 'teaching ' . $b['code'] . ' then';
    }
    return '';
}

// Duties each person holds in $data, for spreading the load evenly.
function exam_load(array $data): array {
    $n = [];
    foreach ($data['duties'] as $d) if ($d['person'] !== '') $n[$d['person']] = ($n[$d['person']] ?? 0) + 1;
    return $n;
}

// Who could take a duty at this exam: the school's own faculty first, then
// everyone else, each with what they are doing then ('' when free) and how
// many duties they already have.
function exam_candidates(array $t, array $faculty, array $data, array $x): array {
    $load = exam_load($data);
    $taken = array_column(exam_duties_of($data, $x['id']), 'person');
    $out = [];
    foreach ($faculty as $id => $f) {
        if (in_array($id, $taken, true)) continue;
        $out[$id] = ['name' => $f['name'], 'own' => in_array($x['school'], $f['schools'], true),
            'busy' => exam_busy($t, $faculty, $data, $id, $x['date'], $x['start'], $x['end']), 'load' => $load[$id] ?? 0];
    }
    uasort($out, fn($a, $b) => [!$a['own'], $a['busy'] !== '', $a['load'], timetable_person($a['name'])]
        <=> [!$b['own'], $b['busy'] !== '', $b['load'], timetable_person($b['name'])]);
    return $out;
}

// --- Writing -----------------------------------------------------------------

function exam_save(callable $mutate): bool {
    return store_update('exams', function (array $d) use ($mutate) {
        $d += ['exams' => [], 'duties' => [], 'mail' => []];
        return $mutate($d);
    });
}

function exam_new_id(): string {
    return bin2hex(random_bytes(5));
}

// One exam from a form or an upload row, checked. [exam, error].
function exam_make(array $in): array {
    $date = exam_parse_date((string) ($in['date'] ?? ''));
    $start = exam_parse_time((string) ($in['start'] ?? ''));
    $end = exam_parse_time((string) ($in['end'] ?? ''));
    $report = exam_parse_time((string) ($in['report'] ?? ''));
    $school = exam_parse_school((string) ($in['school'] ?? ''));
    $class = trim(preg_replace('/\s+/', ' ', (string) ($in['class'] ?? '')));
    $subject = trim(preg_replace('/\s+/', ' ', (string) ($in['subject'] ?? '')));
    if ($date === '') return [null, 'a date the page can read (2026-11-24 or 24/11/2026)'];
    if ($start === '' || $end === '') return [null, 'a start and an end time'];
    if (exam_min($end) <= exam_min($start)) return [null, 'an end time after the start'];
    if ($school === '') return [null, 'a school'];
    if ($subject === '' || $class === '') return [null, 'a subject and a class'];
    if ($report === '') $report = exam_hhmm(max(0, exam_min($start) - EXAM_REPORT_BEFORE));
    $need = (int) ($in['need'] ?? 0);
    return [['id' => exam_id($date, $start, $class, $subject), 'school' => $school, 'class' => $class, 'subject' => $subject,
        'date' => $date, 'start' => $start, 'end' => $end, 'report' => $report,
        'room' => trim((string) ($in['room'] ?? '')), 'need' => $need > 0 ? min($need, 40) : EXAM_DEFAULT_NEED,
        'sample' => !empty($in['sample'])], ''];
}

function exam_add(array $in, string $by): string {
    [$x, $why] = exam_make($in);
    if (!$x) return "Please give $why.";
    $dupe = false;
    exam_save(function (array $d) use ($x, $by, &$dupe) {
        if (isset($d['exams'][$x['id']])) { $dupe = true; return $d; }
        $d['exams'][$x['id']] = $x + ['by' => $by, 'added' => date('Y-m-d H:i')];
        return $d;
    });
    return $dupe ? 'That exam is already on the schedule.' : '';
}

function exam_delete(string $id): void {
    exam_save(function (array $d) use ($id) {
        unset($d['exams'][$id]);
        $d['duties'] = array_filter($d['duties'], fn($x) => $x['exam'] !== $id);
        return $d;
    });
}

function exam_duty(string $exam, string $person, string $name, string $role, string $email, string $by): array {
    return ['exam' => $exam, 'person' => $person, 'name' => $name, 'email' => $email,
        'role' => in_array($role, EXAM_ROLES, true) ? $role : EXAM_ROLES[0],
        'status' => 'allocated', 'arrived' => '', 'remark' => '', 'by' => $by, 'at' => date('Y-m-d H:i')];
}

function exam_assign(string $examId, string $person, array $faculty, string $role, string $email, string $by): string {
    if (!isset($faculty[$person])) return 'Pick a faculty member.';
    $email = trim($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'That email address does not look right.';
    $why = '';
    exam_save(function (array $d) use ($examId, $person, $faculty, $role, $email, $by, &$why) {
        if (!isset($d['exams'][$examId])) { $why = 'That exam is gone.'; return $d; }
        foreach ($d['duties'] as $x) if ($x['exam'] === $examId && $x['person'] === $person) { $why = 'Already on duty at this exam.'; return $d; }
        $d['duties'][exam_new_id()] = exam_duty($examId, $person, $faculty[$person]['name'], $role, $email, $by);
        return $d;
    });
    return $why;
}

function exam_unassign(string $dutyId): void {
    exam_save(function (array $d) use ($dutyId) {
        unset($d['duties'][$dutyId]);
        return $d;
    });
}

// Fill every exam in $ids up to its need: the school's own faculty who are
// free then, fewest duties first. Returns how many duties it added.
function exam_auto_allocate(array $t, array $faculty, array $ids, string $by): int {
    $added = 0;
    exam_save(function (array $d) use ($t, $faculty, $ids, $by, &$added) {
        foreach ($ids as $id) {
            $x = $d['exams'][$id] ?? null;
            if (!$x || exam_is_past($x)) continue;
            for ($short = exam_short($d, $id); $short > 0; $short--) {
                $pick = null;
                foreach (exam_candidates($t, $faculty, $d, $x) as $p => $c) {
                    if ($c['busy'] === '') { $pick = $p; break; }
                }
                if ($pick === null) break;
                $d['duties'][exam_new_id()] = exam_duty($id, $pick, $faculty[$pick]['name'], EXAM_ROLES[0], '', $by)
                    + (!empty($x['sample']) ? ['sample' => true] : []);
                $added++;
            }
        }
        return $d;
    });
    return $added;
}

// The marking buttons: Present, Absent, Not applicable. There is no On time or
// Late button and no arrival time to type, so neither can be chosen: Present
// stamps this server's clock at the click, and a click after the reporting
// time is Late (with its email), before it On time. Present on a duty that is
// already present keeps its first stamp, so it cannot be re-clicked earlier
// or later. Not applicable is a reserve invigilator who was not called on.
// Returns 1 when a late email was queued. $now ('Y-m-d H:i') is for tests.
const EXAM_MARKS = ['present' => 'Present', 'absent' => 'Absent', 'na' => 'Not applicable'];

function exam_set_status(string $examId, string $dutyId, string $mark, array $emails, string $by, ?string $now = null): int {
    if (!isset(EXAM_MARKS[$mark])) return 0;
    $now ??= date('Y-m-d H:i');
    $queued = 0;
    exam_save(function (array $d) use ($examId, $dutyId, $mark, $emails, $by, $now, &$queued) {
        $x = $d['exams'][$examId] ?? null;
        if (!$x || ($d['duties'][$dutyId]['exam'] ?? '') !== $examId) return $d;
        $duty = &$d['duties'][$dutyId];
        $was = $duty['status'];
        if ($mark !== 'present') {
            $duty['status'] = $mark;
            $duty['arrived'] = '';
        } elseif (!in_array($was, ['ontime', 'late'], true)) {
            // The click's time, always; and its day, for a click not on the exam's own day.
            $duty['arrived'] = substr($now, 11, 5);
            $duty['present_at'] = $now;
            $duty['status'] = $now > "{$x['date']} {$x['report']}" ? 'late' : 'ontime';
        }
        $duty['marked_by'] = $by;
        $duty['marked_at'] = $now;
        unset($duty);
        $queued = exam_queue_late($d, $dutyId, $was, $x, $emails);
        return $d;
    });
    return $queued;
}

// The remarks under an exam, saved together. Status is the buttons' alone.
function exam_remarks(string $examId, array $remark, string $by): void {
    exam_save(function (array $d) use ($examId, $remark, $by) {
        foreach ($d['duties'] as $id => &$duty) {
            if ($duty['exam'] !== $examId || !isset($remark[$id])) continue;
            $r = mb_substr(trim((string) $remark[$id]), 0, 500);
            if ($r === $duty['remark']) continue;
            $duty['remark'] = $r;
            $duty['remark_by'] = $by;
        }
        unset($duty);
        return $d;
    });
}

// Queues a duty's late email when it has just turned late and an address is
// known. Sample duties never mail. Returns how many were queued (0 or 1).
function exam_queue_late(array &$d, string $id, string $was, array $x, array $emails): int {
    $duty = $d['duties'][$id];
    $to = $duty['email'] !== '' ? $duty['email'] : (string) ($emails[$duty['person']] ?? '');
    if ($duty['status'] !== 'late' || $was === 'late' || $to === '' || !empty($duty['sample'])) return 0;
    $d['mail'][$id] = exam_late_mail($to, $duty, $x);
    return 1;
}

// Changes an exam's details in place. Its id stays, so its duties stay with it.
function exam_update(string $id, array $in): string {
    [$x, $why] = exam_make($in);
    if (!$x) return "Please give $why.";
    $gone = false;
    exam_save(function (array $d) use ($id, $x, &$gone) {
        if (!isset($d['exams'][$id])) { $gone = true; return $d; }
        $d['exams'][$id] = ['id' => $id, 'sample' => !empty($d['exams'][$id]['sample'])] + $x + $d['exams'][$id];
        return $d;
    });
    return $gone ? 'That exam is gone.' : '';
}

// When Present was clicked, as shown: "3.26 pm", or "3.26 pm, 18 Nov" when
// the click was not on the exam's own day. '' before anyone is present.
// A Present clicked before the exam's day, under the first version of the
// buttons, kept no time; its marked_at is that click, so it stands in.
function exam_arrived(array $duty, array $x): string {
    $at = (string) ($duty['present_at'] ?? '');
    if (($duty['arrived'] ?? '') === '' && in_array($duty['status'] ?? '', ['ontime', 'late'], true) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', (string) ($duty['marked_at'] ?? ''))) {
        $at = $duty['marked_at'];
        $duty['arrived'] = substr($at, 11, 5);
    }
    if (($duty['arrived'] ?? '') === '') return '';
    $on = substr($at, 0, 10);
    return exam_clock($duty['arrived']) . ($on !== '' && $on !== $x['date'] ? ', ' . date('j M', strtotime($on)) : '');
}

function exam_late_mail(string $to, array $duty, array $x): array {
    $day = date('l j F Y', strtotime($x['date']));
    $arrived = $duty['arrived'] !== '' ? ' You were marked present at ' . exam_arrived($duty, $x) . '.' : '';
    $body = "Dear {$duty['name']},\n\n"
        . "You were on {$duty['role']} duty for {$x['subject']} ({$x['class']}" . ($x['room'] !== '' ? ", {$x['room']}" : '') . ") on $day. "
        . 'The reporting time was ' . exam_clock($x['report']) . '.' . $arrived . "\n\n"
        . "This has been recorded as late. Please report on time for your remaining duties.\n\n"
        . "Exam Cell\nAjeenkya D Y Patil University\n";
    return ['to' => $to, 'name' => $duty['name'], 'subject' => "Late for exam duty on $day", 'body' => $body,
        'queued' => date('Y-m-d H:i'), 'sent' => ''];
}

// --- The mail hand-off (api/ingest.php) ---------------------------------------

// Marks the ids the Apps Script says it sent, drops sent mail older than 30
// days, and returns what is still to send (at most 50 a push).
function exam_mail_handoff(array $sentIds): array {
    $pending = [];
    exam_save(function (array $d) use ($sentIds, &$pending) {
        foreach ($sentIds as $id) if (isset($d['mail'][$id]) && $d['mail'][$id]['sent'] === '') $d['mail'][$id]['sent'] = date('Y-m-d H:i');
        $cut = date('Y-m-d', strtotime('-30 days'));
        $d['mail'] = array_filter($d['mail'], fn($m) => $m['sent'] === '' || $m['sent'] >= $cut);
        foreach ($d['mail'] as $id => $m) {
            if ($m['sent'] === '' && count($pending) < 50) $pending[] = ['id' => (string) $id, 'to' => $m['to'], 'subject' => $m['subject'], 'body' => $m['body']];
        }
        return $d;
    });
    return $pending;
}

// The email each timetable person can be reached at: their account's.
function exam_emails(array $users, array $faculty): array {
    require_once __DIR__ . '/audit.php';
    $out = [];
    foreach ($users as $u) {
        if (($u['status'] ?? 'active') !== 'active' || empty($u['email'])) continue;
        $p = audit_person_for($u, $faculty);
        if ($p !== '') $out[$p] ??= (string) $u['email'];
    }
    return $out;
}

// --- Upload and export ---------------------------------------------------------

function exam_parse_date(string $s): string {
    $s = trim($s);
    if (preg_match('/^\d{5}(\.0+)?$/', $s)) return date('Y-m-d', ((int) $s - 25569) * 86400); // Excel serial
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $s, $m)) {
        $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : '';
    }
    $ts = $s !== '' ? strtotime($s) : false;
    return $ts ? date('Y-m-d', $ts) : '';
}

// "10:00", "10.00", "10 am", "2:30 PM", or Excel's fraction of a day.
function exam_parse_time(string $s): string {
    $s = strtolower(trim($s));
    if ($s === '') return '';
    if (preg_match('/^0?\.\d+$/', $s)) return exam_hhmm((int) round((float) $s * 1440) % 1440);
    if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(?::\d{2})?\s*([ap])?\.?m?\.?$/', $s, $m)) return '';
    $h = (int) $m[1];
    $min = (int) ($m[2] ?? 0);
    $ap = $m[3] ?? '';
    if ($ap === 'p' && $h < 12) $h += 12;
    if ($ap === 'a' && $h === 12) $h = 0;
    // No am/pm and before 8: an afternoon paper written 12-hour ("2.00").
    if ($ap === '' && $h < 8) $h += 12;
    return $h < 24 && $min < 60 ? exam_hhmm(60 * $h + $min) : '';
}

function exam_parse_school(string $s): string {
    $k = strtolower(trim($s));
    if ($k === '') return '';
    if (isset(SCHOOLS[$k])) return $k;
    foreach (SCHOOLS as $id => $sc) {
        $n = strtolower($sc['name']);
        if ($k === $n || $k === preg_replace('/^school of /', '', $n)) return $id;
    }
    return '';
}

// Rows of a CSV or the first tab of an .xlsx, as lists of strings.
function exam_read_rows(string $path, string $name): array {
    if (preg_match('/\.xlsx$/i', $name)) {
        $book = read_xlsx($path);
        $grid = reset($book) ?: [];
        $rows = [];
        foreach ($grid as $r => $cells) {
            $row = [];
            for ($c = 0, $n = $cells ? max(array_keys($cells)) : -1; $c <= $n; $c++) $row[] = (string) ($cells[$c] ?? '');
            $rows[$r] = $row;
        }
        ksort($rows);
        return array_values($rows);
    }
    $fh = fopen($path, 'r');
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) $rows[] = array_map(fn($v) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $r);
    fclose($fh);
    return $rows;
}

// The upload: every row of the schedule in EXAM_COLUMNS' format, the header
// found wherever it sits. A row already on the schedule (same date, start,
// class and subject) is updated in place, its duties kept; named invigilators
// are added. $sample marks every row as test data: it never mails, and
// Remove sample data clears it. Returns [added, updated, problems].
// The exam cell's own duty chart: a title ("Unit Test Duty Chart (Lateral
// Entry Students) - October 2026"), a header row with S. No, Name of Faculty
// and one date per session ("26-10-2026", "29th October (E)"), the session's
// time under each date ("12:30 pm to 1:30 pm", "3:00-4:00PM"), then one row
// per person with a tick under each session they hold. A date merged across
// columns is read by the first, so an empty date cell repeats the last one.
// null when the rows are not laid out like that.
const EXAM_TICKS = ['✓', '✔', '√', '☑', '✅', 'y', 'yes', '1', 'x', 'p', 'tick'];

function exam_chart_parse(array $rows): ?array {
    $title = '';
    $year = 0;
    foreach ($rows as $i => $r) {
        $nameCol = null;
        foreach ($r as $c => $v) if (preg_match('/^\s*name/i', (string) $v)) { $nameCol = $c; break; }
        if ($nameCol === null) {
            // Rows above the header: the title, and the year a "29th October" lacks.
            $line = trim(implode(' ', array_filter(array_map('trim', $r), fn($v) => $v !== '')));
            if ($line !== '' && $title === '') $title = $line;
            if (preg_match('/\b(20\d\d)\b/', $line, $m)) $year = (int) $m[1];
            continue;
        }
        $times = $rows[$i + 1] ?? [];
        $sessions = [];
        $date = '';
        foreach ($r as $c => $v) {
            if ($c <= $nameCol) continue;
            $d = exam_chart_date((string) $v, $year ?: (int) date('Y', strtotime(exam_today())));
            if ($d !== '') $date = $d;
            [$start, $end] = exam_chart_time((string) ($times[$c] ?? ''));
            if ($date !== '' && $start !== '') $sessions[$c] = ['date' => $date, 'start' => $start, 'end' => $end, 'names' => []];
        }
        if (!$sessions) return null;
        foreach (array_slice($rows, $i + 2) as $p) {
            $name = trim((string) ($p[$nameCol] ?? ''));
            if ($name === '') continue;
            foreach ($sessions as $c => $s) {
                // A tick may carry the time Present was clicked after it ("✓ 3.26 pm").
                $cell = preg_split('/\s+/u', mb_strtolower(trim((string) ($p[$c] ?? ''))))[0];
                if (in_array($cell, EXAM_TICKS, true)) $sessions[$c]['names'][] = $name;
            }
        }
        $subject = trim(preg_replace('/\s*(duty chart|invigilation).*$/i', '', $title), " -–:");
        $class = preg_match('/\(([^)]+)\)/', $title, $m) ? trim($m[1]) : '';
        return ['subject' => $subject !== '' ? $subject : 'Exam', 'class' => $class !== '' ? $class : 'All classes', 'sessions' => array_values($sessions)];
    }
    return null;
}

// A chart's date heading: "26-10-2026", "26/10/2026", "29th October (E)", an
// Excel serial. The year comes from the title when the cell has none.
function exam_chart_date(string $s, int $year): string {
    $s = trim(preg_replace(['/\([^)]*\)/', '/(\d)(st|nd|rd|th)\b/i'], ['', '$1'], $s));
    if ($s === '' || !preg_match('/\d/', $s)) return '';
    if (preg_match('/^\d{5}(\.0+)?$/', $s) || preg_match('#^\d{1,4}[/.-]\d{1,2}[/.-]\d{2,4}$#', $s)) return exam_parse_date($s);
    if (!preg_match('/[a-z]{3}/i', $s)) return '';
    $ts = strtotime(preg_match('/\b20\d\d\b/', $s) ? $s : "$s $year");
    return $ts ? date('Y-m-d', $ts) : '';
}

// A chart's time cell: "12:30 pm to 1:30 pm", "3:00-4:00PM", "10:30-11:30AM".
// A start written without am/pm takes the end's, unless that puts it after the end.
function exam_chart_time(string $s): array {
    $parts = preg_split('/\s*(?:\bto\b|-|–|—)\s*/i', trim(strtolower($s)));
    if (count($parts) !== 2) return ['', ''];
    $end = exam_parse_time($parts[1]);
    $start = exam_parse_time($parts[0]);
    if (!preg_match('/[ap]\.?m/', $parts[0]) && preg_match('/([ap])\.?m/', $parts[1], $m)) {
        $with = exam_parse_time($parts[0] . ' ' . $m[1] . 'm');
        if ($with !== '' && $end !== '' && exam_min($with) < exam_min($end)) $start = $with;
    }
    return $start !== '' && $end !== '' && exam_min($end) > exam_min($start) ? [$start, $end] : ['', ''];
}

// A duty chart as exams: a session already on the schedule (same school, day
// and times) gets the names, a new one is made from the chart's title. Each
// session asks for as many invigilators as it has ticks.
function exam_chart_import(array $chart, string $school, array $faculty, string $by, bool $sample): array {
    $who = exam_name_index($faculty);
    $data = exam_data();
    $made = [];
    $problems = [];
    foreach ($chart['sessions'] as $s) {
        $when = date('j M', strtotime($s['date'])) . ' ' . exam_clock($s['start']);
        if ($s['date'] < exam_today()) { $problems[] = "$when: that day is over, and past exams cannot be changed."; continue; }
        $x = null;
        foreach ($data['exams'] as $old) {
            if ($old['school'] === $school && $old['date'] === $s['date'] && $old['start'] === $s['start'] && $old['end'] === $s['end']) { $x = $old; break; }
        }
        if ($x) {
            $x['need'] = max((int) $x['need'], min(40, count($s['names'])));
        } else {
            [$x, $why] = exam_make(['date' => $s['date'], 'start' => $s['start'], 'end' => $s['end'], 'school' => $school, 'class' => $chart['class'],
                'subject' => $chart['subject'], 'need' => max(1, count($s['names'])), 'sample' => $sample]);
            if (!$x) { $problems[] = "$when: needs $why."; continue; }
        }
        $made[] = [$x, exam_match_names($s['names'], $who, $faculty, $when, $problems)];
    }
    return [$made, $problems];
}

// Timetable people by every spelling they go by.
function exam_name_index(array $faculty): array {
    $who = [];
    foreach ($faculty as $id => $f) {
        foreach ($f['as'] as $a) $who[$a] = $id;
        $who[timetable_person($f['name'])] ??= $id;
    }
    return $who;
}

// Names as [person id, name]: the timetable's spelling when it knows them,
// as written (and said so) when it does not.
function exam_match_names(array $names, array $who, array $faculty, string $where, array &$problems): array {
    $out = [];
    foreach ($names as $n) {
        if (trim($n) === '') continue;
        $p = $who[timetable_person($n)] ?? '';
        if ($p === '') $problems[] = "$where: \"$n\" is not a name on the timetable; added as written.";
        $out[] = [$p, $p !== '' ? $faculty[$p]['name'] : trim($n)];
    }
    return $out;
}

// An upload, in either layout: the schedule's own columns (EXAM_COLUMNS) or
// the exam cell's duty chart, which names no school, so $school says it.
function exam_import(array $rows, array $faculty, string $by, bool $sample = false, string $school = ''): array {
    $head = null;
    $col = [];
    foreach ($rows as $i => $r) {
        $found = [];
        foreach ($r as $c => $v) {
            $v = strtolower(trim($v));
            // Longest name first, so "Invigilators needed" is not read as "Invigilators".
            foreach (['invigilators needed' => 'need', 'invigilators' => 'names', 'reporting' => 'report', 'date' => 'date', 'start' => 'start',
                'end' => 'end', 'school' => 'school', 'class' => 'class', 'subject' => 'subject', 'room' => 'room'] as $p => $k) {
                if ($v !== '' && str_starts_with($v, $p) && !isset($found[$k])) { $found[$k] = $c; break; }
            }
        }
        if (isset($found['date'], $found['subject'])) { $head = $i; $col = $found; break; }
    }
    $added = $updated = 0;
    if ($head === null) {
        $chart = exam_chart_parse($rows);
        if ($chart === null) return [0, 0, ['No header row was found: neither Date and Subject columns, nor a duty chart with Name of Faculty and a date over each session. Download the template to see the columns.']];
        if (!isset(SCHOOLS[$school])) return [0, 0, ['A duty chart names no school. Please pick the school it is for and upload it again.']];
        [$made, $problems] = exam_chart_import($chart, $school, $faculty, $by, $sample);
    } else {
        $who = exam_name_index($faculty);
        $problems = [];
        $made = [];
        $today = exam_today();
        foreach (array_slice($rows, $head + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($v) => trim($v) !== '')) continue;
            $in = [];
            foreach ($col as $k => $c) $in[$k] = (string) ($r[$c] ?? '');
            [$x, $why] = exam_make($in + ['sample' => $sample]);
            if (!$x) { $problems[] = 'Row ' . ($i + 1) . ": needs $why."; continue; }
            if ($x['date'] < $today) { $problems[] = 'Row ' . ($i + 1) . ': that day is over, and past exams cannot be changed.'; continue; }
            $made[] = [$x, exam_match_names(preg_split('/\s*[;,\n]\s*/', $in['names'] ?? ''), $who, $faculty, 'Row ' . ($i + 1), $problems)];
        }
    }
    exam_save(function (array $d) use ($made, $by, $sample, &$added, &$updated) {
        foreach ($made as [$x, $names]) {
            if (isset($d['exams'][$x['id']])) {
                $d['exams'][$x['id']] = $x + $d['exams'][$x['id']];
                $updated++;
            } else {
                $d['exams'][$x['id']] = $x + ['by' => $by, 'added' => date('Y-m-d H:i')];
                $added++;
            }
            $have = [];
            foreach ($d['duties'] as $duty) if ($duty['exam'] === $x['id']) $have[] = strtolower($duty['name']);
            foreach ($names as [$p, $n]) {
                if (in_array(strtolower($n), $have, true)) continue;
                $d['duties'][exam_new_id()] = exam_duty($x['id'], $p, $n, EXAM_ROLES[0], '', $by) + ($sample ? ['sample' => true] : []);
                $have[] = strtolower($n);
            }
        }
        return $d;
    });
    return [$added, $updated, $problems];
}

// The schedule in the upload's own format, invigilators named.
function exam_export_rows(array $data, array $exams): array {
    $out = [EXAM_COLUMNS];
    foreach ($exams as $id => $x) {
        $out[] = [$x['date'], $x['start'], $x['end'], $x['report'], SCHOOLS[$x['school']]['name'] ?? $x['school'], $x['class'], $x['subject'],
            $x['room'], (string) $x['need'], implode('; ', array_column(exam_duties_of($data, $id), 'name'))];
    }
    return $out;
}

// --- The report ----------------------------------------------------------------

// Every duty whose exam falls in the date range, starts inside the time
// window and is in the school, joined to its exam, in exam order.
function exam_report_rows(array $data, string $school, string $from, string $to, string $tFrom, string $tTo): array {
    $rows = [];
    foreach (exam_list($data, $school, $from, $to) as $id => $x) {
        if ($tFrom !== '' && exam_min($x['start']) < exam_min($tFrom)) continue;
        if ($tTo !== '' && exam_min($x['start']) > exam_min($tTo)) continue;
        foreach (exam_duties_of($data, $id) as $did => $d) $rows[] = ['id' => $did, 'duty' => $d, 'exam' => $x];
    }
    return $rows;
}

// One line per person over those rows: duties, on time, late, absent, unmarked.
function exam_report_people(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $k = $r['duty']['person'] !== '' ? $r['duty']['person'] : 'name:' . strtolower($r['duty']['name']);
        $out[$k] ??= ['name' => $r['duty']['name'], 'school' => $r['exam']['school'], 'duties' => 0, 'ontime' => 0, 'late' => 0, 'absent' => 0, 'allocated' => 0, 'na' => 0];
        $out[$k]['duties']++;
        $out[$k][$r['duty']['status']]++;
    }
    uasort($out, fn($a, $b) => [-$a['absent'], -$a['late'], timetable_person($a['name'])] <=> [-$b['absent'], -$b['late'], timetable_person($b['name'])]);
    return $out;
}

function exam_counts(array $rows): array {
    $n = ['duties' => count($rows), 'ontime' => 0, 'late' => 0, 'absent' => 0, 'allocated' => 0, 'na' => 0];
    foreach ($rows as $r) $n[$r['duty']['status']]++;
    return $n;
}

// --- Sample schedule -------------------------------------------------------------

// A week of papers from subjects the timetable already lists: up to six
// classes a school, one subject each, Monday to Saturday of this week, 10 to 1
// and 2 to 5, in a room that class is taught in. Invigilators are allocated
// the normal way, and papers already over are marked so the report has
// something to show. Everything carries 'sample' so it can be cleared.
function exam_sample(array $t, array $faculty, string $by): int {
    require_once __DIR__ . '/audit.php';
    $roomOf = [];
    foreach ($t['rooms'] ?? [] as $room => $entries) {
        foreach ($entries as $e) if ($e['blocked'] === '' && empty($e['lab'])) foreach ($e['classes'] ?? [$e['class']] as $c) $roomOf[$c] ??= (string) $room;
    }
    $bySchool = [];
    foreach (audit_courses($t, $faculty) as $c) {
        if ($c['school'] === '' || !$c['theory'] || isset($bySchool[$c['school']][$c['class']]) || count($bySchool[$c['school']] ?? []) >= 6) continue;
        $bySchool[$c['school']][$c['class']] = $c;
    }
    // Next week, so every sample exam is still ahead and can be marked.
    $monday = new DateTimeImmutable('monday next week');
    $sessions = [['10:00', '13:00'], ['14:00', '17:00']];
    $ids = [];
    $n = 0;
    exam_save(function (array $d) use ($bySchool, $monday, $sessions, $roomOf, $by, &$ids) {
        foreach ($bySchool as $school => $courses) {
            $slot = 0;
            foreach ($courses as $c) {
                $date = $monday->modify('+' . intdiv($slot, 2) % 6 . ' days')->format('Y-m-d');
                [$start, $end] = $sessions[$slot % 2];
                $room = $roomOf[$c['class'] . ($c['divs'] ? ' Div ' . $c['divs'][0] : '')] ?? $roomOf[$c['class']] ?? '';
                [$x] = exam_make(['date' => $date, 'start' => $start, 'end' => $end, 'school' => $school, 'class' => $c['class'],
                    'subject' => $c['subject'], 'room' => $room, 'need' => 2, 'sample' => true]);
                if ($x && !isset($d['exams'][$x['id']])) {
                    $d['exams'][$x['id']] = $x + ['by' => $by, 'added' => date('Y-m-d H:i')];
                    $ids[] = $x['id'];
                }
                $slot++;
            }
        }
        return $d;
    });
    exam_auto_allocate($t, $faculty, $ids, $by);
    // Mark the papers already over: most on time, some late, a few absent,
    // the same way every time (by a hash of the duty).
    $now = date('Y-m-d H:i');
    exam_save(function (array $d) use ($ids, $now, &$n) {
        foreach ($d['duties'] as $id => &$duty) {
            $x = $d['exams'][$duty['exam']] ?? null;
            if (!$x || !in_array($x['id'], $ids, true) || "{$x['date']} {$x['start']}" > $now) continue;
            $h = crc32($id) % 100;
            $r = exam_min($x['report']);
            [$duty['status'], $duty['arrived']] = $h < 75 ? ['ontime', exam_hhmm($r - 5 - $h % 15)] : ($h < 90 ? ['late', exam_hhmm($r + 5 + $h % 25)] : ['absent', '']);
            if ($duty['status'] === 'absent') $duty['remark'] = 'Sample: did not report';
            $duty['marked_by'] = 'sample';
            $duty['marked_at'] = $now;
        }
        unset($duty);
        $n = count($ids);
        return $d;
    });
    return $n;
}

function exam_clear_sample(): void {
    exam_save(function (array $d) {
        $gone = array_keys(array_filter($d['exams'], fn($x) => !empty($x['sample'])));
        foreach ($gone as $id) unset($d['exams'][$id]);
        $d['duties'] = array_filter($d['duties'], fn($x) => !in_array($x['exam'], $gone, true));
        return $d;
    });
}
