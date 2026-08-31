<?php
// Attendance data pipeline: Google Form -> Apps Script push -> file cache ->
// School -> Year -> Branch -> Division tree. See SPEC.md §7.1.
// MySQL (db/schema.sql) is not read from here yet — this is the live path.
// A school with no branch structure uses the single branch key '' (schools
// other than eng — no confirmed branch data exists for them yet).
//
// The cache holds one present count per class per DAY, so any date range can
// be rebuilt from it. See day_map() for the shape and aggregate_days() for
// what a range's numbers mean.

// Every date in the app is a campus date: which day a class met, which day is
// "latest". The host's clock is UTC, so without this the day rolls over at
// 5:30am IST and a morning's submissions file themselves under yesterday.
date_default_timezone_set('Asia/Kolkata');

define('ATTENDANCE_CACHE_FILE', __DIR__ . '/../cache/attendance.json');

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

// Used until real submissions arrive (SPEC.md §8.1). Shape matches a real row;
// the present counts are synthetic. Strength comes from the canonical
// structure, same as a real submission. Deliberately never written to the
// cache file — in push mode that file IS the record, and seeding it with
// invented numbers makes fake data look pushed.
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

// The one non-empty value among every column whose header starts with $prefix.
// A sectioned Form repeats question titles across sections, so the sheet can
// carry a dozen "class ..." columns with one filled — and, if Present or Date
// is placed inside each section rather than shared, a dozen of those too.
// array_combine keeps only the last of a repeated header, which is blank, so
// they are resolved here off the raw header/field arrays instead.
function first_value(array $header, array $fields, string $prefix): string {
    foreach ($header as $i => $name) {
        if (str_starts_with($name, $prefix) && trim((string) ($fields[$i] ?? '')) !== '') {
            return trim($fields[$i]);
        }
    }
    return '';
}

// One yyyy-mm-dd date out of whatever a cell holds, or null. The Apps Script
// formats real Date cells as yyyy-mm-dd; a hand-typed sheet or an export that
// never went through it gives d/m/Y, the Form's locale. Day-first is assumed:
// 03/04/2026 is 3 April, matching how the Form displays it to faculty.
function row_date(string $value): ?string {
    $value = trim($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    return null;
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

    // The Class question can be titled anything, and one section titled so that
    // it doesn't start with "Class" left first_value() empty while the label sat
    // in the row all along — a whole class quietly uncounted. The label's shape
    // is the dependable signal, not the header: take any cell that parses to a
    // real class. Nothing else in a response can accidentally match, since it
    // must name a school, year, branch and division that all exist.
    foreach ($r as $value) {
        if (is_string($value) && str_contains($value, CLASS_SEP)) {
            $class = parse_class_label(trim($value));
            if ($class !== null) return $class;
        }
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

// $skipped collects one label per row that named no real class. A dropped row
// is the failure mode nobody notices: the push reports the rows it stored, so a
// Form option that no longer matches the structure just quietly stops counting.
function parse_attendance_csv(string $csv, ?array &$skipped = null): array {
    $skipped = [];
    $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), fn($l) => trim($l) !== '');
    $rows = [];
    $header = null;
    $today = date('Y-m-d');
    foreach ($lines as $line) {
        $fields = str_getcsv($line);
        if ($header === null) {
            $header = array_map(fn($h) => strtolower(trim($h)), $fields);
            // Without a Present column every row would parse as present = 0 —
            // a silent university-wide absence rather than a visible error.
            // Reject the whole sheet instead; api/ingest.php turns an empty
            // parse into a 422 and leaves the last good data in place.
            foreach ($header as $name) {
                if (str_starts_with($name, 'present')) continue 2;
            }
            return [];
        }
        $r = @array_combine($header, $fields);
        if (!$r) continue;
        // See first_value(): repeated Class / Present columns must be coalesced
        // off the raw arrays, or array_combine hands back the blank last one.
        $r['class'] = first_value($header, $fields, 'class');
        $r['present'] = first_value($header, $fields, 'present');
        $class = class_from_row($r);
        if ($class === null) {
            // A row with nothing filled in is an empty Form response, not a
            // mismatch — only report rows that named something.
            $named = $r['class'] !== '' ? $r['class'] : trim(implode(CLASS_SEP, [
                $r['school'] ?? '', $r['year'] ?? '', $r['branch'] ?? '', $r['division'] ?? '',
            ]), " /");
            if ($named !== '') $skipped[] = $named;
            continue;
        }
        // The day the class met, which is not the day the form was filled in:
        // the Form's Date question wins, the submission timestamp is the
        // fallback (so rows submitted before that question existed keep their
        // real day instead of collapsing onto the push date), and today is the
        // last resort. A future date is a typo for the year, and would park a
        // reading at the end of the calendar where the "latest day" default
        // view would then sit on it forever, so it is not accepted.
        $date = $today;
        foreach ([first_value($header, $fields, 'date'), first_value($header, $fields, 'timestamp')] as $candidate) {
            $parsed = row_date($candidate);
            if ($parsed !== null && $parsed <= $today) { $date = $parsed; break; }
        }
        $rows[] = $class + [
            'present' => (int) ($r['present'] ?? 0),
            'date'    => $date,
            // Who filed it. Presentational only: it never touches a count, so
            // a missing or misspelt name costs nothing but the attribution.
            'faculty' => first_value($header, $fields, 'faculty'),
        ];
    }
    return $rows;
}

// The tree's flat key for one class. No segment can contain '|', so it splits
// back cleanly and can be prefix-matched to scope a drill-down (js/charts.js).
function class_key(array $c): string {
    return $c['school'] . '|' . $c['year'] . '|' . $c['branch'] . '|' . $c['division'];
}

// date => class key => present. This is what the cache stores: one number per
// class per day, so any range can be rebuilt, and small enough to json_decode
// on every request where keeping the raw rows would not be.
//
// A class submitting twice on the same day overwrites, so the later row wins —
// rows arrive in sheet order, and a resubmission is a correction.
//
// ponytail: grows without bound, roughly 600KB a school year. Prune to a
// rolling window, or move to db/schema.sql, when the decode starts to hurt.
function day_map(array $rows): array {
    $days = [];
    foreach ($rows as $r) {
        $days[$r['date']][class_key($r)] = (int) $r['present'];
    }
    ksort($days);
    return $days;
}

// date => class key => faculty name, parallel to day_map() and keyed the same
// way, so the later row wins there and here alike. Kept beside the day map
// rather than inside it because every reader of that map wants a plain int:
// making the value a pair would touch aggregate_days, class_dates and the
// tests to carry a string none of them use.
//
// Rows with no name are skipped rather than stored empty — the Form only grew
// the question in Aug 2026, so most of the history has none.
function faculty_map(array $rows): array {
    $names = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['faculty'] ?? ''));
        if ($name !== '') $names[$r['date']][class_key($r)] = $name;
    }
    ksort($names);
    return $names;
}

