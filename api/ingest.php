<?php
// Receives the Form's response sheet, pushed by the Apps Script in
// tools/apps-script.gs. Google pushes to us rather than us pulling from Google
// because InfinityFree's free plan blocks outbound HTTP from PHP — inbound is
// fine (SPEC.md §7.1).
//
// Contract: POST, body is the whole sheet as CSV text, secret in the
// X-Ingest-Secret header. Responds 200 with the row/class counts it stored.
//
// The whole sheet arrives every time, not a delta — it's a few thousand rows at
// most, and a full replace means a corrected row in Google always wins here.

require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

function fail(int $code, string $msg): never {
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

// hash_equals, not ===, so a wrong secret can't be recovered by timing.
$given = $_SERVER['HTTP_X_INGEST_SECRET'] ?? '';
if (!hash_equals(INGEST_SECRET, $given)) {
    fail(403, 'bad secret');
}

$csv = file_get_contents('php://input');
if ($csv === false || trim($csv) === '') {
    fail(400, 'empty body');
}

$rows = parse_attendance_csv($csv);
if (!$rows) {
    // Never overwrite good data with nothing. An empty parse means the sheet
    // was cleared, its headers were renamed, or every row named a class that
    // doesn't exist — all of which are mistakes, not "today nobody attended".
    fail(422, 'no valid rows parsed — the sheet needs a column whose name starts with "Present", and Class values that match the form options');
}

$tree = aggregate_attendance($rows);

if (!is_dir(dirname(ATTENDANCE_CACHE_FILE))) {
    @mkdir(dirname(ATTENDANCE_CACHE_FILE), 0775, true);
}
if (@file_put_contents(ATTENDANCE_CACHE_FILE, json_encode($tree)) === false) {
    fail(500, 'could not write the cache file — check that cache/ is writable');
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
]);
