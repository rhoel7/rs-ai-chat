document.getElementById('sendBtn').addEventListener('click', sendMessage);
document.getElementById('userInput').addEventListener('keypress', e => {
  if (e.key === 'Enter') sendMessage();
});
document.getElementById('newChatBtn').addEventListener('click', startNewChat);

// Set by widget.php based on WIDGET_BASE_URL in config.php — empty by
// default (same-folder setup), or a path prefix when this widget is
// embedded on a page that lives elsewhere on the site. Falls back to ''
// if this script is ever loaded without that global defined (e.g. loaded
// directly rather than via widget.php).
const API_BASE = window.REUNIFY_CHAT_API_BASE || '';
const AGENT_NAME = window.REUNIFY_AGENT_NAME || 'Assistant';

// Floating widget open/close — the panel starts collapsed (see the
// "collapsed" class in index.php's CSS) regardless of whether there's
// preloaded history, matching how every real chat widget behaves: the
// visitor chooses to open it, it doesn't force itself open on load.
const chatBubble = document.getElementById('chatBubble');
const chatPanel = document.getElementById('chatPanel');

function openPanel() {
  chatPanel.classList.remove('collapsed');
  chatBubble.classList.add('hidden');
  dismissChatHint();
  document.getElementById('userInput').focus();
}

/**
 * The hint only shows once per browser — first-time visitors often miss a
 * small floating button, but someone who's already opened the chat once
 * doesn't need to keep seeing an animated arrow pointing at it.
 */
function dismissChatHint() {
  const hint = document.getElementById('chatHint');
  if (hint) hint.classList.remove('visible');
  chatBubble.classList.remove('hinting');
  try {
    localStorage.setItem('reunify_chat_hint_dismissed', 'true');
  } catch (e) {
    // localStorage can be unavailable (private browsing, disabled storage) —
    // not critical, the hint just won't remember its dismissal next visit.
  }
}

(function initChatHint() {
  const hint = document.getElementById('chatHint');
  if (!hint) return; // CHAT_HINT_TEXT is empty — hint disabled entirely, nothing to do

  let alreadyDismissed = false;
  try {
    alreadyDismissed = localStorage.getItem('reunify_chat_hint_dismissed') === 'true';
  } catch (e) {
    // localStorage unavailable — default to showing the hint rather than assuming it was dismissed
  }
  if (alreadyDismissed) return;

  setTimeout(() => {
    if (!chatPanel.classList.contains('collapsed')) return; // already opened in the meantime
    hint.classList.add('visible');
    chatBubble.classList.add('hinting');
  }, 1800);

  const dismissBtn = document.getElementById('chatHintDismiss');
  if (dismissBtn) {
    dismissBtn.addEventListener('click', (e) => {
      e.stopPropagation(); // don't also trigger openPanel via a parent click handler
      dismissChatHint();
    });
  }
})();

function closePanel() {
  chatPanel.classList.add('collapsed');
  chatBubble.classList.remove('hidden');
  // Return focus to the bubble — matters most for keyboard users, who'd
  // otherwise lose their place entirely once the panel they were focused
  // inside of disappears.
  chatBubble.focus();
}

chatBubble.addEventListener('click', openPanel);
document.getElementById('closeChatBtn').addEventListener('click', closePanel);

// Escape closes the panel — standard behavior for any dialog-like UI,
// and the only way a keyboard-only user could otherwise dismiss it
// without tabbing all the way to the close button.
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !chatPanel.classList.contains('collapsed')) {
    closePanel();
  }
});

// Event delegation for "View details" toggles on error bubbles — these get
// added dynamically, so the listener has to live on a permanent ancestor.
document.getElementById('chatWindow').addEventListener('click', (e) => {
  if (e.target.classList.contains('error-toggle')) {
    e.preventDefault();
    const details = document.getElementById(e.target.dataset.target);
    const isHidden = details.style.display === 'none';
    details.style.display = isHidden ? 'block' : 'none';
    e.target.textContent = isHidden ? 'Hide details' : 'View details';
  }
});

// If prior messages were preloaded server-side, jump to the most recent one
// rather than showing the top of a long history. Runs after the full page
// (including CSS/fonts/images) has finished loading, not just when this
// script executes, so late-loading resources can't throw off the scroll
// position by shifting the layout after the fact.
function scrollChatToBottom() {
  const el = document.getElementById('chatWindow');
  el.scrollTop = el.scrollHeight;
}
scrollChatToBottom();
window.addEventListener('load', scrollChatToBottom);

