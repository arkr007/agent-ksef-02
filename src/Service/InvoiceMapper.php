<?php

declare(strict_types=1);

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class InvoiceMapper
{
    public function mapFromKsefPayload(array $payload): array
    {
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : $payload;
        $invoiceXml = isset($payload['invoice_xml']) && is_string($payload['invoice_xml']) ? $payload['invoice_xml'] : null;
        $xml = $invoiceXml !== null ? $this->loadXml($invoiceXml) : null;

        $mapped = [
            'ksef_reference_number' => $this->firstString($metadata, [
                ['ksefNumber'],
                ['ksef_reference_number'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'ksefnumber',
                'ksef_reference_number',
            ]),
            'invoice_number' => $this->firstString($metadata, [
                ['invoiceNumber'],
                ['invoiceNo'],
                ['number'],
                ['faNumber'],
                ['invoice_number'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'invoicenumber',
                'invoiceno',
                'fanumber',
                'number',
                'invoice_number',
            ]) ?? $this->xmlFirstValue($xml, ['P_2', 'NumerFaktury', 'InvoiceNumber']),
            'issuer_name' => $this->firstString($metadata, [
                ['seller', 'name'],
                ['sellerName'],
                ['subjectBy', 'name'],
                ['subjectName'],
                ['issuer_name'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'sellername',
                'subjectname',
                'name',
                'fullname',
                'issuer_name',
            ]) ?? $this->xmlFirstValue($xml, ['PelnaNazwa', 'Nazwa', 'SellerName']),
            'issuer_tax_id' => $this->firstString($metadata, [
                ['seller', 'nip'],
                ['sellerNip'],
                ['subjectBy', 'identifier'],
                ['subjectIdentifier'],
                ['issuer_tax_id'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'sellernip',
                'nip',
                'subjectidentifier',
                'identifier',
                'issuer_tax_id',
            ]) ?? $this->xmlFirstValue($xml, ['NIP', 'SellerTaxId']),
            'issue_date' => $this->normalizeDate($this->firstString($metadata, [
                ['issueDate'],
                ['invoiceDate'],
                ['issue_date'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'issuedate',
                'invoicedate',
                'issue_date',
                'dateissued',
            ]) ?? $this->xmlFirstValue($xml, ['P_1', 'DataWystawienia', 'IssueDate'])),
            'sale_date' => $this->normalizeDate($this->xmlFirstValue($xml, [
                'DataSprzedazy',
                'SaleDate',
            ])),
            'due_date' => $this->normalizeDate($this->xmlFirstValue($xml, [
                'Termin',
                'TerminPlatnosci',
                'DataTerminuPlatnosci',
                'DueDate',
            ])),
            'gross_amount' => $this->toDecimal($this->firstValue($metadata, [
                ['grossAmount'],
                ['totalGrossAmount'],
                ['amountGross'],
                ['amounts', 'gross'],
                ['amounts', 'brutto'],
                ['amounts', 'totalGrossAmount'],
                ['gross_amount'],
            ]) ?? $this->findFirstScalarByKey($metadata, [
                'grossamount',
                'totalgrossamount',
                'amountgross',
                'grossvalue',
                'bruttoamount',
                'gross_amount',
                'brutto',
            ])),
            'net_amount' => $this->toDecimal($this->firstValue($metadata, [
                ['netAmount'],
                ['totalNetAmount'],
                ['amountNet'],
                ['net_amount'],
            ]) ?? $this->findFirstScalarByKey($metadata, [
                'netamount',
                'totalnetamount',
                'amountnet',
                'net_amount',
                'netto',
            ])),
            'vat_amount' => $this->toDecimal($this->firstValue($metadata, [
                ['vatAmount'],
                ['totalVatAmount'],
                ['amountVat'],
                ['vat_amount'],
            ]) ?? $this->findFirstScalarByKey($metadata, [
                'vatamount',
                'totalvatamount',
                'amountvat',
                'vat_amount',
                'vat',
            ])),
            'currency' => $this->firstString($metadata, [
                ['currency'],
                ['currencyCode'],
                ['invoiceCurrency'],
                ['amounts', 'currency'],
            ]) ?? $this->findFirstStringByKey($metadata, [
                'currency',
                'currencycode',
                'invoicecurrency',
                'kodwaluty',
            ]) ?? $this->xmlFirstValue($xml, ['KodWaluty', 'Currency']) ?? 'PLN',
            'bank_account' => $this->xmlFirstValue($xml, [
                'NrRB',
                'NrRachunkuBankowego',
                'NumerRachunkuBankowego',
                'NumerRachunku',
                'NRB',
                'Rachunek',
                'RachunekBankowy',
                'IBAN',
            ]),
            'payment_description' => $this->xmlFirstValue($xml, [
                'OpisPlatnosci',
                'TytulPlatnosci',
                'Opis',
                'Tytul',
            ]) ?? $this->buildFallbackDescription($metadata),
            'payment_date' => $this->normalizeDate($this->xmlFirstValue($xml, [
                'DataZaplaty',
                'PaymentDate',
            ])),
            'amount_due' => $this->toDecimal($this->xmlFirstValue($xml, [
                'DoZaplaty',
                'KwotaDoZaplaty',
                'AmountDue',
            ])),
            'raw_payload_json' => json_encode([
                'metadata' => $metadata,
                'invoice_xml' => $invoiceXml,
                'fetch_warning' => $payload['fetch_warning'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $isPaid = $this->xmlBoolean($xml, ['Zaplacono', 'Paid']);
        $mapped['payment_status'] = $isPaid ? 'paid' : (($mapped['amount_due'] ?? null) !== null ? 'to_pay' : 'unknown');

        if (!empty($payload['fetch_warning'])) {
            $mapped['payment_description'] = trim(($mapped['payment_description'] ?? '') . ' [UWAGA: ' . $payload['fetch_warning'] . ']');
        }

        return $mapped;
    }

    private function buildFallbackDescription(array $metadata): ?string
    {
        $invoiceNumber = $this->firstString($metadata, [['invoiceNumber']]);
        if ($invoiceNumber === null) {
            return null;
        }

        return 'Płatność za fakturę ' . $invoiceNumber;
    }

    private function loadXml(string $xml): ?DOMXPath
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        return new DOMXPath($document);
    }

    private function xmlFirstValue(?DOMXPath $xpath, array $localNames): ?string
    {
        if ($xpath === null) {
            return null;
        }

        foreach ($localNames as $localName) {
            $nodeList = $xpath->query('//*[local-name()="' . $localName . '"]');
            if ($nodeList === false || $nodeList->length === 0) {
                continue;
            }

            foreach ($nodeList as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $value = trim($node->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function xmlBoolean(?DOMXPath $xpath, array $localNames): bool
    {
        $value = $this->xmlFirstValue($xpath, $localNames);
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'tak', 'yes'], true);
    }

    private function firstString(array $payload, array $candidatePaths): ?string
    {
        $value = $this->firstValue($payload, $candidatePaths);
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function firstValue(array $payload, array $candidatePaths): mixed
    {
        foreach ($candidatePaths as $path) {
            $current = $payload;
            $found = true;

            foreach ($path as $segment) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    $found = false;
                    break;
                }

                $current = $current[$segment];
            }

            if ($found && $current !== null && $current !== '') {
                return $current;
            }
        }

        return null;
    }

    private function findFirstStringByKey(array $payload, array $normalizedKeys): ?string
    {
        $value = $this->findFirstScalarByKey($payload, $this->normalizeLookupKeys($normalizedKeys));
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function findFirstScalarByKey(array $payload, array $normalizedKeys): mixed
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key);
                if (in_array($normalizedKey, $normalizedKeys, true) && !is_array($value)) {
                    return $value;
                }
            }

            if (!is_array($value)) {
                continue;
            }

            $found = $this->findFirstScalarByKey($value, $normalizedKeys);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    private function normalizeLookupKeys(array $keys): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key),
            $keys
        )));
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $datePart = preg_split('/[T\s]/', $trimmed)[0] ?? $trimmed;
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $datePart);

        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : null;
    }

    private function toDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], (string) $value);
        if (!is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
