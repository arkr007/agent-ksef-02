<?php

declare(strict_types=1);

namespace App\Service;

final class RecurringIssuerCatalogService
{
    private const DESKTOP_FOLDER_NAME = 'faktury_do_ksiegowej';
    private const CSV_SUBDIRECTORY_NAME = 'rob';
    private const CSV_FILE_NAME = 'stali_wystawcy.csv';

    public function loadCatalog(): array
    {
        $desktopDirectory = $this->resolveDesktopDirectory();
        if ($desktopDirectory === null) {
            throw new \RuntimeException('Nie udało się ustalić katalogu Desktop dla bieżącej stacji roboczej.');
        }

        $sourceDirectory = $desktopDirectory . DIRECTORY_SEPARATOR . self::DESKTOP_FOLDER_NAME;
        $csvDirectory = $sourceDirectory . DIRECTORY_SEPARATOR . self::CSV_SUBDIRECTORY_NAME;
        $csvPath = $csvDirectory . DIRECTORY_SEPARATOR . self::CSV_FILE_NAME;

        clearstatcache(true, $csvPath);

        if (!is_dir($sourceDirectory)) {
            throw new \RuntimeException(
                'Nie znaleziono katalogu źródłowego ' . $sourceDirectory . '.'
            );
        }

        if (!is_dir($csvDirectory)) {
            throw new \RuntimeException(
                'Nie znaleziono katalogu z plikiem stałych wystawców: ' . $csvDirectory . '.'
            );
        }

        if (!is_file($csvPath) || !is_readable($csvPath)) {
            throw new \RuntimeException(
                'Nie znaleziono pliku listy stałych wystawców: ' . $csvPath . '.'
            );
        }

        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Nie udało się otworzyć pliku ' . $csvPath . '.');
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
                throw new \RuntimeException('W pliku stałych wystawców brakuje nazwy w linii ' . $lineNumber . '.');
            }

            if ($expectedCountRaw === '' || preg_match('/^\d+$/', $expectedCountRaw) !== 1) {
                fclose($handle);
                throw new \RuntimeException(
                    'W pliku stałych wystawców oczekiwana liczba faktur musi być liczbą całkowitą w linii '
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
            throw new \RuntimeException('Plik stałych wystawców jest pusty.');
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
        $desktopDirectory = $this->resolveDesktopDirectory();

        return ($desktopDirectory ?? 'Desktop') . DIRECTORY_SEPARATOR . self::DESKTOP_FOLDER_NAME;
    }

    public function expectedCsvPath(): string
    {
        return $this->expectedFolderPath()
            . DIRECTORY_SEPARATOR
            . self::CSV_SUBDIRECTORY_NAME
            . DIRECTORY_SEPARATOR
            . self::CSV_FILE_NAME;
    }

    private function resolveDesktopDirectory(): ?string
    {
        $candidates = [];

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && trim($userProfile) !== '') {
            $candidates[] = rtrim(trim($userProfile), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');
        if (is_string($homeDrive) && is_string($homePath) && trim($homeDrive . $homePath) !== '') {
            $candidates[] = rtrim(trim($homeDrive . $homePath), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            $candidates[] = rtrim(trim($home), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
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
