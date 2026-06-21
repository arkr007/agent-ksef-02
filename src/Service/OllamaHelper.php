<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class OllamaHelper implements DocumentAiRecognizerInterface
{
    public function __construct(
        private ApplicationSettings $applicationSettings,
        private Validators $validators
    ) {
    }

    public function isReady(): bool
    {
        $snapshot = $this->applicationSettings->snapshot();
        $baseUrl = trim((string) ($snapshot['ollama']['base_url'] ?? ''));
        $model = trim((string) ($snapshot['ollama']['model'] ?? ''));
        $localOnly = (bool) ($snapshot['ollama']['local_only'] ?? true);

        if ($baseUrl === '' || $model === '') {
            return false;
        }

        if (!$this->validators->isValidHttpUrl($baseUrl)) {
            return false;
        }

        if ($localOnly && !$this->validators->isLocalHostUrl($baseUrl)) {
            return false;
        }

        return true;
    }

    public function recognizePdfDocumentsFromImages(
        string $sourceFileName,
        array $pageImages,
        array $pageTexts = []
    ): array {
        if (!$this->isReady()) {
            return [
                'status' => 'disabled',
                'note' => 'Ollama nie jest gotowa: sprawdz lokalny endpoint, model i ustawienie local_only.',
            ];
        }

        $snapshot = $this->applicationSettings->snapshot();
        $baseUrl = rtrim((string) ($snapshot['ollama']['base_url'] ?? 'http://127.0.0.1:11434'), '/');
        $model = trim((string) ($snapshot['ollama']['model'] ?? ''));
        $timeout = max(30, (int) ($snapshot['ollama']['timeout_seconds'] ?? 180));
        $keepAlive = trim((string) ($snapshot['ollama']['keep_alive'] ?? '15m'));

        try {
            @set_time_limit(max(300, $timeout + 30));
            $catalog = $this->fetchModelCatalog($baseUrl, 15);
            $selectedModel = $this->findModelEntry($catalog, $model);
            if ($selectedModel === null) {
                throw new RuntimeException('Wybrany model Ollama nie jest zainstalowany lokalnie.');
            }

            if (!$this->modelSupportsVision($selectedModel)) {
                throw new RuntimeException('Wybrany model Ollama nie obsluguje obrazow (vision / multimodal). Wybierz model vision.');
            }

            $payload = [
                'model' => $model,
                'stream' => false,
                'format' => InvoiceAiPromptCatalog::jsonSchema(),
                'keep_alive' => $keepAlive !== '' ? $keepAlive : '15m',
                'options' => [
                    'temperature' => 0,
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => InvoiceAiPromptCatalog::systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => InvoiceAiPromptCatalog::promptIntro($sourceFileName, $pageImages, $pageTexts),
                        'images' => $this->imagePayloads($pageImages),
                    ],
                ],
            ];

            $response = $this->postJson($baseUrl . '/api/chat', $payload, $timeout);
            $outputText = trim((string) (($response['message']['content'] ?? '')));
            if ($outputText === '') {
                throw new RuntimeException('Ollama nie zwrocila tresci odpowiedzi.');
            }

            return [
                'status' => 'ok',
                'note' => 'Rozpoznanie lokalne przez Ollama zakonczone powodzeniem.',
                'data' => $this->decodeJsonPayload($outputText),
                'raw_output' => $outputText,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'note' => $exception->getMessage(),
            ];
        }
    }

    public function probeConnection(
        ?string $baseUrl = null,
        ?string $model = null,
        ?bool $localOnly = null
    ): array {
        $snapshot = $this->applicationSettings->snapshot();
        $baseUrl ??= trim((string) ($snapshot['ollama']['base_url'] ?? 'http://127.0.0.1:11434'));
        $model ??= trim((string) ($snapshot['ollama']['model'] ?? ''));
        $localOnly ??= (bool) ($snapshot['ollama']['local_only'] ?? true);

        if ($baseUrl === '' || !$this->validators->isValidHttpUrl($baseUrl)) {
            return [
                'status' => 'error',
                'message' => 'Endpoint Ollama nie jest poprawnym adresem HTTP lub HTTPS.',
            ];
        }

        if ($localOnly && !$this->validators->isLocalHostUrl($baseUrl)) {
            return [
                'status' => 'error',
                'message' => 'Przy local_only endpoint Ollama musi wskazywac localhost tej samej stacji.',
            ];
        }

        if ($model === '') {
            return [
                'status' => 'error',
                'message' => 'Model Ollama nie moze byc pusty.',
            ];
        }

        try {
            $models = $this->fetchModelCatalog($baseUrl, 15);
            $availableNames = array_values(array_map(
                static fn (array $entry): string => trim((string) ($entry['name'] ?? '')),
                $models
            ));
            $availableNames = array_values(array_filter($availableNames, static fn (string $name): bool => $name !== ''));

            $modelEntry = $this->findModelEntry($models, $model);
            $modelInstalled = $modelEntry !== null;
            $visionSupported = $modelEntry !== null ? $this->modelSupportsVision($modelEntry) : false;

            return [
                'status' => !$modelInstalled ? 'warning' : ($visionSupported ? 'ok' : 'warning'),
                'message' => !$modelInstalled
                    ? 'Polaczenie z Ollama dziala, ale wybrany model nie jest widoczny na liscie zainstalowanych modeli.'
                    : ($visionSupported
                        ? 'Polaczenie z Ollama dziala, a wybrany model obsluguje obrazy.'
                        : 'Polaczenie z Ollama dziala, ale wybrany model nie obsluguje obrazow.'),
                'available_models' => $availableNames,
                'model_installed' => $modelInstalled,
                'vision_supported' => $visionSupported,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => 'Nie udalo sie polaczyc z Ollama: ' . $exception->getMessage(),
            ];
        }
    }

    private function imagePayloads(array $pageImages): array
    {
        $images = [];

        foreach (array_values($pageImages) as $pageImage) {
            if (!is_array($pageImage)) {
                continue;
            }

            $imagePath = (string) ($pageImage['image_path'] ?? '');
            if ($imagePath === '' || !is_file($imagePath)) {
                continue;
            }

            $content = file_get_contents($imagePath);
            if ($content === false || $content === '') {
                continue;
            }

            $images[] = base64_encode($content);
        }

        if ($images === []) {
            throw new RuntimeException('Brak obrazow stron PDF do wyslania do Ollamy.');
        }

        return $images;
    }

    private function postJson(string $url, array $payload, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP nie ma rozszerzenia cURL, wiec nie moze polaczyc sie z Ollama.');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonPayload)) {
            throw new RuntimeException('Nie udalo sie przygotowac zapytania do Ollamy.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nie udalo sie zainicjowac polaczenia z Ollama.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            $message = $curlError !== '' ? $curlError : 'Pusta odpowiedz z Ollamy.';
            throw new RuntimeException('Blad komunikacji z Ollama: ' . $message);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ollama zwrocila odpowiedz, ktorej nie udalo sie zdekodowac jako JSON.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['error'] ?? 'Nieznany blad Ollamy.');
            throw new RuntimeException('Ollama zwrocila HTTP ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }

    private function fetchModelCatalog(string $baseUrl, int $timeout): array
    {
        $response = $this->getJson(rtrim($baseUrl, '/') . '/api/tags', $timeout);
        $models = array_values(array_filter(
            (array) ($response['models'] ?? []),
            static fn (mixed $entry): bool => is_array($entry)
        ));

        return $models;
    }

    private function findModelEntry(array $models, string $modelName): ?array
    {
        $normalizedTarget = trim($modelName);
        foreach ($models as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $model = trim((string) ($entry['model'] ?? ''));
            if ($normalizedTarget !== '' && ($normalizedTarget === $name || $normalizedTarget === $model)) {
                return $entry;
            }
        }

        return null;
    }

    private function modelSupportsVision(array $modelEntry): bool
    {
        $capabilities = array_values(array_filter(
            (array) ($modelEntry['capabilities'] ?? []),
            'is_string'
        ));
        $capabilities = array_map(static fn (string $value): string => strtolower(trim($value)), $capabilities);

        return in_array('vision', $capabilities, true);
    }

    private function getJson(string $url, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP nie ma rozszerzenia cURL, wiec nie moze polaczyc sie z Ollama.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nie udalo sie zainicjowac polaczenia z Ollama.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            $message = $curlError !== '' ? $curlError : 'Pusta odpowiedz z Ollamy.';
            throw new RuntimeException($message);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ollama zwrocila odpowiedz, ktorej nie udalo sie zdekodowac jako JSON.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['error'] ?? 'Nieznany blad Ollamy.');
            throw new RuntimeException('HTTP ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }

    private function decodeJsonPayload(string $outputText): array
    {
        $clean = trim($outputText);
        $clean = preg_replace('/^```json\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/^```\s*/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;

        $firstBrace = strpos($clean, '{');
        $lastBrace = strrpos($clean, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
            $clean = substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($clean, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ollama zwrocila odpowiedz, ale nie byla ona poprawnym JSON-em.');
        }

        return $decoded;
    }
}
