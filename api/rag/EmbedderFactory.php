<?php
require_once __DIR__ . '/GeminiEmbedder.php';
require_once __DIR__ . '/MistralEmbedder.php';

class EmbedderFactory {
    public static function make(): Embedder {
        $provider = getenv('RAG_EMBEDDING_PROVIDER') ?: 'gemini';
        return match ($provider) {
            'mistral' => new MistralEmbedder(),
            'gemini' => new GeminiEmbedder(),
            default => new GeminiEmbedder(),
        };
    }
}
