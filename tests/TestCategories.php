<?php
/**
 * Contract tests for categories.json and bb_categories().
 *
 * These assert invariants, never the census: the corpus grows, so the number
 * of glosses in the map is not a fact worth freezing. What must hold for any
 * version of the file is that it parses, that it declares exactly the agreed
 * slug set, that every gloss carries a declared slug, and that the non-lexical
 * families stay machine-decidable.
 */
require_once __DIR__ . '/../srtGloss.php';
require_once __DIR__ . '/../api.php';

class TestCategories {
    private $passed = 0;
    private $failed = 0;
    private $messages = [];

    /** The 21 slugs the tool's category filter is built around. */
    private static $requiredSlugs = [
        'dier', 'eten_drinken', 'familie_personen', 'werkwoord_actie', 'tijd',
        'plaats', 'kleur', 'getal', 'emotie', 'lichaam', 'kleding', 'vervoer',
        'natuur', 'wonen', 'school_werk', 'communicatie', 'pointing',
        'vingerspelling', 'classifier', 'leeg', 'overig',
    ];

    private static $nonLexicalSlugs = ['pointing', 'vingerspelling', 'classifier', 'leeg'];

    /**
     * Bases whose non-lexical slug cannot be derived from their spelling.
     *
     * 'X' is a fingerspelled letter that lost its '#' in annotation — its only
     * occurrence reads "LAKEN PT-Bhand #K #N #E X NOG AANWEZIG". Kept as an
     * explicit list so an exception stays a deliberate, reviewable decision
     * rather than a hole in the pattern rules.
     */
    private static $manualNonLexical = ['X' => 'vingerspelling'];

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

    /**
     * The slug a base's spelling alone dictates, or null when the base is
     * lexical and its slug is a matter of meaning.
     *
     * Matching normalises a copy (NBSP to space, trimmed, upper-cased) so that
     * misspelled and differently-cased members of a family — 'pt-arc',
     * 'Pt-1hand', "\u{a0}nvt" — land with their canonical siblings. The keys of
     * categories.json always stay the raw corpus string.
     */
    public static function patternSlug($base) {
        $n = trim(str_replace("\xC2\xA0", ' ', (string)$base));
        $u = strtoupper($n);

        if ($n === '' || $u === 'NVT' || $n === '-') { return 'leeg'; }
        if (preg_match('/^[^\p{L}\p{N}]+$/u', $n)) { return 'leeg'; }
        if (strncmp($n, '#', 1) === 0) { return 'vingerspelling'; }
        // Corpus-NGT classifier predicates: a predicate type plus a handshape.
        if (preg_match('/^(MOVE|BE|AT|SHAPE)\+/', $u)) { return 'classifier'; }
        // PT-1hand, PT:up, PT1-hand, Pt-arc, PO, PO+PT, PO-PT, PALM-UP+PT.
        if (preg_match('/^PT[-:+0-9]/', $u)) { return 'pointing'; }
        if (in_array($u, ['PO', 'PO+PT', 'PO-PT', 'PALM-UP+PT'], true)) { return 'pointing'; }

        return null;
    }

