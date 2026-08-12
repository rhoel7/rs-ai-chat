<?php
require_once __DIR__ . '/OpenAICompatible.php';

class OpenAIProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('OPENAI_API_KEY');
        $this->url = 'https://api.openai.com/v1/chat/completions';
        $this->model = 'gpt-4o';
        $this->label = 'OpenAI';
        $this->headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ];
    }
}
