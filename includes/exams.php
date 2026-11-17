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

const EXAM_STATUS_LABEL = ['allocated' => 'Not marked', 'ontime' => 'On time', 'late' => 'Late', 'absent' => 'Absent'];
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
    return ['exams' => $d['exams'] ?? [], 'duties' => $d['duties'] ?? [], 'mail' => $d['mail'] ?? []];
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
            if (!$x) continue;
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

// Attendance for one exam, from its marking form: per duty a status, an
// arrival time and a remark. "On time" with an arrival after the reporting
// time is Late; so is a time alone. A duty that turns late queues its email.
function exam_mark(string $examId, array $status, array $arrived, array $remark, array $emails, string $by): int {
    $queued = 0;
    exam_save(function (array $d) use ($examId, $status, $arrived, $remark, $emails, $by, &$queued) {
        $x = $d['exams'][$examId] ?? null;
        if (!$x) return $d;
        foreach ($d['duties'] as $id => &$duty) {
            if ($duty['exam'] !== $examId || !isset($status[$id])) continue;
            $s = isset(EXAM_STATUS_LABEL[$status[$id]]) ? (string) $status[$id] : 'allocated';
            $at = exam_parse_time((string) ($arrived[$id] ?? ''));
            if ($s === 'absent') $at = '';
            if ($at !== '' && in_array($s, ['allocated', 'ontime', 'late'], true)) $s = exam_min($at) > exam_min($x['report']) ? 'late' : 'ontime';
            $was = $duty['status'];
            $duty['status'] = $s;
            $duty['arrived'] = $at;
            $duty['remark'] = mb_substr(trim((string) ($remark[$id] ?? '')), 0, 500);
            $duty['marked_by'] = $by;
            $duty['marked_at'] = date('Y-m-d H:i');
            $to = $duty['email'] !== '' ? $duty['email'] : (string) ($emails[$duty['person']] ?? '');
            if ($s === 'late' && $was !== 'late' && $to !== '' && empty($duty['sample'])) {
                $d['mail'][$id] = exam_late_mail($to, $duty, $x);
                $queued++;
            }
        }
        unset($duty);
        return $d;
    });
    return $queued;
}

function exam_late_mail(string $to, array $duty, array $x): array {
    $day = date('l j F Y', strtotime($x['date']));
    $arrived = $duty['arrived'] !== '' ? ' You reported at ' . exam_clock($duty['arrived']) . '.' : '';
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
// are added. Returns [added, updated, problems].
function exam_import(array $rows, array $faculty, string $by): array {
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
    if ($head === null) return [0, 0, ['No header row with at least Date and Subject was found. Download the format to see the columns.']];
    $who = [];
    foreach ($faculty as $id => $f) {
        foreach ($f['as'] as $a) $who[$a] = $id;
        $who[timetable_person($f['name'])] ??= $id;
    }
    $added = $updated = 0;
    $problems = [];
    $made = [];
    foreach (array_slice($rows, $head + 1, null, true) as $i => $r) {
        if (!array_filter($r, fn($v) => trim($v) !== '')) continue;
        $in = [];
        foreach ($col as $k => $c) $in[$k] = (string) ($r[$c] ?? '');
        [$x, $why] = exam_make($in);
        if (!$x) { $problems[] = 'Row ' . ($i + 1) . ": needs $why."; continue; }
        $names = [];
        foreach (preg_split('/\s*[;,\n]\s*/', $in['names'] ?? '') as $n) {
            if (trim($n) === '') continue;
            $p = $who[timetable_person($n)] ?? '';
            if ($p === '') $problems[] = 'Row ' . ($i + 1) . ': "' . $n . '" is not a name on the timetable; added as written.';
            $names[] = [$p, $p !== '' ? $faculty[$p]['name'] : trim($n)];
        }
        $made[] = [$x, $names];
    }
    exam_save(function (array $d) use ($made, $by, &$added, &$updated) {
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
                $d['duties'][exam_new_id()] = exam_duty($x['id'], $p, $n, EXAM_ROLES[0], '', $by);
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
        $out[$k] ??= ['name' => $r['duty']['name'], 'school' => $r['exam']['school'], 'duties' => 0, 'ontime' => 0, 'late' => 0, 'absent' => 0, 'allocated' => 0];
        $out[$k]['duties']++;
        $out[$k][$r['duty']['status']]++;
    }
    uasort($out, fn($a, $b) => [-$a['absent'], -$a['late'], timetable_person($a['name'])] <=> [-$b['absent'], -$b['late'], timetable_person($b['name'])]);
    return $out;
}

function exam_counts(array $rows): array {
    $n = ['duties' => count($rows), 'ontime' => 0, 'late' => 0, 'absent' => 0, 'allocated' => 0];
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
    $monday = new DateTimeImmutable('monday this week');
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
