<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class AccountingEntryRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function createMany(int $userId, int $sourceFileId, array $entries): array
    {
        $createdIds = [];
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO accounting_entries (
                user_id, source_file_id, row_lp, event_date, document_number, contractor_name,
                contractor_address, business_event_description, revenue_amount, purchase_goods_amount,
                side_purchase_costs_amount, other_expenses_amount, total_expenses_amount, notes, raw_text
             ) VALUES (
                :user_id, :source_file_id, :row_lp, :event_date, :document_number, :contractor_name,
                :contractor_address, :business_event_description, :revenue_amount, :purchase_goods_amount,
                :side_purchase_costs_amount, :other_expenses_amount, :total_expenses_amount, :notes, :raw_text
             )'
        );

        foreach ($entries as $entry) {
            $stmt->execute([
                'user_id' => $userId,
                'source_file_id' => $sourceFileId,
                'row_lp' => $entry['row_lp'] ?? null,
                'event_date' => $entry['event_date'] ?? null,
                'document_number' => $entry['document_number'] ?? null,
                'contractor_name' => $entry['contractor_name'] ?? null,
                'contractor_address' => $entry['contractor_address'] ?? null,
                'business_event_description' => $entry['business_event_description'] ?? null,
                'revenue_amount' => $entry['revenue_amount'] ?? null,
                'purchase_goods_amount' => $entry['purchase_goods_amount'] ?? null,
                'side_purchase_costs_amount' => $entry['side_purchase_costs_amount'] ?? null,
                'other_expenses_amount' => $entry['other_expenses_amount'] ?? null,
                'total_expenses_amount' => $entry['total_expenses_amount'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'raw_text' => $entry['raw_text'] ?? null,
            ]);

            $createdIds[] = (int) $this->database->connection()->lastInsertId();
        }

        return $createdIds;
    }

    public function listBySourceFile(int $userId, int $sourceFileId): array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM accounting_entries
             WHERE user_id = :user_id
               AND source_file_id = :source_file_id
             ORDER BY id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source_file_id' => $sourceFileId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function latestSourceFileIdForUser(int $userId): ?int
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT source_file_id
             FROM accounting_entries
             WHERE user_id = :user_id
               AND source_file_id IS NOT NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }
}
