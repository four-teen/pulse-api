<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Integrations.php';

$options = getopt('', ['source:', 'faculty-profiles', 'faculty-profiles-local', 'check', 'help']);
if (isset($options['help']) || (!isset($options['source']) && !isset($options['faculty-profiles']) && !isset($options['faculty-profiles-local']) && !isset($options['check']))) {
    echo "PULSE integration preparation\n\nphp bin/sync.php --check                       Validate configuration without making requests\nphp bin/sync.php --source=sinq                 Fetch one explicitly enabled summary source\nphp bin/sync.php --faculty-profiles            Fetch remote Synk profiles when enabled\nphp bin/sync.php --faculty-profiles-local      Fetch local loopback Synk profiles in development only\n\nSources are disabled by default. Sync commands are operator-run and read-only.\n";
    exit(0);
}
if (isset($options['check'])) {
    foreach (PulseSourceRegistry::all() as $source) {
        $state = !$source['enabled'] ? 'disabled' : (PulseSourceRegistry::issues($source) ? 'configuration required' : 'ready for an explicit sync');
        echo $source['name'] . ': ' . $state . "\n";
    }
    require_once dirname(__DIR__) . '/src/FacultyProfiles.php';
    $facultyIssues = PulseFacultyProfiles::configurationIssues();
    echo 'Synk faculty profiles: ' . (!$facultyIssues ? 'ready for an explicit sync' : (filter_var(config('SINQ_FACULTY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN) ? 'configuration required' : 'disabled')) . "\n";
    $localIssues = PulseFacultyProfiles::localConfigurationIssues();
    echo 'Synk local loopback test: ' . (!$localIssues ? 'ready for an explicit local sync' : (filter_var(config('SINQ_FACULTY_LOCAL_TEST_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN) ? 'configuration required' : 'disabled')) . "\n";
    echo "No network requests were made.\n";
    exit(0);
}
if (isset($options['faculty-profiles'])) {
    require_once dirname(__DIR__) . '/src/FacultyProfiles.php';
    try {
        $snapshot = PulseFacultyProfiles::sync();
        echo 'Synk faculty profiles: ' . count($snapshot['facultyProfiles']) . " records saved.\n";
    } catch (Throwable $error) {
        $message = $error instanceof RuntimeException ? $error->getMessage() : 'Faculty profile sync failed. Previous records were preserved.';
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    exit(0);
}
if (isset($options['faculty-profiles-local'])) {
    require_once dirname(__DIR__) . '/src/FacultyProfiles.php';
    try {
        $snapshot = PulseFacultyProfiles::syncLocal();
        echo 'Local Synk faculty profiles: ' . count($snapshot['facultyProfiles']) . " records saved to the local-test snapshot.\n";
    } catch (Throwable $error) {
        $message = $error instanceof RuntimeException ? $error->getMessage() : 'Local faculty profile sync failed. Previous local-test records were preserved.';
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    exit(0);
}
$id = $options['source'];
$source = is_string($id) ? (PulseSourceRegistry::all()[$id] ?? null) : null;
if ($source === null) { fwrite(STDERR, "Unknown source. Use sinq, oracle, or pad.\n"); exit(1); }
try {
    $connector = new PulseNormalizedJsonConnector($source);
    $snapshot = $connector->fetchSnapshot();
    (new PulseSnapshotStore())->save($source['id'], $snapshot);
    echo $source['name'] . ": validated records saved.\n";
} catch (Throwable $error) {
    // These errors are controlled messages; vendor response bodies and tokens are never printed.
    $message = $error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'Integration sync failed. Previous records were preserved.';
    fwrite(STDERR, $message . "\n");
    exit(1);
}
