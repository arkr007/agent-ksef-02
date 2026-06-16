<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repository\AuditLogRepository;
use App\Repository\BankImportJobRepository;
use App\Repository\UploadedFileRepository;
use App\Service\ApplicationSettings;
use App\Service\CsvImporter;
use App\Service\FileUploadService;
use App\Service\Validators;
use App\Service\Bank\Pain00100109Exporter;
use Throwable;

final class BankImportController
{
    private const SESSION_PACKAGE_KEY = 'bank_import_current_package';

    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private Flash $flash,
        private ApplicationSettings $applicationSettings,
        private Validators $validators,
        private CsvImporter $csvImporter,
        private Pain00100109Exporter $painExporter,
        private FileUploadService $fileUploadService,
        private UploadedFileRepository $uploadedFileRepository,
        private BankImportJobRepository $bankImportJobRepository,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    public function index(Request $request): Response
    {
        unset($request);

        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        return $this->renderPage(
            $this->applicationSettings->snapshot(),
            (int) $user['id']
        );
    }

    public function import(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.');

            return Response::redirect($this->config->url('/bank-import') . '#bank-import-form');
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $settings = $this->applicationSettings->snapshot();

        try {
            $uploadedFile = $this->fileUploadService->validateAndStore(
                (array) $request->file('csv_file'),
                'bank_csv',
                $userId,
                ['csv'],
                ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream']
            );

            $parsed = $this->csvImporter->import((string) $uploadedFile['storage_path']);
            $headerErrors = $this->validateHeader((array) $parsed['header']);
            if ($headerErrors !== []) {
                return $this->renderPage(
                    $settings,
                    $userId,
                    alerts: $this->alertsFromMessages('error', $headerErrors),
                    scrollTarget: '#bank-import-form'
                );
            }

            $package = $this->buildPreviewPackage(
                $userId,
                $uploadedFile,
                (array) $parsed['rows'],
                (array) $settings['bank']
            );

            $jobId = $this->bankImportJobRepository->create(
                $userId,
                (int) $uploadedFile['id'],
                $package['summary']['error_count'] > 0 || $package['summary']['warning_count'] > 0
                    ? 'preview_ready_with_flags'
                    : 'preview_ready',
                $package['summary']['included_count'],
                $package['summary']['total_amount'],
                $package['summary']['currency'],
                $package['summary']['warning_count'],
                $package['summary']['error_count']
            );

            $package['job_id'] = $jobId;
            $this->storeCurrentPackage($package);

            $this->auditLogRepository->log(
                action: 'bank_import_preview_created',
                userId: $userId,
                entityType: 'bank_import_job',
                entityId: $jobId,
                context: [
                    'source_file_id' => $uploadedFile['id'],
                    'row_count' => $package['summary']['row_count'],
                    'included_count' => $package['summary']['included_count'],
                    'warning_count' => $package['summary']['warning_count'],
                    'error_count' => $package['summary']['error_count'],
                    'total_amount' => $package['summary']['total_amount'],
                ],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            $alerts = [[
                'type' => $package['summary']['error_count'] > 0 || $package['summary']['warning_count'] > 0 ? 'warning' : 'info',
                'message' => $this->buildImportSummaryMessage($package['summary']),
            ]];

            return $this->renderPage(
                $settings,
                $userId,
                alerts: $alerts,
                package: $package,
                latestJob: $this->bankImportJobRepository->findForUser($userId, $jobId),
                scrollTarget: '#bank-package'
            );
        } catch (Throwable $exception) {
            return $this->renderPage(
                $settings,
                $userId,
                alerts: [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                scrollTarget: '#bank-import-form'
            );
        }
    }

    public function exportPain(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.');

            return Response::redirect($this->config->url('/bank-import') . '#bank-package');
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $package = $this->currentPackage($userId);
        if ($package === null) {
            $this->flash->add('warning', 'Najpierw zaimportuj CSV i przygotuj paczke przelewow.');

            return Response::redirect($this->config->url('/bank-import') . '#bank-import-form');
        }

        $exportableTransfers = array_values(array_filter(
            (array) ($package['rows'] ?? []),
            static fn (array $row): bool => (bool) ($row['included_in_export'] ?? false)
        ));

        if ($exportableTransfers === []) {
            $this->flash->add('warning', 'Brak przelewow gotowych do eksportu. Popraw dane w CSV albo wybierz inny zakres.');

            return Response::redirect($this->config->url('/bank-import') . '#bank-package');
        }

        $settings = $this->applicationSettings->snapshot();
        $payerData = (array) ($settings['bank'] ?? []);

        try {
            $xml = $this->painExporter->export($exportableTransfers, $payerData);
            $fileName = $this->buildExportFileName((int) ($package['job_id'] ?? 0));
            $fileRecord = $this->storeGeneratedExport($xml, $fileName, $userId);

            if (!empty($package['job_id'])) {
                $this->bankImportJobRepository->markExported((int) $package['job_id'], (int) $fileRecord['id']);
            }

            $this->auditLogRepository->log(
                action: 'bank_import_exported_pain001',
                userId: $userId,
                entityType: 'bank_import_job',
                entityId: (int) ($package['job_id'] ?? 0),
                context: [
                    'export_file_id' => $fileRecord['id'],
                    'transfer_count' => count($exportableTransfers),
                    'total_amount' => $package['summary']['total_amount'] ?? '0.00',
                ],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            return new Response(
                $xml,
                200,
                [
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'Content-Disposition' => sprintf('attachment; filename="%s"', $fileName),
                ]
            );
        } catch (Throwable $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect($this->config->url('/bank-import') . '#bank-package');
        }
    }

    private function renderPage(
        array $settings,
        int $userId,
        array $alerts = [],
        ?array $package = null,
        ?array $latestJob = null,
        string $scrollTarget = ''
    ): Response {
        $package ??= $this->currentPackage($userId);
        $latestJob ??= $this->bankImportJobRepository->latestForUser($userId);
        $bankSettings = (array) ($settings['bank'] ?? []);

        return Response::html($this->view->render('bank_import', [
            'title' => 'Import przelewow',
            'pageTitle' => 'Funkcja 2: import CSV i plik bankowy',
            'pageDescription' => 'Importujesz CSV z fakturami do zaplaty, sprawdzasz paczke przelewow i generujesz plik pain.001.001.09.',
            'alerts' => $alerts,
            'scrollTarget' => $scrollTarget,
            'payerName' => (string) ($bankSettings['payer_name'] ?? ''),
            'payerAddress' => (string) ($bankSettings['payer_address'] ?? ''),
            'payerIban' => $this->validators->formatBankAccount((string) ($bankSettings['payer_iban'] ?? '')),
            'defaultCurrency' => (string) ($bankSettings['default_currency'] ?? 'PLN'),
            'payerConfigured' => trim((string) ($bankSettings['payer_name'] ?? '')) !== ''
                && trim((string) ($bankSettings['payer_iban'] ?? '')) !== '',
            'package' => $package,
            'latestJob' => $latestJob,
        ]));
    }

    private function validateHeader(array $header): array
    {
        $errors = [];
        $groups = [
            'rachunku bankowego' => ['rachunek_bankowy', 'rachunek_bankowy_do_wplaty'],
            'kwoty brutto lub kwoty do zaplaty' => ['kwota_brutto', 'kwota_do_zaplaty'],
            'terminu platnosci' => ['termin_platnosci'],
            'wystawcy' => ['wystawca', 'wystawca_faktury'],
        ];

        foreach ($groups as $label => $aliases) {
            if ($this->firstExistingColumn($header, $aliases) === null) {
                $errors[] = sprintf('Brakuje kolumny dla %s w naglowku CSV.', $label);
            }
        }

        return $errors;
    }

    private function buildPreviewPackage(int $userId, array $uploadedFile, array $rows, array $payerData): array
    {
        $currency = strtoupper((string) ($payerData['default_currency'] ?? 'PLN'));
        $previewRows = [];
        $warningCount = 0;
        $errorCount = 0;
        $includedCount = 0;
        $totalAmount = 0.0;

        foreach ($rows as $index => $row) {
            $preview = $this->mapTransferRow((array) $row, $index + 1, $currency);
            $previewRows[] = $preview;

            if ($preview['import_status'] === 'error') {
                $errorCount++;
            } elseif ($preview['import_status'] === 'warning') {
                $warningCount++;
            }

            if ($preview['included_in_export']) {
                $includedCount++;
                $totalAmount += (float) $preview['amount'];
            }
        }

        return [
            'user_id' => $userId,
            'job_id' => null,
            'source_file_id' => (int) $uploadedFile['id'],
            'source_file_name' => (string) $uploadedFile['original_name'],
            'uploaded_at' => date('Y-m-d H:i:s'),
            'rows' => $previewRows,
            'summary' => [
                'row_count' => count($previewRows),
                'included_count' => $includedCount,
                'excluded_count' => count($previewRows) - $includedCount,
                'warning_count' => $warningCount,
                'error_count' => $errorCount,
                'transfer_count' => $includedCount,
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'currency' => $currency,
            ],
        ];
    }

    private function mapTransferRow(array $row, int $position, string $currency): array
    {
        $issuerName = $this->firstValue($row, ['wystawca', 'wystawca_faktury']);
        $invoiceNumber = $this->firstValue($row, ['numer_faktury', 'nr_faktury']);
        $recipientAccount = $this->validators->normalizeBankAccount(
            $this->firstValue($row, ['rachunek_bankowy', 'rachunek_bankowy_do_wplaty'])
        );
        $title = $this->firstValue($row, ['tytul_platnosci', 'za_co_platnosc']);
        if ($title === '' && $invoiceNumber !== '') {
            $title = 'Faktura ' . $invoiceNumber;
        }

        $grossAmount = $this->parseDecimal($this->firstValue($row, ['kwota_brutto']));
        $amountDue = $this->parseDecimal($this->firstValue($row, ['kwota_do_zaplaty']));
        $amount = $amountDue !== null && (float) $amountDue > 0
            ? $amountDue
            : $grossAmount;
        $dueDate = $this->normalizeDate($this->firstValue($row, ['termin_platnosci']));
        $paymentStatus = strtolower($this->firstValue($row, ['status_platnosci']));
        $sourceValidationStatus = strtolower($this->firstValue($row, ['status_walidacji']));
        $sourceNotes = $this->firstValue($row, ['uwagi']);

        $notes = [];
        $status = 'ready';
        $includedInExport = true;

        if ($issuerName === '') {
            $notes[] = 'Brak nazwy odbiorcy.';
            $status = 'error';
            $includedInExport = false;
        }

        if ($recipientAccount === null || !$this->validators->isValidIbanOrNrb($recipientAccount)) {
            $notes[] = 'Brak poprawnego rachunku odbiorcy.';
            $status = 'error';
            $includedInExport = false;
        }

        if ($amount === null) {
            $notes[] = 'Brak kwoty przelewu.';
            $status = 'error';
            $includedInExport = false;
        } elseif ((float) $amount <= 0) {
            $notes[] = 'Kwota przelewu musi byc dodatnia.';
            $status = 'error';
            $includedInExport = false;
        }

        if ($dueDate === null) {
            $notes[] = 'Brak poprawnego terminu platnosci.';
            $status = 'error';
            $includedInExport = false;
        }

        if ($title === '') {
            $notes[] = 'Brak tytulu platnosci.';
            $status = 'error';
            $includedInExport = false;
        }

        if ($sourceValidationStatus === 'error') {
            $notes[] = 'Wiersz ma status walidacji "error" i nie trafi do eksportu.';
            $status = 'warning';
            $includedInExport = false;
        } elseif (in_array($sourceValidationStatus, ['warning', 'manual_review'], true)) {
            $notes[] = 'Wiersz wymaga recznej weryfikacji i nie trafi do eksportu.';
            $status = 'warning';
            $includedInExport = false;
        }

        if (str_contains($paymentStatus, 'zaplac')) {
            $notes[] = 'Faktura jest oznaczona jako zaplacona.';
            $status = 'warning';
            $includedInExport = false;
        }

        if ($sourceNotes !== '') {
            $notes[] = 'Uwagi z importu: ' . $sourceNotes;
        }

        if ($status === 'warning' && $this->hasErrorNote($notes)) {
            $status = 'error';
        }

        return [
            'position' => $position,
            'line_number' => (int) ($row['_line_number'] ?? $position + 1),
            'issuer_name' => $issuerName,
            'invoice_number' => $invoiceNumber,
            'recipient_name' => $issuerName,
            'recipient_account' => $this->toIban($recipientAccount),
            'formatted_recipient_account' => $this->validators->formatBankAccount($recipientAccount),
            'title' => $title,
            'gross_amount' => $grossAmount,
            'amount_due' => $amountDue,
            'amount' => $amount,
            'formatted_gross_amount' => $this->formatMoney($grossAmount, $currency),
            'formatted_amount_due' => $this->formatMoney($amountDue, $currency),
            'formatted_amount' => $this->formatMoney($amount, $currency),
            'currency' => $currency,
            'execution_date' => $dueDate ?? date('Y-m-d'),
            'due_date' => $dueDate,
            'payment_status' => $paymentStatus,
            'source_validation_status' => $sourceValidationStatus,
            'source_notes' => $sourceNotes,
            'included_in_export' => $includedInExport,
            'import_status' => $status,
            'import_status_label' => match ($status) {
                'error' => 'Blad',
                'warning' => 'Do sprawdzenia',
                default => 'Gotowy',
            },
            'import_status_badge_class' => match ($status) {
                'error' => 'error',
                'warning' => 'warn',
                default => 'ok',
            },
            'import_notes' => implode(' ', array_unique($notes)),
            'end_to_end_id' => $this->buildEndToEndId($invoiceNumber, $position),
        ];
    }

    private function firstValue(array $row, array $aliases): string
    {
        foreach ($aliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                return trim((string) $row[$alias]);
            }
        }

        return '';
    }

    private function firstExistingColumn(array $header, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (in_array($alias, $header, true)) {
                return $alias;
            }
        }

        return null;
    }

    private function parseDecimal(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' '], '', $normalized);
        $normalized = preg_replace('/[^0-9,.\-]/', '', $normalized) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if ((int) strrpos($normalized, ',') > (int) strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeDate(string $value): ?string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd-m-Y', 'd/m/Y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $candidate);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $candidate) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function hasErrorNote(array $notes): bool
    {
        foreach ($notes as $note) {
            if (
                str_contains($note, 'Brak')
                || str_contains($note, 'Kwota przelewu musi byc dodatnia')
            ) {
                return true;
            }
        }

        return false;
    }

    private function formatMoney(?string $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float) $amount, 2, ',', ' ') . ' ' . $currency;
    }

    private function buildEndToEndId(string $invoiceNumber, int $position): string
    {
        $seed = $invoiceNumber !== '' ? $invoiceNumber : ('POZ-' . $position);
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($seed)) ?? 'POZ' . $position;

        return substr($normalized, 0, 35);
    }

    private function toIban(?string $account): string
    {
        $normalized = strtoupper((string) $account);
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^\d{26}$/', $normalized) === 1) {
            return 'PL' . $normalized;
        }

        return $normalized;
    }

    private function buildImportSummaryMessage(array $summary): string
    {
        return sprintf(
            'Wczytano %d wierszy. Do eksportu gotowych: %d. Wykluczonych: %d. Ostrzezen: %d. Bledow: %d.',
            (int) ($summary['row_count'] ?? 0),
            (int) ($summary['included_count'] ?? 0),
            (int) ($summary['excluded_count'] ?? 0),
            (int) ($summary['warning_count'] ?? 0),
            (int) ($summary['error_count'] ?? 0)
        );
    }

    private function alertsFromMessages(string $type, array $messages): array
    {
        return array_map(
            static fn (string $message): array => ['type' => $type, 'message' => $message],
            $messages
        );
    }

    private function storeCurrentPackage(array $package): void
    {
        $_SESSION[self::SESSION_PACKAGE_KEY] = $package;
    }

    private function currentPackage(int $userId): ?array
    {
        $package = $_SESSION[self::SESSION_PACKAGE_KEY] ?? null;
        if (!is_array($package)) {
            return null;
        }

        if ((int) ($package['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_PACKAGE_KEY]);

            return null;
        }

        return $package;
    }

    private function buildExportFileName(int $jobId): string
    {
        return sprintf(
            'pain001-job-%d-%s.xml',
            $jobId > 0 ? $jobId : 0,
            date('Ymd-His')
        );
    }

    private function storeGeneratedExport(string $xml, string $fileName, int $userId): array
    {
        $directory = (string) $this->config->get('paths.exports', STORAGE_PATH . '/exports');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nie udalo sie przygotowac katalogu eksportow.');
        }

        $storedName = bin2hex(random_bytes(8)) . '_' . $fileName;
        $fullPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $storedName;

        if (file_put_contents($fullPath, $xml) === false) {
            throw new \RuntimeException('Nie udalo sie zapisac wygenerowanego pliku XML.');
        }

        $record = [
            'user_id' => $userId,
            'kind' => 'pain001_export',
            'original_name' => $fileName,
            'stored_name' => $storedName,
            'storage_path' => $fullPath,
            'mime_type' => 'application/xml',
            'size_bytes' => filesize($fullPath) ?: strlen($xml),
            'sha256_hash' => hash_file('sha256', $fullPath) ?: '',
        ];

        $recordId = $this->uploadedFileRepository->create($record);

        return $record + ['id' => $recordId];
    }
}
