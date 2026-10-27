<?php
// The attendance report (report.php): every class in the schools and years
// picked, one row each, year by year. A head of school reads it top down, so
// the order is the report's whole point: 1st Year before 2nd, and inside a
// year the classes that filed before the ones that did not.

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/structure.php';
require_once __DIR__ . '/timetable.php';

// "1st Year" -> 1, so years sort as numbers and "10th" could never land
// before "2nd".
function report_year_rank(string $year): int {
    return (int) $year ?: 99;
}

// One row per class in $rows, with what the range says about it, ordered
// year, then school (SCHOOLS order), then the structure's own order. $tree is
// aggregate_days() over the same rows and range; $faculty is
// get_attendance_faculty(); $due is report_due_faculty().
function report_rows(array $rows, array $tree, array $days, array $faculty, string $from, string $to, array $due = []): array {
    $schoolRank = array_flip(array_keys(SCHOOLS));
    $out = [];
    foreach ($rows as $i => $c) {
        $key = class_key($c);
        $d = null;
        foreach ($tree[$c['school']][$c['year']][$c['branch']] ?? [] as $x) {
            if ($x['division'] === $c['division']) { $d = $x; break; }
        }
        $readings = !empty($d['reported']) ? class_readings($days, $faculty, $key, $from, $to) : [];
        $names = [];
        foreach ($readings as $r) {
            if ($r['faculty'] !== '') $names[timetable_person($r['faculty'])] ??= $r['faculty'];
        }
        $out[] = [
            'key' => $key,
            'school' => $c['school'],
            'year' => $c['year'],
            'branch' => $c['branch'],
            'division' => $c['division'],
            'strength' => (int) $c['strength'],
            'estimated' => class_estimated($c),
            'reported' => !empty($d['reported']),
            'present' => (int) ($d['present'] ?? 0),
            'days' => (int) ($d['days'] ?? 0),
            'readings' => $readings,
            'filed_by' => array_values($names),
            'due' => $due[$key] ?? [],
            'order' => [report_year_rank($c['year']), $schoolRank[$c['school']] ?? 99, $i],
        ];
    }
    usort($out, fn($a, $b) => $a['order'] <=> $b['order']);
    return $out;
}

// Who the timetable says teaches each class on the days in [$from, $to]:
// class key => [['name', 'time' (minutes), 'code'], ...], earliest lecture
// first, each person once. This is who to ask about a class that has not
// filed. Read week by week, because Design's calendars are dated and only
// timetable_for_week() lays them out; a range longer than six weeks reads
// only its last six, which a report about who has not filed never needs.
//
// A class only the structure knows (no timetable line maps to it, see
// timetable_attendance_classes()) is simply absent here.
function report_due_faculty(array $t, array $rows, string $from, string $to): array {
    if (!$t) return [];
    $tz = new DateTimeZone('Asia/Kolkata');
    $first = new DateTimeImmutable($from, $tz);
    $last = new DateTimeImmutable($to, $tz);
    if ($last->diff($first)->days > 42) $first = $last->modify('-41 days');
    $map = [];
    $resolve = [];
    for ($monday = $first->modify('monday this week'); $monday <= $last; $monday = $monday->modify('+7 days')) {
        $week = timetable_for_week($t, $monday);
        $entries = $week['extra'] ?? [];
        foreach ($week['rooms'] as $list) foreach ($list as $e) $entries[] = $e;
        foreach ($entries as $e) {
            if (($e['blocked'] ?? '') !== '' || trim((string) $e['faculty']) === '') continue;
            $i = array_search($e['day'], $week['days'], true);
            if ($i === false) continue;
            $date = $monday->modify("+$i days");
            if ($date < $first || $date > $last) continue;
            $m = timetable_minutes((string) $e['time']);
            $labels = $e['classes'] ?: [$e['class']];
            foreach ($labels as $label) {
                $resolve[$label] ??= array_map('class_key', timetable_attendance_classes((string) $label, $rows));
                foreach ($resolve[$label] as $key) {
                    foreach (preg_split('#\s*/\s*#', (string) $e['faculty']) as $name) {
                        $name = trim(preg_replace('/\s+/', ' ', $name));
                        $who = timetable_person($name);
                        if (!preg_match('/[a-z]/', $who) || preg_match('/\bfaculty\b/', $who)) continue;
                        $at = $m ? $m[0] : 24 * 60;
                        if (!isset($map[$key][$who]) || $at < $map[$key][$who]['time']) {
                            $map[$key][$who] = ['name' => $name, 'time' => $at, 'code' => (string) $e['code']];
                        }
                    }
                }
            }
        }
    }
    foreach ($map as $key => $people) {
        usort($people, fn($a, $b) => $a['time'] <=> $b['time']);
        $map[$key] = $people;
    }
    return $map;
}
