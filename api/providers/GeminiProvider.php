<?php
require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/ProviderException.php';

class GeminiProvider implements AIProvider {
    /**
     * Builds the request payload, separated from generate() so it can be
     * tested directly without a live network call.
     */
    public function buildPayload(array $messages, ?string $systemPrompt = null): array {
        // Gemini uses 'model' instead of 'assistant', and wraps text in a parts array.
        $contents = array_map(function ($m) {
            return [
                'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]]
            ];
        }, $messages);

        $payload = ['contents' => $contents];

        // Gemini's system prompt is NOT a message with role "system" inside
        // contents — it's a separate top-level field. Verified directly
        // against Google's own REST API reference before writing this;
        // several blog/SDK examples show a different shape than the raw
        // REST body actually expects.
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        return $payload;
    }

    public function generate(array $messages, ?string $systemPrompt = null): array {
        $apiKey = getenv('GEMINI_API_KEY');
        // gemini-2.0-flash was deprecated and shut down June 1, 2026 — using
        // it now returns a 429 with quota limit: 0, not a normal rate limit.
        // gemini-3.5-flash is the current durable choice: the immediate
        // replacement (gemini-2.5-flash) is itself scheduled to retire
        // October 16, 2026, so going straight to 3.5 avoids a second
        // migration two months from now.
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

        $payload = $this->buildPayload($messages, $systemPrompt);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw ProviderException::fromHttpResponse(0, $err, 'Gemini');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Gemini');
        }

        $data = json_decode($response, true);
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($reply === null) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Gemini');
        }

        return [
            'text' => $reply,
            'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? null,
            'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? null,
            'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? null,
        ];
    }
}
