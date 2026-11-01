<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Records.php';
require dirname(__DIR__) . '/src/SynkAcademics.php';

$ids=array_column(pulseCampusCatalogue(),'id');
$zero=['total'=>0,'byCampus'=>array_fill_keys($ids,0)];
$valid=['schemaVersion'=>1,'sourceId'=>'sinq','status'=>'connected','studentBasis'=>'active_class_lists',
    'term'=>['academicYearId'=>2,'academicYear'=>'2026-2027','semester'=>1,'semesterLabel'=>'1st Semester','key'=>'2026-2027:1'],
    'retrievedAt'=>gmdate(DATE_ATOM),'faculty'=>$zero,'students'=>$zero,'colleges'=>[],'programs'=>[],
    'quality'=>['facultyWithMultipleColleges'=>0,'studentNamesWithMultipleIds'=>0]];
$checks=0;
function verifyApi(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$withExtra=$valid; $withExtra['password']='must-not-leak'; $withExtra['term']['privateName']='must-not-leak';
$safe=PulseSynkAcademics::validate($withExtra);
verifyApi($safe['faculty']['total']===0,'A successful empty source is a confirmed zero.');
verifyApi(!str_contains(json_encode($safe),'must-not-leak'),'Reconstruct the safe contract; do not forward source fields.');
foreach ([
    fn($data)=>array_replace($data,['sourceId'=>'other']),
    fn($data)=>array_replace($data,['schemaVersion'=>2]),
    fn($data)=>array_replace($data,['retrievedAt'=>gmdate(DATE_ATOM,time()-3600)]),
    fn($data)=>array_replace($data,['retrievedAt'=>gmdate(DATE_ATOM,time()+3600)]),
    fn($data)=>array_replace($data,['studentBasis'=>'approved_enrollments']),
    fn($data)=>array_replace($data,['term'=>array_replace($data['term'],['semester'=>2])]),
    fn($data)=>array_replace($data,['faculty'=>['total'=>1,'byCampus'=>$data['faculty']['byCampus']]]),
    fn($data)=>array_replace($data,['students'=>['total'=>-1,'byCampus'=>$data['students']['byCampus']]]),
] as $mutate) {
    try { PulseSynkAcademics::validate($mutate($valid)); throw new LogicException('Invalid source response was accepted.'); }
    catch (RuntimeException) { $checks++; }
}
putenv('SINQ_ACADEMICS_ENABLED=true'); putenv('SINQ_ACADEMICS_LOCAL_TEST_ENABLED=false');
putenv('SINQ_API_TOKEN=test-only-private-token-for-academic-api');
foreach (['http://sinq.sksu-orms.net','https://evil.example','https://user:pass@sinq.sksu-orms.net','https://sinq.sksu-orms.net?token=secret'] as $base) {
    putenv('SINQ_API_BASE_URL='.$base); verifyApi(PulseSynkAcademics::configurationIssues()!==[],'Reject unsafe API origin before making a request.');
}
putenv('SINQ_API_BASE_URL=https://sinq.sksu-orms.net');
verifyApi(PulseSynkAcademics::configurationIssues()===[],'A configured HTTPS API needs no database settings.');
putenv('SINQ_ACADEMICS_LOCAL_TEST_ENABLED=true'); putenv('APP_ENV=production');
verifyApi(PulseSynkAcademics::configurationIssues()!==[],'Production cannot use local HTTP testing.');
echo "Academic API validation: {$checks} checks passed. No network or database connections were made.\n";
