<?php
declare(strict_types=1);

require_once __DIR__ . '/Records.php';

final class PulseSourceRegistry
{
    public static function all(): array
    {
        return [
            'sinq' => self::source('sinq', 'SINQ', 'https://sinq.sksu-orms.net/'),
            'oracle' => self::source('oracle', 'Oracle', 'https://sksu-oracle.com/'),
            'pad' => self::source('pad', 'PAD', 'https://pad-web-coral.vercel.app/'),
        ];
    }

    private static function source(string $id, string $name, string $website): array
    {
        $prefix = strtoupper($id);
        return [
            'id' => $id, 'name' => $name, 'website' => $website,
            'enabled' => filter_var(config($prefix . '_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            'baseUrl' => trim(config($prefix . '_API_BASE_URL')),
            'summaryPath' => trim(config($prefix . '_SUMMARY_PATH')),
            'allowedHost' => strtolower(trim(config($prefix . '_API_ALLOWED_HOST', parse_url($website, PHP_URL_HOST)))),
            'authType' => config($prefix . '_AUTH_TYPE', 'bearer'),
            'token' => config($prefix . '_API_TOKEN'),
            'apiKeyHeader' => config($prefix . '_API_KEY_HEADER', 'X-API-Key'),
        ];
    }

    public static function issues(array $source): array
    {
        $issues = [];
        $url = parse_url($source['baseUrl']);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            $issues[] = 'An HTTPS API base URL without embedded credentials is required.';
        } elseif (strtolower($url['host']) !== $source['allowedHost'] || filter_var($url['host'], FILTER_VALIDATE_IP) || !str_contains($url['host'], '.') || str_ends_with(strtolower($url['host']), '.localhost')) {
            $issues[] = 'The API hostname must match the approved source hostname.';
        }
        if ($source['summaryPath'] === '' || !preg_match('#^/?[a-zA-Z0-9_./-]+$#D', $source['summaryPath']) || preg_match('#(?:^|/)\.\.(?:/|$)#', $source['summaryPath'])) {
            $issues[] = 'A confirmed summary endpoint path is required.';
        }
        if (!in_array($source['authType'], ['bearer', 'api_key', 'none'], true)) $issues[] = 'Unsupported authentication type.';
        if ($source['authType'] !== 'none' && $source['token'] === '') $issues[] = 'An API credential is required.';
        if (preg_match('/[\r\n\x00]/', $source['token'])) $issues[] = 'The API credential contains invalid characters.';
        if ($source['authType'] === 'api_key' && (!preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $source['apiKeyHeader']) || in_array(strtolower($source['apiKeyHeader']), ['host', 'cookie', 'origin', 'connection', 'content-length', 'proxy-authorization'], true))) {
            $issues[] = 'A valid API key header is required.';
        }
        return $issues;
    }
}

interface PulseSourceConnector
{
    /** Return the normalized v1 snapshot, never a vendor page or unvalidated body. */
    public function fetchSnapshot(): array;
}

/** Read-only adapter for a confirmed endpoint implementing docs/integrations.md. */
final class PulseNormalizedJsonConnector implements PulseSourceConnector
{
    public function __construct(private array $source) {}

    public function fetchSnapshot(): array
    {
        if (!$this->source['enabled']) throw new RuntimeException('This source is disabled. No request was made.');
        if (PulseSourceRegistry::issues($this->source)) throw new RuntimeException('The source configuration is incomplete. No request was made.');
        if (!extension_loaded('curl')) throw new RuntimeException('The PHP cURL extension is required.');
        $url = rtrim($this->source['baseUrl'], '/') . '/' . ltrim($this->source['summaryPath'], '/');
        $headers = ['Accept: application/json', 'User-Agent: PULSE-API/1.0'];
        if ($this->source['authType'] === 'bearer') $headers[] = 'Authorization: Bearer ' . $this->source['token'];
        if ($this->source['authType'] === 'api_key') $headers[] = $this->source['apiKeyHeader'] . ': ' . $this->source['token'];
        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('The source request could not be initialized.');
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(5, min(30, (int) config('INTEGRATION_TIMEOUT_SECONDS', '15'))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $success = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '';
            if ($tooLarge) throw new RuntimeException('The source response exceeded the supported size.');
            if ($success === false || $status < 200 || $status >= 300) throw new RuntimeException('The source request failed. The previous records were preserved.');
            if (!str_contains(strtolower($contentType), 'application/json') && !str_contains(strtolower($contentType), '+json')) throw new RuntimeException('The source did not return JSON.');
            try { $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new RuntimeException('The source returned invalid JSON.'); }
            if (!is_array($payload)) throw new RuntimeException('The source returned an invalid snapshot.');
            return pulseValidateSnapshot($payload, $this->source['id']);
        } finally { curl_close($curl); }
    }
}

final class PulseSnapshotStore
{
    private string $directory;

    public function __construct()
    {
        $this->directory = runtimeDirectory() . '/integrations';
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Integration storage is unavailable.');
    }

    private function path(string $id, string $suffix): string
    {
        if (!isset(PulseSourceRegistry::all()[$id])) throw new InvalidArgumentException('Unknown source.');
        return $this->directory . '/' . $id . '.' . $suffix . '.json';
    }

    public function load(string $id): ?array
    {
        $path = $this->path($id, 'snapshot');
        if (!is_file($path)) return null;
        if (filesize($path) > 2097152) throw new RuntimeException('Stored integration data is invalid.');
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Integration records are unavailable.');
        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Stored integration data is invalid.');
        return pulseValidateSnapshot($data, $id);
    }

    public function save(string $id, array $snapshot): void
    {
        $validated = pulseValidateSnapshot($snapshot, $id);
        $path = $this->path($id, 'snapshot');
        $lock = fopen($this->path($id, 'lock'), 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Integration storage could not be locked.');
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            try { $current = $this->load($id); } catch (Throwable) { $current = null; }
            if ($current !== null && strtotime($current['observedAt']) > strtotime($validated['observedAt'])) {
                throw new RuntimeException('An older snapshot cannot replace newer records.');
            }
            if (file_put_contents($temporary, json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Integration records could not be stored.');
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new RuntimeException('Integration records could not be published.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
