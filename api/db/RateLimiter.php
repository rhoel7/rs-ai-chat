<?php
require_once __DIR__ . '/Database.php';

class RateLimitExceededException extends Exception {}

class RateLimiter {
    private PDO $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    /**
     * Throws RateLimitExceededException if $identifier has made $maxRequests
     * or more requests within the last $windowSeconds. Otherwise records
     * this request and allows it through.
     */
    public function checkAndRecord(string $identifier, string $scope, int $maxRequests, int $windowSeconds): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM rate_limit_log
            WHERE identifier = :identifier AND scope = :scope
              AND created_at > (NOW() - INTERVAL :window SECOND)
        ");
        $stmt->bindValue(':identifier', $identifier);
        $stmt->bindValue(':scope', $scope);
        $stmt->bindValue(':window', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        $count = (int)$stmt->fetchColumn();

        if ($count >= $maxRequests) {
            throw new RateLimitExceededException(
                "Rate limit exceeded for {$scope}: {$count}/{$maxRequests} requests in {$windowSeconds}s"
            );
        }

        $insert = $this->db->prepare("INSERT INTO rate_limit_log (identifier, scope, created_at) VALUES (:identifier, :scope, NOW())");
        $insert->execute(['identifier' => $identifier, 'scope' => $scope]);
    }

    /** Deletes log entries older than 24 hours — call from the daily cron alongside chat history cleanup. */
    public static function purgeOldEntries(): int {
        $db = Database::connect();
        $stmt = $db->query("DELETE FROM rate_limit_log WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        return $stmt->rowCount();
    }
}
