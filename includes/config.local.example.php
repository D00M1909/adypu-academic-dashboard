<?php
// Copy to config.local.php (gitignored) and fill in real credentials for
// shared hosting (e.g. InfinityFree), where env vars aren't reliable.
define('DB_HOST', 'sqlXXX.infinityfree.com');
define('DB_USER', 'if0_XXXXXXXX');
define('DB_PASS', 'your-password');
define('DB_NAME', 'if0_XXXXXXXX_adypu_dashboard');

// Shared secret for api/ingest.php. Must match INGEST_SECRET in the Apps Script
// on the Form's response Sheet. Generate one with:
//   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
define('INGEST_SECRET', 'generate-a-long-random-string');
