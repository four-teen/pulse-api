<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Records.php';
require dirname(__DIR__) . '/src/SynkAcademics.php';
$data = PulseSynkAcademics::read();
if ($data['status'] !== 'connected') {
    fwrite(STDERR, "Synk current-term API counts: {$data['status']}.\n");
    foreach (PulseSynkAcademics::configurationIssues() as $issue) fwrite(STDERR, '- ' . $issue . "\n");
    if (!PulseSynkAcademics::configurationIssues()) fwrite(STDERR, "Check the uploaded Synk academic export, matching API token, and Synk PHP error log. No database is required in PULSE.\n");
    exit(1);
}
echo json_encode(['term' => $data['term'], 'studentBasis' => $data['studentBasis'], 'faculty' => $data['faculty'],
    'students' => $data['students'], 'collegeCount' => count($data['colleges']), 'programCount' => count($data['programs']),
    'quality' => $data['quality']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
echo "Read-only API GET completed. PULSE made no database connection or source writes.\n";
