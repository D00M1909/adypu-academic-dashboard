<?php
// Student lists, one file per school: data/roster/<school>.php holds
// class key => [ ['roll' => ..., 'name' => ...], ... ].
//
// Per school rather than one file, because a marking screen needs exactly one
// class and loading every student in the university to draw seventy names is
// the kind of thing that is fine until Engineering's two thousand arrive.
//
// Behind store.php's guard: this is the one place in the app holding student
// names, and it must never be fetchable over HTTP.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/structure.php';

function roster_for(array $class): array {
    static $loaded = [];
    $school = $class['school'] ?? '';
    if ($school === '') return [];
    $loaded[$school] ??= store_read('roster/' . $school);
    $rows = $loaded[$school][class_key($class)] ?? [];
    return is_array($rows) ? $rows : [];
}

// Which schools have sent anything at all. Only used to tell an admin how far
// the rollout has got; the marking screen asks per class, because a school can
// return half its divisions and the other half still needs the number box.
function roster_schools(): array {
    $out = [];
    foreach (array_keys(SCHOOLS) as $school) {
        $rows = store_read('roster/' . $school);
        if ($rows) $out[$school] = count($rows);
    }
    return $out;
}
