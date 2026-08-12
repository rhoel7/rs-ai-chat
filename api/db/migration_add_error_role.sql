-- Run this ONCE against your existing database via phpMyAdmin's SQL tab.
-- Only needed if you already ran the original schema.sql before error
-- persistence was added — adds 'error' as a valid role value.
-- Safe to run even if you're not sure; it's a no-op if already applied.

ALTER TABLE chat_messages
    MODIFY COLUMN role ENUM('user','assistant','error') NOT NULL;