// Which dates a request actually covers. A missing end defaults to the newest
// day that has any data: a single real, labelled day can't be an averaging
// artefact, and unlike "today" it is never empty in the morning before the
// first form of the day arrives.
function resolve_range(array $days, ?string $from = null, ?string $to = null): array {
    $latest = $days ? max(array_keys($days)) : date('Y-m-d');
    $from = $from ?: ($to ?: $latest);
    $to   = $to ?: $from;
    if ($from > $to) [$from, $to] = [$to, $from];
    return [$from, $to];
}

// Builds School -> Year -> Branch -> Division[] from the CANONICAL class list
// in structure.php, then overlays the days inside [$from, $to].
//
// The structure leads, not the submissions. A class nobody reported in the
// range still exists, still contributes its strength to the denominator, and
// is marked reported => false so the UI can say "not reported" instead of
// showing a school as 0/0 — which reads as "this school has no students".
//
// present is the class's AVERAGE over the days it reported, never the sum: a
// range has to stay comparable to a single day, and dividing by days nobody
// reported would punish a class for its faculty's silence rather than for
// absence. 'days' rides along so the UI can say how much of the range the
// number actually rests on — a class that reported once in a week must not be
// able to pass that off as the week.
function aggregate_days(array $days, ?string $from = null, ?string $to = null): array {
    require_once __DIR__ . '/structure.php';
    [$from, $to] = resolve_range($days, $from, $to);

    $sum = [];
    $count = [];
    foreach ($days as $date => $classes) {
        if ($date < $from || $date > $to) continue;
        foreach ($classes as $key => $present) {
            $sum[$key] = ($sum[$key] ?? 0) + (int) $present;
            $count[$key] = ($count[$key] ?? 0) + 1;
        }
    }

    $tree = [];
    foreach (class_rows() as $c) {
        $key = class_key($c);
        $n = $count[$key] ?? 0;
        $tree[$c['school']][$c['year']][$c['branch']][] = [
            'division' => $c['division'],
            'strength' => $c['strength'],
            'present'  => $n ? (int) round($sum[$key] / $n) : 0,
            'reported' => $n > 0,
            'days'     => $n,
        ];
    }
    return $tree;
}

