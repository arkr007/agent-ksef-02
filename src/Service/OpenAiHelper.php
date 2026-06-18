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

    public function recognizePdfDocuments(string $sourceFileName, array $pageTexts): array
    {
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
            @set_time_limit(300);

            $payload = [
                'model' => $model !== '' ? $model : 'gpt-5-mini',
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $this->systemPrompt(),
                        ]],
                    ],
                    [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $this->buildPdfUserPrompt($sourceFileName, $pageTexts),
                        ]],
                    ],
                ],
            ];

            $response = $this->postJson(self::RESPONSES_URL, $apiKey, $payload);
            $outputText = $this->extractOutputText($response);
            $decoded = $this->decodeJsonPayload($outputText);

            return [
                'status' => 'ok',
                'note' => 'Rozpoznanie OpenAI dla całego PDF zakończone powodzeniem.',
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
Analizujesz cały plik PDF zawierający dokumenty kosztowe firmy. Dostajesz tekst podzielony na strony, z jawnymi znacznikami numerów stron.

Zadanie:
1. Rozpoznaj wszystkie istotne dokumenty księgowe znajdujące się w tym PDF.
2. Łącz strony należące do tego samego dokumentu, jeśli jedna faktura zajmuje więcej niż jedną stronę.
3. Dla każdej pozycji ustal:
- zakres stron,
- typ dokumentu,
- wystawcę,
- numer dokumentu, jeśli da się go odczytać,
- kwotę brutto,
- kwotę do zapłaty, jeśli występuje,
- walutę,
- datę wystawienia,
- termin płatności.
4. Jeśli jakaś strona wygląda na dokument księgowy, ale nie da się jej pewnie przypisać, wpisz ją do `manual_review_pages`.
5. Zwróć wyłącznie jeden obiekt JSON bez markdownu i bez komentarzy.

Zwróć dokładnie obiekt w tej strukturze:
{
  "documents": [
    {
      "page_from": 1,
      "page_to": 1,
      "source_type": "invoice",
      "issuer_name": "Nazwa wystawcy",
      "invoice_number": "FV/123/2026",
      "gross_amount": "1234.56",
      "amount_due": "1234.56",
      "currency": "PLN",
      "issue_date": "2026-05-12",
      "due_date": "2026-05-20",
      "manual_review": false,
      "note": "krótki opis"
    }
  ],
  "manual_review_pages": [
    {
      "page_from": 3,
      "page_to": 3,
      "note": "niepewny odczyt dokumentu"
    }
  ]
}

Zasady:
- source_type: invoice, receipt, payment_confirmation, other
- kwoty zapisuj jako string z kropką dziesiętną, bez spacji i bez symbolu waluty
- currency: 3-literowy kod ISO albo null
- daty zawsze w formacie YYYY-MM-DD albo null
- page_from i page_to muszą odnosić się do numerów stron z wejścia
- jeśli dokument jest wielostronicowy, zwróć jeden wpis z odpowiednim zakresem stron
- jeśli masz pewność, że dana strona nie jest istotnym dokumentem księgowym, nie wpisuj jej do `manual_review_pages`
PROMPT;
    }

    private function buildPdfUserPrompt(string $sourceFileName, array $pageTexts): string
    {
        $parts = [
            'Plik źródłowy: ' . $sourceFileName,
            'Poniżej znajduje się treść PDF podzielona na strony.',
            'Każdy blok ma nagłówek [PAGE N]. Analizuj cały dokument łącznie, a nie stronę po stronie.',
        ];

        foreach (array_values($pageTexts) as $index => $pageText) {
            $parts[] = '[PAGE ' . ($index + 1) . ']';
            $parts[] = $this->summarizePageText(is_string($pageText) ? $pageText : '');
        }

        return implode("\n\n", $parts);
    }

    private function summarizePageText(string $pageText): string
    {
        $trimmed = trim($pageText);
        if ($trimmed === '') {
            return '[brak czytelnej warstwy tekstowej]';
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
        if (mb_strlen($normalized) <= 4200) {
            return $normalized;
        }

        $head = mb_substr($normalized, 0, 2600);
        $tail = mb_substr($normalized, -1400);

        return $head . ' [...] ' . $tail;
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
            CURLOPT_TIMEOUT => 90,
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
            throw new RuntimeException('OpenAI zwróciło odpowiedź, ale nie była ona poprawnym JSON-em.');
        }

        return $decoded;
    }
}
