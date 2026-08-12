<?php
require_once __DIR__ . '/OpenAICompatible.php';

class DeepSeekProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('DEEPSEEK_API_KEY');
        $this->url = 'https://api.deepseek.com/chat/completions';
        // NOTE: deepseek-chat / deepseek-reasoner are being retired July 24, 2026.
        // deepseek-v4-flash / deepseek-v4-pro are the current model names as of this writing.
        // Double check DeepSeek's docs if this sits unused for a while: https://api-docs.deepseek.com
        $this->model = 'deepseek-v4-flash';
        $this->label = 'DeepSeek';
        $this->headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ];
    }
}
