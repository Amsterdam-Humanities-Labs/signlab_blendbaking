<?php
/**
 * blendBaking API.
 *
 * Thin layer over getZinnen.php's listMocapFiles action. Everything specific
 * to this tool lives here: ZIP bundling, gloss aggregation, category lookup.
 *
 * Including this file defines functions only; it dispatches on $_GET['action']
 * solely when requested over HTTP, so tests may require_once it safely.
 */

require_once __DIR__ . '/srtGloss.php';

// Guarded like mocapFiles.php's MOCAP_* constants: a plain define() would
// warn (and lose) on a second define, so tests that need an isolated
// upstream, cache path or cap pre-define these before requiring this file.
// Production never pre-defines them, so behaviour is unchanged there.
if (!defined('BB_EAF_DIR'))    define('BB_EAF_DIR',    '/web/zin/eaf/zin/');
if (!defined('BB_SRT_SUFFIX')) define('BB_SRT_SUFFIX', '_Signbank_ID_glossen.srt');

// The other two annotation tiers ELAN exports beside the gloss tier. Both are
// present for every baked base in the corpus (739/739 as of 2026-09-03);
// _Handvorm.srt also exists but only for 15, so it is not exposed.
// BB_SRT_SUFFIX stays the gloss tier: it is what bb_srt_path defaults to and
// what the ZIP download bundles, and neither of those changes here.
if (!defined('BB_NL_SUFFIX'))  define('BB_NL_SUFFIX',  '_Nederlands.srt');
if (!defined('BB_GVG_SUFFIX')) define('BB_GVG_SUFFIX', '_Gebaar-voor-gebaar.srt');
if (!defined('BB_ZIN_API'))    define('BB_ZIN_API',    'https://signcollect.nl/zin/getZinnen.php');
if (!defined('BB_CACHE'))      define('BB_CACHE',      __DIR__ . '/cache/gloss_index.json');
if (!defined('BB_CATEGORIES')) define('BB_CATEGORIES', __DIR__ . '/categories.json');
if (!defined('BB_MAX_ZIP'))    define('BB_MAX_ZIP',    2000);

// Origin used to absolutise the host-relative media paths upstream returns
// (glbUrl arrives as "/gebarenoverleg_media/..."). The public timings API is
// documented as returning URLs a third-party client can fetch as-is, so it
// must not hand out paths that only resolve against signcollect.nl by luck.
if (!defined('BB_MEDIA_ORIGIN')) define('BB_MEDIA_ORIGIN', 'https://signcollect.nl');

// Page size for `timings`. Every sentence in a page costs one gloss-SRT read
// off BB_EAF_DIR, so this is what bounds filesystem work per request — not a
// display convenience.
if (!defined('BB_TIMINGS_LIMIT'))     define('BB_TIMINGS_LIMIT',     25);
if (!defined('BB_TIMINGS_MAX_LIMIT')) define('BB_TIMINGS_MAX_LIMIT', 200);

// Signbank export used to translate a gloss to its senses.
//
// This was deliberately checked in beside categories.json, so the tool stayed
// standalone and the senses a commit served were the senses it was tested
// against. That is a real property and it is being given up here: the export
// is 11MB and existed in twelve places across these repos, and the Signbank
// connector in menu_beta now regenerates one shared copy on a schedule - a
// pinned copy would go stale silently instead.
//
// The trade is version-pinning for freshness and one copy. If pinning matters
// more for this tool than currency, put the file back and revert this.
// The Signbank export is shared, not per-app: the connector in menu_beta
// publishes one copy and every consumer reads it. sc_path() resolves it below
// the install root, so this follows a docroot that is not /web; the literal is
// the fallback for a host without signcollect-lib (production has none).
if (!defined('BB_SENSES')) {
    $bbLib = null;
    foreach ([__DIR__ . '/../lib/paths.php', '/web/lib/paths.php'] as $bbCandidate) {
        if (is_readable($bbCandidate)) { $bbLib = $bbCandidate; break; }
    }
    if ($bbLib !== null) { require_once $bbLib; }
    define('BB_SENSES', function_exists('sc_path')
        ? sc_path('signbank_data/glosses_transformed.json')
        : '/web/signbank_data/glosses_transformed.json');
}

