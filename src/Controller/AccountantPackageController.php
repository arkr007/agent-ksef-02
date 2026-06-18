<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Service\AccountantPackageSummaryService;
use App\Service\ApplicationSettings;
use App\Service\CsvExporter;
use App\Service\DocumentInboxCatalogService;
use App\Service\InvoiceMapper;
use App\Service\KsefClient;
use App\Service\PdfInboxAnalysisService;
use App\Service\PdfInvoiceCandidateParser;
use App\Service\RecurringIssuerCatalogService;
use Throwable;

final class AccountantPackageController
{
    private const SESSION_KSEF_KEY = 'accountant_package_current_ksef';
    private const SESSION_PDF_ANALYSIS_KEY = 'accountant_package_current_pdf_analysis';
    private const SESSION_PDF_CANDIDATES_KEY = 'accountant_package_current_pdf_candidates';

    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private ApplicationSettings $applicationSettings,
        private KsefClient $ksefClient,
        private InvoiceMapper $invoiceMapper,
        private CsvExporter $csvExporter,
        private RecurringIssuerCatalogService $recurringIssuerCatalogService,
        private DocumentInboxCatalogService $documentInboxCatalogService,
        private PdfInboxAnalysisService $pdfInboxAnalysisService,
        private AccountantPackageSummaryService $accountantPackageSummaryService,
        private PdfInvoiceCandidateParser $pdfInvoiceCandidateParser
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

