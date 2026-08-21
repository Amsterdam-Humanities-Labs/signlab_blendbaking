<?php
/**
 * Gloss extraction from Signbank ID gloss SRT files.
 *
 * Pure library: no output, no I/O beyond reading the paths it is handed.
 */

/**
 * Convert an SRT timecode ("00:00:02,188") to integer milliseconds.
 *
 * Returns null rather than 0 for anything that is not exactly hh:mm:ss,mmm,
 * so a caller can tell "this cue had no parsable timecode" apart from a
 * genuine zero offset. Callers that feed the result straight into arithmetic
 * must therefore null-check first.
 *
 * @return int|null
 */
function srt_timecode_to_ms($timecode) {
    if (!is_string($timecode)) { return null; }
    if (!preg_match('/^(\d{2}):(\d{2}):(\d{2}),(\d{3})$/', $timecode, $m)) { return null; }
    return ((int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3]) * 1000 + (int)$m[4];
}

/**
 * Cue entries from an SRT file's contents, in file order, duplicates kept.
 *
 * A cue line is any line that is not blank, not a bare sequence number, and
 * does not contain a timecode arrow. Each entry carries the start and end
 * timecodes of the block it belongs to, plus the block's own SRT index, so
 * callers can point at where a gloss occurs and how long it lasts.
 *
 * A blank line ends the block, so a stray cue line with no preceding timecode
 * reports nulls rather than inheriting the previous block's.
 *
 * `end` is null when the arrow line has a start but no parsable end — the
 * start is still kept, because a half-parsed timecode line is more useful
 * than none and the corpus has no such lines to lose.
 *
 * Known edge, deliberately left alone: a cue whose text is nothing but digits
 * is indistinguishable from an index line and gets swallowed as one. No gloss
 * in the vocabulary is a bare number. Do not "fix" it by dropping the index
 * skip — that would emit every index line as a phantom gloss.
 *
 * @return array list of ['text' => string, 'start' => ?string, 'end' => ?string, 'index' => ?int]
 */
function srt_cue_entries($contents) {
    $entries = [];
    $start = null;
    $end   = null;
    $index = null;

    foreach (preg_split('/\r?\n/', $contents) as $line) {
        $line = trim($line);
        if ($line === '') { $start = null; $end = null; $index = null; continue; }
        if (preg_match('/^\d+$/', $line)) { $index = (int)$line; continue; }
        if (strpos($line, '-->') !== false) {
            if (preg_match('/^(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/', $line, $m)) {
                $start = $m[1];
                $end   = $m[2];
            } else {
                $start = preg_match('/^(\d{2}:\d{2}:\d{2},\d{3})\s*-->/', $line, $m) ? $m[1] : null;
                $end   = null;
            }
            continue;
        }
        $entries[] = ['text' => $line, 'start' => $start, 'end' => $end, 'index' => $index];
    }

    return $entries;
}

/**
 * Cue text lines only, in file order, duplicates kept.
 *
 * Thin wrapper over srt_cue_entries() so there is one parser, not two.
 */
function srt_cues($contents) {
    $texts = [];
    foreach (srt_cue_entries($contents) as $entry) { $texts[] = $entry['text']; }
    return $texts;
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
 * Every gloss cue in one SRT, in file order, in the shape the timings API serves.
 *
 * Each cue carries both timecode forms on purpose: the raw SRT strings, for
 * humans and for writing an SRT back out, and integer milliseconds, for
 * seeking a player without the caller reimplementing timecode parsing. The
 * folded `baseGloss` ships alongside the exact `gloss` for the same reason —
 * so a consumer can group variants without reimplementing srt_base_gloss().
 *
 * `index` falls back to the cue's 1-based position when the SRT block had no
 * number line, so it is always usable as a stable within-file ordinal.
 *
 * @return array list of ['index','gloss','baseGloss','start','end','startMs','endMs','durationMs']
 */
function srt_gloss_timings($contents) {
    $timings = [];
    foreach (srt_cue_entries($contents) as $i => $entry) {
        $startMs = srt_timecode_to_ms($entry['start']);
        $endMs   = srt_timecode_to_ms($entry['end']);
        $timings[] = [
            'index'      => $entry['index'] !== null ? $entry['index'] : $i + 1,
            'gloss'      => $entry['text'],
            'baseGloss'  => srt_base_gloss($entry['text']),
            'start'      => $entry['start'],
            'end'        => $entry['end'],
            'startMs'    => $startMs,
            'endMs'      => $endMs,
            'durationMs' => ($startMs !== null && $endMs !== null) ? $endMs - $startMs : null,
        ];
    }
    return $timings;
}

/**
 * Aggregate glosses across a set of SRT files.
 *
 * @param array $files map of base filename => absolute SRT path
 * @return array ['files' => int, 'bases' => [base => ['count','videos','variants'=>[variant=>count],
 *                                                    'occurrences'=>[['video','start','end','cue']]]]]
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
        foreach (srt_cue_entries($contents) as $entry) {
            $cue = $entry['text'];
            $base = srt_base_gloss($cue);
            if (!isset($bases[$base])) {
                $bases[$base] = ['count' => 0, 'videos' => 0, 'variants' => [], 'occurrences' => []];
            }
            $bases[$base]['count']++;
            $bases[$base]['variants'][$cue] = ($bases[$base]['variants'][$cue] ?? 0) + 1;
            // One entry per cue instance, so a gloss occurring twice in one video
            // is listed twice while still counting as a single video below.
            $bases[$base]['occurrences'][] = [
                'video' => $videoBase,
                'start' => $entry['start'],
                'end'   => $entry['end'],
                'cue'   => $cue,
            ];
            if (!isset($seenInThisFile[$base])) {
                $bases[$base]['videos']++;
                $seenInThisFile[$base] = true;
            }
        }
    }

    return ['files' => $parsed, 'bases' => $bases];
}
