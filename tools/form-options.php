<?php
// Prints the Form's sections and their dropdown options, ready to paste into
// Google Forms (it splits pasted multi-line text into separate options).
//
//   php tools/form-options.php > class-options.txt
//
// Re-run and re-paste whenever includes/structure.php changes — the labels are
// what parse_class_label() matches on, so a hand-edited option in the Form
// silently drops that class's submissions.

require_once __DIR__ . '/../includes/structure.php';

$sections = form_sections();

echo "SECTION LIST — build these in order, then point the School question at them\n";
echo str_repeat('=', 74), "\n\n";
foreach ($sections as $title => $labels) {
    printf("%-42s %2d options\n", $title, count($labels));
}

foreach ($sections as $title => $labels) {
    echo "\n\n", str_repeat('=', 74), "\n";
    echo "SECTION: $title\n";
    echo "Question title: Class (", $title, ")\n";
    echo str_repeat('=', 74), "\n";
    foreach ($labels as $label) {
        echo $label, "\n";
    }
}
