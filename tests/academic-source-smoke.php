<?php
declare(strict_types=1);

// Explicit opt-in: never reads the real source during the isolated test suite.
if (!in_array('--local', $argv, true)) { fwrite(STDERR, "Use --local to verify local aggregate counts without changing Synk.\n"); exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Records.php';
require dirname(__DIR__) . '/src/SynkAcademics.php';
if (config('APP_ENV') !== 'development') throw new RuntimeException('Local development only.');
$data = PulseSynkAcademics::read();
if ($data['status'] !== 'connected') throw new RuntimeException('The local academic reader is unavailable.');
$settings = require dirname(__DIR__, 2) . '/synk/backend/db_config.local.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($settings['host'], $settings['username'], $settings['password'], $settings['database']);
$db->set_charset('utf8mb4');
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try {
    $term = $db->query('SELECT current_ay_id,current_semester FROM tbl_academic_settings')->fetch_all(MYSQLI_ASSOC);
    if (count($term) !== 1 || (int)$term[0]['current_ay_id'] !== $data['term']['academicYearId'] || (int)$term[0]['current_semester'] !== $data['term']['semester']) throw new RuntimeException('Administrator term changed during verification; retry.');
    $year = (int)$term[0]['current_ay_id']; $semester = (int)$term[0]['current_semester'];
    // Independent SQL aggregates use the Scheduler list joins and exact term scope.
    $facultyFrom = "FROM tbl_college_faculty cf JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id
        JOIN tbl_college c ON c.college_id=cf.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE cf.ay_id={$year} AND cf.semester={$semester} AND LOWER(TRIM(cf.status))='active'";
    $expected = (int)$db->query("SELECT COUNT(DISTINCT cf.faculty_id) AS total {$facultyFrom}")->fetch_assoc()['total'];
    if ($expected !== $data['faculty']['total']) throw new RuntimeException('Faculty university total does not match the independent SQL query.');
    $collegeCounts = $db->query("SELECT cf.college_id,COUNT(DISTINCT cf.faculty_id) AS total {$facultyFrom} GROUP BY cf.college_id")->fetch_all(MYSQLI_ASSOC);
    $collegeMap = array_column($collegeCounts, 'total', 'college_id');
    foreach ($data['colleges'] as $college) if ($college['faculty'] !== (int)($collegeMap[$college['id']] ?? 0)) throw new RuntimeException('College faculty total does not match the independent SQL query.');
    // Independent SQL cross-check uses trimmed, case-normalized structured names.
    // Full Unicode composition/punctuation cases are covered by the isolated unit tests.
    $nameParts = [];
    foreach (['last_name', 'first_name', 'middle_name', 'suffix_name'] as $field) $nameParts[] = "UPPER(TRIM(COALESCE(s.{$field},'')))";
    // Fixed separators preserve structured name fields and case/accent distinctions.
    $nameKey = "BINARY CONCAT_WS('|'," . implode(',', $nameParts) . ')';
    $studentFrom = "FROM tbl_student_management_enrolled_subjects es JOIN tbl_student_management s ON s.student_id=es.student_id
        WHERE es.ay_id={$year} AND es.semester={$semester} AND es.is_active=1";
    $expectedStudents = (int)$db->query("SELECT COUNT(DISTINCT {$nameKey}) AS total {$studentFrom}")->fetch_assoc()['total'];
    if ($expectedStudents !== $data['students']['total']) throw new RuntimeException('Student name count differs from independent SQL normalization; review Unicode composition.');
    foreach (['college' => 'colleges', 'program' => 'programs'] as $scope => $list) {
        $groups = $db->query("SELECT es.{$scope}_id AS unit_id,COUNT(DISTINCT {$nameKey}) AS total {$studentFrom} GROUP BY es.{$scope}_id")->fetch_all(MYSQLI_ASSOC);
        $map = array_column($groups, 'total', 'unit_id');
        foreach ($data[$list] as $row) if ($row['students'] !== (int)($map[$row['id']] ?? 0)) throw new RuntimeException('Student unit total does not match independent SQL.');
    }
    echo 'Independent source verification passed: ' . $expected . ' faculty, ' . $expectedStudents . ' student names; all college and program totals agree.', PHP_EOL;
    echo 'Source engines: ', json_encode(array_unique(array_column($db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('tbl_academic_settings','tbl_academic_years','tbl_campus','tbl_college','tbl_program','tbl_college_faculty','tbl_faculty','tbl_student_management','tbl_student_management_enrolled_subjects')")->fetch_all(MYSQLI_ASSOC), 'ENGINE'))), PHP_EOL;
} finally { $db->rollback(); $db->close(); }
