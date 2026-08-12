<?php
require_once __DIR__ . '/../db/Database.php';
require_once __DIR__ . '/EmbedderFactory.php';

class RagIngestor {
    private PDO $db;
    private Embedder $embedder;

    public function __construct(?Embedder $embedder = null) {
        $this->db = Database::connect();
        $this->embedder = $embedder ?? EmbedderFactory::make();
    }

    /**
     * Scans $folder for .csv files and ingests each one. Returns a summary
     * report — never throws for per-row problems (a bad row is recorded as
     * an error and skipped, not a reason to abort the whole file).
     */
    public function ingestFolder(string $folder): array {
        // Best-effort attempt to avoid a large CSV (many rows, each needing
        // a network round-trip to the embedding API) getting cut off by
        // PHP's default execution time limit (often 30s on shared hosting).
        // Not guaranteed — some hosts override this at the php.ini or web
        // server level regardless of what the script requests — but worth
        // attempting rather than not. If ingestion does time out partway
        // through, re-running it is safe: already-embedded rows are
        // correctly skipped by the change detection, so a retry just picks
        // up where it left off rather than starting over or duplicating.
        @set_time_limit(0);

        $report = [
            'files_processed' => 0,
            'files_skipped_unchanged' => 0,
            'entries_new' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        $csvFiles = glob(rtrim($folder, '/') . '/*.csv');
        if ($csvFiles === false) {
            $report['errors'][] = "Could not read folder: {$folder}";
            return $report;
        }

        foreach ($csvFiles as $filePath) {
            $this->ingestFile($filePath, $report);
        }

        return $report;
    }

    private function ingestFile(string $filePath, array &$report): void {
        $filename = basename($filePath);
        $fileHash = hash_file('sha256', $filePath);
        $currentProvider = $this->embedder->getProviderLabel();

        // File-level short-circuit: skip entirely only if BOTH the file's
        // bytes AND the configured embedding provider match what was used
        // last time. Checking file_hash alone would miss the case where
        // someone switches RAG_EMBEDDING_PROVIDER with an unchanged file —
        // caught this exact gap during testing, not just reasoned about it.
        $stmt = $this->db->prepare("SELECT file_hash, embedding_provider FROM rag_ingested_files WHERE filename = :filename");
        $stmt->execute(['filename' => $filename]);
        $existingFile = $stmt->fetch();

        if ($existingFile && $existingFile['file_hash'] === $fileHash && $existingFile['embedding_provider'] === $currentProvider) {
            $report['files_skipped_unchanged']++;
            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $report['errors'][] = "Could not open {$filename}";
            return;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            $report['errors'][] = "{$filename}: empty file or unreadable header row";
            fclose($handle);
            return;
        }
        $header = array_map(fn($h) => strtolower(trim($h)), $header);
        $titleCol = array_search('title', $header);
        $contentCol = array_search('content', $header);
        $sourceTypeCol = array_search('source_type', $header); // optional column

        if ($titleCol === false || $contentCol === false) {
            $report['errors'][] = "{$filename}: missing required 'title' and/or 'content' column in header row";
            fclose($handle);
            return;
        }

        $rowNumber = 1;
        $rowCount = 0;
        $entriesNewBefore = $report['entries_new'];
        $entriesUpdatedBefore = $report['entries_updated'];
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $title = trim($row[$titleCol] ?? '');
            $content = trim($row[$contentCol] ?? '');
            $sourceType = $sourceTypeCol !== false ? trim($row[$sourceTypeCol] ?? '') : null;
            if ($sourceType === '') {
                $sourceType = null;
            }

            if ($title === '' || $content === '') {
                $report['errors'][] = "{$filename} row {$rowNumber}: skipped — empty title or content";
                continue;
            }

            try {
                $this->ingestRow($filename, $title, $content, $sourceType, $report);
                $rowCount++;
            } catch (Throwable $e) {
                $report['errors'][] = "{$filename} row {$rowNumber} (\"{$title}\"): " . $e->getMessage();
            }
        }
        fclose($handle);

        $embeddingCallsThisFile = $report['entries_new'] - $entriesNewBefore + $report['entries_updated'] - $entriesUpdatedBefore;
        if ($embeddingCallsThisFile > 200) {
            $report['warnings'][] = "{$filename}: {$embeddingCallsThisFile} rows needed embedding in this run. Large files risk hitting your host's PHP execution time limit mid-run — if this file times out, re-running ingestion is safe (already-embedded rows are skipped), but consider splitting very large CSVs into smaller files if this becomes a recurring problem.";
        }

        $upsert = $this->db->prepare("
            INSERT INTO rag_ingested_files (filename, file_hash, embedding_provider, row_count, last_ingested_at)
            VALUES (:filename, :file_hash, :provider, :row_count, NOW())
            ON DUPLICATE KEY UPDATE file_hash = :file_hash2, embedding_provider = :provider2, row_count = :row_count2, last_ingested_at = NOW()
        ");
        $upsert->execute([
            'filename' => $filename, 'file_hash' => $fileHash, 'provider' => $currentProvider, 'row_count' => $rowCount,
            'file_hash2' => $fileHash, 'provider2' => $currentProvider, 'row_count2' => $rowCount,
        ]);
        $report['files_processed']++;
    }

    private function ingestRow(string $sourceFile, string $title, string $content, ?string $sourceType, array &$report): void {
        $contentHash = hash('sha256', $content);

        $stmt = $this->db->prepare("
            SELECT id, content_hash, embedding_provider FROM rag_entries WHERE source_file = :source_file AND title = :title
        ");
        $stmt->execute(['source_file' => $sourceFile, 'title' => $title]);
        $existing = $stmt->fetch();

        $currentProvider = $this->embedder->getProviderLabel();
        if ($existing && $existing['content_hash'] === $contentHash && $existing['embedding_provider'] === $currentProvider) {
            $report['entries_unchanged']++;
            return; // no-op — content AND embedding provider both match what's already stored
        }

        // Embed the title + content together — better retrieval quality than
        // content alone, since a customer's phrasing tends to resemble how
        // a question/title is worded more than how the body text reads.
        $embedding = $this->embedder->embed("{$title}\n\n{$content}");
        $embeddingJson = json_encode($embedding);
        $providerLabel = $this->embedder->getProviderLabel();

        if ($existing) {
            $update = $this->db->prepare("
                UPDATE rag_entries
                SET content = :content, content_hash = :content_hash, source_type = :source_type,
                    embedding = :embedding, embedding_provider = :provider
                WHERE id = :id
            ");
            $update->execute([
                'content' => $content, 'content_hash' => $contentHash, 'source_type' => $sourceType,
                'embedding' => $embeddingJson, 'provider' => $providerLabel, 'id' => $existing['id'],
            ]);
            $report['entries_updated']++;
        } else {
            $insert = $this->db->prepare("
                INSERT INTO rag_entries (source_file, title, content, content_hash, source_type, embedding, embedding_provider, created_at, updated_at)
                VALUES (:source_file, :title, :content, :content_hash, :source_type, :embedding, :provider, NOW(), NOW())
            ");
            $insert->execute([
                'source_file' => $sourceFile, 'title' => $title, 'content' => $content,
                'content_hash' => $contentHash, 'source_type' => $sourceType,
                'embedding' => $embeddingJson, 'provider' => $providerLabel,
            ]);
            $report['entries_new']++;
        }
    }
}
