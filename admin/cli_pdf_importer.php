<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/lib/pdf_importer.php';
require_once __DIR__.'/lib/matchday_record.php';
pdf_import_schema($pdo);
matchday_record_ensure_schema($pdo);
$deadline=time()+45;
try {
    if (in_array('--scan',$argv,true)) echo pdf_import_json(pdf_import_scan($pdo)),"\n";
    do {
        $projected=pdf_import_project_one($pdo);
        $parsed=pdf_import_process_one($pdo);
    } while (($projected || $parsed) && time()<$deadline);
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
