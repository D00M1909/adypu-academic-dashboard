<?php
// The academic audit: every faculty member's course file, one per subject they
// teach, as the 17 items of the Course File Index. Faculty upload each item,
// then submit once (a pop-up asks if they are sure), after which nothing of
// theirs can change. A program lead ticks each upload approved, writes a
// remark beside any that needs one, and can hand a file back for editing. A
// school dean sees what a lead sees, without changing it; the main dean sees
// every school, and sets the one deadline every view counts against.
//
// Who teaches what comes from the timetable (timetable_faculty()), so there is
// nothing for a faculty member to set up: an account is linked to its
// timetable name by an admin, or by the account's name when the two match.
//
// State lives in data/audit.php; each uploaded file in data/audit-files/ as its
// bytes behind the same guard line as every store file, so a browser asking
// for one gets a blank page and the only way to a file is audit-file.php,
// which checks who is asking. Item 10 is a student list.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/timetable.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/attendance.php';

const AUDIT_TERM = 'Odd term 2026-27';
const AUDIT_MAX_BYTES = 10 * 1024 * 1024;
const AUDIT_TYPES = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
];

// Level 2 is a program lead, who reviews one school's files; level 3 a school
// dean, who sees the same school read only; level 4 the main dean, who sees
// every school and sets the deadline. Admins see and do everything.
const AUDIT_ROLES = ['faculty' => 'Faculty', 'lead' => 'Program lead', 'school' => 'School dean', 'dean' => 'Main dean'];

// The Course File Index, in its own order. [title, required, kind]: a 'link'
// item takes a URL instead of a file. Which items are required is not decided
// yet; until it is, everything but the two "if any" plans is.
const AUDIT_ITEMS = [
    '1'   => ['Vision and Mission of Institute and Department', true],
    '2'   => ['Syllabus Structure and Course Syllabus', true],
    '3'   => ['Statements of COs, POs, PSOs, PEOs', true],
    '4'   => ['Mapping of COs with POs and PSOs with Justification', true],
    '5'   => ['Last Year CO-PO-PSO Attainment for subject', true],
    '6'   => ['Gap Identification based on CO-PO-PSO mapping and its planning for fulfillment', true],
    '7'   => ['Teaching Plan with ICT (Theory and Practical)', true],
    '8'   => ['Academic Calendar (University, School)', true],
    '9'   => ['Time Table (Class and Individual)', true],
    '10'  => ['Student database of respective class', true],
    '11'  => ['Last three years Subject Result analysis and Target set for current Sem.', true],
    '12A' => ['Unit wise Notes and PPTs', true],
    '12B' => ['Innovative Teaching Learning (Pedagogy) Report', true],
    '12C' => ['NPTEL videos / SWAYAM videos, MOOC etc.', true, 'link'],
    '12D' => ['Mid Term and End Term Question papers along with solution', true],
    '12E' => ['Question paper UT', true],
    '12F' => ['Unit wise question bank', true],
    '12G' => ['Printed lab manual with Oral question bank', true],
    '13'  => ['Attendance Record (ERP)', true],
    '14'  => ['Continuous Assessment Record for Internal Examination', true],
    '15'  => ['List of Slow and Advanced learners and action taken', true],
    '16'  => ['Industrial Visit Planning (If any)', false],
    '17'  => ['Guest Lecture Planning (If any)', false],
];
const AUDIT_GROUPS = ['12' => 'Unit wise Teaching - Learning Material'];

defined('AUDIT_BLOB_DIR') || define('AUDIT_BLOB_DIR', STORE_DIR . '/audit-files');

// --- Who sees what -----------------------------------------------------------

function audit_role(array $u): string {
    if (!empty($u['admin'])) return 'admin';
    return isset(AUDIT_ROLES[$u['role'] ?? '']) ? $u['role'] : 'faculty';
}

// The timetable person an account is: the one an admin linked, or else the one
// whose name reads the same once titles are dropped.
function audit_person_for(array $u, array $faculty): string {
    $tt = (string) ($u['tt'] ?? '');
    if ($tt !== '' && isset($faculty[$tt])) return $tt;
    $p = timetable_person((string) ($u['name'] ?? ''));
    if ($p === '') return '';
    foreach ($faculty as $id => $f) {
        if (in_array($p, $f['as'], true) || timetable_person($f['name']) === $p) return $id;
    }
    return '';
}

