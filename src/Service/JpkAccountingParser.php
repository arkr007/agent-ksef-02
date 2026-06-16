<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class JpkAccountingParser
{
    public function parse(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Nie udalo sie odczytac pliku JPK XML.');
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Plik JPK jest pusty albo nieczytelny.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw new RuntimeException('Plik nie jest poprawnym XML-em JPK.');
        }

        $xpath = new DOMXPath($document);
        $entries = $this->parsePkpirEntries($xpath);

        if ($entries === []) {
            $entries = array_merge(
                $this->parseVatEntries($xpath, 'SprzedazWiersz', 'sale'),
                $this->parseVatEntries($xpath, 'ZakupWiersz', 'cost')
            );
        }

        if ($entries === []) {
            throw new RuntimeException(
                'Nie znaleziono w pliku JPK wierszy PKPIRWiersz, SprzedazWiersz ani ZakupWiersz.'
            );
        }

        return [
            'raw_text' => $this->normalizePreview($content),
            'entries' => array_values($entries),
        ];
    }

    private function parsePkpirEntries(DOMXPath $xpath): array
    {
        $rows = $xpath->query('//*[local-name()="PKPIRWiersz"]');
        if ($rows === false) {
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }

            $entry = $this->parsePkpirRow($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parsePkpirRow(DOMElement $row): ?array
    {
        $rowLp = $this->childValue($row, 'K_1');
        $eventDate = $this->normalizeDate($this->childValue($row, 'K_2'));
        $documentNumber = $this->firstNonEmpty(
            $this->childValue($row, 'K_3A'),
            $this->childValue($row, 'K_3B')
        );
        $ksefLikeNumber = $this->childValue($row, 'K_3B');
        $contractorName = $this->childValue($row, 'K_5A');
        $contractorAddress = $this->childValue($row, 'K_5B');
        $description = $this->firstNonEmpty(
            $this->childValue($row, 'K_6'),
            $contractorName,
            $documentNumber
        );

        $saleAmount = $this->firstAmount($row, ['K_9', 'K_8', 'K_7']);
        $costAmount = $this->firstAmount($row, ['K_14', 'K_13', 'K_12', 'K_11', 'K_10']);

        $documentType = null;
        $grossAmount = null;

        if ($saleAmount !== null) {
            $documentType = 'sale';
            $grossAmount = $saleAmount;
        }

        if ($costAmount !== null) {
            $documentType = 'cost';
            $grossAmount = $costAmount;
        }

        if ($saleAmount !== null && $costAmount !== null) {
            $documentType = 'cost';
            $grossAmount = $costAmount;
        }

        if ($documentType === null || $grossAmount === null) {
            return null;
        }

        $notes = [];
        if ($ksefLikeNumber !== null && $documentNumber !== null && trim($ksefLikeNumber) !== trim($documentNumber)) {
            $notes[] = 'K_3B: ' . $ksefLikeNumber;
        }

        return [
            'row_lp' => $rowLp,
            'event_date' => $eventDate,
            'document_number' => $documentNumber,
            'contractor_name' => $contractorName,
            'contractor_address' => $contractorAddress,
            'business_event_description' => $description,
            'document_type' => $documentType,
            'gross_amount' => $grossAmount,
            'revenue_amount' => $documentType === 'sale' ? $grossAmount : null,
            'purchase_goods_amount' => $documentType === 'cost' ? $this->decimalOrNull($this->childValue($row, 'K_10')) : null,
            'side_purchase_costs_amount' => $documentType === 'cost' ? $this->decimalOrNull($this->childValue($row, 'K_12')) : null,
            'other_expenses_amount' => $documentType === 'cost' ? $this->firstAmount($row, ['K_13', 'K_12', 'K_11', 'K_10']) : null,
            'total_expenses_amount' => $documentType === 'cost' ? $grossAmount : null,
            'notes' => $notes !== [] ? implode(' | ', $notes) : null,
            'raw_text' => $this->flattenRow($row),
        ];
    }

    private function parseVatEntries(DOMXPath $xpath, string $rowName, string $documentType): array
    {
        $rows = $xpath->query(sprintf('//*[local-name()="%s"]', $rowName));
        if ($rows === false) {
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }

            $entry = $this->parseVatRow($row, $documentType);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseVatRow(DOMElement $row, string $documentType): ?array
    {
        $rowLp = $this->firstNonEmpty(
            $this->childValue($row, 'LpSprzedazy'),
            $this->childValue($row, 'LpZakupu')
        );
        $eventDate = $this->normalizeDate($this->firstNonEmpty(
            $this->childValue($row, 'DataWystawienia'),
            $this->childValue($row, 'DataZakupu'),
            $this->childValue($row, 'DataWpływu'),
            $this->childValue($row, 'DataWplywu')
        ));
        $documentNumber = $this->firstNonEmpty(
            $this->childValue($row, 'DowodSprzedazy'),
            $this->childValue($row, 'NrDostawcy'),
            $this->childValue($row, 'NumerDowoduZakupu')
        );
        $contractorName = $this->firstNonEmpty(
            $this->childValue($row, 'Kontrahent'),
            $this->childValue($row, 'NazwaDostawcy')
        );
        $grossAmount = $this->firstAmount($row, ['K_20', 'K_19', 'K_17', 'K_16', 'K_15', 'K_14', 'K_13', 'K_11', 'K_10']);

        if ($documentNumber === null && $grossAmount === null) {
            return null;
        }

        return [
            'row_lp' => $rowLp,
            'event_date' => $eventDate,
            'document_number' => $documentNumber,
            'contractor_name' => $contractorName,
            'contractor_address' => null,
            'business_event_description' => $contractorName ?? $documentNumber,
            'document_type' => $documentType,
            'gross_amount' => $grossAmount,
            'revenue_amount' => $documentType === 'sale' ? $grossAmount : null,
            'purchase_goods_amount' => null,
            'side_purchase_costs_amount' => null,
            'other_expenses_amount' => $documentType === 'cost' ? $grossAmount : null,
            'total_expenses_amount' => $documentType === 'cost' ? $grossAmount : null,
            'notes' => null,
            'raw_text' => $this->flattenRow($row),
        ];
    }

    private function childValue(DOMElement $row, string $childName): ?string
    {
        foreach ($row->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (!$this->nodeNameMatches((string) $child->localName, $childName)) {
                continue;
            }

            $value = trim($child->textContent);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function firstAmount(DOMElement $row, array $fieldNames): ?string
    {
        foreach ($fieldNames as $fieldName) {
            $amount = $this->decimalOrNull($this->childValue($row, $fieldName));
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function nodeNameMatches(string $actual, string $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        return $this->normalizeNodeName($actual) === $this->normalizeNodeName($expected);
    }

    private function normalizeNodeName(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $ascii) ?? $ascii;

        return strtolower($normalized);
    }

    private function decimalOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(' ', '', trim($value));
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

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $formats = ['Y-m-d', 'Y-m-d\TH:i:s.u', 'Y-m-d\TH:i:s', 'd.m.Y', 'd-m-Y', 'd/m/Y'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function firstNonEmpty(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function flattenRow(DOMElement $row): string
    {
        $parts = [];

        foreach ($row->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $value = trim($child->textContent);
            if ($value === '') {
                continue;
            }

            $parts[] = sprintf('%s=%s', $child->localName, $value);
        }

        return implode(' | ', $parts);
    }

    private function normalizePreview(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;

        return trim($content);
    }
}
