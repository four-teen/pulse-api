<?php
declare(strict_types=1);

// Isolated source-host test: no Synk files or database connection are used.
define('PULSE_SYNK_LOCAL_DB_FILE', __DIR__ . '/synk-export-db-unavailable.php');
require dirname(__DIR__, 2) . '/integrations/synk/backend/pulse_api.php';
