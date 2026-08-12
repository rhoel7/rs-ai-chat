CREATE TABLE IF NOT EXISTS rate_limit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier  VARCHAR(64) NOT NULL,   -- IP address or visitor_token
    scope       VARCHAR(20) NOT NULL,   -- 'ip' or 'visitor' — tracked separately
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, scope, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