// Preloaded history (rendered server-side by PHP) arrives as plain escaped
// text — format it the same way live replies get formatted, so markdown
// looks the same whether it's a fresh reply or something loaded on refresh.
document.querySelectorAll('.chat-bubble.ai:not(.chat-bubble-error)').forEach(el => {
  el.innerHTML = formatMarkdown(el.textContent);
});

async function startNewChat() {
  try {
    const res = await fetch(API_BASE + 'api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reset: true })
    });
    if (!res.ok) throw new Error('Server returned ' + res.status);
    document.getElementById('chatWindow').innerHTML = '';
  } catch (err) {
    // Leave the existing conversation intact rather than clearing it and
    // failing silently — better to show nothing happened than to wipe the
    // visible history while the reset itself may not have gone through.
    appendMessage('ai', "Couldn't start a new chat just now — the connection dropped or timed out. Your current conversation is still here; please try again.");
  }
}

async function sendMessage() {
  const input = document.getElementById('userInput');
  const provider = document.getElementById('providerSelect').value;
  const text = input.value.trim();
  if (!text) return;
  appendMessage('user', text);
  input.value = '';

  appendTypingIndicator();

  try {
    const res = await fetch(API_BASE + 'api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, provider })
    });
    const data = await res.json();
    replaceLastAiMessage(data.reply, data.error, data.details);
  } catch (err) {
    // A rejected fetch() (as opposed to a normal error response from
    // chat.php) means the connection itself failed or was cut off before
    // any response came back — a network hiccup or a timeout, not
    // something chat.php returned. Same friendly-message + technical-detail
    // pattern as server-side errors, for a consistent experience either way.
    replaceLastAiMessage(
      "Couldn't reach the server just now. This usually means the connection dropped or timed out — please try again.",
      true,
      err.message
    );
  }
}

function appendMessage(role, text, isPlaceholder = false) {
  const win = document.getElementById('chatWindow');
  const row = document.createElement('div');
  row.className = role === 'user' ? 'text-end mb-2' : 'text-start mb-2';
  if (isPlaceholder) row.dataset.placeholder = 'true';
  const bubbleClass = role === 'user' ? 'chat-bubble user' : 'chat-bubble ai';
  let html = `<div class="${bubbleClass}">${escapeHtml(text)}</div>`;
  if (!isPlaceholder) {
    html += timestampCaptionHtml(role === 'user' ? 'Sent' : AGENT_NAME, new Date());
  }
  row.innerHTML = html;
  win.appendChild(row);
  win.scrollTop = win.scrollHeight;
}

/**
 * Builds a small "Label · h:mm AM/PM" caption using the VIEWER's own local
 * time — the industry-standard approach (iMessage, WhatsApp, Slack all do
 * this): store an unambiguous timestamp, convert to local time on
 * whatever device is actually displaying it, rather than showing the
 * server's own timezone to everyone regardless of where they are.
 */
function timestampCaptionHtml(label, date) {
  const time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  return `<div class="msg-timestamp">${escapeHtml(label)} · ${escapeHtml(time)}</div>`;
}

/**
 * Preloaded history (rendered server-side) ships the real UTC timestamp
 * as a data attribute rather than pre-formatted text — the server can't
 * know the visitor's timezone, so the actual local-time conversion has to
 * happen here, in the browser, once, on page load.
 */
document.querySelectorAll('.ts-time[data-utc]').forEach(el => {
  const date = new Date(el.dataset.utc);
  el.textContent = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
});

/**
 * Shows a genuinely animated "typing" indicator (three bouncing dots, CSS
 * animation) while a request is actually in flight — replaces the old
 * static "Thinking..." text. Honest by construction: it animates for
 * exactly as long as the real wait takes, nothing simulated about the
 * timing, since it's just a CSS animation running until the response
 * actually arrives and replaceLastAiMessage() swaps it out.
 */
function appendTypingIndicator() {
  const win = document.getElementById('chatWindow');
  const row = document.createElement('div');
  row.className = 'text-start mb-2';
  row.dataset.placeholder = 'true';
  row.innerHTML = `<div class="chat-bubble ai typing-indicator"><span></span><span></span><span></span></div>`;
  win.appendChild(row);
  win.scrollTop = win.scrollHeight;
}

