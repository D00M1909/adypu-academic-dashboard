<?php
// Builds the dashboard's timetable/rooms.php, the room timetable page's data,
// from every school's timetable.
//
//   C:\xampp\php\php.exe tools\timetable\build.php [source ...] > tools\timetable\data\last-build.txt
//
// The sources and the hand transcriptions carry faculty names, so they live in
// tools/timetable/data/, which is gitignored like timetable/ itself. The two
// .xlsx timetables (Law, Liberal Arts) are read here directly; every other
// school is first turned into a .json beside them by the convert-* scripts.
//
// Each workbook has one tab per class: a "Time / Day" header with Monday to
// Friday across, then each period as a time row, a faculty row and a room row.
// The two schools lay that out differently (Law puts the time in column A;
// Liberal Arts puts "Lec 1" there and the time in B, and sometimes leaves the
// faculty and room rows unlabelled), so the parser keys on the header, not on
// fixed columns.
//
// The sheets are hand-typed, so a few things are repaired rather than trusted,
// and every repair is printed so it can be checked against the paper:
//   - a room typed in the faculty row and the name in the room row: swapped back
//   - a missing or "NF" room: the class's own room (the one it uses most)
//   - a period row with no time: one hour from where the previous row ended
//   - "11:00 am to 12:00 am", "01.00 am to 02.00 pm": hours before 8 are pm
//   - "405/307/301/Psy Lab" with "Dr. A/B/Dr. C/D": parallel minors, one
//     booking per room, paired by position
//   - one lecture typed on several classes' tabs (a common first-year subject):
//     one booking, every class listed
//   - "Prof Surname", "Surname", "Prof. First Surname": one person, the fullest
//     spelling
// Overlapping bookings that are not the same lecture are printed as clashes.

$dash = dirname(__DIR__, 2);
$data = __DIR__ . '/data';
$sources = array_slice($argv, 1) ?: [
    "$data/sources/School of Law - Timetable 2026-27.xlsx",
    "$data/sources/School of Liberal Arts - Timetable 2026-27.xlsx",
    "$data/biotech-2026-09-29.json",
    "$data/biotech-ft-2026-10-06.json",
    "$data/civil-2026-07-15.json",
    "$data/biomedical-2026-10-26.json",
    "$data/firstyear-2026-10-26.json",
    "$data/ece-2026-10-12.json",
    "$data/management-2026-10-12.json",
    "$data/sy-cse-2026-09-28.json",
    "$data/ty-cse-2026-09-28.json",
    "$data/btech-cse-2026-09-28.json",
    "$data/aids-2026-08-03.json",
    "$data/mechanical-2026-09-30.json",
    "$data/mdmc-cyber-2026-10-14.json",
    "$data/cyber-2026-08-01.json",
    "$data/science-sem1-2026-09-28.json",
    "$data/science-sem3-2026-09-28.json",
    "$data/hospitality-2026-10-26.json",
    "$data/design-foundation-2026-10-14.json",
    "$data/design-td-2026-10-14.json",
    "$data/design-uiux-2026-10-14.json",
    "$data/design-fashion-2026-10-14.json",
    "$data/design-product-2026-10-14.json",
    "$data/design-vcd-2026-10-14.json",
    "$data/design-interior-2026-10-14.json",
];

require_once "$dash/tools/xlsx.php";
require_once "$dash/includes/timetable.php";
require_once __DIR__ . '/sources.php';

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
const ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X'];
const LAW = ['BALLB' => 'BA LLB', 'BBALLB' => 'BBA LLB', 'LLB' => 'LLB'];
const SOLA = ['psy' => 'Psychology', 'eco' => 'Economics', 'pir' => 'Politics & IR', 'ir' => 'Politics & IR', 'eng' => 'English'];
const SOLA_MA = ['ma counselling' => 'MA Counselling Psychology', 'ma indus' => 'MA Industrial Psychology', 'ma ir' => 'MA Politics & IR'];
// Two spellings of one first name, {"typed": "kept"} in lower case. Faculty
// names, so kept with the data rather than in this file.
define('FIRST_NAME', json_decode((string) @file_get_contents("$data/name-spellings.json"), true) ?: []);

$notes = [];
$note = function (string $s) use (&$notes) { $notes[] = $s; };
$tidy = fn($s): string => trim(preg_replace('/\s+/u', ' ', rtrim(trim((string) $s), '`')));

function class_label(string $tab): string {
    $tab = trim($tab);
    if (preg_match('/^(BALLB|BBALLB|LLB)\s+(\d+)$/i', $tab, $m)) return LAW[strtoupper($m[1])] . ' Sem ' . (ROMAN[(int) $m[2]] ?? $m[2]);
    if (preg_match('/^Sem\s+(\w+)\s+(\w+)$/i', $tab, $m)) {
        $n = ctype_digit($m[1]) ? (ROMAN[(int) $m[1]] ?? $m[1]) : strtoupper($m[1]);
        return 'BA ' . (SOLA[strtolower($m[2])] ?? $m[2]) . " Sem $n";
    }
    if (isset(SOLA_MA[strtolower($tab)])) return SOLA_MA[strtolower($tab)];
    $words = preg_split('/\s+/', str_ireplace(' DIV ', ' ', $tab));
    return implode(' ', array_map(fn($w) => strlen($w) <= 3 ? strtoupper($w) : ucfirst(strtolower($w)), $words));
}

