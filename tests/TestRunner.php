<?php
/**
 * Test runner for blendBaking.
 * Usage: php tests/TestRunner.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Snapshot the real cache directory before anything runs. A test must never
// write into it: the fake-upstream fixture isolates itself by pre-defining
// BB_CACHE, and a cache path that does not derive from BB_CACHE's directory
// silently escapes that and gets the fixture's three fake videos served to
// production for a whole TTL. That has happened once. Checking it here means
// the next occurrence fails the suite instead of waiting to be noticed.
$cacheDir = __DIR__ . '/../cache';
$cacheBefore = is_dir($cacheDir)
    ? array_values(array_diff(scandir($cacheDir), ['.', '..']))
    : [];
sort($cacheBefore);

require_once __DIR__ . '/TestSrtGloss.php';
require_once __DIR__ . '/TestCategories.php';

$classes = ['TestSrtGloss', 'TestCategories'];
$totalPassed = 0; $totalFailed = 0;

foreach ($classes as $class) {
    $suite = new $class();
    $result = $suite->runTests();
    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    printf("%-24s %3d passed, %3d failed\n", $class, $result['passed'], $result['failed']);
    foreach ($result['messages'] as $msg) { echo "  $msg\n"; }
}

$cacheAfter = is_dir($cacheDir)
    ? array_values(array_diff(scandir($cacheDir), ['.', '..']))
    : [];
sort($cacheAfter);

$leaked = array_diff($cacheAfter, $cacheBefore);
if (count($leaked)) {
    $totalFailed++;
    printf("%-24s %3d passed, %3d failed\n", 'CacheIsolation', 0, 1);
    echo "  FAIL: the suite wrote into the real cache/ — " . implode(', ', $leaked) . "\n";
    echo "        Every cache path must derive from dirname(BB_CACHE); see api.php.\n";
    echo "        Delete those files before serving this checkout.\n";
}

printf("\nTOTAL: %d passed, %d failed\n", $totalPassed, $totalFailed);
exit($totalFailed > 0 ? 1 : 0);
