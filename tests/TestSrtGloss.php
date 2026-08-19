<?php
/**
 * Unit tests for srtGloss.php — SRT cue extraction and base-gloss folding.
 */
require_once __DIR__ . '/../srtGloss.php';

class TestSrtGloss {
    private $passed = 0;
    private $failed = 0;
    private $messages = [];

    private function assertTrue($condition, $message = "Assertion failed") {
        if ($condition) { $this->passed++; return true; }
        $this->failed++; $this->messages[] = "FAIL: $message"; return false;
    }

    private function assertEquals($expected, $actual, $message = "Values are not equal") {
        return $this->assertTrue(
            $expected === $actual,
            $message . " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")"
        );
    }

    public function testCuesSkipIndexesTimecodesAndBlanks() {
        $srt = "1\n00:00:01,975 --> 00:00:02,871\nDOEN-C\n\n2\n00:00:03,044 --> 00:00:03,277\nDOEN-A\n";
        $this->assertEquals(['DOEN-C', 'DOEN-A'], srt_cues($srt), "plain two-cue file");
    }

    public function testCuesHandleCrlf() {
        $srt = "1\r\n00:00:01,975 --> 00:00:02,871\r\nPT-1hand\r\n\r\n";
        $this->assertEquals(['PT-1hand'], srt_cues($srt), "CRLF line endings");
    }

    public function testCuesHandleEmptyFile() {
        $this->assertEquals([], srt_cues(''), "empty file yields no cues");
        $this->assertEquals([], srt_cues("1\n00:00:01,000 --> 00:00:02,000\n\n"), "cue with blank text");
    }

    public function testBaseGlossStripsSingleLetterVariant() {
        $this->assertEquals('DOEN', srt_base_gloss('DOEN-C'), "uppercase variant");
        $this->assertEquals('EVEN', srt_base_gloss('EVEN-B'), "uppercase variant B");
        $this->assertEquals('MOOI', srt_base_gloss('MOOI-a'), "lowercase variant folds to same base");
        $this->assertEquals('MOOI', srt_base_gloss('MOOI-A'), "uppercase counterpart of the same base");
    }

    public function testBaseGlossLeavesNonVariantsIntact() {
        // Every one of these appears in live data and must survive unchanged.
        $this->assertEquals('PT-1hand', srt_base_gloss('PT-1hand'), "multi-letter suffix is not a variant");
        $this->assertEquals('PT-1hand:1', srt_base_gloss('PT-1hand:1'), "colon-suffixed pointing sign");
        $this->assertEquals('PO+PT', srt_base_gloss('PO+PT'), "compound pointing sign");
        $this->assertEquals('MOVE+C', srt_base_gloss('MOVE+C'), "classifier ending in a single letter but no hyphen");
        $this->assertEquals('MOVE+geld', srt_base_gloss('MOVE+geld'), "classifier with a word");
        $this->assertEquals('MOVE+Baby_snavel', srt_base_gloss('MOVE+Baby_snavel'), "classifier with underscore");
        $this->assertEquals('#J', srt_base_gloss('#J'), "fingerspelling");
        $this->assertEquals('-', srt_base_gloss('-'), "bare hyphen placeholder");
        $this->assertEquals('nvt', srt_base_gloss('nvt'), "nvt placeholder");
        $this->assertEquals('GAAN-NAAR', srt_base_gloss('GAAN-NAAR-A'), "hyphenated lemma keeps its internal hyphens");
    }

    public function testAggregateCountsOccurrencesAndVideos() {
        $tmp = sys_get_temp_dir() . '/srttest_' . getmypid();
        @mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/A.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nAAP-A\n\n2\n00:00:02,000 --> 00:00:03,000\nAAP-B\n");
        file_put_contents($tmp . '/B.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nAAP-A\n\n2\n00:00:02,000 --> 00:00:03,000\nBOEK\n");

        $agg = gloss_aggregate(['A' => $tmp . '/A.srt', 'B' => $tmp . '/B.srt']);

        $this->assertEquals(2, $agg['files'], "two files parsed");
        $this->assertEquals(3, $agg['bases']['AAP']['count'], "AAP occurs three times across both files");
        $this->assertEquals(2, $agg['bases']['AAP']['videos'], "AAP appears in two videos");
        $this->assertEquals(2, $agg['bases']['AAP']['variants']['AAP-A'], "AAP-A counted twice");
        $this->assertEquals(1, $agg['bases']['AAP']['variants']['AAP-B'], "AAP-B counted once");
        $this->assertEquals(1, $agg['bases']['BOEK']['videos'], "BOEK appears in one video");

        unlink($tmp . '/A.srt'); unlink($tmp . '/B.srt'); rmdir($tmp);
    }

    public function testAggregateSkipsMissingFiles() {
        $agg = gloss_aggregate(['X' => '/nonexistent/nope.srt']);
        $this->assertEquals(0, $agg['files'], "missing files are skipped, not fatal");
        $this->assertEquals([], $agg['bases'], "no bases from a missing file");
    }

    public function testSafeBaseAcceptsRealBases() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals('M20240925_1824', bb_safe_base('M20240925_1824'), "ordinary zin base");
        $this->assertEquals('#A_241120_0', bb_safe_base('#A_241120_0'), "base with hash and digits");
    }

    public function testSafeBaseRejectsTraversal() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals(null, bb_safe_base('../../etc/passwd'), "parent traversal");
        $this->assertEquals(null, bb_safe_base('..%2fetc%2fpasswd'), "encoded traversal");
        $this->assertEquals(null, bb_safe_base('/etc/passwd'), "absolute path");
        $this->assertEquals(null, bb_safe_base('M2024 1824'), "space is not allowed");
        $this->assertEquals(null, bb_safe_base(''), "empty base");
        $this->assertEquals(null, bb_safe_base('M2024/1824'), "slash is not allowed");
    }

    public function testSrtPathStaysInsideEafDir() {
        require_once __DIR__ . '/../api.php';
        $this->assertEquals(null, bb_srt_path('../../etc/passwd'), "traversal yields no path");
        $this->assertEquals(null, bb_srt_path('definitely_not_a_real_base_xyz'), "nonexistent yields no path");

        // A base known to exist from the live measurement.
        $p = bb_srt_path('M20240828_0037');
        $this->assertTrue(
            is_string($p) && strpos($p, '/web/zin/eaf/zin/') === 0,
            "a real base resolves inside the SRT directory"
        );
    }

    /**
     * Run bb_glosses() against a throwaway PHP-built-in-server fixture, in a
     * fresh subprocess so BB_ZIN_API / BB_CACHE can be redefined without
     * disturbing the real upstream or the shared cache file used elsewhere.
     *
     * $upstreamBody is the full <?php ... source of a router script that
     * answers listMocapFiles-shaped requests (keyed on $_GET['page']).
     * Returns the decoded ['has_error' => bool, 'error' => ?string,
     * 'cache_exists' => bool] reported by the subprocess, or null if the
     * fixture server never came up.
     */
    private function bbRunAgainstFakeUpstream($upstreamBody) {
        $tmpDir = sys_get_temp_dir() . '/bbfake_' . getmypid() . '_' . mt_rand(1000, 9999);
        mkdir($tmpDir, 0777, true);
        $router = $tmpDir . '/router.php';
        file_put_contents($router, $upstreamBody);
        $cacheFile = $tmpDir . '/cache.json';

        $port = mt_rand(20000, 60000);
        shell_exec(sprintf(
            'php -S 127.0.0.1:%d %s > %s 2>&1 & echo $! > %s',
            $port, escapeshellarg($router), escapeshellarg($tmpDir . '/server.log'), escapeshellarg($tmpDir . '/server.pid')
        ));

        // Preflight: wait for the dev server to actually accept connections
        // before trusting the test to exercise the real branch under test,
        // rather than a connection-refused error that would coincidentally
        // still look like "an error was reported".
        $up = false;
        for ($i = 0; $i < 20; $i++) {
            $probe = @file_get_contents("http://127.0.0.1:$port/router.php?page=1");
            if ($probe !== false && $probe !== '') { $up = true; break; }
            usleep(100000);
        }

        $result = null;
        if ($up) {
            $runner = $tmpDir . '/runner.php';
            file_put_contents($runner, sprintf(<<<'PHP'
<?php
define('BB_ZIN_API', %s);
define('BB_CACHE', %s);
require %s;
$agg = bb_glosses(true);
echo json_encode([
    'has_error'    => isset($agg['error']),
    'error'        => $agg['error'] ?? null,
    'cache_exists' => file_exists(BB_CACHE),
]);
PHP
                ,
                var_export("http://127.0.0.1:$port/router.php", true),
                var_export($cacheFile, true),
                var_export(__DIR__ . '/../api.php', true)
            ));
            $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
            $result = json_decode($out, true);
        }

        $pid = trim(@file_get_contents($tmpDir . '/server.pid'));
        if ($pid !== '' && ctype_digit($pid)) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
        foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
        @rmdir($tmpDir);

        return $result;
    }

    public function testGlossesReportsFailureAndDoesNotCacheOnPartialPageFailure() {
        require_once __DIR__ . '/../api.php';
        // Page 1 succeeds but reports more total rows than it returns, forcing
        // a second page; page 2 fails outright (HTTP 500). bb_glosses must
        // treat that as a hard error, not silently proceed on the partial set.
        $result = $this->bbRunAgainstFakeUpstream(<<<'PHP'
<?php
header('Content-Type: application/json');
$page = (int)($_GET['page'] ?? 1);
if ($page === 1) {
    echo json_encode(['success' => true, 'total' => 6, 'videos' => [
        ['base' => 'X1', 'hasGloss' => false],
        ['base' => 'X2', 'hasGloss' => false],
        ['base' => 'X3', 'hasGloss' => false],
    ]]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'simulated failure']);
}
PHP
        );
        $this->assertTrue(is_array($result), "fixture ran and returned JSON: " . var_export($result, true));
        if (is_array($result)) {
            $this->assertTrue($result['has_error'] === true, "a mid-pagination upstream failure is reported as an error");
            $this->assertTrue($result['cache_exists'] === false, "a partial result must not be written to the cache");
        }
    }

    public function testGlossesStopsCleanlyWhenUpstreamHasFewerRowsThanTotal() {
        require_once __DIR__ . '/../api.php';
        // Page 1 again promises 6 total but only 3 rows exist anywhere; page 2
        // succeeds with zero rows (data changed between calls, not a failure).
        // bb_glosses must terminate the loop cleanly and still cache the result.
        $result = $this->bbRunAgainstFakeUpstream(<<<'PHP'
<?php
header('Content-Type: application/json');
$page = (int)($_GET['page'] ?? 1);
if ($page === 1) {
    echo json_encode(['success' => true, 'total' => 6, 'videos' => [
        ['base' => 'X1', 'hasGloss' => false],
        ['base' => 'X2', 'hasGloss' => false],
        ['base' => 'X3', 'hasGloss' => false],
    ]]);
} else {
    echo json_encode(['success' => true, 'videos' => []]);
}
PHP
        );
        $this->assertTrue(is_array($result), "fixture ran and returned JSON: " . var_export($result, true));
        if (is_array($result)) {
            $this->assertTrue($result['has_error'] === false, "fewer upstream rows than total is not treated as an error");
            $this->assertTrue($result['cache_exists'] === true, "a clean, if short, result is still cached");
        }
    }

    public function testZipTruncationAddsManifestWhenCapExceeded() {
        require_once __DIR__ . '/../api.php';
        $tmpDir = sys_get_temp_dir() . '/bbzip_' . getmypid() . '_' . mt_rand(1000, 9999);
        mkdir($tmpDir, 0777, true);
        $out = $tmpDir . '/out.zip';
        $runner = $tmpDir . '/runner.php';
        file_put_contents($runner, sprintf(<<<'PHP'
<?php
define('BB_MAX_ZIP', 1);
require %s;
ob_start();
bb_stream_zip(['M20240828_0037', 'M20240828_0039']);
file_put_contents(%s, ob_get_clean());
PHP
            , var_export(__DIR__ . '/../api.php', true), var_export($out, true)
        ));
        shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');

        $zip = new ZipArchive();
        $opened = ($zip->open($out) === true);
        $this->assertTrue($opened, "a truncated request still produces a valid zip archive");
        if ($opened) {
            $manifest = $zip->getFromName('TRUNCATED.txt');
            $this->assertTrue($manifest !== false, "TRUNCATED.txt is present when BB_MAX_ZIP is exceeded");
            $this->assertTrue(strpos($manifest, 'Opgevraagd: 2 basissen') !== false, "manifest states how many bases were requested");
            $this->assertTrue(strpos($manifest, 'Opgenomen: 1 basissen') !== false, "manifest states how many were included");
            $this->assertTrue(strpos($manifest, 'Limiet') !== false, "manifest names the cap");
            $zip->close();
        }

        foreach (glob($tmpDir . '/*') as $f) { @unlink($f); }
        @rmdir($tmpDir);
    }

    public function testZipHasNoManifestWhenNotTruncated() {
        require_once __DIR__ . '/../api.php';
        ob_start();
        bb_stream_zip(['M20240828_0037', 'M20240828_0039']);
        $raw = ob_get_clean();

        $tmp = sys_get_temp_dir() . '/bbzip_notrunc_' . getmypid() . '.zip';
        file_put_contents($tmp, $raw);
        $zip = new ZipArchive();
        $opened = ($zip->open($tmp) === true);
        $this->assertTrue($opened, "produces a valid zip");
        if ($opened) {
            $this->assertEquals(2, $zip->numFiles, "exactly the two requested SRTs, no manifest, when nothing was truncated");
            $this->assertTrue($zip->getFromName('TRUNCATED.txt') === false, "no manifest when nothing was truncated");
            $zip->close();
        }
        unlink($tmp);
    }

    public function testCueEntriesCaptureStartTimecodes() {
        $srt = "1\n00:00:01,975 --> 00:00:02,871\nDOEN-C\n\n2\n00:00:03,044 --> 00:00:03,277\nDOEN-A\n";
        $entries = srt_cue_entries($srt);
        $this->assertEquals(2, count($entries), "two cue entries");
        $this->assertEquals('DOEN-C', $entries[0]['text'], "first cue text");
        $this->assertEquals('00:00:01,975', $entries[0]['start'], "first cue start timecode");
        $this->assertEquals('DOEN-A', $entries[1]['text'], "second cue text");
        $this->assertEquals('00:00:03,044', $entries[1]['start'], "second cue start timecode");
    }

    public function testCueEntriesHandleCrlfAndEdgeCues() {
        $srt = "1\r\n00:00:02,117 --> 00:00:02,173\r\nPT-1hand:1\r\n\r\n"
             . "2\r\n00:00:04,000 --> 00:00:05,000\r\nMOVE+Baby_snavel\r\n\r\n"
             . "3\r\n00:00:06,500 --> 00:00:07,000\r\n#J\r\n";
        $entries = srt_cue_entries($srt);
        $this->assertEquals(3, count($entries), "CRLF file yields three entries");
        $this->assertEquals('PT-1hand:1', $entries[0]['text'], "colon cue survives");
        $this->assertEquals('00:00:02,117', $entries[0]['start'], "CRLF start timecode");
        $this->assertEquals('MOVE+Baby_snavel', $entries[1]['text'], "underscore classifier survives");
        $this->assertEquals('#J', $entries[2]['text'], "fingerspelling survives");
        $this->assertEquals('00:00:06,500', $entries[2]['start'], "third start timecode");
    }

    public function testCueEntriesLeaveStartNullWhenNoTimecodePrecedes() {
        // A cue line with no preceding timecode must still be captured, with a null start.
        $entries = srt_cue_entries("STRAY-A\n");
        $this->assertEquals(1, count($entries), "stray cue still captured");
        $this->assertEquals('STRAY-A', $entries[0]['text'], "stray cue text");
        $this->assertEquals(null, $entries[0]['start'], "stray cue has no start timecode");
    }

    public function testCuesStillReturnsPlainStrings() {
        // srt_cues() is a public contract; adding timings must not change it.
        $srt = "1\n00:00:01,975 --> 00:00:02,871\nDOEN-C\n\n2\n00:00:03,044 --> 00:00:03,277\nDOEN-A\n";
        $this->assertEquals(['DOEN-C', 'DOEN-A'], srt_cues($srt), "srt_cues unchanged");
        $this->assertEquals([], srt_cues(''), "empty file still yields no cues");
    }

    public function testAggregateRecordsOccurrencesWithVideoStartAndCue() {
        $tmp = sys_get_temp_dir() . '/srtocc_' . getmypid();
        @mkdir($tmp, 0777, true);
        // AAP appears TWICE in file A (as two variants) and once in file B.
        file_put_contents($tmp . '/A.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nAAP-A\n\n2\n00:00:05,500 --> 00:00:06,000\nAAP-B\n");
        file_put_contents($tmp . '/B.srt',
            "1\n00:00:03,250 --> 00:00:04,000\nAAP-A\n\n2\n00:00:08,000 --> 00:00:09,000\nBOEK\n");

        $agg = gloss_aggregate(['A' => $tmp . '/A.srt', 'B' => $tmp . '/B.srt']);
        $aap = $agg['bases']['AAP'];

        $this->assertEquals(3, $aap['count'], "AAP occurs three times");
        $this->assertEquals(2, $aap['videos'], "AAP appears in two videos");
        $this->assertEquals(3, count($aap['occurrences']), "one occurrence entry per cue instance");

        $this->assertEquals('A', $aap['occurrences'][0]['video'], "first occurrence video");
        $this->assertEquals('00:00:01,000', $aap['occurrences'][0]['start'], "first occurrence start");
        $this->assertEquals('AAP-A', $aap['occurrences'][0]['cue'], "first occurrence keeps the exact variant");
        $this->assertEquals('AAP-B', $aap['occurrences'][1]['cue'], "second occurrence is the other variant");
        $this->assertEquals('A', $aap['occurrences'][1]['video'], "both A occurrences share the video");
        $this->assertEquals('B', $aap['occurrences'][2]['video'], "third occurrence is the other video");
        $this->assertEquals('00:00:03,250', $aap['occurrences'][2]['start'], "third occurrence start");

        $this->assertEquals(1, count($agg['bases']['BOEK']['occurrences']), "BOEK has one occurrence");

        unlink($tmp . '/A.srt'); unlink($tmp . '/B.srt'); rmdir($tmp);
    }

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
