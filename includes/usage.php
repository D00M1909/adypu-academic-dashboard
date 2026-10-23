<?php
// The two usage numbers worth quoting: attendance entries filed and pages
// opened. stats.php shows them a day at a time; usage_brag() sums them for the
// hub's project card, which gets them by way of the Apps Script (api/ingest.php).

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/structure.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/traffic.php';

// date => ['form' => n, 'app' => n] for every date in $dates.
//
// Every entry, not every lecture: a Form row re-submitted or filed today for
// yesterday's class counts today, once per submission (the user's call, 21
// Oct 2026: the bigger number, the same one Activity shows). Form rows by the
// day the sheet's Timestamp says, from the log each push writes (form_log());
// app saves by the day they were made, from the activity log. Before that log
// began, a day's app entries are its app readings, the closest there is.
function usage_entries(array $dates): array {
    $form = [];
    foreach ((array) (store_read('formlog')['rows'] ?? []) as $r) {
        $on = substr((string) ($r['at'] ?? ''), 0, 10);
        if ($on !== '') $form[$on] = ($form[$on] ?? 0) + 1;
    }
    $logFrom = activity_first_day();
    $marks = [];
    if ($logFrom !== null && $dates) {
        foreach (activity_range(max($logFrom, min($dates)), max($dates)) as $x) {
            if ($x['type'] === 'mark') $marks[$x['day']] = ($marks[$x['day']] ?? 0) + 1;
        }
    }
    $app = remap_legacy_days(submitted('days'));
    $out = [];
    foreach ($dates as $d) {
        $out[$d] = [
            'form' => $form[$d] ?? 0,
            'app'  => $logFrom !== null && $d >= $logFrom
                ? ($marks[$d] ?? 0)
                : array_sum(array_map(fn($r) => count((array) $r), $app[$d] ?? [])),
        ];
    }
    return $out;
}

// Pages people opened that day. The Apps Script's pushes are counted beside
// them (as 'push') and are not views.
function usage_views(array $trafficDay): int {
    $views = $trafficDay['views'] ?? [];
    return array_sum($views) - (int) ($views['push'] ?? 0);
}

// Totals over the $days days up to and including yesterday: whole days only,
// so the number on the hub does not dip every morning and climb back by night.
function usage_brag(int $days = 30): array {
    $dates = [];
    for ($i = 1; $i <= $days; $i++) $dates[] = date('Y-m-d', strtotime("-$i day"));
    $traffic = traffic_days();
    $updates = 0;
    $views = 0;
    foreach (usage_entries($dates) as $d => $e) {
        $updates += $e['form'] + $e['app'];
        $views += usage_views($traffic[$d] ?? []);
    }
    return ['days' => $days, 'to' => $dates[0], 'updates' => $updates, 'views' => $views];
}
