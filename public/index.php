<?php

declare(strict_types=1);

use App\Controller\AccountingCompareController;
use App\Controller\AccountantPackageController;
use App\Controller\AuthController;
use App\Controller\BankImportController;
use App\Controller\DashboardController;
use App\Controller\KsefController;
use App\Controller\SettingsController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repository\AccountingEntryRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BankImportJobRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SettingsRepository;
use App\Repository\UploadedFileRepository;
use App\Repository\UserRepository;
use App\Service\ApplicationSettings;
use App\Service\AccountantPackageSummaryService;
use App\Service\CsvExporter;
use App\Service\CsvImporter;
use App\Service\DocumentInboxCatalogService;
use App\Service\FileUploadService;
use App\Service\InvoiceMapper;
use App\Service\InvoiceMatcher;
use App\Service\JpkAccountingParser;
use App\Service\KsefClient;
use App\Service\NbpExchangeRateService;
use App\Service\OpenAiHelper;
use App\Service\PdfInboxAnalysisService;
use App\Service\PdfInvoiceCandidateParser;
use App\Service\PdfPageRenderService;
use App\Service\RecurringIssuerCatalogService;
use App\Service\SecretVault;
use App\Service\Validators;
use App\Service\Bank\Pain00100109Exporter;

require dirname(__DIR__) . '/src/bootstrap.php';

$config = Config::load(CONFIG_PATH);
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Warsaw'));

$request = Request::capture(
    (string) $config->get('app.base_url', ''),
    (string) $config->get('app.session_name', 'agent_ksef_session')
);

$database = new Database($config);
$userRepository = new UserRepository($database);
$auditLogRepository = new AuditLogRepository($database);
$invoiceRepository = new InvoiceRepository($database);
$accountingEntryRepository = new AccountingEntryRepository($database);
$uploadedFileRepository = new UploadedFileRepository($database);
$bankImportJobRepository = new BankImportJobRepository($database);
$settingsRepository = new SettingsRepository($database);
$csrf = new Csrf();
$flash = new Flash();
$auth = new Auth($userRepository, $auditLogRepository, $config);
$secretVault = new SecretVault($config);
$validators = new Validators();
$applicationSettings = new ApplicationSettings($settingsRepository, $secretVault, $config);
$ksefClient = new KsefClient($applicationSettings, $config);
$invoiceMapper = new InvoiceMapper();
$invoiceMatcher = new InvoiceMatcher();
$jpkAccountingParser = new JpkAccountingParser();
$nbpExchangeRateService = new NbpExchangeRateService();
$recurringIssuerCatalogService = new RecurringIssuerCatalogService();
$documentInboxCatalogService = new DocumentInboxCatalogService($recurringIssuerCatalogService);
$pdfInboxAnalysisService = new PdfInboxAnalysisService();
$pdfPageRenderService = new PdfPageRenderService();
$openAiHelper = new OpenAiHelper($applicationSettings);
$accountantPackageSummaryService = new AccountantPackageSummaryService();
$pdfInvoiceCandidateParser = new PdfInvoiceCandidateParser($pdfInboxAnalysisService, $pdfPageRenderService, $openAiHelper);
$csvExporter = new CsvExporter();
$csvImporter = new CsvImporter();
$fileUploadService = new FileUploadService($config, $uploadedFileRepository, $auditLogRepository);
$painExporter = new Pain00100109Exporter();
$view = new View(TEMPLATE_PATH, [
    'config' => $config,
    'csrfToken' => $csrf->token(),
    'currentUser' => $auth->currentUser(),
    'flashMessages' => $flash->consume(),
]);
$router = new Router();

$authController = new AuthController($view, $config, $auth, $csrf, $flash, $userRepository, $auditLogRepository);
$dashboardController = new DashboardController($view, $config, $auth, $auditLogRepository);
$ksefController = new KsefController(
    $view,
    $config,
    $auth,
    $csrf,
    $flash,
    $applicationSettings,
    $ksefClient,
    $invoiceMapper,
    $invoiceRepository,
    $validators,
    $csvExporter,
    $auditLogRepository
);
$bankImportController = new BankImportController(
    $view,
    $config,
    $auth,
    $csrf,
    $flash,
    $applicationSettings,
    $validators,
    $csvImporter,
    $painExporter,
    $fileUploadService,
    $uploadedFileRepository,
    $bankImportJobRepository,
    $auditLogRepository
);
$accountingCompareController = new AccountingCompareController(
    $view,
    $config,
    $auth,
    $csrf,
    $flash,
    $fileUploadService,
    $jpkAccountingParser,
    $accountingEntryRepository,
    $ksefClient,
    $invoiceMapper,
    $nbpExchangeRateService,
    $invoiceMatcher,
    $csvExporter,
    $auditLogRepository
);
$accountantPackageController = new AccountantPackageController(
    $view,
    $config,
    $auth,
    $csrf,
    $applicationSettings,
    $ksefClient,
    $invoiceMapper,
    $csvExporter,
    $recurringIssuerCatalogService,
    $documentInboxCatalogService,
    $pdfInboxAnalysisService,
    $accountantPackageSummaryService,
    $pdfInvoiceCandidateParser
);
$settingsController = new SettingsController($view, $config, $auth, $csrf, $flash, $applicationSettings, $validators, $auditLogRepository);

$router->get('/', [$dashboardController, 'index']);
$router->get('/login', [$authController, 'loginForm']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/setup-admin', [$authController, 'setupForm']);
$router->post('/setup-admin', [$authController, 'setupAdmin']);
$router->get('/settings', [$settingsController, 'index']);
$router->post('/settings', [$settingsController, 'update']);
$router->get('/ksef', [$ksefController, 'index']);
$router->post('/ksef/fetch', [$ksefController, 'fetch']);
$router->get('/ksef/export', [$ksefController, 'export']);
$router->get('/bank-import', [$bankImportController, 'index']);
$router->post('/bank-import/import', [$bankImportController, 'import']);
$router->post('/bank-import/export', [$bankImportController, 'exportPain']);
$router->get('/accounting-compare', [$accountingCompareController, 'index']);
$router->post('/accounting-compare/import', [$accountingCompareController, 'import']);
$router->get('/accounting-compare/export', [$accountingCompareController, 'export']);
$router->get('/accountant-package', [$accountantPackageController, 'index']);
$router->get('/accountant-package/export', [$accountantPackageController, 'export']);
$router->post('/accountant-package/fetch-ksef', [$accountantPackageController, 'fetchKsef']);
$router->post('/accountant-package/analyze-pdfs', [$accountantPackageController, 'analyzePdfInbox']);
$router->post('/accountant-package/parse-pdf-candidates', [$accountantPackageController, 'parsePdfCandidates']);
$router->get('/history', [$dashboardController, 'history']);

try {
    $response = $router->dispatch($request);
} catch (Throwable $exception) {
    $response = Response::html(
        $view->render('dashboard', [
            'title' => 'Błąd aplikacji',
            'pageTitle' => 'Błąd aplikacji',
            'pageDescription' => 'Wystąpił błąd podczas uruchamiania aplikacji.',
            'cards' => [],
            'alerts' => [
                [
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ],
            ],
        ]),
        500
    );
}

$response->send();