// [label, start minutes, end minutes], or null. Hours before 8 are afternoon,
// whatever the sheet says: it writes "01.00 am" and "12:00 am" for pm.
function slot_of(string $s): ?array {
    if (!preg_match('/(\d{1,2})[.:](\d{2}).*?to\s*(\d{1,2})[.:](\d{2})/i', $s, $m)) return null;
    $h = fn($x) => (int) $x < 8 ? (int) $x + 12 : (int) $x;
    return slot_from($h($m[1]) * 60 + (int) $m[2], $h($m[3]) * 60 + (int) $m[4]);
}
function slot_from(int $a, int $b): array {
    $f = fn($t) => (intdiv($t, 60) > 12 ? intdiv($t, 60) - 12 : intdiv($t, 60)) . '.' . sprintf('%02d', $t % 60);
    return [$f($a) . ' to ' . $f($b), $a, $b];
}

// Drops a "Subject:" prefix and a trailing course code:
// "Constitutional Law- IL0503A" -> "Constitutional Law- I".
function subject_name(string $s): string {
    $s = preg_replace('/^Subject\s*:\s*/i', '', $s);
    $s = preg_replace('/\s*(L\s?\d{4}[A-Z]|[A-Z]{2,3}\s?\d{3}[A-Z]?)$/', '', $s);
    return trim(rtrim(trim($s), '-:'));
}

function room_name(string $s): string {
    $s = trim($s);
    if (preg_match('/^(\d{1,3})\.0$/', $s, $m)) return $m[1];
    if (preg_match('/^(psy\s*)?lab$/i', $s)) return 'Psy Lab';
    if (preg_match('/^dig(ital)?\.?\s*lib(rary)?$/i', $s)) return 'Digital Library';
    if (preg_match('/^moot\s*court$/i', $s)) return 'Moot Court';
    if (preg_match('/^nf$/i', $s)) return '';
    return $s;
}

function is_room(string $s): bool {
    return (bool) preg_match('/^(\d{3}(\.0)?|moot court|nf|(psy\s*)?lab|dig(ital)?\.?\s*lib(rary)?)$/i', trim($s));
}

// "Dr. First Surname" -> "first surname", "Prof First" -> "first": who a name
// is, ignoring the title. The whole name, not the first name alone: two
// schools each have a teacher with the same first name and different surnames.
function person_key(string $name): string {
    $bare = strtolower(trim(preg_replace('/^(dr|prof|mr|mrs|ms)\.?\s*/i', '', trim($name))));
    $words = preg_split('/\s+/', $bare);
    $words[0] = FIRST_NAME[$words[0]] ?? $words[0];
    return implode(' ', $words);
}

$bookings = [];   // one per class per period per room, before merging
$people = [];     // person key => [spelling => count], across every workbook:
                  // one teacher can teach in both schools