function aggregate_attendance(array $rows, ?string $from = null, ?string $to = null): array {
    return aggregate_days(day_map($rows), $from, $to);
}

// The days one class actually reported inside the range. The tile shows an
// average; this is what it rests on, so the modal can name the dates rather
// than let a number imply the whole range was reported.
function class_dates(array $days, string $key, string $from, string $to): array {
    $dates = [];
    foreach ($days as $date => $classes) {
        if ($date >= $from && $date <= $to && isset($classes[$key])) $dates[] = $date;
    }
    sort($dates);
    return $dates;
}

// Also counts how many of the classes in scope have actually reported, and
// their combined strength. Without the reported count, "0 present" and "nobody
// submitted" are indistinguishable; without strength_reported, every
// percentage is diluted by classes that simply never filled the form in — at
// 9am that reads as a near-empty university rather than as a missing form.
function attendance_totals(array $tree): array {
    $strength = 0;
    $strengthReported = 0;
    $present = 0;
    $classes = 0;
    $reported = 0;
    foreach ($tree as $years) {
        foreach ($years as $branches) {
            foreach ($branches as $divisions) {
                foreach ($divisions as $d) {
                    $strength += $d['strength'];
                    $present += $d['present'];
                    $classes++;
                    if (!empty($d['reported'])) {
                        $reported++;
                        $strengthReported += $d['strength'];
                    }
                }
            }
        }
    }
    return [
        'strength'          => $strength,
        'strength_reported' => $strengthReported,
        'present'           => $present,
        'classes'           => $classes,
        'reported'          => $reported,
    ];
}

// Attendance as a percentage of the classes that actually reported. Paired
// everywhere with the reported/classes count, which is what stops it being a
// half-truth: 87% of 45 classes says exactly what it says.
function attendance_pct(array $totals): int {
    return $totals['strength_reported']
        ? (int) round($totals['present'] / $totals['strength_reported'] * 100)
        : 0;
}

function read_attendance_cache(): ?array {
    if (!is_file(ATTENDANCE_CACHE_FILE)) return null;
    $cached = json_decode((string) file_get_contents(ATTENDANCE_CACHE_FILE), true);
    return is_array($cached) && $cached ? $cached : null;
}

// Push mode (SPEC.md §7.1): InfinityFree blocks outbound HTTP from PHP, so
// Google pushes to api/ingest.php and this file IS the record. Nothing here
// expires or refetches — there is nothing to refetch from.
function get_attendance_days(): array {
    $cached = read_attendance_cache();
    if (isset($cached['days']) && is_array($cached['days'])) return $cached['days'];
    // A cache from before the day map holds a bare tree with no dates in it;
    // get_attendance() serves that one as-is. Nothing at all means a fresh
    // checkout, where the sample rows keep the charts and the tiles telling
    // the same story instead of one showing numbers and the other "no data".
    if ($cached !== null) return [];
    return day_map(sample_attendance_rows());
}

// The faculty names beside the day map, or nothing at all: a cache written
// before the Form asked for a name has no such key, and neither do the sample
// rows. Every caller must treat an absent name as "not recorded", never as an
// error — the attendance numbers stand on their own.
function get_attendance_faculty(): array {
    $cached = read_attendance_cache();
    return is_array($cached['faculty'] ?? null) ? $cached['faculty'] : [];
}

function get_attendance(?string $from = null, ?string $to = null): array {
    $days = get_attendance_days();
    if ($days) return aggregate_days($days, $from, $to);

    // ponytail: a cache written before the day map existed holds the bare tree,
    // which has no dates, so the range is ignored and it is served as-is. The
    // next push replaces it. Delete this once every deployment has pushed.
    $cached = read_attendance_cache();
    if ($cached !== null) return $cached;

    return aggregate_attendance(sample_attendance_rows(), $from, $to);
}

// How a range reads on screen. One day is just that day; a real range names
// both ends, because an average with no dates against it is a number nobody
// can check.
function range_label(string $from, string $to): string {
    $f = date_create($from);
    $t = date_create($to);
    if (!$f || !$t) return $from;
    if ($from === $to) return $f->format('d M Y');
    $sameYear = $f->format('Y') === $t->format('Y');
    return $f->format($sameYear ? 'd M' : 'd M Y') . ' to ' . $t->format('d M Y');
}
