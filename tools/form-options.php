<?php
// Prints the option list for the Form's single "Class" dropdown, one per line,
// ready to paste into Google Forms (it splits pasted multi-line text into
// separate options automatically).
//
//   php tools/form-options.php > class-options.txt
//
// Re-run and re-paste whenever includes/structure.php changes — the labels are
// what parse_class_label() matches on, so a hand-edited option in the Form
// silently drops that class's submissions.

require_once __DIR__ . '/../includes/structure.php';

foreach (class_rows() as $c) {
    echo class_label($c['school'], $c['year'], $c['branch'], $c['division']), "\n";
}
