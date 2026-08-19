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
if (!defined('BB_ZIN_API'))    define('BB_ZIN_API',    'https://signcollect.nl/zin/getZinnen.php');
if (!defined('BB_CACHE'))      define('BB_CACHE',      __DIR__ . '/cache/gloss_index.json');
if (!defined('BB_CATEGORIES')) define('BB_CATEGORIES', __DIR__ . '/categories.json');
if (!defined('BB_MAX_ZIP'))    define('BB_MAX_ZIP',    2000);

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
 * Absolute path to a base's gloss SRT, confirmed to sit inside BB_EAF_DIR.
 */
function bb_srt_path($base) {
    $base = bb_safe_base($base);
    if ($base === null) { return null; }

    $candidate = BB_EAF_DIR . $base . BB_SRT_SUFFIX;
    $real = realpath($candidate);
    if ($real === false) { return null; }

    $root = realpath(BB_EAF_DIR);
    if ($root === false || strpos($real, $root . '/') !== 0) { return null; }

    return $real;
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
// missing.
if (!defined('BB_INDEX_SCHEMA')) define('BB_INDEX_SCHEMA', 1);

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

    $res = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);
    if (empty($res['success'])) { return $res; }

    // Match dump_base_glosses.php's guard: upstream can return success:true
    // with no 'videos'/'total' key, and a plain ['videos'] / (int)$res['total']
    // would raise a PHP 8 TypeError (uncaught 500) in that case.
    $rows = $res['videos'] ?? [];
    $total = (int)($res['total'] ?? 0);
    $page = 2;
    while (count($rows) < $total) {
        $next = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => $page]);
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
            echo json_encode(bb_fetch_videos([
                'baked' => '1',
                'hasGloss' => '1',
                'mcpStatusTijdAnnotatie' => $_GET['mcpStatusTijdAnnotatie'] ?? null,
                'search' => $_GET['search'] ?? null,
                'page'   => $_GET['page'] ?? 1,
                'limit'  => $_GET['limit'] ?? 500,
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
