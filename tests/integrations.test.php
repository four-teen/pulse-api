<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Dashboard.php';

$storage = sys_get_temp_dir() . '/pulse-integrations-test-' . bin2hex(random_bytes(8));
putenv('PULSE_STORAGE_PATH=' . $storage);
putenv('SINQ_ACADEMICS_ENABLED=false');
foreach (['SINQ', 'ORACLE', 'PAD'] as $prefix) {
    putenv($prefix . '_ENABLED=false');
    putenv($prefix . '_API_BASE_URL=');
    putenv($prefix . '_SUMMARY_PATH=');
    putenv($prefix . '_API_TOKEN=');
    putenv($prefix . '_AUTH_TYPE=bearer');
}
foreach (array_keys(pulseMetricGroups()) as $group) putenv('PULSE_' . strtoupper($group) . '_SOURCE=');
putenv('DASHBOARD_DATA_MAX_AGE_SECONDS=86400');
putenv('DASHBOARD_PERIOD_KEY=test-current');
$checks = 0;
function check(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejects(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function configured(string $id): void {
    $prefix = strtoupper($id);
    $website = PulseSourceRegistry::all()[$id]['website'];
    putenv($prefix . '_ENABLED=true');
    putenv($prefix . '_API_BASE_URL=' . rtrim($website, '/') . '/api');
    putenv($prefix . '_SUMMARY_PATH=/president-summary');
    putenv($prefix . '_API_ALLOWED_HOST=' . parse_url($website, PHP_URL_HOST));
    putenv($prefix . '_API_TOKEN=test-only-integration-secret');
}

try {
    $dashboard = pulsePresidentDashboard();
    check(count($dashboard['campuses']) === 7, 'The campus catalogue must contain seven records.');
    check(array_column($dashboard['sources'], 'id') === ['sinq', 'oracle', 'pad'], 'The requested sources must be registered.');
    check($dashboard['dataState'] === 'awaiting_data' && $dashboard['updatedAt'] === null, 'A clean installation must not claim to have records.');
    check($dashboard['coverage']['availableMetrics'] === 0, 'Unavailable metrics must not be initialized to zero.');
    check($dashboard['campuses'][0]['students'] === null && $dashboard['campuses'][0]['inventory']['assets'] === null, 'Unknown values must remain null.');
    foreach (PulseSourceRegistry::all() as $source) rejects(fn () => (new PulseNormalizedJsonConnector($source))->fetchSnapshot(), 'Disabled sources must fail before making a request.');

    configured('sinq');
    $config = PulseSourceRegistry::all()['sinq'];
    check(PulseSourceRegistry::issues($config) === [], 'A confirmed configuration should validate.');
    foreach (['http://sinq.sksu-orms.net', 'https://unapproved.example', 'https://user:password@sinq.sksu-orms.net', 'https://sinq.sksu-orms.net?token=private'] as $url) {
        $invalid = $config; $invalid['baseUrl'] = $url;
        check(PulseSourceRegistry::issues($invalid) !== [], 'Unsafe API URLs must be rejected.');
    }
    $invalid = $config; $invalid['summaryPath'] = 'https://unapproved.example/summary';
    rejects(fn () => (new PulseNormalizedJsonConnector($invalid))->fetchSnapshot(), 'An invalid endpoint must fail before making a request.');

    $payload = ['schemaVersion' => 1, 'sourceId' => 'sinq', 'periodKey' => 'test-current', 'observedAt' => gmdate(DATE_ATOM, time() - 5), 'campuses' => [
        ['id' => 'access', 'instruction' => ['students' => 120, 'previousStudents' => 100, 'faculty' => 10, 'programs' => 3, 'retention' => 90], 'research' => ['active' => 2]],
    ]];
    $store = new PulseSnapshotStore();
    $academicPeriod = $payload; $academicPeriod['periodKey'] = '2026-2027:1';
    check(pulseValidateSnapshot($academicPeriod, 'sinq')['periodKey'] === '2026-2027:1', 'Summary snapshots must support the administrator academic term key.');
    $store->save('sinq', $payload);
    check($store->load('sinq')['campuses'][0]['instruction']['students'] === 120, 'Validated snapshots must round-trip.');
    check(pulsePresidentDashboard()['campuses'][0]['students'] === null, 'No category ownership may be guessed from a source name.');
    putenv('PULSE_INSTRUCTION_SOURCE=sinq');
    $dashboard = pulsePresidentDashboard();
    check($dashboard['campuses'][0]['programs'] === 3 && $dashboard['campuses'][1]['programs'] === null, 'Campus values must not leak into another campus.');
    check($dashboard['campuses'][0]['students'] === null && $dashboard['campuses'][0]['faculty'] === null, 'Summary snapshots must never substitute for current-term academic headcounts.');
    check($dashboard['campuses'][0]['research']['active'] === null, 'Unassigned categories must remain unknown.');
    check($dashboard['dataState'] === 'partial', 'Partial data must be marked honestly.');
    putenv('DASHBOARD_PERIOD_KEY=another-period');
    check(pulsePresidentDashboard()['sources'][0]['status'] === 'period_mismatch' && pulsePresidentDashboard()['campuses'][0]['students'] === null, 'Reporting periods must never be silently mixed.');
    putenv('DASHBOARD_PERIOD_KEY=test-current');
    check(!str_contains(json_encode($dashboard), 'test-only-integration-secret'), 'Public records must never contain API credentials.');

    configured('oracle');
    $oracle = $payload; $oracle['sourceId'] = 'oracle'; $oracle['campuses'][0]['instruction']['programs'] = 5;
    $store->save('oracle', $oracle);
    putenv('PULSE_INSTRUCTION_SOURCE=oracle');
    check(pulsePresidentDashboard()['campuses'][0]['programs'] === 5, 'Overlapping sources must not be added together.');
    putenv('PULSE_INSTRUCTION_SOURCE=sinq');

    $invalid = $payload; $invalid['campuses'][0]['instruction']['students'] = '120';
    rejects(fn () => $store->save('sinq', $invalid), 'String metrics must be rejected.');
    $invalid = $payload; $invalid['campuses'][0]['instruction']['students'] = -1;
    rejects(fn () => $store->save('sinq', $invalid), 'Negative counts must be rejected.');
    $invalid = $payload; $invalid['campuses'][0]['instruction']['retention'] = 101;
    rejects(fn () => $store->save('sinq', $invalid), 'Out-of-range percentages must be rejected.');
    $invalid = $payload; $invalid['campuses'][0]['instruction']['studentz'] = 5;
    rejects(fn () => $store->save('sinq', $invalid), 'Unknown field names must be rejected.');
    $invalid = $payload; $invalid['campuses'][] = $invalid['campuses'][0];
    rejects(fn () => $store->save('sinq', $invalid), 'Duplicate campuses must be rejected.');
    $invalid = $payload; $invalid['campuses'][0]['id'] = 'unknown';
    rejects(fn () => $store->save('sinq', $invalid), 'Unknown campuses must be rejected.');
    $invalid = $payload; $invalid['observedAt'] = gmdate(DATE_ATOM, time() + 600);
    rejects(fn () => $store->save('sinq', $invalid), 'Future records must be rejected.');
    $invalid = $payload; $invalid['observedAt'] = '2026-02-30T12:00:00Z';
    rejects(fn () => $store->save('sinq', $invalid), 'Invalid calendar dates must be rejected.');
    $invalid = $payload; $invalid['observedAt'] = gmdate(DATE_ATOM, time() - 86400);
    rejects(fn () => $store->save('sinq', $invalid), 'An old sync must not overwrite a newer one.');
    check($store->load('sinq')['campuses'][0]['instruction']['students'] === 120, 'Failed imports must preserve the known-good snapshot.');

    $old = $payload; $old['sourceId'] = 'pad'; $old['observedAt'] = gmdate(DATE_ATOM, time() - 172800);
    configured('pad'); $store->save('pad', $old); putenv('PULSE_INSTRUCTION_SOURCE=pad');
    check(pulsePresidentDashboard()['dataState'] === 'stale', 'Overdue snapshots must be marked stale.');
    putenv('PAD_ENABLED=false');
    check(pulsePresidentDashboard()['campuses'][0]['students'] === null, 'Disabling a source must also stop serving its records.');
    putenv('PULSE_INSTRUCTION_SOURCE=sinq');
    file_put_contents($storage . '/integrations/sinq.snapshot.json', '{broken');
    $dashboard = pulsePresidentDashboard();
    check($dashboard['sources'][0]['status'] === 'invalid_data' && $dashboard['campuses'][0]['students'] === null, 'Corrupt snapshots must fail closed.');
    echo "Passed {$checks} integration checks. No external HTTP requests were made.\n";
} finally {
    // Delete only this test's generated files inside its verified temporary directory.
    $real = realpath($storage);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($real !== false && $temporaryRoot !== false && str_starts_with($real, $temporaryRoot . DIRECTORY_SEPARATOR . 'pulse-integrations-test-')) {
        foreach (glob($storage . '/integrations/*') ?: [] as $file) if (is_file($file)) unlink($file);
        if (is_dir($storage . '/integrations')) rmdir($storage . '/integrations');
        rmdir($storage);
    }
}
