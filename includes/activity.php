<?php
// What every account does on the site, for the admin Activity page: each
// sign-in and failed sign-in, every page a signed-in person opens, every
// attendance save (and whose save it replaced), every password change and
// every admin action, with the time, address and device it came from.
//
// Only accounts are followed. The public dashboard's visitors stay the
// anonymous one-day hashes of includes/traffic.php: a log of the public
// would be a log of students and parents, which nobody asked for. A failed
// sign-in is logged under the email typed, account or not, since a run of
// them is exactly what this is for.
//
// One file a day, data/activity/YYYY-MM-DD.php, behind store.php's guard
// line, one JSON event per line, appended. Not store_update(): that rewrites
// the whole file under one lock, and a page view per request from every
// signed-in teacher at 09:55 would queue on it. Appending a line is one
// write whatever the size of the day.

require_once __DIR__ . '/store.php';

// A year of days, then the oldest go. Tens of KB a day at today's usage.
const ACTIVITY_KEEP_DAYS = 365;

// What the page calls each kind of event.
const ACTIVITY_TYPES = [
    'signin'        => 'Signed in',
    'resume'        => 'Came back (remembered)',
    'signin_failed' => 'Failed sign-in',
    'signout'       => 'Signed out',
    'signup'        => 'Created account',
    'view'          => 'Opened page',
    'mark'          => 'Saved attendance',
    'mark_failed'   => 'Save refused',
    'password'      => 'Changed password',
    'admin'         => 'Admin action',
];

function activity_path(string $day): string {
    return store_path('activity/' . $day);
}

