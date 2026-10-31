<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Integrations.php';

$production = in_array('--production', $argv, true);
$issues = [];
if (PHP_VERSION_ID < 80100) $issues[] = 'PHP 8.1 or newer is required.';
foreach (['json', 'session', 'curl'] as $extension) if (!extension_loaded($extension)) $issues[] = 'Required PHP extension missing: ' . $extension;
if (config('PRESIDENT_PASSCODE') === '' && config('PRESIDENT_PASSCODE_HASH') === '') $issues[] = 'Configure the presidential passcode.';
try { $directory = runtimeDirectory(); if (!is_writable($directory)) $issues[] = 'Private runtime storage must be writable.'; }
catch (Throwable) { $issues[] = 'Private runtime storage is unavailable.'; }
if ($production) {
    if (config('APP_ENV') !== 'production') $issues[] = 'Set APP_ENV=production on the production host.';
    $origin = parse_url(config('APP_URL'));
    if (!$origin || ($origin['scheme'] ?? '') !== 'https' || empty($origin['host']) || isset($origin['user']) || isset($origin['query']) || isset($origin['fragment']) || (isset($origin['path']) && $origin['path'] !== '')) $issues[] = 'Set APP_URL to the exact HTTPS frontend origin without a trailing slash.';
    if (strlen(config('PULSE_PROXY_SECRET')) < 32) $issues[] = 'Set a shared proxy secret of at least 32 characters on PHP and Vercel.';
    if (config('PRESIDENT_PASSCODE_HASH') === '' && strlen(config('PRESIDENT_PASSCODE')) < 16) $issues[] = 'Use a presidential passcode of at least 16 characters or a password hash.';
    if (config('PRESIDENT_PASSCODE_HASH') !== '' && (password_get_info(config('PRESIDENT_PASSCODE_HASH'))['algo'] ?? null) === null) $issues[] = 'The configured presidential passcode hash is invalid.';
}
foreach (PulseSourceRegistry::all() as $source) {
    if ($source['enabled'] && PulseSourceRegistry::issues($source)) $issues[] = $source['name'] . ' is enabled but its API configuration is incomplete.';
}
if ($issues) {
    foreach ($issues as $issue) fwrite(STDERR, '- ' . $issue . "\n");
    fwrite(STDERR, "Configuration checks need attention. No credentials were printed and no external requests were made.\n");
    exit(1);
}
echo ($production ? 'Production configuration' : 'Local configuration') . " checks passed. Source connections remain under explicit operator control.\n";
echo "No credentials were printed and no external requests were made.\n";