$split = [];
$home = [];
$inFile = [];     // file => person keys seen in it
$listed = [];     // file => person key => full name, from the course lists
$blocks = [];     // outside bookings, added to the rooms as they are
$calendars = [];  // semester calendars, exported as they are (see below)
$classes = 0;
$docs = [];       // id => the document a source came from (sources.php)
foreach ($sources as $src) {
    // A timetable that only came as a PDF (Biotechnology) is transcribed by
    // hand into a .json beside this script: [day, start, hours, subject code,
    // room, faculty code, lab, note] per period, codes spelled out once.
    if (str_ends_with(strtolower($src), '.json')) {
        $tx = json_decode((string) file_get_contents($src), true);
        if (!is_array($tx)) { fwrite(STDERR, "Could not read $src\n"); exit(1); }
        // How the school's document became this file: typed in by hand, or
        // read by a converter script. The transcription's own note says which.
        $doc = source_doc($src, $data, "$dash/timetable/docs", stripos($tx['note'] ?? '', 'by hand') !== false ? 'hand' : 'script');
        if ($doc) $docs[$doc['id']] = $doc;
        foreach ($tx['classes'] as $c) {
            $classes++;
            foreach ($c['entries'] as [$day, $start, $hours, $code, $room, $who, $lab, $extra]) {
                [$h, $m] = array_map('intval', explode('.', $start));
                $at = ($h < 8 ? $h + 12 : $h) * 60 + $m;
                $name = $tx['subjects'][$code] ?? $code;
                $subj = $name . ($lab && !preg_match('/\blab$/i', $name) ? ' Lab' : '') . ($extra !== '' ? " ($extra)" : '');
                // The file's own building, unless a room names another ("ULC 1 306").
                $building = $tx['building'] ?? 'ULC 1';
                $raw = $room;
                if (preg_match('/^ULC \s*(\d+)\s+(.+)$/i', $room, $bm)) [$building, $room] = ['ULC ' . $bm[1], $bm[2]];
                $split[] = [
                    'file' => $tx['source'], 'tab' => "page {$c['page']}", 'class' => $c['class'], 'day' => $day,
                    'subj' => $subj, 'fac' => $tx['faculty'][$who] ?? $who, 'room' => $room, 'building' => $building,
                    'slot' => slot_from($at, $at + 60), 'span' => (int) $hours, 'lab' => (bool) $lab, 'r' => 0,
                    'src' => ['doc' => $doc['id'] ?? '', 'page' => (string) $c['page'], 'day' => $day, 'start' => $at,
                        'room' => $raw, 'words' => [substr((string) strrchr("|$code", '|'), 1), $name]],
                    'fix' => [],
                ];
            }
        }
        // A semester calendar (Design): one subject a session, changing by
        // the week, kept whole and drawn a week at a time by the page.
        foreach ($tx['calendars'] ?? [] as $cal) {
            $classes++;
            $calendars[] = $cal + ['building' => $tx['building'] ?? 'ULC 1', 'doc' => $doc['id'] ?? ''];
        }
        // Another department's hold on a room (ECE's sheet names who else is in
        // each room): drawn as "Booked", never as one of our classes.
        foreach ($tx['blocks'] ?? [] as [$day, $start, $hours, $room, $by]) {
            [$h, $m] = array_map('intval', explode('.', $start));
            $at = ($h < 8 ? $h + 12 : $h) * 60 + $m;
            $blocks[] = ['day' => $day, 'slot' => slot_from($at, $at + 60), 'span' => (int) $hours,
                'room' => $room, 'building' => $tx['building'] ?? 'ULC 1', 'by' => $by,
                'src' => ['doc' => $doc['id'] ?? '', 'page' => (string) ($tx['classes'][0]['page'] ?? ''), 'day' => $day,
                    'start' => $at, 'room' => $room, 'words' => [$by], 'fix' => []]];
        }
        continue;
    }
    $sheets = read_xlsx($src);
    if (!$sheets) { fwrite(STDERR, "Could not read $src\n"); exit(1); }
    $file = basename($src);
    $doc = source_doc($src, $data, "$dash/timetable/docs", 'direct');
    if ($doc) $docs[$doc['id']] = $doc;

    $raw = [];
    foreach ($sheets as $tab => $rows) {
        $tab = (string) $tab;
        $class = class_label($tab);
        $k = null;          // the column holding times and row labels
        $cols = [];
        $periods = [];
        $cur = null;
        $lastEnd = null;
        foreach ($rows as $r => $row) {
            $row = array_map($tidy, $row);
            if (in_array('Sr. No.', $row, true)) {
                // The course list below the grid: no bookings, but it is where
                // the sheet spells its teachers in full ("Prof First Surname"
                // where the grid says "Prof First"). Each titled full name is
                // kept for $who to resolve a first name against.
                foreach (array_slice($rows, array_search($r, array_keys($rows), true), null, true) as $below) {
                    foreach ($below as $v) {
                        foreach (preg_split('#\s*(?:,|/|\band\b)\s*#', $tidy($v)) as $n) {
                            if (preg_match('/^(Dr|Prof|Mr|Mrs|Ms)\.?\s+[A-Z][a-z]+\s+[A-Z][a-z]+$/', $n)) $listed[$file][person_key($n)] = $n;
                        }
                    }
                }
                break;
            }
            if ($k === null) {
                foreach ($row as $c => $v) if (preg_match('#^Time\s*/\s*(Day|Date)#i', $v)) $k = $c;
                if ($k !== null) foreach ($row as $c => $v) if (in_array($v, DAYS, true)) $cols[$c] = $v;
                continue;
            }
            $label = $row[$k] ?? '';
            $lead = $k > 0 ? ($row[$k - 1] ?? '') : '';
            $isBreak = stripos($lead, 'break') !== false || stripos($row[array_key_first($cols)] ?? '', 'break') !== false;
            $slot = slot_of($label);
            if ($isBreak) {
                if ($cur) $periods[] = $cur;
                $cur = null;
                if ($slot) $lastEnd = $slot[2];
                continue;
            }
            if ($slot || preg_match('/^Lec\s*\d+/i', $lead)) {
                if ($cur) $periods[] = $cur;
                if (!$slot) {
                    $start = $lastEnd ?? 9 * 60;
                    $slot = slot_from($start, $start + 60);
                    $note("$file / $tab row " . ($r + 1) . ": no time for $lead, read as {$slot[0]}");
                }
                $lastEnd = $slot[2];
                $cur = ['slot' => $slot, 'subj' => $row, 'fac' => null, 'room' => null, 'r' => $r + 1];
                continue;
            }
            if (!$cur) continue;
            $hasData = (bool) array_filter(array_intersect_key($row, $cols), 'strlen');
            if (stripos($label, 'Faculty') === 0 || ($label === '' && $cur['fac'] === null && $hasData)) {
                if ($cur['fac'] === null) $cur['fac'] = $row;
                elseif ($hasData) $note("$file / $tab row " . ($r + 1) . ': a second faculty row under one period, ignored');
            } elseif (stripos($label, 'Room') === 0 || ($label === '' && $cur['room'] === null && $hasData)) {
                if ($cur['room'] === null) $cur['room'] = $row;
                elseif ($hasData && $cur['room'] !== $row) $note("$file / $tab row " . ($r + 1) . ': a second room row under one period, ignored');
            }
        }
        if ($cur) $periods[] = $cur;
        if ($k === null) { $note("$file / $tab: no Time / Day row, skipped"); continue; }
        $classes++;

        foreach ($periods as $p) {
            foreach ($cols as $c => $day) {
                $subj = $p['subj'][$c] ?? '';
                $fac = $p['fac'][$c] ?? '';
                $room = $p['room'][$c] ?? '';
                $fix = [];
                if (is_room($fac) && !is_room($room) && $room !== '') {
                    [$fac, $room] = [$room, $fac];
                    $note("$file / $tab row {$p['r']} $day: faculty and room were swapped");
                    $fix[] = 'The sheet has the room in the faculty row and the name in the room row; they were swapped back.';
                }
                if ($subj === '' && strcasecmp($fac, 'Minor') === 0) [$subj, $fac] = ['Minor', ''];
                if ($subj === '') continue;
                if (strcasecmp($fac, 'Faculty') === 0) $fac = '';
                // "Administrative Law Studies LLM619Ms" + "First Surname": the title slipped into the code.
                if (preg_match('/^(.*\d)(Ms|Mr|Dr)$/', $subj, $m)) {
                    $subj = $m[1];
                    if ($fac !== '' && !preg_match('/^(Dr|Mr|Ms|Mrs|Prof)\b/i', $fac)) $fac = "{$m[2]}. $fac";
                }
                $raw[] = compact('tab', 'class', 'day', 'subj', 'fac', 'room', 'fix') + ['slot' => $p['slot'], 'r' => $p['r'],
                    'src' => ['doc' => $doc['id'] ?? '', 'page' => $tab, 'day' => $day, 'start' => $p['slot'][1], 'room' => '', 'words' => [],
                        'at' => [$p['r'] - 1, $c]]];
            }
        }
    }

    // Split parallel sessions, count spellings, find each class's own room.
    foreach ($raw as $x) {
        $x['file'] = $file;
        $rooms = array_map('trim', explode('/', $x['room']));
        $facs = array_map('trim', explode('/', $x['fac']));
        $n = count($rooms);
        if ($n > 1 && count($facs) === $n) {
            // Parallel minors: one room and one teacher each, in order.
            $parts = array_map('trim', explode('/', preg_replace('/^Minor\s*:\s*/i', '', $x['subj'])));
            foreach ($rooms as $i => $rm) {
                $subj = stripos($x['subj'], 'Minor') === 0 && count($parts) === $n ? 'Minor: ' . $parts[$i] : $x['subj'];
                $split[] = ['room' => $rm, 'fac' => $facs[$i], 'subj' => $subj] + $x;
            }
            continue;
        }
        if ($n > 1) {
            $note("$file / {$x['tab']} row {$x['r']} {$x['day']}: \"{$x['room']}\" for one class, used {$rooms[0]}");
            $x['fix'][] = "The sheet gives \"{$x['room']}\" for this one class; the first, {$rooms[0]}, was used.";
            $x['room'] = $rooms[0];
        }
        $split[] = $x;
    }
}

