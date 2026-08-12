-- Run this once in phpMyAdmin (or via SSH/mysql CLI) against a database
-- you create in Hostinger's hPanel. A dedicated small database, separate
-- from your WordPress database, is recommended to keep this decoupled.

CREATE TABLE IF NOT EXISTS chat_visitors (
    visitor_token   CHAR(64) PRIMARY KEY,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_active_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_last_active (last_active_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_token       CHAR(64) NOT NULL,
    role                ENUM('user','assistant','error') NOT NULL,
    content             TEXT NOT NULL,
    prompt_tokens       INT UNSIGNED NULL,     -- only populated on assistant rows — NULL for user/error rows
    completion_tokens   INT UNSIGNED NULL,
    total_tokens        INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visitor_time (visitor_token, created_at),
    FOREIGN KEY (visitor_token) REFERENCES chat_visitors(visitor_token) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
