<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class OpenAiHelper implements DocumentAiRecognizerInterface
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';

    public function __construct(
        private ApplicationSettings $applicationSettings
    ) {
    }

    public function isReady(): bool
    {
        $snapshot = $this->applicationSettings->snapshot();

        return trim((string) ($snapshot['openai']['model'] ?? '')) !== ''
            && trim((string) $this->applicationSettings->secretValue('openai.api_key')) !== '';
    }

    public function recognizePdfDocumentsFromImages(
        string $sourceFileName,
        array $pageImages,
        array $pageTexts = []
    ): array {
        if (!$this->isReady()) {
            return [
                'status' => 'disabled',
                'note' => 'Integracja OpenAI nie jest gotowa albo nie ma zapisanego klucza API.',
            ];
        }

        $snapshot = $this->applicationSettings->snapshot();
        $model = trim((string) ($snapshot['openai']['model'] ?? 'gpt-5-mini'));
        $apiKey = trim((string) $this->applicationSettings->secretValue('openai.api_key'));

        if ($apiKey === '') {
            return [
                'status' => 'disabled',
                'note' => 'Brak klucza API OpenAI w ustawieniach aplikacji.',
            ];
        }

        try {
            @set_time_limit(300);

            $payload = [
                'model' => $model !== '' ? $model : 'gpt-5-mini',
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => InvoiceAiPromptCatalog::systemPrompt(),
                        ]],
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildVisionUserContent($sourceFileName, $pageImages, $pageTexts),
                    ],
                ],
            ];

            $response = $this->postJson(self::RESPONSES_URL, $apiKey, $payload);
            $outputText = $this->extractOutputText($response);
            $decoded = $this->decodeJsonPayload($outputText);

            return [
                'status' => 'ok',
                'note' => 'Rozpoznanie OpenAI dla calego PDF zakonczone powodzeniem.',
                'data' => $decoded,
                'raw_output' => $outputText,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'note' => $exception->getMessage(),
            ];
        }
    }

    private function buildVisionUserContent(string $sourceFileName, array $pageImages, array $pageTexts): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => InvoiceAiPromptCatalog::promptIntro($sourceFileName, $pageImages, $pageTexts),
        ]];

        foreach (array_values($pageImages) as $pageImage) {
            if (!is_array($pageImage)) {
                continue;
            }

            $imagePath = (string) ($pageImage['image_path'] ?? '');
            if ($imagePath === '' || !is_file($imagePath)) {
                continue;
            }

            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->imageDataUrl($imagePath),
                'detail' => 'high',
            ];
        }

        return $content;
    }

    private function imageDataUrl(string $imagePath): string
    {
        $content = file_get_contents($imagePath);
        if ($content === false || $content === '') {
            throw new RuntimeException('Nie udalo sie odczytac obrazu strony PDF do wyslania do OpenAI.');
        }

        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:' . $mimeType . ';base64,' . base64_encode($content);
    }

    private function postJson(string $url, string $apiKey, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP nie ma wlaczonego rozszerzenia cURL, wiec nie moze polaczyc sie z OpenAI API.');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonPayload)) {
            throw new RuntimeException('Nie udalo sie przygotowac zapytania do OpenAI API.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nie udalo sie zainicjowac polaczenia z OpenAI API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            $message = $curlError !== '' ? $curlError : 'Pusta odpowiedz z OpenAI API.';
            throw new RuntimeException('Blad komunikacji z OpenAI API: ' . $message);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI API zwrocilo odpowiedz, ktorej nie udalo sie zdekodowac jako JSON.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['error']['message'] ?? 'Nieznany blad OpenAI API.');
            throw new RuntimeException('OpenAI API zwrocilo HTTP ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }

    private function extractOutputText(array $response): string
    {
        $direct = trim((string) ($response['output_text'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        foreach ((array) ($response['output'] ?? []) as $outputItem) {
            if (!is_array($outputItem)) {
                continue;
            }

            foreach ((array) ($outputItem['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $text = trim((string) ($contentItem['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('OpenAI API nie zwrocilo czytelnej odpowiedzi tekstowej.');
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
            throw new RuntimeException('OpenAI zwrocilo odpowiedz, ale nie byla ona poprawnym JSON-em.');
        }

        return $decoded;
    }
}
