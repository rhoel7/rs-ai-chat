-- Run this ONCE against your database via phpMyAdmin's SQL tab, if you
-- already ran the original api/rag/schema.sql before this fix existed.
ALTER TABLE rag_ingested_files
    ADD COLUMN embedding_provider VARCHAR(20) NOT NULL DEFAULT '' AFTER file_hash;
