<?php
// One uploaded audit file, to whoever may see its course file: its faculty
// member, a lead of its school, the main dean, an admin. The bytes sit behind
// the store guard in data/audit-files/, so this is the only way to them.

require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/auth.php';

auth_boot();
$me = auth_require();

$t = timetable_read();
$faculty = $t ? timetable_faculty($t) : [];
$courses = $t ? audit_courses($t, $faculty) : [];
$course = $courses[(string) ($_GET['c'] ?? '')] ?? null;
$item = (string) ($_GET['i'] ?? '');
$it = $course ? (audit_file(audit_all(), $course['id'])['items'][$item] ?? []) : [];

$bytes = null;
if ($course && audit_can_view($me, audit_person_for($me, $faculty), $course) && !empty($it['blob'])) {
    $bytes = audit_blob_read($it['blob']);
}
if ($bytes === null) {
    http_response_code(404);
    exit('Not found.');
}

$ext = (string) ($it['ext'] ?? '');
// A PDF or an image opens in the tab; anything else downloads.
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
header('Content-Type: ' . (AUDIT_TYPES[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string) $it['name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $bytes;
