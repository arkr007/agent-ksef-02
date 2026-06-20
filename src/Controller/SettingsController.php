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
use App\Service\ApplicationSettings;
use App\Service\OllamaHelper;
use App\Service\Validators;

final class SettingsController
{
    public function __construct(
        private View $view,
        private Config $config,
        private Auth $auth,
        private Csrf $csrf,
        private Flash $flash,
        private ApplicationSettings $applicationSettings,
        private Validators $validators,
        private AuditLogRepository $auditLogRepository,
        private OllamaHelper $ollamaHelper
    ) {
    }

    public function index(Request $request): Response
    {
        unset($request);

        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->renderPage($this->applicationSettings->snapshot());
    }

    public function update(Request $request): Response
    {
        $guard = $this->auth->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        if (!$request->isMethod('POST') || !$this->csrf->validate((string) $request->input('_csrf'))) {
            $this->flash->add('error', 'Nieprawidlowy token CSRF. Odswiez formularz i sprobuj ponownie.');

            return Response::redirect($this->config->url('/settings'));
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect($this->config->url('/login'));
        }

        $storedSnapshot = $this->applicationSettings->snapshot();
        $form = (string) $request->input('form_name');
        $result = match ($form) {
            'ksef' => $this->handleKsefUpdate($request, (int) $user['id'], $storedSnapshot),
            'ai' => $this->handleAiUpdate($request, (int) $user['id'], $storedSnapshot),
            'ai_test' => $this->handleAiTest($request, $storedSnapshot),
            'bank' => $this->handleBankUpdate($request, (int) $user['id'], $storedSnapshot),
            default => [
                'errors' => ['Nieznany formularz ustawien.'],
                'snapshot' => $storedSnapshot,
                'form' => 'general',
            ],
        };

        if ($result['errors'] !== []) {
            $alerts = array_map(
                static fn (string $message): array => ['type' => 'error', 'message' => $message],
                $result['errors']
            );

            return $this->renderPage($result['snapshot'], $alerts, $result['form'], $this->anchorForForm($result['form']));
        }

        if (isset($result['alerts']) && is_array($result['alerts'])) {
            return $this->renderPage($result['snapshot'], $result['alerts'], $result['form'], $this->anchorForForm($result['form']));
        }

        $this->flash->add('info', 'Ustawienia zostaly zapisane.');

        return Response::redirect($this->config->url('/settings') . $this->anchorForForm($result['form']));
    }

