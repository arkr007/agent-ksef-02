<?php

declare(strict_types=1);

namespace App\Service;

use DirectoryIterator;

final class DocumentInboxCatalogService
{
    public function __construct(
        private RecurringIssuerCatalogService $recurringIssuerCatalogService
    ) {
    }

    public function loadCatalog(): array
    {
        $directoryPath = $this->recurringIssuerCatalogService->expectedFolderPath();
        if (!is_dir($directoryPath)) {
            throw new \RuntimeException('Nie znaleziono katalogu dokumentow: ' . $directoryPath . '.');
        }

        $pdfFiles = [];
        $otherFiles = [];

        $iterator = new DirectoryIterator($directoryPath);
        foreach ($iterator as $item) {
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            $extension = strtolower($item->getExtension());
            $file = [
                'name' => $item->getFilename(),
                'path' => $item->getPathname(),
                'extension' => $extension !== '' ? $extension : '-',
                'size_bytes' => $item->getSize(),
                'size_label' => $this->formatBytes($item->getSize()),
                'modified_at' => date('Y-m-d H:i:s', $item->getMTime()),
            ];

            if ($extension === 'pdf') {
                $pdfFiles[] = $file;
                continue;
            }

            $otherFiles[] = $file;
        }

        usort($pdfFiles, fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));
        usort($otherFiles, fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

        return [
            'source_directory' => $directoryPath,
            'pdf_files' => array_values(array_map(
                fn (array $file, int $index): array => $file + ['position' => $index + 1],
                $pdfFiles,
                array_keys($pdfFiles)
            )),
            'other_files' => array_values(array_map(
                fn (array $file, int $index): array => $file + ['position' => $index + 1],
                $otherFiles,
                array_keys($otherFiles)
            )),
            'summary' => [
                'pdf_count' => count($pdfFiles),
                'other_file_count' => count($otherFiles),
                'total_file_count' => count($pdfFiles) + count($otherFiles),
            ],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 2, ',', ' ') . ' MB';
    }
}
