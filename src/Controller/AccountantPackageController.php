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
use App\Service\PdfInvoiceCandidateParser;
use App\Service\RecurringIssuerCatalogService;
use Throwable;

final class AccountantPackageController
{
    private const SESSION_KSEF_KEY = 'accountant_package_current_ksef';
    private const SESSION_PDF_CANDIDATES_KEY = 'accountant_package_current_pdf_candidates';
    private const SESSION_PACKAGE_SUMMARY_KEY = 'accountant_package_current_summary';
    private const SESSION_SOURCE_META_KEY = 'accountant_package_current_source_meta';
    private const REMOTE_AI_PROVIDERS = ['hybrid', 'openai'];

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
        private AccountantPackageSummaryService $accountantPackageSummaryService,
        private PdfInvoiceCandidateParser $pdfInvoiceCandidateParser
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

        $userId = (int) $user['id'];
        $alerts = [];

        return $this->renderPage(
            $userId,
            alerts: $alerts,
            selectedMonth: '',
            checklistState: $this->emptyChecklistState()
        );
    }

    public function run(Request $request): Response
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
        $checklistState = [
            'confirm_pdf_ready' => $request->input('confirm_pdf_ready') === '1',
            'confirm_csv_ready' => $request->input('confirm_csv_ready') === '1',
        ];
        $alerts = [];
        $catalog = null;
        $documentCatalog = null;
        $uploadedCsv = $request->file('csv_file');
        $uploadedPdfGroup = $request->file('pdf_files');
        $pdfFiles = $this->normalizeUploadedFiles(is_array($uploadedPdfGroup) ? $uploadedPdfGroup : null);

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.',
                ]]),
                selectedMonth: $selectedMonth,
                checklistState: $checklistState,
                scrollTarget: '#accountant-package-checklist'
            );
        }

        if (!$checklistState['confirm_pdf_ready']) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Potwierdz, ze wybrales pakiet faktur PDF do jednorazowego przetworzenia.',
            ];
        }

        if (!$checklistState['confirm_csv_ready']) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Potwierdz, ze wybrales aktualna liste stalych wystawcow.',
            ];
        }

        $monthRange = $this->resolveMonthRange($selectedMonth);
        if ($monthRange === null) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Wybierz poprawny miesiac do pobrania faktur z KSeF.',
            ];
        }

        if (!is_array($uploadedCsv) || (int) ($uploadedCsv['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Wybierz plik CSV z lista stalych wystawcow.',
            ];
        }

        if ($pdfFiles === []) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Wybierz folder lub zestaw plikow PDF do jednorazowego przetworzenia.',
            ];
        }

        if ($alerts !== []) {
            return $this->renderPage(
                $userId,
                alerts: $alerts,
                selectedMonth: $selectedMonth,
                checklistState: $checklistState,
                scrollTarget: '#accountant-package-checklist'
            );
        }

        try {
            $catalog = $this->recurringIssuerCatalogService->loadCatalogFromUploadedCsv($uploadedCsv, 'Jednorazowy upload CSV');
            $documentCatalog = $this->documentInboxCatalogService->buildCatalogFromUploadedFiles($pdfFiles, 'Jednorazowy upload PDF');
            $ksefPackage = $this->buildKsefPackage($userId, (int) $monthRange['year'], (int) $monthRange['month']);
            $pdfCandidatesPackage = $this->pdfInvoiceCandidateParser->parseFiles($pdfFiles);
            $packageSummary = $this->accountantPackageSummaryService->build($catalog, $ksefPackage, $pdfCandidatesPackage);

            $this->storeKsefPackage($ksefPackage);
            $this->storePdfCandidatesPackage($userId, $pdfCandidatesPackage);
            $this->storePackageSummary($userId, $packageSummary);
            $this->storeSourceMeta($userId, [
                'pdf_selection_label' => $this->describePdfSelection($pdfFiles),
                'csv_selection_label' => trim((string) ($uploadedCsv['name'] ?? 'stali_wystawcy.csv')),
                'pdf_count' => (int) ($documentCatalog['summary']['pdf_count'] ?? 0),
                'issuer_count' => (int) ($catalog['summary']['issuer_count'] ?? 0),
            ]);

            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'info',
                    'message' => sprintf(
                        'Zestawienie zostalo wygenerowane. Pobrano %d faktur KSeF i rozpoznano %d dokumentow z PDF dla miesiaca %s.',
                        (int) ($ksefPackage['summary']['invoice_count'] ?? 0),
                        (int) ($pdfCandidatesPackage['summary']['parsed_document_count'] ?? 0),
                        $selectedMonth
                    ),
                ]]),
                catalog: $catalog,
                documentCatalog: $documentCatalog,
                ksefPackage: $ksefPackage,
                packageSummary: $packageSummary,
                selectedMonth: '',
                checklistState: $this->emptyChecklistState(),
                scrollTarget: '#accountant-package-summary'
            );
        } catch (Throwable $exception) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]),
                selectedMonth: $selectedMonth,
                checklistState: $checklistState,
                scrollTarget: '#accountant-package-checklist'
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

        $userId = (int) $user['id'];
        $selectedMonth = trim((string) $request->query('selected_month', ''));
        $alerts = [];
        $ksefPackage = $this->currentKsefPackage($userId);
        $packageSummary = $this->currentPackageSummary($userId);

        if ($ksefPackage === null || $packageSummary === null) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'Najpierw uruchom funkcje i wygeneruj zestawienie, a dopiero potem pobierz CSV.',
                ]]),
                selectedMonth: '',
                checklistState: $this->emptyChecklistState(),
                scrollTarget: '#accountant-package-summary'
            );
        }

        $rows = $this->mapPackageSummaryToCsvRows($packageSummary);

        if ($rows === []) {
            return $this->renderPage(
                $userId,
                alerts: array_merge($alerts, [[
                    'type' => 'warning',
                    'message' => 'Brak danych do eksportu CSV dla biezacego pakietu.',
                ]]),
                ksefPackage: $ksefPackage,
                packageSummary: $packageSummary,
                selectedMonth: '',
                checklistState: $this->emptyChecklistState(),
                scrollTarget: '#accountant-package-summary'
            );
        }

        $content = $this->csvExporter->export($rows, [
            'Sekcja',
            'Staly wystawca',
            'Status',
            'Spodziewane',
            'Znalezione',
            'Zrodlo',
            'Wystawca dokumentu',
            'Numer dokumentu',
            'Kwota',
            'Waluta',
            'Data',
            'Referencja zrodla',
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
        ?array $packageSummary = null,
        string $selectedMonth = '',
        string $scrollTarget = '',
        array $checklistState = []
    ): Response {
        $sourceMeta = $this->currentSourceMeta($userId);
        $catalog ??= $this->catalogFromSourceMeta($sourceMeta);
        $documentCatalog ??= $this->documentCatalogFromSourceMeta($sourceMeta);
        $ksefPackage ??= $this->currentKsefPackage($userId);
        $packageSummary ??= $this->currentPackageSummary($userId);
        $checklistState = array_replace($this->emptyChecklistState(), $checklistState);

        $settings = $this->applicationSettings->snapshot();
        $activeEnvironment = (string) ($settings['ksef']['environment'] ?? 'test');
        $environmentSettings = is_array($settings['ksef'][$activeEnvironment] ?? null)
            ? $settings['ksef'][$activeEnvironment]
            : [];
        $aiProvider = (string) ($settings['ai']['provider'] ?? 'ollama');

        return $this->viewResponse(
            $alerts,
            $catalog,
            $documentCatalog,
            $selectedMonth,
            $checklistState,
            $ksefPackage,
            $packageSummary,
            $activeEnvironment,
            $environmentSettings,
            $aiProvider,
            $scrollTarget,
            $sourceMeta
        );
    }

    private function viewResponse(
        array $alerts,
        ?array $catalog,
        ?array $documentCatalog,
        string $selectedMonth,
        array $checklistState,
        ?array $ksefPackage,
        ?array $packageSummary,
        string $activeEnvironment,
        array $environmentSettings,
        string $aiProvider,
        string $scrollTarget,
        ?array $sourceMeta
    ): Response {
        $pdfSelectionLabel = trim((string) ($sourceMeta['pdf_selection_label'] ?? 'wybierz folder PDF w formularzu ponizej'));
        $csvSelectionLabel = trim((string) ($sourceMeta['csv_selection_label'] ?? 'wybierz plik CSV w formularzu ponizej'));

        return Response::html($this->view->render('accountant_package', [
            'title' => 'Pakiet dla ksiegowej',
            'pageTitle' => 'Pakiet dla ksiegowej',
            'pageDescription' => 'Przygotuj miesieczne zestawienie faktur kosztowych do przekazania biuru rachunkowemu.',
            'alerts' => $alerts,
            'scrollTarget' => $scrollTarget,
            'desktopFolderPath' => $pdfSelectionLabel,
            'expectedCsvPath' => $csvSelectionLabel,
            'catalog' => $catalog,
            'documentCatalog' => $documentCatalog,
            'selectedMonth' => $selectedMonth,
            'checklistState' => $checklistState,
            'ksefPackage' => $ksefPackage,
            'packageSummary' => $packageSummary,
            'activeEnvironment' => $activeEnvironment,
            'environmentBaseUrl' => (string) ($environmentSettings['base_url'] ?? ''),
            'tokenPresenceLabel' => !empty($environmentSettings['token_present']) ? 'ustawione' : 'brak',
            'aiProvider' => $aiProvider,
            'requiresRemoteAiConfirmation' => in_array($aiProvider, self::REMOTE_AI_PROVIDERS, true),
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
                $rows[] = ['STALY_WYSTAWCA', (string) ($row['issuer_name'] ?? ''), (string) ($row['status_label'] ?? ''), (int) ($row['expected_invoice_count'] ?? 0), (int) ($row['matched_invoice_count'] ?? 0), '', '', '', '', '', '', '', 'Brak dopasowanych dokumentow.'];
                continue;
            }

            foreach ($matchedInvoices as $document) {
                if (!is_array($document)) {
                    continue;
                }

                $rows[] = [
                    'STALY_WYSTAWCA',
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
                'INNE', '', '', '', '',
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
                'RECZNA_WERYFIKACJA', '', (string) ($document['status_label'] ?? 'Do recznej weryfikacji'), '', '',
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
        $normalizedMonth = preg_match('/^\d{4}-\d{2}$/', $selectedMonth) === 1 ? $selectedMonth : date('Y-m');

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

    private function storePdfCandidatesPackage(int $userId, array $package): void
    {
        $_SESSION[self::SESSION_PDF_CANDIDATES_KEY] = ['user_id' => $userId, 'package' => $package];
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

    private function storePackageSummary(int $userId, array $packageSummary): void
    {
        $_SESSION[self::SESSION_PACKAGE_SUMMARY_KEY] = ['user_id' => $userId, 'package' => $packageSummary];
    }

    private function currentPackageSummary(int $userId): ?array
    {
        $stored = $_SESSION[self::SESSION_PACKAGE_SUMMARY_KEY] ?? null;
        if (!is_array($stored)) {
            return null;
        }

        if ((int) ($stored['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_PACKAGE_SUMMARY_KEY]);
            return null;
        }

        $package = $stored['package'] ?? null;
        return is_array($package) ? $package : null;
    }

    private function storeSourceMeta(int $userId, array $sourceMeta): void
    {
        $_SESSION[self::SESSION_SOURCE_META_KEY] = ['user_id' => $userId, 'meta' => $sourceMeta];
    }

    private function currentSourceMeta(int $userId): ?array
    {
        $stored = $_SESSION[self::SESSION_SOURCE_META_KEY] ?? null;
        if (!is_array($stored)) {
            return null;
        }

        if ((int) ($stored['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_SOURCE_META_KEY]);
            return null;
        }

        $meta = $stored['meta'] ?? null;
        return is_array($meta) ? $meta : null;
    }

    private function normalizeUploadedFiles(?array $fileGroup): array
    {
        if (!is_array($fileGroup)) {
            return [];
        }

        $names = $fileGroup['name'] ?? null;
        if (!is_array($names)) {
            return [];
        }

        $tmpNames = is_array($fileGroup['tmp_name'] ?? null) ? $fileGroup['tmp_name'] : [];
        $errors = is_array($fileGroup['error'] ?? null) ? $fileGroup['error'] : [];
        $sizes = is_array($fileGroup['size'] ?? null) ? $fileGroup['size'] : [];
        $fullPaths = is_array($fileGroup['full_path'] ?? null) ? $fileGroup['full_path'] : [];

        $files = [];

        foreach ($names as $index => $name) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmpName = trim((string) ($tmpNames[$index] ?? ''));
            if ($tmpName === '' || !is_file($tmpName)) {
                continue;
            }

            $originalName = trim((string) $name);
            $relativePath = trim((string) ($fullPaths[$index] ?? ''));
            $displayName = $relativePath !== '' ? basename(str_replace('\\', '/', $relativePath)) : $originalName;
            $extension = strtolower(pathinfo($displayName !== '' ? $displayName : $originalName, PATHINFO_EXTENSION));

            $files[] = [
                'name' => $displayName !== '' ? $displayName : $originalName,
                'path' => $tmpName,
                'extension' => $extension,
                'size_bytes' => (int) ($sizes[$index] ?? 0),
                'modified_at' => date('Y-m-d H:i:s'),
                'relative_path' => $relativePath,
            ];
        }

        return $files;
    }

    private function describePdfSelection(array $pdfFiles): string
    {
        if ($pdfFiles === []) {
            return 'brak wybranego pakietu PDF';
        }

        $firstRelativePath = trim((string) ($pdfFiles[0]['relative_path'] ?? ''));
        if ($firstRelativePath !== '') {
            $segments = preg_split('/[\/\\\\]+/', $firstRelativePath) ?: [];
            $folder = trim((string) ($segments[0] ?? ''));
            if ($folder !== '') {
                return $folder . ' (' . count($pdfFiles) . ' plikow PDF)';
            }
        }

        return 'jednorazowy wybor (' . count($pdfFiles) . ' plikow PDF)';
    }

    private function catalogFromSourceMeta(?array $sourceMeta): ?array
    {
        if (!is_array($sourceMeta)) {
            return null;
        }

        return [
            'summary' => [
                'issuer_count' => (int) ($sourceMeta['issuer_count'] ?? 0),
            ],
        ];
    }

    private function documentCatalogFromSourceMeta(?array $sourceMeta): ?array
    {
        if (!is_array($sourceMeta)) {
            return null;
        }

        return [
            'summary' => [
                'pdf_count' => (int) ($sourceMeta['pdf_count'] ?? 0),
            ],
        ];
    }

    private function emptyChecklistState(): array
    {
        return [
            'confirm_pdf_ready' => false,
            'confirm_csv_ready' => false,
        ];
    }
}