    private function handleKsefUpdate(Request $request, int $userId, array $storedSnapshot): array
    {
        $available = $this->config->get('ksef.available_environments', ['production', 'test']);
        $available = is_array($available) ? $available : ['production', 'test'];

        $data = [
            'environment' => trim((string) $request->input('environment')),
            'context_nip' => preg_replace('/\D+/', '', trim((string) $request->input('context_nip'))) ?: '',
            'production_base_url' => trim((string) $request->input('production_base_url')),
            'production_certificate_path' => trim((string) $request->input('production_certificate_path')),
            'production_private_key_path' => trim((string) $request->input('production_private_key_path')),
            'production_token' => trim((string) $request->input('production_token')),
            'clear_production_token' => $request->input('clear_production_token') === '1',
            'test_base_url' => trim((string) $request->input('test_base_url')),
            'test_certificate_path' => trim((string) $request->input('test_certificate_path')),
            'test_private_key_path' => trim((string) $request->input('test_private_key_path')),
            'test_token' => trim((string) $request->input('test_token')),
            'clear_test_token' => $request->input('clear_test_token') === '1',
        ];

        $errors = [];
        if (!$this->validators->isAllowedKsefEnvironment($data['environment'], $available)) {
            $errors[] = 'Wybierz poprawne srodowisko KSeF.';
        }

        if ($data['context_nip'] !== '' && !$this->validators->isValidNip($data['context_nip'])) {
            $errors[] = 'NIP kontekstu KSeF musi byc poprawnym 10-cyfrowym numerem NIP.';
        }

        foreach (['production_base_url', 'test_base_url'] as $urlKey) {
            if ($data[$urlKey] !== '' && filter_var($data[$urlKey], FILTER_VALIDATE_URL) === false) {
                $errors[] = 'Adresy bazowe KSeF musza byc poprawnymi URL-ami.';
                break;
            }
        }

        $snapshot = array_replace_recursive($storedSnapshot, [
            'ksef' => [
                'environment' => $data['environment'],
                'context_nip' => $data['context_nip'],
                'production' => [
                    'base_url' => $data['production_base_url'],
                    'certificate_path' => $data['production_certificate_path'],
                    'private_key_path' => $data['production_private_key_path'],
                    'token_present' => $data['clear_production_token'] ? false : ($data['production_token'] !== '' || $storedSnapshot['ksef']['production']['token_present']),
                ],
                'test' => [
                    'base_url' => $data['test_base_url'],
                    'certificate_path' => $data['test_certificate_path'],
                    'private_key_path' => $data['test_private_key_path'],
                    'token_present' => $data['clear_test_token'] ? false : ($data['test_token'] !== '' || $storedSnapshot['ksef']['test']['token_present']),
                ],
            ],
        ]);

        if ($errors !== []) {
            return ['errors' => $errors, 'snapshot' => $snapshot, 'form' => 'ksef'];
        }

        $this->applicationSettings->saveKsef($data);
        $this->auditLogRepository->log(
            action: 'settings_updated_ksef',
            userId: $userId,
            entityType: 'settings',
            entityId: null,
            context: [
                'environment' => $data['environment'],
                'context_nip' => $data['context_nip'] !== '' ? substr($data['context_nip'], 0, 3) . '******' . substr($data['context_nip'], -1) : null,
                'production_token_changed' => $data['production_token'] !== '' || $data['clear_production_token'],
                'test_token_changed' => $data['test_token'] !== '' || $data['clear_test_token'],
            ]
        );

        return ['errors' => [], 'snapshot' => $snapshot, 'form' => 'ksef'];
    }

    private function handleAiUpdate(Request $request, int $userId, array $storedSnapshot): array
    {
        $availableProviders = $this->config->get('ai.available_providers', ['ollama', 'hybrid', 'openai']);
        $availableProviders = is_array($availableProviders) ? $availableProviders : ['ollama', 'hybrid', 'openai'];

        $data = [
            'provider' => trim((string) $request->input('provider')),
            'model' => trim((string) $request->input('model')),
            'api_key' => trim((string) $request->input('api_key')),
            'clear_api_key' => $request->input('clear_api_key') === '1',
            'ollama_base_url' => trim((string) $request->input('ollama_base_url')),
            'ollama_model' => trim((string) $request->input('ollama_model')),
            'ollama_timeout_seconds' => max(30, (int) $request->input('ollama_timeout_seconds', 180)),
            'ollama_keep_alive' => trim((string) $request->input('ollama_keep_alive')),
            'ollama_local_only' => $request->input('ollama_local_only') === '1',
        ];

        $errors = [];
        if (!$this->validators->isAllowedAiProvider($data['provider'], $availableProviders)) {
            $errors[] = 'Wybierz poprawny tryb AI.';
        }

        if ($data['model'] === '') {
            $errors[] = 'Model OpenAI nie moze byc pusty.';
        }

        if ($data['ollama_model'] === '') {
            $errors[] = 'Model Ollama nie moze byc pusty.';
        }

        if (!$this->validators->isValidHttpUrl($data['ollama_base_url'])) {
            $errors[] = 'Endpoint Ollama musi byc poprawnym adresem HTTP lub HTTPS.';
        } elseif ($data['ollama_local_only'] && !$this->validators->isLocalHostUrl($data['ollama_base_url'])) {
            $errors[] = 'Dla trybu local_only endpoint Ollama musi wskazywac localhost tej samej stacji.';
        }

        if ($data['ollama_keep_alive'] === '') {
            $errors[] = 'Parametr keep_alive dla Ollamy nie moze byc pusty.';
        }

        $hasExistingKey = (bool) $storedSnapshot['openai']['api_key_present'];
        if (
            in_array($data['provider'], ['hybrid', 'openai'], true)
            && $data['api_key'] === ''
            && (
                (!$hasExistingKey && !$data['clear_api_key'])
                || ($hasExistingKey && $data['clear_api_key'])
            )
        ) {
            $errors[] = 'Dla trybu hybrid lub openai ustaw klucz API OpenAI albo wybierz inny tryb.';
        }

        $snapshot = $this->mergeAiSnapshot($storedSnapshot, $data);
        $snapshot['openai']['api_key_present'] = $data['clear_api_key']
            ? false
            : ($data['api_key'] !== '' || (bool) $storedSnapshot['openai']['api_key_present']);

        if ($errors !== []) {
            return ['errors' => $errors, 'snapshot' => $snapshot, 'form' => 'ai'];
        }

        $this->applicationSettings->saveAi($data);
        $this->auditLogRepository->log(
            action: 'settings_updated_ai',
            userId: $userId,
            entityType: 'settings',
            entityId: null,
            context: [
                'provider' => $data['provider'],
                'openai_model' => $data['model'],
                'ollama_model' => $data['ollama_model'],
                'ollama_base_url' => $data['ollama_base_url'],
                'ollama_local_only' => $data['ollama_local_only'],
                'api_key_changed' => $data['api_key'] !== '' || $data['clear_api_key'],
            ]
        );

        return ['errors' => [], 'snapshot' => $snapshot, 'form' => 'ai'];
    }

