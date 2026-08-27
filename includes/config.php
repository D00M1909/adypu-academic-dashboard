<?php
// Database configuration — same pattern as the automatic-timetable-generator
// project. XAMPP default: host=localhost, user=root, password='', db=adypu_dashboard
//
// The DB (db/schema.sql) is a nice-to-have, not read from by the app yet
// (see SPEC.md §7.1), so the connection is lazy: get_db() only connects when
// something actually calls it, and the rest of the app runs fine without a
// DB configured at all.

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// `defined() || define()`, not `define(defined() ? ...)`: define() runs either
// way and re-defining an existing constant raises a warning, which lands in the
// page output on any host where config.local.php actually sets these.
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'adypu_dashboard');

// Shared secret the Apps Script in tools/apps-script.gs sends with every push
// to api/ingest.php. Empty means ingest is disabled and refuses every request —
// set it in config.local.php, never here (this file is committed).
defined('INGEST_SECRET') || define('INGEST_SECRET', getenv('INGEST_SECRET') ?: '');

function get_db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('Database connection failed: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
