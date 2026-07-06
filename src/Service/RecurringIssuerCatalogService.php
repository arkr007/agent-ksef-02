<?php

declare(strict_types=1);

namespace App\Service;

final class RecurringIssuerCatalogService
{
    public function __construct(
        private ApplicationSettings $applicationSettings
    ) {
    }

    public function loadCatalog(): array
    {
        $sourceDirectory = $this->expectedFolderPath();
        $csvPath = $this->expectedCsvPath();
        $csvDirectory = dirname($csvPath);

        clearstatcache(true, $csvPath);

        if ($sourceDirectory === '') {
            throw new \RuntimeException('Nie ustawiono katalogu z lokalnymi dokumentami PDF.');
        }

        if ($csvPath === '') {
            throw new \RuntimeException('Nie ustawiono sciezki pliku listy stalych wystawcow.');
        }

        if (!is_dir($sourceDirectory)) {
            throw new \RuntimeException('Nie znaleziono katalogu zrodlowego ' . $sourceDirectory . '.');
        }

        if (!is_dir($csvDirectory)) {
            throw new \RuntimeException('Nie znaleziono katalogu z plikiem stalych wystawcow: ' . $csvDirectory . '.');
        }

        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new \RuntimeException('Nie znaleziono pliku listy stalych wystawcow: ' . $csvPath . '.');
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Nie udalo sie otworzyc pliku ' . $csvPath . '.');
        }

        $issuers = [];
        $lineNumber = 0;
        $totalExpectedInvoices = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $lineNumber++;
            $cells = array_map([$this, 'sanitizeCell'], $row);

            if ($this->isEmptyRow($cells)) {
                continue;
            }

            $issuerName = $cells[0] ?? '';
            $expectedCountRaw = $cells[1] ?? '';

            if ($issuerName === '') {
                fclose($handle);
                throw new \RuntimeException('W pliku stalych wystawcow brakuje nazwy w linii ' . $lineNumber . '.');
            }

            if ($expectedCountRaw === '' || preg_match('/^\d+$/', $expectedCountRaw) !== 1) {
                fclose($handle);
                throw new \RuntimeException(
                    'W pliku stalych wystawcow oczekiwana liczba faktur musi byc liczba calkowita w linii '
                    . $lineNumber . '.'
                );
            }

            $expectedCount = (int) $expectedCountRaw;
            $totalExpectedInvoices += $expectedCount;

            $issuers[] = [
                'position' => count($issuers) + 1,
                'line_number' => $lineNumber,
                'issuer_name' => $issuerName,
                'expected_invoice_count' => $expectedCount,
            ];
        }

        fclose($handle);

        if ($issuers === []) {
            throw new \RuntimeException('Plik stalych wystawcow jest pusty.');
        }

        return [
            'source_directory' => $sourceDirectory,
            'csv_path' => $csvPath,
            'issuers' => $issuers,
            'summary' => [
                'issuer_count' => count($issuers),
                'expected_invoice_count' => $totalExpectedInvoices,
            ],
        ];
    }

    public function expectedFolderPath(): string
    {
        $snapshot = $this->applicationSettings->snapshot();

        return trim((string) ($snapshot['local_paths']['document_inbox_dir'] ?? ''));
    }

    public function expectedCsvPath(): string
    {
        $snapshot = $this->applicationSettings->snapshot();

        return trim((string) ($snapshot['local_paths']['recurring_issuers_csv'] ?? ''));
    }

    private function sanitizeCell(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $clean = str_replace("\xEF\xBB\xBF", '', $value);
        $clean = str_replace("\xC2\xA0", ' ', $clean);

        return trim($clean);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
