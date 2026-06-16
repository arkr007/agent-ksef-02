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
use App\Repository\InvoiceRepository;
use App\Service\ApplicationSettings;
use App\Service\CsvExporter;
use App\Service\InvoiceMapper;
use App\Service\KsefClient;
use App\Service\Validators;
use DateTimeImmutable;
use Throwable;

final class KsefController
{
    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private Flash $flash,
        private ApplicationSettings $applicationSettings,
        private KsefClient $ksefClient,
        private InvoiceMapper $invoiceMapper,
        private InvoiceRepository $invoiceRepository,
        private Validators $validators,
        private CsvExporter $csvExporter,
        private AuditLogRepository $auditLogRepository
    ) {
    }

    public function index(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $latestJob = $this->invoiceRepository->latestFetchJobForUser((int) $user['id']);
        $formData = $this->defaultFormData($latestJob, [
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ]);

        $invoices = [];
        if ($this->validators->validateDateRange($formData['date_from'], $formData['date_to']) === []) {
            $invoices = $this->invoiceRepository->listByDateRangeForUser(
                (int) $user['id'],
                $formData['date_from'],
                $formData['date_to']
            );
        }

        return $this->renderPage(
            settings: $this->applicationSettings->snapshot(),
            formData: $formData,
            fetchJob: $latestJob,
            invoices: $invoices,
            currentView: $this->resolveViewMode((string) $request->query('view', 'table'))
        );
    }

    public function fetch(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.');

            return Response::redirect($this->config->url('/ksef') . '#ksef-fetch-form');
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $formData = [
            'date_from' => trim((string) $request->input('date_from')),
            'date_to' => trim((string) $request->input('date_to')),
        ];
        $currentView = $this->resolveViewMode((string) $request->input('view_mode', 'table'));
        $settings = $this->applicationSettings->snapshot();
        $validationErrors = $this->validators->validateDateRange($formData['date_from'], $formData['date_to']);

        if ($validationErrors !== []) {
            return $this->renderPage(
                settings: $settings,
                formData: $formData,
                fetchJob: $this->invoiceRepository->latestFetchJobForUser((int) $user['id']),
                invoices: [],
                alerts: $this->alertsFromMessages('error', $validationErrors),
                scrollTarget: '#ksef-fetch-form',
                currentView: $currentView
            );
        }

        $jobId = $this->invoiceRepository->createFetchJob(
            (int) $user['id'],
            (string) $settings['ksef']['environment'],
            $formData['date_from'],
            $formData['date_to']
        );

        try {
            $payloads = $this->ksefClient->getCostInvoicesByDateRange(
                new DateTimeImmutable($formData['date_from']),
                new DateTimeImmutable($formData['date_to'])
            );

            $pdo = $this->invoiceRepository->database()->connection();
            $pdo->beginTransaction();

            $warningCount = 0;
            $errorCount = 0;
            $savedCount = 0;

            foreach ($payloads as $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $invoice = $this->invoiceMapper->mapFromKsefPayload($payload);
                $validation = $this->validators->validateFetchedInvoice($invoice);
                $invoice['bank_account'] = $validation['bank_account'];
                $invoice['validation_status'] = $validation['status'];
                $invoice['validation_notes'] = $validation['notes'];

                if (!empty($payload['fetch_warning'])) {
                    $invoice['validation_notes'] = trim($invoice['validation_notes'] . ' ' . $payload['fetch_warning']);
                    if ($invoice['validation_status'] === 'ok') {
                        $invoice['validation_status'] = 'warning';
                    }
                    $warningCount++;
                }

                $warningCount += (int) $validation['warning_count'];
                $errorCount += (int) $validation['error_count'];

                $this->invoiceRepository->saveFetchedInvoice((int) $user['id'], $invoice);
                $savedCount++;
            }

            $jobStatus = $warningCount > 0 || $errorCount > 0 ? 'completed_with_flags' : 'completed';
            $this->invoiceRepository->finishFetchJob($jobId, $jobStatus, $savedCount, $warningCount);
            $pdo->commit();

            $alerts = [];
            if ($savedCount === 0) {
                $alerts[] = [
                    'type' => 'warning',
                    'message' => 'Pobranie zakonczone, ale KSeF nie zwrocil zadnych faktur w tym zakresie dat.',
                ];
            } else {
                $summary = sprintf('Pobrano i zapisano %d faktur.', $savedCount);
                if ($warningCount > 0 || $errorCount > 0) {
                    $summary .= sprintf(' Wykryto %d ostrzezen recznych i %d bledow walidacji.', $warningCount, $errorCount);
                }

                $alerts[] = [
                    'type' => $warningCount > 0 || $errorCount > 0 ? 'warning' : 'info',
                    'message' => $summary,
                ];
            }

            $this->auditLogRepository->log(
                action: 'ksef_fetch_completed',
                userId: (int) $user['id'],
                entityType: 'invoice_fetch_job',
                entityId: $jobId,
                context: [
                    'environment' => $settings['ksef']['environment'],
                    'date_from' => $formData['date_from'],
                    'date_to' => $formData['date_to'],
                    'invoice_count' => $savedCount,
                    'warning_count' => $warningCount,
                    'error_count' => $errorCount,
                ],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            return $this->renderPage(
                settings: $settings,
                formData: $formData,
                fetchJob: $this->invoiceRepository->fetchJobById((int) $user['id'], $jobId),
                invoices: $this->invoiceRepository->listByDateRangeForUser(
                    (int) $user['id'],
                    $formData['date_from'],
                    $formData['date_to']
                ),
                alerts: $alerts,
                scrollTarget: '#ksef-fetch-form',
                currentView: $currentView
            );
        } catch (Throwable $exception) {
            $pdo = $this->invoiceRepository->database()->connection();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->invoiceRepository->finishFetchJob($jobId, 'failed', 0, 0, $exception->getMessage());
            $this->auditLogRepository->log(
                action: 'ksef_fetch_failed',
                userId: (int) $user['id'],
                entityType: 'invoice_fetch_job',
                entityId: $jobId,
                context: [
                    'environment' => $settings['ksef']['environment'],
                    'date_from' => $formData['date_from'],
                    'date_to' => $formData['date_to'],
                    'error' => $exception->getMessage(),
                ],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            return $this->renderPage(
                settings: $settings,
                formData: $formData,
                fetchJob: $this->invoiceRepository->fetchJobById((int) $user['id'], $jobId),
                invoices: [],
                alerts: [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                scrollTarget: '#ksef-fetch-form',
                currentView: $currentView
            );
        }
    }

    public function export(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $jobId = (int) $request->query('job_id', 0);
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        if ($jobId > 0) {
            $job = $this->invoiceRepository->fetchJobById((int) $user['id'], $jobId);
            if ($job === null) {
                $this->flash->add('error', 'Nie znaleziono wskazanego pobrania KSeF.');

                return Response::redirect($this->config->url('/ksef') . '#ksef-results');
            }

            $dateFrom = (string) $job['date_from'];
            $dateTo = (string) $job['date_to'];
        }

        $validationErrors = $this->validators->validateDateRange($dateFrom, $dateTo);
        if ($validationErrors !== []) {
            $this->flash->add('error', implode(' ', $validationErrors));

            return Response::redirect($this->config->url('/ksef') . '#ksef-fetch-form');
        }

        $invoices = $this->invoiceRepository->listByDateRangeForUser((int) $user['id'], $dateFrom, $dateTo);
        if ($invoices === []) {
            $this->flash->add('warning', 'Brak faktur do eksportu w wybranym zakresie.');

            return Response::redirect($this->config->url('/ksef') . '#ksef-results');
        }

        $content = $this->csvExporter->export(
            array_map(
                fn (array $invoice, int $index): array => $this->mapInvoiceToCsvRow($invoice, $index + 1),
                $invoices,
                array_keys($invoices)
            ),
            ['Lp', 'Wystawca', 'Numer faktury', 'Rachunek bankowy', 'Tytul platnosci', 'Kwota netto', 'Kwota brutto', 'Kwota do zaplaty', 'VAT', 'Termin platnosci', 'Status platnosci', 'Status walidacji', 'Uwagi']
        );

        $this->auditLogRepository->log(
            action: 'ksef_export_csv',
            userId: (int) $user['id'],
            entityType: 'invoice_export',
            entityId: null,
            context: [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'invoice_count' => count($invoices),
            ],
            ipAddress: $request->clientIp(),
            userAgent: $request->userAgent()
        );

        return new Response(
            $content,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    $this->buildExportFileName($dateFrom, $dateTo)
                ),
            ]
        );
    }

    private function renderPage(
        array $settings,
        array $formData,
        ?array $fetchJob,
        array $invoices,
        array $alerts = [],
        string $scrollTarget = '',
        string $currentView = 'table'
    ): Response {
        $environment = (string) $settings['ksef']['environment'];
        $environmentSettings = is_array($settings['ksef'][$environment] ?? null) ? $settings['ksef'][$environment] : [];

        return Response::html($this->view->render('ksef_fetch', [
            'title' => 'Pobieranie z KSeF',
            'pageTitle' => 'Pobieranie faktur kosztowych z KSeF',
            'pageDescription' => 'Tutaj pobierzesz faktury kosztowe za wybrany zakres dat, zapiszesz je w bazie i wyeksportujesz do CSV.',
            'alerts' => $alerts,
            'scrollTarget' => $scrollTarget,
            'formData' => $formData,
            'fetchJob' => $fetchJob,
            'invoices' => $this->decorateInvoices($invoices),
            'currentView' => $currentView,
            'activeEnvironment' => $environment,
            'environmentBaseUrl' => (string) ($environmentSettings['base_url'] ?? ''),
            'contextNip' => (string) ($settings['ksef']['context_nip'] ?? ''),
            'tokenPresenceLabel' => $this->validators->maskSecretPresence((bool) ($environmentSettings['token_present'] ?? false)),
        ]));
    }

    private function defaultFormData(?array $latestJob, array $requested = []): array
    {
        $today = new DateTimeImmutable('today');
        $monthStart = $today->modify('first day of this month');

        return [
            'date_from' => $requested['date_from'] !== '' ? $requested['date_from'] : (string) ($latestJob['date_from'] ?? $monthStart->format('Y-m-d')),
            'date_to' => $requested['date_to'] !== '' ? $requested['date_to'] : (string) ($latestJob['date_to'] ?? $today->format('Y-m-d')),
        ];
    }

    private function alertsFromMessages(string $type, array $messages): array
    {
        return array_map(
            static fn (string $message): array => ['type' => $type, 'message' => $message],
            $messages
        );
    }

    private function mapInvoiceToCsvRow(array $invoice, int $position): array
    {
        $invoice = $this->hydrateInvoiceForOutput($invoice);

        return [
            $position,
            $invoice['issuer_name'] ?? '',
            $invoice['invoice_number'] ?? '',
            $this->validators->formatBankAccount($invoice['bank_account'] ?? null),
            $invoice['payment_description'] ?? '',
            $this->formatCsvDecimal($invoice['net_amount'] ?? null),
            $this->formatCsvDecimal($invoice['gross_amount'] ?? null),
            $this->formatCsvDecimal($invoice['amount_due'] ?? null),
            $this->formatCsvDecimal($invoice['vat_amount'] ?? null),
            $invoice['due_date'] ?? '',
            $invoice['payment_status_label'] ?? '',
            $invoice['validation_status'] ?? '',
            $invoice['validation_notes'] ?? '',
        ];
    }

    private function buildExportFileName(string $dateFrom, string $dateTo): string
    {
        return sprintf(
            'ksef-faktury-%s-do-%s.csv',
            str_replace('-', '', $dateFrom),
            str_replace('-', '', $dateTo)
        );
    }

    private function decorateInvoices(array $invoices): array
    {
        return array_map(function (array $invoice): array {
            $invoice = $this->hydrateInvoiceForOutput($invoice);
            $status = (string) ($invoice['validation_status'] ?? 'warning');

            return $invoice + [
                'formatted_bank_account' => $this->validators->formatBankAccount($invoice['bank_account'] ?? null),
                'formatted_net_amount' => $this->formatMoney($invoice['net_amount'] ?? null, $invoice['currency'] ?? 'PLN'),
                'formatted_gross_amount' => $this->formatMoney($invoice['gross_amount'] ?? null, $invoice['currency'] ?? 'PLN'),
                'formatted_amount_due' => $this->formatMoney($invoice['amount_due'] ?? null, $invoice['currency'] ?? 'PLN'),
                'formatted_vat_amount' => $this->formatMoney($invoice['vat_amount'] ?? null, $invoice['currency'] ?? 'PLN'),
                'status_badge_class' => match ($status) {
                    'ok' => 'ok',
                    'error' => 'error',
                    default => 'warn',
                },
                'status_label' => match ($status) {
                    'ok' => 'OK',
                    'manual_review' => 'Do sprawdzenia',
                    'error' => 'Blad',
                    default => 'Ostrzezenie',
                },
                'payment_status_label' => match ((string) ($invoice['payment_status'] ?? 'unknown')) {
                    'paid' => 'Zaplacona' . (!empty($invoice['payment_date']) ? ' (' . $invoice['payment_date'] . ')' : ''),
                    'to_pay' => 'Do zaplaty',
                    default => 'Brak danych',
                },
            ];
        }, $invoices);
    }

    private function hydrateInvoiceForOutput(array $invoice): array
    {
        $rawPayload = $invoice['raw_payload_json'] ?? null;
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            return $invoice;
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            return $invoice;
        }

        $derived = $this->invoiceMapper->mapFromKsefPayload($decoded);

        $merged = $invoice;
        foreach (['bank_account', 'due_date', 'payment_description', 'payment_status', 'payment_date', 'amount_due'] as $key) {
            if (!empty($derived[$key])) {
                $merged[$key] = $derived[$key];
            }
        }

        foreach (['net_amount', 'gross_amount', 'vat_amount', 'currency'] as $key) {
            if ((empty($merged[$key]) || $merged[$key] === null) && !empty($derived[$key])) {
                $merged[$key] = $derived[$key];
            }
        }

        $validation = $this->validators->validateFetchedInvoice($merged);
        $merged['bank_account'] = $validation['bank_account'];
        $merged['validation_status'] = $validation['status'];
        $merged['validation_notes'] = $validation['notes'];

        return $merged;
    }

    private function resolveViewMode(string $view): string
    {
        return in_array($view, ['table', 'cards'], true) ? $view : 'table';
    }

    private function formatCsvDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric((string) $value)) {
            return number_format((float) $value, 2, ',', '');
        }

        return (string) $value;
    }

    private function formatMoney(mixed $value, string $currency = 'PLN'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric((string) $value)) {
            return trim((string) $value . ' ' . $currency);
        }

        return trim(number_format((float) $value, 2, ',', ' ') . ' ' . $currency);
    }
}