function audit_can_view(array $u, string $person, array $course): bool {
    return match (audit_role($u)) {
        'admin', 'dean' => true,
        'lead', 'school' => $course['school'] === ($u['school'] ?? '') || $course['person'] === $person,
        default => $course['person'] === $person,
    };
}

function audit_can_review(array $u, array $course): bool {
    $role = audit_role($u);
    return $role === 'admin' || ($role === 'lead' && $course['school'] === ($u['school'] ?? ''));
}

function audit_can_set_deadline(array $u): bool {
    return in_array(audit_role($u), ['dean', 'admin'], true);
}

// The names on accounts of one level in one school, for "Program lead: ..."
// on a faculty member's course file.
function audit_staff(array $users, string $school, string $role): array {
    $out = [];
    foreach ($users as $u) {
        if (($u['role'] ?? '') === $role && ($u['school'] ?? '') === $school && ($u['status'] ?? '') === 'active') $out[] = (string) ($u['name'] ?? '');
    }
    return array_values(array_filter($out));
}

// --- The deadline ------------------------------------------------------------

// One date for the whole term, 'Y-m-d', or '' while none is set. A file
// counts as on time when it was first submitted on or before that day: one a
// lead hands back and gets again later is still on time.
function audit_deadline(): string {
    return (string) (store_read('audit')['deadline'] ?? '');
}

function audit_set_deadline(string $date, string $email): string {
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date))) return 'Please pick a date.';
    store_update('audit', function (array $d) use ($date) {
        $d['deadline'] = $date;
        return $d;
    });
    activity_log('audit', $email, ['action' => 'deadline', 'label' => $date === '' ? 'Cleared the audit deadline' : 'Set the audit deadline to ' . date('j M Y', strtotime($date))]);
    return '';
}

const AUDIT_TIMING_LABEL = ['ontime' => 'Submitted on time', 'late' => 'Submitted late', 'pending' => 'Not submitted'];

function audit_timing(array $f, string $deadline): string {
    $first = $f['first'] !== '' ? $f['first'] : $f['submitted'];
    if ($first === '') return 'pending';
    return $deadline === '' || substr($first, 0, 10) <= $deadline ? 'ontime' : 'late';
}

// Days from $today to the deadline: negative once it has passed.
function audit_days_left(string $deadline, string $today): int {
    return (int) round((strtotime($deadline) - strtotime($today)) / 86400);
}

function audit_due_label(string $deadline, string $today): string {
    if ($deadline === '') return 'No deadline set';
    $n = audit_days_left($deadline, $today);
    return match (true) {
        $n > 1 => "$n days left",
        $n === 1 => '1 day left',
        $n === 0 => 'Due today',
        $n === -1 => 'Passed yesterday',
        default => 'Passed ' . -$n . ' days ago',
    };
}

// --- The courses, from the timetable -----------------------------------------

// "Sem VII" and "Third Year" both, from a class label: [year, sem], 0 if the
// label says neither. A label with only a year is in this term's odd semester.
function audit_year_sem(string $class): array {
    $roman = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];
    $sem = preg_match('/\bSem(?:ester)?\s+([IVX]+|\d+)\b/i', $class, $m) ? ($roman[strtoupper($m[1])] ?? (int) $m[1]) : 0;
    $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5];
    $year = preg_match('/\b(first|second|third|fourth|fifth|I|II|III|IV|V)\s+Year\b/i', $class, $m) ? $words[strtolower($m[1])] : 0;
    if ($sem && !$year) $year = intdiv($sem + 1, 2);
    if ($year && !$sem) $sem = 2 * $year - 1;
    return [$year, $sem];
}

// One course is one subject a person teaches to one programme and semester:
// its divisions share a course file ("Div A, Div B"), and its theory and lab
// are the same course.
function audit_class_group(string $class): array {
    $groups = [];
    $divs = [];
    foreach (explode(';', $class) as $c) {
        $c = trim($c);
        if (preg_match('/^(.*?)\s*\(?\b(Div(?:ision)?|Group)\s+([A-Z0-9]+)\)?$/i', $c, $m)) {
            $c = trim($m[1]);
            $divs[] = ucfirst(strtolower($m[2])) === 'Group' ? "Group {$m[3]}" : "Div {$m[3]}";
        }
        if ($c !== '') $groups[$c] = true;
    }
    return [implode('; ', array_keys($groups)), $divs];
}

