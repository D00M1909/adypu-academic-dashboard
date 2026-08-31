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

$rows = parse_attendance_csv($csv, $skipped);
if (!$rows) {
    // Never overwrite good data with nothing. An empty parse means the sheet
    // was cleared, its headers were renamed, or every row named a class that
    // doesn't exist — all of which are mistakes, not "today nobody attended".
    fail(422, 'no valid rows parsed — the sheet needs a column whose name starts with "Present", and Class values that match the form options');
}


// One present count per class per day (includes/attendance.php day_map), so
// the dashboard can rebuild any date range from this file. A full replace,
// not a merge: the whole sheet arrives every time, so a row corrected in
// Google always wins here, and a row deleted there disappears here.
$days = day_map($rows);

if (!is_dir(dirname(ATTENDANCE_CACHE_FILE))) {
    @mkdir(dirname(ATTENDANCE_CACHE_FILE), 0775, true);
}
$payload = ['days' => $days, 'faculty' => faculty_map($rows)];
if (@file_put_contents(ATTENDANCE_CACHE_FILE, json_encode($payload)) === false) {
    fail(500, 'could not write the cache file — check that cache/ is writable');
}

// Reported over the default view's range (the newest day with data), which is
// what the dashboard will actually show, so the push log and the site agree.
[$from, $to] = resolve_range($days);
$totals = attendance_totals(aggregate_days($days, $from, $to));
echo json_encode([
    'ok'       => true,
    'rows'     => count($rows),
    'days'     => count($days),
    'latest'   => $to,
    'present'  => $totals['present'],
    'strength' => $totals['strength_reported'],
    'reported' => $totals['reported'],
    'classes'  => $totals['classes'],
    // Rows the Form holds but the structure doesn't recognise. Echoed back so
    // the Apps Script log names them instead of them just not being counted.
    'skipped'  => array_values(array_unique($skipped)),
]);
