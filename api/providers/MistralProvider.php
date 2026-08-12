<?php
require_once __DIR__ . '/OpenAICompatible.php';

class MistralProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('MISTRAL_API_KEY');
        $this->url = 'https://api.mistral.ai/v1/chat/completions';
        $this->model = 'mistral-small-latest'; // good default for the free Experiment tier
        $this->label = 'Mistral';
        $this->headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ];
    }
}
