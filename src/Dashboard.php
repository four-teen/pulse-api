<?php
declare(strict_types=1);

require_once __DIR__ . '/Integrations.php';

/** Reads private snapshots only. Dashboard requests never contact another system. */
function pulsePresidentDashboard(): array
{
    $store = new PulseSnapshotStore();
    $sources = [];
    $snapshots = [];
    $maxAge = max(60, (int) config('DASHBOARD_DATA_MAX_AGE_SECONDS', '86400'));
    $periodKey = trim(config('DASHBOARD_PERIOD_KEY'));
    foreach (PulseSourceRegistry::all() as $id => $source) {
        $snapshot = null;
        $status = 'not_connected';
        if ($source['enabled']) {
            if (PulseSourceRegistry::issues($source)) $status = 'configuration_required';
            else {
                try {
                    $snapshot = $store->load($id);
                    $status = $snapshot === null ? 'awaiting_sync' : (time() - strtotime($snapshot['observedAt']) > $maxAge ? 'stale' : 'connected');
                    if ($snapshot !== null && $periodKey === '') { $status = 'configuration_required'; $snapshot = null; }
                    elseif ($snapshot !== null && $snapshot['periodKey'] !== $periodKey) { $status = 'period_mismatch'; $snapshot = null; }
                } catch (Throwable) { $status = 'invalid_data'; }
            }
        }
        if ($snapshot !== null) $snapshots[$id] = $snapshot;
        // Credentials, endpoint paths and detailed configuration never leave PHP.
        $sources[] = ['id' => $id, 'name' => $source['name'], 'website' => $source['website'], 'status' => $status, 'updatedAt' => $snapshot['observedAt'] ?? null];
    }

    $owners = [];
    foreach (pulseMetricGroups() as $group => $fields) {
        $configured = strtolower(trim(config('PULSE_' . strtoupper($group) . '_SOURCE')));
        $owners[$group] = isset(PulseSourceRegistry::all()[$configured]) ? $configured : null;
    }
    $campuses = [];
    $available = 0;
    $total = 0;
    $usedSources = [];
    foreach (pulseCampusCatalogue() as $campus) {
        $groups = pulseEmptyGroups();
        foreach ($groups as $group => $fields) {
            $owner = $owners[$group];
            if ($owner !== null && isset($snapshots[$owner])) {
                foreach ($snapshots[$owner]['campuses'] as $record) {
                    if ($record['id'] === $campus['id'] && isset($record[$group])) {
                        $groups[$group] = $record[$group];
                        if (count(array_filter($record[$group], static fn ($value) => $value !== null)) > 0) $usedSources[$owner] = true;
                        break;
                    }
                }
            }
            foreach ($groups[$group] as $value) { $total++; if ($value !== null) $available++; }
        }
        $campuses[] = $campus + $groups['instruction'] + $groups['finance'] + [
            'research' => $groups['research'], 'extension' => $groups['extension'],
            'production' => $groups['production'], 'inventory' => $groups['inventory'],
        ];
    }
    $updatedAt = null;
    $stale = false;
    foreach (array_keys($usedSources) as $id) {
        $observedAt = $snapshots[$id]['observedAt'];
        if ($updatedAt === null || strtotime($observedAt) < strtotime($updatedAt)) $updatedAt = $observedAt;
        if (time() - strtotime($observedAt) > $maxAge) $stale = true;
    }
    return [
        'schemaVersion' => 1,
        'generatedAt' => gmdate(DATE_ATOM),
        'updatedAt' => $updatedAt,
        'dataState' => $available === 0 ? 'awaiting_data' : ($stale ? 'stale' : ($available < $total ? 'partial' : 'ready')),
        'periodLabel' => config('DASHBOARD_PERIOD_LABEL') ?: null,
        'periodKey' => $periodKey ?: null,
        'coverage' => ['availableMetrics' => $available, 'totalMetrics' => $total],
        'categorySources' => $owners,
        'campuses' => $campuses,
        'sources' => $sources,
    ];
}
