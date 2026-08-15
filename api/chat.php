<?php
header('Content-Type: application/json');

// Best-effort attempt to avoid PHP's own execution time limit (commonly
// 30 seconds on shared hosting) killing a request mid-flight before it
// ever reaches an echo. A single message can now involve two sequential
// network calls — RAG's embedding lookup, then the AI completion itself —
// and their combined time can approach or exceed that default even
// though each individual call has its own, shorter timeout. Not
// guaranteed: some hosts override this at the php.ini or web server
// level regardless of what the script requests, and there may be a
// SEPARATE timeout enforced by the web server or a reverse proxy in
// front of PHP that this can't touch at all. Worth attempting anyway.
@set_time_limit(45);

/**
 * config.php stays in this folder, but is blocked from direct browser access
 * by api/.htaccess (see that file). PHP's own require_once can still read it
 * fine, since .htaccess only blocks HTTP requests, not server-side file reads.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ProviderFactory.php';
require_once __DIR__ . '/db/ChatHistoryStore.php';
require_once __DIR__ . '/db/RateLimiter.php';
require_once __DIR__ . '/providers/ProviderException.php';

// Rate limiting — checked BEFORE anything else runs, including before the
// database connection for chat history. This matters most if this ever
// runs as a public demo: without it, every message costs a real API call
// with zero throttling, an open door to a very large bill from one bad actor.
//
// Two independent limits:
//  - Per-IP: catches abuse even from someone clearing cookies to get a
//    fresh visitor token each time
//  - Per-visitor (cookie): friendlier granularity for normal usage
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitIpPerMinute = (int)(getenv('RATE_LIMIT_IP_PER_MINUTE') ?: 20);
$rateLimitIpPerDay = (int)(getenv('RATE_LIMIT_IP_PER_DAY') ?: 300);
$rateLimitVisitorPerMinute = (int)(getenv('RATE_LIMIT_VISITOR_PER_MINUTE') ?: 15);

try {
    $limiter = new RateLimiter();
    $limiter->checkAndRecord($clientIp, 'ip', maxRequests: $rateLimitIpPerMinute, windowSeconds: 60);
    $limiter->checkAndRecord($clientIp, 'ip_daily', maxRequests: $rateLimitIpPerDay, windowSeconds: 86400);
} catch (RateLimitExceededException $e) {
    http_response_code(429);
    echo json_encode([
        'reply' => "You're sending messages faster than this demo allows. Please wait a bit and try again.",
        'error' => true,
    ]);
    exit;
} catch (Throwable $e) {
    // If the rate limiter itself can't reach the database, fail closed
    // (block the request) rather than silently skipping rate limiting.
    http_response_code(500);
    echo json_encode(['reply' => 'Could not verify request rate. Please try again shortly.', 'error' => true]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($body['message'] ?? '');
$providerName = $body['provider'] ?? (getenv('DEFAULT_AI_PROVIDER') ?: 'gemini');
$resetRequested = !empty($body['reset']);

// Input length cap — prevents someone from pasting a massive payload that
// gets encrypted, stored, and forwarded to a paid API with no validation.
$maxMessageLength = (int)(getenv('MAX_MESSAGE_LENGTH') ?: 2000);
if (strlen($userMessage) > $maxMessageLength) {
    echo json_encode([
        'reply' => 'That message is too long (max ' . $maxMessageLength . ' characters). Please shorten it and try again.',
        'error' => true,
    ]);
    exit;
}

try {
    // Identifies the visitor via a persistent cookie and connects to their
    // stored history in MySQL — survives closed browsers, idle time, and
    // return visits, unlike the old $_SESSION version. Auto-expires after
    // 30 days of inactivity via api/db/cleanup.php (run as a cron job).
    $store = new ChatHistoryStore();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'reply' => 'Could not connect to chat storage. Check your database credentials in config.php.',
        'error' => true,
        'details' => $e->getMessage(),
    ]);
    exit;
}

// Second rate limit, scoped to the visitor cookie rather than raw IP —
// runs after $store exists since it needs the resolved visitor token.
try {
    $limiter->checkAndRecord($store->getVisitorToken(), 'visitor', maxRequests: $rateLimitVisitorPerMinute, windowSeconds: 60);
} catch (RateLimitExceededException $e) {
    http_response_code(429);
    echo json_encode([
        'reply' => "You're sending messages faster than this demo allows. Please wait a bit and try again.",
        'error' => true,
    ]);
    exit;
}

if ($resetRequested) {
    $store->clearHistory();
    if (!$userMessage) {
        echo json_encode(['reply' => 'Conversation cleared.', 'reset' => true]);
        exit;
    }
}

if (!$userMessage) {
    echo json_encode(['reply' => 'Please enter a message.']);
    exit;
}

// Keep the history from growing unbounded — cap how many past turns get
// resent. This limits both token cost and request size as conversations
// get long. Adjust MAX_HISTORY_MESSAGES in config.php to change it.
$maxHistoryMessages = (int)(getenv('MAX_HISTORY_MESSAGES') ?: 20);

try {
    $store->addMessage('user', $userMessage);
    // excludeErrors=true here — the AI provider should never see past error
    // messages as if it said them, that would confuse subsequent replies.
    $history = $store->getHistory($maxHistoryMessages, excludeErrors: true);

    // RAG retrieval — augments only the OUTGOING copy of the current
    // message sent to the AI provider, never what's stored in the database.
    // The stored message (addMessage above) stays exactly what the visitor
    // typed; only this in-memory $history array gets the context prepended,
    // so history stays clean on reload and the AI still sees it fresh each
    // time context is relevant, not just once.
    //
    // Skipped entirely for very short replies ("yes", "ok", "sure") —
    // these are almost always a direct response to whatever the assistant
    // just said, not a new question needing fresh knowledge-base grounding.
    // Rewriting a plain "yes" into a big re-injected content block framed
    // as "Customer question: yes" turned out to actively confuse at least
    // one provider into re-answering the general topic instead of
    // recognizing it as a direct affirmative — the conversation history
    // already carries what's needed to interpret a short reply correctly,
    // re-injecting content on top of it was making things worse, not better.
    $ragMinQueryLength = (int)(getenv('RAG_MIN_QUERY_LENGTH') ?: 15);
    if (strlen(trim($userMessage)) >= $ragMinQueryLength) {
        require_once __DIR__ . '/rag/RagRetriever.php';
        $ragTopK = (int)(getenv('RAG_TOP_K') ?: 3);
        $ragMinSimilarity = (float)(getenv('RAG_MIN_SIMILARITY') ?: 0.5);
        $retriever = new RagRetriever();
        $matches = $retriever->retrieve($userMessage, $ragTopK, $ragMinSimilarity);
        if (!empty($matches) && !empty($history)) {
            $lastIndex = count($history) - 1;
            $history[$lastIndex]['content'] = RagRetriever::formatContext($matches) . "Customer question: " . $history[$lastIndex]['content'];
        }
    }

    $provider = ProviderFactory::make($providerName);
    require_once __DIR__ . '/Persona.php';

    // Always tell the model today's real date, regardless of whether
    // persona is enabled — this is a baseline accuracy fix, not a
    // personality feature. Without it, a model with a stale training
    // cutoff will confidently answer date questions with whatever it last
    // "remembers," which is simply wrong. Persona (if enabled) layers on
    // top of this, it doesn't replace it.
    $systemPromptParts = ['Today\'s date is ' . date('F j, Y') . '.'];

    // Same reasoning, same unconditional inclusion — the model doesn't
    // know what THIS chat interface can actually do, so left ungrounded
    // it invents plausible-sounding features (a paperclip icon, drag-and-
    // drop) that don't exist here, observed directly in testing.
    $capabilitiesNote = trim((string)getenv('CHAT_CAPABILITIES_NOTE'));
    if ($capabilitiesNote !== '') {
        $systemPromptParts[] = $capabilitiesNote;
    }

    $personaPrompt = Persona::buildSystemPrompt();
    if ($personaPrompt !== null) {
        $systemPromptParts[] = $personaPrompt;
    }
    $systemPrompt = implode("\n\n", $systemPromptParts);

    $result = $provider->generate($history, $systemPrompt);

    $store->addMessage(
        'assistant',
        $result['text'],
        $result['prompt_tokens'],
        $result['completion_tokens'],
        $result['total_tokens']
    );

    echo json_encode([
        'reply' => $result['text'],
        'provider' => $providerName,
        'turns' => count($history) + 1,
        'tokens' => $result['total_tokens'], // null if this provider didn't report usage
    ]);
} catch (ProviderException $e) {
    // Friendly, plain-English message shown by default. Raw technical
    // details (the actual API error payload) are sent separately so the
    // frontend can offer a "View details" toggle instead of dumping it
    // straight into the chat. Persisted under role 'error' so it displays
    // correctly on reload — but getHistory(excludeErrors: true) above keeps
    // it out of what actually gets sent back to the AI provider.
    $store->addMessage('error', $e->getMessage());
    echo json_encode([
        'reply' => $e->getMessage(),
        'provider' => $providerName,
        'error' => true,
        'details' => $e->rawDetails,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    $friendly = 'Something went wrong on our end.';
    $store->addMessage('error', $friendly);
    echo json_encode([
        'reply' => $friendly,
        'error' => true,
        'details' => $e->getMessage(),
    ]);
}
