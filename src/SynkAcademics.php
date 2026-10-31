<?php
declare(strict_types=1);

/** PULSE-owned reader. Never includes Synk's application bootstrap or writes its data. */
final class PulseSynkAcademics
{
    private const MAX_ROWS = 200000;

    public static function enabled(): bool
    {
        return filter_var(config('SINQ_ACADEMICS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function unavailable(string $status = 'unavailable'): array
    {
        $counts = ['total' => null, 'byCampus' => array_fill_keys(array_column(pulseCampusCatalogue(), 'id'), null)];
        return ['status' => $status, 'term' => null, 'retrievedAt' => null,
            'studentBasis' => self::studentBasis(), 'faculty' => $counts, 'students' => $counts,
            'colleges' => [], 'programs' => [], 'quality' => ['facultyWithMultipleColleges' => 0, 'studentNamesWithMultipleIds' => 0]];
    }

    public static function studentBasis(): string
    {
        return 'active_class_lists';
    }

    public static function read(): array
    {
        if (!self::enabled()) return self::unavailable('disabled');
        $db = null;
        try {
            if (!extension_loaded('mysqli') || !function_exists('mb_strtoupper')) throw new RuntimeException('The mysqli and mbstring extensions are required.');
            $settings = self::databaseSettings();
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $db->options(MYSQLI_OPT_READ_TIMEOUT, 15);
            $db->real_connect($settings['host'], $settings['username'], $settings['password'], $settings['database'], $settings['port']);
            $db->set_charset('utf8mb4');
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
            $engines = self::rows($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
                AND TABLE_NAME IN ('tbl_academic_settings','tbl_academic_years','tbl_campus','tbl_college','tbl_program',
                'tbl_college_faculty','tbl_faculty','tbl_student_management','tbl_student_management_enrolled_subjects')");
            if (count($engines) !== 9 || array_filter($engines, static fn ($row) => strcasecmp((string)$row['ENGINE'], 'InnoDB') !== 0)) {
                throw new RuntimeException('Consistent transactional source tables are required.');
            }
            $terms = self::rows($db, 'SELECT s.current_ay_id AS ay_id,s.current_semester AS semester,ay.ay AS academic_year
                FROM tbl_academic_settings s LEFT JOIN tbl_academic_years ay ON ay.ay_id=s.current_ay_id');
            if (count($terms) !== 1) throw new RuntimeException('One administrator academic setting is required.');
            $term = self::term($terms[0]);
            $campuses = self::rows($db, 'SELECT campus_id,campus_name FROM tbl_campus');
            $colleges = self::rows($db, 'SELECT college_id,college_name,college_code,campus_id FROM tbl_college');
            $programs = self::rows($db, 'SELECT program_id,program_name,program_code,major,college_id FROM tbl_program');
            $faculty = self::rows($db, "SELECT DISTINCT cf.faculty_id,cf.college_id,cf.ay_id,cf.semester,cf.status AS roster_status
                FROM tbl_college_faculty cf JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id
                JOIN tbl_college c ON c.college_id=cf.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
                WHERE cf.ay_id=? AND cf.semester=? AND LOWER(TRIM(cf.status))='active'", [$term['academicYearId'], $term['semester']]);
            $students = self::rows($db, "SELECT DISTINCT s.student_id,s.last_name,s.first_name,s.middle_name,s.suffix_name,
                    es.program_id,es.college_id,es.campus_id,es.ay_id,es.semester,es.is_active,'class_list' AS source_kind
                    FROM tbl_student_management_enrolled_subjects es JOIN tbl_student_management s ON s.student_id=es.student_id
                    WHERE es.ay_id=? AND es.semester=? AND es.is_active=1", [$term['academicYearId'], $term['semester']]);
            $result = self::aggregate($term, $campuses, $colleges, $programs, $faculty, $students, self::studentBasis());
            $db->rollback();
            return $result;
        } catch (Throwable $error) {
            // SQL diagnostics, names, database credentials, and filesystem paths stay private.
            error_log('PULSE Synk academic counts unavailable: ' . get_class($error));
            if ($db instanceof mysqli) { try { $db->rollback(); } catch (Throwable) {} }
            return self::unavailable();
        } finally {
            if ($db instanceof mysqli) { try { $db->close(); } catch (Throwable) {} }
        }
    }

    private static function databaseSettings(): array
    {
        if (config('APP_ENV') === 'development' && config('SINQ_ACADEMICS_DB_NAME') === '') {
            // Read only the existing local configuration array, never db.php or schema helpers.
            $file = dirname(__DIR__, 2) . '/synk/backend/db_config.local.php';
            if (!is_file($file)) throw new RuntimeException('Local Synk database configuration is missing.');
            $settings = require $file;
        } else {
            $settings = ['host' => config('SINQ_ACADEMICS_DB_HOST', 'localhost'), 'database' => config('SINQ_ACADEMICS_DB_NAME'),
                'username' => config('SINQ_ACADEMICS_DB_USER'), 'password' => config('SINQ_ACADEMICS_DB_PASSWORD'),
                'port' => config('SINQ_ACADEMICS_DB_PORT', '3306')];
        }
        if (!is_array($settings)) throw new RuntimeException('Invalid database configuration.');
        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (!is_string($settings[$key] ?? null) || ($key !== 'password' && trim($settings[$key]) === '')) throw new RuntimeException('Database configuration is incomplete.');
        }
        // These applications share this machine / cPanel account. Never send credentials to a remote host.
        if (!in_array(strtolower($settings['host']), ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('A local database host is required.');
        $port = filter_var($settings['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new RuntimeException('Invalid database port.');
        $settings['port'] = $port;
        return $settings;
    }

    private static function rows(mysqli $db, string $sql, array $params = []): array
    {
        // Every data query is a fixed SELECT; limits fail closed rather than truncate counts.
        $stmt = $db->prepare($sql . ' LIMIT ' . (self::MAX_ROWS + 1));
        try {
            if ($params) $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (count($rows) > self::MAX_ROWS) throw new RuntimeException('The academic source exceeds the supported size.');
            return $rows;
        } finally { $stmt->close(); }
    }

    public static function term(array $row): array
    {
        $id = filter_var($row['ay_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $semester = filter_var($row['semester'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
        $year = trim((string)($row['academic_year'] ?? ''));
        if ($id === false || $semester === false || !preg_match('/^(\d{4})-(\d{4})$/D', $year, $years)
            || (int)$years[2] !== (int)$years[1] + 1) throw new RuntimeException('The administrator academic term is invalid.');
        return ['academicYearId' => $id, 'academicYear' => $year, 'semester' => $semester,
            'semesterLabel' => [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Midyear'][$semester], 'key' => $year . ':' . $semester];
    }

    /** Match complete normalized names, not subject rows or student IDs. Do not fuzzy-match names. */
    public static function studentKey(array $row): string
    {
        $parts = [];
        foreach (['last_name', 'first_name', 'middle_name', 'suffix_name'] as $field) {
            $value = (string)($row[$field] ?? '');
            if (preg_match('//u', $value) !== 1) throw new RuntimeException('Invalid student name encoding.');
            if (class_exists('Normalizer')) $value = Normalizer::normalize($value, Normalizer::FORM_C);
            else $value = self::composeLatin($value);
            $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper(strtr($value, ['ñ'=>'Ñ','á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ü'=>'Ü']));
            $value = trim((string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $value));
            if (($field === 'last_name' || $field === 'first_name') && $value === '') throw new RuntimeException('An enrolled student has an incomplete name.');
            $parts[] = $value;
        }
        // Keys are transient: neither these hashes nor personal names are sent to the browser.
        return hash('sha256', implode('|', $parts));
    }

    public static function aggregate(array $term, array $sourceCampuses, array $sourceColleges, array $sourcePrograms, array $facultyRows, array $studentRows, string $basis): array
    {
        $term = self::term(['ay_id' => $term['academicYearId'] ?? null, 'academic_year' => $term['academicYear'] ?? null, 'semester' => $term['semester'] ?? null]);
        if ($basis !== 'active_class_lists'
            || count($facultyRows) > self::MAX_ROWS || count($studentRows) > self::MAX_ROWS) throw new RuntimeException('Invalid academic retrieval.');
        $campusLookup = [];
        foreach (pulseCampusCatalogue() as $campus) {
            $campusLookup[strtolower($campus['name'])] = $campus['id'];
            $campusLookup[strtolower($campus['name'] . ' Campus')] = $campus['id'];
        }
        $campuses = [];
        foreach ($sourceCampuses as $campus) {
            $mapped = $campusLookup[strtolower(trim((string)$campus['campus_name']))] ?? null;
            if ($mapped === null || isset($campuses[(int)$campus['campus_id']]) || in_array($mapped, $campuses, true)) throw new RuntimeException('Unmapped or duplicate source campus.');
            $campuses[(int)$campus['campus_id']] = $mapped;
        }
        if (count($campuses) !== count(pulseCampusCatalogue())) throw new RuntimeException('The source campus catalogue is incomplete.');
        $colleges = [];
        foreach ($sourceColleges as $college) {
            $campus = $campuses[(int)$college['campus_id']] ?? null;
            $id = (int)$college['college_id'];
            if ($campus === null || $id <= 0 || isset($colleges[$id])) throw new RuntimeException('Invalid college scope.');
            $colleges[$id] = ['id' => $id, 'name' => trim((string)$college['college_name']), 'code' => trim((string)$college['college_code']), 'campusId' => $campus, 'faculty' => [], 'students' => []];
        }
        $programs = [];
        foreach ($sourcePrograms as $program) {
            $college = $colleges[(int)$program['college_id']] ?? null;
            $id = (int)$program['program_id'];
            if ($college === null || $id <= 0 || isset($programs[$id])) throw new RuntimeException('Invalid program scope.');
            $programs[$id] = ['id' => $id, 'name' => trim((string)$program['program_name']), 'code' => trim((string)$program['program_code']),
                'major' => trim((string)($program['major'] ?? '')), 'collegeId' => $college['id'], 'campusId' => $college['campusId'], 'students' => []];
        }
        $faculty = []; $studentNames = []; $studentIds = [];
        $facultyCampuses = $studentCampuses = array_fill_keys(array_column(pulseCampusCatalogue(), 'id'), []);
        foreach ($facultyRows as $row) {
            if ((int)($row['ay_id'] ?? 0) !== $term['academicYearId'] || (int)($row['semester'] ?? 0) !== $term['semester']
                || strtolower(trim((string)($row['roster_status'] ?? ''))) !== 'active') continue;
            $id = (int)$row['faculty_id']; $collegeId = (int)$row['college_id'];
            if ($id <= 0 || !isset($colleges[$collegeId])) throw new RuntimeException('Invalid faculty roster identity.');
            $faculty[$id][$collegeId] = true;
            $colleges[$collegeId]['faculty'][$id] = true;
            $facultyCampuses[$colleges[$collegeId]['campusId']][$id] = true;
        }
        foreach ($studentRows as $row) {
            if ((int)($row['ay_id'] ?? 0) !== $term['academicYearId'] || (int)($row['semester'] ?? 0) !== $term['semester']) continue;
            if (($row['source_kind'] ?? '') !== 'class_list' || (int)($row['is_active'] ?? 0) !== 1) continue;
            $key = self::studentKey($row); $programId = (int)$row['program_id'];
            $program = $programs[$programId] ?? null;
            if ($program === null || $program['collegeId'] !== (int)$row['college_id']
                || ($campuses[(int)$row['campus_id']] ?? null) !== $program['campusId']) throw new RuntimeException('An enrolled student has conflicting program, college, or campus scope.');
            $studentNames[$key] = true;
            if ((int)($row['student_id'] ?? 0) > 0) $studentIds[$key][(int)$row['student_id']] = true;
            $studentCampuses[$program['campusId']][$key] = true;
            $colleges[$program['collegeId']]['students'][$key] = true;
            $programs[$programId]['students'][$key] = true;
        }
        foreach ($colleges as &$college) { $college['faculty'] = count($college['faculty']); $college['students'] = count($college['students']); } unset($college);
        foreach ($programs as &$program) $program['students'] = count($program['students']); unset($program);
        return ['status' => 'connected', 'term' => $term, 'retrievedAt' => gmdate(DATE_ATOM), 'studentBasis' => $basis,
            'faculty' => ['total' => count($faculty), 'byCampus' => array_map('count', $facultyCampuses)],
            'students' => ['total' => count($studentNames), 'byCampus' => array_map('count', $studentCampuses)],
            'colleges' => array_values($colleges), 'programs' => array_values($programs),
            'quality' => ['facultyWithMultipleColleges' => count(array_filter($faculty, fn ($scopes) => count($scopes) > 1)),
                'studentNamesWithMultipleIds' => count(array_filter($studentIds, fn ($ids) => count($ids) > 1))]];
    }

    private static function composeLatin(string $text): string
    {
        // Same accent-preserving fallback as the Synk identity helper when PHP intl is unavailable.
        $forms = [
            "\u{0300}" => ['AEIOUaeiou', ['À','È','Ì','Ò','Ù','à','è','ì','ò','ù']],
            "\u{0301}" => ['AEIOUYaeiouy', ['Á','É','Í','Ó','Ú','Ý','á','é','í','ó','ú','ý']],
            "\u{0302}" => ['AEIOUaeiou', ['Â','Ê','Î','Ô','Û','â','ê','î','ô','û']],
            "\u{0303}" => ['ANOano', ['Ã','Ñ','Õ','ã','ñ','õ']],
            "\u{0308}" => ['AEIOUYaeiouy', ['Ä','Ë','Ï','Ö','Ü','Ÿ','ä','ë','ï','ö','ü','ÿ']],
            "\u{030A}" => ['Aa', ['Å','å']], "\u{0327}" => ['Cc', ['Ç','ç']],
        ];
        $pairs = [];
        foreach ($forms as $mark => [$letters, $composed]) foreach (str_split($letters) as $index => $letter) $pairs[$letter . $mark] = $composed[$index];
        return strtr($text, $pairs);
    }
}
