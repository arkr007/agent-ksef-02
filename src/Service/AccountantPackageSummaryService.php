<?php

declare(strict_types=1);

namespace App\Service;

final class AccountantPackageSummaryService
{
    public function build(array $catalog, array $ksefPackage, ?array $pdfCandidatesPackage = null): array
    {
        $issuers = array_values((array) ($catalog['issuers'] ?? []));
        $ksefDocuments = $this->buildKsefDocuments((array) ($ksefPackage['invoices'] ?? []));
        $pdfDocuments = $this->buildPdfDocuments((array) ($pdfCandidatesPackage['documents'] ?? []));

        $matchedByIssuer = [];
        $otherDocuments = [];
        $manualReviewDocuments = [];
        $pdfIncludedCount = 0;
        $pdfDuplicateCount = 0;

        foreach ($ksefDocuments as $document) {
            $match = $this->matchDocumentToIssuer($document, $issuers);
            if ($match === null) {
                $otherDocuments[] = $document + [
                    'match_note' => 'Brak dopasowania do listy stałych wystawców.',
                ];
                continue;
            }

            $this->attachMatchedDocument($matchedByIssuer, $issuers, $match['issuer_index'], $document + [
                'match_score' => $match['score'],
                'match_note' => $match['note'],
            ]);
        }

        foreach ($pdfDocuments as $document) {
            if (($document['status_badge_class'] ?? 'warn') !== 'ok') {
                $manualReviewDocuments[] = $document + [
                    'match_note' => (string) ($document['note'] ?? 'Dokument PDF wymaga ręcznej weryfikacji.'),
                ];
                continue;
            }

            $duplicateKsefDocument = $this->findKsefDuplicate($document, $ksefDocuments);
            if ($duplicateKsefDocument !== null) {
                $pdfDuplicateCount++;
                continue;
            }

            $match = $this->matchDocumentToIssuer($document, $issuers);
            if ($match === null) {
                $otherDocuments[] = $document + [
                    'match_note' => 'Dokument PDF nie pasuje do listy stałych wystawców.',
                ];
                $pdfIncludedCount++;
                continue;
            }

            $this->attachMatchedDocument($matchedByIssuer, $issuers, $match['issuer_index'], $document + [
                'match_score' => $match['score'],
                'match_note' => $match['note'],
            ]);
            $pdfIncludedCount++;
        }

        $recurringRows = [];
        $completeCount = 0;
        $missingCount = 0;
        $matchedDocumentCount = 0;

        foreach ($issuers as $issuer) {
            $position = (int) ($issuer['position'] ?? 0);
            $expectedCount = (int) ($issuer['expected_invoice_count'] ?? 0);
            $matchedDocuments = $matchedByIssuer[$position] ?? [];
            usort($matchedDocuments, [$this, 'compareDocuments']);

            $matchedCount = count($matchedDocuments);
            $matchedDocumentCount += $matchedCount;
            $missingDocuments = max(0, $expectedCount - $matchedCount);
            $surplusDocuments = max(0, $matchedCount - $expectedCount);
            $isComplete = $matchedCount >= $expectedCount;

            if ($isComplete) {
                $completeCount++;
            } else {
                $missingCount++;
            }

            $recurringRows[] = [
                'position' => $position,
                'issuer_name' => (string) ($issuer['issuer_name'] ?? ''),
                'expected_invoice_count' => $expectedCount,
                'matched_invoice_count' => $matchedCount,
                'missing_invoice_count' => $missingDocuments,
                'surplus_invoice_count' => $surplusDocuments,
                'status_badge_class' => $isComplete ? 'ok' : 'error',
                'status_label' => $isComplete ? 'Komplet' : 'Braki',
                'matched_invoices' => $matchedDocuments,
            ];
        }

        usort($otherDocuments, [$this, 'compareDocuments']);
        $manualReviewDocuments = $this->deduplicateManualReviewDocuments($manualReviewDocuments);
        usort($manualReviewDocuments, [$this, 'compareDocuments']);

        return [
            'recurring_rows' => $recurringRows,
            'other_invoices' => array_values(array_map(
                fn (array $document, int $index): array => array_replace($document, ['position' => $index + 1]),
                $otherDocuments,
                array_keys($otherDocuments)
            )),
            'manual_review_documents' => array_values(array_map(
                fn (array $document, int $index): array => array_replace($document, ['position' => $index + 1]),
                $manualReviewDocuments,
                array_keys($manualReviewDocuments)
            )),
            'summary' => [
                'recurring_issuer_count' => count($recurringRows),
                'complete_issuer_count' => $completeCount,
                'missing_issuer_count' => $missingCount,
                'matched_invoice_count' => $matchedDocumentCount,
                'other_invoice_count' => count($otherDocuments),
                'ksef_document_count' => count($ksefDocuments),
                'pdf_document_count' => $pdfIncludedCount,
                'pdf_duplicate_count' => $pdfDuplicateCount,
                'manual_review_count' => count($manualReviewDocuments),
            ],
        ];
    }

    private function buildKsefDocuments(array $invoices): array
    {
        $documents = [];

        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }

