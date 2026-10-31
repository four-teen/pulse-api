<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Integrations.php';

$options = getopt('', ['source:', 'check', 'help']);
if (isset($options['help']) || (!isset($options['source']) && !isset($options['check']))) {
    echo "PULSE integration preparation\n\nphp bin/sync.php --check            Validate configuration without making requests\nphp bin/sync.php --source=sinq      Fetch one explicitly enabled, configured source\n\nSupported source IDs: sinq, oracle, pad. All sources are disabled by default.\n";
    exit(0);
}
if (isset($options['check'])) {
    foreach (PulseSourceRegistry::all() as $source) {
        $state = !$source['enabled'] ? 'disabled' : (PulseSourceRegistry::issues($source) ? 'configuration required' : 'ready for an explicit sync');
        echo $source['name'] . ': ' . $state . "\n";
    }
    echo "No network requests were made.\n";
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