        $userId = (int) $user['id'];
        $alerts = [[
            'type' => 'info',
            'message' => 'Krok 1 gotowy: ekran, routing i szkielet modułu są przygotowane. W kolejnych krokach dojdzie odczyt CSV, PDF i KSeF.',
        ]];
        $catalog = null;
        $documentCatalog = null;

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalog();
            $alerts[] = [
                'type' => 'info',
                'message' => sprintf(
                    'Krok 2 gotowy: wczytano %d stałych wystawców i %d oczekiwanych faktur miesięcznych.',
                    (int) ($catalog['summary']['issuer_count'] ?? 0),
                    (int) ($catalog['summary']['expected_invoice_count'] ?? 0)
                ),
            ];
        } catch (\Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        try {
            $documentCatalog = $this->documentInboxCatalogService->loadCatalog();
            $alerts[] = [
                'type' => 'info',
                'message' => sprintf(
                    'Krok 4 gotowy: wykryto %d plików PDF i %d innych plików w lokalnym folderze dokumentów.',
                    (int) ($documentCatalog['summary']['pdf_count'] ?? 0),
                    (int) ($documentCatalog['summary']['other_file_count'] ?? 0)
                ),
            ];
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        $selectedMonth = trim((string) $request->query('ksef_month', ''));
        if ($selectedMonth === '') {
            $sessionPackage = $this->currentKsefPackage($userId);
            $selectedMonth = is_array($sessionPackage) && isset($sessionPackage['selected_month'])
                ? (string) $sessionPackage['selected_month']
                : date('Y-m');
        }

        return $this->renderPage(
            $userId,
            alerts: $alerts,
            catalog: $catalog,
            documentCatalog: $documentCatalog,
            selectedMonth: $selectedMonth
        );
    }

    public function fetchKsef(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $selectedMonth = trim((string) $request->input('ksef_month'));
        $catalog = null;
        $documentCatalog = null;
        $alerts = [];

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        try {
            $documentCatalog = $this->documentInboxCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Nieprawidłowy token CSRF. Odśwież formularz i spróbuj ponownie.',
            ];

            return $this->renderPage(
                $userId,
                alerts: $alerts,
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-ksef-form'
            );
        }

        $monthRange = $this->resolveMonthRange($selectedMonth);
        if ($monthRange === null) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Wybierz poprawny miesiąc w formacie RRRR-MM.',
            ];

            return $this->renderPage(
                $userId,
                alerts: $alerts,
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth,
                scrollTarget: '#accountant-package-ksef-form'
            );
        }

        try {
            $package = $this->buildKsefPackage($userId, $monthRange['year'], $monthRange['month']);
            $this->storeKsefPackage($package);

            $alerts[] = [
                'type' => 'info',
                'message' => sprintf(
                    'Krok 3 gotowy: pobrano %d faktur kosztowych KSeF dla miesiąca %s.',
                    (int) ($package['summary']['invoice_count'] ?? 0),
                    $selectedMonth
                ),
            ];

            return $this->renderPage(
                $userId,
                alerts: $alerts,
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                ksefPackage: $package,
                selectedMonth: $selectedMonth,
                scrollTarget: '#accountant-package-ksef-results'
            );
        } catch (Throwable $exception) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth,
                scrollTarget: '#accountant-package-ksef-form'
            );
        }
    }

    public function analyzePdfInbox(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $selectedMonth = trim((string) $request->input('selected_month'));
        $catalog = null;
        $documentCatalog = null;
        $alerts = [];

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        try {
            $documentCatalog = $this->documentInboxCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => 'Nieprawidłowy token CSRF. Odśwież formularz i spróbuj ponownie.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-analysis-form'
            );
        }

        if ($documentCatalog === null) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => 'Nie udało się przygotować listy plików PDF do analizy.',
                ]]),
                catalog: $catalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-analysis-form'
            );
        }

        $pdfFiles = array_values((array) ($documentCatalog['pdf_files'] ?? []));
        if ($pdfFiles === []) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'W folderze roboczym nie ma plików PDF do analizy.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-analysis-form'
            );
        }

        $package = $this->pdfInboxAnalysisService->analyze($pdfFiles);
        $this->storePdfAnalysisPackage($userId, $package);

        return $this->renderPage(
            $userId,
            alerts: array_merge($alerts, [[
                'type' => 'info',
                'message' => sprintf(
                    'Krok 5 gotowy: przeanalizowano %d plików PDF. %d ma warstwę tekstową, %d wygląda na skan lub pusty PDF.',
                    (int) ($package['summary']['document_count'] ?? 0),
                    (int) ($package['summary']['text_ready_count'] ?? 0),
                    (int) ($package['summary']['scan_like_count'] ?? 0)
                ),
            ]]),
            catalog: $catalog,
            documentCatalog: $documentCatalog,
            pdfAnalysisPackage: $package,
            selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
            scrollTarget: '#accountant-package-pdf-analysis-results'
        );
    }

    public function parsePdfCandidates(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $selectedMonth = trim((string) $request->input('selected_month'));
        $catalog = null;
        $documentCatalog = null;
        $alerts = [];

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = ['type' => 'warning', 'message' => $exception->getMessage()];
        }

        try {
            $documentCatalog = $this->documentInboxCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = ['type' => 'warning', 'message' => $exception->getMessage()];
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => 'Nieprawidłowy token CSRF. Odśwież formularz i spróbuj ponownie.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-candidates-form'
            );
        }

        if ($documentCatalog === null) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => 'Nie udało się przygotować listy plików PDF do parsowania.',
                ]]),
                catalog: $catalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-candidates-form'
            );
        }

        $pdfFiles = array_values((array) ($documentCatalog['pdf_files'] ?? []));
        if ($pdfFiles === []) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'W folderze roboczym nie ma plików PDF do parsowania.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-pdf-candidates-form'
            );
        }

        $package = $this->pdfInvoiceCandidateParser->parseFiles($pdfFiles);
        $this->storePdfCandidatesPackage($userId, $package);

        return $this->renderPage(
            $userId,
            alerts: array_merge($alerts, [[
                'type' => 'info',
                'message' => sprintf(
                    'Krok 7 gotowy: z PDF-ów rozpoznano %d kandydatów dokumentów z %d plików.',
                    (int) ($package['summary']['parsed_document_count'] ?? 0),
                    (int) ($package['summary']['source_file_count'] ?? 0)
                ),
            ]]),
            catalog: $catalog,
            documentCatalog: $documentCatalog,
            selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
            pdfCandidatesPackage: $package,
            scrollTarget: '#accountant-package-pdf-candidates-results'
        );
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

        $userId = (int) $user['id'];
        $selectedMonth = trim((string) $request->query('selected_month', ''));
        $alerts = [];

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            return $this->renderPage(
                $userId,
                alerts: [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-summary'
            );
        }

        $documentCatalog = null;
        try {
            $documentCatalog = $this->documentInboxCatalogService->loadCatalog();
        } catch (Throwable $exception) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];
        }

        $ksefPackage = $this->currentKsefPackage($userId);
        $pdfAnalysisPackage = $this->currentPdfAnalysisPackage($userId);
        $pdfCandidatesPackage = $this->currentPdfCandidatesPackage($userId);

        if ($ksefPackage === null) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'Najpierw pobierz miesięczne faktury kosztowe z KSeF, a potem wróć do eksportu CSV.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                pdfAnalysisPackage: $pdfAnalysisPackage,
                pdfCandidatesPackage: $pdfCandidatesPackage,
                selectedMonth: $selectedMonth !== '' ? $selectedMonth : date('Y-m'),
                scrollTarget: '#accountant-package-summary'
            );
        }

        $packageSummary = $this->accountantPackageSummaryService->build($catalog, $ksefPackage, $pdfCandidatesPackage);
        $rows = $this->mapPackageSummaryToCsvRows($packageSummary);

        if ($rows === []) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'Brak danych do eksportu CSV dla bieżącego pakietu.',
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                ksefPackage: $ksefPackage,
                pdfAnalysisPackage: $pdfAnalysisPackage,
                pdfCandidatesPackage: $pdfCandidatesPackage,
                selectedMonth: (string) ($ksefPackage['selected_month'] ?? ($selectedMonth !== '' ? $selectedMonth : date('Y-m'))),
                scrollTarget: '#accountant-package-summary'
            );
        }

        $content = $this->csvExporter->export($rows, [
            'Sekcja',
            'Stały wystawca',
            'Status',
            'Spodziewane',
            'Znalezione',
            'Źródło',
            'Wystawca dokumentu',
            'Numer dokumentu',
            'Kwota',
            'Waluta',
            'Data',
            'Referencja źródła',
            'Uwagi',
        ]);

        return new Response(
            $content,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    $this->buildExportFileName((string) ($ksefPackage['selected_month'] ?? date('Y-m')))
                ),
            ]
        );
    }

    private function renderPage(
        int $userId,
        array $alerts = [],
        ?array $catalog = null,
        ?array $documentCatalog = null,
        ?array $ksefPackage = null,
        ?array $pdfAnalysisPackage = null,
        ?array $pdfCandidatesPackage = null,
        string $selectedMonth = '',
        string $scrollTarget = ''
    ): Response {
        $ksefPackage ??= $this->currentKsefPackage($userId);
        $pdfAnalysisPackage ??= $this->currentPdfAnalysisPackage($userId);
        $pdfCandidatesPackage ??= $this->currentPdfCandidatesPackage($userId);
        if ($selectedMonth === '') {
            $selectedMonth = is_array($ksefPackage) && isset($ksefPackage['selected_month'])
                ? (string) $ksefPackage['selected_month']
                : date('Y-m');
        }

        $settings = $this->applicationSettings->snapshot();
        $activeEnvironment = (string) ($settings['ksef']['environment'] ?? 'test');
        $environmentSettings = is_array($settings['ksef'][$activeEnvironment] ?? null)
            ? $settings['ksef'][$activeEnvironment]
            : [];
        $packageSummary = null;
        if ($catalog !== null && $ksefPackage !== null) {
            $packageSummary = $this->accountantPackageSummaryService->build($catalog, $ksefPackage, $pdfCandidatesPackage);
        }

        return Response::html($this->view->render('accountant_package', [
            'title' => 'Pakiet dla księgowej',
            'pageTitle' => 'Funkcja 4: pakiet dla księgowej',
            'pageDescription' => 'Moduł przygotuje kontrolę kompletnej paczki faktur kosztowych za wybrany miesiąc.',
            'alerts' => $alerts,
            'scrollTarget' => $scrollTarget,
            'desktopFolderPath' => $this->recurringIssuerCatalogService->expectedFolderPath(),
            'expectedCsvPath' => $this->recurringIssuerCatalogService->expectedCsvPath(),
            'catalog' => $catalog,
            'documentCatalog' => $documentCatalog,
            'selectedMonth' => $selectedMonth,
            'ksefPackage' => $ksefPackage,
            'pdfAnalysisPackage' => $pdfAnalysisPackage,
            'pdfCandidatesPackage' => $pdfCandidatesPackage,
            'packageSummary' => $packageSummary,
            'activeEnvironment' => $activeEnvironment,
            'environmentBaseUrl' => (string) ($environmentSettings['base_url'] ?? ''),
            'tokenPresenceLabel' => !empty($environmentSettings['token_present']) ? 'ustawione' : 'brak',
        ]));
    }

    private function buildKsefPackage(int $userId, int $year, int $month): array
    {
        $payloads = $this->ksefClient->getCachedCostInvoiceMetadataByMonth($year, $month);
        $invoices = [];
        $position = 1;

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $invoice = $this->invoiceMapper->mapFromKsefPayload($payload);
            $invoice['position'] = $position++;
            $invoice['formatted_gross_amount'] = $this->formatMoney(
                $invoice['gross_amount'] ?? null,
                (string) ($invoice['currency'] ?? 'PLN')
            );
            $invoices[] = $invoice;
        }

        return [
            'user_id' => $userId,
            'selected_month' => sprintf('%04d-%02d', $year, $month),
            'fetched_at' => date('Y-m-d H:i:s'),
            'invoices' => $invoices,
            'summary' => [
                'invoice_count' => count($invoices),
                'issuer_count' => $this->countUniqueIssuers($invoices),
            ],
        ];
    }

    private function resolveMonthRange(string $value): ?array
    {
        if (preg_match('/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])$/', $value, $matches) !== 1) {
            return null;
        }

        return [
            'year' => (int) $matches['year'],
            'month' => (int) $matches['month'],
        ];
    }

    private function countUniqueIssuers(array $invoices): int
    {
        $issuers = [];

        foreach ($invoices as $invoice) {
            $name = mb_strtolower(trim((string) ($invoice['issuer_name'] ?? '')));
            $taxId = preg_replace('/\D+/', '', (string) ($invoice['issuer_tax_id'] ?? '')) ?? '';
            $key = $taxId !== '' ? $taxId : $name;
            if ($key === '') {
                continue;
            }

            $issuers[$key] = true;
        }

        return count($issuers);
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

    private function mapPackageSummaryToCsvRows(array $packageSummary): array
    {
        $rows = [];

        foreach ((array) ($packageSummary['recurring_rows'] ?? []) as $row) {
            $matchedInvoices = (array) ($row['matched_invoices'] ?? []);

            if ($matchedInvoices === []) {
                $rows[] = [
                    'STAŁY_WYSTAWCA',
                    (string) ($row['issuer_name'] ?? ''),
                    (string) ($row['status_label'] ?? ''),
                    (int) ($row['expected_invoice_count'] ?? 0),
                    (int) ($row['matched_invoice_count'] ?? 0),
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    'Brak dopasowanych dokumentów.',
                ];
                continue;
            }

            foreach ($matchedInvoices as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $rows[] = [
                    'STAŁY_WYSTAWCA',
                    (string) ($row['issuer_name'] ?? ''),
                    (string) ($row['status_label'] ?? ''),
                    (int) ($row['expected_invoice_count'] ?? 0),
                    (int) ($row['matched_invoice_count'] ?? 0),
                    (string) ($document['source_label'] ?? ''),
                    (string) ($document['issuer_name'] ?? ''),
                    (string) ($document['invoice_number'] ?? ''),
                    $this->csvAmount($document['amount_due'] ?? $document['gross_amount'] ?? null),
                    (string) ($document['currency'] ?? ''),
                    (string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : ($document['issue_date'] ?? '')),
                    (string) ($document['source_reference'] ?? ''),
                    (string) ($document['match_note'] ?? ''),
                ];
            }
        }

        foreach ((array) ($packageSummary['other_invoices'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $rows[] = [
                'INNE',
                '',
                '',
                '',
                '',
                (string) ($document['source_label'] ?? ''),
                (string) ($document['issuer_name'] ?? ''),
                (string) ($document['invoice_number'] ?? ''),
                $this->csvAmount($document['amount_due'] ?? $document['gross_amount'] ?? null),
                (string) ($document['currency'] ?? ''),
                (string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : ($document['issue_date'] ?? '')),
                (string) ($document['source_reference'] ?? ''),
                (string) ($document['match_note'] ?? ''),
            ];
        }

        foreach ((array) ($packageSummary['manual_review_documents'] ?? []) as $document) {
            if (!is_array($document)) {
                continue;
            }

            $rows[] = [
                'RĘCZNA_WERYFIKACJA',
                '',
                (string) ($document['status_label'] ?? 'Do ręcznej weryfikacji'),
                '',
                '',
                (string) ($document['source_label'] ?? ''),
                (string) ($document['issuer_name'] ?? ''),
                (string) ($document['invoice_number'] ?? ''),
                $this->csvAmount($document['amount_due'] ?? $document['gross_amount'] ?? null),
                (string) ($document['currency'] ?? ''),
                (string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : ($document['issue_date'] ?? '')),
                (string) ($document['source_reference'] ?? ''),
                (string) ($document['match_note'] ?? $document['note'] ?? ''),
            ];
        }

        return $rows;
    }

    private function csvAmount(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return is_numeric((string) $value) ? round((float) $value, 2) : (string) $value;
    }

    private function buildExportFileName(string $selectedMonth): string
    {
        $normalizedMonth = preg_match('/^\d{4}-\d{2}$/', $selectedMonth) === 1
            ? $selectedMonth
            : date('Y-m');

        return 'pakiet-dla-ksiegowej-' . str_replace('-', '', $normalizedMonth) . '.csv';
    }

    private function storeKsefPackage(array $package): void
    {
        $_SESSION[self::SESSION_KSEF_KEY] = $package;
    }

    private function currentKsefPackage(int $userId): ?array
    {
        $package = $_SESSION[self::SESSION_KSEF_KEY] ?? null;
        if (!is_array($package)) {
            return null;
        }

        if ((int) ($package['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_KSEF_KEY]);

            return null;
        }

        return $package;
    }

    private function storePdfAnalysisPackage(int $userId, array $package): void
    {
        $_SESSION[self::SESSION_PDF_ANALYSIS_KEY] = [
            'user_id' => $userId,
            'package' => $package,
        ];
    }

    private function currentPdfAnalysisPackage(int $userId): ?array
    {
        $stored = $_SESSION[self::SESSION_PDF_ANALYSIS_KEY] ?? null;
        if (!is_array($stored)) {
            return null;
        }

        if ((int) ($stored['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_PDF_ANALYSIS_KEY]);

            return null;
        }

        $package = $stored['package'] ?? null;

        return is_array($package) ? $package : null;
    }

    private function storePdfCandidatesPackage(int $userId, array $package): void
    {
        $_SESSION[self::SESSION_PDF_CANDIDATES_KEY] = [
            'user_id' => $userId,
            'package' => $package,
        ];
    }

    private function currentPdfCandidatesPackage(int $userId): ?array
    {
        $stored = $_SESSION[self::SESSION_PDF_CANDIDATES_KEY] ?? null;
        if (!is_array($stored)) {
            return null;
        }

        if ((int) ($stored['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_PDF_CANDIDATES_KEY]);

            return null;
        }

        $package = $stored['package'] ?? null;

        return is_array($package) ? $package : null;
    }
}
