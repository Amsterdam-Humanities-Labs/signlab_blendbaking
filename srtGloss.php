<?php
/**
 * Gloss extraction from Signbank ID gloss SRT files.
 *
 * Pure library: no output, no I/O beyond reading the paths it is handed.
 */

/**
 * Cue text lines from an SRT file's contents, in file order, duplicates kept.
 *
 * A cue line is any line that is not blank, not a bare sequence number, and
 * does not contain a timecode arrow.
 */
function srt_cues($contents) {
    $cues = [];
    foreach (preg_split('/\r?\n/', $contents) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if (preg_match('/^\d+$/', $line)) { continue; }
        if (strpos($line, '-->') !== false) { continue; }
        $cues[] = $line;
    }
    return $cues;
}

/**
 * Fold a cue to its base gloss by removing a trailing single-letter variant.
 *
 * AAP-A and AAP-B both fold to AAP; MOOI-a folds to MOOI. Deliberately lossy
 * for a lemma that genuinely ends in hyphen-plus-one-letter — no such gloss
 * exists in the current vocabulary, and callers keep the original strings in
 * the variants map either way.
 */
function srt_base_gloss($cue) {
    $stripped = preg_replace('/-[A-Za-z]$/', '', $cue);
    // Never strip a cue down to nothing: '-' and '-A' must survive as themselves.
    return ($stripped === '' || $stripped === false) ? $cue : $stripped;
}

/**
 * Aggregate glosses across a set of SRT files.
 *
 * @param array $files map of base filename => absolute SRT path
 * @return array ['files' => int, 'bases' => [base => ['count','videos','variants'=>[variant=>count]]]]
 */
function gloss_aggregate($files) {
    $bases = [];
    $parsed = 0;

    foreach ($files as $videoBase => $path) {
        if (!is_string($path) || !file_exists($path)) { continue; }
        $contents = @file_get_contents($path);
        if ($contents === false) { continue; }
        $parsed++;

        $seenInThisFile = [];
        foreach (srt_cues($contents) as $cue) {
            $base = srt_base_gloss($cue);
            if (!isset($bases[$base])) {
                $bases[$base] = ['count' => 0, 'videos' => 0, 'variants' => []];
            }
            $bases[$base]['count']++;
            $bases[$base]['variants'][$cue] = ($bases[$base]['variants'][$cue] ?? 0) + 1;
            if (!isset($seenInThisFile[$base])) {
                $bases[$base]['videos']++;
                $seenInThisFile[$base] = true;
            }
        }
    }

    return ['files' => $parsed, 'bases' => $bases];
}
