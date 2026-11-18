<?php
// The room timetable and includes/structure.php as the three files Haazir's
// admin Import takes, in this order: classes (schools, branches, divisions),
// subjects, class_timetable (a subject taught to a division at set hours).
// Pure functions; tools/timetable/export-haazir.php writes the files and
// tests/test_export_haazir.php checks that every booking lands exactly once.
//
// Haazir labels a division BRANCH-DIVISION ("CSE-A", "BBA-A"), unique per
// year, so every branch needs a short code unique across the university:
// HAAZIR_BRANCHES. A branch with no code here is reported, never guessed.

require_once __DIR__ . '/../../includes/timetable.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/structure.php';

const HAAZIR_BRANCHES = [
    'eng' => [
        'Core' => 'CORE', 'CS' => 'CS', 'M. Tech' => 'MTECH', 'Biomedical' => 'BME', 'AIDS' => 'AIDS',
        'CSE' => 'CSE', 'SE' => 'SE', 'Biotechnology' => 'BT', 'Mechanical' => 'ME', 'Robotics' => 'ROBO',
        'Civil Engineering' => 'CIVIL', 'ECE' => 'ECE',
    ],
    'design' => [
        'B Des Fashion Design' => 'FD', 'B Des Interior Design' => 'ID', 'B Des Product Design' => 'PD',
        'B Des Transportation Design' => 'TD', 'B Des Visual Communication Design' => 'VCD',
        'B Des UI/UX' => 'UIUX', 'M Des UI/UX' => 'MDESUIUX', 'M Des Transportation' => 'MDESTD', 'PGDM' => 'PGDM',
    ],
    'hosp' => ['BSc Hospitality and Hotel Administration' => 'BSCHHA', 'MSc Hospitality and Hotel Administration' => 'MSCHHA'],
    'science' => ['B.Sc.' => 'BSC'],
    'mgmt' => ['MBA' => 'MBA', 'BBA' => 'BBA'],
    'law' => [
        'BA LLB' => 'BALLB', 'BBA LLB' => 'BBALLB', 'LLB' => 'LLB', 'LLM Corporate & Commercial Law' => 'LLMCCL',
        'LLM Constitutional Law' => 'LLMCONST', 'LLM Criminal Law' => 'LLMCRIM',
    ],
    'lib' => [
        'BA' => 'BA', 'MA Psychology (Industrial-Organizational)' => 'MAPSYIO', 'MA Psychology (Counselling)' => 'MAPSYC',
        'MA Politics & International Relations' => 'MAPIR',
    ],
];

const HAAZIR_DAYS = ['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu',
    'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'];

function haazir_year(string $year): int {
    return (int) $year;
}

/** "CSE-A": what Haazir calls a structure row (private.group_label). */
function haazir_label(array $row): string {
    return HAAZIR_BRANCHES[$row['school']][$row['branch']] . '-' . strtoupper(trim($row['division']));
}

/**
 * The classes file: one row per division of every school whose branches all
 * have a code. A school that has none listed (Architecture, Film: no
 * programmes known yet) is left out and named in $skipped.
 */
function haazir_classes(array $rows, array &$skipped = []): array {
    $out = [];
    foreach ($rows as $r) {
        $code = HAAZIR_BRANCHES[$r['school']][$r['branch']] ?? null;
        if ($code === null) {
            $skipped[$r['school']][$r['branch']] = true;
            continue;
        }
        $out[] = [
            'school_code' => strtoupper($r['school']),
            'school_name' => SCHOOLS[$r['school']]['name'] ?? $r['school'],
            'branch_code' => $code,
            'branch_name' => $r['branch'],
            'year' => haazir_year($r['year']),
            'division' => $r['division'],
        ];
    }
    return $out;
}

/** Structure rows a timetable class label teaches: the attendance mapping, plus Hospitality's. */
function haazir_class_rows(string $label, array $rows): array {
    $found = timetable_attendance_classes($label, $rows);
    if ($found) return $found;
    $roman = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8];
    // "B.Sc HHA Sem III Culinary": a specialisation is a group of its year, which the structure keeps as one division.
    if (preg_match('/^([BM])\.Sc HHA Sem ([IVX]+)\b/', $label, $m) && isset($roman[$m[2]])) {
        $branch = "{$m[1]}Sc Hospitality and Hotel Administration";
        $year = intdiv($roman[$m[2]] + 1, 2);
        return array_values(array_filter($rows, fn($r) => $r['school'] === 'hosp' && $r['branch'] === $branch
            && haazir_year($r['year']) === $year));
    }
    return [];
}

