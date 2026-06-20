<?php

declare(strict_types=1);

namespace App\Service;

interface DocumentAiRecognizerInterface
{
    public function isReady(): bool;

    public function recognizePdfDocumentsFromImages(
        string $sourceFileName,
        array $pageImages,
        array $pageTexts = []
    ): array;
}
