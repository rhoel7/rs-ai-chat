<?php
/**
 * Reunify AI Chat — the widget itself, as a single includable component.
 *
 * Drop this into any PHP page with one line:
 *   <?php include '/path/to/reunify-ai-chat/widget.php'; ?>
 *
 * It's fully self-contained: loads its own config, connects to its own
 * database for history preload, and emits its own CSS/JS — the including
 * page doesn't need to set up any PHP variables or add any other tags
 * first. Just the one include, placed anywhere in the page's <body>.
 *
 * If this file lives in the SAME folder as chat.js/widget.css/api/ (the
 * default), no further setup is needed. If you're including it from a
 * PHP page that lives somewhere ELSE on your site, set WIDGET_BASE_URL in
 * config.php first — see the comment there.
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db/ChatHistoryStore.php';

$priorMessages = [];
try {
    $store = new ChatHistoryStore();
    $priorMessages = $store->getHistory();
} catch (Throwable $e) {
    $priorMessages = [];
}

$chatTitle = getenv('CHAT_TITLE') ?: 'AI Chat';

// Empty by default — resolves chat.js/widget.css/api/chat.php relative to
// wherever this page's URL is, which only works correctly if this file
// lives in the same folder as those assets. Set WIDGET_BASE_URL in
// config.php (e.g. '/wp-content/plugins/reunify-ai-chat') if you're
// including this from a page that lives elsewhere on your site.
$widgetBase = rtrim(getenv('WIDGET_BASE_URL') ?: '', '/');
$assetPrefix = $widgetBase !== '' ? $widgetBase . '/' : '';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo htmlspecialchars($assetPrefix); ?>widget.css?v=<?php echo filemtime(__DIR__ . '/widget.css'); ?>" rel="stylesheet">

<button id="chatBubble" aria-label="Open chat">
  <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 16 16">
    <path d="M2.678 11.894a1 1 0 0 1 .287.801 10.97 10.97 0 0 1-.398 2c1.395-.323 2.247-.697 2.634-.893a1 1 0 0 1 .71-.074A8.06 8.06 0 0 0 8 14c3.996 0 7-2.807 7-6s-3.004-6-7-6-7 2.808-7 6c0 1.468.617 2.83 1.678 3.894zm-.493 3.905a21.7 21.7 0 0 1-.713.129c-.2.032-.352-.176-.273-.362a9.68 9.68 0 0 0 .244-.637l.003-.01c.248-.72.45-1.548.524-2.319C.743 11.37 0 9.76 0 8c0-3.866 3.582-7 8-7s8 3.134 8 7-3.582 7-8 7a9.06 9.06 0 0 1-2.347-.306c-.52.263-1.639.742-3.468 1.105z"/>
  </svg>
</button>

<?php $chatHintText = getenv('CHAT_HINT_TEXT'); ?>
<?php if ($chatHintText !== '' && $chatHintText !== false): ?>
<div id="chatHint" class="chat-hint">
  <span><?php echo htmlspecialchars($chatHintText); ?></span>
  <button id="chatHintDismiss" aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<div id="chatPanel" class="collapsed" role="dialog" aria-modal="false" aria-label="<?php echo htmlspecialchars($chatTitle); ?> chat window">
  <button id="closeChatBtn" aria-label="Close chat">&times;</button>
  <div class="card-header d-flex justify-content-between align-items-center chat-panel-header">
    <span><?php echo htmlspecialchars($chatTitle); ?></span>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <select id="providerSelect" class="form-select form-select-sm" style="width: auto;">
<?php
// The dropdown's default now genuinely reflects DEFAULT_AI_PROVIDER from
// config.php instead of being hardcoded to a fixed option — previously
// this was hardcoded to "groq" regardless of what DEFAULT_AI_PROVIDER
// was set to, so changing the config silently had no effect on what the
// UI actually showed as selected.
$defaultProvider = getenv('DEFAULT_AI_PROVIDER') ?: 'groq';
$providerOptions = [
    'groq' => 'Groq (free)',
    'mistral' => 'Mistral (free)',
    'gemini' => 'Gemini (free)',
    'openai' => 'OpenAI (ChatGPT)',
    'claude' => 'Claude',
    'deepseek' => 'DeepSeek',
    'azure' => 'Azure (Copilot)',
];
foreach ($providerOptions as $value => $label):
    $isSelected = $value === $defaultProvider ? ' selected' : '';
?>
        <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $isSelected; ?>><?php echo htmlspecialchars($label); ?></option>
<?php endforeach; ?>
      </select>
      <button id="newChatBtn" class="btn btn-sm btn-outline-secondary" title="Clear conversation memory">New Chat</button>
    </div>
  </div>
  <div id="chatWindow" class="card-body" aria-live="polite" aria-relevant="additions">
<?php $agentName = getenv('AGENT_NAME') ?: 'Assistant'; ?>
<?php foreach ($priorMessages as $msg):
    $rowClass = $msg['role'] === 'user' ? 'text-end mb-2' : 'text-start mb-2';
    $bubbleClass = match ($msg['role']) {
        'user' => 'chat-bubble user',
        'error' => 'chat-bubble ai chat-bubble-error',
        default => 'chat-bubble ai',
    };
    $timestampLabel = match ($msg['role']) {
        'user' => 'Sent',
        'error' => 'Error',
        default => $agentName,
    };
    // MySQL returns "YYYY-MM-DD HH:MM:SS" with no timezone marker — since
    // the connection forces the session to UTC (see Database.php), that
    // string genuinely IS UTC, just needs an explicit marker so the
    // browser's Date parser doesn't guess wrong. Without the trailing
    // "Z", JS would assume local time and convert incorrectly.
    $utcIso = str_replace(' ', 'T', $msg['created_at']) . 'Z';
?>
    <div class="<?php echo $rowClass; ?>">
      <div class="<?php echo $bubbleClass; ?>"><?php echo htmlspecialchars($msg['content'], ENT_QUOTES); ?></div>
      <div class="msg-timestamp"><?php echo htmlspecialchars($timestampLabel); ?> · <span class="ts-time" data-utc="<?php echo htmlspecialchars($utcIso); ?>"></span></div>
    </div>
<?php endforeach; ?>
  </div>
  <div class="card-footer d-flex gap-2 chat-panel-footer">
    <input id="userInput" class="form-control" placeholder="Ask something...">
    <button id="sendBtn" class="btn btn-primary">Send</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  window.REUNIFY_CHAT_API_BASE = <?php echo json_encode($assetPrefix); ?>;
  window.REUNIFY_AGENT_NAME = <?php echo json_encode($agentName); ?>;
</script>
<script src="<?php echo htmlspecialchars($assetPrefix); ?>chat.js?v=<?php echo filemtime(__DIR__ . '/chat.js'); ?>"></script>