function audit_course_id(string $person, string $subject, string $group): string {
    return substr(sha1("$person|" . strtolower($subject) . "|$group"), 0, 12);
}

// Every course in the timetable, id => person, subject, class, divisions,
// school, year, sem, lab/theory. One pass over the bookings, the dated
// calendars and the roomless classes, the same three timetable_faculty() reads.
function audit_courses(array $t, array $faculty): array {
    $who = [];
    foreach ($faculty as $id => $f) {
        foreach ($f['as'] as $a) $who[$a] = $id;
        $who[timetable_person($f['name'])] ??= $id;
    }
    $out = [];
    $add = function (string $names, string $subject, string $class, bool $lab) use ($who, &$out) {
        $subject = trim(preg_replace('/\s+/', ' ', $subject));
        // "Food Analysis Lab" is Food Analysis's practical, not a second course.
        if (preg_match('/^(.+?)\s*[-(]?\s*\b(Lab|Laboratory|Practicals?)\)?$/i', $subject, $m)) {
            $subject = $m[1];
            $lab = true;
        }
        if ($subject === '' || $class === '') return;
        [$group, $divs] = audit_class_group($class);
        foreach (preg_split('#\s*/\s*#', $names) as $n) {
            $person = $who[timetable_person(trim(preg_replace('/\s+/', ' ', $n)))] ?? '';
            if ($person === '') continue;
            $id = audit_course_id($person, $subject, $group);
            if (!isset($out[$id])) {
                [$year, $sem] = audit_year_sem($group);
                $out[$id] = ['id' => $id, 'person' => $person, 'subject' => $subject, 'class' => $group, 'divs' => [],
                    'school' => timetable_schools(['blocked' => '', 'class' => $group])[0] ?? '',
                    'year' => $year, 'sem' => $sem, 'theory' => false, 'lab' => false];
            }
            $out[$id]['divs'] = array_values(array_unique([...$out[$id]['divs'], ...$divs]));
            $out[$id][$lab ? 'lab' : 'theory'] = true;
        }
    };
    // A slot two classes share with a subject each ("Front Office I / Retail
    // Operations" for "B.Sc HHA Sem I; M.Sc HHA Sem III") is two courses.
    $pairs = function (string $subject, string $class): array {
        $s = preg_split('#\s+/\s+#', $subject);
        $c = array_map('trim', explode(';', $class));
        return count($s) > 1 && count($s) === count($c) ? array_map(null, $s, $c) : [[$subject, $class]];
    };
    foreach ($t['rooms'] ?? [] as $entries) {
        foreach ($entries as $e) {
            if ($e['blocked'] !== '') continue;
            foreach ($pairs($e['code'], $e['class']) as [$s, $c]) $add($e['faculty'], $s, $c, !empty($e['lab']));
        }
    }
    foreach ($t['calendars'] ?? [] as $c) {
        foreach ($c['subjects'] as [$subject, $f]) $add($f, $subject, $c['class'], false);
    }
    foreach ($t['extra'] ?? [] as $x) $add($x['faculty'], $x['code'], $x['class'], !empty($x['lab']));
    foreach ($out as &$c) natsort($c['divs']);
    unset($c);
    uasort($out, fn($a, $b) => [$a['year'], $a['class'], $a['subject']] <=> [$b['year'], $b['class'], $b['subject']]);
    return $out;
}

function audit_course_label(array $c): string {
    $parts = [$c['class']];
    if ($c['divs']) $parts[] = implode(', ', array_values($c['divs']));
    return implode(' · ', $parts);
}

function audit_term_label(array $c): string {
    $bits = [];
    if ($c['year']) $bits[] = 'Year ' . $c['year'];
    if ($c['sem']) $bits[] = 'Sem ' . $c['sem'];
    $bits[] = $c['theory'] && $c['lab'] ? 'Theory + Lab' : ($c['lab'] ? 'Lab' : 'Theory');
    return implode(' · ', $bits);
}

// --- The course files --------------------------------------------------------

function audit_all(): array {
    return store_read('audit')['files'] ?? [];
}

// A course's file as stored, with the parts it has not reached yet filled in.
function audit_file(array $all, string $id): array {
    return ($all[$id] ?? []) + ['items' => [], 'submitted' => '', 'first' => '', 'by' => '', 'reviewed' => '', 'reviewer' => '', 'reopened' => '', 'note' => ''];
}

