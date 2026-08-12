<?php
/**
 * Single, properly authenticated admin endpoint — replaces the earlier
 * pattern of standalone scripts (generate_key.php, migrate_encrypt_existing.php)
 * that relied on "remember to delete this file when you're done." That
 * pattern works, but it's an honor system, not real access control, and
 * depends on Apache .htaccess behavior that varies across hosts (we hit
 * exactly that inconsistency earlier with LiteSpeed vs stock Apache).
 *
 * This endpoint uses a real secret token (ADMIN_TOKEN in config.php),
 * checked with a timing-safe comparison, as the actual access control —
 * .htaccess is what makes this file reachable at all, the token is what
 * actually protects it, and that protection doesn't depend on
 * remembering to delete anything afterward.
 *
 * Auth is HEADER-ONLY, deliberately — an earlier version also accepted
 * ?token=... as a query parameter, which triggered Chrome's real-time
 * Safe Browsing heuristics: "a URL-supplied token that grants admin
 * access in one HTTP request" is a well-documented WordPress backdoor
 * malware signature, and this endpoint's shape matched it closely enough
 * to get flagged even though nothing was actually compromised. Header-only
 * auth also means the token never lands in server access logs or browser
 * history, which is better practice regardless of the Chrome issue.
 *
 * Usage (requires curl/Postman/similar — a plain browser address bar
 * visit can't set custom headers):
 *   curl -H "X-Admin-Token: YOUR_TOKEN" "https://yoursite.com/.../api/admin.php?action=stats"
 */
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$providedToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
$realToken = getenv('ADMIN_TOKEN');

if (!$realToken || $realToken === 'GENERATE_YOUR_OWN_TOKEN_SEE_README') {
    http_response_code(503);
    echo json_encode(['error' => 'ADMIN_TOKEN is not configured in config.php yet.']);
    exit;
}

// hash_equals is timing-safe — a plain === comparison leaks how many
// leading characters matched via response-time differences, which is a
// real (if narrow) attack vector against secret comparisons.
if (!hash_equals($realToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing admin token. Pass it as an X-Admin-Token header, not a URL parameter.']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'generate_key':
            echo json_encode([
                'key' => base64_encode(random_bytes(32)),
                'note' => 'Copy this into CHAT_ENCRYPTION_KEY in config.php.',
            ]);
            break;

        case 'migrate_encrypt':
            require_once __DIR__ . '/db/Database.php';
            require_once __DIR__ . '/db/MessageCipher.php';

            if (!getenv('CHAT_ENCRYPTION_KEY') || getenv('CHAT_ENCRYPTION_KEY') === 'GENERATE_YOUR_OWN_KEY_SEE_README') {
                throw new RuntimeException("CHAT_ENCRYPTION_KEY isn't set yet — set it up first (action=generate_key), then run this.");
            }

            $db = Database::connect();
            $rows = $db->query("SELECT id, content FROM chat_messages")->fetchAll();
            $migrated = 0;
            $alreadyDone = 0;
            $update = $db->prepare("UPDATE chat_messages SET content = :content WHERE id = :id");

            foreach ($rows as $row) {
                if (MessageCipher::isValidCiphertext($row['content'])) {
                    $alreadyDone++;
                    continue;
                }
                $update->execute(['content' => MessageCipher::encrypt($row['content']), 'id' => $row['id']]);
                $migrated++;
            }

            echo json_encode([
                'total_rows_checked' => count($rows),
                'already_encrypted' => $alreadyDone,
                'newly_encrypted' => $migrated,
            ]);
            break;

        case 'purge_inactive':
            require_once __DIR__ . '/db/ChatHistoryStore.php';
            require_once __DIR__ . '/db/RateLimiter.php';
            echo json_encode([
                'purged_visitors' => ChatHistoryStore::purgeInactive(),
                'purged_rate_limit_entries' => RateLimiter::purgeOldEntries(),
            ]);
            break;

        case 'stats':
            require_once __DIR__ . '/db/Database.php';
            require_once __DIR__ . '/db/ChatHistoryStore.php';
            $db = Database::connect();
            $tokenTotals = ChatHistoryStore::getTotalTokenUsage();
            echo json_encode([
                'visitors' => (int)$db->query("SELECT COUNT(*) FROM chat_visitors")->fetchColumn(),
                'messages' => (int)$db->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn(),
                'rate_limit_log_entries' => (int)$db->query("SELECT COUNT(*) FROM rate_limit_log")->fetchColumn(),
                'total_tokens_used' => (int)$tokenTotals['total_tokens'],
            ]);
            break;

        case 'token_usage':
            require_once __DIR__ . '/db/ChatHistoryStore.php';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            echo json_encode([
                'overall' => array_map('intval', ChatHistoryStore::getTotalTokenUsage()),
                'by_conversation' => array_map(function ($row) {
                    $row['assistant_messages'] = (int)$row['assistant_messages'];
                    $row['total_prompt_tokens'] = (int)$row['total_prompt_tokens'];
                    $row['total_completion_tokens'] = (int)$row['total_completion_tokens'];
                    $row['total_tokens'] = (int)$row['total_tokens'];
                    return $row;
                }, ChatHistoryStore::getTokenUsageByConversation($limit)),
            ]);
            break;

        case 'rag_list':
            require_once __DIR__ . '/db/Database.php';
            $db = Database::connect();
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $stmt = $db->prepare("
                SELECT id, source_file, title, source_type, embedding_provider, updated_at
                FROM rag_entries ORDER BY updated_at DESC LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode(['entries' => $stmt->fetchAll()]);
            break;

        case 'rag_delete':
            require_once __DIR__ . '/db/Database.php';
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'rag_delete requires a numeric ?id= parameter. Use action=rag_list to find one.']);
                break;
            }
            $db = Database::connect();
            $stmt = $db->prepare("DELETE FROM rag_entries WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode([
                'deleted' => $stmt->rowCount() > 0,
                'note' => $stmt->rowCount() > 0
                    ? 'Entry deleted. If it still exists in a CSV file, it will be re-inserted next time that file is ingested — delete it from the CSV too if you want it gone for good.'
                    : "No entry found with id={$id}.",
            ]);
            break;

        case 'rag_ingest':
            require_once __DIR__ . '/rag/RagIngestor.php';
            $ingestor = new RagIngestor();
            $report = $ingestor->ingestFolder(__DIR__ . '/rag/incoming');
            echo json_encode($report);
            break;

        case 'rag_stats':
            require_once __DIR__ . '/db/Database.php';
            $db = Database::connect();
            echo json_encode([
                'total_entries' => (int)$db->query("SELECT COUNT(*) FROM rag_entries")->fetchColumn(),
                'by_source_file' => $db->query("
                    SELECT source_file, COUNT(*) as entries, MAX(updated_at) as last_updated
                    FROM rag_entries GROUP BY source_file ORDER BY source_file
                ")->fetchAll(),
                'by_source_type' => $db->query("
                    SELECT COALESCE(source_type, '(none)') as source_type, COUNT(*) as entries
                    FROM rag_entries GROUP BY source_type ORDER BY entries DESC
                ")->fetchAll(),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Unknown or missing action.',
                'valid_actions' => ['generate_key', 'migrate_encrypt', 'purge_inactive', 'stats', 'token_usage', 'rag_ingest', 'rag_stats', 'rag_list', 'rag_delete'],
            ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
