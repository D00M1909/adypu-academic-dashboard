<?php
// Our own hit counter, because the host's "Daily Hits" counts every file it
// serves (the stylesheet, three scripts, the logo, every 5-minute push) and so
// says nothing about how many people opened the dashboard. This counts page
// loads only, one per page per request, and read back by stats.php.
//
// Visitors are counted, never identified: a salted hash of IP + browser, kept
// for the current day only and thrown away at midnight along with its salt, so
// nothing on disk can be matched back to an address or across two days.

require_once __DIR__ . '/store.php';

// How far back the daily totals go. Roughly 200 bytes a day.
const TRAFFIC_KEEP_DAYS = 120;

// Link previews (WhatsApp, Teams) and crawlers load the page too, and a link
// shared in a staff group would otherwise show up as a burst of "visitors".
const TRAFFIC_BOT_UA = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|skype|curl|wget|python|headless|lighthouse|monitor/i';

// A new day: drop yesterday's visitor hashes and salt with it.
function traffic_roll(array &$d, string $today): void {
    if (($d['seen_day'] ?? '') !== $today) {
        $d['seen_day'] = $today;
        $d['seen'] = [];
        $d['seen_click'] = [];
        $d['salt'] = bin2hex(random_bytes(16));
    }
}

function traffic_is_bot(string $ua): bool {
    return $ua === '' || preg_match(TRAFFIC_BOT_UA, $ua) === 1;
}

// $page is a literal from our own source ('dashboard', 'mark', ...). A failure
// here must never cost the page it is counting, so everything is swallowed.
function traffic_hit(string $page, ?string $ip = null, ?string $ua = null, ?string $today = null): void {
    // Every form on these pages posts and then redirects to a GET, so counting
    // the POST too would count each save or sign-in twice.
    if ($page !== 'push' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $ip ??=(string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $today ??= date('Y-m-d');
    $bot = $page !== 'push' && traffic_is_bot($ua);
    try {
        store_update('traffic', function (array $d) use ($page, $ip, $ua, $today, $bot) {
            traffic_roll($d, $today);
            $day = $d['days'][$today] ?? [];
            if ($bot) {
                $day['bots'] = ($day['bots'] ?? 0) + 1;
            } else {
                $day['views'][$page] = ($day['views'][$page] ?? 0) + 1;
                // The Apps Script is not a visitor, it is 288 requests a day.
                if ($page !== 'push') {
                    $id = substr(hash_hmac('sha256', $ip . '|' . $ua, $d['salt']), 0, 12);
                    if (!isset($d['seen'][$id])) {
                        $d['seen'][$id] = 1;
                        $day['visitors'] = ($day['visitors'] ?? 0) + 1;
                    }
                }
            }
            $d['days'][$today] = $day;
            ksort($d['days']);
            $d['days'] = array_slice($d['days'], -TRAFFIC_KEEP_DAYS, null, true);
            return $d;
        });
    } catch (Throwable $e) {
        // Counting is a nicety. The page is the job.
    }
}

// The footer links, by the key the page sends. Anything else is ignored, so the
// endpoint cannot be used to grow the file with made-up names.
const TRAFFIC_LINKS = [
    'author' => 'Advait (GitHub profile)',
    'repo'   => 'GitHub repository',
    'issues' => 'Report a problem',
];

// One click on a footer link. Counted per link, plus how many distinct browsers
// clicked it, by the same one-day salted hash the visitor count uses.
function traffic_click(string $link, ?string $ip = null, ?string $ua = null, ?string $today = null): void {
    if (!isset(TRAFFIC_LINKS[$link])) return;
    $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $today ??= date('Y-m-d');
    if (traffic_is_bot($ua)) return;
    try {
        store_update('traffic', function (array $d) use ($link, $ip, $ua, $today) {
            traffic_roll($d, $today);
            $day = $d['days'][$today] ?? [];
            $day['clicks'][$link] = ($day['clicks'][$link] ?? 0) + 1;
            $id = substr(hash_hmac('sha256', $ip . '|' . $ua, $d['salt']), 0, 12) . $link;
            if (!isset($d['seen_click'][$id])) {
                $d['seen_click'][$id] = 1;
                $day['clickers'][$link] = ($day['clickers'][$link] ?? 0) + 1;
            }
            $d['days'][$today] = $day;
            ksort($d['days']);
            $d['days'] = array_slice($d['days'], -TRAFFIC_KEEP_DAYS, null, true);
            return $d;
        });
    } catch (Throwable $e) {
        // Counting is a nicety. The link is the job.
    }
}

// date => ['visitors' => n, 'views' => [page => n], 'bots' => n,
//          'clicks' => [link => n], 'clickers' => [link => n]]
function traffic_days(): array {
    $days = store_read('traffic')['days'] ?? [];
    return is_array($days) ? $days : [];
}
