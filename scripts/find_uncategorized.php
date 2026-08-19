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
$dump = shell_exec('php ' . escapeshellarg(__DIR__ . '/dump_base_glosses.php') . ' ' . $args . ' 2>/dev/null');

$corpus = [];
foreach (explode("\n", trim((string)$dump)) as $line) {
    if ($line === '') { continue; }
    $parts = explode("\t", $line);
    $corpus[$parts[0]] = (int)($parts[1] ?? 0);
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
