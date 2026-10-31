<?php
declare(strict_types=1);

final class PulseFacultyProfiles
{
    private const PAGE_SIZE = 250;
    private const MAX_RECORDS = 20000;
    private const MAX_RESPONSE_BYTES = 4194304;

    public static function configurationIssues(): array
    {
        if (!filter_var(config('SINQ_FACULTY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) return ['Faculty profile sync is disabled.'];
        return self::remoteConfigurationIssues();
    }

    public static function localConfigurationIssues(): array
    {
        if (!filter_var(config('SINQ_FACULTY_LOCAL_TEST_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) return ['Local faculty profile testing is disabled.'];
        if (config('APP_ENV') !== 'development') return ['Local faculty profile testing is only available when APP_ENV=development.'];
        if (filter_var(config('SINQ_FACULTY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) return ['Disable the remote faculty source while using the local test source.'];
        $base = trim(config('SINQ_FACULTY_LOCAL_TEST_BASE_URL', 'http://127.0.0.1:8081'));
        $url = parse_url($base);
        if (!$url || ($url['scheme'] ?? '') !== 'http' || strtolower($url['host'] ?? '') !== '127.0.0.1'
            || (int)($url['port'] ?? 0) !== 8081 || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['path'])) {
            return ['The local test source must use http://127.0.0.1:8081 only.'];
        }
        $path = trim(config('SINQ_FACULTY_LOCAL_TEST_PATH', '/backend/pulse_api.php?resource=faculty-profiles'));
        if (!self::validPath($path)) return ['A valid faculty profile API path is required.'];
        if (trim(config('SINQ_FACULTY_LOCAL_TEST_TOKEN')) === '') return ['A local-only Synk API token is required.'];
        return [];
    }

    private static function remoteConfigurationIssues(): array
    {
        $base = trim(config('SINQ_API_BASE_URL'));
        $url = parse_url($base);
        $host = strtolower(trim(config('SINQ_API_ALLOWED_HOST', 'sinq.sksu-orms.net')));
        if (!$url || ($url['scheme'] ?? '') !== 'https' || strtolower($url['host'] ?? '') !== $host || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            return ['A confirmed HTTPS Synk API base URL is required.'];
        }
        $path = trim(config('SINQ_FACULTY_PATH', '/backend/pulse_api.php?resource=faculty-profiles'));
        if (!self::validPath($path)) return ['A valid faculty profile API path is required.'];
        if (trim(config('SINQ_API_TOKEN')) === '') return ['A private Synk API token is required.'];
        return [];
    }

    public static function syncLocal(): array
    {
        $issues = self::localConfigurationIssues();
        if ($issues) throw new RuntimeException(implode(' ', $issues));
        return self::syncWithSource(self::localSourceConfig(), true);
    }

    public static function sync(): array
    {
        $issues = self::configurationIssues();
        if ($issues) throw new RuntimeException(implode(' ', $issues));
        return self::syncWithSource(self::remoteSourceConfig(), false);
    }

    private static function syncWithSource(array $source, bool $localTest): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('The PHP cURL extension is required.');

        $records = [];
        $offset = 0;
        $expectedTotal = null;
        do {
            $page = self::requestPage($offset, $source, $localTest);
            $pagination = $page['pagination'];
            if ($expectedTotal === null) $expectedTotal = $pagination['total'];
            if ($pagination['total'] !== $expectedTotal || $pagination['limit'] !== self::PAGE_SIZE || $pagination['offset'] !== $offset) {
                throw new RuntimeException('The source returned inconsistent pagination. Previous records were preserved.');
            }
            foreach ($page['facultyProfiles'] as $record) {
                $id = $record['id'];
                if (isset($records[$id])) throw new RuntimeException('The source returned duplicate faculty records. Previous records were preserved.');
                $records[$id] = $record;
            }
            $offset += count($page['facultyProfiles']);
            if ($offset < $expectedTotal && count($page['facultyProfiles']) !== self::PAGE_SIZE) {
                throw new RuntimeException('The source returned an incomplete page. Previous records were preserved.');
            }
        } while ($offset < $expectedTotal);

        $snapshot = self::validateSnapshot([
            'schemaVersion' => 1,
            'sourceId' => 'sinq',
            'observedAt' => gmdate(DATE_ATOM),
            'facultyProfiles' => array_values($records),
        ]);
        self::save($snapshot, $localTest);
        return $snapshot;
    }

    public static function current(bool $localTest = false): array
    {
        $path = self::snapshotPath($localTest);
        if (!is_file($path)) return ['schemaVersion' => 1, 'sourceId' => 'sinq', 'observedAt' => null, 'facultyProfiles' => []];
        if (filesize($path) > 20971520) throw new RuntimeException('Stored faculty profiles are invalid.');
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Stored faculty profiles are unavailable.');
        try { $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('Stored faculty profiles are invalid.'); }
        if (!is_array($decoded)) throw new RuntimeException('Stored faculty profiles are invalid.');
        return self::validateSnapshot($decoded);
    }

    public static function response(array $query = []): array
    {
        $limit = filter_var($query['limit'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $offset = filter_var($query['offset'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_RECORDS]]);
        if ($limit === false || $offset === false) throw new RuntimeException('Faculty profile paging request is invalid.');
        $search = trim((string)($query['q'] ?? ''));
        if (strlen($search) > 400) throw new RuntimeException('Faculty profile search is too long.');
        $localTest = filter_var(config('SINQ_FACULTY_LOCAL_TEST_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $enabled = $localTest || filter_var(config('SINQ_FACULTY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return ['schemaVersion' => 1, 'sourceId' => 'sinq', 'mode' => 'production', 'status' => 'disabled', 'observedAt' => null, 'facultyProfiles' => [], 'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]];
        }
        $issues = $localTest ? self::localConfigurationIssues() : self::configurationIssues();
        if ($issues) {
            return ['schemaVersion' => 1, 'sourceId' => 'sinq', 'mode' => $localTest ? 'local_test' : 'production', 'status' => 'configuration_required', 'observedAt' => null, 'facultyProfiles' => [], 'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]];
        }
        $snapshot = self::current($localTest);
        $records = $snapshot['facultyProfiles'];
        if ($search !== '') {
            $records = array_values(array_filter($records, static function (array $record) use ($search): bool {
                return stripos(implode(' ', [$record['fullName'], $record['campus'], $record['college'], $record['rank']]), $search) !== false;
            }));
        }
        return [
            'schemaVersion' => 1,
            'sourceId' => 'sinq',
            'mode' => $localTest ? 'local_test' : 'production',
            'status' => $snapshot['observedAt'] === null ? 'awaiting_sync' : 'connected',
            'observedAt' => $snapshot['observedAt'],
            'facultyProfiles' => array_slice($records, $offset, $limit),
            'pagination' => ['total' => count($records), 'limit' => $limit, 'offset' => $offset],
        ];
    }

    private static function requestPage(int $offset, array $source, bool $localTest): array
    {
        $base = rtrim($source['baseUrl'], '/');
        $path = $source['path'];
        $parts = parse_url($path);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['limit'] = (string)self::PAGE_SIZE;
        $query['offset'] = (string)$offset;
        $url = $base . ($parts['path'] ?? '') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('The Synk faculty request could not be initialized.');
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $source['token'], 'User-Agent: PULSE-API/1.0'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $localTest ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(5, min(45, (int)config('INTEGRATION_TIMEOUT_SECONDS', '15'))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $success = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: '';
            if ($tooLarge) throw new RuntimeException('The Synk faculty response exceeded the supported size.');
            if ($success === false || $status < 200 || $status >= 300) throw new RuntimeException('The Synk faculty request failed. Previous records were preserved.');
            if (!str_contains(strtolower($contentType), 'application/json') && !str_contains(strtolower($contentType), '+json')) throw new RuntimeException('Synk did not return JSON.');
            try { $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new RuntimeException('Synk returned invalid JSON.'); }
            if (!is_array($payload)) throw new RuntimeException('Synk returned an invalid faculty page.');
            return self::validatePage($payload);
        } finally { curl_close($curl); }
    }

    private static function validatePage(array $input): array
    {
        if (($input['schemaVersion'] ?? null) !== 1 || ($input['sourceId'] ?? '') !== 'sinq' || !self::validTimestamp($input['observedAt'] ?? null)
            || !is_array($input['facultyProfiles'] ?? null) || !array_is_list($input['facultyProfiles']) || !is_array($input['pagination'] ?? null)) {
            throw new RuntimeException('Synk returned a faculty page with an unsupported format.');
        }
        $pagination = $input['pagination'];
        if (!is_int($pagination['total'] ?? null) || $pagination['total'] < 0 || $pagination['total'] > self::MAX_RECORDS
            || !is_int($pagination['limit'] ?? null) || !is_int($pagination['offset'] ?? null)) {
            throw new RuntimeException('Synk returned invalid pagination.');
        }
        $records = [];
        foreach ($input['facultyProfiles'] as $item) $records[] = self::validateRecord($item);
        return ['schemaVersion' => 1, 'sourceId' => 'sinq', 'observedAt' => $input['observedAt'], 'facultyProfiles' => $records, 'pagination' => $pagination];
    }

    private static function validateSnapshot(array $input): array
    {
        if (($input['schemaVersion'] ?? null) !== 1 || ($input['sourceId'] ?? '') !== 'sinq'
            || !self::validTimestamp($input['observedAt'] ?? null) || !is_array($input['facultyProfiles'] ?? null)
            || !array_is_list($input['facultyProfiles']) || count($input['facultyProfiles']) > self::MAX_RECORDS) {
            throw new RuntimeException('Stored faculty profiles use an unsupported format.');
        }
        $records = [];
        $seen = [];
        foreach ($input['facultyProfiles'] as $record) {
            $record = self::validateRecord($record);
            if (isset($seen[$record['id']])) throw new RuntimeException('Stored faculty profiles contain duplicate records.');
            $seen[$record['id']] = true;
            $records[] = $record;
        }
        return ['schemaVersion' => 1, 'sourceId' => 'sinq', 'observedAt' => $input['observedAt'], 'facultyProfiles' => $records];
    }

    private static function validateRecord(mixed $value): array
    {
        if (!is_array($value) || !is_int($value['id'] ?? null) || $value['id'] <= 0) throw new RuntimeException('Synk returned an invalid faculty identity.');
        foreach (['fullName' => 250, 'campus' => 200, 'college' => 200, 'rank' => 150] as $field => $max) {
            if (!is_string($value[$field] ?? null) || strlen($value[$field]) > $max * 4) throw new RuntimeException('Synk returned an invalid faculty profile field.');
        }
        if (trim($value['fullName']) === '' || !is_array($value['education'] ?? null)) throw new RuntimeException('Synk returned an incomplete faculty profile.');
        $education = [];
        foreach (['bachelor', 'master', 'doctoral'] as $level) {
            $source = $value['education'][$level] ?? null;
            if (!is_array($source)) throw new RuntimeException('Synk returned invalid faculty education data.');
            $education[$level] = [];
            foreach (['degree' => 500, 'institution' => 300, 'specialization' => 500, 'status' => 30] as $field => $max) {
                if (!is_string($source[$field] ?? null) || strlen($source[$field]) > $max * 4) throw new RuntimeException('Synk returned invalid faculty education data.');
                $education[$level][$field] = $source[$field];
            }
        }
        $updatedAt = $value['updatedAt'] ?? null;
        if ($updatedAt !== null && !self::validTimestamp($updatedAt)) throw new RuntimeException('Synk returned an invalid faculty update timestamp.');
        return ['id' => $value['id'], 'fullName' => $value['fullName'], 'campus' => $value['campus'], 'college' => $value['college'], 'rank' => $value['rank'], 'education' => $education, 'updatedAt' => $updatedAt];
    }

    private static function validTimestamp(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try { $date = new DateTimeImmutable($value); }
        catch (Exception) { return false; }
        $errors = DateTimeImmutable::getLastErrors();
        return ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) && $date->getTimestamp() <= time() + 300;
    }

    private static function validPath(string $path): bool
    {
        $parts = parse_url($path);
        return $parts !== false && isset($parts['path']) && preg_match('#^/[A-Za-z0-9_./-]+$#D', $parts['path'])
            && !preg_match('#(?:^|/)\.\.(?:/|$)#', $parts['path']) && !isset($parts['scheme']) && !isset($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private static function snapshotPath(bool $localTest = false): string
    {
        $directory = runtimeDirectory() . '/integrations';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Faculty profile storage is unavailable.');
        return $directory . ($localTest ? '/sinq-faculty-profiles.local-test.json' : '/sinq-faculty-profiles.json');
    }

    private static function save(array $snapshot, bool $localTest): void
    {
        $path = self::snapshotPath($localTest);
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Faculty profile storage could not be locked.');
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Faculty profiles could not be stored.');
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new RuntimeException('Faculty profiles could not be published.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function remoteSourceConfig(): array
    {
        return ['baseUrl' => trim(config('SINQ_API_BASE_URL')), 'path' => trim(config('SINQ_FACULTY_PATH', '/backend/pulse_api.php?resource=faculty-profiles')), 'token' => trim(config('SINQ_API_TOKEN'))];
    }

    private static function localSourceConfig(): array
    {
        return ['baseUrl' => trim(config('SINQ_FACULTY_LOCAL_TEST_BASE_URL', 'http://127.0.0.1:8081')), 'path' => trim(config('SINQ_FACULTY_LOCAL_TEST_PATH', '/backend/pulse_api.php?resource=faculty-profiles')), 'token' => trim(config('SINQ_FACULTY_LOCAL_TEST_TOKEN'))];
    }
}
