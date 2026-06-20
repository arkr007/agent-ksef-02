<?php

declare(strict_types=1);

namespace App\Service;

final class AiRecognizerResolver
{
    public function __construct(
        private ApplicationSettings $applicationSettings,
        private OpenAiHelper $openAiHelper,
        private OllamaHelper $ollamaHelper
    ) {
    }

    public function resolve(): DocumentAiRecognizerInterface
    {
        $snapshot = $this->applicationSettings->snapshot();
        $provider = (string) ($snapshot['ai']['provider'] ?? 'ollama');

        return match ($provider) {
            'openai' => $this->openAiHelper,
            'hybrid' => new HybridAiRecognizer($this->ollamaHelper, $this->openAiHelper),
            default => $this->ollamaHelper,
        };
    }
}
