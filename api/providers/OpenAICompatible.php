<?php
require_once __DIR__ . '/AIProvider.php';
require_once __DIR__ . '/ProviderException.php';

abstract class OpenAICompatible implements AIProvider {
    protected string $url;
    protected array $headers;
    protected string $model;
    protected string $label = 'This provider'; // overridden per subclass for friendlier error messages

    /**
     * Builds the request payload, separated from generate() specifically so
     * it can be tested directly without a live network call — just an
     * array in, array out, no curl involved.
     */
    public function buildPayload(array $messages, ?string $systemPrompt = null): array {
        // Only role and content ever get sent to the API, explicitly,
        // regardless of what extra fields the caller's $messages array
        // might carry (created_at, used for frontend timestamp display,
        // is exactly the field that caused this). Mistral's API rejects
        // any unrecognized field in a message object with a 422 error —
        // "Extra inputs are not permitted" — this isn't optional cleanup,
        // it's required for the request to work at all.
        $cleanMessages = array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $messages);

        if ($systemPrompt !== null && $systemPrompt !== '') {
            array_unshift($cleanMessages, ['role' => 'system', 'content' => $systemPrompt]);
        }
        return [
            'model' => $this->model,
            'messages' => $cleanMessages,
        ];
    }

    public function generate(array $messages, ?string $systemPrompt = null): array {
        $payload = $this->buildPayload($messages, $systemPrompt);

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw ProviderException::fromHttpResponse(0, $err, $this->label);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw ProviderException::fromHttpResponse($httpCode, $response, $this->label);
        }

        $data = json_decode($response, true);
        $reply = $data['choices'][0]['message']['content'] ?? null;
        if ($reply === null) {
            throw ProviderException::fromHttpResponse($httpCode, $response, $this->label);
        }

        // Standard OpenAI-compatible usage shape — shared by OpenAI, DeepSeek,
        // Groq, Mistral, and Azure OpenAI, since that's literally what
        // "OpenAI-compatible" means for this field.
        return [
            'text' => $reply,
            'prompt_tokens' => $data['usage']['prompt_tokens'] ?? null,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
            'total_tokens' => $data['usage']['total_tokens'] ?? null,
        ];
    }
}
