<?php
/**
 * Local test config — fill in whichever provider keys you want to try.
 * You only need to fill in the key(s) for the provider(s) you're testing.
 *
 * IMPORTANT: Never commit this file with real keys to a public repo.
 * For real WordPress deployment, replace this with values stored in
 * WordPress options (encrypted) or server-level environment variables,
 * not a plain PHP file like this one.
 */

// Only sets a default if the variable isn't already set in the real
// environment. This means CI (GitHub Actions), Docker, or any other
// deployment can override these by setting real environment variables
// before PHP runs, without needing to edit this file at all — the
// hardcoded values below only ever act as a fallback default.
function setDefaultEnv(string $name, string $default): void {
    if (getenv($name) === false) {
        putenv("{$name}={$default}");
    }
}

setDefaultEnv('DEFAULT_AI_PROVIDER', 'mistral'); // gemini | openai | claude | deepseek | azure | groq | mistral
// NOTE: Gemini's free tier has been unreliable since Dec 2025 (Google cut it to 0 for
// many accounts/regions — see README). Groq and Mistral currently have the most
// dependable no-cost access, so groq is the default here until you've either linked
// billing on Gemini or decided on a paid provider for production.

setDefaultEnv('GEMINI_API_KEY', 'your-gemini-key-here');
setDefaultEnv('OPENAI_API_KEY', 'your-openai-key-here');
setDefaultEnv('ANTHROPIC_API_KEY', 'your-anthropic-key-here');
setDefaultEnv('DEEPSEEK_API_KEY', 'your-deepseek-key-here');
setDefaultEnv('GROQ_API_KEY', 'your-groq-key-here');
setDefaultEnv('MISTRAL_API_KEY', 'your-mistral-key-here');

// Azure ("Copilot" equivalent) — only needed if you use the azure provider
setDefaultEnv('AZURE_OPENAI_KEY', 'your-azure-key-here');
setDefaultEnv('AZURE_OPENAI_ENDPOINT', 'https://your-resource.openai.azure.com');
setDefaultEnv('AZURE_OPENAI_DEPLOYMENT', 'your-deployment-name');

// Persistent chat memory — create a dedicated MySQL database in Hostinger's
// hPanel (Databases section), separate from your WordPress database, then
// run api/db/schema.sql against it once.
setDefaultEnv('DB_HOST', '127.0.0.1');
setDefaultEnv('DB_NAME', 'your_db_name_here');
setDefaultEnv('DB_USER', 'your_db_user_here');
setDefaultEnv('DB_PASS', 'your_db_password_here');

// Rate limiting — protects against runaway API costs from a single
// abusive visitor or bot. Two independent limits: by IP (catches abuse
// even from someone clearing cookies for a fresh visitor token each time)
// and by visitor cookie (friendlier granularity for normal use).
setDefaultEnv('RATE_LIMIT_IP_PER_MINUTE', '20');
setDefaultEnv('RATE_LIMIT_IP_PER_DAY', '300');
setDefaultEnv('RATE_LIMIT_VISITOR_PER_MINUTE', '15');

// Maximum characters allowed in a single message — prevents someone from
// pasting a huge payload that gets stored and forwarded to a paid API.
setDefaultEnv('MAX_MESSAGE_LENGTH', '2000');

// How many past messages get resent as context to the AI provider on each
// call. Higher means better memory, but more tokens — and cost — per
// message, since the whole history gets resent every time.
setDefaultEnv('MAX_HISTORY_MESSAGES', '20');

// Which provider generates embeddings for RAG ingestion — 'gemini' or
// 'mistral'. Groq has no embedding endpoint, so it's not an option here.
// Uses the same GEMINI_API_KEY / MISTRAL_API_KEY already set above.
setDefaultEnv('RAG_EMBEDDING_PROVIDER', 'mistral');

// How many knowledge base entries to inject as context per question, and
// how similar (cosine similarity, 0-1) a match needs to be to count at
// all. Lower RAG_MIN_SIMILARITY retrieves more liberally but risks
// injecting irrelevant context; raise it if answers seem to ignore
// clearly-relevant entries, lower it if relevant entries aren't being found.
setDefaultEnv('RAG_TOP_K', '3');
setDefaultEnv('RAG_MIN_SIMILARITY', '0.5');

// Messages shorter than this (in characters) skip RAG retrieval entirely —
// short replies like "yes" or "ok" are almost always responding to
// whatever the assistant just said, not asking a new question that needs
// fresh knowledge-base grounding. Re-injecting business content on top of
// a plain "yes" was observed confusing at least one provider into
// re-answering the general topic instead of recognizing a direct
// affirmative reply.
setDefaultEnv('RAG_MIN_QUERY_LENGTH', '15');

