<?php
// The canonical class list: every School -> Year -> Branch -> Division that
// exists, and its strength. Two things depend on it (SPEC.md §7.1):
//
//   1. Strength. Faculty submit only a present count, so the denominator lives
//      here and can't drift from a daily typo on the Form.
//   2. The Form's Class dropdowns. Google Forms has no dependent dropdowns, so
//      a "School" question jumps to one of the sections in form_sections(),
//      each listing only its own classes as labels built by class_label();
//      parse_class_label() splits one back into its four parts.
//      Regenerate the option lists with: php tools/form-options.php
//
// Engineering's structure is real — read from automatic-timetable-generator's
// timetable_db.classes (AY 2026-27) on 26 Aug 2026. Every strength below is
// that DB's scheduling default (60) or a placeholder, NOT a real headcount.
// Replacing these with roll counts from each school office is the one job that
// makes the dashboard's percentages true.
//
// Schools with no confirmed branch data use the single branch key ''.

require_once __DIR__ . '/attendance.php';

// Separator for the flat Class label. Chosen because no school name, year,
// branch or division contains it — parse_class_label() splits on it.
const CLASS_SEP = ' / ';

// school => year => branch => [division => strength]
function class_structure(): array {
    return [
        'eng' => [
            '1st Year' => [
                'Core' => ['A' => 60, 'B' => 60, 'C' => 60, 'D' => 60, 'E' => 60, 'F' => 60],
                'CS'   => ['M' => 60, 'N' => 60, 'O' => 60, 'P' => 60, 'S' => 60,
                           'T' => 60, 'U' => 60, 'V' => 60, 'W' => 60, 'X' => 60],
            ],
            '2nd Year' => [
                'AIDS' => ['A' => 60, 'B' => 60, 'C' => 60, 'D' => 60],
                'CSE'  => ['A' => 60, 'B' => 60, 'C' => 60, 'D' => 60, 'E' => 60],
                'ECE'  => ['A' => 60],
            ],
            '3rd Year' => [
                'AIDS' => ['A' => 60, 'B' => 60],
                'CS'   => ['A' => 60],
                'SE'   => ['A' => 60, 'B' => 60],
            ],
            '4th Year' => [
                'AIDS' => ['A' => 60, 'B' => 60],
                'CS'   => ['A' => 60],
                'SE'   => ['A' => 60, 'B' => 60, 'C' => 60],
            ],
        ],
    ] + placeholder_schools();
}

// Every school except Engineering, until someone confirms their real year /
// branch / division structure. Two divisions per year, no branches.
function placeholder_schools(): array {
    $yearCounts = [
        'mgmt' => 4, 'law' => 5, 'design' => 4,
        'science' => 4, 'arch' => 4, 'hosp' => 4, 'lib' => 4, 'film' => 4,
    ];
    $ordinals = ['1st', '2nd', '3rd', '4th', '5th'];
    $out = [];
    foreach ($yearCounts as $school => $count) {
        for ($y = 0; $y < $count; $y++) {
            $out[$school][$ordinals[$y] . ' Year'][''] = ['A' => 30, 'B' => 60];
        }
    }
    return $out;
}

// Flattens class_structure() to one row per division.
function class_rows(): array {
    $rows = [];
    foreach (class_structure() as $school => $years) {
        foreach ($years as $year => $branches) {
            foreach ($branches as $branch => $divisions) {
                foreach ($divisions as $division => $strength) {
                    $rows[] = compact('school', 'year', 'branch', 'division', 'strength');
                }
            }
        }
    }
    return $rows;
}

// "School of Engineering / 2nd Year / CSE / A", or without the branch segment
// for a school that has none. This exact string is what the Form stores.
function class_label(string $school, string $year, string $branch, string $division): string {
    $name = SCHOOLS[$school]['name'] ?? $school;
    $parts = $branch === ''
        ? [$name, $year, $division]
        : [$name, $year, $branch, $division];
    return implode(CLASS_SEP, $parts);
}

// Inverse of class_label(). Returns null for a label that doesn't name a real
// class — an edited Form option, or a division that's since been removed.
function parse_class_label(string $label): ?array {
    $parts = array_map('trim', explode(CLASS_SEP, $label));
    if (count($parts) === 3) {
        [$name, $year, $division] = $parts;
        $branch = '';
    } elseif (count($parts) === 4) {
        [$name, $year, $branch, $division] = $parts;
    } else {
        return null;
    }

    $school = school_id_for_name($name);
    if ($school === null) return null;

    $strength = class_strength($school, $year, $branch, $division);
    if ($strength === null) return null;

    return compact('school', 'year', 'branch', 'division', 'strength');
}

// Accepts the display name ("School of Engineering") or the id ("eng"), so a
// hand-typed sheet row works as well as a Form submission.
function school_id_for_name(string $name): ?string {
    if (isset(SCHOOLS[$name])) return $name;
    foreach (SCHOOLS as $id => $school) {
        if (strcasecmp($school['name'], $name) === 0) return $id;
    }
    return null;
}

function class_strength(string $school, string $year, string $branch, string $division): ?int {
    return class_structure()[$school][$year][$branch][$division] ?? null;
}

// The Form's sections. Google Forms has no dependent dropdowns, so one "School"
// question jumps to a section holding only that school's classes. Engineering
// carries 37 of the 103 classes and is the only school with branches, so it
// splits one level further, by year — that keeps every list under ~16 options
// without the 44 sections a full school>year>branch>division chain would need.
//
// Returns [section title => [class label, ...]], in the order to build them.
function form_sections(): array {
    $out = [];
    foreach (class_structure() as $school => $years) {
        $name = SCHOOLS[$school]['name'] ?? $school;
        $hasBranches = false;
        foreach ($years as $branches) {
            if (array_keys($branches) !== ['']) { $hasBranches = true; break; }
        }

        if (!$hasBranches) {
            $labels = [];
            foreach ($years as $year => $branches) {
                foreach ($branches as $branch => $divisions) {
                    foreach (array_keys($divisions) as $division) {
                        $labels[] = class_label($school, $year, $branch, $division);
                    }
                }
            }
            $out[$name] = $labels;
            continue;
        }

        foreach ($years as $year => $branches) {
            $labels = [];
            foreach ($branches as $branch => $divisions) {
                foreach (array_keys($divisions) as $division) {
                    $labels[] = class_label($school, $year, $branch, $division);
                }
            }
            $out["$name \u{2014} $year"] = $labels;
        }
    }
    return $out;
}
