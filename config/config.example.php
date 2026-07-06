<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Agent KSeF',
        'base_url' => '/agent_ksef_v03/public',
        'timezone' => 'Europe/Warsaw',
        'locale' => 'pl_PL',
        'app_encryption_key' => 'UZUPELNIJ_W_CONFIG_PHP_MIN_32_ZNAKI',
        'upload_limit_mb' => 20,
        'session_name' => 'agent_ksef_session',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'agent_ksef',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'ksef' => [
        'environment' => 'test',
        'context_nip' => '',
        'available_environments' => ['production', 'test'],
        'endpoints' => [
            'auth_challenge' => '/auth/challenge',
            'auth_ksef_token' => '/auth/ksef-token',
            'auth_status' => '/auth/{referenceNumber}',
            'auth_token_redeem' => '/auth/token/redeem',
            'auth_token_refresh' => '/auth/token/refresh',
            'public_key_certificates' => '/security/public-key-certificates',
            'invoice_metadata_query' => '/invoices/query/metadata',
            'invoice_by_ksef_number' => '/invoices/ksef/{ksefNumber}',
        ],
        'production' => [
            'base_url' => 'TODO_KSEF_ENDPOINT_PRODUCTION',
            'token' => '',
            'certificate_path' => '',
            'private_key_path' => '',
        ],
        'test' => [
            'base_url' => 'TODO_KSEF_ENDPOINT_TEST',
            'token' => '',
            'certificate_path' => '',
            'private_key_path' => '',
        ],
    ],
    'ai' => [
        'provider' => 'ollama',
        'available_providers' => ['ollama', 'hybrid', 'openai'],
    ],
    'openai' => [
        'api_key' => '',
        'model' => 'gpt-5-mini',
    ],
    'ollama' => [
        'base_url' => 'http://127.0.0.1:11434',
        'model' => 'qwen2.5vl:7b',
        'timeout_seconds' => 180,
        'keep_alive' => '15m',
        'local_only' => true,
    ],
    'bank' => [
        'payer_name' => '',
        'payer_address' => '',
        'payer_iban' => '',
        'default_currency' => 'PLN',
    ],
    'local_paths' => [
        'document_inbox_dir' => '',
        'recurring_issuers_csv' => '',
    ],
    'paths' => [
        'uploads' => STORAGE_PATH . '/uploads',
        'exports' => STORAGE_PATH . '/exports',
        'logs' => STORAGE_PATH . '/logs',
    ],
];