// Cache of the fully-paged, unfiltered video list, shared by `glosses` and
// `timings`. Without it, paging through timings re-fetches all of upstream on
// every single request.
//
// Derived from BB_CACHE's directory rather than __DIR__ on purpose: the test
// fixture in TestSrtGloss isolates itself by pre-defining BB_CACHE alone, so a
// second cache anchored to __DIR__ would keep writing its fake three-video
// upstream into the real cache/ and poison production for a whole TTL. Any new
// cache file added here must be derived the same way.
if (!defined('BB_VIDEO_CACHE')) define('BB_VIDEO_CACHE', dirname(BB_CACHE) . '/videos.json');

// Compact gloss => senses index distilled from BB_SENSES. Parsing the 10.5 MB
// export costs ~0.11s and ~48 MB of memory per request; the distilled index is
// 0.27 MB and ~0.005s. Derived from dirname(BB_CACHE) for the same isolation
// reason as BB_VIDEO_CACHE above.
if (!defined('BB_SENSES_CACHE')) define('BB_SENSES_CACHE', dirname(BB_CACHE) . '/senses_index.json');

/**
 * Validate a video base filename. Returns the base, or null if unsafe.
 *
 * Allowed characters are exactly those observed in real bases; anything else
 * — including any path separator or dot-segment — is rejected outright rather
 * than sanitised, so traversal cannot survive normalisation.
 */
function bb_safe_base($base) {
    if (!is_string($base) || $base === '') { return null; }
    if (!preg_match('/^[A-Za-z0-9_#+.-]+$/', $base)) { return null; }
    if (strpos($base, '..') !== false) { return null; }
    return $base;
}

/**
 * Absolute path to one of a base's SRT tiers, confirmed inside BB_EAF_DIR.
 *
 * $suffix selects the tier and defaults to the gloss tier, so every existing
 * caller keeps its behaviour. It is only ever passed one of the BB_*_SUFFIX
 * constants — never user input — so the traversal guard on $base is still the
 * only untrusted-input check that matters.
 *
 * Note the directory also holds timestamped *_backup_*.srt copies of each
 * tier. Matching on an exact suffix is what keeps those out; do not loosen it
 * into a prefix or glob match.
 */
function bb_srt_path($base, $suffix = null) {
    if ($suffix === null) { $suffix = BB_SRT_SUFFIX; }

    $base = bb_safe_base($base);
    if ($base === null) { return null; }

    $candidate = BB_EAF_DIR . $base . $suffix;
    $real = realpath($candidate);
    if ($real === false) { return null; }

    $root = realpath(BB_EAF_DIR);
    if ($root === false || strpos($real, $root . '/') !== 0) { return null; }

    return $real;
}

/**
 * Absolutise a URL that upstream may have returned host-relative.
 *
 * glossSrtUrl already comes back absolute; glbUrl does not. Both go through
 * here so a caller of the public API never has to know which is which.
 */
function bb_absolute_url($url) {
    if (!is_string($url) || $url === '') { return null; }
    if (preg_match('#^https?://#i', $url)) { return $url; }
    return BB_MEDIA_ORIGIN . '/' . ltrim($url, '/');
}

/**
 * URL of another annotation tier's SRT for a base, or null if there isn't one.
 *
 * Derived from upstream's glossSrtUrl by swapping the tier suffix, rather than
 * rebuilt from a hardcoded directory: that keeps the sidecar links on whatever
 * host upstream is serving the gloss SRT from, with one place to change if it
 * ever moves. Same reasoning as bb_fbx_url deriving from glbUrl.
 *
 * Unlike the FBX, this one checks the file is actually on disk first —
 * BB_EAF_DIR is local, so the check is free, and _Handvorm.srt exists for only
 * a fraction of bases, which is a good reminder that tier coverage is not
 * guaranteed. A null is more useful to a client than a URL that 404s.
 */
function bb_sidecar_srt_url($base, $glossSrtUrl, $suffix) {
    if (bb_srt_path($base, $suffix) === null) { return null; }
    if (!is_string($glossSrtUrl) || $glossSrtUrl === '') { return null; }

    $tail = strlen(BB_SRT_SUFFIX);
    if (substr($glossSrtUrl, -$tail) !== BB_SRT_SUFFIX) { return null; }

    return bb_absolute_url(substr($glossSrtUrl, 0, -$tail) . $suffix);
}

