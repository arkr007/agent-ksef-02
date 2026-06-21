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
        if (($primary['status'] ?? '') === 'ok' && !$this->shouldFallbackForLowConfidence($primary)) {
            $primary['provider'] = 'hybrid:primary';

            return $primary;
        }

        $fallback = $this->fallbackRecognizer->recognizePdfDocumentsFromImages($sourceFileName, $pageImages, $pageTexts);
        if (($fallback['status'] ?? '') === 'ok') {
            $fallback['provider'] = 'hybrid:fallback';
            $fallbackReason = ($primary['status'] ?? '') === 'ok'
                ? 'Fallback po niskiej jakosci rozpoznania podstawowego providera.'
                : 'Fallback po niepowodzeniu podstawowego providera.';
            $fallback['note'] = trim((string) ($fallback['note'] ?? '')) . ' ' . $fallbackReason;

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

    private function shouldFallbackForLowConfidence(array $result): bool
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            return true;
        }

        $documents = array_values(array_filter(
            (array) ($data['documents'] ?? []),
            static fn (mixed $item): bool => is_array($item)
        ));
        $manualReviewPages = array_values(array_filter(
            (array) ($data['manual_review_pages'] ?? []),
            static fn (mixed $item): bool => is_array($item)
        ));

        if ($documents === []) {
            return true;
        }

        $trustedDocuments = 0;
        foreach ($documents as $document) {
            $manualReview = (bool) ($document['manual_review'] ?? false);
            $issuerName = trim((string) ($document['issuer_name'] ?? ''));
            $invoiceNumber = trim((string) ($document['invoice_number'] ?? ''));
            $grossAmount = trim((string) ($document['gross_amount'] ?? ''));
            $amountDue = trim((string) ($document['amount_due'] ?? ''));

            $hasKeyFields = ($issuerName !== '' || $invoiceNumber !== '')
                && ($grossAmount !== '' || $amountDue !== '');

            if (!$manualReview && $hasKeyFields) {
                $trustedDocuments++;
            }
        }

        if ($trustedDocuments === 0) {
            return true;
        }

        if ($trustedDocuments < count($documents) && count($manualReviewPages) >= max(1, (int) floor(count($documents) / 2))) {
            return true;
        }

        return false;
    }
}