// draft, submitted (waiting for a lead), approved (a lead reviewed it).
function audit_status(array $f): string {
    if ($f['submitted'] === '') return 'draft';
    return $f['reviewed'] !== '' ? 'approved' : 'submitted';
}

const AUDIT_STATUS_LABEL = ['draft' => 'Not submitted', 'submitted' => 'Waiting for review', 'approved' => 'Reviewed'];

function audit_filled(array $item): bool {
    return !empty($item['blob']) || !empty($item['link']);
}

// [filled required, required, filled in all]
function audit_progress(array $f): array {
    $req = $have = $any = 0;
    foreach (AUDIT_ITEMS as $k => [, $required]) {
        $ok = audit_filled($f['items'][$k] ?? []);
        if ($required) { $req++; if ($ok) $have++; }
        if ($ok) $any++;
    }
    return [$have, $req, $any];
}

function audit_missing(array $f): array {
    $out = [];
    foreach (AUDIT_ITEMS as $k => [$title, $required]) {
        if ($required && !audit_filled($f['items'][$k] ?? [])) $out[] = $k;
    }
    return $out;
}

// Writes one course's file under the store lock. The course's subject and class
// ride along so the summary can name a file the timetable no longer lists.
function audit_save(array $course, callable $mutate): bool {
    $ok = true;
    store_update('audit', function (array $d) use ($course, $mutate, &$ok) {
        $f = audit_file($d['files'] ?? [], $course['id']);
        $f = ['person' => $course['person'], 'subject' => $course['subject'], 'class' => $course['class'], 'school' => $course['school']] + $f;
        $next = $mutate($f);
        if ($next === null) { $ok = false; return $d; }
        $d['files'][$course['id']] = $next;
        return $d;
    });
    return $ok;
}

function audit_blob_path(string $blob): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $blob)) throw new InvalidArgumentException('bad blob id');
    return AUDIT_BLOB_DIR . "/$blob.php";
}

function audit_blob_read(string $blob): ?string {
    $raw = @file_get_contents(audit_blob_path($blob));
    if ($raw === false || !str_starts_with($raw, STORE_GUARD)) return null;
    return substr($raw, strlen(STORE_GUARD));
}

// Every change is refused once the file is submitted: that is the whole point
// of the typed confirmation, and the server is where it has to hold.
function audit_locked(array $f): bool {
    return $f['submitted'] !== '';
}

// $file is one entry of $_FILES. Returns '' or a message to show.
function audit_upload(array $course, string $item, array $file, string $email): string {
    if (!isset(AUDIT_ITEMS[$item]) || (AUDIT_ITEMS[$item][2] ?? '') === 'link') return 'That item does not take a file.';
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > AUDIT_MAX_BYTES) return 'That file is over 10 MB. Please make it smaller, or split it.';
    if ($err === UPLOAD_ERR_NO_FILE) return 'Please choose a file.';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) return 'The upload did not arrive. Please try again.';
    $name = trim(preg_replace('/[^\w .()&,-]+/u', '_', basename((string) $file['name']))) ?: 'file';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(AUDIT_TYPES[$ext])) return 'Please upload a PDF, Word, Excel, PowerPoint or image file.';

    $blob = bin2hex(random_bytes(16));
    if (!is_dir(AUDIT_BLOB_DIR)) @mkdir(AUDIT_BLOB_DIR, 0775, true);
    $bytes = (string) file_get_contents((string) $file['tmp_name']);
    if (@file_put_contents(audit_blob_path($blob), STORE_GUARD . $bytes) === false) return 'The file could not be saved. Please try again.';

    $old = '';
    $ok = audit_save($course, function (array $f) use ($item, $blob, $name, $ext, $bytes, &$old) {
        if (audit_locked($f)) return null;
        $old = (string) ($f['items'][$item]['blob'] ?? '');
        // A new file is a new thing to approve, and the remark was about the old one.
        $f['items'][$item] = ['blob' => $blob, 'name' => $name, 'ext' => $ext, 'size' => strlen($bytes), 'at' => date('Y-m-d H:i')];
        return $f;
    });
    if (!$ok) { @unlink(audit_blob_path($blob)); return 'This course file is submitted, so it cannot change.'; }
    if ($old !== '') @unlink(audit_blob_path($old));
    activity_log('audit', $email, ['action' => 'upload', 'target' => $course['id'], 'label' => "Uploaded item $item for {$course['subject']}"]);
    return '';
}