/**
 * The gloss => senses index, distilled from BB_SENSES and cached.
 *
 * Invalidated by mtime rather than a TTL: BB_SENSES is a checked-in file that
 * only changes when someone copies a new export in, so a TTL would either
 * re-parse 10.5 MB on a schedule for no reason or serve a stale index after an
 * update. Comparing mtimes does neither.
 */
function bb_senses_index($force = false) {
    if (!$force && file_exists(BB_SENSES_CACHE)
        && (!file_exists(BB_SENSES) || filemtime(BB_SENSES_CACHE) >= filemtime(BB_SENSES))) {
        $decoded = json_decode(@file_get_contents(BB_SENSES_CACHE), true);
        if (is_array($decoded) && isset($decoded['exact']) && isset($decoded['ci'])) {
            return $decoded;
        }
    }

    $index = gloss_senses_index(BB_SENSES);

    // Never cache an empty index: a missing or unreadable export would
    // otherwise pin "no gloss has senses" in place until someone noticed.
    if (count($index['exact'])) {
        $dir = dirname(BB_SENSES_CACHE);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents(BB_SENSES_CACHE, json_encode($index), LOCK_EX);
    }

    return $index;
}

/**
 * The FBX for a video row, derived from its GLB URL by swapping the extension.
 *
 * Deliberately NOT built from upstream's `fbxFilename`. That field is the
 * newest take's FBX, while `glbUrl` is the newest *baked* take's GLB, and the
 * two are different takes whenever `glbIsLatestTake` is false — using
 * fbxFilename would hand out an FBX whose animation does not match the SRT
 * timings, sentence and GLB the same row describes, with nothing in the
 * response to reveal the mismatch.
 *
 * Every baked take ships its .fbx and .glb side by side in the same directory
 * under the same stem (857 of each, zero unmatched, as of 2026-08-21), so the
 * extension swap keeps every field of a row pinned to one take. If that
 * invariant ever breaks, this returns a 404-ing URL rather than a wrong-take
 * one — the safer of the two failures.
 *
 * @return string|null null when there is no GLB, or it is not a .glb
 */
function bb_fbx_url($glbUrl) {
    if (!is_string($glbUrl) || $glbUrl === '') { return null; }
    if (strtolower(substr($glbUrl, -4)) !== '.glb') { return null; }
    return substr($glbUrl, 0, -4) . '.fbx';
}

/**
 * Fetch rows from getZinnen.php's listMocapFiles action.
 */
function bb_fetch_videos($query) {
    $query['action'] = 'listMocapFiles';
    $url = BB_ZIN_API . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => 'Upstream-aanvraag mislukt: ' . $err];
    }
    if ($code !== 200) {
        return ['success' => false, 'error' => 'Upstream gaf statuscode ' . $code . ' terug'];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Upstream gaf ongeldige JSON terug'];
    }
    return $decoded;
}

if (!defined('BB_CACHE_TTL')) define('BB_CACHE_TTL', 600);

// Bumped whenever the shape of the cached gloss aggregate changes. bb_glosses
// refuses to trust a cached index whose 'schema' doesn't match — the same
// self-healing guard mocap_get_index uses in mocapFiles.php — so a cache
// written by older code is rebuilt instead of silently trusted with fields
// missing. Raised to 3 when occurrences gained an `end` timecode: a schema-2
// cache lacks that field entirely, and serving it would give the timings API
// null ends for a whole TTL after a deploy.
if (!defined('BB_INDEX_SCHEMA')) define('BB_INDEX_SCHEMA', 3);

/**
 * Every baked video that has a gloss SRT, paged out of upstream in full.
 *
 * Returns ['success' => true, 'videos' => [...], 'total' => int], or an error
 * array. A page that fails partway through is an outright error, never a short
 * list: an incomplete corpus served as though it were the whole one is the one
 * failure mode nothing downstream can detect after the fact.
 *
 * Only the unfiltered call is cached. Filtered fetches are rare, and keying a
 * cache by filter combination would expire in ways callers cannot reason about
 * — better to pay the upstream round-trip than to guess.
 *
 * @param array $filters extra listMocapFiles params; baked/hasGloss/limit/page are fixed here
 * @param bool  $force   bypass and overwrite the cache
 */
