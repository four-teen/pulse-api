<?php
declare(strict_types=1);

// Keep this source export compatible with Synk's PHP 7.3 host.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Read-only PULSE export. Configure SYNK_PULSE_API_TOKEN in the PHP host
// environment; never store the token in this repository or in a URL.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Pulse-Export-Version: academic-php73-v1');

function pulse_api_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    pulse_api_respond(405, ['message' => 'Method not allowed.']);
}

$expectedToken = (string)(getenv('SYNK_PULSE_API_TOKEN') ?: ($_SERVER['SYNK_PULSE_API_TOKEN'] ?? ''));
if ($expectedToken === '' && is_file(__DIR__ . '/pulse_api_config.local.php')) {
    $pulseConfig = require __DIR__ . '/pulse_api_config.local.php';
    if (is_array($pulseConfig)) $expectedToken = (string)($pulseConfig['token'] ?? '');
}
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (strlen($expectedToken) < 32 || strncmp($expectedToken, 'REPLACE_', 8) === 0) $expectedToken = '';
if ($expectedToken === '' || !preg_match('/^Bearer\s+(.+)$/iD', trim($authorization), $matches)
    || !hash_equals($expectedToken, trim($matches[1]))) {
    pulse_api_respond($expectedToken === '' ? 503 : 401, ['message' => $expectedToken === '' ? 'Service is not configured.' : 'Unauthorized.']);
}

$resource = (string)($_GET['resource'] ?? '');
if (!in_array($resource, ['faculty-profiles', 'academic-counts'], true)) pulse_api_respond(404, ['message' => 'Not found.']);

$limit = filter_var($_GET['limit'] ?? '100', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]);
$offset = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
if ($limit === false || $offset === false) pulse_api_respond(400, ['message' => 'Invalid paging request.']);

