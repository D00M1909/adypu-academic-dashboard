<?php
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';
header('Content-Type: application/json');

$school = $_GET['school'] ?? '';
$year = $_GET['year'] ?? '';
$branch = $_GET['branch'] ?? '';
$from = row_date($_GET['from'] ?? '');
$to = row_date($_GET['to'] ?? '');

if ($school === '' || $year === '' || !isset(SCHOOLS[$school])) {
    http_response_code(400);
    echo json_encode(['error' => 'valid school and year are required']);
    exit;
}

$days = get_attendance_days();
[$from, $to] = resolve_range($days, $from, $to);
$tree = get_attendance($from, $to);
$divisions = $tree[$school][$year][$branch] ?? [];
$total = attendance_totals([$school => [$year => [$branch => $divisions]]]);

// The dates behind each average. A division that reported two days of a seven
// day range must not be able to look like it reported the week.
$faculty = get_attendance_faculty();
foreach ($divisions as &$d) {
    $key = class_key([
        'school' => $school, 'year' => $year,
        'branch' => $branch, 'division' => $d['division'],
    ]);
    $d['dates'] = $d['reported'] ? class_dates($days, $key, $from, $to) : [];
    // Which lectures those readings came from. A class its faculty report after
    // each lecture is the whole point of storing a time, so the modal has to be
    // able to say so; a range from before the sheet carried a clock has none.
    $d['times'] = $d['reported'] ? class_times($days, $key, $from, $to) : [];
    // Who filed those days. Distinct names, in date order: a range can span two
    // faculty covering the same class, and a substitution is worth seeing
    // rather than flattening to whoever happened to submit last. A name from a
    // cache written before lecture times is a bare string, not a per-time map,
    // which is what the cast covers.
    $names = [];
    foreach ($d['dates'] as $date) {
        foreach ((array) ($faculty[$date][$key] ?? []) as $name) $names[] = $name;
    }
    $d['faculty'] = array_values(array_unique(array_filter($names)));
}
unset($d);

echo json_encode([
    'school' => $school,
    'schoolName' => SCHOOLS[$school]['name'],
    'year' => $year,
    'branch' => $branch,
    'divisions' => $divisions,
    'total' => $total,
    'pct' => attendance_pct($total),
    'from' => $from,
    'to' => $to,
    'rangeLabel' => range_label($from, $to),
]);
