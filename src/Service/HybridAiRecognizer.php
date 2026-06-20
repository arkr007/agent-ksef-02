<?php

declare(strict_types=1);

namespace App\Service;

final class HybridAiRecognizer implements DocumentAiRecognizerInterface
{
    public function __construct(
        private DocumentAiRecognizerInterface $primaryRecognizer,
        private DocumentAiRecognizerInterface $fallbackRecognizer
    ) {
    }

    public function isReady(): bool
    {
        return $this->primaryRecognizer->isReady() || $this->fallbackRecognizer->isReady();
    }

    public function recognizePdfDocumentsFromImages(
        string $sourceFileName,
        array $pageImages,
        array $pageTexts = []
    ): array {
        $primary = $this->primaryRecognizer->recognizePdfDocumentsFromImages($sourceFileName, $pageImages, $pageTexts);
        if (($primary['status'] ?? '') === 'ok') {
            $primary['provider'] = 'hybrid:primary';

            return $primary;
        }

        $fallback = $this->fallbackRecognizer->recognizePdfDocumentsFromImages($sourceFileName, $pageImages, $pageTexts);
        if (($fallback['status'] ?? '') === 'ok') {
            $fallback['provider'] = 'hybrid:fallback';
            $fallback['note'] = trim((string) ($fallback['note'] ?? '')) . ' Fallback po niepowodzeniu podstawowego providera.';

            return $fallback;
        }

        return [
            'status' => 'error',
            'note' => 'Niepowodzenie w trybie hybrid. Provider podstawowy: '
                . (string) ($primary['note'] ?? 'brak szczegolow')
                . '. Provider zapasowy: '
                . (string) ($fallback['note'] ?? 'brak szczegolow')
                . '.',
        ];
    }
}
