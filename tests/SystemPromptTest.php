<?php
require_once __DIR__ . '/../api/Persona.php';
require_once __DIR__ . '/../api/providers/GroqProvider.php';
require_once __DIR__ . '/../api/providers/GeminiProvider.php';
require_once __DIR__ . '/../api/providers/ClaudeProvider.php';

/**
 * No database or network needed — buildPayload() on each provider is pure
 * (array in, array out), and Persona::buildSystemPrompt() only reads
 * environment variables. Uses putenv() directly to control PERSONA_*
 * for each test, then restores the real config afterward.
 */
class SystemPromptTest {
    public function testDisabledByDefaultReturnsNull(TestRunner $t): void {
        putenv('PERSONA_ENABLED=false');
        $t->assertEquals(null, Persona::buildSystemPrompt(), 'Persona should be off unless explicitly enabled');
    }

    public function testEnabledWithEmptyPromptReturnsNull(TestRunner $t): void {
        putenv('PERSONA_ENABLED=true');
        putenv('PERSONA_PROMPT=');
        $t->assertEquals(null, Persona::buildSystemPrompt(), 'Enabling the feature with no actual persona text configured should behave as if disabled, not send an empty prompt');
        putenv('PERSONA_ENABLED=false');
    }

    public function testEnabledWithPromptIncludesPersonaText(TestRunner $t): void {
        putenv('PERSONA_ENABLED=true');
        putenv('PERSONA_PROMPT=You are a helpful sales associate for Reunify Studios.');
        $prompt = Persona::buildSystemPrompt();
        $t->assertStringContains('Reunify Studios', $prompt, 'The configured persona text should appear in the system prompt');
        putenv('PERSONA_ENABLED=false');
    }

    public function testEnabledPromptIncludesAntiOverrideLanguage(TestRunner $t): void {
        putenv('PERSONA_ENABLED=true');
        putenv('PERSONA_PROMPT=You are a helpful assistant.');
        $prompt = Persona::buildSystemPrompt();
        $t->assertStringContains('ignore', $prompt, 'The anti-override wrapper should be present alongside the persona text');
        putenv('PERSONA_ENABLED=false');
    }

    public function testTopicRestrictionOffByDefaultEvenWithPersonaEnabled(TestRunner $t): void {
        putenv('PERSONA_ENABLED=true');
        putenv('PERSONA_PROMPT=You are a helpful assistant.');
        putenv('PERSONA_RESTRICT_TOPIC=false');
        $prompt = Persona::buildSystemPrompt();
        $t->assertFalse(str_contains($prompt, 'Only discuss topics'), 'Topic restriction should not appear unless separately enabled — a persona alone should not automatically restrict scope');
        putenv('PERSONA_ENABLED=false');
    }

    public function testTopicRestrictionAppearsWhenExplicitlyEnabled(TestRunner $t): void {
        putenv('PERSONA_ENABLED=true');
        putenv('PERSONA_PROMPT=You are a helpful sales associate.');
        putenv('PERSONA_RESTRICT_TOPIC=true');
        $prompt = Persona::buildSystemPrompt();
        $t->assertStringContains('Only discuss topics', $prompt, 'Topic restriction language should appear when explicitly enabled');
        $t->assertStringContains('sales associate', $prompt, 'The persona text itself should still be present too');
        putenv('PERSONA_ENABLED=false');
        putenv('PERSONA_RESTRICT_TOPIC=false');
    }

    public function testTopicRestrictionHasNoEffectWhenPersonaDisabled(TestRunner $t): void {
        putenv('PERSONA_ENABLED=false');
        putenv('PERSONA_RESTRICT_TOPIC=true'); // set, but persona itself is off
        $t->assertEquals(null, Persona::buildSystemPrompt(), 'Topic restriction is meaningless without a persona to restrict — should still return null when persona is disabled');
        putenv('PERSONA_RESTRICT_TOPIC=false');
    }

    // --- Provider payload shape tests — the actual point of this file ---

