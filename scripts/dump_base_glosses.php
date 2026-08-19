<?php
/**
 * Print every base gloss in the corpus, one per line, with its occurrence
 * count, most frequent first. Input for generating categories.json.
 *
 * Only the TSV goes to stdout; the summary line goes to stderr, so callers
 * (find_uncategorized.php) can parse stdout without filtering.
 *
 * Usage: php scripts/dump_base_glosses.php [--baked-only]
 */
require_once __DIR__ . '/../srtGloss.php';
require_once __DIR__ . '/../api.php';

$bakedOnly = in_array('--baked-only', $argv, true);

$files = [];
if ($bakedOnly) {
    $res = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);
    $rows = $res['videos'] ?? [];
    $total = (int)($res['total'] ?? 0);
    $page = 2;
    while (count($rows) < $total) {
        $next = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => $page]);
        if (empty($next['videos'])) { break; }
        $rows = array_merge($rows, $next['videos']);
        $page++;
    }
    foreach ($rows as $row) {
        $p = bb_srt_path($row['base']);
        if ($p !== null) { $files[$row['base']] = $p; }
    }
} else {
    // scandir, never find: see the plan's Global Constraints.
    foreach (scandir(BB_EAF_DIR) as $f) {
        if (strpos($f, 'backup') !== false) { continue; }
        $suffixLen = strlen(BB_SRT_SUFFIX);
        if (substr($f, -$suffixLen) !== BB_SRT_SUFFIX) { continue; }
        $files[substr($f, 0, -$suffixLen)] = BB_EAF_DIR . $f;
    }
}

$agg = gloss_aggregate($files);
$sorted = $agg['bases'];
uasort($sorted, function ($a, $b) { return $b['count'] - $a['count']; });

fwrite(STDERR, sprintf("%d files, %d base glosses\n", $agg['files'], count($sorted)));
foreach ($sorted as $base => $info) {
    printf("%s\t%d\n", $base, $info['count']);
}
