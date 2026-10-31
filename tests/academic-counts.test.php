<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Records.php';
require dirname(__DIR__) . '/src/SynkAcademics.php';

$checks = 0;
function checkAcademic(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejectAcademic(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException) { checkAcademic(true, $message); return; }
    throw new RuntimeException($message);
}
function facultyRow(int $id, int $college, array $overrides = []): array {
    return array_replace(['faculty_id' => $id, 'college_id' => $college, 'ay_id' => 2, 'semester' => 1, 'roster_status' => 'active'], $overrides);
}
function studentRow(int $id, int $program, array $overrides = []): array {
    return array_replace(['student_id' => $id, 'last_name' => 'Dela Cruz', 'first_name' => 'Ana', 'middle_name' => 'M.', 'suffix_name' => '',
        'program_id' => $program, 'college_id' => $program === 11 ? 1 : 2, 'campus_id' => $program === 11 ? 1 : 2,
        'ay_id' => 2, 'semester' => 1, 'is_active' => 1, 'source_kind' => 'class_list'], $overrides);
}
$term = PulseSynkAcademics::term(['ay_id' => 2, 'semester' => 1, 'academic_year' => '2026-2027']);
$campuses = [];
foreach (pulseCampusCatalogue() as $index => $campus) $campuses[] = ['campus_id' => $index + 1, 'campus_name' => $campus['name'] . ' Campus'];
$colleges = [
    ['college_id' => 1, 'college_name' => 'College One', 'college_code' => 'ONE', 'campus_id' => 1],
    ['college_id' => 2, 'college_name' => 'College Two', 'college_code' => 'TWO', 'campus_id' => 2],
];
$programs = [
    ['program_id' => 11, 'program_name' => 'Program One', 'program_code' => 'P1', 'college_id' => 1],
    ['program_id' => 22, 'program_name' => 'Program Two', 'program_code' => 'P2', 'college_id' => 2],
];
$faculty = [facultyRow(1, 1), facultyRow(1, 1), facultyRow(1, 2), facultyRow(2, 2),
    facultyRow(3, 1, ['semester' => 2]), facultyRow(4, 1, ['ay_id' => 1]), facultyRow(5, 1, ['roster_status' => 'inactive']),
    facultyRow(6, 1, ['ay_id' => null, 'semester' => null])];
$students = [studentRow(1, 11), studentRow(1, 11), studentRow(2, 11, ['first_name' => '  ANA ', 'middle_name' => ' m ']),
    studentRow(1, 22), studentRow(3, 22, ['first_name' => 'Jose']),
    studentRow(4, 11, ['is_active' => 0]), studentRow(5, 11, ['semester' => 2]),
    studentRow(6, 11, ['ay_id' => 1]), studentRow(7, 11, ['source_kind' => 'approved_enrollment'])];
$data = PulseSynkAcademics::aggregate($term, $campuses, $colleges, $programs, $faculty, $students, 'active_class_lists');
checkAcademic($data['term']['key'] === '2026-2027:1', 'Use the administrator term, not the calendar or scheduler session.');
checkAcademic($data['faculty']['total'] === 2, 'Count distinct roster faculty only in the current academic term.');
checkAcademic($data['faculty']['byCampus']['access'] === 1 && $data['faculty']['byCampus']['isulan'] === 2, 'Deduplicate faculty independently within each campus.');
checkAcademic(array_column($data['colleges'], 'faculty') === [1, 2], 'College counts use the roster college, not permanent faculty affiliation.');
checkAcademic(array_sum($data['faculty']['byCampus']) > $data['faculty']['total'], 'Cross-campus faculty must not be counted twice in the university total.');
checkAcademic($data['students']['total'] === 2, 'Deduplicate complete normalized names across subject rows and different IDs.');
checkAcademic($data['students']['byCampus']['access'] === 1 && $data['students']['byCampus']['isulan'] === 2, 'Student campus totals are distinct per campus.');
checkAcademic(array_column($data['programs'], 'students') === [1, 2], 'Student program totals deduplicate repeated subject memberships.');
checkAcademic(array_column($data['colleges'], 'students') === [1, 2], 'Student college totals are distinct, not sums of courses.');
checkAcademic($data['quality']['facultyWithMultipleColleges'] === 1 && $data['quality']['studentNamesWithMultipleIds'] === 1, 'Report overlapping assignments and same-name identities without exposing names.');
checkAcademic(!str_contains(json_encode($data), 'Dela Cruz') && !str_contains(json_encode($data), 'student_id'), 'Return only aggregate counts and unit labels.');
checkAcademic(PulseSynkAcademics::studentKey(studentRow(1, 11, ['last_name' => 'Peña'])) === PulseSynkAcademics::studentKey(studentRow(1, 11, ['last_name' => "PEN\u{0303}A"])), 'Preserve and compose accents before case normalization.');
checkAcademic(PulseSynkAcademics::studentKey(studentRow(1, 11, ['first_name' => 'Ana', 'middle_name' => 'Maria'])) !== PulseSynkAcademics::studentKey(studentRow(1, 11, ['first_name' => 'Ana Maria', 'middle_name' => ''])), 'Do not merge different structured names.');
checkAcademic(PulseSynkAcademics::studentKey(studentRow(1, 11, ['suffix_name' => 'Jr.'])) !== PulseSynkAcademics::studentKey(studentRow(1, 11)), 'Keep different suffixes distinct.');
$empty = PulseSynkAcademics::aggregate($term, $campuses, $colleges, $programs, [], [], 'active_class_lists');
checkAcademic($empty['faculty']['total'] === 0 && $empty['students']['total'] === 0, 'A valid current term with no roster records is a confirmed zero.');
$next = PulseSynkAcademics::aggregate(PulseSynkAcademics::term(['ay_id' => 2, 'semester' => 2, 'academic_year' => '2026-2027']), $campuses, $colleges, $programs, $faculty, $students, 'active_class_lists');
checkAcademic($next['faculty']['total'] === 1 && $next['students']['total'] === 1, 'Changing the selected term excludes previous-term identities.');
rejectAcademic(fn () => PulseSynkAcademics::term(['ay_id' => 0, 'semester' => 1, 'academic_year' => '2026-2027']), 'Reject an unset administrator academic year.');
rejectAcademic(fn () => PulseSynkAcademics::term(['ay_id' => 2, 'semester' => 0, 'academic_year' => '2026-2027']), 'Reject an unset administrator semester.');
rejectAcademic(fn () => PulseSynkAcademics::term(['ay_id' => 2, 'semester' => 1, 'academic_year' => '2026-2028']), 'Reject malformed academic year labels.');
rejectAcademic(fn () => PulseSynkAcademics::aggregate($term, $campuses, $colleges, $programs, [], [studentRow(1, 11, ['college_id' => 2])], 'active_class_lists'), 'Fail closed when a membership conflicts with its program scope.');
rejectAcademic(fn () => PulseSynkAcademics::aggregate($term, array_slice($campuses, 1), $colleges, $programs, [], [], 'active_class_lists'), 'Do not return partial university totals when a campus mapping is missing.');
rejectAcademic(fn () => PulseSynkAcademics::aggregate($term, $campuses, $colleges, $programs, [], [studentRow(1, 11, ['first_name' => ''])], 'active_class_lists'), 'Do not silently skip students with invalid identities.');
putenv('SINQ_ACADEMICS_ENABLED=false');
checkAcademic(PulseSynkAcademics::read()['faculty']['total'] === null, 'Disabled source is unavailable, not zero or the all-time faculty directory.');
echo "Academic counts: {$checks} checks passed. No source connection or writes were made.\n";
