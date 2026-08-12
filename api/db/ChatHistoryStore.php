<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MessageCipher.php';

class ChatHistoryStore {
    private const COOKIE_NAME = 'reunify_visitor';
    private const COOKIE_LIFETIME_DAYS = 30; // matches the 30-day retention policy below
    private const RETENTION_DAYS = 30;

    private PDO $db;
    private string $visitorToken;

    public function __construct() {
        $this->db = Database::connect();
        $this->visitorToken = $this->resolveVisitorToken();
        $this->touchVisitor();
    }

    public function getVisitorToken(): string {
        return $this->visitorToken;
    }

    /**
     * Reads the visitor's identifying cookie if present and well-formed,
     * otherwise mints a new random one. Cookie is httponly (JS can't read
     * or tamper with it) and rolling (refreshed on every visit, so active
     * visitors stay identified while truly inactive ones age out naturally).
     */
    private function resolveVisitorToken(): string {
        $existing = $_COOKIE[self::COOKIE_NAME] ?? '';

        if (preg_match('/^[a-f0-9]{64}$/', $existing)) {
            $token = $existing;
        } else {
            $token = bin2hex(random_bytes(32));
        }

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + (self::COOKIE_LIFETIME_DAYS * 86400),
            'path' => '/',
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']),
            'samesite' => 'Lax',
        ]);

        return $token;
    }

    private function touchVisitor(): void {
        $stmt = $this->db->prepare("
            INSERT INTO chat_visitors (visitor_token, created_at, last_active_at)
            VALUES (:token, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_active_at = NOW()
        ");
        $stmt->execute(['token' => $this->visitorToken]);
    }

    /**
     * Returns the visitor's history in chronological order, most recent
     * $limit messages. Pass excludeErrors=true when building context to
     * send to an AI provider — error turns are real, displayed history,
     * but shouldn't be fed back to the model as if it said them.
     */
    public function getHistory(int $limit = 20, bool $excludeErrors = false): array {
        $roleFilter = $excludeErrors ? "AND role != 'error'" : "";
        $stmt = $this->db->prepare("
            SELECT role, content, created_at FROM chat_messages
            WHERE visitor_token = :token {$roleFilter}
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':token', $this->visitorToken);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());

        foreach ($rows as &$row) {
            $row['content'] = MessageCipher::decrypt($row['content']);
        }
        return $rows;
    }

    public function addMessage(string $role, string $content, ?int $promptTokens = null, ?int $completionTokens = null, ?int $totalTokens = null): void {
        $stmt = $this->db->prepare("
            INSERT INTO chat_messages (visitor_token, role, content, prompt_tokens, completion_tokens, total_tokens, created_at)
            VALUES (:token, :role, :content, :prompt_tokens, :completion_tokens, :total_tokens, NOW())
        ");
        $stmt->execute([
            'token' => $this->visitorToken,
            'role' => $role,
            'content' => MessageCipher::encrypt($content),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ]);
    }

    public function clearHistory(): void {
        $stmt = $this->db->prepare("DELETE FROM chat_messages WHERE visitor_token = :token");
        $stmt->execute(['token' => $this->visitorToken]);
    }

    /**
     * Deletes visitors (and their messages, via ON DELETE CASCADE) that have
     * been inactive longer than the retention window. Call this from a
     * scheduled cron job (see api/db/cleanup.php), not on every request.
     */
    public static function purgeInactive(): int {
        $db = Database::connect();
        $stmt = $db->prepare("
            DELETE FROM chat_visitors
            WHERE last_active_at < (NOW() - INTERVAL :days DAY)
        ");
        $stmt->bindValue(':days', self::RETENTION_DAYS, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Total token usage per conversation (visitor), highest first — for
     * admin reporting. Only assistant-role rows carry token data, so this
     * naturally reflects real API usage, not user input length.
     */
    public static function getTokenUsageByConversation(int $limit = 50): array {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT
                visitor_token,
                COUNT(*) as assistant_messages,
                SUM(prompt_tokens) as total_prompt_tokens,
                SUM(completion_tokens) as total_completion_tokens,
                SUM(total_tokens) as total_tokens,
                MAX(created_at) as last_message_at
            FROM chat_messages
            WHERE role = 'assistant' AND total_tokens IS NOT NULL
            GROUP BY visitor_token
            ORDER BY total_tokens DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Overall token totals across every conversation — one quick number for "how much have we used total". */
    public static function getTotalTokenUsage(): array {
        $db = Database::connect();
        $row = $db->query("
            SELECT
                COALESCE(SUM(prompt_tokens), 0) as total_prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as total_completion_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COUNT(*) as assistant_messages_with_usage
            FROM chat_messages
            WHERE role = 'assistant' AND total_tokens IS NOT NULL
        ")->fetch();
        return $row;
    }
}