function audit_set_link(array $course, string $item, string $url, string $email): string {
    if ((AUDIT_ITEMS[$item][2] ?? '') !== 'link') return 'That item takes a file.';
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500) return 'Please paste a full link, starting with https://';
    $ok = audit_save($course, function (array $f) use ($item, $url) {
        if (audit_locked($f)) return null;
        $f['items'][$item] = ['link' => $url, 'at' => date('Y-m-d H:i')];
        return $f;
    });
    if (!$ok) return 'This course file is submitted, so it cannot change.';
    activity_log('audit', $email, ['action' => 'link', 'target' => $course['id'], 'label' => "Added a link for item $item of {$course['subject']}"]);
    return '';
}

function audit_remove(array $course, string $item, string $email): string {
    $old = '';
    $ok = audit_save($course, function (array $f) use ($item, &$old) {
        if (audit_locked($f)) return null;
        $old = (string) ($f['items'][$item]['blob'] ?? '');
        unset($f['items'][$item]);
        return $f;
    });
    if (!$ok) return 'This course file is submitted, so it cannot change.';
    if ($old !== '') @unlink(audit_blob_path($old));
    activity_log('audit', $email, ['action' => 'remove', 'target' => $course['id'], 'label' => "Removed item $item from {$course['subject']}"]);
    return '';
}

// $sure is the pop-up's Yes, so a stray post without it never locks a file.
function audit_submit(array $course, bool $sure, string $email): string {
    if (!$sure) return 'Please confirm the submission.';
    $why = '';
    audit_save($course, function (array $f) use (&$why, $email) {
        if (audit_locked($f)) { $why = 'This course file is already submitted.'; return null; }
        if ($m = audit_missing($f)) { $why = 'Still to upload: item ' . implode(', ', $m) . '.'; return null; }
        $f['submitted'] = date('Y-m-d H:i');
        if ($f['first'] === '') $f['first'] = $f['submitted'];
        $f['by'] = $email;
        $f['reviewed'] = '';
        return $f;
    });
    if ($why === '') activity_log('audit', $email, ['action' => 'submit', 'target' => $course['id'], 'label' => "Submitted the course file for {$course['subject']}"]);
    return $why;
}

// A lead's review: which items are approved and the remark beside each. Saving
// it is what marks the file reviewed, so the main dean's summary counts it.
function audit_review(array $course, array $approved, array $remarks, string $note, string $email): string {
    $why = '';
    audit_save($course, function (array $f) use ($approved, $remarks, $note, $email, &$why) {
        if (!audit_locked($f)) { $why = 'This course file is not submitted yet.'; return null; }
        foreach (AUDIT_ITEMS as $k => $_) {
            $f['items'][$k] ??= [];
            $f['items'][$k]['ok'] = in_array((string) $k, array_map('strval', $approved), true) && audit_filled($f['items'][$k]);
            $f['items'][$k]['remark'] = mb_substr(trim((string) ($remarks[$k] ?? '')), 0, 500);
            if ($f['items'][$k] === ['ok' => false, 'remark' => '']) unset($f['items'][$k]);
        }
        $f['note'] = mb_substr(trim($note), 0, 1000);
        $f['reviewed'] = date('Y-m-d H:i');
        $f['reviewer'] = $email;
        return $f;
    });
    if ($why === '') activity_log('audit', $email, ['action' => 'review', 'target' => $course['id'], 'label' => "Reviewed the course file for {$course['subject']}"]);
    return $why;
}

// Hands a submitted file back to its faculty member to change. Approvals and
// remarks stay, so the lead can see what was asked for; a replaced file clears
// its own tick (audit_upload()).
function audit_reopen(array $course, string $email): string {
    $why = '';
    audit_save($course, function (array $f) use (&$why) {
        if (!audit_locked($f)) { $why = 'This course file is already open for editing.'; return null; }
        $f['submitted'] = '';
        $f['reviewed'] = '';
        $f['reopened'] = date('Y-m-d H:i');
        return $f;
    });
    if ($why === '') activity_log('audit', $email, ['action' => 'reopen', 'target' => $course['id'], 'label' => "Opened the course file for {$course['subject']} for editing"]);
    return $why;
}

