<?php
require_once __DIR__ . '/providers/OpenAIProvider.php';
require_once __DIR__ . '/providers/DeepSeekProvider.php';
require_once __DIR__ . '/providers/AzureProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/ClaudeProvider.php';
require_once __DIR__ . '/providers/GroqProvider.php';
require_once __DIR__ . '/providers/MistralProvider.php';

class ProviderFactory {
    public static function make(string $providerName): AIProvider {
        return match ($providerName) {
            'openai'   => new OpenAIProvider(),
            'deepseek' => new DeepSeekProvider(),
            'azure'    => new AzureProvider(),
            'claude'   => new ClaudeProvider(),
            'gemini'   => new GeminiProvider(),
            'groq'     => new GroqProvider(),
            'mistral'  => new MistralProvider(),
            default    => new GroqProvider(), // safe always-free fallback (Gemini free tier is currently unreliable, see README)
        };
    }
}
