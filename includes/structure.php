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

// Whether a school's strengths are invented rather than counted. Nothing may
// state those as fact: "450 students" on Law's tile reads as a roll count when
// it is five years of the A=30/B=60 default. Derived from placeholder_schools()
// so the two can never drift apart, and a school stops being one the moment its
// real structure lands in class_structure().
function is_placeholder_school(string $school): bool {
    return isset(placeholder_schools()[$school]);
}

// Flattens a structure to one row per division: the schools' by default, the
// partners' when partner_rows() passes theirs.
function class_rows(?array $structure = null): array {
    $rows = [];
    foreach ($structure ?? class_structure() as $school => $years) {
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
    $name = group_name($school);
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

    // A partner class comes back in the same shape, its partner id in the
    // school field, so every writer and reader downstream stays as it is.
    $school = school_id_for_name($name) ?? partner_id_for_name($name);
    if ($school === null) return null;

    $strength = class_strength($school, $year, $branch, $division)
        ?? partner_structure()[$school][$year][$branch][$division] ?? null;
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
// partner_structure() goes through the same split, one section per partner.
function form_sections(?array $structure = null): array {
    $out = [];
    foreach ($structure ?? class_structure() as $school => $years) {
        $name = group_name($school);
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
            $out["$name: $year"] = $labels;
        }
    }
    return $out;
}

// --- Knowledge partners -----------------------------------------------------
//
// Partner classes are their own tree, never part of class_structure(): they
// never count toward a school tile, the university percentage or the classes
// reported pill, and show only on the Knowledge Partner view. Keyed by partner
// id (kp-aero), which no school id can equal, so no class key is shared either.

// id => ['name' => ..., 'placeholder' => bool], in KNOWLEDGE_PARTNERS order.
// A placeholder partner returned no divisions, so no tile may state its
// enrolment as fact.
function partner_groups(): array {
    $out = [];
    foreach (KNOWLEDGE_PARTNERS as $p) {
        $out['kp-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $p['name']))] = ['name' => $p['name'], 'placeholder' => $p['name'] === 'PixelPop'];
    }
    return $out;
}

function partner_id_for_name(string $name): ?string {
    foreach (partner_groups() as $id => $p) {
        if ($id === $name || strcasecmp($p['name'], $name) === 0) return $id;
    }
    return null;
}

// A school's or a partner's display name, for labels and headings.
function group_name(string $id): string {
    return SCHOOLS[$id]['name'] ?? partner_groups()[$id]['name'] ?? $id;
}

// partner => year => program => [division => strength], the same shape as
// class_structure() with the program in the branch slot. A bare number is one
// division A of that strength.
//
// From the returned 05-partner-divisions-request.xlsx (21 Sep 2026, Aero,
// Emversity and Noval updated from its second return on 22 Sep), read
// literally: its division letters are the divisions (NxtWave's 2nd year starts
// at G, Veloces's and Aero's 1st years are Q, R, S). Program names are kept as
// they were so the Form options stay put where a program did not change.
// Cleaned on the way in:
// - Programs of 0 students are dropped (Seamedu's ITDS 1st/2nd and CSDF 2nd,
//   Emversity's RT 2nd).
// - Sunstone's B.Tech CSE (AI) 2nd year has a third, unlettered row of 40,
//   taken as division C.
// - Flyglam listed BBA and MBA twice, plain and Aviation, with near identical
//   counts. It has only the Aviation programs, so only those are kept.
// - Emversity's 1st year is one combined program in two divisions; its 2nd
//   year "Medical Laboratory Science" is kept as B.Sc MLT.
//
// PixelPop sent nothing and has no classes.
function partner_structure(): array {
    $programs = [
        'kp-aero' => [
            '1st Year' => ['B.Tech Aeronautical' => ['R' => 28], 'B.Tech Aerospace' => ['S' => 35],
                           'B.Tech Avionics' => ['Q' => 5], 'B.Tech Defence Technology' => 15,
                           'Integrated Aerospace' => 12, 'Integrated Defence Technology' => 18,
                           'M.Tech Aerospace' => 3, 'M.Tech Space Technology' => 5,
                           'M.Tech Defence Technology' => 1],
            '2nd Year' => ['B.Tech Aeronautical' => 44, 'B.Tech Aerospace' => 49, 'B.Tech Avionics' => 12,
                           'Integrated Aerospace' => 28, 'Integrated Defence Technology' => 32,
                           'M.Tech Aerospace' => 7],
            '3rd Year' => ['B.Tech Aeronautical' => 49, 'B.Tech Aerospace' => 58, 'B.Tech Avionics' => 4,
                           'Integrated Aerospace' => 28, 'Integrated Defence Technology' => 18],
            '4th Year' => ['B.Tech Aeronautical' => 50, 'B.Tech Aerospace' => 58, 'B.Tech Avionics' => 9,
                           'Dual Degree Aerospace' => 15],
        ],
        'kp-newton' => [
            '1st Year' => ['B.Tech CSE (AI&ML)' => ['A' => 118, 'B' => 118, 'C' => 117]],
            '2nd Year' => ['B.Tech CSE (AI&ML)' => ['A' => 101, 'B' => 100, 'C' => 102, 'D' => 100]],
            '3rd Year' => ['B.Tech CSE (AI&ML)' => ['A' => 111, 'B' => 114, 'C' => 88]],
        ],
        'kp-sunstone' => [
            '1st Year' => ['B.Tech (CS&IT)' => 133, 'B.Tech CSE (AI)' => ['A' => 76, 'B' => 56],
                           'BCA (FSD)' => 38, 'MCA (FSD)' => 33, 'BBA' => 37, 'MBA' => 16],
            '2nd Year' => ['B.Tech (CS&IT)' => ['A' => 79, 'B' => 118],
                           'B.Tech CSE (AI)' => ['A' => 50, 'B' => 50, 'C' => 40],
                           'BCA (FSD)' => 62, 'MCA (FSD)' => 52, 'BBA' => 33, 'MBA' => 27],
            '3rd Year' => ['B.Tech (CS&IT)' => 118, 'BCA (FSD)' => 104, 'BBA' => 33],
        ],
        'kp-nxtwave' => [
            '1st Year' => ['B.Tech CSE (DS)' => array_fill_keys(['A', 'B', 'C', 'D', 'E'], 56)],
            '2nd Year' => ['B.Tech CSE (DS)' => array_fill_keys(['G', 'H', 'I', 'J', 'K'], 67)],
        ],
        'kp-emversity' => [
            '1st Year' => ['B.Sc (AOTT, CVT, MLS, RT)' => ['A' => 75, 'B' => 75]],
            '2nd Year' => ['B.Sc CVT' => 34, 'B.Sc AOTT' => 33, 'B.Sc MLT' => 7],
        ],
        'kp-veloces' => [
            '1st Year' => ['B.Tech CSE (Cyber Forensics & Information Security)' => ['Q' => 34],
                           'B.Tech CSE (Virtual & Augmented Reality)' => ['Q' => 5]],
            '2nd Year' => ['B.Tech CSE (Cyber Forensics & Information Security)' => 52,
                           'B.Tech CSE (Virtual & Augmented Reality)' => 9],
            '3rd Year' => ['B.Tech CSE (Cyber Forensics & Information Security)' => 47,
                           'B.Tech CSE (Virtual & Augmented Reality)' => 32],
        ],
        'kp-seamedu' => [
            '1st Year' => ['B.Tech (AI&DE)' => 17, 'B.Tech (CSDF)' => 3, 'BCA FY' => 43, 'MCA FY' => 45,
                           'BBA (IB+BFS+DM)' => 13, 'MBA (IB+BFS+BAI)' => 9,
                           'B.Sc Sound Engineering' => 26, 'BCA Game Development' => 12,
                           'BBA Media and Communication' => 5, 'B.Sc Filmmaking' => 15,
                           'B.Sc Animation and Visual Effects' => 13, 'B.Sc Game Art and Design' => 9],
            '2nd Year' => ['B.Tech (AI&DS)' => 26, 'BCA (CS)' => 29, 'BCA (AI&DS)' => 25, 'MCA (CC)' => 20,
                           'MCA (CSDF)' => 43, 'MCA (DSA)' => 26, 'BBA (IB)' => 9, 'BBA (BKFS)' => 3,
                           'BBA (DM)' => 4, 'MBA (IB)' => 2, 'MBA (BKFS)' => 3,
                           'B.Sc Sound Engineering' => 26, 'BCA Game Development' => 22,
                           'BA Journalism and Media Production' => 6, 'B.Sc Filmmaking' => 13,
                           'B.Sc Animation and Visual Effects' => 27, 'B.Sc Game Art and Design' => 15],
            '3rd Year' => ['B.Tech (ITDS)' => 10, 'BCA (CFIS)' => 10, 'BCA (AIML)' => 37, 'BCA (MIT)' => 9,
                           'BBA (IB)' => 9, 'B.Sc Sound Engineering' => 19, 'BCA Game Development' => 31,
                           'BA Journalism and Media Production' => 3, 'B.Sc Filmmaking' => 23,
                           'B.Sc Animation and Visual Effects' => 30, 'B.Sc Game Art and Design' => 14],
            '4th Year' => ['B.Tech (ITDS)' => 44, 'B.Tech (CTIS)' => 20],
        ],
        'kp-upgrad' => [
            '1st Year' => ['B.Tech CS in AI' => ['A' => 76, 'B' => 71]],
        ],
        'kp-flyglam' => [
            '1st Year' => ['BBA Aviation' => 7],
            '2nd Year' => ['BBA Aviation' => 12, 'MBA Aviation' => 5],
            '3rd Year' => ['BBA Aviation' => 8],
        ],
        'kp-noval' => [
            '1st Year' => ['B.Sc Clinical Research and Technology' => 4],
            '2nd Year' => ['B.Sc Clinical Research and Technology' => 10, 'M.Sc Clinical Research' => 8],
        ],
    ];
    $out = [];
    foreach ($programs as $partner => $years) {
        foreach ($years as $year => $list) {
            foreach ($list as $program => $divs) $out[$partner][$year][$program] = is_array($divs) ? $divs : ['A' => $divs];
        }
    }
    return $out;
}

function partner_rows(): array {
    return class_rows(partner_structure());
}