// --- The summary -------------------------------------------------------------

// One row per person: courses, how many submitted and reviewed, items approved
// out of items filled, and every remark a lead wrote, for the schools given.
function audit_summary(array $courses, array $files, array $faculty, array $schools, string $deadline = ''): array {
    $rows = [];
    foreach ($courses as $c) {
        if (!in_array($c['school'], $schools, true)) continue;
        $f = audit_file($files, $c['id']);
        $r = &$rows[$c['school']][$c['person']];
        $r ??= ['person' => $c['person'], 'name' => $faculty[$c['person']]['name'] ?? $c['person'], 'courses' => 0, 'started' => 0, 'submitted' => 0, 'reviewed' => 0,
            'ontime' => 0, 'late' => 0, 'pending' => 0, 'filled' => 0, 'approved' => 0, 'remarks' => [], 'last' => '', 'reviewers' => []];
        $r['courses']++;
        $r[audit_timing($f, $deadline)]++;
        [, , $any] = audit_progress($f);
        if ($any) $r['started']++;
        $s = audit_status($f);
        if ($s !== 'draft') $r['submitted']++;
        if ($s === 'approved') $r['reviewed']++;
        if ($f['submitted'] > $r['last']) $r['last'] = $f['submitted'];
        if ($s === 'approved' && $f['reviewer'] !== '') $r['reviewers'][$f['reviewer']] = true;
        foreach (AUDIT_ITEMS as $k => [$title]) {
            $it = $f['items'][$k] ?? [];
            if (audit_filled($it)) $r['filled']++;
            if (!empty($it['ok'])) $r['approved']++;
            if (($it['remark'] ?? '') !== '') $r['remarks'][] = ['course' => $c['id'], 'subject' => $c['subject'], 'item' => $k, 'title' => $title, 'text' => $it['remark']];
        }
        if ($f['note'] !== '') $r['remarks'][] = ['course' => $c['id'], 'subject' => $c['subject'], 'item' => '', 'title' => 'Overall', 'text' => $f['note']];
        unset($r);
    }
    foreach ($rows as &$people) uasort($people, fn($a, $b) => strnatcasecmp(timetable_person($a['name']), timetable_person($b['name'])));
    return $rows;
}

// Where a person stands against the deadline, for the summary's filter: not
// submitted while any file is still out, late if any came in after it.
function audit_person_timing(array $r): string {
    return $r['pending'] ? 'pending' : ($r['late'] ? 'late' : 'ontime');
}

// One row per course file, for the export and the insights: everything the
// summary counts, file by file.
function audit_rows(array $courses, array $files, array $faculty, array $schools, string $deadline): array {
    $rows = [];
    foreach ($courses as $c) {
        if (!in_array($c['school'], $schools, true)) continue;
        $f = audit_file($files, $c['id']);
        [$have, $req, $any] = audit_progress($f);
        $approved = $remarks = 0;
        foreach ($f['items'] as $it) {
            if (!empty($it['ok'])) $approved++;
            if (($it['remark'] ?? '') !== '') $remarks++;
        }
        $rows[] = ['course' => $c, 'file' => $f, 'faculty' => $faculty[$c['person']]['name'] ?? $c['person'],
            'status' => audit_status($f), 'timing' => audit_timing($f, $deadline),
            'have' => $have, 'req' => $req, 'any' => $any, 'approved' => $approved, 'remarks' => $remarks + ($f['note'] !== '' ? 1 : 0)];
    }
    return $rows;
}

// What the Insights draw, from audit_rows(): first submissions per day, and
// for each item how many of the started files have it.
function audit_insights(array $rows): array {
    $days = [];
    $items = array_fill_keys(array_keys(AUDIT_ITEMS), 0);
    $started = 0;
    foreach ($rows as $r) {
        $first = $r['file']['first'] !== '' ? $r['file']['first'] : $r['file']['submitted'];
        if ($first !== '') $days[substr($first, 0, 10)] = ($days[substr($first, 0, 10)] ?? 0) + 1;
        if (!$r['any']) continue;
        $started++;
        foreach (AUDIT_ITEMS as $k => $_) if (audit_filled($r['file']['items'][$k] ?? [])) $items[$k]++;
    }
    ksort($days);
    return ['days' => $days, 'items' => $items, 'started' => $started];
}