foreach ($split as &$x) {
        $x['room'] = room_name($x['room']);
        $x['tab'] = "{$x['file']} / {$x['tab']}";
        if ($x['room'] !== '' && count(explode('/', $x['fac'])) === 1) $home[$x['tab']][$x['room']] = ($home[$x['tab']][$x['room']] ?? 0) + 1;
        foreach (explode('/', $x['fac']) as $f) if (($f = trim($f)) !== '') $inFile[$x['file']][person_key($f)] = true;
    }
    unset($x);

    // A first name alone ("Prof First") is the one full name in the same file
    // that starts with it ("Prof. First Surname"), if there is exactly one,
    // whether in the grid or only in a course list below it.
    $who = function (string $file, string $name) use (&$inFile, &$listed): string {
        $key = person_key($name);
        if (str_contains($key, ' ')) return $key;
        $known = array_keys(($inFile[$file] ?? []) + ($listed[$file] ?? []));
        $full = array_filter($known, fn($k) => str_starts_with($k, "$key "));
        return count($full) === 1 ? reset($full) : $key;
    };
    foreach ($split as $x) {
        foreach (explode('/', $x['fac']) as $f) {
            if (($f = trim($f)) === '') continue;
            $key = $who($x['file'], $f);
            $people[$key][$f] = ($people[$key][$f] ?? 0) + 1;
            // The list's own spelling competes for the fullest, used or not.
            if (isset($listed[$x['file']][$key])) $people[$key][$listed[$x['file']][$key]] ??= 0;
        }
    }

    // The fullest spelling of each person: most words of name (titles do not
    // count), then a title, then the most used.
    $spell = [];
    $words = fn($n) => count(preg_split('/\s+/', trim(preg_replace('/^(dr|prof|mr|mrs|ms)\.?\s*/i', '', $n))));
    $titled = fn($n) => (int) (bool) preg_match('/^(dr|prof|mr|mrs|ms)\b/i', $n);
    foreach ($people as $key => $ways) {
        uksort($ways, fn($a, $b) => [$words($b), $titled($b), $ways[$b]] <=> [$words($a), $titled($a), $ways[$a]]);
        $best = preg_replace('/^(Dr|Prof|Mr|Mrs|Ms)\.?\s+/i', '$1. ', array_key_first($ways));
        $spell[$key] = $best;
    }

    foreach ($split as $x) {
        // "Online" is no room at all, and in the workbooks read here directly
        // (Law, Liberal Arts: ULC 1, three-digit rooms) a one- or two-digit
        // number ("18") is none either: left out and printed, never guessed.
        // A converted file's "12" is ULC 7's 012, so it is not touched.
        if (preg_match('/^online$/i', $x['room']) || (!isset($x['building']) && preg_match('/^\d{1,2}$/', $x['room']))) {
            $note("{$x['tab']} row {$x['r']} {$x['day']}: room \"{$x['room']}\" is not a classroom, left out ({$x['subj']})");
            continue;
        }
        if ($x['room'] === '') {
            $mine = $home[$x['tab']] ?? [];
            arsort($mine);
            $guess = (string) (array_key_first($mine) ?? '');
            if ($guess === '') { $note("{$x['tab']} row {$x['r']} {$x['day']}: no room, dropped ({$x['subj']})"); continue; }
            $note("{$x['tab']} row {$x['r']} {$x['day']}: no room, guessed $guess ({$x['subj']})");
            $x['fix'][] = "The sheet gives no room for this period; the class's usual room, $guess, was used.";
            $x['room'] = $guess;
        }
        $fac = implode(' / ', array_map(fn($f) => $spell[$who($x['file'], $f)] ?? $f, array_filter(array_map('trim', explode('/', $x['fac'])), 'strlen')));
        $bookings[] = ['fac' => $fac, 'subj' => subject_name($x['subj'])] + $x;
}