try {
    // The override is defined only by PULSE's loopback development router.
    require defined('PULSE_SYNK_LOCAL_DB_FILE') ? PULSE_SYNK_LOCAL_DB_FILE : __DIR__ . '/db.php';

    if ($resource === 'academic-counts') {
        require_once __DIR__ . '/pulse_academic_counts.php';
        pulse_api_respond(200, SynkAcademicCounts::read($conn));
    }

    $tableExists = static function (mysqli $db, string $table): bool {
        $allowed = ['tbl_faculty', 'tbl_campus', 'tbl_college', 'tbl_designation', 'tbl_useraccount_faculty_links', 'tbl_faculty_profiles', 'tbl_faculty_record_profiles'];
        if (!in_array($table, $allowed, true)) return false;
        $result = $db->query("SHOW TABLES LIKE '{$table}'");
        $exists = $result instanceof mysqli_result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) $result->close();
        return $exists;
    };
    $columns = static function (mysqli $db, string $table): array {
        $result = $db->query("SHOW COLUMNS FROM `{$table}`");
        $names = [];
        while ($row = $result->fetch_assoc()) $names[strtolower((string)$row['Field'])] = true;
        $result->close();
        return $names;
    };
    $facultyTable = 'tbl_faculty';
    if (!$tableExists($conn, $facultyTable)) throw new RuntimeException('Faculty records are unavailable.');
    $facultyColumns = $columns($conn, $facultyTable);
    if (!isset($facultyColumns['faculty_id'])) throw new RuntimeException('Faculty records are unavailable.');

    $select = ['f.faculty_id'];
    foreach (['first_name', 'middle_name', 'last_name', 'ext_name', 'campus_id', 'college_id', 'status', 'record_updated_at'] as $field) {
        if (isset($facultyColumns[$field])) $select[] = "f.`{$field}`";
    }
    $joins = '';
    $campusJoin = $tableExists($conn, 'tbl_campus') && isset($facultyColumns['campus_id']);
    $collegeJoin = $tableExists($conn, 'tbl_college') && isset($facultyColumns['college_id']);
    if ($collegeJoin) {
        $select[] = "COALESCE(c.college_name, '') AS directory_college";
        $joins .= ' LEFT JOIN tbl_college c ON c.college_id = f.college_id';
        if ($campusJoin) {
            $select[] = "COALESCE(cp.campus_name, '') AS directory_campus";
            $joins .= ' LEFT JOIN tbl_campus cp ON cp.campus_id = COALESCE(f.campus_id, c.campus_id)';
        }
    } elseif ($campusJoin) {
        $select[] = "COALESCE(cp.campus_name, '') AS directory_campus";
        $joins .= ' LEFT JOIN tbl_campus cp ON cp.campus_id = f.campus_id';
    }
    if (isset($facultyColumns['designation_id']) && $tableExists($conn, 'tbl_designation')) {
        $designationColumns = $columns($conn, 'tbl_designation');
        if (isset($designationColumns['designation_id'], $designationColumns['designation_name'])) {
            $select[] = "COALESCE(d.designation_name, '') AS directory_rank";
            $joins .= ' LEFT JOIN tbl_designation d ON d.designation_id = f.designation_id';
        }
    }
    $where = isset($facultyColumns['status']) ? " WHERE (f.status = 'active' OR f.status IS NULL)" : '';
    $result = $conn->query('SELECT ' . implode(', ', $select) . " FROM tbl_faculty f{$joins}{$where} ORDER BY f.faculty_id");
    $facultyRows = [];
    while ($row = $result->fetch_assoc()) $facultyRows[] = $row;
    $result->close();

    $linkedUsers = [];
    if ($tableExists($conn, 'tbl_useraccount_faculty_links')) {
        $result = $conn->query('SELECT user_id, faculty_id FROM tbl_useraccount_faculty_links');
        while ($row = $result->fetch_assoc()) $linkedUsers[(int)$row['faculty_id']] = (int)$row['user_id'];
        $result->close();
    }

    $accountProfiles = [];
    if ($tableExists($conn, 'tbl_faculty_profiles')) {
        $profileFields = ['full_name', 'campus_name', 'college_name', 'faculty_rank', 'bachelor_degree', 'bachelor_institution', 'bachelor_specialization', 'bachelor_status', 'master_degree', 'master_institution', 'master_specialization', 'master_status', 'doctoral_degree', 'doctoral_institution', 'doctoral_specialization', 'doctoral_status', 'updated_at'];
        $profileColumns = $columns($conn, 'tbl_faculty_profiles');
        $selectedFields = array_values(array_filter($profileFields, static function (string $field) use ($profileColumns): bool {
            return isset($profileColumns[$field]);
        }));
        if (isset($profileColumns['user_id']) && $selectedFields) {
            $result = $conn->query('SELECT user_id, `' . implode('`, `', $selectedFields) . '` FROM tbl_faculty_profiles');
            while ($row = $result->fetch_assoc()) $accountProfiles[(int)$row['user_id']] = $row;
            $result->close();
        }
    }

    $recordProfiles = [];
    if ($tableExists($conn, 'tbl_faculty_record_profiles')) {
        $result = $conn->query('SELECT faculty_id, profile_data, updated_at FROM tbl_faculty_record_profiles');
        while ($row = $result->fetch_assoc()) {
            $decoded = json_decode((string)$row['profile_data'], true);
            if (is_array($decoded)) $recordProfiles[(int)$row['faculty_id']] = $decoded + ['updated_at' => $row['updated_at'] ?? null];
        }
        $result->close();
    }

    $education = static function (array $profile, string $level): array {
        $prefix = $level . '_';
        return [
            'degree' => trim((string)($profile[$prefix . 'degree'] ?? '')),
            'institution' => trim((string)($profile[$prefix . 'institution'] ?? '')),
            'specialization' => trim((string)($profile[$prefix . 'specialization'] ?? '')),
            'status' => trim((string)($profile[$prefix . 'status'] ?? '')),
        ];
    };
    $profileTimestamp = static function ($value): ?string {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
            return $date->format(DATE_ATOM);
        } catch (Throwable $error) { return null; }
    };
    $records = [];
    foreach ($facultyRows as $faculty) {
        $facultyId = (int)$faculty['faculty_id'];
        $profile = $accountProfiles[$linkedUsers[$facultyId] ?? 0] ?? $recordProfiles[$facultyId] ?? [];
        $nameParts = [];
        foreach (['first_name', 'middle_name', 'last_name', 'ext_name'] as $part) {
            $value = trim((string)($faculty[$part] ?? ''));
            if ($value !== '') $nameParts[] = $value;
        }
        $fullName = trim((string)($profile['full_name'] ?? '')) ?: implode(' ', $nameParts);
        if ($fullName === '') continue;
        $records[] = [
            'id' => $facultyId,
            'fullName' => $fullName,
            'campus' => trim((string)($profile['campus_name'] ?? '')) ?: trim((string)($faculty['directory_campus'] ?? '')),
            'college' => trim((string)($profile['college_name'] ?? '')) ?: trim((string)($faculty['directory_college'] ?? '')),
            'rank' => trim((string)($profile['faculty_rank'] ?? '')) ?: trim((string)($faculty['directory_rank'] ?? '')),
            'education' => [
                'bachelor' => $education($profile, 'bachelor'),
                'master' => $education($profile, 'master'),
                'doctoral' => $education($profile, 'doctoral'),
            ],
            'updatedAt' => $profileTimestamp($profile['updated_at'] ?? ($faculty['record_updated_at'] ?? null)),
        ];
    }
    usort($records, static function (array $left, array $right): int {
        return strcasecmp($left['fullName'], $right['fullName']);
    });
    $total = count($records);
    pulse_api_respond(200, [
        'schemaVersion' => 1,
        'sourceId' => 'sinq',
        'observedAt' => gmdate(DATE_ATOM),
        'facultyProfiles' => array_slice($records, $offset, $limit),
        'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
    ]);
} catch (Throwable $error) {
    error_log('Synk PULSE API: ' . get_class($error));
    pulse_api_respond(500, ['message' => $resource === 'academic-counts' ? 'Academic counts are temporarily unavailable.' : 'Faculty profiles are temporarily unavailable.']);
}
