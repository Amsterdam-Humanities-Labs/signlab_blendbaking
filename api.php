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

define('BB_EAF_DIR',    '/web/zin/eaf/zin/');
define('BB_SRT_SUFFIX', '_Signbank_ID_glossen.srt');
define('BB_ZIN_API',    'https://signcollect.nl/zin/getZinnen.php');
define('BB_CACHE',      __DIR__ . '/cache/gloss_index.json');
define('BB_CATEGORIES', __DIR__ . '/categories.json');
define('BB_MAX_ZIP',    2000);

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
    curl_close($ch);

    if ($body === false) {
        return ['success' => false, 'error' => 'Upstream request failed: ' . $err];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Upstream returned invalid JSON'];
    }
    return $decoded;
}

/**
 * Aggregate glosses for the baked videos, cached to BB_CACHE.
 */
function bb_glosses($force = false) {
    if (!$force && file_exists(BB_CACHE)) {
        $decoded = json_decode(@file_get_contents(BB_CACHE), true);
        if (is_array($decoded) && isset($decoded['bases'])) { return $decoded; }
    }

    $res = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => 1]);
    if (empty($res['success'])) { return $res; }

    $rows = $res['videos'];
    $total = (int)$res['total'];
    $page = 2;
    while (count($rows) < $total) {
        $next = bb_fetch_videos(['baked' => '1', 'hasGloss' => '1', 'limit' => 500, 'page' => $page]);
        if (empty($next['success']) || !count($next['videos'])) { break; }
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
 */
function bb_stream_zip($bases) {
    $paths = [];
    foreach ($bases as $base) {
        if (count($paths) >= BB_MAX_ZIP) { break; }
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Kon ZIP niet aanmaken.']);
        return;
    }
    foreach ($paths as $name => $path) { $zip->addFile($path, $name); }
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
        $bases = is_array($raw) ? $raw : array_filter(explode(',', $raw));
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
