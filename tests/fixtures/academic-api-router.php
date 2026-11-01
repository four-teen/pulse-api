<?php
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET' || isset($_SERVER['HTTP_COOKIE']) || ($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer '.getenv('TEST_SOURCE_TOKEN')
    || ($_GET['resource']??'')!=='academic-counts') { http_response_code(400); echo '{}'; return; }
$scenario=getenv('TEST_SCENARIO');
if ($scenario==='rejected') { http_response_code(401); echo '{"password":"must-not-leak"}'; return; }
if ($scenario==='redirect') { header('Location: http://127.0.0.1:1/unexpected',true,302); echo '{}'; return; }
if ($scenario==='html') { header('Content-Type: text/html'); echo '<html>must-not-leak</html>'; return; }
if ($scenario==='oversized') { echo str_repeat('x',2097153); return; }
if ($scenario==='invalid-json') { echo '{broken'; return; }
$zero=['total'=>0,'byCampus'=>array_fill_keys(['access','isulan','tacurong','kalamansig','lutayan','palimbang','bagumbayan'],0)];
echo json_encode(['schemaVersion'=>1,'sourceId'=>'sinq','status'=>'connected','studentBasis'=>'active_class_lists',
    'term'=>['academicYearId'=>2,'academicYear'=>'2026-2027','semester'=>1,'semesterLabel'=>'1st Semester','key'=>'2026-2027:1'],
    'retrievedAt'=>gmdate(DATE_ATOM),'faculty'=>$zero,'students'=>$zero,'colleges'=>[],'programs'=>[],
    'quality'=>['facultyWithMultipleColleges'=>0,'studentNamesWithMultipleIds'=>0],'password'=>'must-not-leak']);