function bb_fetch_all_videos($filters = [], $force = false) {
    $cacheable = empty($filters);

    if ($cacheable && !$force
        && file_exists(BB_VIDEO_CACHE)
        && (time() - filemtime(BB_VIDEO_CACHE)) < BB_CACHE_TTL) {
        $decoded = json_decode(@file_get_contents(BB_VIDEO_CACHE), true);
        if (is_array($decoded) && isset($decoded['videos'])) { return $decoded; }
    }

    $query = array_merge($filters, ['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);

    $res = bb_fetch_videos($query);
    if (empty($res['success'])) { return $res; }

    // Match dump_base_glosses.php's guard: upstream can return success:true
    // with no 'videos'/'total' key, and a plain ['videos'] / (int)$res['total']
    // would raise a PHP 8 TypeError (uncaught 500) in that case.
    $rows = $res['videos'] ?? [];
    $total = (int)($res['total'] ?? 0);
    $page = 2;
    while (count($rows) < $total) {
        $query['page'] = $page;
        $next = bb_fetch_videos($query);
        if (empty($next['success'])) {
            // A genuine upstream failure mid-pagination: the rows gathered so
            // far are an unknown-sized subset, not a complete index. Report
            // loudly and do not cache — silently caching a truncated result
            // would keep serving stale, incomplete data until someone happens
            // to pass refresh=1.
            return [
                'success' => false,
                'error'   => 'Upstream-aanvraag mislukt tijdens het ophalen van pagina ' . $page
                             . ': ' . ($next['error'] ?? 'onbekende fout'),
                'partial' => true,
            ];
        }
        if (empty($next['videos'])) {
            // Upstream succeeded but had nothing left before reaching $total
            // — data can legitimately change between paged calls. Not an
            // error; stop paging cleanly.
            break;
        }
        $rows = array_merge($rows, $next['videos']);
        $page++;
    }

    $result = ['success' => true, 'videos' => $rows, 'total' => $total];

    if ($cacheable) {
        $dir = dirname(BB_VIDEO_CACHE);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents(BB_VIDEO_CACHE, json_encode($result), LOCK_EX);
    }

    return $result;
}

/**
 * Aggregate glosses for the baked videos, cached to BB_CACHE.
 *
 * The cache is only trusted when it is younger than BB_CACHE_TTL and carries
 * the current BB_INDEX_SCHEMA, mirroring mocap_get_index's TTL/schema guard.
 * If pagination fails partway through the upstream fetch, no cache is written
 * and an explicit error is returned — a truncated index must never be served
 * as if it were complete.
 */
function bb_glosses($force = false) {
    if (!$force && file_exists(BB_CACHE) && (time() - filemtime(BB_CACHE)) < BB_CACHE_TTL) {
        $decoded = json_decode(@file_get_contents(BB_CACHE), true);
        if (is_array($decoded) && isset($decoded['bases'])
            && ($decoded['schema'] ?? null) === BB_INDEX_SCHEMA) {
            return $decoded;
        }
    }

    $res = bb_fetch_all_videos([], $force);
    if (empty($res['success'])) { return $res; }
    $rows = $res['videos'];

    $files = [];
    foreach ($rows as $row) {
        if (empty($row['hasGloss'])) { continue; }
        $p = bb_srt_path($row['base']);
        if ($p !== null) { $files[$row['base']] = $p; }
    }

    $agg = gloss_aggregate($files);
    $agg['schema'] = BB_INDEX_SCHEMA;
    $agg['built_at'] = time();
    $agg['videos'] = count($rows);

    $dir = dirname(BB_CACHE);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents(BB_CACHE, json_encode($agg), LOCK_EX);

    return $agg;
}

/**
 * One sentence's public timings record: its metadata plus every gloss cue.
 *
 * Field names are camelCase throughout, including for the upstream columns
 * that arrive snake_case (`sentence_id`, `mcp_status_tijd_annotatie`). This is
 * a published contract, not a passthrough, so it gets one naming convention
 * rather than inheriting the database's.
 *
 * A sentence whose SRT cannot be read is still returned, with an empty
 * `glosses` list and a non-null `srtError`. Dropping it would make `total`
 * disagree with the number of records a client can actually page through, and
 * would hide a broken file behind a silently shorter list.
 */
function bb_sentence_timings($row, $sensesIndex = null) {
    $base = $row['base'] ?? '';

    $glosses  = [];
    $srtError = null;

    $path = bb_srt_path($base);
    if ($path === null) {
        $srtError = 'Gloss-SRT niet gevonden voor basis ' . $base . '.';
    } else {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $srtError = 'Gloss-SRT kon niet gelezen worden voor basis ' . $base . '.';
        } else {
            $glosses = srt_gloss_timings($contents);
        }
    }

    // Passed in by bb_timings so the index is loaded once per request rather
    // than once per sentence; defaulted here so a direct caller still works.
    if ($sensesIndex === null) { $sensesIndex = bb_senses_index(); }

    // Looked up on the exact cue, not the folded base gloss: Signbank has
    // HUILEN-A but no HUILEN, so folding first would lose almost every match.
    //
    // Rebuilt rather than appended so `senses` sits with the identity fields
    // it belongs to instead of trailing the timing fields. srt_gloss_timings
    // stays a pure timing parse and never learns about Signbank.
    foreach ($glosses as $i => $g) {
        $glosses[$i] = [
            'index'      => $g['index'],
            'gloss'      => $g['gloss'],
            'baseGloss'  => $g['baseGloss'],
            'senses'     => gloss_senses_lookup($sensesIndex, $g['gloss']),
            'start'      => $g['start'],
            'end'        => $g['end'],
            'startMs'    => $g['startMs'],
            'endMs'      => $g['endMs'],
            'durationMs' => $g['durationMs'],
        ];
    }

    // Null timecodes are excluded rather than coerced: min() over a list
    // containing null returns null in PHP, which would report a 0 ms sentence
    // start for a file with one unparsable cue among many good ones.
    $starts = [];
    $ends   = [];
    foreach ($glosses as $g) {
        if ($g['startMs'] !== null) { $starts[] = $g['startMs']; }
        if ($g['endMs']   !== null) { $ends[]   = $g['endMs']; }
    }

    $glbUrl = $row['glbUrl'] ?? null;

    return [
        'base'        => $base,
        'sentenceId'  => isset($row['sentence_id']) ? (int)$row['sentence_id'] : null,
        'videoId'     => isset($row['video_id']) ? (int)$row['video_id'] : null,
        'zin'         => $row['zin'] ?? null,
        'thema'       => $row['thema'] ?? null,
        'takeNumber'  => isset($row['takeNumber']) ? (int)$row['takeNumber'] : null,
        'statusTijdAnnotatie' => $row['mcp_status_tijd_annotatie'] ?? null,
        'fbxUrl'      => bb_absolute_url(bb_fbx_url($glbUrl)),
        // Carries over from the GLB the FBX is derived from: false means this
        // take was baked but a newer take of the same sentence exists unbaked.
        'fbxIsLatestTake' => isset($row['glbIsLatestTake']) ? (bool)$row['glbIsLatestTake'] : null,
        // srtUrl keeps its name and its meaning — the gloss tier — because
        // clients already depend on it. The other two tiers get their own
        // fields rather than turning srtUrl into an object.
        'srtUrl'      => bb_absolute_url($row['glossSrtUrl'] ?? null),
        'nederlandsSrt' => bb_sidecar_srt_url($base, $row['glossSrtUrl'] ?? null, BB_NL_SUFFIX),
        'gvgSrt'        => bb_sidecar_srt_url($base, $row['glossSrtUrl'] ?? null, BB_GVG_SUFFIX),
        'glossCount'  => count($glosses),
        'firstStartMs' => count($starts) ? min($starts) : null,
        'lastEndMs'    => count($ends)   ? max($ends)   : null,
        'srtError'    => $srtError,
        'glosses'     => $glosses,
    ];
}

/**
 * The public timings endpoint: gloss cue timings per sentence, paged.
 *
 * Filtering happens locally against the full (cached) video list rather than
 * being pushed upstream, because `base` and `gloss` have no upstream
 * equivalent and mixing local and remote filters would make `total` mean two
 * different things depending on which filters a caller happened to combine.
 *
 * Only the rows in the requested page have their SRT read, so page size —
 * not corpus size — is what costs disk I/O.
 *
 * @param array $opts ['bases'=>string[], 'sentenceId'=>?int, 'gloss'=>?string,
 *                     'search'=>?string, 'mcpStatusTijdAnnotatie'=>?string,
 *                     'page'=>int, 'limit'=>int]
 */
function bb_timings($opts) {
    $filters = [];
    foreach (['mcpStatusTijdAnnotatie', 'search'] as $key) {
        if (isset($opts[$key]) && $opts[$key] !== '' && $opts[$key] !== null) {
            $filters[$key] = $opts[$key];
        }
    }

    $res = bb_fetch_all_videos($filters);
    if (empty($res['success'])) { return $res; }
    $rows = $res['videos'];

    if (!empty($opts['bases'])) {
        $wanted = array_flip($opts['bases']);
        $rows = array_values(array_filter($rows, function ($row) use ($wanted) {
            return isset($wanted[$row['base'] ?? '']);
        }));
    }

    if (!empty($opts['sentenceId'])) {
        $sentenceId = (int)$opts['sentenceId'];
        $rows = array_values(array_filter($rows, function ($row) use ($sentenceId) {
            return (int)($row['sentence_id'] ?? 0) === $sentenceId;
        }));
    }

    if (!empty($opts['gloss'])) {
        // Matches on the folded base gloss, which is what the Glossen tab and
        // the `glosses` action both key on. Reuses the gloss index's
        // occurrences rather than re-reading every SRT in the corpus just to
        // find out which files mention the gloss.
        $agg = bb_glosses();
        if (isset($agg['error'])) { return $agg; }

        $entry = $agg['bases'][$opts['gloss']] ?? null;
        $videos = [];
        foreach (($entry['occurrences'] ?? []) as $occ) { $videos[$occ['video']] = true; }

        $rows = array_values(array_filter($rows, function ($row) use ($videos) {
            return isset($videos[$row['base'] ?? '']);
        }));
    }

    $total = count($rows);

    $limit = isset($opts['limit']) ? (int)$opts['limit'] : BB_TIMINGS_LIMIT;
    if ($limit < 1) { $limit = BB_TIMINGS_LIMIT; }
    if ($limit > BB_TIMINGS_MAX_LIMIT) { $limit = BB_TIMINGS_MAX_LIMIT; }

    $page = isset($opts['page']) ? (int)$opts['page'] : 1;
    if ($page < 1) { $page = 1; }

    $slice = array_slice($rows, ($page - 1) * $limit, $limit);

    // Loaded once for the whole page, not per sentence.
    $sensesIndex = bb_senses_index();

    $sentences = [];
    foreach ($slice as $row) { $sentences[] = bb_sentence_timings($row, $sensesIndex); }

    return [
        'success'   => true,
        'total'     => $total,
        'page'      => $page,
        'limit'     => $limit,
        'count'     => count($sentences),
        'sentences' => $sentences,
    ];
}

/**
 * Category map, or an empty structure when categories.json is absent.
 */
function bb_categories() {
    if (!file_exists(BB_CATEGORIES)) {
        return ['categories' => [], 'glosses' => []];
    }
    $decoded = json_decode(@file_get_contents(BB_CATEGORIES), true);
    if (!is_array($decoded) || !isset($decoded['glosses'])) {
        return ['categories' => [], 'glosses' => []];
    }
    return $decoded;
}

/**
 * Stream a ZIP of gloss SRTs for the given bases.
 *
 * When the requested base count exceeds BB_MAX_ZIP, the excess is dropped
 * from the archive but never silently: a TRUNCATED.txt manifest is added
 * inside the ZIP itself, since a response header alone would be discarded by
 * the browser's file-download path and give the caller no way to notice.
 */
function bb_stream_zip($bases) {
    $requested = count($bases);
    $paths = [];
    $truncated = false;
    foreach ($bases as $base) {
        if (count($paths) >= BB_MAX_ZIP) { $truncated = true; break; }
        $p = bb_srt_path($base);
        if ($p !== null) { $paths[basename($p)] = $p; }
    }

    if (!count($paths)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Geen geldige SRT-bestanden gevonden.']);
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'bbzip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Kon ZIP niet aanmaken.']);
        return;
    }
    foreach ($paths as $name => $path) { $zip->addFile($path, $name); }
    if ($truncated) {
        $zip->addFromString('TRUNCATED.txt',
            "Deze ZIP is afgekapt.\n" .
            "Opgevraagd: {$requested} basissen\n" .
            "Opgenomen: " . count($paths) . " basissen\n" .
            "Limiet (BB_MAX_ZIP): " . BB_MAX_ZIP . "\n");
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="blendBaking_glossen_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
}

