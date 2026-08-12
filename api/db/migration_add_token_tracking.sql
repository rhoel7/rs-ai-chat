-- Run this ONCE against your existing database via phpMyAdmin's SQL tab,
-- if you already ran the original schema.sql before token tracking existed.
-- Safe to run even if unsure — ALTER TABLE ADD COLUMN fails harmlessly
-- (with an error you can ignore) if the columns already exist.

ALTER TABLE chat_messages
    ADD COLUMN prompt_tokens INT UNSIGNED NULL AFTER content,
    ADD COLUMN completion_tokens INT UNSIGNED NULL AFTER prompt_tokens,
    ADD COLUMN total_tokens INT UNSIGNED NULL AFTER completion_tokens;
