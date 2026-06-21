<?php

declare(strict_types=1);

namespace App\Service;

final class InvoiceAiPromptCatalog
{
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
Analizujesz caly plik PDF zawierajacy dokumenty kosztowe firmy. Otrzymujesz obrazy kolejnych stron dokumentu, w kolejnosci od pierwszej do ostatniej.

Zadanie:
1. Rozpoznaj wszystkie istotne dokumenty ksiegowe znajdujace sie w tym PDF.
2. Lacz strony nalezace do tego samego dokumentu, jesli jedna faktura zajmuje wiecej niz jedna strone.
3. Dla kazdej pozycji ustal:
- zakres stron,
- typ dokumentu,
- wystawce,
- numer dokumentu, jesli da sie go odczytac,
- kwote brutto,
- kwote do zaplaty, jesli wystepuje,
- walute,
- date wystawienia,
- termin platnosci.
4. Jesli jakas strona wyglada na dokument ksiegowy, ale nie da sie jej pewnie przypisac, wpisz ja do `manual_review_pages`.
5. Zwroc wylacznie jeden obiekt JSON bez markdownu i bez komentarzy.

Zwroc dokladnie obiekt w tej strukturze:
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
      "note": "krotki opis"
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
- kwoty zapisuj jako string z kropka dziesietna, bez spacji i bez symbolu waluty
- currency: 3-literowy kod ISO albo null
- daty zawsze w formacie YYYY-MM-DD albo null
- page_from i page_to musza odnosic sie do numerow stron wynikajacych z kolejnosci obrazow
- jesli dokument jest wielostronicowy, zwroc jeden wpis z odpowiednim zakresem stron
- jesli masz pewnosc, ze dana strona nie jest istotnym dokumentem ksiegowym, nie wpisuj jej do `manual_review_pages`
- jesli widzisz zarowno kwote brutto, jak i kwote do zaplaty, zwroc obie
PROMPT;
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'documents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'page_from' => ['type' => 'integer'],
                            'page_to' => ['type' => 'integer'],
                            'source_type' => [
                                'type' => 'string',
                                'enum' => ['invoice', 'receipt', 'payment_confirmation', 'other'],
                            ],
                            'issuer_name' => ['type' => ['string', 'null']],
                            'invoice_number' => ['type' => ['string', 'null']],
                            'gross_amount' => ['type' => ['string', 'null']],
                            'amount_due' => ['type' => ['string', 'null']],
                            'currency' => ['type' => ['string', 'null']],
                            'issue_date' => ['type' => ['string', 'null']],
                            'due_date' => ['type' => ['string', 'null']],
                            'manual_review' => ['type' => 'boolean'],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'page_from',
                            'page_to',
                            'source_type',
                            'issuer_name',
                            'invoice_number',
                            'gross_amount',
                            'amount_due',
                            'currency',
                            'issue_date',
                            'due_date',
                            'manual_review',
                            'note',
                        ],
                    ],
                ],
                'manual_review_pages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'page_from' => ['type' => 'integer'],
                            'page_to' => ['type' => 'integer'],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['page_from', 'page_to', 'note'],
                    ],
                ],
            ],
            'required' => ['documents', 'manual_review_pages'],
        ];
    }

    public static function promptIntro(string $sourceFileName, array $pageImages, array $pageTexts): string
    {
        $pageCount = count($pageImages);
        $lines = [
            'Plik zrodlowy: ' . $sourceFileName,
            'Za tym komunikatem znajduje sie ' . $pageCount . ' obrazow stron PDF w kolejnosci od strony 1 do strony ' . $pageCount . '.',
            'Najwazniejszym zrodlem informacji sa obrazy stron.',
            'Jesli pomocniczy skrot tekstu strony jest dostepny, traktuj go tylko jako wsparcie, a nie zrodlo nadrzedne.',
        ];

        foreach (array_values($pageTexts) as $index => $pageText) {
            $snippet = self::pageTextSnippet(is_string($pageText) ? $pageText : '');
            if ($snippet === null) {
                continue;
            }

            $lines[] = 'Pomocniczy skrot strony ' . ($index + 1) . ': ' . $snippet;
        }

        return implode("\n", $lines);
    }

    private static function pageTextSnippet(string $pageText): ?string
    {
        $trimmed = trim($pageText);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;

        return mb_substr($normalized, 0, 240);
    }
}
