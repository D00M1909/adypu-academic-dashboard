<?php
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/structure.php';
require_once __DIR__ . '/../includes/traffic.php';
traffic_hit('class');
header('Content-Type: application/json');

// The breakdown behind whatever the headline number is showing. Every level is
// optional: no school is the whole view, a school with no year is that school,
// and so on down. `branch` absent means every branch; `branch=` (empty) is the
// real key of a branchless school, so the two are not the same request.
$school = $_GET['school'] ?? '';
$year = $_GET['year'] ?? '';
$branch = $_GET['branch'] ?? null;
$from = row_date($_GET['from'] ?? '');
$to = row_date($_GET['to'] ?? '');

$partner = isset(partner_groups()[$school]) || ($school === '' && ($_GET['view'] ?? '') === 'partners');
if (($school !== '' && !isset(SCHOOLS[$school]) && !$partner) || ($school === '' && $year !== '')) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown school, or a year without a school']);
    exit;
}

$days = get_attendance_days();
[$from, $to] = resolve_range($days, $from, $to);
$tree = $partner ? aggregate_days($days, $from, $to, partner_rows()) : get_attendance($from, $to);

$scope = [];
foreach ($tree as $sid => $years) {
    if ($school !== '' && $sid !== $school) continue;
    foreach ($years as $y => $branches) {
        if ($year !== '' && $y !== $year) continue;
        foreach ($branches as $b => $divs) {
            if ($branch !== null && $b !== $branch) continue;
            $scope[$sid][$y][$b] = $divs;
        }
    }
}
$total = attendance_totals($scope);

// One branch lists every division, reported or not: six rows, and the silent
// ones are the point. Anything wider lists only the classes that reported,
// each with the path the scope leaves out, and the total line carries how
// many did not: 134 "not reported" rows would bury the two that did.
$single = $school !== '' && $year !== '' && $branch !== null;
$faculty = get_attendance_faculty();
$divisions = [];
foreach ($scope as $sid => $years) {
    foreach ($years as $y => $branches) {
        foreach ($branches as $b => $divs) {
            foreach ($divs as $d) {
                if (!$single && !$d['reported']) continue;
                $key = class_key(['school' => $sid, 'year' => $y, 'branch' => $b, 'division' => $d['division']]);
                // Every entry behind the number: day, lecture, count, who filed
                // it. The modal lists them all under the division and derives
                // its own chips (how many days, which slots, which faculty)
                // from this one list, so nothing on screen can disagree.
                $d['readings'] = $d['reported'] ? class_readings($days, $faculty, $key, $from, $to) : [];
                if (!$single) {
                    $d['path'] = array_values(array_filter([
                        $school === '' ? preg_replace('/^School of /', '', group_name($sid)) : '',
                        $year === '' ? $y : '',
                        $b,
                    ], 'strlen'));
                }
                $divisions[] = $d;
            }
        }
    }
}

echo json_encode([
    'school' => $school,
    'schoolName' => $school === '' ? ($partner ? 'All partners' : 'All schools') : group_name($school),
    'year' => $year,
    'branch' => $branch ?? '',
    'single' => $single,
    'divisions' => $divisions,
    'total' => $total,
    'pct' => attendance_pct($total),
    'from' => $from,
    'to' => $to,
    'rangeLabel' => range_label($from, $to),
]);