// "Android, Chrome": enough to tell a phone from the office PC and to notice
// a new device on an account, without keeping a fingerprint to compare.
function activity_device(string $ua): string {
    $os = match (true) {
        (bool) preg_match('/Android/i', $ua) => 'Android',
        (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iPhone',
        (bool) preg_match('/Windows/i', $ua) => 'Windows',
        (bool) preg_match('/Macintosh|Mac OS/i', $ua) => 'Mac',
        (bool) preg_match('/CrOS/i', $ua) => 'Chromebook',
        (bool) preg_match('/Linux/i', $ua) => 'Linux',
        default => 'Unknown',
    };
    $browser = match (true) {
        (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
        (bool) preg_match('/Edg\//i', $ua) => 'Edge',
        (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
        (bool) preg_match('/Firefox|FxiOS/i', $ua) => 'Firefox',
        (bool) preg_match('/Chrome|CriOS/i', $ua) => 'Chrome',
        (bool) preg_match('/Safari/i', $ua) => 'Safari',
        default => 'Other',
    };
    return "$os, $browser";
}

// Appends one event. $who is the account's email ('' for nobody signed in);
// $detail is whatever that kind of event carries. Swallows every failure: a
// log that cannot be written must never cost the save it was recording.
function activity_log(string $type, string $who, array $detail = [], ?int $at = null): void {
    try {
        $at ??= time();
        $day = date('Y-m-d', $at);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $event = [
            't' => date('H:i:s', $at),
            'who' => $who,
            'type' => $type,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'device' => activity_device($ua),
            'ua' => substr($ua, 0, 300),
            // Which sitting it was: a short hash of the session id, so a day's
            // events group into visits without the id itself being on disk.
            'sid' => session_status() === PHP_SESSION_ACTIVE ? substr(hash('sha256', session_id()), 0, 8) : '',
        ];
        $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($fwd !== '') $event['fwd'] = substr($fwd, 0, 100);
        if ($detail) $event['d'] = $detail;
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) return;

        $path = activity_path($day);
        $new = !is_file($path);
        if ($new && !is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        $fh = @fopen($path, 'a');
        if (!$fh) return;
        if (flock($fh, LOCK_EX)) {
            // The guard line goes in first, under the lock, so two first
            // events of a day cannot both write it.
            clearstatcache(true, $path);
            if (filesize($path) === 0) fwrite($fh, STORE_GUARD);
            fwrite($fh, $line . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        if ($new) activity_prune($day);
    } catch (Throwable $e) {
        // The log is a record of the work, never the work.
    }
}

// Once a day, on its first event: days past ACTIVITY_KEEP_DAYS are deleted.
function activity_prune(string $today): void {
    $cut = date('Y-m-d', strtotime("$today -" . ACTIVITY_KEEP_DAYS . ' days'));
    foreach (glob(STORE_DIR . '/activity/*.php') ?: [] as $f) {
        if (basename($f, '.php') < $cut) @unlink($f);
    }
}

// One day's events, oldest first, each with its 'day' added.
function activity_day(string $day): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return [];
    $fh = @fopen(activity_path($day), 'r');
    if (!$fh) return [];
    flock($fh, LOCK_SH);
    $out = [];
    while (($line = fgets($fh)) !== false) {
        if (str_starts_with($line, '<?php')) continue;
        $e = json_decode($line, true);
        if (is_array($e) && isset($e['type'])) $out[] = ['day' => $day] + $e;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $out;
}

// Every event from $from to $to, both inclusive, oldest first.
function activity_range(string $from, string $to): array {
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        array_push($out, ...activity_day($d));
    }
    return $out;
}

// The current request's signed-in account, or ''. Read straight from the
// session, so logging a page view costs no read of the account file.
function activity_who(): string {
    return session_status() === PHP_SESSION_ACTIVE ? (string) ($_SESSION['email'] ?? '') : '';
}

// A page opened by a signed-in account. Called by traffic_hit(), which every
// page already calls once; the address is kept with its query, since which
// class or room was opened is the part worth knowing.
function activity_view(string $page): void {
    $who = activity_who();
    if ($who === '' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    activity_log('view', $who, ['page' => $page, 'url' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300)]);
}

// Per account, over a list of events: what the Activity page's table shows.
// email => ['events', 'views', 'signins', 'failed', 'marks', 'overwrites',
// 'classes' (set), 'days' (set of active days), 'first', 'last' ("day H:i:s"),
// 'ips' (set), 'devices' (set), 'late' (saves filed over a day after the
// lecture), 'backdated' (saves for a date before the day filed)].
function activity_summary(array $events): array {
    $out = [];
    foreach ($events as $e) {
        $who = (string) ($e['who'] ?? '');
        if ($who === '') continue;
        $s = &$out[$who];
        $s ??= ['events' => 0, 'views' => 0, 'signins' => 0, 'failed' => 0, 'marks' => 0, 'overwrites' => 0,
            'classes' => [], 'days' => [], 'first' => '', 'last' => '', 'ips' => [], 'devices' => [], 'late' => 0, 'backdated' => 0];
        $at = $e['day'] . ' ' . $e['t'];
        $s['events']++;
        if ($s['first'] === '' || $at < $s['first']) $s['first'] = $at;
        if ($at > $s['last']) $s['last'] = $at;
        if ($e['type'] !== 'signin_failed') {
            $s['days'][$e['day']] = true;
            if ($e['ip'] !== '') $s['ips'][$e['ip']] = true;
            $s['devices'][$e['device']] = true;
        }
        $d = $e['d'] ?? [];
        match ($e['type']) {
            'view' => $s['views']++,
            'signin', 'resume' => $s['signins']++,
            'signin_failed' => $s['failed']++,
            default => null,
        };
        if ($e['type'] === 'mark') {
            $s['marks']++;
            $s['classes'][$d['class'] ?? ''] = true;
            if (!empty($d['replaced'])) $s['overwrites']++;
            if (($d['lag'] ?? 0) > 24 * 60) $s['late']++;
            if (($d['date'] ?? $e['day']) < $e['day']) $s['backdated']++;
        }
        unset($s);
    }
    return $out;
}

// The first day the log holds, or null before anything has been logged.
function activity_first_day(): ?string {
    $days = array_map(fn($f) => basename($f, '.php'), glob(STORE_DIR . '/activity/*.php') ?: []);
    sort($days);
    return $days[0] ?? null;
}

// Who a typed name is, for counting one person once: "Dr. Asha  Rao", "asha
// rao" and "Prof. Asha Rao." are all "asha rao". The Form's name is free text.
function activity_person(string $name): string {
    $name = strtolower(trim(preg_replace('/[\s.]+/', ' ', $name)));
    while (preg_match('/^(dr|prof|mr|mrs|ms|adv|ar)\b\s*/', $name, $m)) $name = substr($name, strlen($m[0]));
    return trim($name);
}

// The leaderboard: everyone who filed attendance between $from and $to, from
// the Google Form's "Faculty name" answers and from saves in the app, most
// lectures first. Both maps are date => class key => lecture => name. A
// lecture filed both ways is credited once, to the app's name, the same rule
// the dashboard's numbers follow (merge_readings()). A person's school is the
// one they mark for most, read off the class key (class_key() leads with it).
// Returns [rows, unnamed], where unnamed counts lectures nobody's name is on.
function activity_leaderboard(array $form, array $app, string $from, string $to): array {
    $rows = [];
    $unnamed = 0;
    $dates = array_unique([...array_keys($form), ...array_keys($app)]);
    foreach ($dates as $date) {
        $date = (string) $date;
        if ($date < $from || $date > $to) continue;
        $slots = [];
        foreach ((array) ($form[$date] ?? []) as $class => $times) foreach ((array) $times as $time => $name) $slots["$class|$time"] = [(string) $class, (string) $name, 'form'];
        foreach ((array) ($app[$date] ?? []) as $class => $times) foreach ((array) $times as $time => $name) $slots["$class|$time"] = [(string) $class, (string) $name, 'app'];
        foreach ($slots as [$class, $name, $via]) {
            $key = activity_person($name);
            if ($key === '') { $unnamed++; continue; }
            $r = &$rows[$key];
            $r ??= ['name' => [], 'lectures' => 0, 'form' => 0, 'app' => 0, 'classes' => [], 'days' => [], 'schools' => [], 'last' => ''];
            $r['name'][$name] = ($r['name'][$name] ?? 0) + 1;
            $r['lectures']++;
            $r[$via]++;
            $r['classes'][$class] = true;
            $r['days'][$date] = true;
            $school = explode('|', $class)[0];
            $r['schools'][$school] = ($r['schools'][$school] ?? 0) + 1;
            if ($date > $r['last']) $r['last'] = $date;
            unset($r);
        }
    }
    foreach ($rows as $key => $r) {
        arsort($r['name']);
        arsort($r['schools']);
        $rows[$key] = ['key' => $key, 'name' => (string) array_key_first($r['name']), 'school' => (string) (array_key_first($r['schools']) ?? ''),
            'classes' => count($r['classes']), 'days' => count($r['days'])] + $r;
    }
    usort($rows, fn($a, $b) => [$b['lectures'], $b['days'], $a['name']] <=> [$a['lectures'], $a['days'], $b['name']]);
    return [array_values($rows), $unnamed];
}
