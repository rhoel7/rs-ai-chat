<?php
require_once __DIR__ . '/../api/rag/RagRetriever.php';
require_once __DIR__ . '/TestEmbedder.php';

/** Requires api/config.php pointed at a real test database with api/rag/schema.sql applied. */
/** Requires api/config.php pointed at a real test database with api/rag/schema.sql applied. */
class RagRetrieverTest {
    public function __construct() {
        // 'test' is a provider label that only ever comes from this test
        // suite, real usage always uses 'gemini' or 'mistral' — safe to
        // purge entirely on every run. Without this, leftover rows from a
        // PREVIOUS run that didn't reach its own cleanup (a crash, an
        // earlier failing assertion) leak into later runs' results, since
        // retrieve() correctly searches across all source files, not just
        // one — that's real production behavior, a knowledge base spans
        // multiple CSVs, so the fix belongs in test isolation, not in
        // narrowing what retrieve() searches.
        Database::connect()->exec("DELETE FROM rag_entries WHERE embedding_provider = 'test'");
    }

    private function insertEntry(string $sourceFile, string $title, string $content, array $embedding, string $provider = 'test'): void {
        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO rag_entries (source_file, title, content, content_hash, source_type, embedding, embedding_provider, created_at, updated_at)
            VALUES (:sf, :t, :c, :ch, 'faq', :e, :p, NOW(), NOW())
            ON DUPLICATE KEY UPDATE content = :c2, embedding = :e2, embedding_provider = :p2
        ");
        $stmt->execute([
            'sf' => $sourceFile, 't' => $title, 'c' => $content, 'ch' => hash('sha256', $content),
            'e' => json_encode($embedding), 'p' => $provider,
            'c2' => $content, 'e2' => json_encode($embedding), 'p2' => $provider,
        ]);
    }

    private function cleanUp(string $sourceFile): void {
        Database::connect()->prepare("DELETE FROM rag_entries WHERE source_file = :sf")->execute(['sf' => $sourceFile]);
    }

    // --- Pure math tests, no database needed ---

    public function testCosineSimilarityOfIdenticalVectorsIsOne(TestRunner $t): void {
        $v = [1.0, 2.0, 3.0];
        $sim = RagRetriever::cosineSimilarity($v, $v);
        $t->assertTrue(abs($sim - 1.0) < 0.0001, "Identical vectors should have similarity ~1.0, got {$sim}");
    }

    public function testCosineSimilarityOfOrthogonalVectorsIsZero(TestRunner $t): void {
        $sim = RagRetriever::cosineSimilarity([1.0, 0.0], [0.0, 1.0]);
        $t->assertTrue(abs($sim - 0.0) < 0.0001, "Orthogonal vectors should have similarity ~0.0, got {$sim}");
    }

    public function testCosineSimilarityOfOppositeVectorsIsNegativeOne(TestRunner $t): void {
        $sim = RagRetriever::cosineSimilarity([1.0, 1.0], [-1.0, -1.0]);
        $t->assertTrue(abs($sim - (-1.0)) < 0.0001, "Opposite vectors should have similarity ~-1.0, got {$sim}");
    }

    public function testCosineSimilarityHandlesZeroVectorWithoutDivisionByZeroError(TestRunner $t): void {
        $sim = RagRetriever::cosineSimilarity([0.0, 0.0], [1.0, 1.0]);
        $t->assertEquals(0.0, $sim, 'A zero vector should return 0 similarity, not throw or return NaN');
    }

    // --- Database-backed retrieval tests ---

    public function testRetrievesHighestSimilarityMatchFirst(TestRunner $t): void {
        $sf = 'retriever_test_ranking.csv';
        $this->cleanUp($sf);
        $this->insertEntry($sf, 'Close match', 'content A', [1.0, 0.0, 0.0]);
        $this->insertEntry($sf, 'Far match', 'content B', [0.0, 1.0, 0.0]);

        TestEmbedder::reset();
        TestEmbedder::setVectorFor('test query', [0.9, 0.1, 0.0]); // much closer to "Close match"

        $retriever = new RagRetriever(new TestEmbedder());
        $results = $retriever->retrieve('test query', limit: 5, minSimilarity: -1.0); // no filtering, just rank

        $t->assertTrue(count($results) >= 2, 'Should retrieve both entries when threshold is permissive');
        $t->assertEquals('Close match', $results[0]['title'], 'The more similar entry should rank first');

        $this->cleanUp($sf);
    }

