<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;

final class UploadedFileRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function create(array $payload): int
    {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO uploaded_files (user_id, kind, original_name, stored_name, storage_path, mime_type, size_bytes, sha256_hash)
             VALUES (:user_id, :kind, :original_name, :stored_name, :storage_path, :mime_type, :size_bytes, :sha256_hash)'
        );
        $stmt->execute([
            'user_id' => $payload['user_id'],
            'kind' => $payload['kind'],
            'original_name' => $payload['original_name'],
            'stored_name' => $payload['stored_name'],
            'storage_path' => $payload['storage_path'],
            'mime_type' => $payload['mime_type'],
            'size_bytes' => $payload['size_bytes'],
            'sha256_hash' => $payload['sha256_hash'],
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }
}
