<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Repository\SettingsRepository;

final class ApplicationSettings
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private SecretVault $secretVault,
        private Config $config
    ) {
    }

    public function snapshot(): array
    {
        $stored = $this->settingsRepository->getAllAppSettings();

        return [
            'database' => [
                'host' => (string) $this->config->get('database.host', ''),
                'port' => (string) $this->config->get('database.port', ''),
                'name' => (string) $this->config->get('database.name', ''),
                'username' => (string) $this->config->get('database.username', ''),
            ],
            'ai' => [
                'provider' => $this->plainValue($stored, 'ai.provider', (string) $this->config->get('ai.provider', 'ollama')),
                'available_providers' => $this->config->get('ai.available_providers', ['ollama', 'hybrid', 'openai']),
            ],
            'ksef' => [
                'environment' => $this->plainValue($stored, 'ksef.environment', (string) $this->config->get('ksef.environment', 'test')),
                'context_nip' => $this->plainValue($stored, 'ksef.context_nip', (string) $this->config->get('ksef.context_nip', '')),
                'available_environments' => $this->config->get('ksef.available_environments', ['production', 'test']),
                'production' => [
                    'base_url' => $this->plainValue($stored, 'ksef.production.base_url', (string) $this->config->get('ksef.production.base_url', '')),
                    'certificate_path' => $this->plainValue($stored, 'ksef.production.certificate_path', (string) $this->config->get('ksef.production.certificate_path', '')),
                    'private_key_path' => $this->plainValue($stored, 'ksef.production.private_key_path', (string) $this->config->get('ksef.production.private_key_path', '')),
                    'token_present' => $this->hasSecretValue($stored, 'ksef.production.token', (string) $this->config->get('ksef.production.token', '')),
                ],
                'test' => [
                    'base_url' => $this->plainValue($stored, 'ksef.test.base_url', (string) $this->config->get('ksef.test.base_url', '')),
                    'certificate_path' => $this->plainValue($stored, 'ksef.test.certificate_path', (string) $this->config->get('ksef.test.certificate_path', '')),
                    'private_key_path' => $this->plainValue($stored, 'ksef.test.private_key_path', (string) $this->config->get('ksef.test.private_key_path', '')),
                    'token_present' => $this->hasSecretValue($stored, 'ksef.test.token', (string) $this->config->get('ksef.test.token', '')),
                ],
            ],
            'openai' => [
                'model' => $this->plainValue($stored, 'openai.model', (string) $this->config->get('openai.model', 'gpt-5-mini')),
                'api_key_present' => $this->hasSecretValue($stored, 'openai.api_key', (string) $this->config->get('openai.api_key', '')),
            ],
            'ollama' => [
                'base_url' => $this->plainValue($stored, 'ollama.base_url', (string) $this->config->get('ollama.base_url', 'http://127.0.0.1:11434')),
                'model' => $this->plainValue($stored, 'ollama.model', (string) $this->config->get('ollama.model', 'qwen2.5vl:7b')),
                'timeout_seconds' => $this->plainValue($stored, 'ollama.timeout_seconds', (string) $this->config->get('ollama.timeout_seconds', 180)),
                'keep_alive' => $this->plainValue($stored, 'ollama.keep_alive', (string) $this->config->get('ollama.keep_alive', '15m')),
                'local_only' => $this->plainValue($stored, 'ollama.local_only', $this->config->get('ollama.local_only', true) ? '1' : '0') === '1',
            ],
            'bank' => [
                'payer_name' => $this->plainValue($stored, 'bank.payer_name', (string) $this->config->get('bank.payer_name', '')),
                'payer_address' => $this->plainValue($stored, 'bank.payer_address', (string) $this->config->get('bank.payer_address', '')),
                'payer_iban' => $this->plainValue($stored, 'bank.payer_iban', (string) $this->config->get('bank.payer_iban', '')),
                'default_currency' => $this->plainValue($stored, 'bank.default_currency', (string) $this->config->get('bank.default_currency', 'PLN')),
            ],
            'local_paths' => [
                'document_inbox_dir' => $this->plainValue($stored, 'local_paths.document_inbox_dir', $this->defaultDocumentInboxDir()),
                'recurring_issuers_csv' => $this->plainValue($stored, 'local_paths.recurring_issuers_csv', $this->defaultRecurringIssuersCsvPath()),
            ],
        ];
    }

    public function saveKsef(array $data): void
    {
        $this->settingsRepository->saveText('ksef.environment', $data['environment']);
        $this->settingsRepository->saveText('ksef.context_nip', $data['context_nip']);
        $this->settingsRepository->saveText('ksef.production.base_url', $data['production_base_url']);
        $this->settingsRepository->saveText('ksef.production.certificate_path', $data['production_certificate_path']);
        $this->settingsRepository->saveText('ksef.production.private_key_path', $data['production_private_key_path']);
        $this->settingsRepository->saveText('ksef.test.base_url', $data['test_base_url']);
        $this->settingsRepository->saveText('ksef.test.certificate_path', $data['test_certificate_path']);
        $this->settingsRepository->saveText('ksef.test.private_key_path', $data['test_private_key_path']);

        $this->saveOptionalSecret('ksef.production.token', $data['production_token'], $data['clear_production_token']);
        $this->saveOptionalSecret('ksef.test.token', $data['test_token'], $data['clear_test_token']);
    }

    public function saveAi(array $data): void
    {
        $this->settingsRepository->saveText('ai.provider', $data['provider']);
        $this->settingsRepository->saveText('openai.enabled', in_array($data['provider'], ['hybrid', 'openai'], true) ? '1' : '0');
        $this->settingsRepository->saveText('openai.model', $data['model']);
        $this->settingsRepository->saveText('ollama.enabled', in_array($data['provider'], ['ollama', 'hybrid'], true) ? '1' : '0');
        $this->settingsRepository->saveText('ollama.base_url', $data['ollama_base_url']);
        $this->settingsRepository->saveText('ollama.model', $data['ollama_model']);
        $this->settingsRepository->saveText('ollama.timeout_seconds', (string) $data['ollama_timeout_seconds']);
        $this->settingsRepository->saveText('ollama.keep_alive', $data['ollama_keep_alive']);
        $this->settingsRepository->saveText('ollama.local_only', $data['ollama_local_only'] ? '1' : '0');

        $this->saveOptionalSecret('openai.api_key', $data['api_key'], $data['clear_api_key']);
    }

    public function saveBank(array $data): void
    {
        $this->settingsRepository->saveText('bank.payer_name', $data['payer_name']);
        $this->settingsRepository->saveText('bank.payer_address', $data['payer_address']);
        $this->settingsRepository->saveText('bank.payer_iban', $data['payer_iban']);
        $this->settingsRepository->saveText('bank.default_currency', $data['default_currency']);
    }

    public function saveLocalPaths(array $data): void
    {
        $this->settingsRepository->saveText('local_paths.document_inbox_dir', $data['document_inbox_dir']);
        $this->settingsRepository->saveText('local_paths.recurring_issuers_csv', $data['recurring_issuers_csv']);
    }

    private function plainValue(array $stored, string $key, string $fallback): string
    {
        if (!isset($stored[$key])) {
            return $fallback;
        }

        return (string) ($stored[$key]['setting_value_text'] ?? $fallback);
    }

    private function hasSecretValue(array $stored, string $key, string $fallback): bool
    {
        if (!isset($stored[$key])) {
            return trim($fallback) !== '';
        }

        $raw = (string) ($stored[$key]['setting_value_text'] ?? '');
        if ($raw === '') {
            return false;
        }

        if ((int) ($stored[$key]['is_encrypted'] ?? 0) === 1) {
            try {
                return trim($this->secretVault->decrypt($raw)) !== '';
            } catch (\Throwable) {
                return true;
            }
        }

        return trim($raw) !== '';
    }

    private function saveOptionalSecret(string $key, string $plainText, bool $clear): void
    {
        if ($clear) {
            $this->settingsRepository->delete($key);

            return;
        }

        if (trim($plainText) === '') {
            return;
        }

        $this->settingsRepository->saveText($key, $this->secretVault->encrypt($plainText), true);
    }

    public function secretValue(string $key): ?string
    {
        $stored = $this->settingsRepository->getAllAppSettings();
        if (!isset($stored[$key])) {
            return null;
        }

        $raw = (string) ($stored[$key]['setting_value_text'] ?? '');
        if ($raw === '') {
            return null;
        }

        if ((int) ($stored[$key]['is_encrypted'] ?? 0) !== 1) {
            return $raw;
        }

        return $this->secretVault->decrypt($raw);
    }

    private function defaultDocumentInboxDir(): string
    {
        $desktopDirectory = $this->resolveDesktopDirectory();
        $baseDirectory = $desktopDirectory ?? 'Desktop';

        return rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . 'faktury_do_ksiegowej';
    }

    private function defaultRecurringIssuersCsvPath(): string
    {
        return $this->defaultDocumentInboxDir()
            . DIRECTORY_SEPARATOR
            . 'rob'
            . DIRECTORY_SEPARATOR
            . 'stali_wystawcy.csv';
    }

    private function resolveDesktopDirectory(): ?string
    {
        $candidates = [];

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && trim($userProfile) !== '') {
            $candidates[] = rtrim(trim($userProfile), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');
        if (is_string($homeDrive) && is_string($homePath) && trim($homeDrive . $homePath) !== '') {
            $candidates[] = rtrim(trim($homeDrive . $homePath), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        $home = getenv('HOME');
        if (is_string($home) && trim($home) !== '') {
            $candidates[] = rtrim(trim($home), '/\\') . DIRECTORY_SEPARATOR . 'Desktop';
        }

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
