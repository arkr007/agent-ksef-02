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

    public function buildCatalogFromUploadedFiles(array $files, string $sourceLabel = 'Jednorazowy upload'): array
    {
        $pdfFiles = [];
        $otherFiles = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $extension = strtolower((string) ($file['extension'] ?? pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)));
            $entry = [
                'name' => (string) ($file['name'] ?? ''),
                'path' => (string) ($file['path'] ?? ''),
                'extension' => $extension !== '' ? $extension : '-',
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'size_label' => $this->formatBytes((int) ($file['size_bytes'] ?? 0)),
                'modified_at' => (string) ($file['modified_at'] ?? date('Y-m-d H:i:s')),
                'relative_path' => (string) ($file['relative_path'] ?? ''),
            ];

            if ($extension === 'pdf') {
                $pdfFiles[] = $entry;
                continue;
            }

            $otherFiles[] = $entry;
        }

        usort($pdfFiles, fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));
        usort($otherFiles, fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

        return [
            'source_directory' => $sourceLabel,
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
