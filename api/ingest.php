<?php
// Receives the Form's response sheet, pushed by the Apps Script in
// tools/apps-script.gs. The whole sheet arrives every time, not a delta.

require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST only');
}

if (!defined('INGEST_SECRET') || INGEST_SECRET === '') {
    fail(503, 'INGEST_SECRET is not configured on this server');
}

$given = $_SERVER['HTTP_X_INGEST_SECRET'] ?? '';
if (!hash_equals(INGEST_SECRET, $given)) {
    fail(403, 'bad secret');
}

$csv = file_get_contents('php://input');
if ($csv === false || trim($csv) === '') {
    fail(400, 'empty body');
}

$rows = parse_attendance_csv($csv, $skipped);
if (!$rows) {
    fail(422, 'no valid rows parsed - the sheet needs a column whose name starts with "Present", and Class values that match the form options');
}

$db = get_db();
$stmt = $db->prepare("
  INSERT INTO attendance_records
    (school_id, year_label, branch, division, record_date, strength, present)
  VALUES (?, ?, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    strength = VALUES(strength),
    present = VALUES(present),
    submitted_at = CURRENT_TIMESTAMP
");

if (!$stmt) {
    fail(500, 'could not prepare attendance history storage');
}

foreach ($rows as $r) {
    $stmt->bind_param(
        'sssssii',
        $r['school'],
        $r['year'],
        $r['branch'],
        $r['division'],
        $r['date'],
        $r['strength'],
        $r['present']
    );
    if (!$stmt->execute()) {
        fail(500, 'could not save attendance history');
    }
}

$tree = aggregate_attendance($rows);

if (!is_dir(dirname(ATTENDANCE_CACHE_FILE))) {
    @mkdir(dirname(ATTENDANCE_CACHE_FILE), 0775, true);
}
if (@file_put_contents(ATTENDANCE_CACHE_FILE, json_encode($tree)) === false) {
    fail(500, 'could not write the cache file - check that cache/ is writable');
}

$totals = attendance_totals($tree);
echo json_encode([
    'ok'       => true,
    'rows'     => count($rows),
    'schools'  => count($tree),
    'present'  => $totals['present'],
    'strength' => $totals['strength'],
    'reported' => $totals['reported'],
    'classes'  => $totals['classes'],
    // Rows the Form holds but the structure doesn't recognise. Echoed back so
    // the Apps Script log names them instead of them just not being counted.
    'skipped'  => array_values(array_unique($skipped)),
]);

