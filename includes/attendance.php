<?php
// Attendance data pipeline: Google Sheet (published CSV) -> file cache ->
// School -> Year -> Division tree. See SPEC.md §7.1.
// MySQL (db/schema.sql) is not read from here yet — this is the live path.

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
    // Every school gets every one of its years, each with divisions A and B,
    // so the drill-down works end to end for all nine schools. Engineering
    // additionally gets its mockup-matching 2nd Year divisions (§5 modal example).
    $ordinals = ['1st', '2nd', '3rd', '4th', '5th'];
    $yearCounts = [
        'eng' => 4, 'mgmt' => 4, 'law' => 5, 'design' => 4,
        'science' => 4, 'arch' => 4, 'hosp' => 4, 'lib' => 4, 'film' => 4,
    ];

    $rows = [];
    foreach ($yearCounts as $school => $count) {
        for ($i = 0; $i < $count; $i++) {
            $year = $ordinals[$i] . ' Year';
            $divisions = ($school === 'eng' && $i === 1) ? ['A', 'B', 'C', 'D'] : ['A', 'B'];
            foreach ($divisions as $j => $div) {
                $strength = 30 + (($j % 2) * 30);
                $rows[] = [
                    'school' => $school,
                    'year' => $year,
                    'division' => $div,
                    'strength' => $strength,
                    'present' => $strength - (5 + $i * 3 + $j * 2),
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
            'division' => trim($r['division'] ?? ''),
            'strength' => (int) ($r['strength'] ?? 0),
            'present'  => (int) ($r['present'] ?? 0),
            'date'     => trim($r['date'] ?? ''),
        ];
    }
    return $rows;
}

// Groups flat rows into School -> Year -> Division[], keeping only the latest
// row per school/year/division/date (a same-day resubmission wins).
function aggregate_attendance(array $rows): array {
    $latest = [];
    foreach ($rows as $r) {
        $key = $r['school'] . '|' . $r['year'] . '|' . $r['division'] . '|' . $r['date'];
        $latest[$key] = $r;
    }
    $tree = [];
    foreach ($latest as $r) {
        $tree[$r['school']][$r['year']][] = [
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
        foreach ($years as $divisions) {
            foreach ($divisions as $d) {
                $strength += $d['strength'];
                $present += $d['present'];
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
