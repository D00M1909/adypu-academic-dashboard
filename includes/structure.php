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
// Engineering, Design, Hospitality and Science are real, structure and strength
// both: the schools filled in the workbook tools/data-request.php generates and
// returned it on 4 Sep 2026 (02-structure-request-updated.xlsx). Their columns
// are read literally, so where a sheet put a stream name in the Division column
// that is what the division is called here.
//
// Still not real headcounts: Engineering 1st Year, and the five schools left in
// placeholder_schools(). Replacing those with roll counts from each school
// office is the one job left that makes the dashboard's percentages true.
//
// Schools with no confirmed branch data use the single branch key ''.

require_once __DIR__ . '/attendance.php';

// Separator for the flat Class label. Chosen because no school name, year,
// branch or division contains it — parse_class_label() splits on it.
const CLASS_SEP = ' / ';

// How many classes a school may hold before its Form section splits by year.
// Only Engineering (61) is over it; raising it past 61 would put every class in
// one dropdown, lowering it past 26 costs Design three more Form sections.
const SECTION_SPLIT_AT = 30;

// school => year => branch => [division => strength]
function class_structure(): array {
    return [
        // CSE (2nd Year) and SE (3rd/4th) are both Software Engineering; CS is
        // Cyber Security. Spelled as the timetable DB spells them, so no Form
        // option and no past submission is orphaned by this update.
        'eng' => [
            '1st Year' => [
                // Placeholders. Core (A-F) and CS (M-X) are the timetable DB's
                // two scheduling groups, not branches a student would name
                // (seed_fy_data.php seeds them as "FY A".."FY X"), and 60 is
                // that DB's default. The school listed no first-year B.Tech
                // division, so both stand until it does.
                'Core'       => ['A' => 60, 'B' => 60, 'C' => 60, 'D' => 60, 'E' => 60, 'F' => 60],
                'CS'         => ['M' => 60, 'N' => 60, 'O' => 60, 'P' => 60, 'S' => 60,
                                 'T' => 60, 'U' => 60, 'V' => 60, 'W' => 60, 'X' => 60],
                'M. Tech'    => ['Biotechnology' => 5, 'Bioinformatics' => 1],
                'Biomedical' => ['B' => 14, 'C' => 7, 'D' => 6, 'E' => 7], // no division A, per the sheet
            ],
            '2nd Year' => [
                'AIDS'              => ['A' => 73, 'B' => 67, 'C' => 66, 'D' => 67],
                'CSE'               => ['A' => 70, 'B' => 68, 'C' => 68, 'D' => 69, 'E' => 66],
                'Biotechnology'     => ['A' => 60], // the one count the sheet left at our default
                'Biomedical'        => ['A' => 14],
                'Mechanical'        => ['A' => 53],
                'Robotics'          => ['A' => 48],
                'Civil Engineering' => ['A' => 69], // written "69*" with no footnote anywhere
                'M. Tech'           => ['Biotechnology' => 5],
                // ECE division A was here until 4 Sep 2026; the school confirmed it closed.
            ],
            '3rd Year' => [
                'AIDS'              => ['A' => 60, 'B' => 57],
                'CS'                => ['A' => 25],
                'SE'                => ['A' => 68, 'B' => 68],
                'Biotechnology'     => ['Medical Biotechnology' => 10, 'Food Technology' => 4,
                                        'Bioinformatics' => 11],
                'Biomedical'        => ['A' => 8],
                'Mechanical'        => ['A' => 22],
                'Robotics'          => ['A' => 25],
                'Civil Engineering' => ['A' => 37],
            ],
            '4th Year' => [
                'AIDS'          => ['A' => 63, 'B' => 62],
                'CS'            => ['A' => 28],
                'SE'            => ['A' => 61, 'B' => 60, 'C' => 57],
                'Biotechnology' => ['Medical Biotechnology' => 8, 'Food Technology' => 4,
                                    'Bioinformatics' => 6],
                'Biomedical'    => ['A' => 18],
                'Mechanical'    => ['A' => 7],
                'Robotics'      => ['A' => 10],
            ],
        ],
        // A division labelled B with no A is the sheet's own; kept verbatim
        // rather than renamed, since the faculty pick the label they were given.
        'design' => [
            '1st Year' => [
                'B Des Fashion Design'              => ['A' => 4],
                'B Des Interior Design'             => ['A' => 14],
                'B Des Product Design'              => ['A' => 14],
                'B Des Transportation Design'       => ['A' => 1],
                'B Des Visual Communication Design' => ['A' => 8],
                'B Des UI/UX'                       => ['B' => 11],
                'M Des UI/UX'                       => ['B' => 3],
                'PGDM'                              => ['A' => 4],
            ],
            '2nd Year' => [
                'B Des Fashion Design'              => ['A' => 2],
                'B Des Interior Design'             => ['A' => 2],
                'B Des Product Design'              => ['A' => 7],
                'B Des Transportation Design'       => ['A' => 2],
                'B Des Visual Communication Design' => ['A' => 4],
                'B Des UI/UX'                       => ['A' => 9],
                'M Des Transportation'              => ['A' => 4],
                'M Des UI/UX'                       => ['B' => 3],
            ],
            '3rd Year' => [
                'B Des Fashion Design'              => ['A' => 1],
                'B Des Product Design'              => ['A' => 11],
                'B Des Transportation Design'       => ['A' => 5],
                'B Des Visual Communication Design' => ['A' => 9],
                'B Des UI/UX'                       => ['B' => 5],
            ],
            '4th Year' => [
                'B Des Fashion Design'              => ['A' => 1],
                'B Des Product Design'              => ['A' => 17],
                'B Des Transportation Design'       => ['A' => 4],
                'B Des Visual Communication Design' => ['A' => 20],
                'B Des UI/UX'                       => ['B' => 17],
            ],
        ],
        // Their sheet numbered the years I/II/III and misspelt "Administration"
        // in the MSc rows. Its own Grand Total of 30 agrees with these.
        'hosp' => [
            '1st Year' => [
                'BSc Hospitality and Hotel Administration' => ['A' => 11],
                'MSc Hospitality and Hotel Administration' => ['A' => 2],
            ],
            '2nd Year' => [
                'BSc Hospitality and Hotel Administration' => ['A' => 8],
                'MSc Hospitality and Hotel Administration' => ['A' => 1],
            ],
            '3rd Year' => [
                'BSc Hospitality and Hotel Administration' => ['A' => 8],
            ],
        ],
        // Two years is all they sent, so two years is all the dashboard claims.
        'science' => [
            '1st Year' => ['B.Sc.' => ['A' => 100]],
            '2nd Year' => ['B.Sc.' => ['A' => 30]],
        ],
    ] + placeholder_schools();
}

// The five schools that returned nothing usable, until one of them confirms its
// real year / branch / division structure. Two divisions per year, no branches.
// Management did send a structure (MBA 1-2, BBA 1-3) but no student numbers, so
// it stays here rather than draw a percentage over an invented denominator.
function placeholder_schools(): array {
    $yearCounts = ['mgmt' => 4, 'law' => 5, 'arch' => 4, 'lib' => 4, 'film' => 4];
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
// alone carries 61 of the 136 classes, too many for one dropdown, so it splits
// one level further, by year. Nothing else does: Design's 26 options are no
// worse than the 22 Engineering's own first year already asks a faculty member
// to scroll, and every extra section is one more Form page to build by hand and
// one more chance to mis-route the School question.
//
// Returns [section title => [class label, ...]], in the order to build them.
function form_sections(): array {
    $out = [];
    foreach (class_structure() as $school => $years) {
        $name = SCHOOLS[$school]['name'] ?? $school;
        $classes = 0;
        foreach ($years as $branches) {
            foreach ($branches as $divisions) $classes += count($divisions);
        }

        if ($classes <= SECTION_SPLIT_AT) {
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