// What makes two cells the same lecture, given that each tab numbers its own
// cores ("Core III : Health Psychology" on one, "Core IV: Health Psychology" on
// another) and a parallel minor is written whole on some tabs and split on
// others: a minor is a minor, whatever the tab calls it.
function lecture_key(string $subj): string {
    if (preg_match('/^minor\b/i', $subj)) return 'minor';
    $s = preg_replace('/^core\s+[ivx]+\s*:\s*/i', '', $subj);
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
}

// The ULCs the page offers. A booking in any other is written into the data
// files anyway (AIDS and CSE use ULC 5), so adding one is a change here only.
const SHOW = ['ULC 1', 'ULC 3', 'ULC 5', 'ULC 6', 'ULC 7', 'School of Design', 'Dental Building'];
$hidden = [];
$bookings = array_values(array_filter($bookings, function ($b) use (&$hidden) {
    $bld = $b['building'] ?? 'ULC 1';
    if (in_array($bld, SHOW, true)) return true;
    $hidden[$bld] = ($hidden[$bld] ?? 0) + 1;
    return false;
}));
$blocks = array_values(array_filter($blocks, fn($k) => in_array($k['building'], SHOW, true)));
$calendars = array_values(array_filter($calendars, fn($c) => in_array($c['building'], SHOW, true)));

// ULC 5's rooms are known by a letter and a number ("ULC 5C 16" is C-16), but
// some cells drop the letter ("ULC_5 16"). A bare number that appears with
// exactly one letter elsewhere in the same ULC is that lettered room.
$lettered = [];
foreach ([...$bookings, ...$blocks] as $b) {
    if (preg_match('/^([A-Z])-(\d+.*)$/', $b['room'], $lm)) $lettered[$b['building'] ?? 'ULC 1'][$lm[2]][$lm[1]] = true;
}
$letter = function (array $b) use ($lettered): array {
    $seen = $lettered[$b['building'] ?? 'ULC 1'][$b['room']] ?? [];
    if (count($seen) === 1) $b['room'] = array_key_first($seen) . '-' . $b['room'];
    return $b;
};
$bookings = array_map($letter, $bookings);
$blocks = array_map($letter, $blocks);

// The sheets' "ULC 5B" and "ULC 5C" are not wings of ULC 5: B is ULC 6 and C
// is ULC 7, so the letter only ever said the building ("ULC 5C 16" is room 16
// in ULC 7, numbered 016 like ULC 6's rooms). One room per lab, whatever a
// sheet calls it: CSE's Animation Lab is Biotechnology's Mac Lab. ULC 1's
// named labs go by their room number first, the name after ("305 (Psy Lab)"),
// and the Digital Library, the Digital Lab (DL Lab) and room 105 are one room,
// as are the Food Lab and Food Tech Lab (502). ULC 6's 104 and 105 are labs
// (First Year's CPL and CMET), and AIDS's "B 104/105 Lab" is the two together,
// so it books both.
const WING = ['B' => 'ULC 6', 'C' => 'ULC 7'];
const SAME_ROOM = ['Mac Lab' => 'Animation Lab'];
const ULC1_ROOMS = [
    'Psy Lab' => '305 (Psy Lab)',
    'DST Lab' => '501 (DST Lab)',
    'Food Lab' => '502 (Food Lab / Food Tech Lab)', 'Food Tech Lab' => '502 (Food Lab / Food Tech Lab)',
    'Digital Library' => '105 (Digital Library / Digital Lab)', 'DL Lab' => '105 (Digital Library / Digital Lab)',
    '105' => '105 (Digital Library / Digital Lab)',
];
const ULC6_LABS = ['104' => '104 Lab', '105' => '105 Lab'];
$place = function (array $b): array {
    $bld = $b['building'] ?? 'ULC 1';
    if ($bld === 'ULC 5' && preg_match('/^([A-Z])-(.+)$/', $b['room'], $wm) && isset(WING[$wm[1]])) [$bld, $b['room']] = [WING[$wm[1]], $wm[2]];
    if ($bld === 'ULC 7' && preg_match('/^\d{1,2}$/', $b['room'])) $b['room'] = str_pad($b['room'], 3, '0', STR_PAD_LEFT);
    $b['building'] = $bld;
    $b['room'] = SAME_ROOM[$b['room']] ?? $b['room'];
    if ($bld === 'ULC 1') $b['room'] = ULC1_ROOMS[$b['room']] ?? $b['room'];
    if ($bld !== 'ULC 6') return [$b];
    $each = preg_match('/^(\d{3})\/(\d{3})\s*lab$/i', $b['room'], $pm) ? [$pm[1], $pm[2]] : [$b['room']];
    return array_map(fn($r) => ['room' => ULC6_LABS[$r] ?? $r] + $b, $each);
};
$bookings = array_merge(...array_map($place, $bookings));
$blocks = array_merge(...array_map($place, $blocks));

