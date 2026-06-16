<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Repository\AuditLogRepository;
use App\Repository\UploadedFileRepository;

final class FileUploadService
{
    public function __construct(
        private Config $config,
        private UploadedFileRepository $uploadedFileRepository,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    public function validateAndStore(array $file, string $kind, int $userId, array $allowedExtensions, array $allowedMimeTypes): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Nie udało się odebrać pliku.');
        }

        $maxBytes = ((int) $this->config->get('app.upload_limit_mb', 20)) * 1024 * 1024;
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('Plik przekracza dopuszczalny rozmiar albo jest pusty.');
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new \RuntimeException('Niedozwolone rozszerzenie pliku.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName) ?: 'application/octet-stream';
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \RuntimeException('Niedozwolony typ MIME pliku.');
        }

        $safeBaseName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'plik';
        $storedName = sprintf('%s_%s.%s', $safeBaseName, bin2hex(random_bytes(8)), $extension);
        $storageDirectory = (string) $this->config->get('paths.uploads', STORAGE_PATH . '/uploads');

        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
            throw new \RuntimeException('Nie udało się przygotować katalogu uploadów.');
        }

        $destination = rtrim($storageDirectory, '/\\') . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Nie udało się zapisać pliku na serwerze.');
        }

        $record = [
            'user_id' => $userId,
            'kind' => $kind,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'storage_path' => $destination,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'sha256_hash' => hash_file('sha256', $destination) ?: '',
        ];

        $recordId = $this->uploadedFileRepository->create($record);
        $this->auditLogRepository->log(
            action: 'upload_' . $kind,
            userId: $userId,
            entityType: 'uploaded_file',
            entityId: $recordId,
            context: [
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
            ]
        );

        return $record + ['id' => $recordId];
    }
}