function replaceLastAiMessage(text, isError = false, details = null) {
  const win = document.getElementById('chatWindow');
  const placeholder = win.querySelector('[data-placeholder="true"]');
  if (!placeholder) return;

  const bubbleClass = isError ? 'chat-bubble ai chat-bubble-error' : 'chat-bubble ai';
  placeholder.innerHTML = `<div class="${bubbleClass}"></div>`;
  delete placeholder.dataset.placeholder;
  const bubbleEl = placeholder.querySelector('.chat-bubble');

  // The response already fully exists by the time this runs — render it
  // immediately rather than simulating a gradual reveal of content that
  // isn't actually arriving gradually. The typing indicator (shown while
  // the request was genuinely in flight) already covered the real wait.
  bubbleEl.innerHTML = formatMarkdown(text);

  if (isError && details) {
    const detailId = 'err-details-' + Date.now();

    const link = document.createElement('a');
    link.href = '#';
    link.className = 'error-toggle';
    link.dataset.target = detailId;
    link.textContent = 'View details';

    const pre = document.createElement('pre');
    pre.id = detailId;
    pre.className = 'error-details';
    pre.style.display = 'none';
    pre.textContent = details;

    bubbleEl.appendChild(document.createTextNode(' '));
    bubbleEl.appendChild(link);
    bubbleEl.appendChild(pre);
  }

  placeholder.insertAdjacentHTML('beforeend', timestampCaptionHtml(isError ? 'Error' : AGENT_NAME, new Date()));
  win.scrollTop = win.scrollHeight;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

/**
 * Converts a small, safe whitelist of markdown syntax into real HTML.
 * Escapes HTML first — ALWAYS — so nothing in the AI's reply (or anything
 * echoed back from earlier conversation turns) can inject real tags. Only
 * the specific patterns matched below become HTML; everything else stays
 * literal escaped text.
 */
function formatMarkdown(rawText) {
  let html = escapeHtml(rawText);

  // Headings: # through ###### at the start of a line -> bold text.
  // Real <h1>-<h6> tags would look oversized inside a small chat bubble,
  // so headings render as bold instead — visually consistent with the
  // rest of the formatting rather than introducing a new style language.
  html = html.replace(/^#{1,6} +(.*)$/gm, '<strong>$1</strong>');

  // Bullet markers at the start of a line: "- " or "* " -> "• "
  // Done before bold/italic so a leading "* " can't get misread as an
  // italic opening marker.
  html = html.replace(/^[-*] /gm, '• ');

  // Bare URLs: a raw https://... sitting in plain text, not wrapped in
  // markdown [text](url) syntax. Models often paste a URL directly rather
  // than using full link syntax — this catches that case too. The negative
  // lookbehind skips URLs that ARE part of [text](url), so this doesn't
  // interfere with the explicit-link rule that runs right after it. Trailing
  // sentence punctuation (a period ending the sentence, etc.) is kept
  // outside the link rather than swallowed into the URL.
  html = html.replace(/(?<!\]\()https?:\/\/[^\s<>()]+/g, (match) => {
    const trailingPunctuation = match.match(/[.,!?;:'"]+$/);
    let url = match;
    let trailing = '';
    if (trailingPunctuation) {
      trailing = trailingPunctuation[0];
      url = match.slice(0, -trailing.length);
    }
    if (!url) return match; // guard against a match that was ALL trailing punctuation
    return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>${trailing}`;
  });

  // Links: [text](url) — the URL pattern ONLY matches http(s):// and
  // mailto: schemes. This is a deliberate security boundary, not just a
  // formatting choice: anything else (javascript:, data:, vbscript:, etc.)
  // simply won't match this pattern at all, so a malicious "link" stays as
  // inert escaped text instead of becoming a clickable, executable URL.
  // rel="noopener noreferrer" prevents the opened tab from accessing
  // window.opener on this page.
  html = html.replace(
    /\[([^\]\n]+?)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g,
    '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
  );

  // Inline code: `code`
  html = html.replace(/`([^`\n]+?)`/g, '<code>$1</code>');

  // Strikethrough: ~~text~~
  html = html.replace(/~~([^~\n]+?)~~/g, '<s>$1</s>');

  // Bold+italic combined: ***text*** — must run BEFORE the separate bold
  // and italic rules below, otherwise the bold rule would greedily consume
  // two of the three asterisks and leave a stray one, breaking the output.
  html = html.replace(/\*\*\*([^*\n]+?)\*\*\*/g, '<strong><em>$1</em></strong>');

  // Bold: **text** or __text__
  html = html.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/__([^_\n]+?)__/g, '<strong>$1</strong>');

  // Italic: *text* or _text_ (single markers — bold's doubles are already consumed above)
  html = html.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
  html = html.replace(/\b_([^_\n]+?)_\b/g, '<em>$1</em>');

  return html;
}