    private function handleAiTest(Request $request, array $storedSnapshot): array
    {
        $data = [
            'provider' => trim((string) $request->input('provider')),
            'model' => trim((string) $request->input('model')),
            'api_key' => '',
            'clear_api_key' => false,
            'ollama_base_url' => trim((string) $request->input('ollama_base_url')),
            'ollama_model' => trim((string) $request->input('ollama_model')),
            'ollama_timeout_seconds' => max(30, (int) $request->input('ollama_timeout_seconds', 180)),
            'ollama_keep_alive' => trim((string) $request->input('ollama_keep_alive')),
            'ollama_local_only' => $request->input('ollama_local_only') === '1',
        ];

        $snapshot = $this->mergeAiSnapshot($storedSnapshot, $data);
        $probe = $this->ollamaHelper->probeConnection(
            $data['ollama_base_url'],
            $data['ollama_model'],
            $data['ollama_local_only']
        );

        return [
            'errors' => [],
            'snapshot' => $snapshot,
            'form' => 'ai',
            'alerts' => [[
                'type' => match ((string) ($probe['status'] ?? 'warning')) {
                    'ok' => 'info',
                    'error' => 'error',
                    default => 'warning',
                },
                'message' => $this->probeMessage($probe),
            ]],
        ];
    }

    private function handleBankUpdate(Request $request, int $userId, array $storedSnapshot): array
    {
        $cleanIban = preg_replace('/\s+/', '', (string) $request->input('payer_iban')) ?: '';
        $data = [
            'payer_name' => trim((string) $request->input('payer_name')),
            'payer_address' => trim((string) $request->input('payer_address')),
            'payer_iban' => strtoupper($cleanIban),
            'default_currency' => strtoupper(trim((string) $request->input('default_currency'))),
        ];

        $errors = [];
        if ($data['payer_iban'] !== '' && !$this->validators->isValidIbanOrNrb($data['payer_iban'])) {
            $errors[] = 'Numer rachunku platnika musi byc poprawnym NRB lub IBAN.';
        }

        if (!$this->validators->isSupportedCurrency($data['default_currency'])) {
            $errors[] = 'Waluta domyslna musi byc jedna z: PLN, EUR, USD.';
        }

        $snapshot = array_replace_recursive($storedSnapshot, [
            'bank' => [
                'payer_name' => $data['payer_name'],
                'payer_address' => $data['payer_address'],
                'payer_iban' => $data['payer_iban'],
                'default_currency' => $data['default_currency'],
            ],
        ]);

        if ($errors !== []) {
            return ['errors' => $errors, 'snapshot' => $snapshot, 'form' => 'bank'];
        }

        $this->applicationSettings->saveBank($data);
        $this->auditLogRepository->log(
            action: 'settings_updated_bank',
            userId: $userId,
            entityType: 'settings',
            entityId: null,
            context: [
                'payer_name' => $data['payer_name'],
                'currency' => $data['default_currency'],
                'iban_last4' => $data['payer_iban'] !== '' ? substr($data['payer_iban'], -4) : null,
            ]
        );

        return ['errors' => [], 'snapshot' => $snapshot, 'form' => 'bank'];
    }

