<?php
/**
 * $messages is an array of ['role' => 'user'|'assistant', 'content' => '...']
 * in chronological order — the full conversation so far, not just the latest turn.
 *
 * $systemPrompt, when provided, sets the model's persona/instructions for
 * this request. Each provider applies it in whatever shape its own API
 * actually expects (a system-role message, a systemInstruction field, a
 * top-level system parameter) — callers don't need to know which.
 *
 * Returns an array: ['text' => string, 'prompt_tokens' => ?int, 'completion_tokens' => ?int, 'total_tokens' => ?int]
 * Token fields are nullable — a provider that doesn't report usage still
 * works, it just won't have token data recorded for that message.
 */
interface AIProvider {
    public function generate(array $messages, ?string $systemPrompt = null): array;
}
