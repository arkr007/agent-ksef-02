<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final class Validators
{
    public function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), ['PLN', 'EUR', 'USD'], true);
    }

    public function isValidNip(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $digits[$index]) * $weight;
        }

        return $sum % 11 === (int) $digits[9];
    }

    public function isAllowedKsefEnvironment(string $environment, array $available): bool
    {
        return in_array($environment, $available, true);
    }

    public function isValidIbanOrNrb(string $value): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^\d{26}$/', $normalized) === 1) {
            $normalized = 'PL' . $normalized;
        }

        if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $normalized) !== 1) {
            return false;
        }

        $rearranged = substr($normalized, 4) . substr($normalized, 0, 4);
        $converted = '';
        $length = strlen($rearranged);

        for ($index = 0; $index < $length; $index++) {
            $char = $rearranged[$index];
            $converted .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        $convertedLength = strlen($converted);
        for ($index = 0; $index < $convertedLength; $index++) {
            $remainder = ($remainder * 10 + (int) $converted[$index]) % 97;
        }

        return $remainder === 1;
    }

    public function maskSecretPresence(bool $present): string
    {
        return $present ? 'ustawione' : 'brak';
    }

    public function isValidIsoDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    public function validateDateRange(string $dateFrom, string $dateTo, int $maxDays = 93): array
    {
        $errors = [];

        if (!$this->isValidIsoDate($dateFrom) || !$this->isValidIsoDate($dateTo)) {
            $errors[] = 'Zakres dat musi byc podany w formacie RRRR-MM-DD.';

            return $errors;
        }

        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);

        if ($from > $to) {
            $errors[] = 'Data od nie moze byc pozniejsza niz data do.';
        }

        $days = (int) $from->diff($to)->days;
        if ($days > $maxDays) {
            $errors[] = sprintf('Jedno pobranie moze obejmowac maksymalnie %d dni.', $maxDays);
        }

        return $errors;
    }

    public function normalizeBankAccount(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return null;
        }

        $compact = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';

        if (preg_match('/PL\d{26}/', $compact, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\d{26}/', $compact, $matches) === 1) {
            return $matches[0];
        }

        return $compact === '' ? null : $compact;
    }

    public function formatBankAccount(?string $value): string
    {
        $normalized = $this->normalizeBankAccount($value);
        if ($normalized === null) {
            return '';
        }

        return trim((string) preg_replace('/(.{4})/', '$1 ', $normalized));
    }

    public function validateFetchedInvoice(array $invoice): array
    {
        $warnings = [];
        $errors = [];
        $manualReview = [];
        $paymentStatus = (string) ($invoice['payment_status'] ?? 'unknown');
        $isPaid = $paymentStatus === 'paid';

        $grossAmount = isset($invoice['gross_amount']) && $invoice['gross_amount'] !== null
            ? (float) $invoice['gross_amount']
            : null;

        $bankAccount = $this->normalizeBankAccount($invoice['bank_account'] ?? null);
        if (!$isPaid && $bankAccount === null) {
            $warnings[] = 'Brak rachunku bankowego na fakturze.';
        } elseif (!$isPaid && $bankAccount !== null && !$this->isValidIbanOrNrb($bankAccount)) {
            $errors[] = 'Rachunek bankowy ma niepoprawny format NRB/IBAN.';
        }

        if (!$isPaid && ($invoice['due_date'] ?? null) === null) {
            $warnings[] = 'Brak terminu platnosci.';
        }

        if (($invoice['invoice_number'] ?? null) === null) {
            $warnings[] = 'Brak numeru faktury.';
        }

        if (($invoice['issuer_name'] ?? null) === null) {
            $warnings[] = 'Brak nazwy wystawcy.';
        }

        if ($grossAmount === null) {
            $warnings[] = 'Brak kwoty brutto.';
        } elseif ($grossAmount < 0) {
            $manualReview[] = 'Kwota brutto jest ujemna - sprawdz, czy to korekta.';
        } elseif ($grossAmount === 0.0) {
            $warnings[] = 'Kwota brutto wynosi 0,00.';
        }

        $description = strtolower(trim((string) ($invoice['payment_description'] ?? '')));
        $invoiceNumber = strtolower(trim((string) ($invoice['invoice_number'] ?? '')));
        if (str_contains($description, 'korekt') || str_contains($invoiceNumber, 'korekt')) {
            $manualReview[] = 'Dokument wyglada na korekte i wymaga recznej weryfikacji.';
        }

        if ($isPaid && ($invoice['payment_date'] ?? null) !== null) {
            $warnings = array_values(array_filter(
                $warnings,
                static fn (string $warning): bool => !in_array($warning, [
                    'Brak rachunku bankowego na fakturze.',
                    'Brak terminu platnosci.',
                ], true)
            ));
        }

        $status = 'ok';
        if ($errors !== []) {
            $status = 'error';
        } elseif ($manualReview !== []) {
            $status = 'manual_review';
        } elseif ($warnings !== []) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'notes' => implode(' ', array_merge($errors, $manualReview, $warnings)),
            'warning_count' => count($warnings) + count($manualReview),
            'error_count' => count($errors),
            'bank_account' => $bankAccount,
        ];
    }
}