    private function mergeAiSnapshot(array $storedSnapshot, array $data): array
    {
        return array_replace_recursive($storedSnapshot, [
            'ai' => [
                'provider' => $data['provider'] !== '' ? $data['provider'] : ($storedSnapshot['ai']['provider'] ?? 'ollama'),
            ],
            'openai' => [
                'model' => $data['model'] !== '' ? $data['model'] : ($storedSnapshot['openai']['model'] ?? ''),
                'api_key_present' => (bool) ($storedSnapshot['openai']['api_key_present'] ?? false),
            ],
            'ollama' => [
                'base_url' => $data['ollama_base_url'] !== '' ? $data['ollama_base_url'] : ($storedSnapshot['ollama']['base_url'] ?? ''),
                'model' => $data['ollama_model'] !== '' ? $data['ollama_model'] : ($storedSnapshot['ollama']['model'] ?? ''),
                'timeout_seconds' => (string) $data['ollama_timeout_seconds'],
                'keep_alive' => $data['ollama_keep_alive'] !== '' ? $data['ollama_keep_alive'] : ($storedSnapshot['ollama']['keep_alive'] ?? ''),
                'local_only' => $data['ollama_local_only'],
            ],
        ]);
    }

    private function probeMessage(array $probe): string
    {
        $message = (string) ($probe['message'] ?? 'Brak odpowiedzi z testu polaczenia.');
        $availableModels = array_values(array_filter((array) ($probe['available_models'] ?? []), 'is_string'));
        if ($availableModels === []) {
            return $message;
        }

        return $message . ' Dostepne modele: ' . implode(', ', $availableModels) . '.';
    }

    private function renderPage(array $snapshot, array $alerts = [], string $activeForm = 'general', string $scrollTarget = ''): Response
    {
        return Response::html($this->view->render('settings', [
            'title' => 'Ustawienia',
            'pageTitle' => 'Ustawienia aplikacji',
            'pageDescription' => 'Tutaj zarzadzasz trybem KSeF, providerem AI i danymi platnika dla eksportu przelewow.',
            'alerts' => $alerts,
            'activeForm' => $activeForm,
            'scrollTarget' => $scrollTarget,
            'settings' => $snapshot,
            'aiProviderLabel' => $this->aiProviderLabel((string) ($snapshot['ai']['provider'] ?? 'ollama')),
            'openAiPresenceLabel' => $this->validators->maskSecretPresence((bool) $snapshot['openai']['api_key_present']),
            'prodTokenPresenceLabel' => $this->validators->maskSecretPresence((bool) $snapshot['ksef']['production']['token_present']),
            'testTokenPresenceLabel' => $this->validators->maskSecretPresence((bool) $snapshot['ksef']['test']['token_present']),
        ]));
    }

    private function anchorForForm(string $form): string
    {
        return match ($form) {
            'ksef' => '#settings-ksef',
            'ai', 'ai_test' => '#settings-ai',
            'bank' => '#settings-bank',
            default => '',
        };
    }

    private function aiProviderLabel(string $provider): string
    {
        return match ($provider) {
            'hybrid' => 'hybrid',
            'openai' => 'openai',
            default => 'ollama',
        };
    }
}