// The same room number in two ULCs (105 is an AIDS room in ULC 1 and a lab in
// ULC 6) is two rooms: each is named with its ULC, so neither hides the other.
// The School of Design's rooms (201 to 305) share numbers with ULC 1's, so
// they always carry their building, and ULC 1's keep their plain names (and
// the links people already have to them).
// A calendar's rooms go through the same naming as a booking's.
$calRooms = fn(array $c): array => array_map(fn($r) => ['room' => (string) $r, 'building' => $c['building']], $c['rooms']);
foreach ($calendars as $i => $c) $calendars[$i]['rooms'] = array_column(array_merge(...array_map($place, $calRooms($c))), 'room');
$ulcsOf = [];
foreach ([...$bookings, ...$blocks, ...array_merge([], ...array_map($calRooms, $calendars))] as $b) $ulcsOf[$b['room']][$b['building'] ?? 'ULC 1'] = true;
$qualify = function (array $b) use ($ulcsOf): array {
    $bld = $b['building'] ?? 'ULC 1';
    $ulcs = array_filter(array_keys($ulcsOf[$b['room']]), fn($x) => str_starts_with($x, 'ULC '));
    if (str_starts_with($bld, 'ULC ') ? count($ulcs) > 1 : count($ulcsOf[$b['room']]) > 1) $b['room'] = "{$b['room']} ($bld)";
    return $b;
};
$bookings = array_map($qualify, $bookings);
$blocks = array_map($qualify, $blocks);
foreach ($calendars as $i => $c) $calendars[$i]['rooms'] = array_column(array_map($qualify, $calRooms($c)), 'room');

// One lecture typed on several classes' tabs is one booking with every class.
$merged = [];
foreach ($bookings as $b) {
    $key = implode('|', [$b['room'], $b['day'], $b['slot'][0], lecture_key($b['subj'])]);
    $from = ['fix' => $b['fix']] + $b['src'];
    if (!isset($merged[$key])) { $merged[$key] = $b + ['classes' => [$b['class']], 'facs' => [$b['fac']], 'srcs' => [$from]]; continue; }
    // Keep the most specific name: "Minor: Sociology" over "Minor :Sociology/PIR/Eco".
    if (str_contains($merged[$key]['subj'], '/') && !str_contains($b['subj'], '/')) $merged[$key]['subj'] = $b['subj'];
    $merged[$key]['classes'][] = $b['class'];
    $merged[$key]['facs'][] = $b['fac'];
    $merged[$key]['srcs'][] = $from;
}

// One teacher in one room at one hour is one session, even when two tabs give
// the course two names (BA "Essential Skills for Mental Health Practitioners"
// and MA "Skills in Counselling", one teacher in the Psy Lab): a
// combined class, so both names are kept.
$byTeacher = [];
foreach ($merged as $key => $b) {
    // "Industry faculty" is whoever the industry sends, not one person.
    if ($b['fac'] === '' || str_contains($b['fac'], ' / ') || stripos($b['fac'], 'industry') !== false) continue;
    $who = implode('|', [$b['room'], $b['day'], $b['slot'][0], $b['fac']]);
    if (!isset($byTeacher[$who])) { $byTeacher[$who] = $key; continue; }
    $into = &$merged[$byTeacher[$who]];
    if (lecture_key($into['subj']) !== lecture_key($b['subj'])) $into['subj'] .= ' / ' . $b['subj'];
    array_push($into['classes'], ...$b['classes']);
    array_push($into['facs'], ...$b['facs']);
    array_push($into['srcs'], ...$b['srcs']);
    unset($into, $merged[$key]);
}

// "BA Psychology Sem I", "BA Economics Sem I" -> "BA Sem I: Psychology, Economics"
function classes_label(array $list): string {
    $list = array_values(array_unique($list));
    if (count($list) === 1) return $list[0];
    $groups = [];
    foreach ($list as $c) {
        if (preg_match('/^(B\.Tech First Year Div) (\S+)$/', $c, $m)) $groups[$m[1]][] = $m[2];
        // "BA LLB" is one programme, not BA's LLB stream.
        elseif (preg_match('/^(BA|MA|B\.Tech) (.+) (Sem \S+)$/', $c, $m) && $m[2] !== 'LLB') $groups["{$m[1]} {$m[3]}"][] = $m[2];
        else $groups[$c] = [];
    }
    return implode('; ', array_map(fn($g, $subs) => $subs ? "$g: " . implode(', ', $subs) : $g, array_keys($groups), $groups));
}