    public function testOpenAICompatiblePrependsSystemRoleMessage(TestRunner $t): void {
        $provider = new GroqProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], 'Be a pirate.');
        $t->assertEquals('system', $payload['messages'][0]['role'], 'System prompt should be prepended as the first message, role=system');
        $t->assertEquals('Be a pirate.', $payload['messages'][0]['content']);
        $t->assertEquals('user', $payload['messages'][1]['role'], 'The original user message should still be present, after the system message');
    }

    public function testOpenAICompatibleOmitsSystemMessageWhenNoPersona(TestRunner $t): void {
        $provider = new GroqProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], null);
        $t->assertEquals(1, count($payload['messages']), 'With no system prompt, the messages array should be untouched — no extra message added');
        $t->assertEquals('user', $payload['messages'][0]['role']);
    }

    public function testOpenAICompatibleStripsExtraFieldsFromHistory(TestRunner $t): void {
        // Directly reproduces the real bug: getHistory() includes
        // created_at for frontend timestamp display, and that same array
        // gets passed straight to the provider. Mistral's API rejected
        // this with a 422 — "Extra inputs are not permitted" — for
        // exactly the created_at field. This test exists so that bug
        // cannot silently come back.
        $provider = new GroqProvider();
        $messagesWithExtraFields = [
            ['role' => 'user', 'content' => 'hi', 'created_at' => '2026-08-11 16:28:10'],
            ['role' => 'assistant', 'content' => 'hello', 'created_at' => '2026-08-11 16:28:12'],
        ];
        $payload = $provider->buildPayload($messagesWithExtraFields, null);
        foreach ($payload['messages'] as $msg) {
            $t->assertEquals(['role', 'content'], array_keys($msg), 'Only role and content should ever reach the API payload, regardless of what extra fields the history array carries');
        }
    }

    public function testClaudeStripsExtraFieldsFromHistory(TestRunner $t): void {
        $provider = new ClaudeProvider();
        $messagesWithExtraFields = [
            ['role' => 'user', 'content' => 'hi', 'created_at' => '2026-08-11 16:28:10'],
        ];
        $payload = $provider->buildPayload($messagesWithExtraFields, null);
        $t->assertEquals(['role', 'content'], array_keys($payload['messages'][0]), 'Claude payload messages should also only ever contain role and content');
    }

    public function testGeminiStripsExtraFieldsFromHistory(TestRunner $t): void {
        // Gemini restructures messages into a completely different shape
        // regardless (role/parts, not role/content), so it was never
        // actually vulnerable to this — this test just confirms that
        // stays true rather than assuming it.
        $provider = new GeminiProvider();
        $messagesWithExtraFields = [
            ['role' => 'user', 'content' => 'hi', 'created_at' => '2026-08-11 16:28:10'],
        ];
        $payload = $provider->buildPayload($messagesWithExtraFields, null);
        $t->assertFalse(isset($payload['contents'][0]['created_at']), 'Gemini payload should never carry the extra field through either');
    }

    public function testGeminiUsesTopLevelSystemInstructionField(TestRunner $t): void {
        $provider = new GeminiProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], 'Be a pirate.');
        $t->assertEquals('Be a pirate.', $payload['systemInstruction']['parts'][0]['text'], 'Gemini needs systemInstruction as a TOP-LEVEL field, not a message role');
        // Confirm it did NOT get added as a fake "system" entry inside contents (the wrong shape)
        foreach ($payload['contents'] as $entry) {
            $t->assertFalse($entry['role'] === 'system', 'Gemini contents array should never contain a "system" role — that is not how its API works');
        }
    }

    public function testGeminiOmitsSystemInstructionWhenNoPersona(TestRunner $t): void {
        $provider = new GeminiProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], null);
        $t->assertFalse(isset($payload['systemInstruction']), 'With no system prompt, systemInstruction should not appear in the payload at all');
    }

    public function testClaudeUsesTopLevelSystemField(TestRunner $t): void {
        $provider = new ClaudeProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], 'Be a pirate.');
        $t->assertEquals('Be a pirate.', $payload['system'], 'Claude needs a top-level "system" string field');
        foreach ($payload['messages'] as $msg) {
            $t->assertFalse($msg['role'] === 'system', 'Claude messages array must never contain role=system — the real API rejects this with an explicit error');
        }
    }

    public function testClaudeOmitsSystemFieldWhenNoPersona(TestRunner $t): void {
        $provider = new ClaudeProvider();
        $payload = $provider->buildPayload([['role' => 'user', 'content' => 'hello']], null);
        $t->assertFalse(isset($payload['system']), 'With no system prompt, the system field should not appear in the payload at all');
    }
}
