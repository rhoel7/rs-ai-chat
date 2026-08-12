<?php
require_once __DIR__ . '/OpenAICompatible.php';

class GroqProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('GROQ_API_KEY');
        $this->url = 'https://api.groq.com/openai/v1/chat/completions';
        $this->model = 'llama-3.3-70b-versatile';
        $this->label = 'Groq';
        $this->headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ];
    }
}
