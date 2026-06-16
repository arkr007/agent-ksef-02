<?php

declare(strict_types=1);

namespace App\Service;

final class CsvExporter
{
    public function export(array $rows, array $headers = []): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Nie udalo sie przygotowac bufora CSV.');
        }

        fwrite($stream, "\xEF\xBB\xBF");

        if ($headers !== []) {
            fputcsv($stream, $headers, ';');
        }

        foreach ($rows as $row) {
            $normalized = array_map([$this, 'normalizeCell'], $row);
            fputcsv($stream, $normalized, ';');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? '' : $content;
    }

    private function normalizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) || is_int($value)) {
            return str_replace('.', ',', number_format((float) $value, 2, '.', ''));
        }

        return (string) $value;
    }
}