// ---------------------------------------------------------------------------
// HTTP dispatch. Skipped on CLI so tests can include this file.
// ---------------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && isset($_GET['action'])) {
    $action = $_GET['action'];

    // Read-only, unauthenticated, public data — the same posture as the
    // .htaccess on /gebarenoverleg_media/fbx/post_processed/, which already
    // serves the FBX and GLB files this API points at with Allow-Origin *.
    // Sent before any early exit so the zip action gets them too. No
    // Allow-Credentials: there is no session to leak, and adding it would
    // make the wildcard origin illegal anyway.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit();
    }

    if ($action === 'zip') {
        $raw = $_POST['bases'] ?? $_GET['bases'] ?? '';
        $bases = is_array($raw) ? $raw : explode(',', $raw);
        // Filter on length, not truthiness: the default array_filter callback
        // would drop a base literally equal to "0".
        $bases = array_filter($bases, function ($v) { return strlen($v) > 0; });
        bb_stream_zip($bases);
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    switch ($action) {
        case 'list':
            $listed = bb_fetch_videos([
                'baked' => '1',
                'hasGloss' => '1',
                'mcpStatusTijdAnnotatie' => $_GET['mcpStatusTijdAnnotatie'] ?? null,
                'search' => $_GET['search'] ?? null,
                'page'   => $_GET['page'] ?? 1,
                'limit'  => $_GET['limit'] ?? 500,
            ]);
            // Add fbxUrl alongside upstream's fields rather than replacing
            // glbUrl: this action is a proxy, and a proxy that drops fields is
            // a lossy one. The UI reads fbxUrl; nothing else has to change.
            if (!empty($listed['videos'])) {
                foreach ($listed['videos'] as $i => $row) {
                    $listed['videos'][$i]['fbxUrl'] = bb_fbx_url($row['glbUrl'] ?? null);
                }
            }
            echo json_encode($listed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'timings':
            // `bases` accepts a comma-separated string or a repeated param,
            // mirroring the zip action, so a caller can ask for many sentences
            // in one request without tripping PHP's max_input_vars.
            $rawBases = $_GET['bases'] ?? $_POST['bases'] ?? $_GET['base'] ?? '';
            $bases = is_array($rawBases) ? $rawBases : explode(',', $rawBases);
            $bases = array_values(array_filter(array_map('trim', $bases), function ($v) {
                return strlen($v) > 0;
            }));

            echo json_encode(bb_timings([
                'bases'                  => $bases,
                'sentenceId'             => $_GET['sentenceId'] ?? null,
                'gloss'                  => $_GET['gloss'] ?? null,
                'search'                 => $_GET['search'] ?? null,
                'mcpStatusTijdAnnotatie' => $_GET['mcpStatusTijdAnnotatie'] ?? null,
                'page'                   => $_GET['page'] ?? 1,
                'limit'                  => $_GET['limit'] ?? BB_TIMINGS_LIMIT,
            ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'glosses':
            $agg = bb_glosses(($_GET['refresh'] ?? '') === '1');
            $agg['success'] = !isset($agg['error']);
            $agg['categoryMap'] = bb_categories();
            echo json_encode($agg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'categories':
            echo json_encode(bb_categories(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;

        case 'rebuildIndex':
            $agg = bb_glosses(true);
            echo json_encode(['success' => !isset($agg['error']),
                              'bases' => count($agg['bases'] ?? []),
                              'files' => $agg['files'] ?? 0]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Onbekende actie: ' . $action]);
    }
    exit();
}
