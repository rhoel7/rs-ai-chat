<?php
require_once __DIR__ . '/Embedder.php';
require_once __DIR__ . '/../providers/ProviderException.php';

class MistralEmbedder implements Embedder {
    public function embed(string $text): array {
        $apiKey = getenv('MISTRAL_API_KEY');
        $url = 'https://api.mistral.ai/v1/embeddings';

        // NOTE: the REST API field is "input" (singular), not "inputs" —
        // the OpenAI-compatible SDK layer uses "inputs" (plural), but the
        // raw Mistral REST endpoint expects "input".
        $payload = [
            'model' => 'mistral-embed',
            'input' => $text,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
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
            throw ProviderException::fromHttpResponse(0, $err, 'Mistral Embeddings');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Mistral Embeddings');
        }

        $data = json_decode($response, true);
        $values = $data['data'][0]['embedding'] ?? null;
        if ($values === null) {
            throw ProviderException::fromHttpResponse($httpCode, $response, 'Mistral Embeddings');
        }
        return $values;
    }

    public function getProviderLabel(): string {
        return 'mistral';
    }
}
