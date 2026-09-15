<?php
// The partner divisions request. The partnerships office's Partners Information
// workbook (a tab per partner: faculty, rooms, programs, student counts) is read
// for the one thing the dashboard needs, which programs and years each partner
// has students in, and re-issued as a single sheet asking which ADYPU division
// those students sit in and how many. Nothing else in that workbook is asked
// for: one person has to fill this in, fast.
//
//   php tools/data-request.php partner-divisions "Partners Information.xlsx" out.xlsx

require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/../includes/attendance.php';

function pd_key(string $s): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
}

// Excel keeps 4 as "4.0".
function pd_value(string $v): string {
    return is_numeric($v) && (float)$v == floor((float)$v) ? sprintf('%.0f', (float)$v) : $v;
}

// Whether a program has students in a given year in 2026-27. Year 1 joined in
// 2026-27 (column W), year 2 in 2025-26, back to year 4 in 2023-24. An intake of
// 0, "-" or "NA" means that cohort never existed, and so does a blank beside a
// filled total, which was added up without it. A fifth year has no intake
// column, so it exists if the fourth does.
function pd_cohort(array $program, int $year): bool {
    $c = 23 - $year;
    if ($year < 1) return true;
    if ($c < 19) return pd_cohort($program, 4);
    $v = $program[$c] ?? '';
    if ($v === '') return ($program[23] ?? '') === '';
    return is_numeric($v) && $v > 0;
}

// A partner tab's program-years with students, as [program, year, count]. In
// the template (0-based columns) programs sit in R-X under an S. No in Q, and
// student counts in AB-AD under an S. No in AA, each table ending at "Total".
function pd_students(array $g): array {
    $cell = fn(int $r, int $c): string => $g[$r][$c] ?? '';
    $last = $g ? max(array_keys($g)) : 0;

    $programs = [];
    for ($r = 4; $r <= $last && !str_starts_with($cell($r, 16), 'Total'); $r++) {
        if ($cell($r, 17) === '') continue;
        $p = [];
        foreach (range(17, 23) as $c) $p[$c] = pd_value($cell($r, $c));
        $programs[pd_key($p[17])] ??= $p;
    }

    // A partner writes a program's name once and leaves the following years'
    // names blank, so a blank name carries the one above. A row holding only
    // the template's pre-typed year number is not a row.
    $rows = $have = [];
    $name = '';
    for ($r = 4; $r <= $last && !str_starts_with($cell($r, 26), 'Total'); $r++) {
        if ($cell($r, 27) === '' && $cell($r, 29) === '') continue;
        $name = $cell($r, 27) !== '' ? $cell($r, 27) : $name;
        $rows[] = [$name, pd_value($cell($r, 28)), pd_value($cell($r, 29))];
        $have[pd_key($name) . '|' . pd_value($cell($r, 28))] = true;
    }
    // A year a listed program has students in but nobody wrote a row for gets
    // one, so it is asked about instead of silently missing.
    foreach ($programs as $key => $p) {
        for ($y = 1; $y <= ((int)$p[18] ?: 4); $y++) {
            if (!isset($have["$key|$y"]) && pd_cohort($p, $y)) $rows[] = [$p[17], (string)$y, ''];
        }
    }
    // A year reported as 0 students has no division to name.
    return array_values(array_filter($rows, fn($row) => $row[2] !== '0'));
}

function pd_workbook(array $book): array {
    // A partner with exactly one school on the dashboard gets it filled in.
    $schools = [];
    foreach (KNOWLEDGE_PARTNERS as $p) {
        if (count($p['schools']) === 1) $schools[pd_key($p['name'])] = SCHOOLS[$p['schools'][0]]['name'];
    }

    $owe = ['y', ''];
    $rows = [
        [['b', 'PARTNER STUDENTS PER DIVISION']],
        [],
        ['Please fill in the yellow cells.'],
        ['Division = class name (e.g. A). Enrolled = how many students are in it.'],
        ['Students split across divisions? Copy the row, one per division.'],
        ['Add rows that are missing, delete rows that are wrong.'],
        [],
        // No branch column: the program is the branch, and no partner program
        // exists as a branch in structure.php for one to be chosen from.
        array_map(fn($h) => ['b', $h], ['Partner', 'Program', 'Year', 'Students (partner total)', 'School',
                                        'Division', 'ENROLLED IN DIVISION']),
    ];
    foreach ($book as $tab => $g) {
        // Partner tabs carry the template's faculty header; the contacts tab does not.
        if (($g[3][1] ?? '') !== 'Name of Faculty') continue;
        foreach (pd_students($g) ?: array_fill(0, 3, ['', '', '']) as [$program, $year, $count]) {
            $rows[] = [$tab, $program !== '' ? $program : $owe, $year !== '' ? $year : $owe, $count,
                       $schools[pd_key($tab)] ?? $owe, $owe, $owe];
        }
    }
    return ['Divisions' => ['cols' => [12, 44, 6, 22, 24, 12, 22], 'rows' => $rows]];
}
