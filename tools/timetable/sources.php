<?php
// Where each booking came from, for the room page's source panel: which of the
// documents the schools sent, which tab or page of it, and which cells. The
// build calls source_doc() once per source and source_cell() once per booking.
//
// The documents themselves are copied into timetable/docs/ beside rooms.php
// (gitignored like it: they carry faculty names), and a workbook is also saved
// as its cells, so source.php can draw the tab and mark the class without
// reading an .xlsx on the server.

// Each source the build reads => [id, the file in data/sources/ the school
// sent]. A transcription is keyed by its name without the date, so the next
// dated version of it still finds its document.
const SOURCE_DOCS = [
    'School of Law - Timetable 2026-27.xlsx' => ['law', 'School of Law - Timetable 2026-27.xlsx'],
    'School of Liberal Arts - Timetable 2026-27.xlsx' => ['sola', 'School of Liberal Arts - Timetable 2026-27.xlsx'],
    'aids' => ['eng-aids', 'School of Engineering - AIDS Timetable 2026-27.xlsx'],
    'biomedical' => ['eng-biomedical', 'School of Engineering - Biomedical Timetable 2026-27.xlsx'],
    'biotech' => ['eng-biotech', 'School of Engineering - Biotechnology Timetable 2026-27.pdf'],
    'btech-cse' => ['eng-cse-final', 'School of Engineering - CSE Final Year Timetable 2026-27.xlsx'],
    'sy-cse' => ['eng-cse-sy', 'School of Engineering - CSE SY Timetable 2026-27.xlsx'],
    'ty-cse' => ['eng-cse-ty', 'School of Engineering - CSE TY Timetable 2026-27.xlsx'],
    'civil' => ['eng-civil', 'School of Engineering - Civil Timetable 2026-27.xlsx'],
    'ece' => ['eng-ece', 'School of Engineering - ECE Timetable 2026-27.xlsx'],
    'firstyear' => ['eng-firstyear', 'School of Engineering - First Year Timetable 2026-27.pdf'],
    'mechanical' => ['eng-mech', 'School of Engineering - Mechanical, AE and Robotics Timetable 2026-27.xlsx'],
    'management' => ['mgmt', 'School of Management - Timetable 2026-27.xlsx'],
    'design-foundation' => ['des-foundation', 'School of Design - Foundation Timetable 2026-27.xlsx'],
    'design-td' => ['des-td', 'School of Design - Transportation Design Timetable 2026-27.xlsx'],
    'design-uiux' => ['des-uiux', 'School of Design - UI-UX Design Timetable 2026-27.xlsx'],
    'design-fashion' => ['des-fashion', 'School of Design - Fashion Design Timetable 2026-27.xlsx'],
    'design-product' => ['des-product', 'School of Design - Product Design Timetable 2026-27.xlsx'],
    'design-vcd' => ['des-vcd', 'School of Design - Visual Communication Design Timetable 2026-27.xlsx'],
];

