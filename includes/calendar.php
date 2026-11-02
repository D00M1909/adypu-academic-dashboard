<?php
// Holidays and events (events.php), kept in data/calendar.php. Anyone can read
// them; only an admin writes them.
//
// A holiday means no class is expected, so a class that filed nothing that day
// reads Holiday rather than Not filled. An event is a note on a normal day:
// classes are still expected and nothing about the numbers changes. A class
// that does file on a holiday (a make-up lecture) still counts.
//
// schools and years narrow an entry; an empty list means all of them.

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/structure.php';
require_once __DIR__ . '/store.php';

const CALENDAR_TYPES = ['holiday' => 'Holiday', 'event' => 'Event'];
// A strip per entry on a busy month would push the page down; the rest are on
// the Events tab.
const CALENDAR_STRIPS = 3;

// Every entry, earliest first.
function calendar_all(): array {
    $list = array_values(store_read('calendar')['entries'] ?? []);
    usort($list, fn($a, $b) => [$a['from'], $a['to'], $a['title']] <=> [$b['from'], $b['to'], $b['title']]);
    return $list;
}

// The years any school has, 1st Year first.
function calendar_years(): array {
    $years = array_values(array_unique(array_column(class_rows(), 'year')));
    usort($years, fn($a, $b) => [(int) $a, $a] <=> [(int) $b, $b]);
    return $years;
}

// A posted form as an entry: [entry, ''] or [null, what is wrong with it].
function calendar_clean(array $in): array {
    $title = trim(preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')));
    $type = (string) ($in['type'] ?? '');
    $from = row_date((string) ($in['from'] ?? ''));
    $to = row_date((string) ($in['to'] ?? '')) ?? $from;
    $some = fn(string $scope, string $list, array $known): array => ($in[$scope] ?? '') === 'some'
        ? array_values(array_intersect($known, (array) ($in[$list] ?? []))) : [];
    $schools = $some('for', 'schools', array_keys(SCHOOLS));
    $years = $some('for_years', 'years', calendar_years());

    if ($title === '') return [null, 'Give it a name.'];
    if (!isset(CALENDAR_TYPES[$type])) return [null, 'Choose Holiday or Event.'];
    if ($from === null) return [null, 'Pick a date.'];
    if ($to < $from) return [null, 'The last day is before the first.'];
    if (($in['for'] ?? '') === 'some' && !$schools) return [null, 'Tick at least one school.'];
    if (($in['for_years'] ?? '') === 'some' && !$years) return [null, 'Tick at least one year.'];
    return [['title' => mb_substr($title, 0, 60), 'type' => $type, 'from' => $from, 'to' => $to,
        'schools' => $schools, 'years' => $years], ''];
}

// A new entry with $id '', an edit with its id. Returns the id, or '' when
// the id it was asked to edit has gone.
function calendar_save(array $entry, string $id = ''): string {
    $saved = '';
    store_update('calendar', function (array $d) use ($entry, $id, &$saved) {
        if ($id !== '' && !isset($d['entries'][$id])) return $d;
        $saved = $id !== '' ? $id : bin2hex(random_bytes(6));
        $d['entries'][$saved] = ['id' => $saved] + $entry;
        return $d;
    });
    return $saved;
}

function calendar_delete(string $id): void {
    store_update('calendar', function (array $d) use ($id) {
        unset($d['entries'][$id]);
        return $d;
    });
}

// Whether an entry touches this school and year. '' means any at all, so a
// filter left on All schools still lists an entry for one school.
function calendar_applies(array $e, string $school = '', string $year = ''): bool {
    return ($school === '' || !$e['schools'] || in_array($school, $e['schools'], true))
        && ($year === '' || !$e['years'] || in_array($year, $e['years'], true));
}

// Whether an entry covers ALL of what this school and year select, which is
// what lets a whole day be drawn as a holiday.
function calendar_covers(array $e, string $school = '', string $year = ''): bool {
    return (!$e['schools'] || ($school !== '' && in_array($school, $e['schools'], true)))
        && (!$e['years'] || ($year !== '' && in_array($year, $e['years'], true)));
}

function calendar_between(array $entries, string $from, string $to): array {
    return array_values(array_filter($entries, fn($e) => $e['from'] <= $to && $e['to'] >= $from));
}

// Whether a class of this school and year has a holiday on $date.
function calendar_is_off(array $entries, string $date, string $school, string $year): bool {
    foreach (calendar_between($entries, $date, $date) as $e) {
        if ($e['type'] === 'holiday' && calendar_applies($e, $school, $year)) return true;
    }
    return false;
}

// Whether a class was off for the whole of a range, so filing nothing in it is
// no fault of anybody's. Sundays do not count against that, or no range over a
// week could ever qualify; a range of nothing but Sundays needs a real holiday.
function calendar_off(array $entries, string $from, string $to, string $school, string $year): bool {
    $days = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) $days[] = date('Y-m-d', $d);
    $working = array_filter($days, fn($d) => date('w', strtotime($d)) !== '0') ?: $days;
    foreach ($working as $d) {
        if (!calendar_is_off($entries, $d, $school, $year)) return false;
    }
    return (bool) $working;
}