    public function testCategoriesFileIsValidJson() {
        $raw = @file_get_contents(BB_CATEGORIES);
        $this->assertTrue(is_string($raw) && $raw !== '', "categories.json exists and is non-empty");
        json_decode((string)$raw, true);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error(), "categories.json parses: " . json_last_error_msg());
    }

    public function testDeclaresExactlyTheRequiredSlugsWithDutchLabels() {
        $cat = bb_categories();
        $this->assertTrue(!empty($cat['categories']), "bb_categories() returns a populated category list");

        $slugs = [];
        foreach ($cat['categories'] as $c) {
            $this->assertTrue(isset($c['slug'], $c['label']), "every category entry has a slug and a label");
            $this->assertTrue(is_string($c['label'] ?? null) && trim((string)($c['label'] ?? '')) !== '',
                "category '" . ($c['slug'] ?? '?') . "' has a non-empty label");
            $slugs[] = $c['slug'] ?? null;
        }

        $this->assertEquals(count($slugs), count(array_unique($slugs)), "slugs are unique");

        $required = self::$requiredSlugs;
        sort($required);
        $declared = $slugs;
        sort($declared);
        $this->assertEquals($required, $declared, "declares exactly the required slug set");
    }

    public function testEveryGlossCarriesADeclaredSlug() {
        $cat = bb_categories();
        $known = [];
        foreach ($cat['categories'] as $c) { $known[$c['slug']] = true; }

        $this->assertTrue(count($cat['glosses']) > 0, "the gloss map is populated");

        $bad = [];
        foreach ($cat['glosses'] as $base => $slug) {
            if (!isset($known[$slug])) { $bad[] = "$base => $slug"; }
        }
        $this->assertEquals([], $bad, "no gloss carries an undeclared slug");
    }

    public function testNonLexicalPatternsNeverCarryASemanticSlug() {
        $cat = bb_categories();
        $wrong = [];
        foreach ($cat['glosses'] as $base => $slug) {
            $expected = self::patternSlug($base);
            if ($expected !== null && $slug !== $expected) { $wrong[] = "$base => $slug (expected $expected)"; }
        }
        $this->assertEquals([], $wrong, "every pattern-matched base carries its pattern's slug");
    }

    public function testNonLexicalSlugsAreOnlyUsedByTheirPatterns() {
        $cat = bb_categories();
        $wrong = [];
        foreach ($cat['glosses'] as $base => $slug) {
            if (!in_array($slug, self::$nonLexicalSlugs, true)) { continue; }
            if (self::patternSlug($base) !== null) { continue; }
            if ((self::$manualNonLexical[$base] ?? null) === $slug) { continue; }
            $wrong[] = "$base => $slug";
        }
        $this->assertEquals([], $wrong, "no lexical base is given a non-lexical slug");
    }

    public function testDocumentedExceptionsAreStillPresent() {
        $cat = bb_categories();
        foreach (self::$manualNonLexical as $base => $slug) {
            $this->assertEquals($slug, $cat['glosses'][$base] ?? null,
                "documented exception '$base' still carries '$slug'");
        }
    }

    public function testPatternSlugRecognisesEachFamily() {
        $this->assertEquals('pointing', self::patternSlug('PT-1hand'), "canonical pointing");
        $this->assertEquals('pointing', self::patternSlug('pt-arc'), "lower-cased pointing");
        $this->assertEquals('pointing', self::patternSlug('PT:duim'), "colon-separated pointing");
        $this->assertEquals('pointing', self::patternSlug('PO+PT'), "compound pointing");
        $this->assertEquals('vingerspelling', self::patternSlug('#J'), "fingerspelling");
        $this->assertEquals('classifier', self::patternSlug('MOVE+Baby_snavel'), "MOVE classifier");
        $this->assertEquals('classifier', self::patternSlug('BE+C_spreid'), "BE classifier");
        $this->assertEquals('leeg', self::patternSlug('nvt'), "nvt placeholder");
        $this->assertEquals('leeg', self::patternSlug("\xC2\xA0nvt"), "nvt behind a non-breaking space");
        $this->assertEquals('leeg', self::patternSlug('??'), "punctuation-only placeholder");
        $this->assertEquals(null, self::patternSlug('AAP'), "a lexical gloss matches no pattern");
        $this->assertEquals(null, self::patternSlug('POEZIE'), "a lexical gloss starting with PO is not pointing");
    }

    public function runTests() {
        foreach (get_class_methods($this) as $m) {
            if (strpos($m, 'test') === 0) { $this->$m(); }
        }
        return ['passed' => $this->passed, 'failed' => $this->failed, 'messages' => $this->messages];
    }
}