            $documents[] = $this->decorateDocument($invoice, 'ksef', [
                'source_label' => 'KSeF',
                'source_badge_class' => 'ok',
                'source_reference' => (string) ($invoice['ksef_reference_number'] ?? ''),
            ]);
        }

        return $documents;
    }

    private function buildPdfDocuments(array $documents): array
    {
        $normalized = [];

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $normalized[] = $this->decorateDocument($document, 'pdf', [
                'source_label' => 'PDF',
                'source_badge_class' => (string) ($document['status_badge_class'] ?? 'warn'),
                'source_reference' => trim(sprintf(
                    '%s / strona %s',
                    (string) ($document['source_file_name'] ?? ''),
                    (string) ($document['source_page_number'] ?? $document['source_chunk_index'] ?? 1)
                )),
            ]);
        }

        return $normalized;
    }

    private function decorateDocument(array $document, string $sourceKind, array $overrides = []): array
    {
        return array_replace($document, [
            'source_kind' => $sourceKind,
            'source_label' => strtoupper($sourceKind),
            'source_badge_class' => $sourceKind === 'ksef' ? 'ok' : 'warn',
            'source_reference' => '',
            'formatted_amount_due' => $this->formatMoney(
                $document['amount_due'] ?? $document['gross_amount'] ?? null,
                (string) ($document['currency'] ?? 'PLN')
            ),
            'formatted_gross_amount' => $this->formatMoney(
                $document['gross_amount'] ?? null,
                (string) ($document['currency'] ?? 'PLN')
            ),
        ], $overrides);
    }

    private function attachMatchedDocument(array &$matchedByIssuer, array $issuers, int $issuerIndex, array $document): void
    {
        $issuerPosition = (int) ($issuers[$issuerIndex]['position'] ?? ($issuerIndex + 1));
        if (!isset($matchedByIssuer[$issuerPosition])) {
            $matchedByIssuer[$issuerPosition] = [];
        }

        $matchedByIssuer[$issuerPosition][] = $document;
    }

    private function matchDocumentToIssuer(array $document, array $issuers): ?array
    {
        $documentName = (string) ($document['issuer_name'] ?? '');
        if (trim($documentName) === '') {
            return null;
        }

        $documentAlias = $this->buildAliasData($documentName);
        $bestMatch = null;

        foreach ($issuers as $index => $issuer) {
            $issuerName = (string) ($issuer['issuer_name'] ?? '');
            if (trim($issuerName) === '') {
                continue;
            }

            $issuerAlias = $this->buildAliasData($issuerName);
            $score = $this->scoreAliasMatch($issuerAlias, $documentAlias);
            if ($score < 70) {
                continue;
            }

            $candidate = [
                'issuer_index' => $index,
                'score' => $score,
                'note' => $this->describeMatch($score, $issuerName, $documentName),
            ];

            if ($bestMatch === null || $candidate['score'] > $bestMatch['score']) {
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    private function findKsefDuplicate(array $pdfDocument, array $ksefDocuments): ?array
    {
        $pdfInvoiceNumber = $this->normalizeDocumentNumber((string) ($pdfDocument['invoice_number'] ?? ''));
        if ($pdfInvoiceNumber === '') {
            return null;
        }

        $pdfCurrency = strtoupper(trim((string) ($pdfDocument['currency'] ?? '')));
        $pdfAlias = $this->buildAliasData((string) ($pdfDocument['issuer_name'] ?? ''));

        foreach ($ksefDocuments as $ksefDocument) {
            $ksefInvoiceNumber = $this->normalizeDocumentNumber((string) ($ksefDocument['invoice_number'] ?? ''));
            if ($ksefInvoiceNumber === '' || $ksefInvoiceNumber !== $pdfInvoiceNumber) {
                continue;
            }

            $ksefCurrency = strtoupper(trim((string) ($ksefDocument['currency'] ?? '')));
            if ($pdfCurrency !== '' && $ksefCurrency !== '' && $pdfCurrency !== $ksefCurrency) {
                continue;
            }

            $issuerScore = $this->scoreAliasMatch(
                $this->buildAliasData((string) ($ksefDocument['issuer_name'] ?? '')),
                $pdfAlias
            );
            $amountMatches = $this->documentsHaveMatchingAmounts($pdfDocument, $ksefDocument);

            if ($amountMatches || $issuerScore >= 80) {
                return $ksefDocument;
            }
        }

        return null;
    }

    private function documentsHaveMatchingAmounts(array $left, array $right): bool
    {
        $leftAmounts = $this->extractComparableAmounts($left);
        $rightAmounts = $this->extractComparableAmounts($right);

        foreach ($leftAmounts as $leftAmount) {
            foreach ($rightAmounts as $rightAmount) {
                if (abs($leftAmount - $rightAmount) < 0.01) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractComparableAmounts(array $document): array
    {
        $values = [];

        foreach (['amount_due', 'gross_amount'] as $key) {
            $value = $document[$key] ?? null;
            if ($value === null || $value === '' || !is_numeric((string) $value)) {
                continue;
            }

            $values[] = round((float) $value, 2);
        }

        return array_values(array_unique($values));
    }

    private function deduplicateManualReviewDocuments(array $documents): array
    {
        $seen = [];
        $result = [];

        foreach ($documents as $document) {
            $fingerprint = sha1(implode('|', [
                (string) ($document['source_file_name'] ?? ''),
                (string) ($document['source_chunk_index'] ?? ''),
                (string) ($document['invoice_number'] ?? ''),
                (string) ($document['issuer_name'] ?? ''),
                (string) ($document['amount_due'] ?? ''),
            ]));

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $result[] = $document;
        }

        return $result;
    }

    private function compareDocuments(array $left, array $right): int
    {
        $leftDate = (string) ($left['issue_date'] ?? $left['due_date'] ?? '');
        $rightDate = (string) ($right['issue_date'] ?? $right['due_date'] ?? '');

        return strcmp($leftDate, $rightDate)
            ?: strcasecmp((string) ($left['issuer_name'] ?? ''), (string) ($right['issuer_name'] ?? ''))
            ?: strcasecmp((string) ($left['invoice_number'] ?? ''), (string) ($right['invoice_number'] ?? ''));
    }

    private function buildAliasData(string $value): array
    {
        $prepared = preg_replace('/([a-z])([A-Z])/', '$1 $2', trim($value)) ?? trim($value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $prepared);
        if (!is_string($ascii) || $ascii === '') {
            $ascii = $prepared;
        }

        $lower = strtolower($ascii);
        $words = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? $lower;
        $compact = preg_replace('/[^a-z0-9]+/', '', $lower) ?? $lower;

        $tokens = array_values(array_filter(
            explode(' ', trim($words)),
            fn (string $token): bool => $token !== '' && !in_array($token, $this->stopWords(), true)
        ));

        return [
            'compact' => $compact,
            'tokens' => array_values(array_unique($tokens)),
        ];
    }

    private function normalizeDocumentNumber(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
        if (!is_string($ascii) || $ascii === '') {
            $ascii = trim($value);
        }

        return strtoupper(preg_replace('/[^A-Z0-9]+/', '', $ascii) ?? '');
    }

    private function scoreAliasMatch(array $issuerAlias, array $documentAlias): int
    {
        $issuerCompact = (string) ($issuerAlias['compact'] ?? '');
        $documentCompact = (string) ($documentAlias['compact'] ?? '');
        $issuerTokens = (array) ($issuerAlias['tokens'] ?? []);
        $documentTokens = (array) ($documentAlias['tokens'] ?? []);
        $intersection = array_values(array_intersect($issuerTokens, $documentTokens));
        $score = 0;

        if ($issuerCompact !== '' && $issuerCompact === $documentCompact) {
            return 100;
        }

        if ($issuerCompact !== '' && $documentCompact !== '') {
            if (str_contains($documentCompact, $issuerCompact) || str_contains($issuerCompact, $documentCompact)) {
                $score = max($score, 92);
            }
        }

        if ($intersection !== []) {
            $coverage = count($intersection) / max(1, count($issuerTokens));
            $longest = max(array_map('strlen', $intersection));

            if ($coverage >= 1.0) {
                $score = max($score, 88);
            } elseif ($coverage >= 0.5 && count($intersection) >= 2) {
                $score = max($score, 80);
            } elseif ($longest >= 5) {
                $score = max($score, 74);
            }
        }

        if (
            count($intersection) === 1
            && count($issuerTokens) > 1
            && count($documentTokens) > 1
            && $score < 92
        ) {
            return 0;
        }

        if ($issuerCompact !== '' && $documentCompact !== '') {
            similar_text($issuerCompact, $documentCompact, $percent);
            if ($percent >= 78.0) {
                $score = max($score, (int) round($percent));
            }
        }

        if (
            count($intersection) === 1
            && count($issuerTokens) > 1
            && count($documentTokens) > 1
            && $score < 92
        ) {
            return 0;
        }

        return $score;
    }

    private function formatMoney(mixed $value, string $currency = 'PLN'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric((string) $value)) {
            return trim((string) $value . ' ' . $currency);
        }

        return trim(number_format((float) $value, 2, ',', ' ') . ' ' . $currency);
    }

    private function describeMatch(int $score, string $issuerName, string $documentIssuerName): string
    {
        if ($score >= 92) {
            return 'Dopasowanie nazwy: bardzo mocne (' . $issuerName . ' <- ' . $documentIssuerName . ').';
        }

        if ($score >= 80) {
            return 'Dopasowanie nazwy: mocne (' . $issuerName . ' <- ' . $documentIssuerName . ').';
        }

        return 'Dopasowanie nazwy: przybliżone (' . $issuerName . ' <- ' . $documentIssuerName . ').';
    }

    private function stopWords(): array
    {
        return [
            'sa',
            'sp',
            'z',
            'o',
            'oo',
            'spolka',
            'akcyjna',
            'limited',
            'ltd',
            'company',
            'co',
            'operations',
            'pl',
        ];
    }
}
