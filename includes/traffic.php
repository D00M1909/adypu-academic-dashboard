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
            // A new day: drop yesterday's visitor hashes and salt with it.
            if (($d['seen_day'] ?? '') !== $today) {
                $d['seen_day'] = $today;
                $d['seen'] = [];
                $d['salt'] = bin2hex(random_bytes(16));
            }
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

// date => ['visitors' => n, 'views' => [page => n], 'bots' => n]
function traffic_days(): array {
    $days = store_read('traffic')['days'] ?? [];
    return is_array($days) ? $days : [];
}
