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

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
