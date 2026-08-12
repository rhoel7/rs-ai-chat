-- Run this once against your database via phpMyAdmin's SQL tab.

CREATE TABLE IF NOT EXISTS rag_entries (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_file         VARCHAR(255) NOT NULL,   -- which CSV this row came from
    title               VARCHAR(500) NOT NULL,   -- natural identity within a file — no separate ID column needed
    content             TEXT NOT NULL,
    content_hash        CHAR(64) NOT NULL,       -- SHA-256 of content — lets re-ingestion detect real changes vs no-ops
    source_type         VARCHAR(50) NULL,
    embedding           LONGTEXT NOT NULL,       -- JSON-encoded array of floats
    embedding_provider  VARCHAR(20) NOT NULL,    -- vector spaces aren't compatible across providers — checked before use
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_file_title (source_file, title),
    INDEX idx_source_type (source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracks whole-file state so an unchanged CSV can be skipped entirely
-- without even being parsed, not just row-by-row.
CREATE TABLE IF NOT EXISTS rag_ingested_files (
    filename            VARCHAR(255) PRIMARY KEY,
    file_hash           CHAR(64) NOT NULL,   -- SHA-256 of the whole file's bytes
    embedding_provider  VARCHAR(20) NOT NULL, -- provider active when this file was last fully ingested — a config switch invalidates the file-level skip even if bytes are unchanged
    row_count           INT UNSIGNED NOT NULL DEFAULT 0,
    last_ingested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