// Where in its document each class of a booking is written: the tab and the
// cells for a workbook, the page for a PDF, and what the build had to repair.
// One entry per class, so a lecture shared by three classes cites three tabs.
$unplaced = [];
$cite = function (array $list) use ($docs, &$unplaced): array {
    $out = [];
    foreach ($list as $s) {
        $doc = $docs[$s['doc']] ?? null;
        if (!$doc) continue;
        $c = ['doc' => $doc['id'], 'tab' => '', 'page' => 0, 'cell' => null, 'fix' => implode(' ', $s['fix'] ?? [])];
        if ($doc['kind'] === 'pdf') {
            $c['page'] = (int) $s['page'];
        } else {
            $c['tab'] = source_tab($doc, $s['page']) ?? '';
            if ($c['tab'] !== '') $c['cell'] = source_cell($doc['grid'][$c['tab']], $doc['merges'][$c['tab']] ?? [], $s['day'], $s['start'], $s['room'], $s['words']);
            // A sheet read here directly knows the subject's own cell, which
            // is all there is when the period has no time label to find.
            if ($c['tab'] !== '' && $c['cell'] === null && isset($s['at'])) $c['cell'] = [$s['at'][0], $s['at'][1], $s['at'][0], $s['at'][1]];
            if ($c['cell'] === null) $unplaced[] = "{$doc['name']} / " . ($c['tab'] ?: "no tab \"{$s['page']}\"") . " {$s['day']} " . slot_from($s['start'], $s['start'] + 60)[0];
        }
        if (!in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
};

$rooms = [];
$slots = [];
$inBuilding = [];   // room => building; a room number is only ever in one
foreach ($merged as $b) {
    $bld = $b['building'] ?? 'ULC 1';
    if (isset($inBuilding[$b['room']]) && $inBuilding[$b['room']] !== $bld) {
        fwrite(STDERR, "room {$b['room']} is in both {$inBuilding[$b['room']]} and $bld: give one of them its building in the name
");
        exit(1);
    }
    $inBuilding[$b['room']] = $bld;
    [$label, $a, $z] = $b['slot'];
    $span = $b['span'] ?? 1;
    // Every hour a long booking covers is a row its room's grid draws.
    for ($i = 0; $i < $span; $i++) {
        [$l, $x, $y] = $i === 0 ? $b['slot'] : slot_from($a + 60 * $i, $a + 60 * ($i + 1));
        $slots[$l] = [$x, $y];
    }
    $facs = array_values(array_unique(array_filter($b['facs'], 'strlen')));
    $rooms[$b['room']][] = [
        'day' => $b['day'], 'time' => $label, 'span' => $span,
        'code' => $b['subj'], 'class' => classes_label($b['classes']), 'faculty' => implode(' / ', $facs),
        'classes' => array_values(array_unique($b['classes'])),
        'lab' => !empty($b['lab']) || (bool) preg_match('/\b(lab|practical)\b/i', $b['subj']), 'blocked' => '',
        'src' => $cite($b['srcs']),
    ];
}

// An outside booking never covers a real class, the generator's own rule
// (official_add_blocks): a block is cut into hours and an hour the room
// already has a class in is dropped. ECE's sheet marks the ULC-1 Computer Lab
// "SOE CSE/AIDS" all week; CSE's own timetable then says which of those hours
// are its classes, and only the rest stay "Booked".
$busy = function (string $room, string $day, int $a, int $z) use (&$rooms, &$slots): bool {
    foreach ($rooms[$room] ?? [] as $e) {
        if ($e['day'] !== $day || $e['blocked'] !== '') continue;
        [$x] = $slots[$e['time']];
        $y = max($slots[$e['time']][1], $x + 60 * $e['span']);
        if ($x < $z && $a < $y) return true;
    }
    return false;
};
$hourly = [];
foreach ($blocks as $k) {
    [, $a] = $k['slot'];
    for ($i = 0; $i < $k['span']; $i++) {
        $h = $a + 60 * $i;
        if (!$busy($k['room'], $k['day'], $h, $h + 60)) $hourly[] = ['slot' => slot_from($h, $h + 60), 'span' => 1] + $k;
    }
}
// and joined back into runs, so a free morning is one tall "Booked" cell
$blocks = [];
foreach ($hourly as $k) {
    $n = count($blocks) - 1;
    $prev = $n >= 0 ? $blocks[$n] : null;
    if ($prev && $prev['room'] === $k['room'] && $prev['day'] === $k['day'] && $prev['by'] === $k['by'] && $prev['slot'][1] + 60 * $prev['span'] === $k['slot'][1]) {
        $blocks[$n]['span']++;
    } else {
        $blocks[] = $k;
    }
}
foreach ($blocks as $k) {
    if (isset($inBuilding[$k['room']]) && $inBuilding[$k['room']] !== $k['building']) {
        fwrite(STDERR, "room {$k['room']} is in both {$inBuilding[$k['room']]} and {$k['building']}\n");
        exit(1);
    }
    $inBuilding[$k['room']] = $k['building'];
    [$label, $a] = $k['slot'];
    for ($i = 0; $i < $k['span']; $i++) {
        [$l, $x, $y] = $i === 0 ? $k['slot'] : slot_from($a + 60 * $i, $a + 60 * ($i + 1));
        $slots[$l] = [$x, $y];
    }
    $rooms[$k['room']][] = ['day' => $k['day'], 'time' => $label, 'span' => $k['span'], 'code' => '', 'class' => '',
        'faculty' => '', 'lab' => false, 'blocked' => $k['by'], 'src' => $cite([$k['src']])];
}

// The calendars, exported whole rather than as a booking per week: a booking
// each would carry the term's 20 weeks of Design on 2,000 lines and push
// rooms.php past the size the host deletes (see includes/timetable.php). Each
// session is its first hour and a span, like any long booking, and its rooms
// are rooms like any other, booked or not this week.
$calOut = [];
foreach ($calendars as $c) {
    $sessions = [];
    foreach ($c['sessions'] as [$start, $span]) {
        [$h, $m] = array_map('intval', explode('.', $start));
        $at = ($h < 8 ? $h + 12 : $h) * 60 + $m;
        for ($i = 0; $i < $span; $i++) {
            [$l, $x, $y] = slot_from($at + 60 * $i, $at + 60 * ($i + 1));
            $slots[$l] = [$x, $y];
        }
        $sessions[] = [slot_from($at, $at + 60)[0], (int) $span];
    }
    foreach ($c['rooms'] as $room) {
        if (isset($inBuilding[$room]) && $inBuilding[$room] !== $c['building']) {
            fwrite(STDERR, "room $room is in both {$inBuilding[$room]} and {$c['building']}\n");
            exit(1);
        }
        $inBuilding[$room] = $c['building'];
        $rooms[$room] ??= [];
    }
    $doc = $docs[$c['doc']] ?? null;
    $calOut[] = [
        'class' => $c['class'], 'rooms' => $c['rooms'], 'sessions' => $sessions,
        'subjects' => $c['subjects'], 'dates' => $c['dates'],
        'src' => $doc ? ['doc' => $doc['id'], 'tab' => source_tab($doc, $c['page']) ?? '', 'cells' => $c['cells']] : [],
    ];
}

// Overlaps that are not the same lecture: same room, same day, times crossing.
$clashes = [];
// Two calendars in one room on one date and session (three Fashion years share
// 205): one line per pair of classes, with how many days it happens.
$held = [];
foreach ($calOut as $c) {
    foreach ($c['dates'] as $date => $per) {
        foreach ($per as $k => $s) {
            if ($s < 0) continue;
            foreach ($c['rooms'] as $room) $held[$room][$date][$k][] = $c['class'];
        }
    }
}
$pairs = [];
foreach ($held as $room => $byDate) {
    foreach ($byDate as $date => $bySession) {
        foreach ($bySession as $who) {
            foreach ($who as $i => $a) foreach (array_slice($who, $i + 1) as $b) {
                $p = &$pairs["room $room: $a and $b"];
                $p = [($p[0] ?? 0) + 1, $p[1] ?? $date];
                unset($p);
            }
        }
    }
}
foreach ($pairs as $what => [$n, $first]) $clashes[] = "$what, $n session(s) from $first";
foreach ($rooms as $room => $list) {
    foreach ($list as $i => $e) {
        foreach (array_slice($list, $i + 1) as $f) {
            if ($e['day'] !== $f['day']) continue;
            [$a1, $z1] = $slots[$e['time']];
            [$a2, $z2] = $slots[$f['time']];
            $z1 = max($z1, $a1 + 60 * $e['span']);
            $z2 = max($z2, $a2 + 60 * $f['span']);
            $who = fn($x) => $x['blocked'] !== '' ? "booked by {$x['blocked']} {$x['time']}" : "{$x['class']} {$x['time']} ({$x['code']})";
            if ($a1 < $z2 && $a2 < $z1) $clashes[] = "room $room {$e['day']}: " . $who($e) . ' and ' . $who($f);
        }
    }
}

uasort($slots, fn($x, $y) => $x <=> $y);
$export = [
    'format' => TIMETABLE_FORMAT,
    'version' => 1,
    'exported_at' => date('Y-m-d H:i'),
    'source' => implode(' + ', array_map('basename', $sources)),
    'days' => DAYS,
    // Breaks are left out: each class has its own lunch hour, and another
    // class teaches in it, so a break row would hide real bookings.
    'slots' => array_map(fn($t) => ['time' => $t, 'break' => ''], array_keys($slots)),
    'buildings' => (function () use ($inBuilding) {
        $by = [];
        foreach ($inBuilding as $room => $bld) $by[$bld][] = (string) $room;
        // The ULCs in order, then any other building: the first is the page's default.
        uksort($by, fn($a, $b) => [!str_starts_with($a, 'ULC '), $a] <=> [!str_starts_with($b, 'ULC '), $b]);
        return $by;
    })(),
    'rooms' => $rooms,
    'calendars' => $calOut,
    'docs' => array_map(fn($d) => array_intersect_key($d, array_flip(['name', 'file', 'kind', 'how'])), $docs),
    // The Dean picker's people, kept with the data rather than in the repo.
    'deans' => json_decode((string) @file_get_contents("$data/deans.json"), true)['deans'] ?? [],
    // Hours no timetable places at a time and room, added to a person's workload.
    'extra' => json_decode((string) @file_get_contents("$data/workload-extra.json"), true)['extra'] ?? [],
];

$t = timetable_parse(json_encode($export));
if (is_string($t)) { fwrite(STDERR, "$t\n"); exit(1); }
$out = "$dash/timetable/rooms.php";
// The sources go beside it in timetable/sources.php, which is never uploaded.
if (!timetable_write_split($t, $out, "$dash/timetable/sources.php")) { fwrite(STDERR, "could not write $out\n"); exit(1); }

foreach ($hidden as $bld => $n) echo "$bld: $n booking(s) not shown, the page does not offer $bld yet\n";
echo "Repairs:\n";
foreach ($notes as $n) echo "  $n\n";
echo "\nBookings whose cell in the source could not be found (" . count($unplaced) . "):\n";
foreach (array_unique($unplaced) as $u) echo "  $u\n";
echo "\nClashes (" . count($clashes) . "):\n";
foreach ($clashes as $c) echo "  $c\n";
printf("\n%d classes, %d rooms, %d bookings (%d before merging shared lectures), %d time slots -> %s\n",
    $classes, count($t['rooms']), array_sum(array_map('count', $t['rooms'])), count($bookings), count($t['slots']), $out);
printf("%d calendars (%s), %d class days\n", count($t['calendars']), implode(', ', array_column($t['calendars'], 'class')),
    array_sum(array_map(fn($c) => count($c['dates']), $t['calendars'])));
echo 'rooms: ', implode(', ', array_map('strval', array_keys($t['rooms']))), "\n";
echo 'slots: ', implode(' | ', array_column($t['slots'], 'time')), "\n";
$who = [];
foreach ($t['rooms'] as $list) foreach ($list as $e) foreach (explode(' / ', $e['faculty']) as $f) if ($f !== '') $who[$f] = true;
foreach ($t['calendars'] as $c) foreach ($c['subjects'] as [, $fac]) foreach (explode(' / ', $fac) as $f) if ($f !== '') $who[$f] = true;
ksort($who);
echo 'faculty: ', implode(', ', array_keys($who)), "\n";
