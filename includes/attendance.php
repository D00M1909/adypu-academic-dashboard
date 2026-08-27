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

// Used until real submissions arrive or a fetch fails (SPEC.md §8.1). Shape
// matches a real row; the present counts are synthetic — there is no source for
// them until the Google Form is live. Strength comes from the canonical
// structure, same as a real submission.
function sample_attendance_rows(): array {
    require_once __DIR__ . '/structure.php';
    $today = date('Y-m-d');
    $rows = [];
    $i = 0;
    $yearIndex = [];

    foreach (class_rows() as $c) {
        // Two placeholder formulas, kept only so the numbers match what the
        // dashboard showed before the structure was extracted. Both die with
        // the sample data.
        if ($c['school'] === 'eng') {
            $i++;
            $present = $c['strength'] - (8 + ($i % 6) * 4);
        } else {
            $key = $c['school'] . '|' . $c['year'];
            $yearIndex[$c['school']] ??= [];
            $yearIndex[$c['school']][$c['year']] ??= count($yearIndex[$c['school']]);
            $y = $yearIndex[$c['school']][$c['year']];
            $j = $c['division'] === 'A' ? 0 : 1;
            $present = $c['strength'] - (5 + $y * 3 + $j * 2);
        }
        $rows[] = $c + ['present' => $present, 'date' => $today];
    }

    return $rows;
}

function fetch_sheet_csv(): ?string {
    if (SHEET_CSV_URL === '') return null;
    $csv = @file_get_contents(SHEET_CSV_URL);
    return $csv !== false ? $csv : null;
}

// The one non-empty value among every column whose header starts with $prefix.
// A sectioned Form repeats question titles across sections, so the sheet can
// carry a dozen "class ..." columns with one filled — and, if Present is placed
// inside each section rather than shared, a dozen "present" columns too.
// array_combine keeps only the last of a repeated header, which is blank, so
// both are resolved here off the raw header/field arrays instead.
function first_value(array $header, array $fields, string $prefix): string {
    foreach ($header as $i => $name) {
        if (str_starts_with($name, $prefix) && trim((string) ($fields[$i] ?? '')) !== '') {
            return trim($fields[$i]);
        }
    }
    return '';
}

// Resolves one CSV row to a real class. Two row shapes are accepted: a "class"
// column holding a full label ("School of Engineering / 2nd Year / CSE / A"),
// and the four separate columns a hand-maintained sheet or the DB export uses. Either
// way strength comes from structure.php, never from the row — see SPEC.md §7.1.
// A row naming a class that doesn't exist returns null and is dropped.
function class_from_row(array $r): ?array {
    require_once __DIR__ . '/structure.php';

    if (!empty($r['class'])) {
        return parse_class_label(trim($r['class']));
    }
    if (empty($r['school'])) {
        return null;
    }

    $school = school_id_for_name(trim($r['school']));
    if ($school === null) return null;

    $year     = trim($r['year'] ?? '');
    $branch   = trim($r['branch'] ?? '');
    $division = trim($r['division'] ?? '');
    $strength = class_strength($school, $year, $branch, $division);
    if ($strength === null) return null;

    return compact('school', 'year', 'branch', 'division', 'strength');
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
        if (!$r) continue;
        // See first_value(): repeated Class / Present columns must be coalesced
        // off the raw arrays, or array_combine hands back the blank last one.
        $r['class'] = first_value($header, $fields, 'class');
        $r['present'] = first_value($header, $fields, 'present');
        $class = class_from_row($r);
        if ($class === null) continue;
        $rows[] = $class + [
            'present' => (int) ($r['present'] ?? 0),
            'date'    => trim($r['date'] ?? '') ?: date('Y-m-d'),
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

function read_attendance_cache(): ?array {
    if (!is_file(ATTENDANCE_CACHE_FILE)) return null;
    $cached = json_decode((string) file_get_contents(ATTENDANCE_CACHE_FILE), true);
    return is_array($cached) && $cached ? $cached : null;
}

function get_attendance(bool $forceRefresh = false): array {
    $cached = read_attendance_cache();

    // Push mode (SPEC.md §7.1): with no SHEET_CSV_URL there is nothing to pull
    // from, so the cache file IS the record — api/ingest.php replaces it when
    // Google pushes. It must never expire back to sample data.
    if (SHEET_CSV_URL === '' && $cached !== null) {
        return $cached;
    }

    $fresh = is_file(ATTENDANCE_CACHE_FILE)
        && (time() - filemtime(ATTENDANCE_CACHE_FILE)) < ATTENDANCE_CACHE_TTL;
    if (!$forceRefresh && $fresh && $cached !== null) {
        return $cached;
    }

    $csv = fetch_sheet_csv();

    // Sheet unreachable: serve the last good data rather than dropping the
    // dashboard back to sample numbers, which would look real and be wrong.
    if ($csv === null && $cached !== null) {
        return $cached;
    }

    $rows = $csv !== null ? parse_attendance_csv($csv) : sample_attendance_rows();
    if (!$rows && $cached !== null) {
        return $cached;
    }
    $tree = aggregate_attendance($rows);

    if (!is_dir(dirname(ATTENDANCE_CACHE_FILE))) {
        @mkdir(dirname(ATTENDANCE_CACHE_FILE), 0775, true);
    }
    @file_put_contents(ATTENDANCE_CACHE_FILE, json_encode($tree));

    return $tree;
}
