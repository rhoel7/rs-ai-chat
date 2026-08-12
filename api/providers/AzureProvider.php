<?php
require_once __DIR__ . '/OpenAICompatible.php';

/**
 * NOTE: "Copilot" doesn't expose a general-purpose chat API you can call directly.
 * This Azure OpenAI provider is the realistic stand-in — same underlying OpenAI
 * models, hosted on Microsoft's infrastructure with Azure-style auth.
 */
class AzureProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('AZURE_OPENAI_KEY');
        $endpoint = rtrim(getenv('AZURE_OPENAI_ENDPOINT'), '/');
        $deployment = getenv('AZURE_OPENAI_DEPLOYMENT');
        $this->url = "{$endpoint}/openai/deployments/{$deployment}/chat/completions?api-version=2024-08-01-preview";
        $this->model = $deployment; // Azure ignores 'model' in body but keep for consistency
        $this->label = 'Azure OpenAI';
        $this->headers = [
            'Content-Type: application/json',
            "api-key: {$apiKey}" // different header name than OpenAI direct
        ];
    }
}
