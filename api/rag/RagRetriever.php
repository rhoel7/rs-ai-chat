<?php
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/EmbedderFactory.php';

class RagRetriever {
    private PDO $db;
    private Embedder $embedder;

    public function __construct(?Embedder $embedder = null) {
        $this->db = Database::connect();
        $this->embedder = $embedder ?? EmbedderFactory::make();
    }

    /**
     * Returns the top-matching knowledge base entries for $queryText, best
     * match first. Empty array if there's no knowledge base yet, nothing
     * clears the similarity threshold, or the embedding call itself fails —
     * callers should treat an empty result as "answer without RAG context",
     * not as an error. A RAG lookup failing shouldn't take down basic chat.
     */
    public function retrieve(string $queryText, int $limit = 3, float $minSimilarity = 0.5): array {
        try {
            $currentProvider = $this->embedder->getProviderLabel();

            // Only entries embedded under the CURRENTLY configured provider
            // are comparable — a Gemini vector and a Mistral vector don't
            // live in the same space, comparing them would be meaningless.
            $stmt = $this->db->prepare("
                SELECT title, content, source_type, embedding
                FROM rag_entries
                WHERE embedding_provider = :provider
            ");
            $stmt->execute(['provider' => $currentProvider]);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return []; // no knowledge base yet (or nothing under this provider) — nothing to retrieve
            }

            $queryEmbedding = $this->embedder->embed($queryText);

            $scored = [];
            foreach ($rows as $row) {
                $entryEmbedding = json_decode($row['embedding'], true);
                if (!is_array($entryEmbedding)) {
                    continue; // skip a malformed row rather than fail the whole lookup
                }
                $similarity = self::cosineSimilarity($queryEmbedding, $entryEmbedding);
                if ($similarity >= $minSimilarity) {
                    $scored[] = [
                        'title' => $row['title'],
                        'content' => $row['content'],
                        'source_type' => $row['source_type'],
                        'similarity' => $similarity,
                    ];
                }
            }

            usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            return array_slice($scored, 0, $limit);
        } catch (Throwable $e) {
            // Graceful degradation, deliberately: a RAG lookup failure
            // (embedding API hiccup, DB blip) should never block a chat
            // reply outright — worst case, the answer just isn't grounded
            // in the knowledge base this one time. But "graceful" isn't
            // the same as "silent" — log it, so a systematically broken
            // embedding provider is discoverable instead of just quietly
            // degrading chat quality with nobody noticing why.
            error_log('[RAG] Retrieval failed for query, falling back to no context: ' . $e->getMessage());
            return [];
        }
    }

    public static function cosineSimilarity(array $a, array $b): float {
        $dot = 0.0; $normA = 0.0; $normB = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        $denominator = sqrt($normA) * sqrt($normB);
        return $denominator > 0 ? $dot / $denominator : 0.0;
    }

    /** Formats retrieved entries into a context block to prepend to the outgoing message. */
    public static function formatContext(array $matches): string {
        if (empty($matches)) {
            return '';
        }
        $blocks = array_map(fn($m) => "{$m['title']}: {$m['content']}", $matches);
        return "Relevant business information:\n" . implode("\n\n", $blocks) . "\n\n";
    }
}
