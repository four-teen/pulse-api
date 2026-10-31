<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// A small dependency-free dotenv loader. Real process environment takes precedence.
function config(string $name, string $default = ''): string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        $file = dirname(__DIR__) . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                $values[$key] = $value;
            }
        }
        $effectiveEnvironment = getenv('APP_ENV') !== false ? getenv('APP_ENV') : ($values['APP_ENV'] ?? '');
        if ($effectiveEnvironment === 'development') {
            $localFile = dirname(__DIR__) . '/.env.local';
            if (is_file($localFile)) {
                foreach (file($localFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key === 'APP_ENV') continue;
                    if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                        $value = substr($value, 1, -1);
                    }
                    $values[$key] = $value;
                }
            }
        }
    }
    $environment = getenv($name);
    return $environment !== false ? $environment : ($values[$name] ?? $default);
}

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function president(): array
{
    return [
        'name' => config('PRESIDENT_NAME', 'University President'),
        'role' => 'president',
    ];
}

function sessionOptions(): array
{
    return [
        'lifetime' => 0,
        'path' => '/',
        'secure' => config('APP_ENV') === 'production',
        'httponly' => true,
        // The Vercel proxy keeps this cookie on the web app's own origin.
        'samesite' => 'Strict',
    ];
}

function runtimeDirectory(): string
{
    $directory = config('PULSE_STORAGE_PATH', dirname(__DIR__) . '/var');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Runtime storage unavailable.');
    }
    return $directory;
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $directory = runtimeDirectory() . '/sessions';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Session storage unavailable.');
    }
    session_save_path($directory);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) max(60, (int) config('SESSION_TTL', '3600')));
    session_name('pulse_session');
    session_set_cookie_params(sessionOptions());
    if (!session_start()) throw new RuntimeException('Session storage is unavailable.');
}

function clearSession(): void
{
    $_SESSION = [];
    session_destroy();
    $options = sessionOptions();
    unset($options['lifetime']);
    setcookie('pulse_session', '', ['expires' => time() - 3600] + $options);
}

function requireBrowserOrigin(): void
{
    $expected = rtrim(config('APP_URL'), '/');
    $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    if ($expected === '' || $origin === '' || !hash_equals($expected, $origin)) {
        respond(403, ['message' => 'This request is not allowed from this address.']);
    }
}

function credentialsVersion(): string
{
    // Changing the single presidential passcode invalidates existing sessions.
    return hash('sha256', 'president-passcode-v1' . "\0" . config('PRESIDENT_PASSCODE_HASH') . "\0" . config('PRESIDENT_PASSCODE'));
}

function login(): never
{
    if (config('PRESIDENT_PASSCODE') === '' && config('PRESIDENT_PASSCODE_HASH') === '') {
        respond(503, ['message' => 'Presidential access is not configured yet. Please contact your system administrator.']);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        respond(415, ['message' => 'JSON content is required.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) respond(413, ['message' => 'Request is too large.']);
    try { $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { respond(400, ['message' => 'Invalid request. Please try again.']); }
    if (!is_array($input) || !is_string($input['passcode'] ?? null) || $input['passcode'] === '') {
        respond(400, ['message' => 'Enter your presidential passcode.']);
    }
    if (strlen($input['passcode']) > 1024) {
        respond(400, ['message' => 'The passcode is too long.']);
    }

    // A shared lock protects the one presidential account across concurrent requests.
    // Do not trust forwarded IP headers from arbitrary clients; the Vercel proxy is shared.
    $directory = runtimeDirectory();
    $handle = fopen($directory . '/login-attempts.json', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Rate limit storage unavailable.');
    try {
        $state = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
        $now = time();
        $attempts = array_values(array_filter($state['attempts'] ?? [], fn ($timestamp) => is_int($timestamp) && $timestamp > $now - 900));
        $blockedUntil = (int) ($state['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            header('Retry-After: ' . ($blockedUntil - $now));
            $response = [429, ['message' => 'Too many sign-in attempts. Please wait a few minutes and try again.']];
        } else {
            $passcodeHash = config('PRESIDENT_PASSCODE_HASH');
            $passcodeMatches = $passcodeHash !== ''
                ? password_verify($input['passcode'], $passcodeHash)
                : hash_equals(hash('sha256', config('PRESIDENT_PASSCODE')), hash('sha256', $input['passcode']));
            if ($passcodeMatches) {
                $state = ['attempts' => [], 'blocked_until' => 0];
                $response = [200, ['user' => president()]];
            } else {
                $attempts[] = $now;
                $state = ['attempts' => $attempts, 'blocked_until' => count($attempts) >= 5 ? $now + 300 : 0];
                $response = [401, ['message' => 'The presidential passcode is incorrect. Please try again.']];
            }
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Rate limit storage unavailable.');
            fflush($handle);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    if ($response[0] === 200) {
        startSession();
        session_regenerate_id(true);
        $_SESSION = ['role' => 'president', 'expires_at' => time() + max(60, (int) config('SESSION_TTL', '3600')), 'credentials_version' => credentialsVersion()];
        session_write_close();
    }
    respond($response[0], $response[1]);
}
