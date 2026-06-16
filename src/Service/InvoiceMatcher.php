<?php

declare(strict_types=1);

namespace App\Service;

final class InvoiceMatcher
{
    private const AMOUNT_TOLERANCE_RATIO = 0.01;

    public function match(array $invoices, array $accountingEntries): array
    {
        $preparedInvoices = array_map(fn (array $invoice): array => $this->prepareInvoice($invoice), $invoices);
        $preparedEntries = array_map(fn (array $entry): array => $this->prepareEntry($entry), $accountingEntries);

        $rows = [];

        foreach (['cost', 'sale'] as $documentType) {
            $typeInvoices = array_values(array_filter(
                $preparedInvoices,
                fn (array $invoice): bool => $invoice['document_type'] === $documentType
            ));
            $typeEntries = array_values(array_filter(
                $preparedEntries,
                fn (array $entry): bool => $entry['document_type'] === $documentType
            ));

            $rows = array_merge($rows, $this->matchByType($documentType, $typeInvoices, $typeEntries));
        }

        usort($rows, function (array $left, array $right): int {
            $typeOrder = ['cost' => 0, 'sale' => 1];
            $statusOrder = [
                'BOTH' => 0,
                'NUMBER_MATCH_AMOUNT_DIFF' => 1,
                'AMOUNT_MATCH_NUMBER_DIFF' => 2,
                'ONLY_JPK' => 3,
                'ONLY_KSEF' => 4,
            ];

            $leftType = $typeOrder[$left['document_type'] ?? 'cost'] ?? 99;
            $rightType = $typeOrder[$right['document_type'] ?? 'cost'] ?? 99;
            if ($leftType !== $rightType) {
                return $leftType <=> $rightType;
            }

            $leftStatus = $statusOrder[$left['status'] ?? 'ONLY_JPK'] ?? 99;
            $rightStatus = $statusOrder[$right['status'] ?? 'ONLY_JPK'] ?? 99;
            if ($leftStatus !== $rightStatus) {
                return $leftStatus <=> $rightStatus;
            }

            $leftDate = (string) ($left['event_date'] ?? $left['issue_date'] ?? '');
            $rightDate = (string) ($right['event_date'] ?? $right['issue_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($leftDate, $rightDate);
            }

            $leftNumber = (string) ($left['document_number'] ?? $left['invoice_number'] ?? '');
            $rightNumber = (string) ($right['document_number'] ?? $right['invoice_number'] ?? '');

            return strcmp($leftNumber, $rightNumber);
        });

        return $rows;
    }

    private function matchByType(string $documentType, array $invoices, array $entries): array
    {
        $rows = [];
        $usedInvoiceIds = [];
        $usedEntryIds = [];

        foreach ($entries as $entry) {
            if (isset($usedEntryIds[$entry['match_id']])) {
                continue;
            }

            $candidate = $this->findInvoiceByNumberAndAmount($entry, $invoices, $usedInvoiceIds);
            if ($candidate === null) {
                continue;
            }

            $usedInvoiceIds[$candidate['match_id']] = true;
            $usedEntryIds[$entry['match_id']] = true;
            $rows[] = $this->buildRow(
                'BOTH',
                $documentType,
                $entry['source'],
                $candidate['source'],
                sprintf(
                    'Zgodny typ dokumentu, numer oraz kwota brutto (roznica %.2f%%).',
                    $this->amountRelativeDeltaPercent($entry['gross_amount_norm'] ?? null, $candidate['gross_amount_norm'] ?? null) ?? 0.0
                )
            );
        }

        foreach ($entries as $entry) {
            if (isset($usedEntryIds[$entry['match_id']])) {
                continue;
            }

            $candidate = $this->findInvoiceByNumber($entry, $invoices, $usedInvoiceIds);
            if ($candidate === null) {
                continue;
            }

            $usedInvoiceIds[$candidate['match_id']] = true;
            $usedEntryIds[$entry['match_id']] = true;
            $rows[] = $this->buildRow(
                'NUMBER_MATCH_AMOUNT_DIFF',
                $documentType,
                $entry['source'],
                $candidate['source'],
                sprintf(
                    'Zgodny numer dokumentu, ale inna kwota brutto (%s vs %s).',
                    $entry['gross_amount_norm'] ?? '',
                    $candidate['gross_amount_norm'] ?? ''
                )
            );
        }

        foreach ($entries as $entry) {
            if (isset($usedEntryIds[$entry['match_id']])) {
                continue;
            }

            $candidate = $this->findInvoiceByAmount($entry, $invoices, $usedInvoiceIds);
            if ($candidate === null) {
                continue;
            }

            $usedInvoiceIds[$candidate['match_id']] = true;
            $usedEntryIds[$entry['match_id']] = true;
            $rows[] = $this->buildRow(
                'AMOUNT_MATCH_NUMBER_DIFF',
                $documentType,
                $entry['source'],
                $candidate['source'],
                sprintf(
                    'Zgodna kwota brutto w tolerancji < %.2f%%, ale inny numer dokumentu (%s vs %s).',
                    self::AMOUNT_TOLERANCE_RATIO * 100,
                    (string) ($entry['document_number_norm'] ?? ''),
                    (string) ($candidate['invoice_number_norm'] ?? '')
                )
            );
        }

        foreach ($entries as $entry) {
            if (isset($usedEntryIds[$entry['match_id']])) {
                continue;
            }

            $rows[] = $this->buildRow(
                'ONLY_JPK',
                $documentType,
                $entry['source'],
                null,
                'Wpis z JPK nie ma odpowiednika w swiezo pobranych danych KSeF dla tego typu dokumentu.'
            );
        }

        foreach ($invoices as $invoice) {
            if (isset($usedInvoiceIds[$invoice['match_id']])) {
                continue;
            }

            $rows[] = $this->buildRow(
                'ONLY_KSEF',
                $documentType,
                null,
                $invoice['source'],
                'Faktura z KSeF nie ma odpowiednika w JPK dla tego typu dokumentu.'
            );
        }

        return $rows;
    }

    private function buildRow(
        string $status,
        string $documentType,
        ?array $entry,
        ?array $invoice,
        string $reasoning
    ): array {
        return [
            'status' => $status,
            'document_type' => $documentType,
            'reasoning' => $reasoning,
            'accounting_entry_id' => $entry['id'] ?? null,
            'invoice_id' => $invoice['id'] ?? null,
            'row_lp' => $entry['row_lp'] ?? null,
            'event_date' => $entry['event_date'] ?? null,
            'document_number' => $entry['document_number'] ?? null,
            'contractor_name' => $entry['contractor_name'] ?? null,
            'business_event_description' => $entry['business_event_description'] ?? null,
            'jpk_gross_amount' => $this->entryAmount($entry),
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'issuer_name' => $invoice['issuer_name'] ?? null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'ksef_gross_amount' => $invoice['comparison_gross_amount'] ?? $invoice['gross_amount'] ?? null,
            'ksef_original_gross_amount' => $invoice['original_gross_amount'] ?? $invoice['gross_amount'] ?? null,
            'ksef_original_currency' => $invoice['original_currency'] ?? $invoice['currency'] ?? 'PLN',
            'ksef_exchange_rate' => $invoice['comparison_exchange_rate'] ?? null,
            'ksef_exchange_rate_date' => $invoice['comparison_exchange_rate_date'] ?? null,
            'ksef_exchange_rate_source' => $invoice['comparison_exchange_rate_source'] ?? null,
            'ksef_comparison_warning' => $invoice['comparison_warning'] ?? null,
            'ksef_reference_number' => $invoice['ksef_reference_number'] ?? null,
        ];
    }

    private function prepareInvoice(array $invoice): array
    {
        $documentType = $this->normalizeType((string) ($invoice['document_type'] ?? 'cost'));
        $invoiceNumber = $this->normalizeToken((string) ($invoice['invoice_number'] ?? ''));
        $grossAmount = $this->normalizeDecimal($invoice['comparison_gross_amount'] ?? $invoice['gross_amount'] ?? null);

        return [
            'match_id' => 'invoice:' . (string) ($invoice['id'] ?? spl_object_id((object) $invoice)),
            'document_type' => $documentType,
            'invoice_number_norm' => $invoiceNumber,
            'gross_amount_norm' => $grossAmount,
            'source' => $invoice,
        ];
    }

    private function prepareEntry(array $entry): array
    {
        $documentType = $this->normalizeType((string) ($entry['document_type'] ?? ''));
        if ($documentType === '') {
            $documentType = isset($entry['revenue_amount']) && $entry['revenue_amount'] !== null && $entry['revenue_amount'] !== ''
                ? 'sale'
                : 'cost';
        }

        $documentNumber = $this->normalizeToken((string) ($entry['document_number'] ?? ''));
        $grossAmount = $this->normalizeDecimal($this->entryAmount($entry));

        return [
            'match_id' => 'entry:' . (string) ($entry['id'] ?? spl_object_id((object) $entry)),
            'document_type' => $documentType,
            'document_number_norm' => $documentNumber,
            'gross_amount_norm' => $grossAmount,
            'source' => $entry,
        ];
    }

    private function findInvoiceByNumberAndAmount(array $entry, array $invoices, array $usedInvoiceIds): ?array
    {
        if (($entry['document_number_norm'] ?? '') === '' || ($entry['gross_amount_norm'] ?? '') === '') {
            return null;
        }

        $best = null;
        $bestDelta = null;

        foreach ($invoices as $invoice) {
            if (isset($usedInvoiceIds[$invoice['match_id']])) {
                continue;
            }

            if (($invoice['invoice_number_norm'] ?? '') !== ($entry['document_number_norm'] ?? '')) {
                continue;
            }

            if (!$this->amountWithinTolerance(
                $entry['gross_amount_norm'] ?? null,
                $invoice['gross_amount_norm'] ?? null
            )) {
                continue;
            }

            $delta = $this->amountDelta($entry['gross_amount_norm'] ?? null, $invoice['gross_amount_norm'] ?? null);
            if ($best === null || $delta < $bestDelta) {
                $best = $invoice;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function findInvoiceByNumber(array $entry, array $invoices, array $usedInvoiceIds): ?array
    {
        if (($entry['document_number_norm'] ?? '') === '') {
            return null;
        }

        $best = null;
        $bestDelta = null;

        foreach ($invoices as $invoice) {
            if (isset($usedInvoiceIds[$invoice['match_id']])) {
                continue;
            }

            if (($invoice['invoice_number_norm'] ?? '') !== ($entry['document_number_norm'] ?? '')) {
                continue;
            }

            $delta = $this->amountDelta($entry['gross_amount_norm'] ?? null, $invoice['gross_amount_norm'] ?? null);
            if ($delta === null || $this->amountWithinTolerance(
                $entry['gross_amount_norm'] ?? null,
                $invoice['gross_amount_norm'] ?? null
            )) {
                continue;
            }

            if ($best === null || $delta < $bestDelta) {
                $best = $invoice;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function findInvoiceByAmount(array $entry, array $invoices, array $usedInvoiceIds): ?array
    {
        if (($entry['gross_amount_norm'] ?? '') === '') {
            return null;
        }

        foreach ($invoices as $invoice) {
            if (isset($usedInvoiceIds[$invoice['match_id']])) {
                continue;
            }

            if (!$this->amountWithinTolerance(
                $entry['gross_amount_norm'] ?? null,
                $invoice['gross_amount_norm'] ?? null
            )) {
                continue;
            }

            if (($invoice['invoice_number_norm'] ?? '') === ($entry['document_number_norm'] ?? '')) {
                continue;
            }

            return $invoice;
        }

        return null;
    }

    private function normalizeType(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'sale', 'sprzedaz', 'przychod' => 'sale',
            'cost', 'koszt', 'zakup' => 'cost',
            default => $normalized,
        };
    }

    private function entryAmount(?array $entry): ?string
    {
        if ($entry === null) {
            return null;
        }

        foreach ([
            'gross_amount',
            'revenue_amount',
            'total_expenses_amount',
            'other_expenses_amount',
            'side_purchase_costs_amount',
            'purchase_goods_amount',
        ] as $key) {
            if (!isset($entry[$key]) || $entry[$key] === null || $entry[$key] === '') {
                continue;
            }

            return $this->normalizeDecimal($entry[$key]);
        }

        return null;
    }

    private function normalizeToken(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
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

    private function amountDelta(?string $left, ?string $right): ?float
    {
        if ($left === null || $right === null || $left === '' || $right === '') {
            return null;
        }

        return abs((float) $left - (float) $right);
    }

    private function amountWithinTolerance(?string $left, ?string $right): bool
    {
        $delta = $this->amountDelta($left, $right);
        if ($delta === null) {
            return false;
        }

        $baseline = abs((float) $left);
        if ($baseline <= 0.0) {
            $baseline = abs((float) $right);
        }

        if ($baseline <= 0.0) {
            return $delta === 0.0;
        }

        return ($delta / $baseline) < self::AMOUNT_TOLERANCE_RATIO;
    }

    private function amountRelativeDeltaPercent(?string $left, ?string $right): ?float
    {
        $delta = $this->amountDelta($left, $right);
        if ($delta === null) {
            return null;
        }

        $baseline = abs((float) $left);
        if ($baseline <= 0.0) {
            $baseline = abs((float) $right);
        }

        if ($baseline <= 0.0) {
            return $delta === 0.0 ? 0.0 : 100.0;
        }

        return ($delta / $baseline) * 100;
    }
}
