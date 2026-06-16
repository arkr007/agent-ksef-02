<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class BankImportJobRepository
{
    public function __construct(private Database $database)
    {
    }

    public function create(
        int $userId,
        int $sourceFileId,
        string $status,
        int $transferCount,
        string $totalAmount,
        string $currency,
        int $warningCount,
        int $errorCount
    ): int {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO bank_import_jobs (
                user_id, source_file_id, status, transfer_count, total_amount, currency, warning_count, error_count, finished_at
             ) VALUES (
                :user_id, :source_file_id, :status, :transfer_count, :total_amount, :currency, :warning_count, :error_count, NOW()
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source_file_id' => $sourceFileId,
            'status' => $status,
            'transfer_count' => $transferCount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function markExported(int $jobId, int $exportFileId, string $status = 'exported'): void
    {
        $stmt = $this->database->connection()->prepare(
            'UPDATE bank_import_jobs
             SET export_file_id = :export_file_id,
                 status = :status,
                 finished_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'export_file_id' => $exportFileId,
            'status' => $status,
        ]);
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM bank_import_jobs
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findForUser(int $userId, int $jobId): ?array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM bank_import_jobs
             WHERE user_id = :user_id
               AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'id' => $jobId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
