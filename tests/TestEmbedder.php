<?php
require_once __DIR__ . '/../api/rag/Embedder.php';

/**
 * A test-only embedder — deterministic and controllable, so tests can
 * assert on exact behavior instead of dealing with real network calls or
 * genuine semantic embeddings. Two modes:
 *  - Same text always produces the same vector (for change-detection tests)
 *  - Vectors can be forced via setVectorFor() to test similarity ranking
 *    without depending on real semantic understanding.
 */
class TestEmbedder implements Embedder {
    private static array $forcedVectors = [];
    public static int $callCount = 0;

    public static function setVectorFor(string $text, array $vector): void {
        self::$forcedVectors[$text] = $vector;
    }

    public static function reset(): void {
        self::$forcedVectors = [];
        self::$callCount = 0;
    }

    public function embed(string $text): array {
        self::$callCount++;
        if (isset(self::$forcedVectors[$text])) {
            return self::$forcedVectors[$text];
        }
        // deterministic fallback — same text always gives the same vector
        $hash = crc32($text);
        return [($hash % 1000) / 1000, (($hash >> 8) % 1000) / 1000, (($hash >> 16) % 1000) / 1000];
    }

    public function getProviderLabel(): string {
        return 'test';
    }
}
