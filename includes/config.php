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

define('DB_HOST', defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost'));
define('DB_USER', defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root'));
define('DB_PASS', defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') ?: ''));
define('DB_NAME', defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'adypu_dashboard'));

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
