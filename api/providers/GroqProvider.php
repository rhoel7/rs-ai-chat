<?php
require_once __DIR__ . '/OpenAICompatible.php';

class GroqProvider extends OpenAICompatible {
    public function __construct() {
        $apiKey = getenv('GROQ_API_KEY');
        $this->url = 'https://api.groq.com/openai/v1/chat/completions';
        // llama-3.3-70b-versatile (and llama-3.1-8b-instant) moved to Groq's
        // Enterprise tier ("Contact Sales" pricing) — confirmed directly
        // against Groq's own docs, not just inferred from the error. Meta's
        // Llama models specifically require an enterprise agreement now;
        // gpt-oss-120b remains on the standard self-serve developer tier
        // with real, listed rate limits, and is Groq's own featured model
        // as of this check. If this ever 404s again, check
        // https://console.groq.com/docs/models for what's currently on the
        // "Production Models" table with real (not "Contact Sales") limits.
        $this->model = 'openai/gpt-oss-120b';
        $this->label = 'Groq';
        $this->headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ];
    }
}
