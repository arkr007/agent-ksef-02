<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final class PdfInvoiceCandidateParser
{
    private const MANUAL_REVIEW_RETRY_CHUNK_SIZE = 8;
    private const MAX_MANUAL_REVIEW_RETRY_DEPTH = 2;

    public function __construct(
        private PdfInboxAnalysisService $pdfInboxAnalysisService,
        private PdfPageRenderService $pdfPageRenderService,
        private DocumentAiRecognizerInterface $documentAiRecognizer
    ) {
    }

    public function parseFiles(array $pdfFiles): array
    {
        @set_time_limit(300);

        $documents = [];
        $seenFingerprints = [];
        $summary = [
            'source_file_count' => 0,
            'parsed_document_count' => 0,
            'text_file_count' => 0,
            'scan_like_count' => 0,
            'error_count' => 0,
            'ai_recognized_count' => 0,
            'ai_manual_review_count' => 0,
        ];

        foreach ($pdfFiles as $file) {
            if (!is_array($file)) {
                continue;
            }

            $summary['source_file_count']++;
            $filePath = (string) ($file['path'] ?? '');
            $payload = $this->pdfInboxAnalysisService->extractTextPayload($filePath);
            $status = (string) ($payload['status'] ?? 'error');

            if ($status === 'text_ready') {
                $summary['text_file_count']++;
            } elseif ($status === 'scan_like') {
                $summary['scan_like_count']++;
            } else {
                $summary['error_count']++;
            }

            $pageTexts = $this->pageTextsFromPayload($payload);
            $pageCount = max(count($pageTexts), (int) ($payload['page_count'] ?? 0));

            if ($pageCount === 0) {
                $documents[] = $this->buildSourceOnlyDocument($file, $payload, 'error');
                continue;
            }

            $renderedPages = $this->pdfPageRenderService->renderPages($filePath);

            try {
                foreach ($this->parseWholePdf(
                    $pageTexts,
                    array_values((array) ($renderedPages['pages'] ?? [])),
                    $file,
                    max($pageCount, (int) ($renderedPages['page_count'] ?? 0))
                ) as $parsed) {
                    if (!is_array($parsed)) {
                        continue;
                    }

                    $fingerprint = $this->documentFingerprint($parsed);
                    if ($fingerprint !== null && isset($seenFingerprints[$fingerprint])) {
                        continue;
                    }

                    if ($fingerprint !== null) {
                        $seenFingerprints[$fingerprint] = true;
                    }

                    if (($parsed['_ai_used'] ?? false) === true) {
                        if (($parsed['status_badge_class'] ?? 'warn') === 'ok') {
                            $summary['ai_recognized_count']++;
                        } else {
                            $summary['ai_manual_review_count']++;
                        }
                    }

                    unset($parsed['_ai_used']);
                    $documents[] = $parsed;
                    $summary['parsed_document_count']++;
                }
            } finally {
                $this->pdfPageRenderService->cleanup($renderedPages['temp_dir'] ?? null);
            }
        }

        return [
            'parsed_at' => date('Y-m-d H:i:s'),
            'documents' => array_values(array_map(
                fn (array $document, int $index): array => array_replace($document, ['position' => $index + 1]),
                $documents,
                array_keys($documents)
            )),
            'summary' => $summary,
        ];
    }

    private function parseWholePdf(
        array $pageTexts,
        array $pageImages,
        array $file,
        int $pageCount,
        int $pageOffset = 0,
        ?int $fullPageCount = null,
        int $retryDepth = 0
    ): array {
        $fullPageCount ??= $pageCount;

        $aiAttempt = $this->documentAiRecognizer->recognizePdfDocumentsFromImages(
            (string) ($file['name'] ?? ''),
            $pageImages,
            $pageTexts
        );

        if (($aiAttempt['status'] ?? '') === 'ok' && is_array($aiAttempt['data'] ?? null)) {
            return $this->buildWholePdfDocumentsFromAiRecognition(
                $aiAttempt['data'],
                $pageTexts,
                $pageImages,
                $file,
                $pageCount,
                $pageOffset,
                $fullPageCount,
                $retryDepth
            );
        }

        $aiFailureNote = null;
        if (($aiAttempt['status'] ?? '') === 'error') {
            $aiFailureNote = (string) ($aiAttempt['note'] ?? 'Blad rozpoznawania AI.');
        }

        $fallbackDocuments = [];
        for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
            $parsed = $this->parsePageHeuristically(
                $pageTexts[$pageIndex] ?? '',
                $file,
                $pageOffset + $pageIndex + 1,
                $fullPageCount,
                $aiFailureNote
            );

            if ($parsed !== null) {
                $fallbackDocuments[] = $parsed;
            }
        }

        return $fallbackDocuments;
    }

    private function buildWholePdfDocumentsFromAiRecognition(
        array $recognition,
        array $pageTexts,
        array $pageImages,
        array $file,
        int $pageCount,
        int $pageOffset,
        int $fullPageCount,
        int $retryDepth
    ): array {
        $documents = [];
        $coveredPages = [];

        foreach ((array) ($recognition['documents'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $document = $this->buildAiWholePdfDocument($item, $pageTexts, $file, $pageCount);
            if ($document === null) {
                continue;
            }

            $document = $this->offsetDocumentPages($document, $pageOffset, $fullPageCount);
            $documents[] = $document;

            $pageFrom = max(1, (int) ($document['source_page_from'] ?? 0) - $pageOffset);
            $pageTo = max($pageFrom, (int) ($document['source_page_to'] ?? 0) - $pageOffset);
            for ($page = $pageFrom; $page <= $pageTo; $page++) {
                $coveredPages[$page] = true;
            }
        }

        foreach ((array) ($recognition['manual_review_pages'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $pageFrom = $this->normalizePageNumber($item['page_from'] ?? null, $pageCount);
            $pageTo = $this->normalizePageNumber($item['page_to'] ?? null, $pageCount, $pageFrom);
            $note = $this->normalizeNullableString($item['note'] ?? null) ?? 'Niepewny odczyt dokumentu.';

            if ($pageFrom === null || $pageTo === null) {
                continue;
            }

            if ($this->shouldRetryManualReviewRange($pageFrom, $pageTo, $retryDepth)) {
                foreach ($this->retryManualReviewRange(
                    $pageTexts,
                    $pageImages,
                    $file,
                    $pageFrom,
                    $pageTo,
                    $pageOffset,
                    $fullPageCount,
                    $retryDepth
                ) as $retryDocument) {
                    $documents[] = $retryDocument;
                }

                for ($page = $pageFrom; $page <= $pageTo; $page++) {
                    $coveredPages[$page] = true;
                }

                continue;
            }

            for ($page = $pageFrom; $page <= $pageTo; $page++) {
                $coveredPages[$page] = true;
            }

            $absoluteFrom = $pageOffset + $pageFrom;
            $absoluteTo = $pageOffset + $pageTo;

            $documents[] = $this->buildManualReviewRangeDocument(
                $file,
                $absoluteFrom,
                $absoluteTo,
                $fullPageCount,
                $this->combinedPreview($pageTexts, $pageFrom, $pageTo),
                'Zakres stron ' . $this->pageLabel($absoluteFrom, $absoluteTo) . ' z PDF '
                . (string) ($file['name'] ?? '') . ' wymaga recznej weryfikacji: ' . $note
            );
        }

        for ($page = 1; $page <= $pageCount; $page++) {
            if (isset($coveredPages[$page])) {
                continue;
            }

            $pageText = trim((string) ($pageTexts[$page - 1] ?? ''));
            $parsed = $this->parsePageHeuristically(
                $pageText,
                $file,
                $pageOffset + $page,
                $fullPageCount,
                'AI nie przypisalo strony do zadnego dokumentu.'
            );

            if ($parsed !== null) {
                $documents[] = $parsed;
            }
        }

        return $documents;
    }

    private function buildAiWholePdfDocument(
        array $item,
        array $pageTexts,
        array $file,
        int $pageCount
    ): ?array {
        $pageFrom = $this->normalizePageNumber($item['page_from'] ?? null, $pageCount);
        $pageTo = $this->normalizePageNumber($item['page_to'] ?? null, $pageCount, $pageFrom);
        if ($pageFrom === null || $pageTo === null) {
            return null;
        }

        $sourceType = $this->normalizeAiSourceType((string) ($item['source_type'] ?? 'other'));
        $issuerName = $this->normalizeNullableString($item['issuer_name'] ?? null);
        $invoiceNumber = $this->normalizeNullableString($item['invoice_number'] ?? null);
        $grossAmount = $this->normalizeAmount((string) ($item['gross_amount'] ?? ''));
        $amountDue = $this->normalizeAmount((string) ($item['amount_due'] ?? ''));
        $currency = $this->normalizeCurrency($item['currency'] ?? null);
        $issueDate = $this->normalizeAiDate($item['issue_date'] ?? null);
        $dueDate = $this->normalizeAiDate($item['due_date'] ?? null);
        $manualReview = (bool) ($item['manual_review'] ?? false);
        $note = $this->normalizeNullableString($item['note'] ?? null) ?? 'Dane rozpoznane przez AI z caĹ‚ego PDF.';

        $reviewReasons = [];
        if ($issuerName === null || $this->isSuspiciousIssuerName($issuerName)) {
            $reviewReasons[] = 'brak pewnej nazwy wystawcy';
        }

        if ($invoiceNumber === null && $sourceType === 'invoice') {
            $reviewReasons[] = 'brak pewnego numeru dokumentu';
        }

        if ($grossAmount === null && $amountDue === null) {
            $reviewReasons[] = 'brak pewnej kwoty';
        }

        $requiresManualReview = $manualReview || $reviewReasons !== [];
        $pageLabel = $this->pageLabel($pageFrom, $pageTo);

        return [
            'source_file_name' => (string) ($file['name'] ?? ''),
            'source_file_path' => (string) ($file['path'] ?? ''),
            'source_chunk_index' => $pageFrom,
            'source_page_number' => $pageFrom,
            'source_page_from' => $pageFrom,
            'source_page_to' => $pageTo,
            'source_page_label' => $pageLabel,
            'recognition_mode' => 'ai_whole_pdf',
            'recognition_mode_label' => 'AI (caĹ‚y PDF)',
            'source_type' => $sourceType,
            'page_count' => $pageCount > 0 ? $pageCount : null,
            'invoice_number' => $invoiceNumber,
            'issuer_name' => $issuerName,
            'amount_due' => $amountDue ?? $grossAmount,
            'gross_amount' => $grossAmount,
            'currency' => $currency,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'status_badge_class' => $requiresManualReview ? 'warn' : 'ok',
            'status_label' => $requiresManualReview ? 'Do rÄ™cznej weryfikacji' : 'Rozpoznany przez AI',
            'note' => $requiresManualReview
                ? 'Zakres stron ' . $pageLabel . ' z PDF ' . (string) ($file['name'] ?? '') . ' wymaga rÄ™cznej weryfikacji: ' . implode('; ', array_merge([$note], $reviewReasons)) . '.'
                : $note,
            'text_preview' => $this->combinedPreview($pageTexts, $pageFrom, $pageTo),
            '_ai_used' => true,
        ];
    }

    private function parsePageHeuristically(
        string $pageText,
        array $file,
        int $pageNumber,
        int $pageCount,
        ?string $aiFailureNote = null
    ): ?array {
        $normalizedPage = trim($pageText);
        if ($normalizedPage === '') {
            $note = 'Strona z PDF ' . (string) ($file['name'] ?? '') . ' nie zawiera czytelnej warstwy tekstowej.';
            if ($aiFailureNote !== null) {
                $note .= ' Dodatkowo AI nie rozpoznaĹ‚o dokumentu: ' . $aiFailureNote;
            }

            return $this->buildManualReviewPageDocument(
                $file,
                $pageNumber,
                $pageCount,
                '',
                $note
            );
        }

        $search = $this->normalizeForSearch($normalizedPage);
        $invoiceNumber = $this->extractInvoiceNumber($normalizedPage, $search);
        $issuerName = $this->extractIssuerName($normalizedPage, $search);
        $dueDate = $this->extractDueDate($normalizedPage, $search);
        $issueDate = $this->extractIssueDate($normalizedPage, $search);
        $amountDue = $this->extractAmountDue($normalizedPage, $search);
        $currency = $this->extractCurrency($normalizedPage, $search, $amountDue);
        $sourceType = $this->detectSourceType($search);

        if (!$this->looksLikeInvoicePage($normalizedPage, $search, $invoiceNumber, $issuerName, $amountDue, $issueDate, $dueDate)) {
            $note = 'Strona z PDF ' . (string) ($file['name'] ?? '') . ' wymaga rÄ™cznej weryfikacji.';
            if ($aiFailureNote !== null) {
                $note .= ' Rozpoznawanie AI zakoĹ„czyĹ‚o siÄ™ bĹ‚Ä™dem: ' . $aiFailureNote;
            }

            return $this->buildManualReviewPageDocument(
                $file,
                $pageNumber,
                $pageCount,
                $normalizedPage,
                $note
            );
        }

        $reviewReasons = $this->manualReviewReasons(
            $normalizedPage,
            $issuerName,
            $invoiceNumber,
            $amountDue,
            $issueDate,
            $dueDate
        );
        $requiresManualReview = $reviewReasons !== [];
        $note = $requiresManualReview
            ? 'Strona z PDF ' . (string) ($file['name'] ?? '') . ' wymaga rÄ™cznej weryfikacji: ' . implode('; ', $reviewReasons) . '.'
            : 'Dane rozpoznane z pojedynczej strony PDF.';

        if ($aiFailureNote !== null) {
            $note .= ' Fallback lokalny po bĹ‚Ä™dzie AI: ' . $aiFailureNote . '.';
        }

        return [
            'source_file_name' => (string) ($file['name'] ?? ''),
            'source_file_path' => (string) ($file['path'] ?? ''),
            'source_chunk_index' => $pageNumber,
            'source_page_number' => $pageNumber,
            'source_page_from' => $pageNumber,
            'source_page_to' => $pageNumber,
            'source_page_label' => $this->pageLabel($pageNumber, $pageNumber),
            'recognition_mode' => 'text_fallback',
            'recognition_mode_label' => 'Fallback tekstowy',
            'source_type' => $sourceType,
            'page_count' => $pageCount > 0 ? $pageCount : null,
            'invoice_number' => $invoiceNumber,
            'issuer_name' => $issuerName,
            'amount_due' => $amountDue,
            'currency' => $currency,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'status_badge_class' => $requiresManualReview ? 'warn' : 'ok',
            'status_label' => $requiresManualReview ? 'Do rÄ™cznej weryfikacji' : 'Rozpoznany tekstowo',
            'note' => $note,
            'text_preview' => $this->preview($normalizedPage),
        ];
    }

    private function pageTextsFromPayload(array $payload): array
    {
        $pageTexts = array_values(array_map(
            fn (mixed $pageText): string => is_string($pageText) ? trim($pageText) : '',
            (array) ($payload['page_texts'] ?? [])
        ));

        if ($pageTexts !== []) {
            return $pageTexts;
        }

        $fallbackText = trim((string) ($payload['text'] ?? ''));

        return $fallbackText !== '' ? [$fallbackText] : [];
    }

    private function buildManualReviewPageDocument(
        array $file,
        int $pageNumber,
        int $pageCount,
        string $pageText,
        string $note
    ): array {
        return [
            'source_file_name' => (string) ($file['name'] ?? ''),
            'source_file_path' => (string) ($file['path'] ?? ''),
            'source_chunk_index' => $pageNumber,
            'source_page_number' => $pageNumber,
            'source_page_from' => $pageNumber,
            'source_page_to' => $pageNumber,
            'source_page_label' => $this->pageLabel($pageNumber, $pageNumber),
            'recognition_mode' => 'manual_review',
            'recognition_mode_label' => 'Brak rozpoznania',
            'source_type' => 'page_review',
            'page_count' => $pageCount > 0 ? $pageCount : null,
            'invoice_number' => null,
            'issuer_name' => null,
            'amount_due' => null,
            'currency' => null,
            'issue_date' => null,
            'due_date' => null,
            'status_badge_class' => 'warn',
            'status_label' => 'Do rÄ™cznej weryfikacji',
            'note' => $note,
            'text_preview' => $this->preview($pageText),
        ];
    }

    private function buildSourceOnlyDocument(array $file, array $payload, string $type): array
    {
        return [
            'source_file_name' => (string) ($file['name'] ?? ''),
            'source_file_path' => (string) ($file['path'] ?? ''),
            'source_chunk_index' => 1,
            'source_page_number' => 1,
            'source_page_from' => 1,
            'source_page_to' => 1,
            'source_page_label' => $this->pageLabel(1, 1),
            'recognition_mode' => 'source_only',
            'recognition_mode_label' => 'Brak rozpoznania',
            'source_type' => $type === 'scan_like' ? 'scan' : 'error',
            'page_count' => $payload['page_count'] ?? null,
            'invoice_number' => null,
            'issuer_name' => null,
            'amount_due' => null,
            'currency' => null,
            'issue_date' => null,
            'due_date' => null,
            'status_badge_class' => $type === 'scan_like' ? 'warn' : 'error',
            'status_label' => $type === 'scan_like' ? 'Skan / brak tekstu' : 'BĹ‚Ä…d odczytu',
            'note' => (string) ($payload['note'] ?? ''),
            'text_preview' => '',
        ];
    }

    private function buildManualReviewRangeDocument(
        array $file,
        int $pageFrom,
        int $pageTo,
        int $pageCount,
        string $text,
        string $note
    ): array {
        return [
            'source_file_name' => (string) ($file['name'] ?? ''),
            'source_file_path' => (string) ($file['path'] ?? ''),
            'source_chunk_index' => $pageFrom,
            'source_page_number' => $pageFrom,
            'source_page_from' => $pageFrom,
            'source_page_to' => $pageTo,
            'source_page_label' => $this->pageLabel($pageFrom, $pageTo),
            'recognition_mode' => 'ai_whole_pdf',
            'recognition_mode_label' => 'AI (caĹ‚y PDF)',
            'source_type' => 'page_review',
            'page_count' => $pageCount > 0 ? $pageCount : null,
            'invoice_number' => null,
            'issuer_name' => null,
            'amount_due' => null,
            'currency' => null,
            'issue_date' => null,
            'due_date' => null,
            'status_badge_class' => 'warn',
            'status_label' => 'Do rÄ™cznej weryfikacji',
            'note' => $note,
            'text_preview' => $this->preview($text),
            '_ai_used' => true,
        ];
    }

    private function looksLikeInvoicePage(
        string $pageText,
        string $search,
        ?string $invoiceNumber,
        ?string $issuerName,
        ?string $amountDue,
        ?string $issueDate,
        ?string $dueDate
    ): bool {
        if ($invoiceNumber !== null || $issuerName !== null || $amountDue !== null) {
            return true;
        }

        return str_contains($search, 'invoice')
            || str_contains($search, 'faktura')
            || str_contains($search, 'paragon fiskalny')
            || str_contains($search, 'sprzedawca')
            || str_contains($search, 'wystawca')
            || str_contains($search, 'bill to')
            || $issueDate !== null
            || $dueDate !== null;
    }

    private function manualReviewReasons(
        string $chunk,
        ?string $issuerName,
        ?string $invoiceNumber,
        ?string $amountDue,
        ?string $issueDate,
        ?string $dueDate
    ): array {
        $reasons = [];

        if ($issuerName === null || $issuerName === '') {
            $reasons[] = 'brak rozpoznanej nazwy wystawcy';
        } elseif ($this->isSuspiciousIssuerName($issuerName)) {
            $reasons[] = 'nazwa wystawcy wyglÄ…da na bĹ‚Ä™dnie odczytanÄ…';
        }

        if ($invoiceNumber === null || $invoiceNumber === '') {
            $reasons[] = 'brak rozpoznanego numeru dokumentu';
        } elseif ($this->isSuspiciousInvoiceNumber($invoiceNumber)) {
            $reasons[] = 'numer dokumentu wyglÄ…da na bĹ‚Ä™dnie odczytany';
        }

        if ($amountDue === null) {
            $reasons[] = 'brak rozpoznanej kwoty';
        }

        if ($this->looksLikeNarrativeText($chunk, $issuerName, $invoiceNumber, $amountDue, $issueDate, $dueDate)) {
            $reasons[] = 'strona wyglÄ…da bardziej jak opis lub notatka niĹĽ kompletna faktura';
        }

        return array_values(array_unique($reasons));
    }

    private function isSuspiciousIssuerName(string $issuerName): bool
    {
        $trimmed = trim($issuerName);
        if ($trimmed === '') {
            return true;
        }

        $normalized = $this->normalizeForSearch($trimmed);

        if (preg_match('/^(date|invoice|number|total|payment|segment)\b/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/https?:\/\/|www\./i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/[a-zÄ…Ä‡Ä™Ĺ‚Ĺ„ĂłĹ›ĹşĹĽ]/iu', $trimmed) !== 1) {
            return true;
        }

        $digitCount = preg_match_all('/\d/', $trimmed);
        $letterCount = preg_match_all('/[a-zÄ…Ä‡Ä™Ĺ‚Ĺ„ĂłĹ›ĹşĹĽ]/iu', $trimmed);

        return $digitCount !== false
            && $letterCount !== false
            && $digitCount > 0
            && $letterCount > 0
            && $digitCount >= $letterCount;
    }

    private function isSuspiciousInvoiceNumber(string $invoiceNumber): bool
    {
        $normalized = strtoupper(trim($invoiceNumber));

        if ($normalized === '' || mb_strlen($normalized) < 4) {
            return true;
        }

        return in_array($normalized, ['KSEF', 'INVOICE', 'FAKTURA', 'NUMBER'], true);
    }

    private function looksLikeNarrativeText(
        string $chunk,
        ?string $issuerName,
        ?string $invoiceNumber,
        ?string $amountDue,
        ?string $issueDate,
        ?string $dueDate
    ): bool {
        if (($issuerName !== null && !$this->isSuspiciousIssuerName($issuerName)) || $invoiceNumber !== null) {
            return false;
        }

        $normalized = $this->normalizeForSearch($chunk);

        if (preg_match('/https?:\/\/|www\./i', $chunk) === 1) {
            return true;
        }

        if ($amountDue === null && $issueDate === null && $dueDate === null) {
            return true;
        }

        return str_contains($normalized, 'ksef')
            || str_contains($normalized, 'more information')
            || str_contains($normalized, 'project')
            || str_contains($normalized, 'morganizer')
            || str_contains($normalized, 'candidate');
    }

    private function extractInvoiceNumber(string $chunk, string $search): ?string
    {
        $patterns = [
            '/Invoice Number\s+([A-Z0-9\/\-_\.#]+)/i',
            '/Invoice number\s+([A-Z0-9\/\-_\.#]+)/i',
            '/Invoice\s*#\s*([A-Z0-9\/\-_\.]+)/i',
            '/Invoice No\.?\s*([A-Z0-9\/\-_\.]+)/i',
            '/Faktura VAT\s+Nr\s+([A-Z0-9\/\-_\.]+)/iu',
            '/Faktura\s+nr\s+([A-Z0-9\/\-_\.]+)/iu',
            '/FAKTuRA vAT Nn\s+([A-Z0-9\/\-_\.]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $chunk, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        if (preg_match('/faktura vat[^\n]{0,40}\b([a-z0-9\/\-_]{4,})/i', $search, $matches) === 1) {
            return strtoupper(trim((string) ($matches[1] ?? '')));
        }

        return null;
    }

    private function extractIssuerName(string $chunk, string $search): ?string
    {
        if (preg_match('/^\s*(Adobe Systems Software Ireland Ltd)/im', $chunk, $matches) === 1) {
            return $this->cleanIssuer((string) ($matches[1] ?? ''));
        }

        if (preg_match('/^\s*(Easy Composites EU B\.V\.)/im', $chunk, $matches) === 1) {
            return $this->cleanIssuer((string) ($matches[1] ?? ''));
        }

        if (preg_match('/^\s*([A-Z][^\n]{2,120}?)\s+Bill to/ims', $chunk, $matches) === 1) {
            return $this->cleanIssuer((string) ($matches[1] ?? ''));
        }

        if (preg_match('/Sprzedawca\s+([^\n]+?)\s+Nabywca/iu', $chunk, $matches) === 1) {
            return $this->cleanIssuer((string) ($matches[1] ?? ''));
        }

        if (preg_match('/Wystawca\s+Nabywca\s+([A-ZĹĹšĹ»ĹąÄ†ĹĂ“Ä„Ä][^\n]{2,120})/iu', $chunk, $matches) === 1) {
            $value = (string) ($matches[1] ?? '');
            $value = preg_replace('/\s+Artur\b.*$/iu', '', $value) ?? $value;

            return $this->cleanIssuer($value);
        }

        if (preg_match('/^([A-ZĹĹšĹ»ĹąÄ†ĹĂ“Ä„Ä][^\n]{2,120})$/mu', $chunk, $matches) === 1) {
            $line = $this->cleanIssuer((string) ($matches[1] ?? ''));
            if (!$this->isSuspiciousIssuerName($line) && !str_contains($this->normalizeForSearch($line), 'artur dmochowski')) {
                return $line;
            }
        }

        if (preg_match('/Base\/Linker|BaseLinker/i', $search) === 1) {
            return 'BaseLinker';
        }

        if (preg_match('/LH\.pl Sp\. z o\.o\./i', $chunk, $matches) === 1) {
            return $this->cleanIssuer((string) ($matches[0] ?? ''));
        }

        return null;
    }

    private function extractIssueDate(string $chunk, string $search): ?string
    {
        $patterns = [
            '/Invoice Date\s+([0-9]{1,2}-[A-Z]{3}-[0-9]{4})/i',
            '/Date of issue\s+([A-Z][a-z]{2,9}\s+[0-9]{1,2},\s+[0-9]{4})/i',
            '/Date:\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})/i',
            '/Data Wystawienia:\s*([0-9oO]{4}[-\.][0-9oO]{2}[-\.][0-9oO]{2})/iu',
            '/Data wystawienia[:\s]+([0-9oO]{4}[-\.][0-9oO]{2}[-\.][0-9oO]{2})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $chunk, $matches) === 1) {
                $date = $this->normalizeDateString((string) ($matches[1] ?? ''));
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function extractDueDate(string $chunk, string $search): ?string
    {
        $patterns = [
            '/Date due\s+([A-Z][a-z]{2,9}\s+[0-9]{1,2},\s+[0-9]{4})/i',
            '/Termin p[Ĺ‚l]atno[Ĺ›s]ci\s+([0-9oO]{4}[-\.][0-9oO]{2}[-\.][0-9oO]{2})/iu',
            '/Payment Due\s+([0-9]{1,2}-[A-Z]{3}-[0-9]{4})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $chunk, $matches) === 1) {
                $date = $this->normalizeDateString((string) ($matches[1] ?? ''));
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function extractAmountDue(string $chunk, string $search): ?string
    {
        $patterns = [
            '/Amount due\s+[$â‚¬]?\s*([0-9][0-9\., ]{0,20})\s*(USD|EUR|PLN)?/i',
            '/TOTAL\s+[$â‚¬]?\s*([0-9][0-9\., ]{0,20})\s*(USD|EUR|PLN)?/i',
            '/Total\s+[$â‚¬]?\s*([0-9][0-9\., ]{0,20})\s*(USD|EUR|PLN)?/i',
            '/Do zap[Ĺ‚l]aty\s+([0-9][0-9\., ]{0,20})\s*(PLN|USD|EUR)?/iu',
            '/WARTO[ĹšS]C\s+BRUTTO\s+([0-9lI\., ]+)\s*z[Ĺ‚l]/iu',
            '/POZOSTA[ĹL]O DO ZAP[ĹL]ATY\s+([0-9lI\., ]+)\s*z[Ĺ‚l]/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $chunk, $matches) === 1) {
                $amount = $this->normalizeAmount((string) ($matches[1] ?? ''));
                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    private function extractCurrency(string $chunk, string $search, ?string $amountDue): ?string
    {
        if ($amountDue === null) {
            return null;
        }

        foreach (['USD', 'EUR', 'PLN'] as $currency) {
            if (stripos($chunk, $currency) !== false) {
                return $currency;
            }
        }

        if (preg_match('/z[Ĺ‚l]/iu', $chunk) === 1) {
            return 'PLN';
        }

        if (str_contains($search, '$')) {
            return 'USD';
        }

        if (str_contains($search, 'â‚¬') || str_contains($search, 'eur')) {
            return 'EUR';
        }

        return 'PLN';
    }

    private function detectSourceType(string $search): string
    {
        if (str_contains($search, 'paragon fiskalny')) {
            return 'receipt';
        }

        if (str_contains($search, 'faktura')) {
            return 'faktura';
        }

        if (str_contains($search, 'invoice')) {
            return 'invoice';
        }

        return 'document';
    }

    private function normalizeForSearch(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (!is_string($ascii) || $ascii === '') {
            $ascii = $value;
        }

        $ascii = str_replace("\0", '', $ascii);
        $normalized = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        return strtolower($normalized);
    }

    private function normalizeAmount(string $value): ?string
    {
        $normalized = str_replace([' ', "\xc2\xa0", 'o', 'O', 'l', 'I'], ['', '', '0', '0', '1', '1'], trim($value));
        $normalized = preg_replace('/[^0-9,.\-]/', '', $normalized) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if ((int) strrpos($normalized, ',') > (int) strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeDateString(string $value): ?string
    {
        $clean = str_replace(['o', 'O'], '0', trim($value));
        $formats = ['F j, Y', 'M j, Y', 'd-M-Y', 'Y-m-d', 'Y.m.d', 'j/n/Y', 'd/m/Y'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $clean);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function cleanIssuer(string $value): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        $clean = str_replace("\0", '', $clean);

        return trim($clean);
    }

    private function preview(string $text): string
    {
        $singleLine = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return mb_substr($singleLine, 0, 280);
    }

    private function documentFingerprint(array $document): ?string
    {
        $invoiceNumber = trim((string) ($document['invoice_number'] ?? ''));
        $issuerName = trim((string) ($document['issuer_name'] ?? ''));

        $parts = [
            trim((string) ($document['source_file_name'] ?? '')),
            $invoiceNumber,
            $issuerName,
            trim((string) ($document['amount_due'] ?? '')),
            trim((string) ($document['gross_amount'] ?? '')),
            trim((string) ($document['issue_date'] ?? '')),
            trim((string) ($document['due_date'] ?? '')),
        ];

        if ($invoiceNumber === '' || $issuerName === '') {
            $parts[] = trim((string) ($document['source_page_label'] ?? $document['source_page_number'] ?? $document['source_chunk_index'] ?? ''));
        }

        if (implode('', $parts) === '') {
            return null;
        }

        return sha1(implode('|', $parts));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $lower = strtolower($trimmed);
        if (in_array($lower, ['null', 'brak', 'nieznane', 'unknown', 'n/a'], true)) {
            return null;
        }

        return $trimmed;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $currency = $this->normalizeNullableString($value);
        if ($currency === null) {
            return null;
        }

        $currency = strtoupper($currency);

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }

    private function normalizeAiDate(mixed $value): ?string
    {
        $date = $this->normalizeNullableString($value);
        if ($date === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return $this->normalizeDateString($date);
    }

    private function normalizeAiSourceType(string $value): string
    {
        return match (strtolower(trim($value))) {
            'invoice', 'faktura' => 'invoice',
            'receipt', 'paragon' => 'receipt',
            'payment_confirmation', 'confirmation', 'bank_confirmation' => 'payment_confirmation',
            default => 'other',
        };
    }

    private function normalizePageNumber(mixed $value, int $pageCount, ?int $fallback = null): ?int
    {
        if (is_numeric($value)) {
            $page = (int) $value;
        } elseif ($fallback !== null) {
            $page = $fallback;
        } else {
            return null;
        }

        if ($page < 1) {
            $page = 1;
        }

        if ($pageCount > 0 && $page > $pageCount) {
            $page = $pageCount;
        }

        return $page;
    }

    private function pageLabel(int $pageFrom, int $pageTo): string
    {
        return $pageFrom === $pageTo ? (string) $pageFrom : ($pageFrom . '-' . $pageTo);
    }

    private function combinedPreview(array $pageTexts, int $pageFrom, int $pageTo): string
    {
        $selected = [];

        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $text = trim((string) ($pageTexts[$page - 1] ?? ''));
            if ($text !== '') {
                $selected[] = $text;
            }
        }

        return $this->preview(implode("\n", $selected));
    }

    private function shouldRetryManualReviewRange(int $pageFrom, int $pageTo, int $retryDepth): bool
    {
        if ($retryDepth >= self::MAX_MANUAL_REVIEW_RETRY_DEPTH) {
            return false;
        }

        return ($pageTo - $pageFrom + 1) > 1;
    }

    private function retryManualReviewRange(
        array $pageTexts,
        array $pageImages,
        array $file,
        int $pageFrom,
        int $pageTo,
        int $pageOffset,
        int $fullPageCount,
        int $retryDepth
    ): array {
        $documents = [];
        $chunkSize = $retryDepth === 0 ? self::MANUAL_REVIEW_RETRY_CHUNK_SIZE : 1;

        for ($chunkStart = $pageFrom; $chunkStart <= $pageTo; $chunkStart += $chunkSize) {
            $chunkEnd = min($pageTo, $chunkStart + $chunkSize - 1);
            $chunkLength = $chunkEnd - $chunkStart + 1;

            foreach ($this->parseWholePdf(
                array_slice($pageTexts, $chunkStart - 1, $chunkLength),
                array_slice($pageImages, $chunkStart - 1, $chunkLength),
                $file,
                $chunkLength,
                $pageOffset + $chunkStart - 1,
                $fullPageCount,
                $retryDepth + 1
            ) as $document) {
                $documents[] = $document;
            }
        }

        if ($documents !== []) {
            return $documents;
        }

        $absoluteFrom = $pageOffset + $pageFrom;
        $absoluteTo = $pageOffset + $pageTo;

        return [
            $this->buildManualReviewRangeDocument(
                $file,
                $absoluteFrom,
                $absoluteTo,
                $fullPageCount,
                $this->combinedPreview($pageTexts, $pageFrom, $pageTo),
                'Zakres stron ' . $this->pageLabel($absoluteFrom, $absoluteTo) . ' z PDF '
                . (string) ($file['name'] ?? '') . ' wymaga recznej weryfikacji po ponownej analizie AI.'
            ),
        ];
    }

    private function offsetDocumentPages(array $document, int $pageOffset, int $fullPageCount): array
    {
        $pageFrom = (int) ($document['source_page_from'] ?? 0);
        $pageTo = (int) ($document['source_page_to'] ?? $pageFrom);
        $absoluteFrom = $pageFrom > 0 ? $pageOffset + $pageFrom : $pageFrom;
        $absoluteTo = $pageTo > 0 ? $pageOffset + $pageTo : $pageTo;

        return array_replace($document, [
            'source_chunk_index' => $absoluteFrom,
            'source_page_number' => $absoluteFrom,
            'source_page_from' => $absoluteFrom,
            'source_page_to' => $absoluteTo,
            'source_page_label' => $this->pageLabel($absoluteFrom, $absoluteTo),
            'page_count' => $fullPageCount > 0 ? $fullPageCount : ($document['page_count'] ?? null),
        ]);
    }
}

