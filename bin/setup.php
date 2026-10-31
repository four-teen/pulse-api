<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$file = dirname(__DIR__) . '/.env';
if (is_file($file)) { fwrite(STDERR, "A .env file already exists. Edit it directly; it was not changed.\n"); exit(1); }
$passcode = 'Pulse-' . bin2hex(random_bytes(12));
$template = file_get_contents(dirname(__DIR__) . '/.env.example');
if ($template === false) { fwrite(STDERR, "Unable to read .env.example.\n"); exit(1); }
$contents = preg_replace('/^PRESIDENT_PASSCODE=$/m', 'PRESIDENT_PASSCODE=' . $passcode, $template);
if (file_put_contents($file, $contents, LOCK_EX) === false) { fwrite(STDERR, "Unable to create .env.\n"); exit(1); }
@chmod($file, 0600);
echo "Created .env with a random local passcode. View .env to see or change the presidential passcode.\n";
