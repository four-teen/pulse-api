<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Dashboard.php';
require dirname(__DIR__) . '/src/FacultyProfiles.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    // Also support an API hosted under /pulse-api/ rather than a dedicated domain.
    $path = preg_replace('#^.*?(/(?:auth/[^/]+|v1/(?:president/dashboard|campuses|integrations|faculty-profiles)|health))$#', '$1', $path);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $routes = [
        '/health' => 'GET', '/auth/login' => 'POST', '/auth/session' => 'GET', '/auth/logout' => 'POST',
        '/v1/president/dashboard' => 'GET', '/v1/campuses' => 'GET', '/v1/integrations' => 'GET', '/v1/faculty-profiles' => 'GET',
    ];
    if (!isset($routes[$path])) respond(404, ['message' => 'Not found.']);
    if ($routes[$path] !== $method) {
        header('Allow: ' . $routes[$path]);
        respond(405, ['message' => 'Method not allowed.']);
    }
    if ($path === '/health') respond(200, ['status' => 'ok', 'service' => 'PULSE API']);

    $proxySecret = config('PULSE_PROXY_SECRET');
    if ($proxySecret !== '' && !hash_equals($proxySecret, $_SERVER['HTTP_X_PULSE_PROXY_SECRET'] ?? '')) {
        respond(403, ['message' => 'This request is not allowed.']);
    }
    if ($method === 'POST') requireBrowserOrigin();
    if ($path === '/auth/login') login();

    startSession();
    if ($path === '/auth/logout') {
        clearSession();
        respond(200, ['message' => 'You have been signed out.']);
    }
    if (($_SESSION['role'] ?? '') !== 'president' || (int) ($_SESSION['expires_at'] ?? 0) <= time() || !hash_equals(credentialsVersion(), $_SESSION['credentials_version'] ?? '')) {
        clearSession();
        respond(401, ['message' => 'Please sign in to continue.']);
    }
    session_write_close();
    if ($path === '/auth/session') respond(200, ['user' => president()]);
    if ($path === '/v1/faculty-profiles') respond(200, PulseFacultyProfiles::response($_GET));
    $dashboard = pulsePresidentDashboard();
    if ($path === '/v1/campuses') respond(200, ['schemaVersion' => 1, 'campuses' => $dashboard['campuses']]);
    if ($path === '/v1/integrations') respond(200, ['schemaVersion' => 1, 'sources' => $dashboard['sources']]);
    respond(200, $dashboard);
} catch (Throwable $error) {
    // Never return internal paths, credentials, or stack traces to the browser.
    error_log('PULSE API: ' . get_class($error) . ': ' . $error->getMessage());
    respond(500, ['message' => 'The presidential service is temporarily unavailable. Please try again shortly.']);
}
