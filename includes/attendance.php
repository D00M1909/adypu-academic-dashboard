<?php
// Attendance data pipeline: Google Sheet (published CSV) -> file cache ->
// School -> Year -> Branch -> Division tree. See SPEC.md §7.1.
// MySQL (db/schema.sql) is not read from here yet — this is the live path.
// A school with no branch structure uses the single branch key '' (schools
// other than eng — no confirmed branch data exists for them yet).

define('SHEET_CSV_URL', getenv('SHEET_CSV_URL') ?: '');
define('ATTENDANCE_CACHE_FILE', __DIR__ . '/../cache/attendance.json');
define('ATTENDANCE_CACHE_TTL', 300);

// Icon per school is presentational (see the SVG sprite in index.php, symbol
// ids match these keys) — not stored here.
const SCHOOLS = [
    'eng'     => ['name' => 'School of Engineering'],
    'mgmt'    => ['name' => 'School of Management'],
    'law'     => ['name' => 'School of Law'],
    'design'  => ['name' => 'School of Design'],
    'science' => ['name' => 'School of Science'],
    'arch'    => ['name' => 'School of Architecture'],
    'hosp'    => ['name' => 'School of Hospitality'],
    'lib'     => ['name' => 'School of Liberal Arts'],
    'film'    => ['name' => 'School of Film & Media'],
];

// Used until SHEET_CSV_URL is configured or a fetch fails (SPEC.md §8.1).
function sample_attendance_rows(): array {
    $today = date('Y-m-d');
    $rows = [];

    // School of Engineering: real division/branch structure confirmed from
    // automatic-timetable-generator's timetable_db.classes (AY 2026-27), read
    // 26 Aug 2026. Strength is that DB's own scheduling default (60 per
    // division everywhere, not a real headcount) — kept as-is since it's the
    // only confirmed number available. Present/day has no real source until
    // the Google Form exists, so it's a synthetic placeholder below.
    $engStructure = [
        '1st Year' => ['Core' => ['A', 'B', 'C', 'D', 'E', 'F'], 'CS' => ['M', 'N', 'O', 'P', 'S', 'T', 'U', 'V', 'W', 'X']],
        '2nd Year' => ['AIDS' => ['A', 'B', 'C', 'D'], 'CSE' => ['A', 'B', 'C', 'D', 'E'], 'ECE' => ['A']],
        '3rd Year' => ['AIDS' => ['A', 'B'], 'CS' => ['A'], 'SE' => ['A', 'B']],
        '4th Year' => ['AIDS' => ['A', 'B'], 'CS' => ['A'], 'SE' => ['A', 'B', 'C']],
    ];
    $i = 0;
    foreach ($engStructure as $year => $branches) {
        foreach ($branches as $branch => $divisions) {
            foreach ($divisions as $division) {
                $i++;
                $rows[] = [
                    'school' => 'eng',
                    'year' => $year,
                    'branch' => $branch,
                    'division' => $division,
                    'strength' => 60,
                    'present' => 60 - (8 + ($i % 6) * 4),
                    'date' => $today,
                ];
            }
        }
    }

    // Other schools: no confirmed branch/division data yet, so branch stays
    // empty and the drill-down skips straight from Year to the division
    // modal, same as before this data existed for Engineering.
    $ordinals = ['1st', '2nd', '3rd', '4th', '5th'];
    $yearCounts = [
        'mgmt' => 4, 'law' => 5, 'design' => 4,
        'science' => 4, 'arch' => 4, 'hosp' => 4, 'lib' => 4, 'film' => 4,
    ];
    foreach ($yearCounts as $school => $count) {
        for ($y = 0; $y < $count; $y++) {
            $year = $ordinals[$y] . ' Year';
            foreach (['A', 'B'] as $j => $div) {
                $strength = 30 + (($j % 2) * 30);
                $rows[] = [
                    'school' => $school,
                    'year' => $year,
                    'branch' => '',
                    'division' => $div,
                    'strength' => $strength,
                    'present' => $strength - (5 + $y * 3 + $j * 2),
                    'date' => $today,
                ];
            }
        }
    }
    return $rows;
}

function fetch_sheet_csv(): ?string {
    if (SHEET_CSV_URL === '') return null;
    $csv = @file_get_contents(SHEET_CSV_URL);
    return $csv !== false ? $csv : null;
}

function parse_attendance_csv(string $csv): array {
    $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), fn($l) => trim($l) !== '');
    $rows = [];
    $header = null;
    foreach ($lines as $line) {
        $fields = str_getcsv($line);
        if ($header === null) {
            $header = array_map(fn($h) => strtolower(trim($h)), $fields);
            continue;
        }
        $r = @array_combine($header, $fields);
        if (!$r || empty($r['school'])) continue;
        $rows[] = [
            'school'   => trim($r['school']),
            'year'     => trim($r['year'] ?? ''),
            'branch'   => trim($r['branch'] ?? ''),
            'division' => trim($r['division'] ?? ''),
            'strength' => (int) ($r['strength'] ?? 0),
            'present'  => (int) ($r['present'] ?? 0),
            'date'     => trim($r['date'] ?? ''),
        ];
    }
    return $rows;
}

// Groups flat rows into School -> Year -> Branch -> Division[], keeping only
// the latest row per school/year/branch/division/date (a same-day
// resubmission wins). Branch is '' for schools with no branch structure.
function aggregate_attendance(array $rows): array {
    $latest = [];
    foreach ($rows as $r) {
        $key = $r['school'] . '|' . $r['year'] . '|' . $r['branch'] . '|' . $r['division'] . '|' . $r['date'];
        $latest[$key] = $r;
    }
    $tree = [];
    foreach ($latest as $r) {
        $tree[$r['school']][$r['year']][$r['branch']][] = [
            'division' => $r['division'],
            'strength' => $r['strength'],
            'present'  => $r['present'],
        ];
    }
    return $tree;
}

function attendance_totals(array $tree): array {
    $strength = 0;
    $present = 0;
    foreach ($tree as $years) {
        foreach ($years as $branches) {
            foreach ($branches as $divisions) {
                foreach ($divisions as $d) {
                    $strength += $d['strength'];
                    $present += $d['present'];
                }
            }
        }
    }
    return ['strength' => $strength, 'present' => $present];
}

function get_attendance(bool $forceRefresh = false): array {
    if (!$forceRefresh && is_file(ATTENDANCE_CACHE_FILE)
        && (time() - filemtime(ATTENDANCE_CACHE_FILE)) < ATTENDANCE_CACHE_TTL) {
        $cached = json_decode(file_get_contents(ATTENDANCE_CACHE_FILE), true);
        if (is_array($cached)) return $cached;
    }

    $csv = fetch_sheet_csv();
    $rows = $csv !== null ? parse_attendance_csv($csv) : sample_attendance_rows();
    $tree = aggregate_attendance($rows);

    if (!is_dir(dirname(ATTENDANCE_CACHE_FILE))) {
        @mkdir(dirname(ATTENDANCE_CACHE_FILE), 0777, true);
    }
    @file_put_contents(ATTENDANCE_CACHE_FILE, json_encode($tree));

    return $tree;
}
