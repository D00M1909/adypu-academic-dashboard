<?php
// Beacon target for the footer links (see site_footer()): counts one click and
// answers nothing. Never blocks the link, which opens regardless.

require_once __DIR__ . '/../includes/traffic.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    traffic_click((string) ($_POST['l'] ?? ''));
}
http_response_code(204);