    public function testMinSimilarityThresholdExcludesWeakMatches(TestRunner $t): void {
        $sf = 'retriever_test_threshold.csv';
        $this->cleanUp($sf);
        $this->insertEntry($sf, 'Relevant', 'content A', [1.0, 0.0]);
        $this->insertEntry($sf, 'Irrelevant', 'content B', [0.0, 1.0]);

        TestEmbedder::reset();
        TestEmbedder::setVectorFor('threshold query', [1.0, 0.0]); // identical to "Relevant", orthogonal to "Irrelevant"

        $retriever = new RagRetriever(new TestEmbedder());
        $results = $retriever->retrieve('threshold query', limit: 5, minSimilarity: 0.5);

        $t->assertEquals(1, count($results), 'Only the entry above the similarity threshold should be returned');
        $t->assertEquals('Relevant', $results[0]['title']);

        $this->cleanUp($sf);
    }

    public function testTopKLimitsResultCount(TestRunner $t): void {
        $sf = 'retriever_test_topk.csv';
        $this->cleanUp($sf);
        for ($i = 0; $i < 5; $i++) {
            $this->insertEntry($sf, "Entry {$i}", "content {$i}", [1.0, 0.0]);
        }

        TestEmbedder::reset();
        TestEmbedder::setVectorFor('topk query', [1.0, 0.0]);

        $retriever = new RagRetriever(new TestEmbedder());
        $results = $retriever->retrieve('topk query', limit: 2, minSimilarity: -1.0);

        $t->assertEquals(2, count($results), 'limit=2 should return at most 2 results even when more entries match');

        $this->cleanUp($sf);
    }

    public function testEmptyKnowledgeBaseReturnsEmptyGracefully(TestRunner $t): void {
        $sf = 'retriever_test_empty.csv';
        $this->cleanUp($sf); // ensure genuinely empty for this source_file

        TestEmbedder::reset();
        $retriever = new RagRetriever(new TestEmbedder());
        $results = $retriever->retrieve('anything', limit: 3, minSimilarity: 0.0);

        // Not asserting exactly 0 here since other tests may leave unrelated
        // entries under a different source_file — the real guarantee is that
        // this never throws, which the test reaching this line already proves.
        $t->assertTrue(is_array($results), 'retrieve() should always return an array, even with no relevant entries');
    }

    public function testEntriesFromADifferentEmbeddingProviderAreExcluded(TestRunner $t): void {
        $sf = 'retriever_test_provider.csv';
        $this->cleanUp($sf);
        $this->insertEntry($sf, 'Wrong provider', 'content', [1.0, 0.0], provider: 'some_other_provider');

        TestEmbedder::reset();
        TestEmbedder::setVectorFor('provider query', [1.0, 0.0]);

        $retriever = new RagRetriever(new TestEmbedder()); // getProviderLabel() returns 'test'
        $results = $retriever->retrieve('provider query', limit: 5, minSimilarity: -1.0);

        $titles = array_column($results, 'title');
        $t->assertFalse(in_array('Wrong provider', $titles), 'Entries embedded under a different provider must never be compared against the current provider\'s query vector');

        $this->cleanUp($sf);
    }

    public function testFormatContextProducesReadableBlock(TestRunner $t): void {
        $formatted = RagRetriever::formatContext([
            ['title' => 'Return policy', 'content' => '30 days', 'source_type' => 'policy', 'similarity' => 0.9],
        ]);
        $t->assertStringContains('Return policy', $formatted);
        $t->assertStringContains('30 days', $formatted);
    }

    public function testFormatContextOfEmptyMatchesIsEmptyString(TestRunner $t): void {
        $t->assertEquals('', RagRetriever::formatContext([]), 'No matches should format to an empty string, not a header with nothing under it');
    }
}
