<?php
require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/ProviderException.php';

class ClaudeProvider implements AIProvider {
    /**
     * Builds the request payload, separated from generate() so it can be
     * tested directly without a live network call.
     */
    public function buildPayload(array $messages, ?string $systemPrompt = null): array {
        // Only role and content ever get sent to the API, explicitly,
        // regardless of what extra fields the caller's $messages array
        // might carry (created_at, used for frontend timestamp display,
        // is exactly the field that caused a 422 on Mistral for this same
        // reason — same fix applies here defensively, even though this
        // hasn't been independently confirmed to break on Claude specifically).
        $cleanMessages = array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages);

        $payload = [
            'model' => 'claude-sonnet-5',
            'max_tokens' => 1000,
            'messages' => $cleanMessages,
        ];

        // Claude's system prompt is a top-level "system" string, NOT a
        // message with role "system" in the messages array — the API
        // rejects that with an explicit error. Verified directly against
        // Anthropic's docs before writing this.
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        return $payload;
    }

    public function generate(array $messages, ?string $systemPrompt = null): array {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        $url = 'https://api.anthropic.com/v1/messages';

        $payload = $this->buildPayload($messages, $systemPrompt);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'content-type: application/json',
            "x-api-key: {$apiKey}",
            'anthropic-version: 2023-06-01'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw ProviderException::fromHttpResponse(0, $err, 'Claude');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Claude');
        }

        $data = json_decode($response, true);
        $reply = $data['content'][0]['text'] ?? null;
        if ($reply === null) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Claude');
        }

        // Claude's usage block has no total_tokens field directly — sum it ourselves.
        $promptTokens = $data['usage']['input_tokens'] ?? null;
        $completionTokens = $data['usage']['output_tokens'] ?? null;
        return [
            'text' => $reply,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => ($promptTokens !== null && $completionTokens !== null)
                ? $promptTokens + $completionTokens
                : null,
        ];
    }
}