// The document behind one source path, copied into $out, as the entry the
// export's 'docs' list carries: ['id', 'name', 'file', 'kind', 'how'], plus
// 'grid' and 'merges' per tab for a workbook (dropped before export). Null for
// a source with no document on file, whose bookings then show no source.
function source_doc(string $src, string $data, string $out, string $how): ?array {
    $key = preg_replace('/-\d{4}-\d{2}-\d{2}\.json$/', '', basename($src));
    if (!isset(SOURCE_DOCS[$key])) return null;
    [$id, $name] = SOURCE_DOCS[$key];
    $path = "$data/sources/$name";
    if (!is_file($path)) return null;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $doc = ['id' => $id, 'name' => $name, 'file' => "docs/$id.$ext", 'kind' => $ext === 'pdf' ? 'pdf' : 'sheet', 'how' => $how];
    // Rewritten only when it changed, so the file's date says when the school's
    // document last did, and stage-upload.sh can leave an unchanged one behind.
    source_put("$out/$id.$ext", (string) file_get_contents($path));
    if ($doc['kind'] === 'sheet') {
        $doc['grid'] = read_xlsx($path);
        $doc['merges'] = read_xlsx_merges($path);
        $tabs = [];
        foreach ($doc['grid'] as $tab => $rows) $tabs[] = ['name' => (string) $tab, 'rows' => source_rows($rows), 'merges' => $doc['merges'][$tab] ?? []];
        source_put("$out/$id.php", STORE_GUARD . json_encode(['name' => $name, 'tabs' => $tabs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    return $doc;
}

// [row => [col => value]] as JSON objects keep it: lists only where the keys
// already are 0..n, so "row 12, column D" survives the trip.
function source_rows(array $rows): array {
    $out = [];
    foreach ($rows as $r => $cols) $out[(string) $r] = array_map('strval', array_combine(array_map('strval', array_keys($cols)), $cols));
    return $out;
}

function source_put(string $path, string $bytes): void {
    if (@file_get_contents($path) === $bytes) return;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, $bytes, LOCK_EX);
}

// A tab named by a transcription ("SY AIDS A") is the workbook's "SY- AIDS-A":
// they were typed by different people, so only letters and digits count.
function source_tab(array $doc, string $page): ?string {
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    foreach (array_keys($doc['grid']) as $tab) if ($norm($tab) === $norm($page)) return (string) $tab;
    return null;
}

// Minutes from midnight a time label starts at ("09.30 to 10.30", "8.00 am to
// 9.00", "08:30-09:30", ECE's "1 09:30–10:30"), or null. Hours before 8 are
// afternoon, as everywhere else in these sheets.
function source_minutes(string $v): ?int {
    if (!preg_match('/^\s*(?:\d\s+)?(\d{1,2})[.:](\d{2})\s*(?:[ap]\.?m\.?)?\s*(?:to|-|–)/iu', $v, $m)) return null;
    $h = (int) $m[1];
    return ($h < 8 ? $h + 12 : $h) * 60 + (int) $m[2];
}

// The cells one booking occupies in one tab, as [first row, first col, last
// row, last col] (0-based), or null when the tab cannot be read that way. The
// sheets come in three shapes, all handled by finding the day's label and the
// time's label and reading where their row and column cross:
//   - days across, times down (nearly all): the period's rows under the day,
//     which is the subject, faculty and room together;
//   - days down, times across (Civil): the day's rows under the time, narrowed
//     to the row naming the subject when there are two classes in one sheet;
//   - days across, then periods across under each day, rooms down (ECE's
//     master): the period's column on the room's row.
// $words are the subject's code and name, used only to pick between rows.
function source_cell(array $grid, array $merges, string $day, int $start, string $room = '', array $words = []): ?array {
    $days = [];
    $times = [];
    foreach ($grid as $r => $row) {
        foreach ($row as $c => $v) {
            if (preg_match('/^(mon|tue|wed|thu|fri)[a-z]*\.?$/i', trim($v), $m)) {
                $days[] = [$r, $c, ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday'][strtolower($m[1])]];
            } elseif (($t = source_minutes($v)) !== null) {
                $times[] = [$r, $c, $t];
            }
        }
    }
    $mine = array_values(array_filter($days, fn($d) => $d[2] === $day));
    $when = array_values(array_filter($times, fn($t) => $t[2] === $start));
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    // A short code has to be a word of its own: "SD" is inside "MOS(DAJ)".
    $says = function (int $r, int $c) use ($grid, $words, $norm): bool {
        $text = $grid[$r][$c] ?? '';
        $tokens = array_map($norm, preg_split('/[^a-z0-9]+/i', $text));
        foreach ($words as $w) {
            if (($w = $norm($w)) === '') continue;
            if (strlen($w) <= 4 ? in_array($w, $tokens, true) : str_contains($norm($text), $w)) return true;
        }
        return false;
    };
    // The next label after $at along a row or a column, or $at + $cap.
    $next = function (array $labels, int $at, int $line, bool $down, int $cap) {
        $n = $at + $cap;
        foreach ($labels as $l) {
            [$lr, $lc] = $l;
            if ($down && $lc === $line && $lr > $at) $n = min($n, $lr);
            if (!$down && $lr === $line && $lc > $at) $n = min($n, $lc);
        }
        return $n - 1;
    };
    $widen = function (array $box) use ($merges): array {
        foreach ($merges as [$r1, $c1, $r2, $c2]) {
            if ($box[0] >= $r1 && $box[0] <= $r2 && $box[1] >= $c1 && $box[1] <= $c2) {
                $box = [min($box[0], $r1), min($box[1], $c1), max($box[2], $r2), max($box[3], $c2)];
            }
        }
        return $box;
    };

    // A header's first day column: a time label left of it labels rows, one
    // under it (ECE's periods) labels a column.
    $first = [];
    foreach ($days as [$dr, $dc]) $first[$dr] = min($first[$dr] ?? PHP_INT_MAX, $dc);
    foreach ($when as [$tr, $tc]) {
        // Days across, times down: the nearest day header above the time.
        $head = null;
        foreach ($mine as $d) if ($d[0] < $tr && $first[$d[0]] > $tc && (!$head || $d[0] > $head[0])) $head = $d;
        if ($head) {
            $last = $next($times, $tr, $tc, true, 4);
            $right = $next($days, $head[1], $head[0], false, 1);
            return $widen([$tr, $head[1], $last, max($head[1], $right)]);
        }
    }
    foreach ($when as [$tr, $tc]) {
        // Days down, times across.
        foreach ($mine as [$dr, $dc]) {
            if ($dr <= $tr || $dc >= $tc) continue;
            $last = $next($days, $dr, $dc, true, 4);
            for ($r = $dr; $r <= $last; $r++) if ($says($r, $tc)) return $widen([$r, $tc, $r, $tc]);
            return $widen([$dr, $tc, $last, $tc]);
        }
    }
    // Days across with periods under them, rooms down. The day is found by
    // counting blocks of periods along the row (period 1 again starts the
    // next day), because ECE's sheet only labels the first three days.
    $rowsOfTimes = [];
    foreach ($times as [$tr, $tc, $t]) $rowsOfTimes[$tr][$tc] = $t;
    $want = array_search($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], true);
    foreach ($rowsOfTimes as $tr => $cols) {
        if (count($cols) < 10) continue;
        ksort($cols);
        $block = 0;
        $prev = null;
        $col = null;
        foreach ($cols as $c => $t) {
            if ($prev !== null && $t < $prev) $block++;
            $prev = $t;
            if ($block === $want && $t === $start) { $col = $c; break; }
        }
        if ($col === null) continue;
        $label = min(array_keys($cols));
        $end = $norm($room);
        foreach ($grid as $r => $row) {
            if ($r <= $tr || $end === '') continue;
            foreach ($row as $c => $v) if ($c < $label && str_ends_with($norm($v), $end)) return $widen([$r, $col, $r, $col]);
        }
        foreach ($grid as $r => $row) if ($r > $tr && $says($r, $col)) return $widen([$r, $col, $r, $col]);
    }
    return null;
}
