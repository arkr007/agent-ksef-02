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
use App\Repository\AccountingEntryRepository;
use App\Repository\AuditLogRepository;
use App\Service\CsvExporter;
use App\Service\FileUploadService;
use App\Service\InvoiceMapper;
use App\Service\InvoiceMatcher;
use App\Service\JpkAccountingParser;
use App\Service\KsefClient;
use App\Service\NbpExchangeRateService;
use Throwable;

final class AccountingCompareController
{
    private const SESSION_COMPARE_KEY = 'accounting_compare_current';

    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private Flash $flash,
        private FileUploadService $fileUploadService,
        private JpkAccountingParser $jpkAccountingParser,
        private AccountingEntryRepository $accountingEntryRepository,
        private KsefClient $ksefClient,
        private InvoiceMapper $invoiceMapper,
        private NbpExchangeRateService $nbpExchangeRateService,
        private InvoiceMatcher $invoiceMatcher,
        private CsvExporter $csvExporter,
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

        return $this->renderPage((int) $user['id']);
    }

    public function import(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.');

            return Response::redirect($this->config->url('/accounting-compare') . '#compare-import-form');
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $userId = (int) $user['id'];
        $compareMonth = trim((string) $request->input('compare_month'));
        $monthRange = $this->resolveMonthRange($compareMonth);
        if ($monthRange === null) {
            return $this->renderPage(
                $userId,
                alerts: [[
                    'type' => 'error',
                    'message' => 'Wybierz poprawny miesiac porownania w formacie RRRR-MM.',
                ]],
                selectedMonth: $compareMonth,
                scrollTarget: '#compare-import-form',
                useCurrentPackage: false
            );
        }

        try {
            $uploadedFile = $this->fileUploadService->validateAndStore(
                (array) $request->file('jpk_file'),
                'accounting_jpk',
                $userId,
                ['xml'],
                ['application/xml', 'text/xml', 'application/octet-stream']
            );

            $parsed = $this->jpkAccountingParser->parse((string) $uploadedFile['storage_path']);
            $parsedEntries = array_values((array) ($parsed['entries'] ?? []));
            $entryIds = $this->accountingEntryRepository->createMany($userId, (int) $uploadedFile['id'], $parsedEntries);
            $entries = $this->attachEntryIds($parsedEntries, $entryIds);
            $documentTypeDemand = $this->detectRequestedDocumentTypes($entries);

            $costInvoices = [];
            if ($documentTypeDemand['cost']) {
                $costInvoices = $this->mapLiveKsefInvoices(
                    $this->ksefClient->getCachedCostInvoiceMetadataByMonth($monthRange['year'], $monthRange['month']),
                    'cost',
                    1
                );
            }

            $saleInvoices = [];
            if ($documentTypeDemand['sale']) {
                $saleInvoices = $this->mapLiveKsefInvoices(
                    $this->ksefClient->getCachedSalesInvoiceMetadataByMonth($monthRange['year'], $monthRange['month']),
                    'sale',
                    count($costInvoices) + 1
                );
            }

            $invoices = $this->nbpExchangeRateService->enrichInvoicesForComparison(
                array_merge($costInvoices, $saleInvoices),
                $monthRange['year'],
                $monthRange['month']
            );

            $comparisonRows = $this->invoiceMatcher->match($invoices, $entries);
            $package = $this->buildPackage(
                $uploadedFile,
                (string) ($parsed['raw_text'] ?? ''),
                $entries,
                $comparisonRows,
                $compareMonth,
                count($invoices),
                count($costInvoices),
                count($saleInvoices)
            );
            $this->storeCurrentPackage($package);

            $this->auditLogRepository->log(
                action: 'accounting_compare_imported_jpk',
                userId: $userId,
                entityType: 'uploaded_file',
                entityId: (int) $uploadedFile['id'],
                context: [
                    'compare_month' => $compareMonth,
                    'live_ksef_invoice_count' => count($invoices),
                    'live_ksef_cost_count' => count($costInvoices),
                    'live_ksef_sale_count' => count($saleInvoices),
                    'entry_count' => count($entries),
                    'comparison_count' => count($comparisonRows),
                    'entry_ids' => $entryIds,
                ],
                ipAddress: $request->clientIp(),
                userAgent: $request->userAgent()
            );

            return $this->renderPage(
                $userId,
                alerts: [[
                    'type' => 'info',
                    'message' => sprintf(
                        'Wczytano JPK, rozpoznano %d wpisow i przygotowano %d wynikow porownania z biezacym KSeF.',
                        count($entries),
                        count($comparisonRows)
                    ),
                ]],
                package: $package,
                selectedMonth: $compareMonth,
                scrollTarget: '#compare-results'
            );
        } catch (Throwable $exception) {
            return $this->renderPage(
                $userId,
                alerts: [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                selectedMonth: $compareMonth,
                scrollTarget: '#compare-import-form',
                useCurrentPackage: false
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

        $package = $this->currentPackage((int) $user['id']);
        if ($package === null) {
            $this->flash->add('warning', 'Najpierw wczytaj JPK i przygotuj porownanie.');

            return Response::redirect($this->config->url('/accounting-compare') . '#compare-import-form');
        }

        $rows = array_map(fn (array $row): array => $this->mapCompareRowToCsv($row), (array) ($package['comparison_rows'] ?? []));
        $content = $this->csvExporter->export($rows, [
            'Typ',
            'Status',
            'LP JPK',
            'Data JPK',
            'Numer JPK',
            'Kwota brutto JPK',
            'Kontrahent JPK',
            'Opis JPK',
            'Numer KSeF',
            'Kwota KSeF do porownania (PLN)',
            'Kwota oryginalna KSeF',
            'Waluta KSeF',
            'Kurs NBP',
            'Data kursu',
            'Wystawca KSeF',
            'Data wystawienia KSeF',
            'Termin platnosci KSeF',
            'Uzasadnienie',
        ]);

        return new Response(
            $content,
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    'porownanie-ksef-jpk-' . date('Ymd-His') . '.csv'
                ),
            ]
        );
    }

    private function renderPage(
        int $userId,
        array $alerts = [],
        ?array $package = null,
        string $selectedMonth = '',
        string $scrollTarget = '',
        bool $useCurrentPackage = true
    ): Response {
        if ($package === null && $useCurrentPackage) {
            $package = $this->currentPackage($userId);
        }

        if ($selectedMonth === '') {
            $selectedMonth = is_array($package) && isset($package['compare_month'])
                ? (string) $package['compare_month']
                : date('Y-m');
        }

        return Response::html($this->view->render('accounting_compare', [
            'title' => 'Porownanie KSeF z JPK',
            'pageTitle' => 'Funkcja 3: porownanie JPK z miesiecznym zbiorem KSeF',
            'pageDescription' => 'Wgrywasz plik JPK XML, wskazujesz miesiac i porownujesz go ze swiezo pobranymi fakturami KSeF z tego okresu.',
            'alerts' => $alerts,
            'scrollTarget' => $scrollTarget,
            'package' => $package,
            'selectedMonth' => $selectedMonth,
            'amountTolerance' => '1%',
        ]));
    }

    private function buildPackage(
        array $uploadedFile,
        string $rawText,
        array $entries,
        array $comparisonRows,
        string $compareMonth,
        int $liveKsefInvoiceCount,
        int $liveKsefCostCount,
        int $liveKsefSaleCount
    ): array {
        $statusCounts = [
            'BOTH' => 0,
            'NUMBER_MATCH_AMOUNT_DIFF' => 0,
            'AMOUNT_MATCH_NUMBER_DIFF' => 0,
            'ONLY_KSEF' => 0,
            'ONLY_JPK' => 0,
        ];

        foreach ($comparisonRows as $row) {
            $status = (string) ($row['status'] ?? 'ONLY_JPK');
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
            $statusCounts[$status]++;
        }

        $jpkCostCount = 0;
        $jpkSaleCount = 0;
        foreach ($entries as $entry) {
            $type = $this->normalizeType((string) ($entry['document_type'] ?? ''));
            if ($type === 'sale') {
                $jpkSaleCount++;
                continue;
            }

            $jpkCostCount++;
        }

        return [
            'user_id' => $uploadedFile['user_id'] ?? 0,
            'source_file_id' => $uploadedFile['id'] ?? 0,
            'source_file_name' => $uploadedFile['original_name'] ?? '',
            'compare_month' => $compareMonth,
            'live_ksef_invoice_count' => $liveKsefInvoiceCount,
            'live_ksef_cost_count' => $liveKsefCostCount,
            'live_ksef_sale_count' => $liveKsefSaleCount,
            'raw_text_preview' => mb_substr($rawText, 0, 2500),
            'entries' => $entries,
            'comparison_rows' => array_map(fn (array $row): array => $this->decorateComparisonRow($row), $comparisonRows),
            'summary' => [
                'entries_count' => count($entries),
                'comparison_count' => count($comparisonRows),
                'pdf_cost_count' => $jpkCostCount,
                'pdf_sale_count' => $jpkSaleCount,
                'both_count' => $statusCounts['BOTH'] ?? 0,
                'number_match_amount_diff_count' => $statusCounts['NUMBER_MATCH_AMOUNT_DIFF'] ?? 0,
                'amount_match_number_diff_count' => $statusCounts['AMOUNT_MATCH_NUMBER_DIFF'] ?? 0,
                'only_ksef_count' => $statusCounts['ONLY_KSEF'] ?? 0,
                'only_jpk_count' => $statusCounts['ONLY_JPK'] ?? 0,
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

    private function attachEntryIds(array $entries, array $entryIds): array
    {
        $enriched = [];

        foreach ($entries as $index => $entry) {
            $entry['id'] = $entryIds[$index] ?? null;
            $enriched[] = $entry;
        }

        return $enriched;
    }

    private function mapLiveKsefInvoices(array $payloads, string $documentType, int $startingId): array
    {
        $invoices = [];
        $nextId = $startingId;

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $invoice = $this->invoiceMapper->mapFromKsefPayload($payload);
            $invoice['id'] = $nextId++;
            $invoice['document_type'] = $documentType;
            if (!isset($invoice['fetch_warning']) || trim((string) $invoice['fetch_warning']) === '') {
                $invoice['fetch_warning'] = $payload['fetch_warning'] ?? null;
            }
            $invoices[] = $invoice;
        }

        return $invoices;
    }

    private function decorateComparisonRow(array $row): array
    {
        $row['status_badge_class'] = match ((string) ($row['status'] ?? 'ONLY_JPK')) {
            'BOTH' => 'ok',
            'ONLY_KSEF', 'ONLY_JPK' => 'warn',
            default => 'error',
        };

        $row['status_label'] = match ((string) ($row['status'] ?? 'ONLY_JPK')) {
            'BOTH' => 'Zgodne',
            'NUMBER_MATCH_AMOUNT_DIFF' => 'Numer zgodny, kwota inna',
            'AMOUNT_MATCH_NUMBER_DIFF' => 'Kwota zgodna, numer inny',
            'ONLY_KSEF' => 'Tylko KSeF',
            default => 'Tylko JPK',
        };

        $row['document_type_label'] = match ($this->normalizeType((string) ($row['document_type'] ?? ''))) {
            'sale' => 'Sprzedaz',
            default => 'Koszt',
        };

        $row['ksef_amount_label'] = $this->formatDisplayAmount($row['ksef_gross_amount'] ?? null, 'PLN');
        $row['ksef_amount_note'] = $this->buildKsefAmountNote($row);

        return $row;
    }

    private function mapCompareRowToCsv(array $row): array
    {
        return [
            $row['document_type_label'] ?? $row['document_type'] ?? '',
            $row['status_label'] ?? $row['status'] ?? '',
            $row['row_lp'] ?? '',
            $row['event_date'] ?? '',
            $row['document_number'] ?? '',
            $this->formatCsvDecimal($row['jpk_gross_amount'] ?? null),
            $row['contractor_name'] ?? '',
            $row['business_event_description'] ?? '',
            $row['invoice_number'] ?? '',
            $this->formatCsvDecimal($row['ksef_gross_amount'] ?? null),
            $this->formatCsvDecimal($row['ksef_original_gross_amount'] ?? null),
            $row['ksef_original_currency'] ?? '',
            $this->formatCsvDecimal($row['ksef_exchange_rate'] ?? null, 4),
            $row['ksef_exchange_rate_date'] ?? '',
            $row['issuer_name'] ?? '',
            $row['issue_date'] ?? '',
            $row['due_date'] ?? '',
            $row['reasoning'] ?? '',
        ];
    }

    private function formatCsvDecimal(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric((string) $value)) {
            return number_format((float) $value, $scale, ',', '');
        }

        return (string) $value;
    }

    private function buildKsefAmountNote(array $row): string
    {
        $warning = trim((string) ($row['ksef_comparison_warning'] ?? ''));
        if ($warning !== '') {
            return $warning;
        }

        $currency = strtoupper(trim((string) ($row['ksef_original_currency'] ?? 'PLN')));
        if ($currency === 'PLN') {
            return '';
        }

        $parts = [];
        $originalAmount = $this->formatDisplayAmount($row['ksef_original_gross_amount'] ?? null, $currency);
        if ($originalAmount !== '') {
            $parts[] = 'oryg.: ' . $originalAmount;
        }

        if (($row['ksef_exchange_rate'] ?? null) !== null && $row['ksef_exchange_rate'] !== '') {
            $parts[] = 'kurs NBP: ' . number_format((float) $row['ksef_exchange_rate'], 4, ',', '');
        }

        if (!empty($row['ksef_exchange_rate_date'])) {
            $parts[] = 'data kursu: ' . (string) $row['ksef_exchange_rate_date'];
        }

        if (($row['ksef_exchange_rate_source'] ?? '') === 'monthly_average_rate') {
            $parts[] = 'srednia miesieczna';
        }

        return implode(' | ', $parts);
    }

    private function formatDisplayAmount(mixed $value, string $currency): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric((string) $value)) {
            return trim((string) $value . ' ' . $currency);
        }

        return trim(number_format((float) $value, 2, ',', ' ') . ' ' . $currency);
    }

    private function normalizeType(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'sale', 'sprzedaz', 'przychod' => 'sale',
            default => 'cost',
        };
    }

    private function detectRequestedDocumentTypes(array $entries): array
    {
        $hasCost = false;
        $hasSale = false;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->normalizeType((string) ($entry['document_type'] ?? 'cost'));
            if ($type === 'sale') {
                $hasSale = true;
                continue;
            }

            $hasCost = true;
        }

        if (!$hasCost && !$hasSale) {
            $hasCost = true;
        }

        return [
            'cost' => $hasCost,
            'sale' => $hasSale,
        ];
    }

    private function storeCurrentPackage(array $package): void
    {
        $_SESSION[self::SESSION_COMPARE_KEY] = $package;
    }

    private function currentPackage(int $userId): ?array
    {
        $package = $_SESSION[self::SESSION_COMPARE_KEY] ?? null;
        if (!is_array($package)) {
            return null;
        }

        if ((int) ($package['user_id'] ?? 0) !== $userId) {
            unset($_SESSION[self::SESSION_COMPARE_KEY]);

            return null;
        }

        return $package;
    }
}
