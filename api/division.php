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
foreach ($divisions as &$d) {
    $d['dates'] = $d['reported']
        ? class_dates($days, class_key([
            'school' => $school, 'year' => $year,
            'branch' => $branch, 'division' => $d['division'],
        ]), $from, $to)
        : [];
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
