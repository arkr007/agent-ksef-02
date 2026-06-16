<?php

declare(strict_types=1);

namespace App\Service;

final class OpenAiHelper
{
    public function suggestNormalization(string $text): array
    {
        unset($text);

        throw new \LogicException('Integracja OpenAI zostanie zaimplementowana w etapie 3 i wykorzystana w etapach 4-6.');
    }
}
