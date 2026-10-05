<?php
declare(strict_types=1);

require_once __DIR__ . '/TestHarness.php';

$harness = new TestHarness();

$testFiles = glob(__DIR__ . '/*Test.php');
sort($testFiles);

foreach ($testFiles as $testFile) {
    require $testFile;
}

exit($harness->exitCode());
