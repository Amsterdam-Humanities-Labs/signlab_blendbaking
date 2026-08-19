<?php
/**
 * Test runner for blendBaking.
 * Usage: php tests/TestRunner.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/TestSrtGloss.php';

$classes = ['TestSrtGloss'];
$totalPassed = 0; $totalFailed = 0;

foreach ($classes as $class) {
    $suite = new $class();
    $result = $suite->runTests();
    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    printf("%-24s %3d passed, %3d failed\n", $class, $result['passed'], $result['failed']);
    foreach ($result['messages'] as $msg) { echo "  $msg\n"; }
}

printf("\nTOTAL: %d passed, %d failed\n", $totalPassed, $totalFailed);
exit($totalFailed > 0 ? 1 : 0);
