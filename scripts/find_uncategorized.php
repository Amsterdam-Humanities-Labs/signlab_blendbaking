<?php
/**
 * Print base glosses that appear in the SRT corpus but are missing from
 * categories.json, or that carry a slug not declared in its category list.
 *
 * Exit code 0 when categories.json is complete and consistent, 1 otherwise.
 *
 * Usage: php scripts/find_uncategorized.php [--baked-only]
 */
require_once __DIR__ . '/../srtGloss.php';
require_once __DIR__ . '/../api.php';

$args = implode(' ', array_slice($argv, 1));
$cmd = 'php ' . escapeshellarg(__DIR__ . '/dump_base_glosses.php');
if ($args !== '') { $cmd .= ' ' . $args; }

// exec() leaves the child's stderr attached to ours and hands back its exit
// status. Both matter: this exit code is the only verification this task has,
// so a dump that dies must never be mistaken for a corpus with nothing missing.
$lines = [];
$status = 0;
exec($cmd, $lines, $status);

if ($status !== 0) {
    fwrite(STDERR, "dump_base_glosses.php exited with status $status\n");
    exit(1);
}

$corpus = [];
foreach ($lines as $line) {
    $line = rtrim($line, "\r\n");
    if ($line === '') { continue; }
    $parts = explode("\t", $line);
    $corpus[$parts[0]] = (int)($parts[1] ?? 0);
}

// An empty corpus makes every completeness check vacuously true. Treat it as
// a broken scan (BB_EAF_DIR gone, rclone mount down, upstream fetch failed),
// never as success.
if (!count($corpus)) {
    fwrite(STDERR, "dump produced no glosses: corpus scan or upstream fetch failed\n");
    exit(1);
}

$cat = bb_categories();
$known = [];
foreach ($cat['categories'] as $c) { $known[$c['slug']] = true; }

$missing = [];
foreach ($corpus as $base => $count) {
    if (!isset($cat['glosses'][$base])) { $missing[$base] = $count; }
}

$badSlug = [];
foreach ($cat['glosses'] as $base => $slug) {
    if (!isset($known[$slug])) { $badSlug[$base] = $slug; }
}

$stale = array_diff_key($cat['glosses'], $corpus);

printf("corpus base glosses : %d\n", count($corpus));
printf("categorized         : %d\n", count($cat['glosses']));
printf("missing             : %d\n", count($missing));
printf("undeclared slugs    : %d\n", count($badSlug));
printf("stale (not in corpus): %d\n", count($stale));

if (count($missing)) {
    echo "\n-- missing, most frequent first --\n";
    arsort($missing);
    foreach ($missing as $base => $count) { printf("%s\t%d\n", $base, $count); }
}
if (count($badSlug)) {
    echo "\n-- glosses with an undeclared slug --\n";
    foreach ($badSlug as $base => $slug) { printf("%s\t%s\n", $base, $slug); }
}

exit((count($missing) || count($badSlug)) ? 1 : 0);
