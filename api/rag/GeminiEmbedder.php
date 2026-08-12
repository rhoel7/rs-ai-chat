<?php
require_once __DIR__ . '/Embedder.php';
require_once __DIR__ . '/../providers/ProviderException.php';

class GeminiEmbedder implements Embedder {
    public function embed(string $text): array {
        $apiKey = getenv('GEMINI_API_KEY');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';

        $payload = [
            'model' => 'models/gemini-embedding-001',
            'content' => ['parts' => [['text' => $text]]],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "x-goog-api-key: {$apiKey}",
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        // Deliberately short — RagRetriever already falls back gracefully to
        // "answer without knowledge base context" on any failure, so this
        // step should fail fast rather than eating into the overall
        // request's time budget. The main AI completion call still gets its
        // own full timeout separately; this just prevents a slow embedding
        // lookup from stacking on top of it and risking the combined
        // request exceeding a host-enforced connection timeout.
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw ProviderException::fromHttpResponse(0, $err, 'Gemini Embeddings');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Gemini Embeddings');
        }

        $data = json_decode($response, true);
        // NOTE: the field is "embedding" (singular object), not "embeddings" —
        // verified against Google's official REST API reference directly,
        // several SDK-based blog examples show the plural form, which is
        // the SDK's normalized wrapper, not the raw REST response shape.
        $values = $data['embedding']['values'] ?? null;
        if ($values === null) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Gemini Embeddings');
        }
        return $values;
    }

    public function getProviderLabel(): string {
        return 'gemini';
    }
}
