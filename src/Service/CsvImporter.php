<?php

declare(strict_types=1);

namespace App\Service;

final class CsvImporter
{
    public function import(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Nie udalo sie odczytac pliku CSV.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Nie udalo sie otworzyc pliku CSV.');
        }

        $header = null;
        $rows = [];
        $lineNumber = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $lineNumber++;
            $row = array_map([$this, 'sanitizeCell'], $row);

            if ($this->isEmptyRow($row)) {
                continue;
            }

            if ($header === null) {
                $header = $this->normalizeHeader($row);
                continue;
            }

            $mapped = [];
            foreach ($header as $index => $columnName) {
                if ($columnName === '') {
                    continue;
                }

                $mapped[$columnName] = $row[$index] ?? '';
            }

            $mapped['_line_number'] = $lineNumber;
            $rows[] = $mapped;
        }

        fclose($handle);

        if ($header === null) {
            throw new \RuntimeException('Plik CSV nie zawiera wiersza naglowkowego.');
        }

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    private function sanitizeCell(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $clean = str_replace("\xEF\xBB\xBF", '', $value);

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

    private function normalizeHeader(array $header): array
    {
        return array_map(function (string $column): string {
            $normalized = trim($column);
            if ($normalized === '') {
                return '';
            }

            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($ascii === false) {
                $ascii = $normalized;
            }

            $ascii = strtolower($ascii);
            $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '';

            return trim($ascii, '_');
        }, $header);
    }
}