/** Lower case, single spaces: one subject however a sheet capitalises it. */
function haazir_subject_key(string $name): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

/**
 * A short code for a subject name, Haazir's subject code (1 to 30 of A-Z 0-9
 * space . _ / -): a short name as it is ("CAD/CAM", "ECA"), a long one by its
 * initials, numbering and Lab kept ("Applied Mathematics I" -> "AM I",
 * "Computer Programming Laboratory I Lab" -> "CPL I LAB"). $taken holds the
 * codes already given; a clash gets "-2", "-3".
 */
function haazir_subject_code(string $name, array $taken): string {
    // A bracket is a note ("(801 or 809)", "(Food Lab or online)"), not the subject.
    $bare = trim(preg_replace('/\([^)]*\)/', ' ', $name)) ?: $name;
    $plain = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 .\/_-]+/', ' ', $bare)));
    if ($plain !== '' && strlen($plain) <= 12) {
        $code = strtoupper($plain);
    } else {
        $stop = ['OF', 'AND', 'THE', 'TO', 'IN', 'FOR', 'WITH', 'ON', 'A', 'AN', 'USING'];
        $head = [];      // initials run together, an acronym on its own: "IA PLC SCADA"
        $tail = [];      // numbering and Lab: "I LAB"
        $run = false;    // the last head token is a run of initials
        foreach (preg_split('/[\s\/_.-]+/', $plain) as $w) {
            $upper = strtoupper($w);
            if ($w === '' || in_array($upper, $stop, true)) continue;
            if (preg_match('/^(I|II|III|IV|V|VI|VII|VIII|IX|X|\d+)$/', $w)) {
                $tail[] = $w;
            } elseif ($upper === 'LAB') {
                $tail[] = 'LAB';
            } elseif (preg_match('/^[A-Z][A-Z0-9]{1,5}$/', $w)) {
                $head[] = $w;
                $run = false;
            } elseif ($run) {
                $head[count($head) - 1] .= $upper[0];
            } else {
                $head[] = $upper[0];
                $run = true;
            }
        }
        $code = trim(implode(' ', $head) . ' ' . implode(' ', $tail));
        if (!preg_match('/^[A-Z0-9]/', $code)) $code = 'S' . $code;
        $code = rtrim(substr($code, 0, 27));
    }
    $base = $code;
    for ($n = 2; in_array($code, $taken, true); $n++) $code = substr($base, 0, 27) . "-$n";
    return $code;
}

/**
 * Subject codes for every name, keeping the codes $known gave before (the
 * export's subject-codes.json) so a code never moves once Haazir has it.
 * Returns [key => [code, name]], name being the spelling met most often.
 */
function haazir_subjects(array $names, array $known = []): array {
    $count = [];
    foreach ($names as $n) {
        $spelling = trim(preg_replace('/\s+/', ' ', $n));
        $count[haazir_subject_key($n)][$spelling] = ($count[haazir_subject_key($n)][$spelling] ?? 0) + 1;
    }
    ksort($count);
    $out = [];
    $taken = array_values($known);
    foreach ($count as $key => $spellings) {
        arsort($spellings);
        $name = substr((string) array_key_first($spellings), 0, 150);
        if (isset($known[$key])) {
            $code = $known[$key];
        } else {
            $code = haazir_subject_code($name, $taken);
            $taken[] = $code;
        }
        $out[$key] = [$code, $name];
    }
    return $out;
}

/** "13:30" from minutes after midnight. */
function haazir_time(int $t): string {
    return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
}

/** The room as people say it: "ULC 1 610", "School of Design 201". */
function haazir_room(array $t, string $room): string {
    foreach ($t['buildings'] ?? [] as $building => $rooms) {
        if (in_array($room, $rooms, true)) return substr($building . ' ' . timetable_room_label($room), 0, 50);
    }
    return substr(timetable_room_label($room), 0, 50);
}

/**
 * Every weekly booking (rooms and the roomless extra list) as one lecture per
 * division it teaches. Bookings that hold a room for another school
 * ("blocked") are not lectures. Returns [lectures, unmatched], unmatched
 * counting the bookings per class label that no structure class answers to.
 * Design's dated calendars are not weekly and are not read here.
 */