// Gives the AI a persistent role/personality instead of behaving as a
// generic assistant — e.g. "a helpful sales associate for Reunify
// Studios" or "a patient programming tutor." Off by default; anyone who
// just wants plain chat shouldn't have to opt out of anything.
setDefaultEnv('PERSONA_ENABLED', 'false');

// The persona itself, in plain language — describe the role, tone, and
// any boundaries. This gets automatically wrapped with instructions
// telling the model to resist attempts to override it (see api/Persona.php)
// — you don't need to write that part yourself, just describe who the
// assistant should be.
setDefaultEnv('PERSONA_PROMPT', 'You are a helpful assistant for our business. Be friendly, concise, and accurate. If you do not know something, say so rather than guessing.');

// Only relevant when PERSONA_ENABLED is true. When also 'true', the
// assistant is instructed to only discuss topics related to its defined
// role and politely redirect anything else — good for a business sales
// assistant that shouldn't answer, say, programming questions. Leave this
// false for personas that are SUPPOSED to range broadly (a programmer
// assistant, a general tutor), where this would work against the point.
setDefaultEnv('PERSONA_RESTRICT_TOPIC', 'false');

// Hostname keywords (comma-separated) that mark this as a staging
// environment, shown in the STAGING/LIVE banner on index.php. Adjust to
// match your own naming convention if it's not just "staging".
setDefaultEnv('STAGING_HOSTNAME_KEYWORDS', 'staging,dev,test');

// Title shown in the chat header. Change this to your own site/business
// name — nothing else in the code needs to change.
setDefaultEnv('CHAT_TITLE', 'RAIA');

// The name shown next to the AI's replies in chat, alongside the
// timestamp — "Raia · 9:14 AM" instead of a generic "Automated" label.
// Defaults to a name-styled version of the RAIA brand rather than a
// disconnected name, so it stays recognizably tied to the product while
// still reading naturally in a friendly conversational context.
setDefaultEnv('AGENT_NAME', 'Raia');

// Small animated label pointing at the floating bubble, shown once (per
// browser, remembered via localStorage) a short delay after page load —
// first-time visitors often don't notice a small floating button on their
// own. Disappears permanently once they open the chat or dismiss it
// explicitly. Set to an empty string to disable it entirely.
setDefaultEnv('CHAT_HINT_TEXT', 'Questions? Chat with us!');

// Always told to the model, regardless of whether persona is enabled —
// same reasoning as the date grounding below. Without this, the AI falls
// back on its general knowledge of how chat apps typically work (most DO
// have file upload) and confidently describes features this widget
// doesn't actually have, observed directly: asked to attach a photo, it
// invented a paperclip icon and drag-and-drop instructions that don't
// exist in this interface, then kept doubling down even after being told
// "I don't see that icon." Update this as real capabilities get added
// (file attachment, order tracking links, etc.) — it's the one place
// that needs to change, not the model's behavior.
setDefaultEnv('CHAT_CAPABILITIES_NOTE', 'This chat is text-only. There is no way for a customer to attach, upload, or send files, photos, or images through it, and no real-time order tracking or account lookup. If asked to do any of these, say plainly that this chat can\'t do that yet, do not describe steps, icons, or drag-and-drop for a feature that does not exist, and point them to https://reunifystudios.com/contact-us/ instead. Never invent a phone number, email address, or any other contact detail that wasn\'t explicitly given to you here.');

// Leave this blank if index.php/widget.php lives in the same folder as
// chat.js and the api/ folder (the default setup). Only set this if you're
// including widget.php from a PHP page that lives somewhere ELSE on your
// site — set it to the root-relative (or full) URL path to this project's
// folder, e.g. '/wp-content/plugins/reunify-ai-chat', so the widget's
// asset links and API calls resolve correctly regardless of what page
// it's embedded on. No trailing slash.
setDefaultEnv('WIDGET_BASE_URL', '');

// Encrypts message content before it's stored — visit api/admin.php with
// action=generate_key (see README) to get a real key, then paste it here.
// Never reuse this placeholder as a real key.
setDefaultEnv('CHAT_ENCRYPTION_KEY', 'GENERATE_YOUR_OWN_KEY_SEE_README');

// Protects api/admin.php (key generation, encryption migration, cleanup,
// stats). This has to be set BEFORE admin.php is usable at all, so generate
// it yourself, any of these work fine — a password manager's "generate
// password" feature (32+ characters), or if you have SSH access:
//   php -r "echo bin2hex(random_bytes(32));"
// Paste the result here. Treat this like a password: anyone with it can
// run admin actions against your database.
setDefaultEnv('ADMIN_TOKEN', 'GENERATE_YOUR_OWN_TOKEN_SEE_README');
