<?php
// Server-side state that must never be fetchable over HTTP: faculty password
// hashes, student names, roll numbers. cache/attendance.json is deliberately
// browsable (it is step 3 of "the data isn't showing"); nothing in here may be.
//
// Every file is JSON behind a PHP guard line, so a browser asking for
// /data/faculty.php runs it, hits exit, and gets a blank page. Deliberately not
// an .htaccess rule: a rule this host silently ignored would leak student names
// with no way to notice from the outside, and this host has form for silently
// ignoring things. The guard cannot be bypassed by any server that runs PHP at
// all, which is the only kind of server this app is ever deployed to.
//
// Read one from a shell with:  tail -c +16 data/faculty.php | jq

const STORE_GUARD = "<?php exit; ?>\n";

// Overridable two ways, both so a test or a one-off import can write somewhere
// that is not the live data directory: define it before this file loads, or set
// ADYPU_DATA_DIR in the environment for a command-line tool.
defined('STORE_DIR') || define('STORE_DIR', getenv('ADYPU_DATA_DIR') ?: __DIR__ . '/../data');

function store_path(string $name): string {
    // Every name is a literal in our own source, never a request parameter, but
    // a traversal here would read or write anywhere on the host, so the shape
    // is enforced rather than assumed.
    if (!preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $name) || str_contains($name, '..')) {
        throw new InvalidArgumentException("bad store name: $name");
    }
    return STORE_DIR . '/' . $name . '.php';
}

// Tolerates a file with no guard line as well as one with it, so a hand-edited
// file is still readable rather than silently empty.
function store_decode(string $raw): ?array {
    if (str_starts_with($raw, '<?php')) {
        $nl = strpos($raw, "\n");
        $raw = $nl === false ? '' : substr($raw, $nl + 1);
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function store_read(string $name): array {
    $path = store_path($name);
    if (!is_file($path)) return [];
    // Shared lock, because store_update() truncates in place: an unlocked read
    // can land mid-write and decode to nothing. A dashboard that drops every
    // app submission for exactly one request is a bug nobody would reproduce.
    $fh = @fopen($path, 'r');
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $raw = (string) stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return store_decode($raw) ?? [];
}

// Read, mutate and write under one exclusive lock. Twenty faculty submitting at
// 09:55 is the normal morning, not an edge case, and a plain read-then-write
// keeps only the last of them.
function store_update(string $name, callable $mutate): bool {
    $path = store_path($name);
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    $fh = @fopen($path, 'c+');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
    $data = $mutate(store_decode((string) stream_get_contents($fh)) ?? []);
    rewind($fh);
    ftruncate($fh, 0);
    $ok = fwrite($fh, STORE_GUARD . json_encode($data)) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}
