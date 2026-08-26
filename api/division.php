<?php
require_once __DIR__ . '/../includes/attendance.php';
header('Content-Type: application/json');

$school = $_GET['school'] ?? '';
$year = $_GET['year'] ?? '';
$refresh = isset($_GET['refresh']);

if ($school === '' || $year === '' || !isset(SCHOOLS[$school])) {
    http_response_code(400);
    echo json_encode(['error' => 'valid school and year are required']);
    exit;
}

$tree = get_attendance($refresh);
$divisions = $tree[$school][$year] ?? [];
$total = attendance_totals([$school => [$year => $divisions]]);

echo json_encode([
    'school' => $school,
    'schoolName' => SCHOOLS[$school]['name'],
    'year' => $year,
    'divisions' => $divisions,
    'total' => $total,
    'date' => date('d M Y'),
]);
