<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class InvoiceMatchRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function replaceForSourceFile(int $userId, int $sourceFileId, array $matches): void
    {
        $delete = $this->database->connection()->prepare(
            'DELETE im
             FROM invoice_matches im
             INNER JOIN accounting_entries ae ON ae.id = im.accounting_entry_id
             WHERE im.user_id = :user_id
               AND ae.source_file_id = :source_file_id'
        );
        $delete->execute([
            'user_id' => $userId,
            'source_file_id' => $sourceFileId,
        ]);

        $insert = $this->database->connection()->prepare(
            'INSERT INTO invoice_matches (
                user_id, invoice_id, accounting_entry_id, status, confidence_score, reasoning, matched_at
             ) VALUES (
                :user_id, :invoice_id, :accounting_entry_id, :status, :confidence_score, :reasoning, :matched_at
             )'
        );

        foreach ($matches as $match) {
            if (($match['accounting_entry_id'] ?? null) === null) {
                continue;
            }

            $insert->execute([
                'user_id' => $userId,
                'invoice_id' => $match['invoice_id'] ?? null,
                'accounting_entry_id' => $match['accounting_entry_id'] ?? null,
                'status' => $match['status'],
                'confidence_score' => $match['confidence_score'] ?? null,
                'reasoning' => $match['reasoning'] ?? null,
                'matched_at' => in_array($match['status'], ['BOTH', 'UNCERTAIN_MATCH'], true)
                    ? date('Y-m-d H:i:s')
                    : null,
            ]);
        }
    }

    public function listForSourceFile(int $userId, int $sourceFileId): array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT
                im.id,
                im.status,
                im.confidence_score,
                im.reasoning,
                im.invoice_id,
                im.accounting_entry_id,
                i.invoice_number,
                i.issuer_name,
                i.gross_amount,
                i.due_date,
                i.payment_description,
                ae.row_lp,
                ae.event_date,
                ae.document_number,
                ae.contractor_name,
                ae.business_event_description,
                ae.total_expenses_amount,
                ae.purchase_goods_amount,
                ae.side_purchase_costs_amount,
                ae.other_expenses_amount
             FROM invoice_matches im
             LEFT JOIN invoices i ON i.id = im.invoice_id
             LEFT JOIN accounting_entries ae ON ae.id = im.accounting_entry_id
             WHERE im.user_id = :user_id
               AND ae.source_file_id = :source_file_id
             ORDER BY
                CASE im.status
                    WHEN \'BOTH\' THEN 1
                    WHEN \'UNCERTAIN_MATCH\' THEN 2
                    WHEN \'ONLY_ACCOUNTING\' THEN 3
                    ELSE 4
                END,
                ae.id ASC,
                i.id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source_file_id' => $sourceFileId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
