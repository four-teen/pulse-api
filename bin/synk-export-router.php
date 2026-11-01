<?php
declare(strict_types=1);

// Local-only overlay: run the prepared export without modifying the Synk reference app.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/backend/pulse_api.php') { http_response_code(404); echo 'Not found.'; return; }
define('PULSE_SYNK_LOCAL_DB_FILE', dirname(__DIR__, 2) . '/synk/backend/db.php');
require dirname(__DIR__) . '/integrations/synk/backend/pulse_api.php';
