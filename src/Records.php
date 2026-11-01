<?php
declare(strict_types=1);

/** Stable PULSE identifiers. Source-specific campus codes must be mapped to these. */
function pulseCampusCatalogue(): array
{
    return [
        ['id' => 'access', 'name' => 'ACCESS', 'mark' => 'AC', 'color' => '#496e46', 'tint' => '#eaf0e2'],
        ['id' => 'isulan', 'name' => 'Isulan', 'mark' => 'IS', 'color' => '#a4854b', 'tint' => '#f4efdf'],
        ['id' => 'tacurong', 'name' => 'Tacurong', 'mark' => 'TC', 'color' => '#527b7f', 'tint' => '#e9f0ef'],
        ['id' => 'kalamansig', 'name' => 'Kalamansig', 'mark' => 'KL', 'color' => '#65836a', 'tint' => '#eaf0e8'],
        ['id' => 'lutayan', 'name' => 'Lutayan', 'mark' => 'LT', 'color' => '#9a785f', 'tint' => '#f3ebe3'],
        ['id' => 'palimbang', 'name' => 'Palimbang', 'mark' => 'PL', 'color' => '#71829d', 'tint' => '#ebedf4'],
        ['id' => 'bagumbayan', 'name' => 'Bagumbayan', 'mark' => 'BG', 'color' => '#8b7a91', 'tint' => '#f0ebf1'],
    ];
}

/** Metric names and constraints for the version-one integration contract. */
function pulseMetricGroups(): array
{
    return [
        'instruction' => ['students' => 'count', 'previousStudents' => 'count', 'faculty' => 'count', 'programs' => 'count', 'retention' => 'percent'],
        'research' => ['active' => 'count', 'completed' => 'count', 'publications' => 'count', 'funding' => 'amount'],
        'extension' => ['active' => 'count', 'completed' => 'count', 'communities' => 'count', 'beneficiaries' => 'count', 'sessions' => 'count'],
        'production' => ['enterprises' => 'count', 'revenue' => 'amount', 'expenses' => 'amount', 'target' => 'amount'],
        'inventory' => ['buildings' => 'count', 'classrooms' => 'count', 'laboratories' => 'count', 'assets' => 'count', 'serviceable' => 'count', 'value' => 'amount'],
        'finance' => ['budget' => 'amount', 'utilization' => 'percent'],
    ];
}

function pulseEmptyGroups(): array
{
    $groups = [];
    foreach (pulseMetricGroups() as $name => $fields) $groups[$name] = array_fill_keys(array_keys($fields), null);
    return $groups;
}

/** Reject malformed source data before it can replace a known-good snapshot. */
function pulseValidateSnapshot(array $input, string $sourceId): array
{
    if (($input['schemaVersion'] ?? null) !== 1 || ($input['sourceId'] ?? '') !== $sourceId) {
        throw new InvalidArgumentException('Unsupported snapshot version or source identifier.');
    }
    $periodKey = $input['periodKey'] ?? null;
    if (!is_string($periodKey) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,79}$/D', $periodKey)) throw new InvalidArgumentException('A reporting period identifier is required.');
    $observedAt = $input['observedAt'] ?? null;
    if (!is_string($observedAt) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $observedAt)) {
        throw new InvalidArgumentException('A valid observedAt timestamp is required.');
    }
    try { $timestamp = new DateTimeImmutable($observedAt); }
    catch (Exception) { throw new InvalidArgumentException('The snapshot timestamp is invalid.'); }
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $timestamp->getTimestamp() > time() + 300) {
        throw new InvalidArgumentException('The snapshot timestamp is invalid or in the future.');
    }
    $rows = $input['campuses'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || count($rows) > 7) {
        throw new InvalidArgumentException('The snapshot must contain a campus list with no more than seven records.');
    }
    $knownIds = array_column(pulseCampusCatalogue(), 'id');
    $records = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !in_array($row['id'], $knownIds, true) || isset($records[$row['id']])) {
            throw new InvalidArgumentException('Unknown or duplicate campus identifier.');
        }
        $normalized = ['id' => $row['id']];
        foreach (pulseMetricGroups() as $group => $fields) {
            if (!array_key_exists($group, $row)) continue;
            if (!is_array($row[$group]) || (array_is_list($row[$group]) && $row[$group] !== [])) {
                throw new InvalidArgumentException('Metric groups must be objects.');
            }
            if (array_diff(array_keys($row[$group]), array_keys($fields))) {
                throw new InvalidArgumentException('A metric name does not match the integration contract.');
            }
            $normalized[$group] = array_fill_keys(array_keys($fields), null);
            foreach ($row[$group] as $field => $value) {
                if ($value === null) continue;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1000000000000000) {
                    throw new InvalidArgumentException('Metrics must be nonnegative finite numbers or null.');
                }
                if ($fields[$field] === 'count' && (!is_int($value) || $value > 1000000000)) {
                    throw new InvalidArgumentException('Counts must be nonnegative integers within the supported range.');
                }
                if ($fields[$field] === 'percent' && $value > 100) throw new InvalidArgumentException('Percentages must be between zero and 100.');
                $normalized[$group][$field] = $value;
            }
        }
        $inventory = $normalized['inventory'] ?? [];
        if (isset($inventory['serviceable'], $inventory['assets']) && $inventory['serviceable'] > $inventory['assets']) {
            throw new InvalidArgumentException('Serviceable assets cannot exceed recorded assets.');
        }
        $records[$row['id']] = $normalized;
    }
    return ['schemaVersion' => 1, 'sourceId' => $sourceId, 'periodKey' => $periodKey, 'observedAt' => $timestamp->format(DATE_ATOM), 'campuses' => array_values($records)];
}