function haazir_lectures(array $t, array $rows): array {
    $lectures = [];
    $unmatched = [];
    $add = function (array $e, string $room) use (&$lectures, &$unmatched, $rows) {
        $m = timetable_minutes($e['time']);
        $end = timetable_end($e);
        $labels = $e['classes'] ?: [$e['class']];
        foreach ($labels as $label) {
            $found = haazir_class_rows($label, $rows);
            if (!$found || !$m || $end === null || !isset(HAAZIR_DAYS[$e['day']])) {
                $unmatched[$label] = ($unmatched[$label] ?? 0) + 1;
                continue;
            }
            foreach ($found as $r) {
                if (!isset(HAAZIR_BRANCHES[$r['school']][$r['branch']])) {
                    $unmatched[$label] = ($unmatched[$label] ?? 0) + 1;
                    continue;
                }
                $lectures[] = [
                    'year' => haazir_year($r['year']),
                    'group' => haazir_label($r),
                    'day' => HAAZIR_DAYS[$e['day']],
                    'start' => $m[0],
                    'end' => $end,
                    'subject' => $e['code'],
                    'room' => $room,
                    'faculty' => $e['faculty'] ?? '',
                ];
            }
        }
    };
    foreach ($t['rooms'] as $room => $entries) {
        foreach ($entries as $e) if (($e['blocked'] ?? '') === '') $add($e, haazir_room($t, $room));
    }
    foreach ($t['extra'] ?? [] as $e) $add($e + ['classes' => [], 'blocked' => ''], '');
    return [$lectures, $unmatched];
}

/** People named in a faculty cell ("A / B"), by timetable_person() key; seats like "Industry faculty" dropped. */
function haazir_people(string $faculty): array {
    $out = [];
    foreach (preg_split('#\s*/\s*#', $faculty) as $f) {
        $p = timetable_person($f);
        if (preg_match('/[a-z]/', $p) && !preg_match('/\bfaculty\b/', $p)) $out[$p] = trim(preg_replace('/\s+/', ' ', $f));
    }
    return $out;
}

/**
 * The class_timetable file. One row per division, subject, weekday and start:
 * a lecture written twice (two rooms for one lab, two cells of one sheet) is
 * one row with both rooms and every teacher. $emails maps a person key to
 * their college email; anyone without one is left out of professor_email and
 * collected in $noEmail (key => spelling).
 */
function haazir_timetable(array $lectures, array $subjects, array $emails, array &$noEmail = []): array {
    $merged = [];
    foreach ($lectures as $l) {
        $code = $subjects[haazir_subject_key($l['subject'])][0];
        $k = "{$l['year']}|{$l['group']}|$code|{$l['day']}|{$l['start']}";
        $row = $merged[$k] ?? ['subject_code' => $code, 'year' => $l['year'], 'group' => $l['group'],
            'weekday' => $l['day'], 'start' => $l['start'], 'end' => $l['end'], 'rooms' => [], 'emails' => []];
        $row['end'] = max($row['end'], $l['end']);
        if ($l['room'] !== '') $row['rooms'][$l['room']] = true;
        foreach (haazir_people($l['faculty']) as $key => $spelling) {
            if (isset($emails[$key])) $row['emails'][strtolower($emails[$key])] = true;
            else $noEmail[$key] = $spelling;
        }
        $merged[$k] = $row;
    }
    $out = [];
    foreach ($merged as $row) {
        $out[] = [
            'subject_code' => $row['subject_code'],
            'year' => $row['year'],
            'group' => $row['group'],
            'weekday' => $row['weekday'],
            'start' => haazir_time($row['start']),
            'end' => haazir_time($row['end']),
            'room' => substr(implode(' / ', array_keys($row['rooms'])), 0, 50),
            'professor_email' => implode(';', array_keys($row['emails'])),
            'term' => '',
        ];
    }
    usort($out, fn($a, $b) => [$a['year'], $a['group'], array_search($a['weekday'], array_values(HAAZIR_DAYS)), $a['start']]
        <=> [$b['year'], $b['group'], array_search($b['weekday'], array_values(HAAZIR_DAYS)), $b['start']]);
    return $out;
}
