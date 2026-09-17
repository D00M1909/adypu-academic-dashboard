<?php
// Prints the Form's sections and their dropdown options, ready to paste into
// Google Forms (it splits pasted multi-line text into separate options).
//
//   php tools/form-options.php > class-options.txt
//
// Re-run and re-paste whenever includes/structure.php changes: the labels are
// what parse_class_label() matches on, so a hand-edited option in the Form
// silently drops that class's submissions.

require_once __DIR__ . '/../includes/structure.php';

// Schools first, then the knowledge partners: the School question gets one
// extra option, "Knowledge Partner", jumping to a Partner question whose
// options jump to the partner sections below.
$groups = [
    'SCHOOL SECTIONS, point the School question at them' => form_sections(),
    'KNOWLEDGE PARTNER SECTIONS, point the Partner question at them' => form_sections(partner_structure()),
];

foreach ($groups as $heading => $sections) {
    echo "$heading\n", str_repeat('=', 74), "\n";
    foreach ($sections as $title => $labels) {
        printf("%-42s %2d options\n", $title, count($labels));
    }
    echo "\n";
}

foreach ($groups as $sections) {
    foreach ($sections as $title => $labels) {
        echo "\n\n", str_repeat('=', 74), "\n";
        echo "SECTION: $title\n";
        echo "Question title: Class ($title)\n";
        echo str_repeat('=', 74), "\n";
        foreach ($labels as $label) {
            echo $label, "\n";
        }
    }
}