function calendar_when(array $e): string {
    $day = fn(string $d): string => date('D j M', strtotime($d));
    return $e['from'] === $e['to'] ? $day($e['from']) : $day($e['from']) . ' to ' . $day($e['to']);
}

// Who an entry is for, in words: "All schools", "Engineering · 1st Year".
function calendar_scope(array $e): string {
    $short = fn(string $id): string => preg_replace('/^School of /', '', SCHOOLS[$id]['name'] ?? $id);
    $parts = [$e['schools'] ? implode(', ', array_map($short, $e['schools'])) : 'All schools'];
    if ($e['years']) $parts[] = implode(', ', $e['years']);
    return implode(' · ', $parts);
}

// date => the entries on it, for the filing chart: type, title, schools, years.
function calendar_chart_days(array $entries, string $from, string $to): array {
    $out = [];
    foreach (calendar_between($entries, $from, $to) as $e) {
        $last = strtotime(min($e['to'], $to));
        for ($d = strtotime(max($e['from'], $from)); $d <= $last; $d = strtotime('+1 day', $d)) {
            $out[date('Y-m-d', $d)][] = ['k' => $e['type'], 't' => $e['title'], 's' => $e['schools'], 'y' => $e['years']];
        }
    }
    return $out;
}

// The small coloured label for one entry. The page must carry the icon-holiday
// and icon-event symbols.
function calendar_tag(array $e): string {
    return '<span class="cal-tag is-' . htmlspecialchars($e['type']) . '" title="' . htmlspecialchars($e['title'] . ', ' . calendar_scope($e)) . '">'
         . '<svg aria-hidden="true"><use href="#icon-' . htmlspecialchars($e['type']) . '"/></svg>'
         . '<span>' . htmlspecialchars($e['title']) . '</span></span>';
}

// The strip a page shows over a marked day or range. $holidayNote is said
// after a holiday, for the page that has something to add. The data attributes
// let js/report.js hide a strip when its school or year is filtered out.
function calendar_strips(array $entries, string $from, string $to, string $holidayNote = ''): string {
    $list = calendar_between($entries, $from, $to);
    $html = '';
    foreach (array_slice($list, 0, CALENDAR_STRIPS) as $e) {
        $label = CALENDAR_TYPES[$e['type']];
        $bits = [calendar_scope($e)];
        if ($from !== $to) array_unshift($bits, calendar_when($e));
        if ($e['type'] === 'holiday' && $holidayNote !== '') $bits[] = $holidayNote;
        $html .= '<p class="cal-strip is-' . htmlspecialchars($e['type']) . '" data-schools="' . htmlspecialchars(implode(' ', $e['schools'])) . '" data-years="' . htmlspecialchars(implode('|', $e['years'])) . '">'
               . '<svg aria-hidden="true"><use href="#icon-' . htmlspecialchars($e['type']) . '"/></svg>'
               . '<strong>' . $label . ': ' . htmlspecialchars($e['title']) . '</strong>'
               . '<small>' . htmlspecialchars(implode(' · ', $bits)) . '</small></p>';
    }
    if (count($list) > CALENDAR_STRIPS) {
        $html .= '<p class="cal-strip-more"><a href="events.php?' . htmlspecialchars(http_build_query(['month' => substr($from, 0, 7)])) . '">'
               . (count($list) - CALENDAR_STRIPS) . ' more on the Events tab</a></p>';
    }
    return $html;
}
