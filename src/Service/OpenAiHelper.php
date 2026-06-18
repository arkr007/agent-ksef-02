<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class OpenAiHelper
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';

    public function __construct(
        private ApplicationSettings $applicationSettings
    ) {
    }

    public function isReady(): bool
    {
        $snapshot = $this->applicationSettings->snapshot();

        return (bool) ($snapshot['openai']['enabled'] ?? false)
            && trim((string) $this->applicationSettings->secretValue('openai.api_key')) !== '';
    }

    public function recognizeDocumentPage(
        string $sourceFileName,
        int $pageNumber,
        ?string $imagePath,
        string $pageText = ''
    ): array {
        if (!$this->isReady()) {
            return [
                'status' => 'disabled',
                'note' => 'Integracja OpenAI nie jest aktywna albo nie ma zapisanego klucza API.',
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
            $payload = [
                'model' => $model !== '' ? $model : 'gpt-5-mini',
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => $this->systemPrompt(),
                            ],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => $this->buildUserContent($sourceFileName, $pageNumber, $imagePath, $pageText),
                    ],
                ],
            ];

            $response = $this->postJson(self::RESPONSES_URL, $apiKey, $payload);
            $outputText = $this->extractOutputText($response);
            $decoded = $this->decodeJsonObject($outputText);

            return [
                'status' => 'ok',
                'note' => 'Rozpoznanie OpenAI zakończone powodzeniem.',
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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Analizujesz pojedynczą stronę PDF z dokumentami kosztowymi firmy.

Zadanie:
1. Oceń, czy ta strona przedstawia istotny dokument księgowy:
- fakturę,
- rachunek,
- paragon,
- potwierdzenie płatności mogące zastępować dokument dla księgowości,
- albo inny dokument nieistotny / nierozpoznawalny.
2. Zwróć tylko jeden obiekt JSON bez markdownu, bez komentarzy i bez kodu.
3. Nie zgaduj. Jeśli pole jest niepewne, wpisz null i ustaw manual_review=true.

Zwróć dokładnie taki obiekt:
{
  "is_relevant_document": true,
  "source_type": "invoice",
  "invoice_number": "FV/123/2026",
  "issuer_name": "Nazwa wystawcy",
  "amount_due": "1234.56",
  "currency": "PLN",
  "issue_date": "2026-05-12",
  "due_date": "2026-05-20",
  "manual_review": false,
  "confidence": "high",
  "note": "krotki opis rozpoznania"
}

Zasady:
- source_type: invoice, receipt, payment_confirmation, other
- amount_due: liczba jako string z kropką dziesiętną, bez spacji i bez symbolu waluty
- currency: 3-literowy kod ISO, np. PLN, EUR, USD; jeśli nie widać, wpisz null
- daty zawsze w formacie YYYY-MM-DD albo null
- jeśli to nie jest istotny dokument księgowy, ustaw is_relevant_document=false, source_type="other", manual_review=true
- jeśli dokument jest istotny, ale odczyt jest niepewny lub dane wyglądają na błędne, ustaw manual_review=true
PROMPT;
    }

    private function buildUserContent(
        string $sourceFileName,
        int $pageNumber,
        ?string $imagePath,
        string $pageText
    ): array {
        $content = [
            [
                'type' => 'input_text',
                'text' => sprintf(
                    "Plik źródłowy: %s\nStrona: %d\nJeśli warstwa tekstowa pomaga, użyj jej tylko pomocniczo. Obraz strony jest ważniejszy od surowego OCR.\nWarstwa tekstowa:\n%s",
                    $sourceFileName,
                    $pageNumber,
                    $this->trimPageText($pageText)
                ),
            ],
        ];

        if ($imagePath !== null && is_file($imagePath)) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $this->imageDataUrl($imagePath),
                'detail' => 'high',
            ];
        }

        return $content;
    }

    private function trimPageText(string $pageText): string
    {
        $trimmed = trim($pageText);
        if ($trimmed === '') {
            return '[brak czytelnej warstwy tekstowej]';
        }

        return mb_substr($trimmed, 0, 6000);
    }

    private function imageDataUrl(string $imagePath): string
    {
        $content = file_get_contents($imagePath);
        if ($content === false || $content === '') {
            throw new RuntimeException('Nie udało się odczytać obrazu strony PDF do wysłania do OpenAI.');
        }

        return 'data:image/png;base64,' . base64_encode($content);
    }

    private function postJson(string $url, string $apiKey, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP nie ma włączonego rozszerzenia cURL, więc nie może połączyć się z OpenAI API.');
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonPayload)) {
            throw new RuntimeException('Nie udało się przygotować zapytania do OpenAI API.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nie udało się zainicjować połączenia z OpenAI API.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!is_string($rawResponse) || $rawResponse === '') {
            $message = $curlError !== '' ? $curlError : 'Pusta odpowiedź z OpenAI API.';
            throw new RuntimeException('Błąd komunikacji z OpenAI API: ' . $message);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI API zwróciło odpowiedź, której nie udało się zdekodować jako JSON.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['error']['message'] ?? 'Nieznany błąd OpenAI API.');
            throw new RuntimeException('OpenAI API zwróciło HTTP ' . $httpCode . ': ' . $message);
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

        throw new RuntimeException('OpenAI API nie zwróciło czytelnej odpowiedzi tekstowej.');
    }

    private function decodeJsonObject(string $outputText): array
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
            throw new RuntimeException('OpenAI zwróciło odpowiedź, ale nie była ona poprawnym obiektem JSON.');
        }

        return $decoded;
    }
}
