<?php
// Throwaway diagnostic: can PHP on this host reach the outside world?
//
// The whole Apps Script push exists only because InfinityFree is assumed to
// block outbound HTTP. If it doesn't, the dashboard can fetch the published
// Sheet CSV itself and the push — and the bot check that keeps blocking it —
// becomes irrelevant.
//
// Upload to htdocs/, open it in a BROWSER (a browser passes the host's bot
// check), read the verdict, then DELETE IT.
//
// Deliberately conservative, after an earlier version took the site down: ONE
// target, ONE method at a time, and a 4-second timeout. When outbound is
// blocked by silently dropping packets rather than refusing the connection,
// every attempt burns its full timeout — six attempts at ten seconds each
// exceeded the host's execution limit and the whole site returned
// ERR_CONNECTION_RESET until the file was removed. Total worst case here is
// 8 seconds, which no shared host minds.

header('Content-Type: text/plain; charset=utf-8');

// One small, stable, unauthenticated target. docs.google.com is the host that
// actually matters — it serves the published Sheet CSV.
$url = 'https://docs.google.com/generate_204';
const PROBE_TIMEOUT = 4;

echo "PHP version        : ", PHP_VERSION, "\n";
echo "allow_url_fopen    : ", ini_get('allow_url_fopen') ? 'On' : 'Off', "\n";
echo "cURL extension     : ", function_exists('curl_init') ? 'available' : 'MISSING', "\n";
echo str_repeat('-', 60), "\n";
echo "Probing $url (max ", PROBE_TIMEOUT, "s each)\n\n";

$anyWorked = false;

// Method 1: file_get_contents — what includes/attendance.php actually uses.
$start = microtime(true);
$ctx = stream_context_create(['http' => ['timeout' => PROBE_TIMEOUT, 'ignore_errors' => true]]);
$body = @file_get_contents($url, false, $ctx);
$ms = round((microtime(true) - $start) * 1000);
if ($body !== false) {
    $anyWorked = true;
    echo "  file_get_contents : OK ({$ms}ms)\n";
} else {
    $err = error_get_last()['message'] ?? 'no detail';
    echo "  file_get_contents : FAILED ({$ms}ms) — ", trim($err), "\n";
}

// Method 2: cURL — sometimes allowed where the fopen wrappers are not.
if (function_exists('curl_init')) {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => PROBE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => PROBE_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $out = curl_exec($ch);
    $ms = round((microtime(true) - $start) * 1000);
    if ($out !== false) {
        $anyWorked = true;
        echo "  cURL              : OK ({$ms}ms, HTTP ", curl_getinfo($ch, CURLINFO_HTTP_CODE), ")\n";
    } else {
        echo "  cURL              : FAILED ({$ms}ms) — ", curl_error($ch), "\n";
    }
    curl_close($ch);
}

echo "\n", str_repeat('=', 60), "\n";
echo $anyWorked
    ? "VERDICT: outbound works. The dashboard can pull the Sheet itself —\n"
    . "         send this output back and we drop the Apps Script push.\n"
    : "VERDICT: outbound is blocked, as assumed. Keep the push, or move hosts.\n";
echo str_repeat('=', 60), "\nDelete this file when you're done.\n";
