<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

final class InvoiceRepository
{
    public function __construct(private Database $database)
    {
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function createFetchJob(int $userId, string $environment, string $dateFrom, string $dateTo): int
    {
        $stmt = $this->database->connection()->prepare(
            'INSERT INTO invoice_fetch_jobs (user_id, environment, date_from, date_to, status, started_at)
             VALUES (:user_id, :environment, :date_from, :date_to, :status, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'environment' => $environment,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'status' => 'running',
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function finishFetchJob(int $jobId, string $status, int $invoiceCount, int $warningCount, ?string $errorMessage = null): void
    {
        $stmt = $this->database->connection()->prepare(
            'UPDATE invoice_fetch_jobs
             SET status = :status,
                 invoice_count = :invoice_count,
                 warning_count = :warning_count,
                 error_message = :error_message,
                 finished_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $jobId,
            'status' => $status,
            'invoice_count' => $invoiceCount,
            'warning_count' => $warningCount,
            'error_message' => $errorMessage,
        ]);
    }

    public function saveFetchedInvoice(int $userId, array $invoice): int
    {
        $existingId = $this->findExistingInvoiceId($userId, $invoice);
        if ($existingId !== null) {
            $stmt = $this->database->connection()->prepare(
                'UPDATE invoices
                 SET ksef_reference_number = :ksef_reference_number,
                     invoice_number = :invoice_number,
                     issuer_name = :issuer_name,
                     issuer_tax_id = :issuer_tax_id,
                     issue_date = :issue_date,
                     sale_date = :sale_date,
                     due_date = :due_date,
                     gross_amount = :gross_amount,
                     net_amount = :net_amount,
                     vat_amount = :vat_amount,
                     currency = :currency,
                     bank_account = :bank_account,
                     payment_description = :payment_description,
                     raw_payload_json = :raw_payload_json,
                     validation_status = :validation_status,
                     validation_notes = :validation_notes
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $existingId,
                'ksef_reference_number' => $invoice['ksef_reference_number'],
                'invoice_number' => $invoice['invoice_number'],
                'issuer_name' => $invoice['issuer_name'],
                'issuer_tax_id' => $invoice['issuer_tax_id'],
                'issue_date' => $invoice['issue_date'],
                'sale_date' => $invoice['sale_date'],
                'due_date' => $invoice['due_date'],
                'gross_amount' => $invoice['gross_amount'],
                'net_amount' => $invoice['net_amount'],
                'vat_amount' => $invoice['vat_amount'],
                'currency' => $invoice['currency'],
                'bank_account' => $invoice['bank_account'],
                'payment_description' => $invoice['payment_description'],
                'raw_payload_json' => $invoice['raw_payload_json'],
                'validation_status' => $invoice['validation_status'],
                'validation_notes' => $invoice['validation_notes'],
            ]);

            return $existingId;
        }

        $stmt = $this->database->connection()->prepare(
            'INSERT INTO invoices (
                user_id, source, ksef_reference_number, invoice_number, issuer_name, issuer_tax_id,
                issue_date, sale_date, due_date, gross_amount, net_amount, vat_amount, currency,
                bank_account, payment_description, raw_payload_json, validation_status, validation_notes
             ) VALUES (
                :user_id, :source, :ksef_reference_number, :invoice_number, :issuer_name, :issuer_tax_id,
                :issue_date, :sale_date, :due_date, :gross_amount, :net_amount, :vat_amount, :currency,
                :bank_account, :payment_description, :raw_payload_json, :validation_status, :validation_notes
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source' => 'KSEF',
            'ksef_reference_number' => $invoice['ksef_reference_number'],
            'invoice_number' => $invoice['invoice_number'],
            'issuer_name' => $invoice['issuer_name'],
            'issuer_tax_id' => $invoice['issuer_tax_id'],
            'issue_date' => $invoice['issue_date'],
            'sale_date' => $invoice['sale_date'],
            'due_date' => $invoice['due_date'],
            'gross_amount' => $invoice['gross_amount'],
            'net_amount' => $invoice['net_amount'],
            'vat_amount' => $invoice['vat_amount'],
            'currency' => $invoice['currency'],
            'bank_account' => $invoice['bank_account'],
            'payment_description' => $invoice['payment_description'],
            'raw_payload_json' => $invoice['raw_payload_json'],
            'validation_status' => $invoice['validation_status'],
            'validation_notes' => $invoice['validation_notes'],
        ]);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function listByDateRangeForUser(int $userId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM invoices
             WHERE user_id = :user_id
               AND source = :source
               AND (
                    (issue_date BETWEEN :issue_date_from AND :issue_date_to)
                    OR (issue_date IS NULL AND sale_date BETWEEN :sale_date_from AND :sale_date_to)
                    OR (issue_date IS NULL AND sale_date IS NULL AND due_date BETWEEN :due_date_from AND :due_date_to)
               )
             ORDER BY COALESCE(issue_date, sale_date, due_date, DATE(created_at)) ASC, id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source' => 'KSEF',
            'issue_date_from' => $dateFrom,
            'issue_date_to' => $dateTo,
            'sale_date_from' => $dateFrom,
            'sale_date_to' => $dateTo,
            'due_date_from' => $dateFrom,
            'due_date_to' => $dateTo,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function latestFetchJobForUser(int $userId): ?array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM invoice_fetch_jobs
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function listKsefForUser(int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $sql = 'SELECT *
                FROM invoices
                WHERE user_id = :user_id
                  AND source = :source';
        $params = [
            'user_id' => $userId,
            'source' => 'KSEF',
        ];

        if ($dateFrom !== null && $dateTo !== null) {
            $sql .= '
                  AND COALESCE(issue_date, sale_date, due_date, DATE(created_at))
                      BETWEEN :date_from AND :date_to';
            $params['date_from'] = $dateFrom;
            $params['date_to'] = $dateTo;
        }

        $sql .= '
                ORDER BY COALESCE(issue_date, sale_date, due_date, DATE(created_at)) ASC, id ASC';

        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchJobById(int $userId, int $jobId): ?array
    {
        $stmt = $this->database->connection()->prepare(
            'SELECT *
             FROM invoice_fetch_jobs
             WHERE user_id = :user_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'id' => $jobId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function findExistingInvoiceId(int $userId, array $invoice): ?int
    {
        $referenceNumber = trim((string) ($invoice['ksef_reference_number'] ?? ''));
        if ($referenceNumber !== '') {
            $stmt = $this->database->connection()->prepare(
                'SELECT id
                 FROM invoices
                 WHERE user_id = :user_id
                   AND source = :source
                   AND ksef_reference_number = :ksef_reference_number
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => $userId,
                'source' => 'KSEF',
                'ksef_reference_number' => $referenceNumber,
            ]);

            $existingId = $stmt->fetchColumn();

            return $existingId === false ? null : (int) $existingId;
        }

        $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
        $issuerTaxId = trim((string) ($invoice['issuer_tax_id'] ?? ''));
        $issueDate = trim((string) ($invoice['issue_date'] ?? ''));
        $grossAmount = $invoice['gross_amount'] ?? null;

        if ($invoiceNumber === '' || $issuerTaxId === '' || $issueDate === '' || $grossAmount === null) {
            return null;
        }

        $stmt = $this->database->connection()->prepare(
            'SELECT id
             FROM invoices
             WHERE user_id = :user_id
               AND source = :source
               AND invoice_number = :invoice_number
               AND issuer_tax_id = :issuer_tax_id
               AND issue_date = :issue_date
               AND gross_amount = :gross_amount
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'source' => 'KSEF',
            'invoice_number' => $invoiceNumber,
            'issuer_tax_id' => $issuerTaxId,
            'issue_date' => $issueDate,
            'gross_amount' => $grossAmount,
        ]);

        $existingId = $stmt->fetchColumn();

        return $existingId === false ? null : (int) $existingId;
    }
}
